<?php

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Enums\ShopRole;
use App\Jobs\ImportTemuOrders;
use App\Models\MarketplaceOrder;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Models\User;
use App\Models\WooCommerceConnection;
use App\Support\MarketplaceOrderMeta as Meta;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SHIPPED_ORDER_SN = 'PO-192-13753655163510164';

const PLANZER_TRACKING = '41204200501223377766';

/**
 * A shop that confirms Temu shipments, with one imported order Temu waits on.
 *
 * @param  array<string, mixed>  $order
 * @return array{0: Shop, 1: MarketplaceOrder}
 */
function shippingShop(array $order = []): array
{
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create(['import_orders' => true, 'confirm_shipments' => true, 'orders_checked_at' => now()]);
    WooCommerceConnection::factory()->verified()->for($shop)->create(['store_url' => 'https://toys.example']);

    $row = MarketplaceOrder::factory()->for($shop)->create([
        'marketplace' => Marketplace::Temu,
        'external_order_id' => SHIPPED_ORDER_SN,
        'marketplace_status' => (string) MarketplaceOrder::TEMU_UNSHIPPED,
        'status' => MarketplaceOrderStatus::Imported,
        'woocommerce_order_id' => 83739,
        ...$order,
    ]);

    return [$shop, $row];
}

/**
 * Fake Temu and WooCommerce for a check that confirms shipments.
 *
 * WooCommerce lists the imported order with Planzer's parcel number, as the
 * plugin keeps it.
 *
 * @param  array<string, mixed>  $calls
 * @param  array<string, mixed>|null  $refusal  Temu's answer to the confirmation, when it refuses.
 */
function fakeShipmentApis(array &$calls, ?string $tracking = PLANZER_TRACKING, ?array $refusal = null, int $parentStatus = 2): void
{
    $calls = ['temu' => [], 'woo' => []];

    Http::fake(function (Request $request) use (&$calls, $tracking, $refusal, $parentStatus) {
        if (str_contains($request->url(), 'toys.example')) {
            $calls['woo'][] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];

            return $request->method() === 'GET'
                ? Http::response([[
                    'id' => 83739,
                    'date_modified_gmt' => '2026-10-12T10:00:00',
                    'meta_data' => Meta::list([
                        Meta::MARKETPLACE => 'temu',
                        Meta::ORDER_ID => SHIPPED_ORDER_SN,
                        Meta::DELIVERY_STATUS => 'IN_DELIVERY',
                        Meta::TRACKING_CODE => $tracking,
                    ]),
                ]], 200, ['X-WP-TotalPages' => '1'])
                : Http::response(['id' => 1], 201);
        }

        $data = $request->data();
        $calls['temu'][] = $data;

        if ($data['type'] === 'bg.logistics.shipment.v2.confirm' && $refusal !== null) {
            return Http::response(['success' => false, ...$refusal]);
        }

        $result = match ($data['type']) {
            'bg.order.list.v2.get' => ['totalItemNum' => 0, 'pageItems' => []],
            'bg.order.detail.v2.get' => [
                'parentOrderMap' => ['parentOrderSn' => SHIPPED_ORDER_SN, 'parentOrderStatus' => $parentStatus, 'regionId' => 192],
                'orderList' => [
                    ['orderSn' => '192-1', 'goodsId' => 609474295220495, 'skuId' => 150607323233142, 'quantity' => 2, 'orderStatus' => 2],
                    ['orderSn' => '192-2', 'goodsId' => 609474295220496, 'skuId' => 150607323233143, 'quantity' => 1, 'orderStatus' => 3],
                ],
            ],
            'bg.logistics.companies.get' => [
                ['logisticsServiceProviderId' => 232089901, 'logisticsServiceProviderName' => 'Swiss Post'],
                ['logisticsServiceProviderId' => 999304692, 'logisticsServiceProviderName' => 'Planzer'],
            ],
            'bg.logistics.shipment.v2.confirm' => ['warningMessage' => []],
            default => [],
        };

        return Http::response(['success' => true, 'errorCode' => 1000000, 'errorMsg' => '', 'result' => $result]);
    });
}

/**
 * @param  array<string, mixed>  $calls
 * @return array<int, array<string, mixed>>
 */
function shipmentCallsOf(array $calls, string $type): array
{
    return array_values(array_filter($calls['temu'], fn (array $call) => $call['type'] === $type));
}

