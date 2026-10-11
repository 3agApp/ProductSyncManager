<?php

namespace Database\Factories;

use App\Enums\TemuOrderStatus;
use App\Models\Shop;
use App\Models\TemuOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemuOrder>
 */
class TemuOrderFactory extends Factory
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
            'parent_order_sn' => 'PO-192-'.fake()->unique()->numerify('################'),
            'temu_status' => TemuOrder::TEMU_UNSHIPPED,
            'status' => TemuOrderStatus::Imported,
            'woocommerce_order_id' => fake()->numberBetween(1000, 99999),
            'ordered_at' => now()->subHour(),
            'ship_by_at' => now()->addDays(2),
            'imported_at' => now()->subMinutes(55),
        ];
    }
}
