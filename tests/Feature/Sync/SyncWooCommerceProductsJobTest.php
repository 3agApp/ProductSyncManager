<?php

use App\Actions\Sync\ImportWooCommerceProducts;
use App\Enums\ProductStatus;
use App\Enums\SyncRunStatus;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

/**
 * Build a remote product payload in the shape WooCommerce returns.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function remoteProduct(int $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'name' => "Remote product {$id}",
        'sku' => "SKU-{$id}",
        'global_unique_id' => "0123456789{$id}",
        'short_description' => "<p>Short {$id}</p>",
        'description' => "<p>Long {$id}</p>",
        'permalink' => "https://store.test/product/{$id}",
        'brands' => [['id' => 7, 'name' => 'Acme', 'slug' => 'acme']],
        'categories' => [
            ['id' => 1, 'name' => 'Bags', 'slug' => 'bags'],
            ['id' => 2, 'name' => 'Accessories', 'slug' => 'accessories'],
        ],
        'images' => [
            ['id' => 11, 'src' => "https://store.test/img/{$id}-main.jpg"],
            ['id' => 12, 'src' => "https://store.test/img/{$id}-alt.jpg"],
        ],
        'regular_price' => '19.99',
        'price' => '17.99',
        'stock_quantity' => 12,
        'status' => 'publish',
        ...$overrides,
    ];
}

/**
 * Fake a WooCommerce store serving the given simple products.
 *
 * @param  array<int, array<string, mixed>>  $simple
 * @param  array<int, int>  $failPages  Pages that should return a 500.
 */
function fakeStore(array $simple, int $variableCount = 0, array $failPages = [], int $perPage = 100): void
{
    Http::fake(function (Request $request) use ($simple, $variableCount, $failPages, $perPage) {
        $query = $request->data();
        $type = $query['type'] ?? 'simple';
        $page = (int) ($query['page'] ?? 1);

        if ($type === 'variable') {
            return Http::response([], 200, ['X-WP-Total' => (string) $variableCount]);
        }

        if (in_array($page, $failPages, true)) {
            return Http::response('Upstream exploded', 500);
        }

        $pages = max(1, (int) ceil(count($simple) / $perPage));
        $slice = array_slice($simple, ($page - 1) * $perPage, $perPage);

        return Http::response(array_values($slice), 200, [
            'X-WP-Total' => (string) count($simple),
            'X-WP-TotalPages' => (string) $pages,
        ]);
    });
}

/**
 * Create a shop with a usable WooCommerce connection and a queued run.
 */
function queuedRun(?Shop $shop = null): SyncRun
{
    $shop ??= Shop::factory()->create();

    WooCommerceConnection::factory()->for($shop)->create([
        'store_url' => 'https://store.test',
    ]);

    return SyncRun::factory()->for($shop)->create();
}

function runSync(SyncRun $run): void
{
    app(SyncWooCommerceProducts::class, ['syncRun' => $run])
        ->handle(app(ImportWooCommerceProducts::class));
}

test('a sync creates products from the remote catalog', function () {
    fakeStore([remoteProduct(101), remoteProduct(102)]);

    $run = queuedRun();
    runSync($run);

    $run->refresh();

    expect($run->status)->toBe(SyncRunStatus::Completed)
        ->and($run->total_products)->toBe(2)
        ->and($run->processed_products)->toBe(2)
        ->and($run->created_products)->toBe(2);

    $this->assertDatabaseHas('products', [
        'shop_id' => $run->shop_id,
        'remote_id' => 101,
        'sku' => 'SKU-101',
        'name' => 'Remote product 101',
        'description' => 'Long 101',
        'short_description' => 'Short 101',
        'price' => '19.99',
        'stock' => 12,
        'status' => ProductStatus::Active->value,
    ]);
});

test('an imported brand is stored once and shared by its products', function () {
    fakeStore([
        remoteProduct(101),
        remoteProduct(102, ['brands' => [['id' => 7, 'name' => 'ACME', 'slug' => 'acme']]]),
        remoteProduct(103, ['brands' => [['id' => 8, 'name' => 'Globex', 'slug' => 'globex']]]),
    ]);

    $run = queuedRun();
    runSync($run);

    $brands = $run->shop->brands()->orderBy('slug')->pluck('name', 'slug');

    expect($brands->all())->toBe(['acme' => 'Acme', 'globex' => 'Globex'])
        ->and($run->shop->products()->where('remote_id', 102)->sole()->brand->slug)->toBe('acme');
});

