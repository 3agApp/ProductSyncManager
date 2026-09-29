<?php

namespace App\Models;

use App\Concerns\IsCatalogTerm;
use App\Enums\NaldaListing;
use App\Enums\TemuListing;
use Carbon\CarbonImmutable;
use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $shop_id
 * @property int|null $remote_id
 * @property string $name
 * @property string $slug
 * @property NaldaListing $nalda_listing
 * @property TemuListing $temu_listing
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 * @property-read Collection<int, Product> $products
 */
#[Fillable(['remote_id', 'name', 'slug', 'nalda_listing', 'temu_listing'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, IsCatalogTerm;

    /**
     * Get the shop's brands, for the shared term behaviour.
     *
     * @return HasMany<Brand, Shop>
     */
    protected static function termsOf(Shop $shop): HasMany
    {
        return $shop->brands();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nalda_listing' => NaldaListing::class,
            'temu_listing' => TemuListing::class,
        ];
    }

    /**
     * Get the shop that owns the brand.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the products carrying this brand.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
