<?php

namespace App\Concerns;

use App\Data\ShopPermissions;
use App\Data\UserShop;
use App\Enums\ShopPermission;
use App\Enums\ShopRole;
use App\Models\Membership;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

trait HasShops
{
    /**
     * Get all of the shops the user belongs to.
     *
     * @return BelongsToMany<Shop, $this>
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'shop_members', 'user_id', 'shop_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all of the shops the user owns.
     *
     * @return HasManyThrough<Shop, Membership, $this>
     */
    public function ownedShops(): HasManyThrough
    {
        return $this->hasManyThrough(
            Shop::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'shop_id',
        )->where('shop_members.role', ShopRole::Owner->value);
    }

    /**
     * Get all of the memberships for the user.
     *
     * @return HasMany<Membership, $this>
     */
    public function shopMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Get the user's current shop.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function currentShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'current_shop_id');
    }

    /**
     * Get the user's personal shop.
     */
    public function personalShop(): ?Shop
    {
        return $this->ownedShops()
            ->where('shops.is_personal', true)
            ->first();
    }

    /**
     * Switch to the given shop.
     */
    public function switchShop(Shop $shop): bool
    {
        if (! $this->belongsToShop($shop)) {
            return false;
        }

        $this->update(['current_shop_id' => $shop->id]);
        $this->setRelation('currentShop', $shop);

        URL::defaults(['current_shop' => $shop->slug]);

        return true;
    }

    /**
     * Determine if the user belongs to the given shop.
     */
    public function belongsToShop(Shop $shop): bool
    {
        return $this->shops()->where('shops.id', $shop->id)->exists();
    }

    /**
     * Determine if the given shop is the user's current shop.
     */
    public function isCurrentShop(Shop $shop): bool
    {
        return $this->current_shop_id === $shop->id;
    }

    /**
     * Determine if the user is the owner of the given shop.
     */
    public function ownsShop(Shop $shop): bool
    {
        return $this->shopRole($shop) === ShopRole::Owner;
    }

    /**
     * Get the user's role on the given shop.
     */
    public function shopRole(Shop $shop): ?ShopRole
    {
        return $this->shopMemberships()
            ->where('shop_id', $shop->id)
            ->first()
            ?->role;
    }

    /**
     * Get the user's shops as a collection of UserShop objects.
     *
     * @return Collection<int, UserShop>
     */
    public function toUserShops(bool $includeCurrent = false): Collection
    {
        return $this->shops()
            ->get()
            ->map(fn (Shop $shop) => ! $includeCurrent && $this->isCurrentShop($shop) ? null : $this->toUserShop($shop))
            ->filter()
            ->values();
    }

    /**
     * Get the user's shop as a UserShop object.
     */
    public function toUserShop(Shop $shop): UserShop
    {
        $role = $this->shopRole($shop);

        return new UserShop(
            id: $shop->id,
            name: $shop->name,
            slug: $shop->slug,
            isPersonal: $shop->is_personal,
            currency: $shop->currency,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentShop($shop),
        );
    }

    /**
     * Get the standard permissions for a shop as a ShopPermissions object.
     */
    public function toShopPermissions(Shop $shop): ShopPermissions
    {
        $role = $this->shopRole($shop);

        return new ShopPermissions(
            canUpdateShop: $role?->hasPermission(ShopPermission::UpdateShop) ?? false,
            canDeleteShop: $role?->hasPermission(ShopPermission::DeleteShop) ?? false,
            canAddMember: $role?->hasPermission(ShopPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(ShopPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(ShopPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(ShopPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(ShopPermission::CancelInvitation) ?? false,
            canCreateProduct: $role?->hasPermission(ShopPermission::CreateProduct) ?? false,
            canUpdateProduct: $role?->hasPermission(ShopPermission::UpdateProduct) ?? false,
            canDeleteProduct: $role?->hasPermission(ShopPermission::DeleteProduct) ?? false,
            canCreateBrand: $role?->hasPermission(ShopPermission::CreateBrand) ?? false,
            canUpdateBrand: $role?->hasPermission(ShopPermission::UpdateBrand) ?? false,
            canDeleteBrand: $role?->hasPermission(ShopPermission::DeleteBrand) ?? false,
            canCreateCategory: $role?->hasPermission(ShopPermission::CreateCategory) ?? false,
            canUpdateCategory: $role?->hasPermission(ShopPermission::UpdateCategory) ?? false,
            canDeleteCategory: $role?->hasPermission(ShopPermission::DeleteCategory) ?? false,
            canManageSync: $role?->hasPermission(ShopPermission::ManageSync) ?? false,
            canRunSync: $role?->hasPermission(ShopPermission::RunSync) ?? false,
        );
    }

    public function fallbackShop(?Shop $excluding = null): ?Shop
    {
        return $this->shops()
            ->when($excluding, fn ($query) => $query->where('shops.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(shops.name)')
            ->first();
    }

    /**
     * Determine if the user has the given permission on the shop.
     */
    public function hasShopPermission(Shop $shop, ShopPermission $permission): bool
    {
        return $this->shopRole($shop)?->hasPermission($permission) ?? false;
    }
}
