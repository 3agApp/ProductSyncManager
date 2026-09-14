<?php

use App\Actions\Nalda\StartNaldaExport;
use App\Actions\Sync\ImportWooCommerceProducts;
use App\Enums\ShopType;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\QueueNaldaUploadAfterWebhooks;
use App\Jobs\SyncWooCommerceWebhookDeliveries;
use App\Jobs\UploadNaldaProductFeed;
use App\Models\NaldaConnection;
use App\Models\Shop;
use App\Models\WooCommerceConnection;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(['*/wp-json/wc/v3/products?*' => Http::response([
        ['id' => 42, 'type' => 'simple', 'status' => 'publish', 'name' => 'Toy', 'sku' => 'TOY', 'price' => '5', 'stock_quantity' => 0],
    ])]);

    $this->connection = WooCommerceConnection::factory()->for(Shop::factory()->state(['type' => ShopType::Wordpress]))->create();
    $this->shop = $this->connection->shop;
});

function applyWebhookDelivery($test): void
{
    DB::table('woocommerce_webhook_deliveries')->insert([
        'connection_id' => $test->connection->id,
        'delivery_id' => (string) Str::uuid(),
        'product_id' => 42,
        'store_url' => $test->connection->store_url,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new SyncWooCommerceWebhookDeliveries($test->connection->id, $test->shop->id))->handle(app(ImportWooCommerceProducts::class));
}

function runNaldaUploadAfterWebhooks(Shop $shop, ?Job $job = null): void
{
    $upload = new QueueNaldaUploadAfterWebhooks($shop->id);

    if ($job !== null) {
        $upload->setJob($job);
    }

    $upload->handle(app(StartNaldaExport::class));
}

test('webhook changes queue one delayed nalda upload per shop', function () {
    NaldaConnection::factory()->for($this->shop)->create();

    applyWebhookDelivery($this);
    applyWebhookDelivery($this);

    Queue::assertPushed(QueueNaldaUploadAfterWebhooks::class, 1);
    Queue::assertPushed(QueueNaldaUploadAfterWebhooks::class, fn (QueueNaldaUploadAfterWebhooks $job) => $job->shopId === $this->shop->id
        && $job->delay === QueueNaldaUploadAfterWebhooks::DELAY_SECONDS);
    Queue::assertNotPushed(UploadNaldaProductFeed::class);
});

test('shops that do not upload after sync queue nothing', function (bool $connected) {
    if ($connected) {
        NaldaConnection::factory()->for($this->shop)->create(['upload_after_sync' => false]);
    }

    applyWebhookDelivery($this);

    Queue::assertNotPushed(QueueNaldaUploadAfterWebhooks::class);
})->with([
    'no nalda connection' => false,
    'upload after sync off' => true,
]);

test('the delayed job starts a webhook upload', function () {
    NaldaConnection::factory()->for($this->shop)->create();

    runNaldaUploadAfterWebhooks($this->shop);

    $export = $this->shop->naldaExports()->sole();

    expect($export->trigger)->toBe(SyncTrigger::Webhook)
        ->and($export->status)->toBe(SyncRunStatus::Queued);
    Queue::assertPushed(UploadNaldaProductFeed::class, fn (UploadNaldaProductFeed $job) => $job->export->is($export));
});

test('changes made while an upload builds are sent once it is done', function () {
    NaldaConnection::factory()->for($this->shop)->create();
    $this->shop->naldaExports()->create(['trigger' => SyncTrigger::Scheduled, 'status' => SyncRunStatus::Running, 'started_at' => now()]);

    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(QueueNaldaUploadAfterWebhooks::RETRY_SECONDS);

    runNaldaUploadAfterWebhooks($this->shop, $queued);

    expect($this->shop->naldaExports()->count())->toBe(1);
});

test('an upload still queued carries the changes without another one', function () {
    NaldaConnection::factory()->for($this->shop)->create();
    $this->shop->naldaExports()->create(['trigger' => SyncTrigger::Scheduled, 'status' => SyncRunStatus::Queued]);

    $queued = Mockery::mock(Job::class);
    $queued->shouldNotReceive('release');

    runNaldaUploadAfterWebhooks($this->shop, $queued);

    expect($this->shop->naldaExports()->count())->toBe(1);
    Queue::assertNotPushed(UploadNaldaProductFeed::class);
});

test('the delayed job uploads nothing once the shop stops uploading after sync', function (Closure $state) {
    $state($this->shop);

    $queued = Mockery::mock(Job::class);
    $queued->shouldNotReceive('release');

    runNaldaUploadAfterWebhooks($this->shop, $queued);

    expect($this->shop->naldaExports()->count())->toBe(0);
    Queue::assertNotPushed(UploadNaldaProductFeed::class);
})->with([
    'setting turned off' => [fn (Shop $shop) => NaldaConnection::factory()->for($shop)->create(['upload_after_sync' => false])],
    'credentials removed' => [fn (Shop $shop) => NaldaConnection::factory()->withoutCredentials()->for($shop)->create()],
    'connection removed' => [fn (Shop $shop) => null],
]);
