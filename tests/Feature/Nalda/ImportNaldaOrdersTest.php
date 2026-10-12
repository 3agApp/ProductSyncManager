<?php

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Jobs\ImportNaldaOrders;
use App\Models\MarketplaceOrder;
use App\Models\NaldaConnection;
use App\Models\Product;
use App\Models\Shop;
use App\Models\WooCommerceConnection;
use App\Support\MarketplaceOrderMeta as Meta;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * A shop that imports Nalda orders, with one product Nalda sells.
 */
function naldaOrderShop(array $connection = []): Shop
{
    $shop = Shop::factory()->create(['prices_include_tax' => true, 'tax_rate' => '8.10', 'currency' => 'CHF']);
    NaldaConnection::factory()->for($shop)->create(['api_key' => 'test-key', 'import_orders' => true, ...$connection]);
    WooCommerceConnection::factory()->verified()->for($shop)->create(['store_url' => 'https://toys.example']);
    Product::factory()->for($shop)->create(['sku' => 'SCHUL-01', 'gtin' => '4251450942600', 'remote_id' => 7001]);

    return $shop;
}

/**
 * A row of Nalda's order items, as the API answers it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function naldaItem(array $overrides = []): array
{
    return [
        'orderId' => 469328,
        'gtin' => '4251450942600',
        'quantity' => 2,
        'price' => 18.9,
        'status' => 'in_preparation',
        'createdAt' => '2026-10-11T20:47:53.879Z',
        'deliveryDatePlanned' => '2026-10-14T00:00:00.000Z',
        'email' => 'marisa@example.com',
        'country' => 'CH',
        'street1' => 'Eichenweg 68',
        'title' => 'Schultüten Musselin - Blau Mint',
        'postalCode' => '8408',
        'city' => 'Winterthur',
        'firstName' => 'Marisa',
        'lastName' => 'Muster',
        'currency' => 'CHF',
        'payoutStatus' => 'OPEN',
        'refund' => 0,
        'commission' => 6.35,
        'commissionPercentage' => 16.8,
        ...$overrides,
    ];
}

/**
 * Fake Nalda and WooCommerce, recording what was sent to each.
 *
 * @param  array<int, array<string, mixed>>  $items  Nalda's order items.
 * @param  array<int, array<string, mixed>>  $storeOrders  Orders the store already has.
 * @param  array<string, mixed>  $calls
 */
function fakeNaldaOrderApis(array $items, array &$calls, array $storeOrders = []): void
{
    $calls = ['nalda' => [], 'woo' => []];

    Http::fake(function (Request $request) use ($items, $storeOrders, &$calls) {
        if (str_contains($request->url(), 'sellers-api.nalda.com')) {
            $calls['nalda'][] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data(), 'key' => $request->header('X-API-KEY')[0] ?? null];

            if (str_ends_with($request->url(), '/orders/items')) {
                return Http::response(['success' => true, 'message' => 'ok', 'result' => $items]);
            }

            return Http::response(['success' => true, 'message' => 'Order found', 'result' => [
                'orderId' => 469328, 'fee' => 6.5, 'commission' => 1.09, 'payoutStatus' => 'OPEN',
            ]]);
        }

        $calls['woo'][] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];

        if ($request->method() === 'GET') {
            return Http::response($storeOrders, 200, ['X-WP-TotalPages' => '1']);
        }

        return Http::response(['id' => 9100, 'status' => 'processing'], 201);
    });
}

/**
 * @param  array<string, mixed>  $calls
 * @return array<int, array<string, mixed>>
 */
function naldaOrderCreates(array $calls): array
{
    return array_values(array_filter($calls['woo'], fn (array $call) => $call['method'] === 'POST' && str_ends_with($call['url'], '/orders')));
}

/**
 * @param  array<string, mixed>  $payload
 */
function payloadMeta(array $payload, string $key): ?string
{
    return Meta::read($payload, $key);
}

