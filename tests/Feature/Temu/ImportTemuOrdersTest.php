<?php

use App\Enums\MarketplaceOrderStatus;
use App\Enums\ShopRole;
use App\Jobs\ImportTemuOrders;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Models\TemuProduct;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const TEMU_ORDER_SN = 'PO-192-13753655163510164';

/**
 * A shop that imports Temu orders, with one product listed on Temu.
 *
 * @return array{0: Shop, 1: Product, 2: TemuProduct}
 */
function orderShop(): array
{
    $shop = Shop::factory()->create(['prices_include_tax' => true, 'tax_rate' => '8.10', 'currency' => 'CHF']);
    TemuConnection::factory()->for($shop)->create(['import_orders' => true]);
    WooCommerceConnection::factory()->verified()->for($shop)->create(['store_url' => 'https://toys.example']);

    $product = Product::factory()->for($shop)->create(['sku' => 'B003-001-070', 'remote_id' => 4321]);
    $record = TemuProduct::factory()->forProduct($product)->create(['sku_id' => 150607323233142, 'sent_price' => '6.20']);

    return [$shop, $product, $record];
}

/**
 * A Temu order as bg.order.list.v2.get and bg.order.detail.v2.get return it.
 *
 * @param  array<string, mixed>  $parent
 * @param  array<string, mixed>  $line
 * @return array<string, mixed>
 */
function temuOrder(array $parent = [], array $line = []): array
{
    return [
        'parentOrderMap' => [
            'parentOrderSn' => TEMU_ORDER_SN,
            'parentOrderStatus' => 2,
            'parentOrderTime' => 1791743026,
            'expectShipLatestTime' => 1791930600,
            'regionId' => 192,
            ...$parent,
        ],
        'orderList' => [[
            'orderSn' => '192-13753660406390164',
            'goodsId' => 609474295220495,
            'skuId' => 150607323233142,
            'goodsName' => '24 Adventskalender Zahlenaufkleber – gold Nr70',
            'spec' => '24 / Gold',
            'quantity' => 1,
            'orderStatus' => 2,
            'productList' => [['extCode' => 'B003-001-070', 'productSkuId' => 234060598325]],
            ...$line,
        ]],
    ];
}

/**
 * @return array<string, mixed>
 */
function temuAddress(): array
{
    return [
        'receiptName' => 'Anna Muster',
        'addressExtra' => ['firstName' => 'Anna', 'lastName' => 'Muster'],
        'addressLine1' => 'Bahnhofstrasse 1',
        'addressLine2' => '',
        'regionName1' => 'Switzerland',
        'regionName2' => 'St. Gallen',
        'regionName3' => 'Flumserberg',
        'postCode' => '8898',
        'mobile' => '+41790000000',
        'mail' => 'relay@temu.example',
    ];
}

/**
 * Fake Temu and WooCommerce, recording every call to each.
 *
 * @param  array<int, array<string, mixed>>  $orders  Orders in Temu's list.
 * @param  array<string, mixed>  $calls
 * @param  callable|null  $woo  Answers WooCommerce requests; creates order 9001 by default.
 */
function fakeOrderApis(array $orders, array &$calls, ?callable $woo = null): void
{
    $calls = ['temu' => [], 'woo' => []];

    Http::fake(function (Request $request) use ($orders, &$calls, $woo) {
        if (str_contains($request->url(), 'toys.example')) {
            $calls['woo'][] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];

            return $woo !== null ? $woo($request) : Http::response(['id' => 9001, 'status' => 'processing'], 201);
        }

        $data = $request->data();
        $calls['temu'][] = $data;

        $result = match ($data['type']) {
            'bg.order.list.v2.get' => ['totalItemNum' => count($orders), 'pageItems' => $orders],
            'bg.order.detail.v2.get' => $orders[0] ?? [],
            'bg.order.shippinginfo.v2.get' => temuAddress(),
            default => [],
        };

        return Http::response(['success' => true, 'errorCode' => 1000000, 'errorMsg' => '', 'result' => $result]);
    });
}

/**
 * @param  array<string, mixed>  $calls
 * @return array<int, array<string, mixed>>
 */
function wooOrderCreates(array $calls): array
{
    return array_values(array_filter($calls['woo'], fn (array $call) => $call['method'] === 'POST' && str_ends_with($call['url'], '/orders')));
}

