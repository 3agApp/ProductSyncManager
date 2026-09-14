<?php

namespace Database\Factories;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\NaldaExport;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NaldaExport>
 */
class NaldaExportFactory extends Factory
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
            'listed_products' => fake()->numberBetween(0, 500),
            'removed_products' => fake()->numberBetween(0, 20),
            'skipped_products' => fake()->numberBetween(0, 20),
            'file_name' => 'products-'.now()->format('Ymd-His').'.csv',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    /**
     * Indicate that the export is waiting for a worker.
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
