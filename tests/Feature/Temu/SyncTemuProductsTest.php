<?php

use App\Actions\Temu\BuildTemuProductPayload;
use App\Enums\ProductStatus;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Enums\TemuListing;
use App\Enums\TemuProductStatus;
use App\Jobs\SyncTemuProducts;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TemuConnection;
use App\Models\TemuProduct;
use App\Models\TemuSyncRun;
use App\Services\Temu\TemuClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Fake Temu's gateway, answering each method from the map and recording every call.
 *
 * @param  array<string, array<string, mixed>|callable>  $results
 * @param  array<int, array<string, mixed>>  $calls
 */
function fakeTemuGateway(array $results, array &$calls): void
{
    Http::fake(function (Request $request) use ($results, &$calls) {
        $data = $request->data();
        $calls[] = $data;

        $result = $results[$data['type']] ?? [];

        if (is_callable($result)) {
            return $result($data);
        }

        return Http::response(['success' => true, 'errorCode' => 1000000, 'errorMsg' => '', 'result' => $result]);
    });
}

/**
 * @param  array<int, array<string, mixed>>  $calls
 * @return array<int, array<string, mixed>>
 */
function temuCallsOf(array $calls, string $type): array
{
    return array_values(array_filter($calls, fn (array $call) => $call['type'] === $type));
}

function queuedTemuRun(Shop $shop): TemuSyncRun
{
    return $shop->temuSyncRuns()->create(['trigger' => SyncTrigger::Manual, 'status' => SyncRunStatus::Queued]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function temuReadyProduct(Shop $shop, array $overrides = []): Product
{
    return Product::factory()->for($shop)->create([
        'name' => 'Wooden Train Set',
        'sku' => 'TOY-100',
        'gtin' => '4006381333931',
        'price' => '49.90',
        'stock' => 7,
        'status' => ProductStatus::Active,
        'image_url' => 'https://toys.example/train.jpg',
        'gallery_image_urls' => ['https://toys.example/train-2.jpg'],
        'description' => 'A classic wooden train.',
        ...$overrides,
    ]);
}

test('an included product is created on temu with a signed request', function () {
    $shop = Shop::factory()->create();
    $connection = TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000001, 'externalGoodsId' => 'psm-'.$product->id]], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $add = temuCallsOf($calls, 'temu.local.goods.v3.add');

    expect($add)->toHaveCount(1)
        ->and($add[0]['sign'])->toBe(TemuClient::sign($add[0], (string) $connection->app_secret))
        ->and($add[0]['goodsBasic']['externalGoodsId'])->toBe('psm-'.$product->id)
        ->and($add[0]['goodsBasic']['goodsName'])->toBe('Wooden Train Set')
        ->and($add[0]['goodsBasic']['goodsCarouselImage'])->toBe(['https://toys.example/train.jpg', 'https://toys.example/train-2.jpg'])
        ->and($add[0]['skuList'][0]['externalSkuId'])->toBe('TOY-100')
        ->and($add[0]['skuList'][0]['price']['basePrice'])->toBe(['amount' => '49.90', 'currency' => 'CHF'])
        ->and($add[0]['skuList'][0]['quantity'])->toBe(7)
        ->and($add[0]['skuList'][0]['barCode'])->toBe(['barCodeType' => 'EAN', 'barCodeId' => ['4006381333931']]);

    $record = $product->temuProduct()->sole();

    expect($record->goods_id)->toBe(601000000000001)
        ->and($record->shop_id)->toBe($shop->id)
        ->and($record->status)->toBe(TemuProductStatus::Processing)
        ->and($record->sent_stock)->toBe(7)
        ->and($record->sent_price)->toBe('49.90')
        ->and($record->content_hash)->not->toBeNull();

    $run->refresh();

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->created_products)->toBe(1)
        ->and($run->active_shop_id)->toBeNull()
        ->and($connection->refresh()->last_synced_at)->not->toBeNull();
});

test('products that are excluded, drafts, unpriced or without an image are not created', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();

    temuReadyProduct($shop, ['sku' => 'A', 'temu_listing' => TemuListing::Exclude]);
    temuReadyProduct($shop, ['sku' => 'B', 'status' => ProductStatus::Draft]);
    temuReadyProduct($shop, ['sku' => 'C', 'price' => '0']);
    temuReadyProduct($shop, ['sku' => 'D', 'image_url' => null]);

    $calls = [];
    fakeTemuGateway([], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'temu.local.goods.v3.add'))->toBe([]);
});

