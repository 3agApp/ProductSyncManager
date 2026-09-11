<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\WooCommerceConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WooCommerceConnection>
 */
class WooCommerceConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'ck_'.fake()->regexify('[a-f0-9]{40}');
        $secret = 'cs_'.fake()->regexify('[a-f0-9]{40}');

        return [
            'shop_id' => Shop::factory(),
            'store_url' => 'https://'.fake()->unique()->domainName(),
            'consumer_key' => $key,
            'consumer_secret' => $secret,
            'key_last_four' => substr($key, -4),
            'secret_last_four' => substr($secret, -4),
            'verified_at' => null,
        ];
    }

    /**
     * Indicate that the connection has been verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'verified_at' => now(),
        ]);
    }
}
