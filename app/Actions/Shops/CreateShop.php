<?php

namespace App\Actions\Shops;

use App\Enums\ShopRole;
use App\Enums\ShopType;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateShop
{
    /**
     * Create a new shop and add the user as owner.
     */
    public function handle(User $user, string $name, ShopType $type = ShopType::Wordpress): Shop
    {
        return DB::transaction(function () use ($user, $name, $type) {
            $shop = Shop::create([
                'name' => $name,
                'type' => $type,
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
