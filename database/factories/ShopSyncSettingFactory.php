<?php

namespace Database\Factories;

use App\Enums\SyncFrequency;
use App\Models\Shop;
use App\Models\ShopSyncSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopSyncSetting>
 */
class ShopSyncSettingFactory extends Factory
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
            'is_enabled' => false,
            'frequency' => SyncFrequency::Daily,
            'time_of_day' => '02:00',
            'day_of_week' => null,
            'timezone' => 'UTC',
            'last_run_at' => null,
            'next_run_at' => null,
        ];
    }

    /**
     * Indicate that scheduled syncing is enabled.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
            'next_run_at' => now()->addDay(),
        ]);
    }

    /**
     * Indicate that the schedule is due to run now.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);
    }

    /**
     * Indicate that the shop syncs hourly.
     */
    public function hourly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => SyncFrequency::Hourly,
        ]);
    }

    /**
     * Indicate that the shop syncs weekly.
     */
    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => SyncFrequency::Weekly,
            'day_of_week' => 1,
        ]);
    }
}
