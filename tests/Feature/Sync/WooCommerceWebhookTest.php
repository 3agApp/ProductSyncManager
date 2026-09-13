<?php

use App\Actions\Sync\ImportWooCommerceProducts;
use App\Actions\Sync\SaveWooCommerceConnection;
use App\Enums\ProductStatus;
use App\Enums\ShopType;
use App\Jobs\SyncWooCommerceProduct;
use App\Jobs\SyncWooCommerceProducts;
use App\Jobs\SyncWooCommerceWebhookDeliveries;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    Sleep::fake();
    $this->connection = WooCommerceConnection::factory()->for(Shop::factory()->state(['type' => ShopType::Wordpress]))->create([
        'webhook_secret' => str_repeat('s', 32),
    ]);
});

function sendProductWebhook($test, string $body = '{"id":42}', string $topic = 'product.updated', string $signature = '', string $delivery = 'delivery-1')
{
    return $test->call('POST', route('woocommerce.webhook', $test->connection), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
        'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => $delivery,
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature ?: base64_encode(hash_hmac('sha256', $body, $test->connection->webhook_secret, true)),
    ], $body);
}

function runWebhookDeliveries($test, ?Job $job = null): void
{
    $sync = new SyncWooCommerceWebhookDeliveries($test->connection->id, $test->connection->shop_id);
    if ($job !== null) {
        $sync->setJob($job);
    }
    $sync->handle(app(ImportWooCommerceProducts::class));
}

/**
 * Fake a store that answers the batched product lookup with every requested id.
 *
 * @return ArrayObject<int, int> request sizes, filled as requests arrive
 */
function fakeStoreProducts(?Closure $onRequest = null): ArrayObject
{
    $sizes = new ArrayObject;
    Http::fake(['*/wp-json/wc/v3/products?*' => function (Request $request) use ($sizes, $onRequest) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $ids = array_map('intval', explode(',', $query['include']));
        $sizes[] = count($ids);
        $onRequest?->__invoke();

        return Http::response(array_map(fn (int $id) => [
            'id' => $id, 'type' => 'simple', 'status' => 'publish', 'name' => "Product {$id}", 'sku' => "SKU-{$id}", 'price' => '5', 'stock_quantity' => 3,
        ], $ids));
    }]);

    return $sizes;
}

test('signed product webhooks queue without authentication and duplicates share one receipt', function () {
    sendProductWebhook($this)->assertAccepted();
    sendProductWebhook($this)->assertAccepted();
    expect(DB::table('woocommerce_webhook_deliveries')->count())->toBe(1);
    Queue::assertPushed(SyncWooCommerceWebhookDeliveries::class, fn ($job) => $job->connectionId === $this->connection->id);
    Http::assertNothingSent();
});

test('rejects invalid signatures, payloads and unsupported topics', function () {
    sendProductWebhook($this, signature: 'invalid')->assertUnauthorized();
    sendProductWebhook($this, '{"id":0}')->assertUnprocessable();
    sendProductWebhook($this, topic: 'order.updated')->assertUnprocessable();
    Queue::assertNothingPushed();
});

test('activation ping returns 200 without enqueuing work', function () {
    $this->call('POST', route('woocommerce.webhook', $this->connection), [], [], [], [], 'webhook_id=12')->assertOk();
    Queue::assertNothingPushed();
});

test('webhooks require a configured secret and cannot cross shops', function () {
    $this->connection->update(['webhook_secret' => str_repeat('x', 32)]);
    sendProductWebhook($this, signature: base64_encode(hash_hmac('sha256', '{"id":42}', str_repeat('s', 32), true)))->assertUnauthorized();
    $this->connection->update(['webhook_secret' => null]);
    $this->postJson(route('woocommerce.webhook', $this->connection), ['id' => 42])->assertForbidden();
    Queue::assertNothingPushed();
});

test('fetches current product instead of trusting stale payload and only applies a receipt once', function () {
    Http::fake(['*/wp-json/wc/v3/products?*' => Http::response([['id' => 42, 'type' => 'simple', 'status' => 'publish', 'name' => 'Current name', 'sku' => 'NEW', 'price' => '15', 'stock_quantity' => 9]])]);
    sendProductWebhook($this, '{"id":42,"name":"Old name"}', 'product.deleted')->assertAccepted();
    runWebhookDeliveries($this);
    runWebhookDeliveries($this);
    expect($this->connection->shop->products()->sole()->name)->toBe('Current name');
    expect(DB::table('woocommerce_webhook_deliveries')->sole()->processed_at)->not->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'include=42') && str_contains($request->url(), 'status=any'));
    sendProductWebhook($this, '{"id":42,"name":"Old name"}', 'product.deleted')->assertAccepted();
    Queue::assertPushed(SyncWooCommerceWebhookDeliveries::class, 1);
});

test('a burst of deliveries costs one request per hundred products', function () {
    $sizes = fakeStoreProducts();
    foreach (range(1, 150) as $id) {
        sendProductWebhook($this, json_encode(['id' => $id]), delivery: "first-{$id}")->assertAccepted();
    }
    // The same products saved again before the job ran: no extra requests.
    foreach (range(1, 50) as $id) {
        sendProductWebhook($this, json_encode(['id' => $id, 'again' => true]), delivery: "second-{$id}")->assertAccepted();
    }

    runWebhookDeliveries($this);

    expect($sizes->getArrayCopy())->toBe([100, 50])
        ->and($this->connection->shop->products()->count())->toBe(150)
        ->and(DB::table('woocommerce_webhook_deliveries')->whereNull('processed_at')->count())->toBe(0);
    Queue::assertPushed(SyncWooCommerceWebhookDeliveries::class, 1);
});