test('a shop that excludes all only creates products included one by one', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->excludingAll()->create();

    temuReadyProduct($shop, ['sku' => 'FOLLOWS']);
    $included = temuReadyProduct($shop, ['sku' => 'CHOSEN', 'temu_listing' => TemuListing::Include]);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000002]], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $add = temuCallsOf($calls, 'temu.local.goods.v3.add');

    expect($add)->toHaveCount(1)
        ->and($add[0]['goodsBasic']['externalGoodsId'])->toBe('psm-'.$included->id);
});

test('a brand included under an exclude all shop has its products created', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->excludingAll()->create();
    $brand = Brand::factory()->for($shop)->temuListing(TemuListing::Include)->create();

    $follows = temuReadyProduct($shop, ['sku' => 'FOLLOWS', 'brand_id' => $brand->id]);
    temuReadyProduct($shop, ['sku' => 'OPTED-OUT', 'brand_id' => $brand->id, 'temu_listing' => TemuListing::Exclude]);
    temuReadyProduct($shop, ['sku' => 'UNBRANDED']);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000010]], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $add = temuCallsOf($calls, 'temu.local.goods.v3.add');

    expect($add)->toHaveCount(1)
        ->and($add[0]['goodsBasic']['externalGoodsId'])->toBe('psm-'.$follows->id);
});

test('a brand excluded under an include all shop keeps its products off temu', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $brand = Brand::factory()->for($shop)->temuListing(TemuListing::Exclude)->create();

    temuReadyProduct($shop, ['sku' => 'FOLLOWS', 'brand_id' => $brand->id]);
    $chosen = temuReadyProduct($shop, ['sku' => 'OPTED-IN', 'brand_id' => $brand->id, 'temu_listing' => TemuListing::Include]);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000011]], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $add = temuCallsOf($calls, 'temu.local.goods.v3.add');

    expect($add)->toHaveCount(1)
        ->and($add[0]['goodsBasic']['externalGoodsId'])->toBe('psm-'.$chosen->id);
});

test('a listed product whose brand is excluded is taken off sale', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $brand = Brand::factory()->for($shop)->temuListing(TemuListing::Exclude)->create();
    $product = temuReadyProduct($shop, ['brand_id' => $brand->id]);
    $record = TemuProduct::factory()->forProduct($product)->create(['goods_id' => 601000000000012]);

    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.list.query' => ['goodsList' => [['goodsId' => 601000000000012, 'goodsShowSubStatus' => 2001]]],
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $sale = temuCallsOf($calls, 'bg.local.goods.sale.status.set');

    expect($sale)->toHaveCount(1)
        ->and($sale[0]['onsale'])->toBe(0)
        ->and($record->refresh()->on_sale)->toBeFalse()
        ->and($run->refresh()->removed_products)->toBe(1);
});

