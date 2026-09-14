<?php

use App\Actions\Sync\ImportWooCommerceCategories;
use App\Actions\Sync\ImportWooCommerceProducts;
use App\Enums\SyncRunStatus;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\WooCommerceConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

/**
 * Fake an empty store with the given tax settings.
 *
 * @param  array<int, array<string, mixed>>  $taxes
 */
function fakeTaxStore(?string $pricesIncludeTax, array $taxes, int $settingsStatus = 200): void
{
    Http::fake(function (Request $request) use ($pricesIncludeTax, $taxes, $settingsStatus) {
        $url = $request->url();

        if (str_contains($url, 'settings/tax/woocommerce_prices_include_tax')) {
            return Http::response(['id' => 'woocommerce_prices_include_tax', 'value' => $pricesIncludeTax], $settingsStatus);
        }

        if (str_contains($url, '/taxes')) {
            return Http::response($taxes, 200);
        }

        return Http::response([], 200, ['X-WP-Total' => '0', 'X-WP-TotalPages' => '1']);
    });
}

function syncTaxShop(Shop $shop): SyncRun
{
    WooCommerceConnection::factory()->for($shop)->create(['store_url' => 'https://store.test']);

    $run = SyncRun::factory()->for($shop)->create();

    app(SyncWooCommerceProducts::class, ['syncRun' => $run])
        ->handle(app(ImportWooCommerceProducts::class), app(ImportWooCommerceCategories::class));

    return $run->refresh();
}

test('a sync copies the store\'s tax settings onto the shop, preferring the swiss rate', function () {
    fakeTaxStore('no', [
        ['id' => 1, 'country' => 'DE', 'rate' => '19.0000', 'class' => 'standard'],
        ['id' => 2, 'country' => 'CH', 'rate' => '8.1000', 'class' => 'standard'],
    ]);

    $shop = Shop::factory()->create(['prices_include_tax' => true, 'tax_rate' => '0']);

    expect(syncTaxShop($shop)->status)->toBe(SyncRunStatus::Completed);

    $shop->refresh();

    expect($shop->prices_include_tax)->toBeFalse()
        ->and($shop->tax_rate)->toBe('8.10');
});

test('a store that will not share its tax settings keeps the shop\'s own and still syncs', function () {
    fakeTaxStore('no', [], settingsStatus: 403);

    $shop = Shop::factory()->create(['prices_include_tax' => true, 'tax_rate' => '2.6']);

    expect(syncTaxShop($shop)->status)->toBe(SyncRunStatus::Completed);

    $shop->refresh();

    expect($shop->prices_include_tax)->toBeTrue()
        ->and($shop->tax_rate)->toBe('2.60');
});

test('unrecognisable tax answers leave the shop unchanged', function () {
    fakeTaxStore(null, [['id' => 1, 'country' => 'CH', 'rate' => 'lots']]);

    $shop = Shop::factory()->create(['prices_include_tax' => false, 'tax_rate' => '2.6']);

    syncTaxShop($shop);

    $shop->refresh();

    expect($shop->prices_include_tax)->toBeFalse()
        ->and($shop->tax_rate)->toBe('2.60');
});

test('a sync copies the store\'s currency onto the shop', function (mixed $storeCurrency, string $expected) {
    Http::fake(function (Request $request) use ($storeCurrency) {
        if (str_contains($request->url(), 'settings/general/woocommerce_currency')) {
            return Http::response(['id' => 'woocommerce_currency', 'value' => $storeCurrency], 200);
        }

        return Http::response([], 200, ['X-WP-Total' => '0', 'X-WP-TotalPages' => '1']);
    });

    $shop = Shop::factory()->create(['currency' => 'USD']);

    expect(syncTaxShop($shop)->status)->toBe(SyncRunStatus::Completed)
        ->and($shop->refresh()->currency)->toBe($expected);
})->with([
    'a valid code' => ['chf', 'CHF'],
    'an unreadable value keeps the shop\'s own' => ['Swiss franc', 'USD'],
    'no value keeps the shop\'s own' => [null, 'USD'],
]);