test('a long backlog steps aside for the rest of the queue', function () {
    fakeStoreProducts(fn () => $this->travel(5)->minutes());
    foreach (range(1, 150) as $id) {
        sendProductWebhook($this, json_encode(['id' => $id]), delivery: "d-{$id}")->assertAccepted();
    }
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(1);

    runWebhookDeliveries($this, $queued);

    expect(DB::table('woocommerce_webhook_deliveries')->whereNull('processed_at')->count())->toBe(50);
});

test('archives only the affected remote product when unavailable or no longer importable', function (array $payload) {
    $product = Product::factory()->for($this->connection->shop)->create(['remote_id' => 42, 'status' => ProductStatus::Active]);
    $manual = Product::factory()->for($this->connection->shop)->create(['remote_id' => null, 'status' => ProductStatus::Active]);
    $otherShop = Product::factory()->create(['remote_id' => 42, 'status' => ProductStatus::Active]);
    Http::fake(['*/wp-json/wc/v3/products?*' => Http::response($payload)]);
    sendProductWebhook($this)->assertAccepted();
    runWebhookDeliveries($this);
    expect($product->refresh()->status)->toBe(ProductStatus::Archived)
        ->and($manual->refresh()->status)->toBe(ProductStatus::Active)
        ->and($otherShop->refresh()->status)->toBe(ProductStatus::Active);
})->with([
    'no longer in the store' => [[]],
    'unpublished' => [[['id' => 42, 'status' => 'draft', 'type' => 'simple']]],
    'now variable' => [[['id' => 42, 'status' => 'publish', 'type' => 'variable']]],
]);

test('unexpected failures do not archive and leave delivery retryable', function (int $status, mixed $payload) {
    $product = Product::factory()->for($this->connection->shop)->create(['remote_id' => 42, 'status' => ProductStatus::Active]);
    Http::fake(['*/wp-json/wc/v3/products?*' => Http::response($payload, $status)]);
    sendProductWebhook($this)->assertAccepted();
    expect(fn () => runWebhookDeliveries($this))->toThrow(WooCommerceRequestException::class);
    expect($product->refresh()->status)->toBe(ProductStatus::Active)
        ->and(DB::table('woocommerce_webhook_deliveries')->sole()->processed_at)->toBeNull();
})->with([
    'html error page' => [404, '<html>Missing</html>'],
    'server error' => [500, ['message' => 'Unavailable']],
    'not a list' => [200, ['id' => 42]],
    'a product that was not asked for' => [200, [['id' => 99, 'status' => 'publish', 'type' => 'simple']]],
]);

test('full and incremental sync share a lock and release blocked jobs', function () {
    $full = new SyncWooCommerceProducts(SyncRun::factory()->for($this->connection->shop)->create());
    $incremental = new SyncWooCommerceWebhookDeliveries($this->connection->id, $this->connection->shop_id);
    $fullMiddleware = $full->middleware()[0];
    $incrementalMiddleware = $incremental->middleware()[0];
    expect($fullMiddleware->getLockKey($full))->toBe($incrementalMiddleware->getLockKey($incremental));
    $lock = Cache::lock($fullMiddleware->getLockKey($full), 60);
    $lock->get();
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('release')->once()->with(30);
    $incremental->setJob($queued);
    $incrementalMiddleware->handle($incremental, fn () => throw new RuntimeException('Must not run concurrently'));
    $lock->release();
});

test('waiting behind a full sync retries by time, not by attempts', function () {
    $job = new SyncWooCommerceWebhookDeliveries($this->connection->id, $this->connection->shop_id);

    expect($job->retryUntil()->isAfter(now()->addHours(11)))->toBeTrue()
        ->and(property_exists($job, 'tries'))->toBeFalse()
        ->and($job->uniqueId())->toBe((string) $this->connection->id);
});

test('a job queued by an earlier release hands over to the batched job', function () {
    $deliveryId = DB::table('woocommerce_webhook_deliveries')->insertGetId([
        'connection_id' => $this->connection->id,
        'delivery_id' => str_repeat('a', 64),
        'product_id' => 42,
        'store_url' => $this->connection->store_url,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new SyncWooCommerceProduct($deliveryId, $this->connection->shop_id))->handle();

    Queue::assertPushed(SyncWooCommerceWebhookDeliveries::class, fn ($job) => $job->connectionId === $this->connection->id);
    Http::assertNothingSent();
});

test('changing a store prevents pending deliveries from touching its new catalog', function () {
    sendProductWebhook($this)->assertAccepted();
    $this->connection->update(['store_url' => 'https://another.test']);
    runWebhookDeliveries($this);
    Http::assertNothingSent();
});

test('webhook secret is encrypted, retained when blank, and cleared when changing store', function () {
    $save = app(SaveWooCommerceConnection::class);
    $shop = $this->connection->shop;
    $saved = $save->handle($shop, ['store_url' => $this->connection->store_url, 'webhook_secret' => '']);
    expect($saved->webhook_secret)->toBe(str_repeat('s', 32));
    expect(DB::table('woocommerce_connections')->where('id', $saved->id)->value('webhook_secret'))->not->toBe($saved->webhook_secret);
    $saved = $save->handle($shop, ['store_url' => 'https://different.test']);
    expect($saved->webhook_secret)->toBeNull();
});