test('stock, price and details that changed are sent to a listed product', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop, ['stock' => 3, 'price' => '44.50']);

    $record = TemuProduct::factory()->forProduct($product)->create([
        'goods_id' => 601000000000003,
        'sku_id' => null,
        'status' => TemuProductStatus::Processing,
        'sent_stock' => 7,
        'sent_price' => '49.90',
        'content_hash' => 'outdated',
    ]);

    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.list.query' => ['goodsList' => [[
            'goodsId' => 601000000000003,
            'goodsShowSubStatus' => 2001,
            'skuInfoList' => [['skuId' => 51000000000003, 'stock' => 7]],
        ]]],
        'bg.local.goods.priceorder.change.sku.price' => ['successSkuList' => [51000000000003], 'failedSkuList' => []],
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $stock = temuCallsOf($calls, 'bg.local.goods.stock.edit');
    $price = temuCallsOf($calls, 'bg.local.goods.priceorder.change.sku.price');
    $content = temuCallsOf($calls, 'bg.local.goods.partial.update');

    expect($stock)->toHaveCount(1)
        ->and($stock[0]['skuStockTargetList'])->toBe([['skuId' => 51000000000003, 'stockTarget' => 3]])
        ->and($price)->toHaveCount(1)
        ->and($price[0]['changeSkuPriceDTOList'][0]['skuChangePriceBaseDTOList'][0]['newSupplierPrice'])->toBe(['amount' => '44.50', 'currency' => 'CHF'])
        ->and($content)->toHaveCount(1)
        ->and($content[0]['goodsBasic']['goodsName'])->toBe('Wooden Train Set')
        ->and($content[0]['goodsDesc'])->toBe('A classic wooden train.')
        ->and($content[0]['goodsBasic'])->not->toHaveKey('goodsGallery')
        ->and(temuCallsOf($calls, 'temu.local.goods.v3.add'))->toBe([]);

    $record->refresh();

    expect($record->status)->toBe(TemuProductStatus::Active)
        ->and($record->sku_id)->toBe(51000000000003)
        ->and($record->temu_sub_status)->toBe(2001)
        ->and($record->sent_stock)->toBe(3)
        ->and($record->sent_price)->toBe('44.50')
        ->and($record->content_hash)->not->toBe('outdated')
        ->and($run->refresh()->updated_products)->toBe(1);

    // Nothing changed since, so a second run sends nothing but the status check.
    $calls = [];
    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(array_column($calls, 'type'))->toBe(['bg.local.goods.list.query']);
});

test('temu sells at the discounted price with the shop price as the retail price', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create(['discount_percent' => 10]);
    $product = temuReadyProduct($shop);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000001]], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $add = temuCallsOf($calls, 'temu.local.goods.v3.add');

    // 49.90 less 10% is 44.91, rounded to the nearest 5 Rappen.
    expect($add[0]['skuList'][0]['price'])->toBe([
        'basePrice' => ['amount' => '44.90', 'currency' => 'CHF'],
        'listPrice' => ['amount' => '49.90', 'currency' => 'CHF'],
    ]);

    $record = $product->temuProduct()->sole();

    expect($record->sent_price)->toBe('44.90')
        ->and($record->sent_list_price)->toBe('49.90');
});

test('a brand discount takes the place of the shop discount', function () {
    $shop = Shop::factory()->create();
    $connection = TemuConnection::factory()->for($shop)->create(['discount_percent' => 10]);
    $brand = Brand::factory()->for($shop)->create(['temu_discount_percent' => 20]);
    $noDiscount = Brand::factory()->for($shop)->create(['temu_discount_percent' => 0]);

    $discounted = temuReadyProduct($shop, ['brand_id' => $brand->id]);
    $atShopPrice = temuReadyProduct($shop, ['sku' => 'TOY-101', 'brand_id' => $noDiscount->id]);

    // 49.90 less 20% is 39.92; a brand at 0% sells at the shop price, which
    // leaves no retail price above it to send.
    expect($connection->price($discounted))->toBe('39.90')
        ->and($connection->listPrice($discounted))->toBe('49.90')
        ->and($connection->price($atShopPrice))->toBe('49.90')
        ->and($connection->listPrice($atShopPrice))->toBeNull();
});

test('discounted prices are rounded to the nearest 5 rappen', function (string $price, float $discount, string $expected) {
    $shop = Shop::factory()->create();
    $connection = TemuConnection::factory()->for($shop)->create(['discount_percent' => $discount]);

    expect($connection->price(temuReadyProduct($shop, ['price' => $price])))->toBe($expected);
})->with([
    'rounds down' => ['29.90', 10, '26.90'],
    'rounds up' => ['19.90', 10, '17.90'],
    'halfway rounds up' => ['44.50', 10, '40.05'],
    'fractional discount' => ['100.00', 12.5, '87.50'],
    'never reaches zero' => ['0.05', 50, '0.05'],
]);

