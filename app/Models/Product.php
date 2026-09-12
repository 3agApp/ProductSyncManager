<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int|null $brand_id
 * @property int|null $remote_id
 * @property string $name
 * @property string $sku
 * @property string|null $gtin
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $image_url
 * @property array<int, string>|null $gallery_image_urls
 * @property string|null $permalink
 * @property string $price
 * @property int $stock
 * @property ProductStatus $status
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read Brand|null $brand
 * @property-read Collection<int, Category> $categories
 */
#[Fillable([
    'brand_id',
    'remote_id',
    'name',
    'sku',
    'gtin',
    'description',
    'short_description',
    'image_url',
    'gallery_image_urls',
    'permalink',
    'price',
    'stock',
    'status',
    'last_synced_at',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Products at or below this stock level are considered low on stock.
     */
    public const int LOW_STOCK_THRESHOLD = 5;

    /**
     * Get the shop that owns the product.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the product's brand, if it has been given one.
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the categories the product is filed under.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'status' => ProductStatus::class,
            'remote_id' => 'integer',
            'gallery_image_urls' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
