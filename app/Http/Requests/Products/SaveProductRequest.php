<?php

namespace App\Http\Requests\Products;

use App\Enums\NaldaListing;
use App\Enums\ProductStatus;
use App\Enums\TemuListing;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
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
        'categories' => ',',
        'gallery_image_urls' => "\n",
    ];

    /**
     * Prepare the data for validation.
     *
     * The form enters categories and gallery links as separated text, so
     * they are split before the array rules run. Fields that were not
     * submitted at all are left alone, so a caller that omits them does not
     * wipe what is stored.
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
     * Read a list of trimmed values out of what was submitted.
     *
     * Text is split on the separator, but a list that arrives as a list is
     * taken as it stands. Joining and re-splitting it would break any entry
     * containing the separator -- a category named "Toys, Games" among
     * them.
     *
     * @param  non-empty-string  $separator
     * @return array<int, string>|null
     */
    private function toList(mixed $value, string $separator): ?array
    {
        $items = match (true) {
            is_array($value) => $value,
            is_string($value) => explode($separator, $value),
            default => [],
        };

        $items = collect($items)
            ->filter(fn (mixed $item) => is_string($item))
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
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'categories' => ['sometimes', 'nullable', 'array', 'max:50'],
            'categories.*' => ['string', 'max:255'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'gallery_image_urls' => ['sometimes', 'nullable', 'array', 'max:20'],
            'gallery_image_urls.*' => ['url', 'max:2048'],
            'permalink' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'nalda_listing' => ['sometimes', Rule::enum(NaldaListing::class)],
            'temu_listing' => ['sometimes', Rule::enum(TemuListing::class)],
            'temu_name' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get the attributes to save, with the brand resolved to a row.
     *
     * The form takes the brand as typed text rather than a chosen id, so a
     * name that is new to the shop becomes a brand here. A brand that was
     * not submitted at all is left as it is on the product. Categories are
     * dropped, since they live on a pivot rather than a column.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        $validated = $this->validated();
        $attributes = Arr::except($validated, ['brand', 'categories']);

        if (! array_key_exists('brand', $validated)) {
            return $attributes;
        }

        return [
            ...$attributes,
            'brand_id' => Brand::findOrCreateFor($this->shop(), $validated['brand'])?->id,
        ];
    }

    /**
     * Get the category rows the product should be filed under.
     *
     * Each entry is a written path, so "Bags > Totes" files the product
     * under a nested category, creating the levels that are new to the
     * shop. Null means the field was not submitted and whatever the product
     * is already filed under should be left alone.
     *
     * @return array<int, int>|null
     */
    public function categoryIds(): ?array
    {
        if (! $this->has('categories')) {
            return null;
        }

        $paths = $this->validated()['categories'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $shop = $this->shop();
        $ids = [];

        foreach ($paths as $path) {
            $category = Category::findOrCreatePath($shop, is_string($path) ? $path : null);

            if ($category !== null) {
                $ids[$category->id] = $category->id;
            }
        }

        return array_values($ids);
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