test('a changed retail price is sent to a listed product', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create(['discount_percent' => 10]);
    $product = temuReadyProduct($shop, ['price' => '59.90']);

    $record = TemuProduct::factory()->forProduct($product)->create([
        'goods_id' => 601000000000003,
        'sku_id' => 51000000000003,
        'status' => TemuProductStatus::Active,
        'sent_stock' => 7,
        'sent_price' => '44.90',
        'sent_list_price' => '49.90',
    ]);
    $record->update(['content_hash' => app(BuildTemuProductPayload::class)->contentHash($product)]);

    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.priceorder.change.sku.price' => ['successSkuList' => [51000000000003], 'failedSkuList' => []],
    ], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $price = temuCallsOf($calls, 'bg.local.goods.priceorder.change.sku.price');
    $update = temuCallsOf($calls, 'bg.local.goods.partial.update');

    expect($price[0]['changeSkuPriceDTOList'][0]['skuChangePriceBaseDTOList'][0]['newSupplierPrice'])->toBe(['amount' => '53.90', 'currency' => 'CHF'])
        ->and($update)->toHaveCount(1)
        ->and($update[0]['goodsId'])->toBe(601000000000003)
        ->and($update[0]['skuList'])->toBe([['skuId' => 51000000000003, 'listPrice' => ['amount' => '59.90', 'currency' => 'CHF']]])
        ->and($record->refresh()->sent_list_price)->toBe('59.90');
});

test('a retail price temu refuses until the new base price is approved is sent again later', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create(['discount_percent' => 10]);
    $product = temuReadyProduct($shop);

    // Listed before the discount, so Temu still has the shop price as the
    // base price while it reviews the lower one.
    $record = TemuProduct::factory()->forProduct($product)->create([
        'goods_id' => 601000000000003,
        'sku_id' => 51000000000003,
        'status' => TemuProductStatus::Active,
        'sent_stock' => 7,
        'sent_price' => '49.90',
        'sent_list_price' => null,
    ]);
    $record->update(['content_hash' => app(BuildTemuProductPayload::class)->contentHash($product)]);

    $approved = false;
    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.priceorder.change.sku.price' => ['successSkuList' => [51000000000003], 'failedSkuList' => []],
        'bg.local.goods.partial.update' => function () use (&$approved) {
            return $approved
            ? Http::response(['success' => true, 'errorCode' => 1000000, 'errorMsg' => '', 'result' => []])
            : Http::response(['success' => false, 'errorCode' => 150010041, 'errorMsg' => 'List price must be greater than base price']);
        },
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $record->refresh();

    expect(temuCallsOf($calls, 'bg.local.goods.partial.update'))->toHaveCount(1)
        ->and($record->sent_price)->toBe('44.90')
        ->and($record->sent_list_price)->toBeNull()
        ->and($record->message)->toBeNull()
        ->and($run->refresh()->failed_products)->toBe(0);

    // Once Temu has approved the new base price, the retail price goes through.
    $approved = true;
    $calls = [];

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'bg.local.goods.priceorder.change.sku.price'))->toBe([])
        ->and(temuCallsOf($calls, 'bg.local.goods.partial.update'))->toHaveCount(1)
        ->and($record->refresh()->sent_list_price)->toBe('49.90');
});

test('a draft keeps its price until temu submits it, but still gets stock', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create(['discount_percent' => 10]);
    $product = temuReadyProduct($shop, ['stock' => 3]);

    $record = TemuProduct::factory()->forProduct($product)->create([
        'goods_id' => 601000000000003,
        'sku_id' => 51000000000003,
        'status' => TemuProductStatus::Draft,
        'sent_stock' => 7,
        'sent_price' => '49.90',
    ]);
    $record->update(['content_hash' => app(BuildTemuProductPayload::class)->contentHash($product)]);

    $subStatus = 5001;
    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.list.query' => function () use (&$subStatus) {
            return Http::response(['success' => true, 'errorCode' => 1000000, 'errorMsg' => '', 'result' => [
                'goodsList' => [['goodsId' => 601000000000003, 'goodsShowSubStatus' => $subStatus]],
            ]]);
        },
        'bg.local.goods.priceorder.change.sku.price' => ['successSkuList' => [51000000000003], 'failedSkuList' => []],
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    expect(temuCallsOf($calls, 'bg.local.goods.stock.edit'))->toHaveCount(1)
        ->and(temuCallsOf($calls, 'bg.local.goods.priceorder.change.sku.price'))->toBe([])
        ->and(temuCallsOf($calls, 'bg.local.goods.partial.update'))->toBe([])
        ->and($record->refresh()->sent_price)->toBe('49.90')
        ->and($run->refresh()->failed_products)->toBe(0);

    // Once Temu has submitted it, the discounted price goes out.
    $subStatus = 2001;
    $calls = [];

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'bg.local.goods.priceorder.change.sku.price'))->toHaveCount(1)
        ->and($record->refresh()->sent_price)->toBe('44.90');
});