test('a sync imports the richer woocommerce fields', function () {
    fakeStore([remoteProduct(101)]);

    $run = queuedRun();
    runSync($run);

    $product = $run->shop->products()->sole();

    expect($product->gtin)->toBe('0123456789101')
        ->and($product->brand->name)->toBe('Acme')
        ->and($product->categories)->toBe(['Bags', 'Accessories'])
        ->and($product->image_url)->toBe('https://store.test/img/101-main.jpg')
        ->and($product->gallery_image_urls)->toBe(['https://store.test/img/101-alt.jpg'])
        ->and($product->permalink)->toBe('https://store.test/product/101')
        ->and($product->short_description)->toBe('Short 101')
        ->and($product->description)->toBe('Long 101');
});

test('a product with no images brands or categories stores nulls', function () {
    fakeStore([remoteProduct(101, [
        'images' => [],
        'brands' => [],
        'categories' => [],
        'global_unique_id' => '',
        'permalink' => '',
    ])]);

    $run = queuedRun();
    runSync($run);

    $product = $run->shop->products()->sole();

    expect($product->image_url)->toBeNull()
        ->and($product->gallery_image_urls)->toBeNull()
        ->and($product->brand_id)->toBeNull()
        ->and($product->categories)->toBeNull()
        ->and($product->gtin)->toBeNull()
        ->and($product->permalink)->toBeNull();
});

test('a gtin stored in older product meta is still picked up', function () {
    fakeStore([remoteProduct(101, [
        'global_unique_id' => '',
        'meta_data' => [
            ['key' => '_something_else', 'value' => 'ignored'],
            ['key' => '_ean', 'value' => '5012345678900'],
        ],
    ])]);

    $run = queuedRun();
    runSync($run);

    expect($run->shop->products()->sole()->gtin)->toBe('5012345678900');
});

test('only published products are requested from the store', function () {
    fakeStore([remoteProduct(101)]);

    runSync(queuedRun());

    Http::assertSent(fn ($request) => ($request->data()['status'] ?? null) === 'publish');
    Http::assertNotSent(fn ($request) => ($request->data()['status'] ?? null) === 'any');
});

test('everything imported is active', function () {
    fakeStore([remoteProduct(101), remoteProduct(102)]);

    $run = queuedRun();
    runSync($run);

    expect($run->shop->products()->pluck('status')->unique()->all())
        ->toBe([ProductStatus::Active]);
});

