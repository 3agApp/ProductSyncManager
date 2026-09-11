<?php

namespace App\Http\Requests\Products;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $product = $this->product();

        return $product
            ? Gate::allows('update', $product)
            : Gate::allows('create', [Product::class, $this->shop()]);
    }

    /**
     * The fields entered as separated lists rather than arrays.
     *
     * @var array<string, string>
     */
    private const array LIST_FIELDS = [
        'brands' => ',',
        'categories' => ',',
        'gallery_image_urls' => "\n",
    ];

    /**
     * Prepare the data for validation.
     *
     * The form enters brands, categories and gallery links as separated
     * text, so they are split before the array rules run. Fields that were
     * not submitted at all are left alone, so a caller that omits them does
     * not wipe what is stored.
     */
    protected function prepareForValidation(): void
    {
        foreach (self::LIST_FIELDS as $field => $separator) {
            if ($this->has($field)) {
                $this->merge([$field => $this->toList($this->input($field), $separator)]);
            }
        }
    }

    /**
     * Split separated text into a list of trimmed values.
     *
     * @param  non-empty-string  $separator
     * @return array<int, string>|null
     */
    private function toList(mixed $value, string $separator): ?array
    {
        if (is_array($value)) {
            $value = implode($separator, $value);
        }

        if (! is_string($value)) {
            return null;
        }

        $items = collect(explode($separator, $value))
            ->map(fn (string $item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $items === [] ? null : $items;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'required',
                'string',
                'max:64',
                Rule::unique(Product::class)
                    ->where('shop_id', $this->shop()->id)
                    ->ignore($this->product()?->id),
            ],
            'gtin' => ['sometimes', 'nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:65535'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'brands' => ['sometimes', 'nullable', 'array', 'max:20'],
            'brands.*' => ['string', 'max:255'],
            'categories' => ['sometimes', 'nullable', 'array', 'max:50'],
            'categories.*' => ['string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'gallery_image_urls' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery_image_urls.*' => ['url', 'max:2048'],
            'permalink' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'gtin' => 'GTIN',
            'image_url' => 'primary image',
            'gallery_image_urls' => 'gallery images',
            'gallery_image_urls.*' => 'gallery image',
            'permalink' => 'store link',
        ];
    }

    /**
     * Get the shop associated with the request.
     */
    private function shop(): Shop
    {
        $shop = $this->route('current_shop');

        abort_if(! $shop instanceof Shop, 404);

        return $shop;
    }

    /**
     * Get the product being updated, if any.
     */
    private function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }
}