test('a product temu is still reviewing is left alone', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop, ['stock' => 1]);

    TemuProduct::factory()->forProduct($product)->create(['goods_id' => 601000000000004, 'sent_stock' => 7]);

    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.list.query' => ['goodsList' => [['goodsId' => 601000000000004, 'goodsShowSubStatus' => 4001]]],
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    expect(array_column($calls, 'type'))->toBe(['bg.local.goods.list.query'])
        ->and($product->temuProduct->refresh()->status)->toBe(TemuProductStatus::Processing)
        ->and($run->refresh()->skipped_products)->toBe(1);
});

test('a product no longer chosen for temu is taken off sale', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop, ['temu_listing' => TemuListing::Exclude]);
    $record = TemuProduct::factory()->forProduct($product)->create(['goods_id' => 601000000000005]);

    $calls = [];
    fakeTemuGateway([
        'bg.local.goods.list.query' => ['goodsList' => [['goodsId' => 601000000000005, 'goodsShowSubStatus' => 2001]]],
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $sale = temuCallsOf($calls, 'bg.local.goods.sale.status.set');

    expect($sale)->toHaveCount(1)
        ->and($sale[0]['goodsId'])->toBe(601000000000005)
        ->and($sale[0]['onsale'])->toBe(0)
        ->and($record->refresh()->on_sale)->toBeFalse()
        ->and($record->status)->toBe(TemuProductStatus::Inactive)
        ->and($run->refresh()->removed_products)->toBe(1);
});

test('a product put back on temu is returned to sale', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop);
    TemuProduct::factory()->forProduct($product)->create([
        'goods_id' => 601000000000006,
        'on_sale' => false,
        'status' => TemuProductStatus::Inactive,
        'sent_stock' => 7,
        'sent_price' => '49.90',
    ]);

    $calls = [];
    fakeTemuGateway([], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $sale = temuCallsOf($calls, 'bg.local.goods.sale.status.set');

    expect($sale)->toHaveCount(1)
        ->and($sale[0]['onsale'])->toBe(1)
        ->and($product->temuProduct->refresh()->on_sale)->toBeTrue();
});

test('a product deleted from the catalog is taken off sale', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop);
    $record = TemuProduct::factory()->forProduct($product)->create(['goods_id' => 601000000000007]);

    $product->delete();

    expect($record->refresh()->product_id)->toBeNull();

    $calls = [];
    fakeTemuGateway([], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'bg.local.goods.sale.status.set'))->toHaveCount(1)
        ->and($record->refresh()->on_sale)->toBeFalse();
});

test('a product temu refuses is recorded and not sent again until it is edited', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop);

    $calls = [];
    fakeTemuGateway([
        'temu.local.goods.v3.add' => fn () => Http::response(['success' => false, 'errorCode' => 150010009, 'errorMsg' => 'Enter the product name']),
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $record = $product->temuProduct()->sole();

    expect($record->status)->toBe(TemuProductStatus::Failed)
        ->and($record->message)->toBe('Enter the product name')
        ->and($record->goods_id)->toBeNull()
        ->and($run->refresh()->status)->toBe(SyncRunStatus::Completed)
        ->and($run->failed_products)->toBe(1);

    $this->travel(1)->minute();
    $calls = [];
    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'temu.local.goods.v3.add'))->toBe([]);

    $this->travel(1)->minute();
    $product->update(['name' => 'Wooden Train Set, 12 pieces']);
    $calls = [];
    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'temu.local.goods.v3.add'))->toHaveCount(1);
});