test('a product that is unpublished in the store is archived', function () {
    $run = queuedRun();
    $unpublished = Product::factory()->for($run->shop)->create([
        'remote_id' => 202,
        'sku' => 'SKU-202',
        'status' => ProductStatus::Active,
    ]);

    // The store still has product 202, but it is no longer published, so it
    // is absent from the published-only listing the sync asks for.
    fakeStore([remoteProduct(101)]);
    runSync($run);

    expect($unpublished->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($run->refresh()->archived_products)->toBe(1);
});

test('a sync updates a product it imported before', function () {
    $run = queuedRun();
    $product = Product::factory()->for($run->shop)->create([
        'remote_id' => 101,
        'name' => 'Stale name',
        'sku' => 'SKU-101',
    ]);

    fakeStore([remoteProduct(101, ['name' => 'Fresh name'])]);
    runSync($run);

    expect($product->fresh()->name)->toBe('Fresh name')
        ->and($run->refresh()->updated_products)->toBe(1)
        ->and($run->created_products)->toBe(0);
});

test('a sync adopts a matching local product by sku', function () {
    $run = queuedRun();
    $product = Product::factory()->for($run->shop)->create([
        'remote_id' => null,
        'sku' => 'SKU-101',
    ]);

    fakeStore([remoteProduct(101)]);
    runSync($run);

    expect($product->fresh()->remote_id)->toBe(101)
        ->and($run->shop->products()->count())->toBe(1);
});

test('a sync does not steal a product already bound to another remote product', function () {
    $run = queuedRun();
    Product::factory()->for($run->shop)->create([
        'remote_id' => 999,
        'sku' => 'SKU-101',
    ]);

    fakeStore([remoteProduct(101)]);
    runSync($run);

    // The SKU is taken, so the new product falls back to its synthesized SKU.
    $this->assertDatabaseHas('products', [
        'shop_id' => $run->shop_id,
        'remote_id' => 101,
        'sku' => 'WC-101',
    ]);
});

test('a product with no sku gets a synthesized one', function () {
    fakeStore([remoteProduct(101, ['sku' => ''])]);

    $run = queuedRun();
    runSync($run);

    $this->assertDatabaseHas('products', [
        'remote_id' => 101,
        'sku' => 'WC-101',
    ]);
});

test('missing and unusable remote values fall back to safe defaults', function () {
    fakeStore([remoteProduct(101, [
        'regular_price' => '',
        'price' => 'not-a-number',
        'stock_quantity' => null,
        'short_description' => '',
        'description' => '',
    ])]);

    $run = queuedRun();
    runSync($run);

    $product = $run->shop->products()->sole();

    expect($product->price)->toBe('0.00')
        ->and($product->stock)->toBe(0)
        ->and($product->description)->toBeNull();
});

test('a sync archives products the store no longer has', function () {
    $run = queuedRun();
    $missing = Product::factory()->for($run->shop)->create([
        'remote_id' => 555,
        'sku' => 'SKU-555',
        'status' => ProductStatus::Active,
    ]);

    fakeStore([remoteProduct(101)]);
    runSync($run);

    expect($missing->fresh()->status)->toBe(ProductStatus::Archived)
        ->and($run->refresh()->archived_products)->toBe(1);
});

test('a sync never archives products created by hand', function () {
    $run = queuedRun();
    $local = Product::factory()->for($run->shop)->create([
        'remote_id' => null,
        'sku' => 'HAND-MADE',
        'status' => ProductStatus::Active,
    ]);

    fakeStore([remoteProduct(101)]);
    runSync($run);

    expect($local->fresh()->status)->toBe(ProductStatus::Active)
        ->and($run->refresh()->archived_products)->toBe(0);
});

test('a sync archives nothing when a page fails midway', function () {
    $run = queuedRun();
    $existing = Product::factory()->for($run->shop)->create([
        'remote_id' => 555,
        'sku' => 'SKU-555',
        'status' => ProductStatus::Active,
    ]);

    $products = array_map(fn (int $id) => remoteProduct($id), range(1, 250));
    fakeStore($products, failPages: [2], perPage: 100);

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);

    expect($existing->fresh()->status)->toBe(ProductStatus::Active)
        ->and($run->refresh()->archived_products)->toBe(0);
});

test('variable products are excluded and reported as skipped', function () {
    fakeStore([remoteProduct(101)], variableCount: 40);

    $run = queuedRun();
    runSync($run);

    expect($run->refresh()->skipped_products)->toBe(40)
        ->and($run->shop->products()->count())->toBe(1);
});

test('a sync walks every page of a large catalog', function () {
    $products = array_map(fn (int $id) => remoteProduct($id), range(1, 250));
    fakeStore($products, perPage: 100);

    $run = queuedRun();
    runSync($run);

    expect($run->refresh()->processed_products)->toBe(250)
        ->and($run->total_products)->toBe(250)
        ->and($run->shop->products()->count())->toBe(250);
});

test('rejected credentials fail the run without retrying', function () {
    Http::fake(fn () => Http::response(['message' => 'nope'], 401));

    $run = queuedRun();
    SyncWooCommerceProducts::dispatch($run);

    $run->refresh();

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->message)->toContain('rejected the API credentials');

    Http::assertSentCount(1);
});

test('a shop with no connection fails the run', function () {
    $shop = Shop::factory()->create();
    $run = SyncRun::factory()->for($shop)->create();

    SyncWooCommerceProducts::dispatch($run);

    expect($run->refresh()->status)->toBe(SyncRunStatus::Failed);
});
