<?php

namespace Database\Seeders;

use App\Enums\ShopRole;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $products = Product::factory()->for($user->personalShop());

        $products->count(16)->branded()->create();
        $products->count(4)->draft()->branded()->create();
        $products->count(3)->lowStock()->branded()->create();

        // Left brandless, so the empty column shows up in the dashboard too.
        $products->count(2)->outOfStock()->create();
        $products->archived()->create();

        $outlet = Shop::factory()->create(['name' => 'Acme Outlet']);
        $outlet->members()->attach($user, ['role' => ShopRole::Owner->value]);

        Product::factory()->count(6)->for($outlet)->branded()->create();
    }
}
