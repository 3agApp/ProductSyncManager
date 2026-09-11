<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Shop;
use App\Models\ShopSyncSetting;
use App\Models\SyncRun;
use App\Models\WooCommerceConnection;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

/**
 * Create a connected shop with a schedule in the given state.
 *
 * @param  array<string, mixed>  $settings
 */
function scheduledShop(array $settings = []): Shop
{
    $shop = Shop::factory()->create();

    WooCommerceConnection::factory()->for($shop)->create();

    ShopSyncSetting::factory()->for($shop)->create([
        'is_enabled' => true,
        'next_run_at' => now()->subMinute(),
        ...$settings,
    ]);

    return $shop;
}

test('a due shop is dispatched', function () {
    Queue::fake();

    $shop = scheduledShop();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SyncWooCommerceProducts::class);

    expect($shop->syncRuns()->sole()->trigger)->toBe(SyncTrigger::Scheduled);
});

test('a shop scheduled for later is not dispatched', function () {
    Queue::fake();

    scheduledShop(['next_run_at' => now()->addHour()]);

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('a disabled shop is not dispatched', function () {
    Queue::fake();

    scheduledShop(['is_enabled' => false]);

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('the next run is advanced past now after dispatching', function () {
    Queue::fake();

    $shop = scheduledShop(['time_of_day' => '02:00']);

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    expect($shop->syncSettings->refresh()->next_run_at->isAfter(now()))->toBeTrue();
});

test('a shop with a run already in flight is skipped but still rescheduled', function () {
    Queue::fake();

    $shop = scheduledShop();
    SyncRun::factory()->for($shop)->running()->create();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();

    expect($shop->syncRuns()->count())->toBe(1)
        ->and($shop->syncSettings->refresh()->next_run_at->isAfter(now()))->toBeTrue();
});

test('a shop without a connection is skipped but still rescheduled', function () {
    Queue::fake();

    $shop = Shop::factory()->create();
    ShopSyncSetting::factory()->for($shop)->create([
        'is_enabled' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertNothingPushed();

    expect($shop->syncSettings->refresh()->next_run_at->isAfter(now()))->toBeTrue();
});

test('a run that stalled is failed', function () {
    Queue::fake();

    $shop = Shop::factory()->create();
    $stalled = SyncRun::factory()->for($shop)->running()->create([
        'created_at' => now()->subMinutes(SyncRun::ACTIVE_TIMEOUT_MINUTES + 1),
    ]);
    $fresh = SyncRun::factory()->for($shop)->running()->create();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    expect($stalled->fresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($stalled->fresh()->message)->toBe('The sync timed out.')
        ->and($fresh->fresh()->status)->toBe(SyncRunStatus::Running);
});

test('the dispatcher runs every minute on the schedule', function () {
    // Schedule::command() shells out to its own process, so asserting the
    // registration is the meaningful check here rather than running it.
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'sync:dispatch-due'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
