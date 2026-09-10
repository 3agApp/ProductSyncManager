<?php

namespace App\Actions\Shops;

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateShop
{
    /**
     * Create a new shop and add the user as owner.
     */
    public function handle(User $user, string $name, bool $isPersonal = false): Shop
    {
        return DB::transaction(function () use ($user, $name, $isPersonal) {
            $shop = Shop::create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $membership = $shop->memberships()->create([
                'user_id' => $user->id,
                'role' => ShopRole::Owner,
            ]);

            $user->switchShop($shop);

            return $shop;
        });
    }
}