test('an unshipped temu order is created in woocommerce, billed to temu', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder()], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $creates = wooOrderCreates($calls);
    expect($creates)->toHaveCount(1);

    $payload = $creates[0]['data'];
    expect($payload['status'])->toBe('processing')
        ->and($payload['set_paid'])->toBeTrue()
        ->and($payload['payment_method_title'])->toBe('Temu')
        ->and($payload['transaction_id'])->toBe(TEMU_ORDER_SN)
        ->and($payload['billing'])->toMatchArray(['company' => 'Whaleco Switzerland AG', 'address_1' => 'Teichgässlein 9', 'postcode' => '4058', 'city' => 'Basel', 'country' => 'CH'])
        ->and($payload['billing'])->not->toHaveKey('email')
        ->and($payload['shipping'])->toMatchArray([
            'first_name' => 'Anna',
            'last_name' => 'Muster',
            'address_1' => 'Bahnhofstrasse 1',
            'city' => 'Flumserberg',
            'state' => 'SG',
            'postcode' => '8898',
            'country' => 'CH',
            'phone' => '+41790000000',
        ])
        ->and($payload['shipping'])->not->toHaveKey('email')
        ->and($payload['line_items'])->toHaveCount(1)
        ->and($payload['line_items'][0]['product_id'])->toBe(4321)
        ->and($payload['line_items'][0]['quantity'])->toBe(1)
        // 6.20 including 8.1% tax, which WooCommerce adds back.
        ->and($payload['line_items'][0]['total'])->toBe('5.7354')
        // The 5.50 Temu pays for shipping, also including tax.
        ->and($payload['shipping_lines'])->toBe([['method_id' => 'temu', 'method_title' => 'Temu', 'total' => '5.0879']]);

    $note = collect($calls['woo'])->first(fn (array $call) => str_ends_with($call['url'], '/orders/9001/notes'));
    expect($note['data']['note'])->toContain(TEMU_ORDER_SN);

    $row = $shop->marketplaceOrders()->sole();
    expect($row->status)->toBe(MarketplaceOrderStatus::Imported)
        ->and($row->woocommerce_order_id)->toBe(9001)
        ->and($row->ship_by_at->getTimestamp())->toBe(1791930600)
        ->and($shop->temuConnection->fresh()->orders_checked_at)->not->toBeNull();
});

test('a temu order carries the marketplace meta the woocommerce plugin reads', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder()], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $payload = wooOrderCreates($calls)[0]['data'];
    $meta = collect($payload['meta_data'])->pluck('value', 'key');

    expect($meta['_wpsm_marketplace'])->toBe('temu')
        ->and($meta['_wpsm_marketplace_order_id'])->toBe(TEMU_ORDER_SN)
        ->and($meta['_wpsm_end_customer_email'])->toBe('relay@temu.example')
        ->and($meta['_wpsm_shipping_payout'])->toBe('5.50')
        ->and($meta['_wpsm_ship_by'])->toBe('2026-10-13T22:30:00+00:00')
        ->and($meta['_billing_vat_number'])->toBe('CHE-245.617.681')
        ->and($meta)->not->toHaveKey('_temu_parent_order_sn')
        ->and(collect($payload['line_items'][0]['meta_data'])->pluck('value', 'key')['_wpsm_marketplace_item_id'])->toBe('192-13753660406390164');
});

test('an order is imported only once', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder()], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));
    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(wooOrderCreates($calls))->toHaveCount(1)
        ->and($shop->marketplaceOrders()->count())->toBe(1);
});

test('a later check only asks temu for recently updated orders', function () {
    [$shop] = orderShop();
    $shop->temuConnection->update(['orders_checked_at' => now()->subMinutes(5)]);
    $calls = [];
    fakeOrderApis([], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $list = $calls['temu'][0];
    expect($list['type'])->toBe('bg.order.list.v2.get')
        ->and($list['updateAtStart'])->toBe(now()->subMinutes(35)->getTimestamp())
        ->and($list['updateAtEnd'])->toBe(now()->getTimestamp());
});

test('a pending temu order waits until temu releases it', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder(['parentOrderStatus' => 1])], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(wooOrderCreates($calls))->toBe([])
        ->and($shop->marketplaceOrders()->sole()->status)->toBe(MarketplaceOrderStatus::Pending);
});

