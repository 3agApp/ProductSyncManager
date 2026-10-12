<?php

use App\Jobs\ExportNaldaOrderStatuses;
use App\Models\MarketplaceOrder;
use App\Models\NaldaConnection;
use App\Models\Shop;
use App\Models\WooCommerceConnection;
use App\Services\Nalda\NaldaSftp;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A shop sending Nalda order statuses, with one imported order.
 */
function statusShop(): Shop
{
    $shop = Shop::factory()->create();
    NaldaConnection::factory()->for($shop)->create(['api_key' => 'test-key', 'import_orders' => true, 'delivery_time_days' => 3]);
    WooCommerceConnection::factory()->verified()->for($shop)->create(['store_url' => 'https://toys.example']);

    return $shop;
}

test('delivery details are read back from the store and sent to nalda', function () {
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 5));
    $shop = statusShop();
    MarketplaceOrder::factory()->nalda()->for($shop)->create([
        'external_order_id' => '469328',
        'woocommerce_order_id' => 9100,
        'gtins' => ['4251450942600', '7640000000001'],
        'delivery_status' => 'IN_PREPARATION',
        'ordered_at' => now()->subDay(),
    ]);
    // Too old for the file.
    MarketplaceOrder::factory()->nalda()->for($shop)->create([
        'external_order_id' => '100000',
        'gtins' => ['111'],
        'delivery_status' => 'DELIVERED',
        'ordered_at' => now()->subDays(90),
    ]);

    $requests = [];
    Http::fake(function (Request $request) use (&$requests) {
        $requests[] = $request->url();

        return Http::response([[
            'id' => 9100,
            'date_modified_gmt' => '2026-10-12T08:00:00',
            'meta_data' => [
                ['id' => 1, 'key' => '_wpsm_marketplace', 'value' => 'nalda'],
                ['id' => 2, 'key' => '_wpsm_marketplace_order_id', 'value' => '469328'],
                ['id' => 3, 'key' => '_wpsm_delivery_status', 'value' => 'IN_DELIVERY'],
                ['id' => 4, 'key' => '_wpsm_tracking_code', 'value' => '41000123456789'],
            ],
        ]], 200, ['X-WP-TotalPages' => '1']);
    });

    $uploaded = null;
    $this->mock(NaldaSftp::class)
        ->shouldReceive('upload')
        ->once()
        ->withArgs(function (NaldaConnection $connection, string $fileName, $stream) use (&$uploaded) {
            $uploaded = stream_get_contents($stream);

            return $fileName === 'order-status/order_status_2026-10-12.csv';
        });

    dispatch_sync(new ExportNaldaOrderStatuses($shop->id));

    expect($requests[0])->toContain('modified_after=')
        ->and($uploaded)->toBe(
            "orderId,gtin,state,expectedDeliveryDate,trackingCode\n"
            ."469328,4251450942600,IN_DELIVERY,14.10.26,41000123456789\n"
            ."469328,7640000000001,IN_DELIVERY,14.10.26,41000123456789\n"
        )
        ->and($shop->wooCommerceConnection->fresh()->marketplace_orders_checked_at)->not->toBeNull()
        ->and($shop->naldaConnection->fresh()->statuses_exported_at)->not->toBeNull();
});

test('nothing is uploaded when no order has a state', function () {
    $shop = statusShop();
    Http::fake(['*' => Http::response([], 200, ['X-WP-TotalPages' => '1'])]);
    $this->mock(NaldaSftp::class)->shouldNotReceive('upload');

    dispatch_sync(new ExportNaldaOrderStatuses($shop->id));

    expect($shop->naldaConnection->fresh()->statuses_exported_at)->toBeNull();
});

test('the file is sent five minutes after each import', function () {
    $shop = statusShop();
    Queue::fake();

    $this->travelTo(now()->setTime(10, 20));
    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertPushed(ExportNaldaOrderStatuses::class, fn (ExportNaldaOrderStatuses $job) => $job->shopId === $shop->id);
});
