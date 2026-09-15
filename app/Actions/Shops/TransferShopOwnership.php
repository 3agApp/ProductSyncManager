<?php

namespace App\Actions\Shops;

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferShopOwnership
{
    /**
     * Make the user the shop's owner, keeping the previous owner on as an admin.
     */
    public function handle(Shop $shop, User $newOwner): void
    {
        DB::transaction(function () use ($shop, $newOwner) {
            $shop->memberships()
                ->where('role', ShopRole::Owner->value)
                ->where('user_id', '!=', $newOwner->id)
                ->update(['role' => ShopRole::Admin->value]);

            $shop->memberships()->updateOrCreate(
                ['user_id' => $newOwner->id],
                ['role' => ShopRole::Owner],
            );
        });
    }
}
