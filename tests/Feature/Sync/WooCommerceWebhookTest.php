<?php

use App\Actions\Sync\ImportWooCommerceProducts;
use App\Actions\Sync\SaveWooCommerceConnection;
use App\Enums\ProductStatus;
use App\Enums\ShopType;
use App\Jobs\SyncWooCommerceProduct;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use Illuminate\Contracts\Queue\Job;
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

function sendProductWebhook($test, string $body = '{"id":42}', string $topic = 'product.updated', string $signature = '')
{
    return $test->call('POST', route('woocommerce.webhook', $test->connection), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_WC_WEBHOOK_TOPIC' => $topic,
        'HTTP_X_WC_WEBHOOK_DELIVERY_ID' => 'delivery-1',
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature ?: base64_encode(hash_hmac('sha256', $body, $test->connection->webhook_secret, true)),
    ], $body);
}

function runProductWebhook($test): void
{
    $delivery = DB::table('woocommerce_webhook_deliveries')->sole();
    (new SyncWooCommerceProduct($delivery->id, $test->connection->shop_id))->handle(app(ImportWooCommerceProducts::class));
}

test('signed product webhooks queue without authentication and duplicates share one receipt', function () {
    sendProductWebhook($this)->assertAccepted();
    sendProductWebhook($this)->assertAccepted();
    expect(DB::table('woocommerce_webhook_deliveries')->count())->toBe(1);
    Queue::assertPushed(SyncWooCommerceProduct::class);
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
    Http::fake(['*/products/42' => Http::response(['id' => 42, 'type' => 'simple', 'status' => 'publish', 'name' => 'Current name', 'sku' => 'NEW', 'price' => '15', 'stock_quantity' => 9])]);
    sendProductWebhook($this, '{"id":42,"name":"Old name"}', 'product.deleted')->assertAccepted();
    runProductWebhook($this);
    runProductWebhook($this);
    expect($this->connection->shop->products()->sole()->name)->toBe('Current name');
    expect(DB::table('woocommerce_webhook_deliveries')->sole()->processed_at)->not->toBeNull();
    Http::assertSentCount(1);
    sendProductWebhook($this, '{"id":42,"name":"Old name"}', 'product.deleted')->assertAccepted();
    Queue::assertPushed(SyncWooCommerceProduct::class, 1);
});

test('archives only the affected remote product when unavailable or no longer importable', function (int $status, array $payload) {
    $product = Product::factory()->for($this->connection->shop)->create(['remote_id' => 42, 'status' => ProductStatus::Active]);
    $manual = Product::factory()->for($this->connection->shop)->create(['remote_id' => null, 'status' => ProductStatus::Active]);
    $otherShop = Product::factory()->create(['remote_id' => 42, 'status' => ProductStatus::Active]);
    Http::fake(['*/products/42' => Http::response($payload, $status)]);
    sendProductWebhook($this)->assertAccepted();
    runProductWebhook($this);
    expect($product->refresh()->status)->toBe(ProductStatus::Archived)
        ->and($manual->refresh()->status)->toBe(ProductStatus::Active)
        ->and($otherShop->refresh()->status)->toBe(ProductStatus::Active);
})->with([
    [404, ['code' => 'woocommerce_rest_product_invalid_id']],
    [200, ['id' => 42, 'status' => 'draft', 'type' => 'simple']],
    [200, ['id' => 42, 'status' => 'publish', 'type' => 'variable']],
]);

test('unexpected failures do not archive and leave delivery retryable', function (int $status, mixed $payload) {
    $product = Product::factory()->for($this->connection->shop)->create(['remote_id' => 42, 'status' => ProductStatus::Active]);
    Http::fake(['*/products/42' => Http::response($payload, $status)]);
    sendProductWebhook($this)->assertAccepted();
    expect(fn () => runProductWebhook($this))->toThrow(WooCommerceRequestException::class);
    expect($product->refresh()->status)->toBe(ProductStatus::Active)
        ->and(DB::table('woocommerce_webhook_deliveries')->sole()->processed_at)->toBeNull();
})->with([[404, '<html>Missing</html>'], [500, ['message' => 'Unavailable']], [200, ['id' => 99]]]);

test('full and incremental sync share a lock and release blocked jobs', function () {
    $full = new SyncWooCommerceProducts(SyncRun::factory()->for($this->connection->shop)->create());
    $incremental = new SyncWooCommerceProduct(1, $this->connection->shop_id);
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

test('changing a store prevents pending deliveries from touching its new catalog', function () {
    sendProductWebhook($this)->assertAccepted();
    $this->connection->update(['store_url' => 'https://another.test']);
    runProductWebhook($this);
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
