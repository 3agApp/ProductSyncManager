<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\Shop;
use App\Models\TemuSyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TemuSyncRun>
 */
class TemuSyncRunFactory extends Factory
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
            'trigger' => SyncTrigger::Manual,
            'status' => SyncRunStatus::Completed,
            'created_products' => fake()->numberBetween(0, 50),
            'updated_products' => fake()->numberBetween(0, 50),
            'removed_products' => fake()->numberBetween(0, 5),
            'failed_products' => 0,
            'skipped_products' => 0,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    /**
     * Indicate that the run is waiting for a worker.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Queued,
            'started_at' => null,
            'finished_at' => null,
        ]);
    }
}
