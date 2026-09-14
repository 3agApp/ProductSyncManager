<?php

namespace Database\Factories;

use App\Enums\NaldaDefaultBehavior;
use App\Models\NaldaConnection;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NaldaConnection>
 */
class NaldaConnectionFactory extends Factory
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
            'sftp_username' => 'nalda-seller-'.fake()->regexify('[A-Za-z0-9]{28}'),
            'sftp_password' => fake()->password(16, 24),
            'default_behavior' => NaldaDefaultBehavior::IncludeAll,
            'country' => 'CH',
            'currency' => 'CHF',
            'condition' => 'new',
            'tax_rate' => '8.10',
            'delivery_time_days' => 3,
            'return_days' => 14,
            'upload_after_sync' => true,
        ];
    }

    /**
     * Indicate that products are left off Nalda unless included one by one.
     */
    public function excludingAll(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_behavior' => NaldaDefaultBehavior::ExcludeAll,
        ]);
    }

    /**
     * Indicate that no SFTP credentials have been saved.
     */
    public function withoutCredentials(): static
    {
        return $this->state(fn (array $attributes) => [
            'sftp_username' => null,
            'sftp_password' => null,
        ]);
    }
}
