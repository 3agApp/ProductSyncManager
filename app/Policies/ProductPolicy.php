<?php

namespace App\Policies;

use App\Enums\ShopPermission;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

class ProductPolicy
{
    /**
     * Determine whether the user can create products in the shop.
     */
    public function create(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::CreateProduct);
    }

    /**
     * Determine whether the user can update the product.
     */
    public function update(User $user, Product $product): bool
    {
        return $user->hasShopPermission($product->shop, ShopPermission::UpdateProduct);
    }

    /**
     * Determine whether the user can delete the product.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->hasShopPermission($product->shop, ShopPermission::DeleteProduct);
    }
}
