<?php

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use App\Models\Shop;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
            'name' => ['required', 'string', 'max:255', $this->freeSlug()],
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