test('orders handled before importing started are left alone', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder(['parentOrderStatus' => 4])], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(wooOrderCreates($calls))->toBe([])
        ->and($shop->marketplaceOrders()->count())->toBe(0);
});

test('an imported order cancelled on temu is cancelled in woocommerce', function () {
    [$shop] = orderShop();
    MarketplaceOrder::factory()->for($shop)->create(['external_order_id' => TEMU_ORDER_SN, 'woocommerce_order_id' => 9001]);
    $calls = [];
    fakeOrderApis([temuOrder(['parentOrderStatus' => 3])], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $update = collect($calls['woo'])->first(fn (array $call) => $call['method'] === 'PUT');
    expect($update['url'])->toEndWith('/orders/9001')
        ->and($update['data'])->toBe(['status' => 'cancelled'])
        ->and($shop->marketplaceOrders()->sole()->status)->toBe(MarketplaceOrderStatus::Cancelled);
});

test('a line whose product left the catalog is sent by name', function () {
    [$shop, $product] = orderShop();
    $product->delete();
    $calls = [];
    fakeOrderApis([temuOrder()], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $item = wooOrderCreates($calls)[0]['data']['line_items'][0];
    expect($item)->not->toHaveKey('product_id')
        ->and($item['name'])->toBe('24 Adventskalender Zahlenaufkleber – gold Nr70 24 / Gold');

    $note = collect($calls['woo'])->first(fn (array $call) => str_ends_with($call['url'], '/notes'));
    expect($note['data']['note'])->toContain('Not found in the catalog');
});

test('an order with an item temu has no price for fails', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder(line: ['skuId' => 1, 'productList' => [['extCode' => 'UNKNOWN']]])], $calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $row = $shop->marketplaceOrders()->sole();
    expect(wooOrderCreates($calls))->toBe([])
        ->and($row->status)->toBe(MarketplaceOrderStatus::Failed)
        ->and($row->message)->toContain('No Temu price');
});

test('an order woocommerce refuses fails and can be imported again', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder()], $calls, fn () => Http::response(['code' => 'woocommerce_rest_invalid_product_id'], 400));

    dispatch_sync(new ImportTemuOrders($shop->id));

    $row = $shop->marketplaceOrders()->sole();
    expect($row->status)->toBe(MarketplaceOrderStatus::Failed)
        ->and($row->message)->toContain('HTTP 400');

    $owner = User::factory()->create();
    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    Queue::fake();

    $this->actingAs($owner)
        ->post(route('shops.marketplace-orders.retry', ['shop' => $shop->slug, 'marketplaceOrder' => $row->id]))
        ->assertRedirect();

    expect($row->fresh()->status)->toBe(MarketplaceOrderStatus::Pending);
    Queue::assertPushed(ImportTemuOrders::class);
});

test('an order whose import was cut off is never sent again by itself', function () {
    [$shop] = orderShop();
    $calls = [];
    fakeOrderApis([temuOrder()], $calls, fn () => throw new ConnectionException('timed out'));

    dispatch_sync(new ImportTemuOrders($shop->id));
    expect($shop->marketplaceOrders()->sole()->status)->toBe(MarketplaceOrderStatus::Creating);

    fakeOrderApis([temuOrder()], $calls);
    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(wooOrderCreates($calls))->toBe([])
        ->and($shop->marketplaceOrders()->sole()->status)->toBe(MarketplaceOrderStatus::Creating);
});

test('only shops set up to import orders are checked', function () {
    [$importing] = orderShop();

    $off = Shop::factory()->create();
    TemuConnection::factory()->for($off)->create(['import_orders' => false]);
    WooCommerceConnection::factory()->verified()->for($off)->create();

    $noStore = Shop::factory()->create();
    TemuConnection::factory()->for($noStore)->create(['import_orders' => true]);

    Queue::fake();
    $this->travelTo(now()->setTime(10, 5));

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Queue::assertPushed(ImportTemuOrders::class, 1);
    Queue::assertPushed(ImportTemuOrders::class, fn (ImportTemuOrders $job) => $job->shopId === $importing->id);
});
