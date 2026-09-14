<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueShopSlugs;
use App\Enums\ShopRole;
use App\Enums\ShopType;
use Database\Factories\ShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_personal
 * @property ShopType $type
 * @property bool $prices_include_tax
 * @property string $tax_rate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, ShopInvitation> $invitations
 * @property-read Collection<int, Membership> $memberships
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Brand> $brands
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Product> $products
 * @property-read Collection<int, SyncRun> $syncRuns
 * @property-read WooCommerceConnection|null $wooCommerceConnection
 * @property-read ShopSyncSetting|null $syncSettings
 * @property-read NaldaConnection|null $naldaConnection
 * @property-read Collection<int, NaldaExport> $naldaExports
 */
#[Fillable(['name', 'slug', 'is_personal', 'type', 'prices_include_tax', 'tax_rate'])]
class Shop extends Model
{
    /** @use HasFactory<ShopFactory> */
    use GeneratesUniqueShopSlugs, HasFactory, SoftDeletes;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Shop $shop) {
            if (empty($shop->slug)) {
                $shop->slug = static::generateUniqueShopSlug($shop->name);
            }
        });

        static::updating(function (Shop $shop) {
            if ($shop->isDirty('name')) {
                $shop->slug = static::generateUniqueShopSlug($shop->name, $shop->id);
            }
        });
    }

    /**
     * Get the shop owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', ShopRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this shop.
     *
     * @return BelongsToMany<User, $this, Membership, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shop_members', 'shop_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this shop.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this shop.
     *
     * @return HasMany<ShopInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ShopInvitation::class);
    }

    /**
     * Get all brands for this shop.
     *
     * @return HasMany<Brand, $this>
     */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Get all categories for this shop.
     *
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * Get all products for this shop.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get the WooCommerce connection for the shop.
     *
     * @return HasOne<WooCommerceConnection, $this>
     */
    public function wooCommerceConnection(): HasOne
    {
        return $this->hasOne(WooCommerceConnection::class);
    }

    /**
     * Get the sync schedule settings for the shop.
     *
     * @return HasOne<ShopSyncSetting, $this>
     */
    public function syncSettings(): HasOne
    {
        return $this->hasOne(ShopSyncSetting::class);
    }

    /**
     * Get the sync runs for the shop.
     *
     * @return HasMany<SyncRun, $this>
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /**
     * Get the Nalda marketplace settings for the shop.
     *
     * @return HasOne<NaldaConnection, $this>
     */
    public function naldaConnection(): HasOne
    {
        return $this->hasOne(NaldaConnection::class);
    }

    /**
     * Get the product file uploads to Nalda for the shop.
     *
     * @return HasMany<NaldaExport, $this>
     */
    public function naldaExports(): HasMany
    {
        return $this->hasMany(NaldaExport::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'type' => ShopType::class,
            'prices_include_tax' => 'boolean',
            'tax_rate' => 'decimal:2',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
