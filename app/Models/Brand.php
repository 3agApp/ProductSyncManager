<?php

namespace App\Models;

use Database\Factories\BrandFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $shop_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read Collection<int, Product> $products
 */
#[Fillable(['name', 'slug'])]
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Brand $brand) {
            if ($brand->isDirty('name') && ! $brand->isDirty('slug')) {
                $brand->slug = Str::slug($brand->name);
            }
        });
    }

    /**
     * Get the shop's brand of this name, creating it when it is new.
     *
     * Brands arrive as typed or imported text, so they are matched on the
     * slug: that keeps "Acme", "acme" and " ACME " on one row instead of
     * three. A name with nothing sluggable in it is treated as no brand.
     */
    public static function findOrCreateFor(Shop $shop, ?string $name): ?self
    {
        $name = trim((string) $name);
        $slug = Str::slug($name);

        if ($slug === '') {
            return null;
        }

        return $shop->brands()->firstOrCreate(['slug' => $slug], ['name' => $name]);
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
