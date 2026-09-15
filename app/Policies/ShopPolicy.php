<?php

namespace App\Policies;

use App\Enums\ShopPermission;
use App\Models\Shop;
use App\Models\User;

class ShopPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Shop $shop): bool
    {
        return $user->belongsToShop($shop);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::UpdateShop);
    }

    /**
     * Determine whether the user can leave the shop.
     */
    public function leave(User $user, Shop $shop): bool
    {
        return $user->belongsToShop($shop)
            && ! $user->ownsShop($shop);
    }

    /**
     * Determine whether the user can add a member to the shop.
     */
    public function addMember(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::AddMember);
    }

    /**
     * Determine whether the user can update a member's role in the shop.
     */
    public function updateMember(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::UpdateMember);
    }

    /**
     * Determine whether the user can remove a member from the shop.
     */
    public function removeMember(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::RemoveMember);
    }

    /**
     * Determine whether the user can invite members to the shop.
     */
    public function inviteMember(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::CreateInvitation);
    }

    /**
     * Determine whether the user can cancel invitations.
     */
    public function cancelInvitation(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::CancelInvitation);
    }

    /**
     * Determine whether the user can configure the shop's sync connection and schedule.
     */
    public function manageSync(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::ManageSync);
    }

    /**
     * Determine whether the user can start a sync for the shop.
     */
    public function runSync(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::RunSync);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Shop $shop): bool
    {
        return $user->hasShopPermission($shop, ShopPermission::DeleteShop);
    }
}
