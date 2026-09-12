<?php

namespace App\Policies;

use App\Enums\ShopPermission;
use App\Models\Brand;
use App\Models\Shop;
use App\Models\User;

class BrandPolicy
{
    /**
     * Determine whether the user can create brands in the shop.
     */
    public function create(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::CreateBrand);
    }

    /**
     * Determine whether the user can update the brand.
     */
    public function update(User $user, Brand $brand): bool
    {
        return $user->hasShopPermission($brand->shop, ShopPermission::UpdateBrand);
    }

    /**
     * Determine whether the user can delete the brand.
     */
    public function delete(User $user, Brand $brand): bool
    {
        return $user->hasShopPermission($brand->shop, ShopPermission::DeleteBrand);
    }
}
