<?php

namespace App\Actions\Shops;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteShop
{
    /**
     * Delete the shop, moving anyone working in it to another of their shops.
     */
    public function handle(Shop $shop): void
    {
        DB::transaction(function () use ($shop) {
            User::where('current_shop_id', $shop->id)
                ->each(fn (User $user) => $user->switchToFallbackShop($shop));

            $shop->invitations()->delete();
            $shop->memberships()->delete();
            $shop->delete();
        });
    }
}
