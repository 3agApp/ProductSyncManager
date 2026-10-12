<?php

use App\Models\MarketplaceOrder;
use App\Models\NaldaConnection;
use App\Models\Shop;
use App\Models\WooCommerceConnection;
use App\Support\MarketplaceOrderMeta as Meta;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A shop with prices including 8.1% VAT, connected to WooCommerce and Nalda.
 */
function legacyNaldaShop(): Shop
{
    $shop = Shop::factory()->create(['prices_include_tax' => true, 'tax_rate' => '8.10', 'currency' => 'CHF']);
    NaldaConnection::factory()->for($shop)->create(['api_key' => 'test-key']);
    WooCommerceConnection::factory()->verified()->for($shop)->create(['store_url' => 'https://toys.example']);

    return $shop;
}

/**
 * An order as the old Nalda plugin left it: 34.00 for a 36.86 payout.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function legacyNaldaStoreOrder(array $overrides = []): array
{
    return [
        'id' => 8300,
        'status' => 'completed',
        'total' => '34.00',
        'refunds' => [],
        'meta_data' => [['key' => '_nalda_order_id', 'value' => '469328']],
        'line_items' => [[
            'id' => 51,
            'quantity' => 2,
            'meta_data' => [
                ['key' => '_nalda_customer_price', 'value' => '18.90'],
                ['key' => '_nalda_commission', 'value' => '6.35'],
            ],
        ]],
        'shipping_lines' => [],
        ...$overrides,
    ];
}

/**
 * Fake Nalda and WooCommerce for a correction run.
 *
 * @param  array<int, array<string, mixed>>  $storeOrders
 * @param  array<int, array<string, mixed>>  $writes
 */
function fakeCorrectionApis(array $storeOrders, array &$writes, float $refund = 0): void
{
    $writes = [];

    Http::fake(function (Request $request) use ($storeOrders, $refund, &$writes) {
        if (str_contains($request->url(), 'sellers-api.nalda.com')) {
            return Http::response(['success' => true, 'result' => ['orderId' => 469328, 'fee' => 6.5, 'commission' => 1.09, 'refund' => $refund]]);
        }

        if ($request->method() === 'GET') {
            return Http::response($storeOrders, 200, ['X-WP-TotalPages' => '1']);
        }

        $writes[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];

        return Http::response(['id' => 8300, 'total' => '36.86']);
    });
}

test('an old nalda order is repriced at the payout, shipping included', function () {
    $shop = legacyNaldaShop();
    MarketplaceOrder::factory()->nalda()->for($shop)->create(['external_order_id' => '469328', 'customer_total' => '37.80']);
    $writes = [];
    fakeCorrectionApis([legacyNaldaStoreOrder()], $writes);

    $this->artisan('nalda:correct-legacy-totals', ['shop' => $shop->slug, '--apply' => true])->assertSuccessful();

    expect($writes)->toHaveCount(2);

    $payload = $writes[0]['data'];

    expect($writes[0]['method'])->toBe('PUT')
        ->and($writes[0]['url'])->toEndWith('/orders/8300')
        // 2 × 18.90 less 6.35 = 31.45 and 6.50 less 1.09 = 5.41, both including 8.1% VAT.
        ->and($payload['line_items'])->toBe([['id' => 51, 'subtotal' => '29.0934', 'total' => '29.0934']])
        ->and($payload['shipping_lines'])->toBe([['method_id' => 'nalda', 'method_title' => 'Nalda', 'total' => '5.0046']])
        ->and(Meta::read($payload, Meta::CUSTOMER_TOTAL))->toBe('44.30')
        ->and(Meta::read($payload, Meta::COMMISSION))->toBe('7.44')
        ->and(Meta::read($payload, Meta::SHIPPING_PAYOUT))->toBe('5.41')
        ->and($writes[1]['data']['note'])->toContain('34.00 → 36.86')
        ->and($shop->marketplaceOrders()->sole()->customer_total)->toBe('44.30');
});

test('without --apply nothing is changed', function () {
    $shop = legacyNaldaShop();
    $writes = [];
    fakeCorrectionApis([legacyNaldaStoreOrder()], $writes);

    $this->artisan('nalda:correct-legacy-totals', ['shop' => $shop->slug])
        ->expectsOutputToContain('would correct: 1')
        ->assertSuccessful();

    expect($writes)->toBe([]);
});

test('orders that are right, cancelled, refunded or not from nalda are left alone', function (array $order, float $refund) {
    $shop = legacyNaldaShop();
    $writes = [];
    fakeCorrectionApis([legacyNaldaStoreOrder($order)], $writes, $refund);

    $this->artisan('nalda:correct-legacy-totals', ['shop' => $shop->slug, '--apply' => true])->assertSuccessful();

    expect($writes)->toBe([]);
})->with([
    'already at the payout' => [['total' => '36.86'], 0],
    'cancelled' => [['status' => 'cancelled'], 0],
    'refunded in woocommerce' => [['refunds' => [['id' => 1, 'total' => '-10.00']]], 0],
    'refunded by nalda' => [[], 5.0],
    'a line without a nalda price' => [['line_items' => [['id' => 51, 'quantity' => 1, 'meta_data' => []]]], 0],
    'not a nalda order' => [['meta_data' => [['key' => Meta::MARKETPLACE, 'value' => 'temu'], ['key' => Meta::ORDER_ID, 'value' => 'PO-1']]], 0],
]);
