<?php

use App\Console\Commands\PruneWebhookDeliveries;
use App\Models\Shop;
use App\Models\WooCommerceConnection;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record a delivery with the given ages.
 */
function delivery(WooCommerceConnection $connection, int $ageDays, ?int $processedDaysAgo): string
{
    $deliveryId = Str::random(64);

    DB::table('woocommerce_webhook_deliveries')->insert([
        'connection_id' => $connection->id,
        'delivery_id' => $deliveryId,
        'product_id' => 42,
        'store_url' => $connection->store_url,
        'processed_at' => $processedDaysAgo === null ? null : now()->subDays($processedDaysAgo),
        'created_at' => now()->subDays($ageDays),
        'updated_at' => now()->subDays($ageDays),
    ]);

    return $deliveryId;
}

test('processed deliveries past the retention and stale unprocessed ones are pruned', function () {
    $connection = WooCommerceConnection::factory()->for(Shop::factory())->create();

    $oldProcessed = delivery($connection, ageDays: 10, processedDaysAgo: 10);
    $recentProcessed = delivery($connection, ageDays: 2, processedDaysAgo: 2);
    $pending = delivery($connection, ageDays: 3, processedDaysAgo: null);
    $stalePending = delivery($connection, ageDays: 40, processedDaysAgo: null);

    $this->artisan('webhooks:prune-deliveries')->assertSuccessful();

    expect(DB::table('woocommerce_webhook_deliveries')->pluck('delivery_id')->all())
        ->toEqualCanonicalizing([$recentProcessed, $pending])
        ->not->toContain($oldProcessed, $stalePending);
});

test('pruning works through more than one batch', function () {
    $connection = WooCommerceConnection::factory()->for(Shop::factory())->create();
    DB::table('woocommerce_webhook_deliveries')->insert(collect(range(1, 2500))->map(fn () => [
        'connection_id' => $connection->id,
        'delivery_id' => Str::random(64),
        'product_id' => 42,
        'store_url' => $connection->store_url,
        'processed_at' => now()->subDays(8),
        'created_at' => now()->subDays(8),
        'updated_at' => now()->subDays(8),
    ])->all());

    $this->artisan('webhooks:prune-deliveries')->assertSuccessful();

    expect(DB::table('woocommerce_webhook_deliveries')->count())->toBe(0);
});

test('webhook deliveries and failed jobs are pruned daily', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event) => $event->command);

    expect($commands->contains(fn (?string $command) => str_contains((string) $command, 'webhooks:prune-deliveries')))->toBeTrue()
        ->and($commands->contains(fn (?string $command) => str_contains((string) $command, 'queue:prune-failed')))->toBeTrue();
});

test('the command is registered under its class', function () {
    expect(app(PruneWebhookDeliveries::class)->getName())->toBe('webhooks:prune-deliveries');
});
