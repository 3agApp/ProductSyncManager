<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\Shop;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
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
            'triggered_by' => null,
            'trigger' => SyncTrigger::Manual,
            'status' => SyncRunStatus::Queued,
        ];
    }

    /**
     * Indicate that the run is in progress.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
            'total_products' => 100,
            'processed_products' => 40,
        ]);
    }

    /**
     * Indicate that the run finished successfully.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'total_products' => 100,
            'processed_products' => 100,
            'created_products' => 100,
        ]);
    }

    /**
     * Indicate that the run failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SyncRunStatus::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'message' => 'WooCommerce rejected the credentials.',
        ]);
    }

    /**
     * Indicate that the run was started by the scheduler.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger' => SyncTrigger::Scheduled,
        ]);
    }
}