test('a new nalda order is created in woocommerce at what nalda pays out', function () {
    $shop = naldaOrderShop();
    $calls = [];
    fakeNaldaOrderApis([naldaItem()], $calls);

    dispatch_sync(new ImportNaldaOrders($shop->id));

    $creates = naldaOrderCreates($calls);
    expect($creates)->toHaveCount(1);

    $payload = $creates[0]['data'];
    $line = $payload['line_items'][0];

    expect($payload['status'])->toBe('processing')
        ->and($payload['set_paid'])->toBeFalse()
        ->and($payload['billing']['company'])->toBe('Nalda Marketplace AG')
        ->and($payload['billing']['email'])->toBe('orders@nalda.com')
        ->and($payload['shipping'])->toMatchArray(['first_name' => 'Marisa', 'city' => 'Winterthur', 'postcode' => '8408', 'country' => 'CH'])
        ->and($line['product_id'])->toBe(7001)
        ->and($line['quantity'])->toBe(2)
        // 2 × 18.90 less 6.35 commission = 31.45 including 8.1% VAT.
        ->and($line['total'])->toBe('29.0934')
        // The shipping fee 6.50 less its 1.09 commission = 5.41 including VAT.
        ->and($payload['shipping_lines'][0])->toBe(['method_id' => 'nalda', 'method_title' => 'Nalda', 'total' => '5.0046'])
        ->and(payloadMeta($payload, Meta::MARKETPLACE))->toBe('nalda')
        ->and(payloadMeta($payload, Meta::ORDER_ID))->toBe('469328')
        ->and(payloadMeta($payload, Meta::END_CUSTOMER_EMAIL))->toBe('marisa@example.com')
        ->and(payloadMeta($payload, Meta::DELIVERY_STATUS))->toBe('IN_PREPARATION')
        ->and(payloadMeta($payload, Meta::EXPECTED_DELIVERY_DATE))->toBe('2026-10-14')
        ->and(payloadMeta($payload, Meta::CUSTOMER_TOTAL))->toBe('44.30')
        ->and(payloadMeta($payload, Meta::COMMISSION))->toBe('7.44')
        ->and(payloadMeta($payload, '_billing_vat_number'))->toBe('CHE-353.496.457 MWST')
        ->and($calls['nalda'][0]['key'])->toBe('test-key');

    $row = $shop->marketplaceOrders()->sole();
    expect($row->marketplace)->toBe(Marketplace::Nalda)
        ->and($row->status)->toBe(MarketplaceOrderStatus::Imported)
        ->and($row->woocommerce_order_id)->toBe(9100)
        ->and($row->gtins)->toBe(['4251450942600'])
        ->and($shop->naldaConnection->fresh()->orders_checked_at)->not->toBeNull();
});

test('the first check adopts the orders the old plugin imported', function () {
    $shop = naldaOrderShop();
    $calls = [];
    $storeOrder = [
        'id' => 77020,
        'date_created_gmt' => '2026-10-10T20:47:53',
        'meta_data' => [
            ['id' => 1, 'key' => '_nalda_order_id', 'value' => '469328'],
            ['id' => 2, 'key' => '_nalda_delivery_status', 'value' => 'DELIVERED'],
            ['id' => 3, 'key' => '_nalda_payout_status', 'value' => 'PAID_OUT'],
        ],
        'line_items' => [['id' => 5, 'meta_data' => [['id' => 6, 'key' => '_nalda_gtin', 'value' => '4251450942600']]]],
    ];
    fakeNaldaOrderApis([naldaItem(['status' => 'delivered', 'payoutStatus' => 'PAID_OUT'])], $calls, [$storeOrder]);

    dispatch_sync(new ImportNaldaOrders($shop->id));

    $row = $shop->marketplaceOrders()->sole();
    expect(naldaOrderCreates($calls))->toBe([])
        ->and($row->woocommerce_order_id)->toBe(77020)
        ->and($row->delivery_status)->toBe('DELIVERED')
        ->and($row->gtins)->toBe(['4251450942600']);
});