test('a product temu turned away with a system error is sent again on the next run', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop);

    $calls = [];
    $attempts = 0;
    fakeTemuGateway([
        'temu.local.goods.v3.add' => function () use (&$attempts) {
            return ++$attempts === 1
                ? Http::response(['success' => false, 'errorCode' => 150010001, 'errorMsg' => 'System error, please try again later'])
                : Http::response(['success' => true, 'errorCode' => 1000000, 'errorMsg' => '', 'result' => ['goodsId' => 601000000000020]]);
        },
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    $record = $product->temuProduct()->sole();

    expect($record->status)->toBe(TemuProductStatus::Pending)
        ->and($record->message)->toBe('System error, please try again later')
        ->and($run->refresh()->failed_products)->toBe(1);

    $this->travel(1)->minute();
    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect($attempts)->toBe(2)
        ->and($record->refresh()->status)->toBe(TemuProductStatus::Processing)
        ->and($record->message)->toBeNull();
});

test('a failed product put back to pending is sent again without being edited', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = temuReadyProduct($shop);
    $record = TemuProduct::factory()->forProduct($product)->create([
        'goods_id' => null,
        'status' => TemuProductStatus::Failed,
        'message' => 'No categories are recommended. Please complete product name and try again.',
    ]);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000021]], $calls);

    $this->travel(1)->minute();
    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'temu.local.goods.v3.add'))->toBe([]);

    $record->update(['status' => TemuProductStatus::Pending]);
    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    expect(temuCallsOf($calls, 'temu.local.goods.v3.add'))->toHaveCount(1)
        ->and($record->refresh()->goods_id)->toBe(601000000000021);
});

test('the temu title is sent in place of the product name', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    temuReadyProduct($shop, ['sku' => 'TITLED', 'temu_name' => 'Wooden train set for toddlers, 12 pieces']);
    temuReadyProduct($shop, ['sku' => 'PLAIN']);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000022]], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $names = collect(temuCallsOf($calls, 'temu.local.goods.v3.add'))
        ->mapWithKeys(fn (array $call) => [$call['skuList'][0]['externalSkuId'] => $call['goodsBasic']['goodsName']]);

    expect($names['TITLED'])->toBe('Wooden train set for toddlers, 12 pieces')
        ->and($names['PLAIN'])->toBe('Wooden Train Set');
});

test('a category named after the product brand is left out of the category hint', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    $product = Product::factory()->for($shop)->branded('Papierdrachen')->categorized('Papierdrachen', 'Kostüme & Verkleiden', 'Geburtstag')->create([
        'price' => '12.90',
        'status' => ProductStatus::Active,
        'image_url' => 'https://toys.example/crown.jpg',
    ]);

    $calls = [];
    fakeTemuGateway(['temu.local.goods.v3.add' => ['goodsId' => 601000000000023]], $calls);

    dispatch_sync(new SyncTemuProducts(queuedTemuRun($shop)));

    $add = temuCallsOf($calls, 'temu.local.goods.v3.add');

    expect($add)->toHaveCount(1)
        ->and($add[0]['goodsBasic']['extCatName'])->toBe('Geburtstag / Kostüme & Verkleiden')
        ->and($product->brand->name)->toBe('Papierdrachen');
});

test('a refused token fails the run without retrying', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    temuReadyProduct($shop);

    $calls = [];
    fakeTemuGateway([
        'temu.local.goods.v3.add' => fn () => Http::response(['success' => false, 'errorCode' => 7000016, 'errorMsg' => 'access_token expired']),
    ], $calls);

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    expect($run->refresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($run->message)->toContain('Temu rejected the app key, secret or access token.');
});

test('a run without usable credentials fails', function () {
    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->withoutCredentials()->create();

    Http::fake();

    $run = queuedTemuRun($shop);
    dispatch_sync(new SyncTemuProducts($run));

    expect($run->refresh()->status)->toBe(SyncRunStatus::Failed);

    Http::assertNothingSent();
});

test('temu sub-status codes map to statuses', function (int $code, TemuProductStatus $status) {
    expect(TemuProductStatus::fromTemuSubStatus($code))->toBe($status);
})->with([
    [2001, TemuProductStatus::Active],
    [2101, TemuProductStatus::Active],
    [3003, TemuProductStatus::Active],
    [3002, TemuProductStatus::Inactive],
    [4001, TemuProductStatus::Processing],
    [4003, TemuProductStatus::Rejected],
    [4004, TemuProductStatus::Rejected],
    [5001, TemuProductStatus::Draft],
    [6002, TemuProductStatus::Deleted],
    [9999, TemuProductStatus::Processing],
]);
