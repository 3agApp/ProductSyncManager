<?php

namespace App\Policies;

use App\Enums\ShopPermission;
use App\Models\Category;
use App\Models\Shop;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Determine whether the user can create categories in the shop.
     */
    public function create(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::CreateCategory);
    }

    /**
     * Determine whether the user can update the category.
     */
    public function update(User $user, Category $category): bool
    {
        return $user->hasShopPermission($category->shop, ShopPermission::UpdateCategory);
    }

    /**
     * Determine whether the user can delete the category.
     */
    public function delete(User $user, Category $category): bool
    {
        return $user->hasShopPermission($category->shop, ShopPermission::DeleteCategory);
    }
}
