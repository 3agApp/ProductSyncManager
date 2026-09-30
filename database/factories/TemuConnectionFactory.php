<?php

namespace Database\Factories;

use App\Enums\TemuDefaultBehavior;
use App\Models\Shop;
use App\Models\TemuConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemuConnection>
 */
class TemuConnectionFactory extends Factory
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
            'app_key' => fake()->regexify('[a-f0-9]{32}'),
            'app_secret' => fake()->regexify('[a-f0-9]{40}'),
            'access_token' => 'upl'.fake()->regexify('[a-z0-9]{56}'),
            'default_behavior' => TemuDefaultBehavior::IncludeAll,
            'currency' => 'CHF',
            // Tests see the shop price unless they set a discount.
            'discount_percent' => 0,
            'sync_after_sync' => true,
        ];
    }

    /**
     * Indicate that products are left off Temu unless included one by one.
     */
    public function excludingAll(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_behavior' => TemuDefaultBehavior::ExcludeAll,
        ]);
    }

    /**
     * Indicate that no credentials have been saved.
     */
    public function withoutCredentials(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_key' => null,
            'app_secret' => null,
            'access_token' => null,
        ]);
    }
}
