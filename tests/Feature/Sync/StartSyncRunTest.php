<?php

use App\Actions\Sync\StartSyncRun;
use App\Enums\ShopRole;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->shop = Shop::factory()->create();
    WooCommerceConnection::factory()->for($this->shop)->create();
});

test('the database refuses a second active run for a shop', function (bool $running) {
    $running
        ? SyncRun::factory()->for($this->shop)->running()->create()
        : SyncRun::factory()->for($this->shop)->create();

    expect(fn () => SyncRun::factory()->for($this->shop)->create())
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['queued' => [false], 'running' => [true]]);

test('starting a run while one is active returns null without queueing', function () {
    Queue::fake();

    SyncRun::factory()->for($this->shop)->create();

    expect(app(StartSyncRun::class)->handle($this->shop, SyncTrigger::Manual))->toBeNull()
        ->and($this->shop->syncRuns()->count())->toBe(1);

    Queue::assertNothingPushed();
});

test('a finished run frees the shop for the next one', function (string $state) {
    Queue::fake();

    $finished = SyncRun::factory()->for($this->shop)->create();
    $state === 'completed' ? $finished->markCompleted() : $finished->markFailed('Nope.');

    $run = app(StartSyncRun::class)->handle($this->shop, SyncTrigger::Manual);

    expect($run)->not->toBeNull()
        ->and($finished->refresh()->active_shop_id)->toBeNull()
        ->and($run->active_shop_id)->toBe($this->shop->id);

    Queue::assertPushed(SyncWooCommerceProducts::class);
})->with(['completed', 'failed']);

test('runs of different shops do not block each other', function () {
    Queue::fake();

    SyncRun::factory()->running()->create();

    expect(app(StartSyncRun::class)->handle($this->shop, SyncTrigger::Manual))->not->toBeNull();
});

test('a run whose job cannot be queued is failed and frees the shop', function () {
    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('Queue unavailable'));

    $run = app(StartSyncRun::class)->handle($this->shop, SyncTrigger::Manual);

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->message)->toBe('The sync could not be queued. Please try again.')
        ->and($run->refresh()->active_shop_id)->toBeNull();
});

test('a manual sync that cannot be queued tells the user', function () {
    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('Queue unavailable'));

    $user = User::factory()->create();
    $this->shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $this
        ->actingAs($user)
        ->post(route('shops.sync.runs.store', $this->shop))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The sync could not be queued. Please try again.',
        ]);
});

test('a late retry does not revive a run that already finished', function () {
    Http::preventStrayRequests();

    $run = SyncRun::factory()->for($this->shop)->create();
    $run->markFailed('The sync timed out.');

    app()->call([new SyncWooCommerceProducts($run), 'handle']);

    expect($run->refresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($run->started_at)->toBeNull();
});
