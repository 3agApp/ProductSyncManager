<?php

namespace App\Http\Requests\Catalog;

use App\Enums\NaldaListing;
use App\Enums\TemuListing;
use App\Models\Brand;
use App\Models\Shop;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveBrandRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $brand = $this->brand();

        return $brand
            ? Gate::allows('update', $brand)
            : Gate::allows('create', [Brand::class, $this->shop()]);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // An existing brand can have just its listing choices changed,
            // so only a new brand has to come with a name.
            'name' => [...($this->brand() ? ['sometimes'] : []), 'required', 'string', 'max:255', $this->freeSlug()],
            'nalda_listing' => ['sometimes', Rule::enum(NaldaListing::class)],
            'temu_listing' => ['sometimes', Rule::enum(TemuListing::class)],
        ];
    }

    /**
     * Require a name no other brand in the shop already answers to.
     *
     * Brands are told apart by their slug, so that is what has to be free:
     * "Acme" and "acme" would be the same brand.
     */
    private function freeSlug(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $slug = Str::slug((string) $value);

            if ($slug === '') {
                $fail(__('The name must contain at least one letter or number.'));

                return;
            }

            $brand = $this->brand();

            $taken = $this->shop()->brands()
                ->where('slug', $slug)
                ->when($brand !== null, fn ($query) => $query->whereKeyNot($brand->id))
                ->exists();

            if ($taken) {
                $fail(__('This shop already has a brand by that name.'));
            }
        };
    }

    /**
     * Get the brand attributes to save.
     *
     * @return array<string, mixed>
     */
    public function brandAttributes(): array
    {
        $attributes = [];

        if ($this->has('name')) {
            $name = (string) $this->validated('name');
            $attributes['name'] = $name;
            $attributes['slug'] = Str::slug($name);
        }

        if ($this->has('nalda_listing')) {
            $attributes['nalda_listing'] = NaldaListing::from((string) $this->validated('nalda_listing'));
        }

        if ($this->has('temu_listing')) {
            $attributes['temu_listing'] = TemuListing::from((string) $this->validated('temu_listing'));
        }

        return $attributes;
    }

    /**
     * Get the shop associated with the request.
     */
    public function shop(): Shop
    {
        $shop = $this->route('current_shop');

        abort_if(! $shop instanceof Shop, 404);

        return $shop;
    }

    /**
     * Get the brand being updated, if any.
     */
    private function brand(): ?Brand
    {
        $brand = $this->route('brand');

        return $brand instanceof Brand ? $brand : null;
    }
}
