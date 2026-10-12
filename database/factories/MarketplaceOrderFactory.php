<?php

namespace Database\Factories;

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketplaceOrder>
 */
class MarketplaceOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'marketplace' => Marketplace::Temu,
            'external_order_id' => 'PO-192-'.fake()->unique()->numerify('################'),
            'marketplace_status' => (string) MarketplaceOrder::TEMU_UNSHIPPED,
            'status' => MarketplaceOrderStatus::Imported,
            'woocommerce_order_id' => fake()->numberBetween(1000, 99999),
            'ordered_at' => now()->subHour(),
            'ship_by_at' => now()->addDays(2),
            'imported_at' => now()->subMinutes(55),
        ];
    }

    /**
     * Make the order a Nalda one.
     */
    public function nalda(): static
    {
        return $this->state(fn (array $attributes) => [
            'marketplace' => Marketplace::Nalda,
            'external_order_id' => (string) fake()->unique()->numberBetween(400000, 999999),
            'marketplace_status' => 'IN_PREPARATION',
            'ship_by_at' => null,
        ]);
    }
}
