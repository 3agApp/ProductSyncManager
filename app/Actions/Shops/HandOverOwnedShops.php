<?php

namespace App\Actions\Shops;

use App\Enums\ShopRole;
use App\Models\Membership;
use App\Models\Shop;
use App\Models\User;

/**
 * Makes sure no shop is left ownerless when a user goes.
 *
 * Ownership lives in the membership row, and memberships cascade away with
 * the user, so an owner who deletes their account would otherwise take the
 * only account that can manage members, change the shop or delete it --
 * leaving everyone else with a catalogue nobody can administer and a sync
 * nobody can stop.
 */
class HandOverOwnedShops
{
    public function __construct(private DeleteShop $deleteShop) {}

    /**
     * Hand on, or wind up, every shop the user owns.
     */
    public function handle(User $user): void
    {
        foreach ($user->ownedShops()->get() as $shop) {
            $successor = $this->successorTo($user, $shop);

            // Nobody else is in it, so there is nothing to hand over and
            // nobody left to miss it.
            if ($successor === null) {
                $this->deleteShop->handle($shop);

                continue;
            }

            $successor->update(['role' => ShopRole::Owner]);
        }
    }

    /**
     * Pick who should take the shop on.
     *
     * The longest-standing admin, since they already hold every permission
     * but the two an owner keeps. Failing that the longest-standing member,
     * who is at least someone rather than nobody.
     */
    private function successorTo(User $user, Shop $shop): ?Membership
    {
        return $shop->memberships()
            ->where('user_id', '!=', $user->id)
            ->oldest()
            ->oldest('id')
            ->get()
            // A stable sort, so the ordering above still decides between two
            // members holding the same role.
            ->sortBy(fn (Membership $membership) => $membership->role === ShopRole::Admin ? 0 : 1)
            ->first();
    }
}