test('an order with a planzer parcel number is confirmed on temu as one parcel', function () {
    [$shop, $row] = shippingShop();
    $calls = [];
    fakeShipmentApis($calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    $confirms = shipmentCallsOf($calls, 'bg.logistics.shipment.v2.confirm');
    expect($confirms)->toHaveCount(1)
        ->and($confirms[0]['sendType'])->toBe(0)
        ->and($confirms[0]['sendRequestList'])->toBe([[
            // The line Temu cancelled is left out.
            'orderSendInfoList' => [[
                'parentOrderSn' => SHIPPED_ORDER_SN,
                'orderSn' => '192-1',
                'goodsId' => 609474295220495,
                'skuId' => 150607323233142,
                'quantity' => 2,
            ]],
            'carrierId' => 999304692,
            'trackingNumber' => PLANZER_TRACKING,
        ]]);

    $row->refresh();
    expect($row->shipment_confirmed_at)->not->toBeNull()
        ->and($row->marketplace_status)->toBe((string) MarketplaceOrder::TEMU_SHIPPED)
        ->and($row->tracking_code)->toBe(PLANZER_TRACKING)
        ->and($shop->temuConnection->refresh()->carrier_id)->toBe(999304692);

    $note = collect($calls['woo'])->firstWhere('method', 'POST');
    expect($note['url'])->toEndWith('/orders/83739/notes')
        ->and($note['data']['note'])->toContain(PLANZER_TRACKING);
});

test('a confirmed order is not confirmed again', function () {
    [$shop] = shippingShop(['shipment_confirmed_at' => now()]);
    $calls = [];
    fakeShipmentApis($calls);

    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(shipmentCallsOf($calls, 'bg.logistics.shipment.v2.confirm'))->toBe([]);
});

test('nothing is confirmed without a parcel number or with the setting off', function (array $connection, ?string $tracking) {
    [$shop] = shippingShop();
    $shop->temuConnection->update($connection);
    $calls = [];
    fakeShipmentApis($calls, $tracking);

    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(shipmentCallsOf($calls, 'bg.logistics.shipment.v2.confirm'))->toBe([]);
})->with([
    'no parcel number yet' => [[], null],
    'confirming switched off' => [['confirm_shipments' => false], PLANZER_TRACKING],
]);

test('an order temu no longer waits on is only updated', function () {
    [$shop, $row] = shippingShop();
    $calls = [];
    fakeShipmentApis($calls, parentStatus: MarketplaceOrder::TEMU_CANCELLED);

    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(shipmentCallsOf($calls, 'bg.logistics.shipment.v2.confirm'))->toBe([])
        ->and($row->refresh()->marketplace_status)->toBe((string) MarketplaceOrder::TEMU_CANCELLED)
        ->and($row->shipment_confirmed_at)->toBeNull();
});

test('a shipment temu refuses is kept for someone to look at and not sent again', function () {
    [$shop, $row] = shippingShop();
    $calls = [];
    fakeShipmentApis($calls, refusal: ['errorCode' => 120014023, 'errorMsg' => 'Tracking number is invalid']);

    dispatch_sync(new ImportTemuOrders($shop->id));
    dispatch_sync(new ImportTemuOrders($shop->id));

    expect(shipmentCallsOf($calls, 'bg.logistics.shipment.v2.confirm'))->toHaveCount(1)
        ->and($row->refresh()->shipment_message)->toContain('Tracking number is invalid')
        ->and($row->shipment_confirmed_at)->toBeNull();
});

test('a refused shipment can be confirmed again from the temu page', function () {
    [$shop, $row] = shippingShop(['shipment_message' => 'Temu refused the shipment: Tracking number is invalid']);
    $calls = [];
    fakeShipmentApis($calls);

    $owner = User::factory()->create();
    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $this->actingAs($owner)
        ->post(route('shops.marketplace-orders.retry', ['shop' => $shop->slug, 'marketplaceOrder' => $row->id]))
        ->assertRedirect();

    expect($row->refresh()->shipment_message)->toBeNull()
        ->and($row->shipment_confirmed_at)->not->toBeNull()
        ->and($row->status)->toBe(MarketplaceOrderStatus::Imported);
});

test('temu orders close to their ship-by time are flagged until confirmed', function (array $order, ?string $warning) {
    [, $row] = shippingShop($order);

    expect($row->refresh()->shipByWarning())->toBe($warning);
})->with([
    'overdue' => [['ship_by_at' => now()->subHour()], 'overdue'],
    'due within a day' => [['ship_by_at' => now()->addHours(5)], 'soon'],
    'days away' => [['ship_by_at' => now()->addDays(3)], null],
    'already confirmed' => [['ship_by_at' => now()->subHour(), 'shipment_confirmed_at' => now()], null],
    'cancelled' => [['ship_by_at' => now()->subHour(), 'status' => MarketplaceOrderStatus::Cancelled], null],
]);