test('an order nalda pays out is marked paid in woocommerce', function () {
    $shop = naldaOrderShop(['orders_checked_at' => now()->subMinutes(15)]);
    MarketplaceOrder::factory()->nalda()->for($shop)->create(['external_order_id' => '469328', 'woocommerce_order_id' => 9100, 'payout_status' => 'PAY_OUT_IN_PROGRESS']);
    $calls = [];
    fakeNaldaOrderApis([naldaItem(['payoutStatus' => 'PAID_OUT'])], $calls);

    dispatch_sync(new ImportNaldaOrders($shop->id));

    $update = collect($calls['woo'])->first(fn (array $call) => $call['method'] === 'PUT');
    expect($update['url'])->toEndWith('/orders/9100')
        ->and($update['data']['set_paid'])->toBeTrue()
        ->and(naldaOrderCreates($calls))->toBe([])
        ->and($shop->marketplaceOrders()->sole()->payout_status)->toBe('PAID_OUT');

    // Nothing more to do on the next check.
    fakeNaldaOrderApis([naldaItem(['payoutStatus' => 'PAID_OUT'])], $calls);
    dispatch_sync(new ImportNaldaOrders($shop->id));
    expect(collect($calls['woo'])->where('method', 'PUT'))->toBeEmpty();
});

test('an order nalda cancels is cancelled in woocommerce', function () {
    $shop = naldaOrderShop(['orders_checked_at' => now()->subMinutes(15)]);
    MarketplaceOrder::factory()->nalda()->for($shop)->create(['external_order_id' => '469328', 'woocommerce_order_id' => 9100, 'payout_status' => 'OPEN']);
    $calls = [];
    fakeNaldaOrderApis([naldaItem(['status' => 'cancelled'])], $calls);

    dispatch_sync(new ImportNaldaOrders($shop->id));

    $update = collect($calls['woo'])->first(fn (array $call) => $call['method'] === 'PUT');
    expect($update['data']['status'])->toBe('cancelled')
        ->and($shop->marketplaceOrders()->sole()->status)->toBe(MarketplaceOrderStatus::Cancelled);
});

test('an article missing from the catalog is sent by name', function () {
    $shop = naldaOrderShop();
    $calls = [];
    fakeNaldaOrderApis([naldaItem(['gtin' => '0000000000017'])], $calls);

    dispatch_sync(new ImportNaldaOrders($shop->id));

    $line = naldaOrderCreates($calls)[0]['data']['line_items'][0];
    expect($line)->not->toHaveKey('product_id')
        ->and($line['name'])->toBe('Schultüten Musselin - Blau Mint');
});

test('a gtin written with leading zeros still finds its product', function () {
    $shop = naldaOrderShop();
    $calls = [];
    fakeNaldaOrderApis([naldaItem(['gtin' => '04251450942600'])], $calls);

    dispatch_sync(new ImportNaldaOrders($shop->id));

    expect(naldaOrderCreates($calls)[0]['data']['line_items'][0]['product_id'])->toBe(7001);
});

test('a shop without the switch, an api key or a store is not checked', function () {
    $off = naldaOrderShop(['import_orders' => false]);
    $noKey = naldaOrderShop(['api_key' => null]);
    $calls = [];
    fakeNaldaOrderApis([naldaItem()], $calls);

    dispatch_sync(new ImportNaldaOrders($off->id));
    dispatch_sync(new ImportNaldaOrders($noKey->id));

    expect($calls['nalda'])->toBe([]);

    Queue::fake();
    $this->travelTo(now()->setTime(10, 15));
    $on = naldaOrderShop();
    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertPushed(ImportNaldaOrders::class, 1);
    Queue::assertPushed(ImportNaldaOrders::class, fn (ImportNaldaOrders $job) => $job->shopId === $on->id);
});
