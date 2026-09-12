<?php

use App\Actions\Sync\ImportWooCommerceCategories;
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
use Illuminate\Support\Str;

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
 * @param  array<int, array<string, mixed>>  $categories  The store's category tree.
 */
function fakeStore(
    array $simple,
    int $variableCount = 0,
    array $failPages = [],
    int $perPage = 100,
    array $categories = [],
): void {
    Http::fake(function (Request $request) use ($simple, $variableCount, $failPages, $perPage, $categories) {
        $query = $request->data();
        $type = $query['type'] ?? 'simple';
        $page = (int) ($query['page'] ?? 1);

        if (str_contains($request->url(), 'products/categories')) {
            return Http::response($page === 1 ? $categories : [], 200, [
                'X-WP-TotalPages' => '1',
            ]);
        }

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
        ->handle(app(ImportWooCommerceProducts::class), app(ImportWooCommerceCategories::class));
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

/**
 * Build a remote category payload in the shape WooCommerce returns.
 *
 * @return array<string, mixed>
 */
function remoteCategory(int $id, string $name, int $parent = 0): array
{
    return [
        'id' => $id,
        'name' => $name,
        'slug' => Str::slug($name),
        'parent' => $parent,
    ];
}

test('a sync rebuilds the store category tree', function () {
    fakeStore(
        [remoteProduct(101, ['categories' => [['id' => 20, 'name' => 'Totes', 'slug' => 'totes']]])],
        categories: [
            // The child arrives before its parent, which a store is free to do.
            remoteCategory(20, 'Totes', parent: 10),
            remoteCategory(10, 'Bags'),
            remoteCategory(30, 'Accessories'),
        ],
    );

    $run = queuedRun();
    runSync($run);

    $shop = $run->shop;
    $bags = $shop->categories()->where('remote_id', 10)->sole();
    $totes = $shop->categories()->where('remote_id', 20)->sole();

    expect($shop->categories()->count())->toBe(3)
        ->and($totes->parent_id)->toBe($bags->id)
        ->and($bags->parent_id)->toBeNull()
        ->and($shop->products()->sole()->categories->pluck('id')->all())->toBe([$totes->id]);
});

test('a renamed remote category keeps its row and its products', function () {
    $shop = Shop::factory()->create();
    $import = app(ImportWooCommerceCategories::class);

    $ids = $import->handle($shop, [remoteCategory(20, 'Totes')]);

    $product = Product::factory()->for($shop)->create();
    $product->categories()->sync([$ids[20]]);

    // The store renames the category; the id it is known by does not change.
    $again = $import->handle($shop, [remoteCategory(20, 'Tote Bags')]);

    $category = $shop->categories()->sole();

    expect($again[20])->toBe($ids[20])
        ->and($category->name)->toBe('Tote Bags')
        ->and($category->slug)->toBe('tote-bags')
        ->and($product->categories()->pluck('categories.id')->all())->toBe([$ids[20]]);
});

test('a renamed remote brand keeps its row and its products', function () {
    $shop = Shop::factory()->create();
    $import = app(ImportWooCommerceProducts::class);

    $import->handle($shop, [remoteProduct(101, [
        'brands' => [['id' => 7, 'name' => 'Acme', 'slug' => 'acme']],
    ])], now());

    $brandId = $shop->brands()->sole()->id;

    // The store renames the brand; the id it is known by does not change.
    $import->handle($shop, [remoteProduct(101, [
        'brands' => [['id' => 7, 'name' => 'Acme Corp', 'slug' => 'acme-corp']],
    ])], now());

    $brand = $shop->brands()->sole();

    expect($brand->id)->toBe($brandId)
        ->and($brand->name)->toBe('Acme Corp')
        ->and($brand->slug)->toBe('acme-corp')
        ->and($brand->remote_id)->toBe(7)
        ->and($shop->products()->sole()->brand_id)->toBe($brandId);
});

test('a brand named without an id still gets one row per name', function () {
    fakeStore([
        remoteProduct(101, ['brands' => [['name' => 'Acme']]]),
        remoteProduct(102, ['brands' => [['name' => 'ACME']]]),
    ]);

    $run = queuedRun();
    runSync($run);

    expect($run->shop->brands()->count())->toBe(1)
        ->and($run->shop->brands()->sole()->remote_id)->toBeNull();
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

test('html entities in remote names are decoded rather than stored raw', function () {
    fakeStore(
        [remoteProduct(101, [
            'name' => 'Spülschwamm - Kitty&amp;fish',
            'brands' => [['id' => 7, 'name' => 'Papier &amp; Co', 'slug' => 'papier-co']],
            'categories' => [['id' => 20, 'name' => 'Schmuck &amp; Accessoires', 'slug' => 'schmuck-accessoires']],
        ])],
        categories: [remoteCategory(20, 'Schmuck &amp; Accessoires')],
    );

    $run = queuedRun();
    runSync($run);

    $product = $run->shop->products()->sole();

    expect($product->name)->toBe('Spülschwamm - Kitty&fish')
        ->and($product->brand->name)->toBe('Papier & Co')
        ->and($product->categories->pluck('name')->all())->toBe(['Schmuck & Accessoires']);
});

test('a sync imports the richer woocommerce fields', function () {
    fakeStore([remoteProduct(101)]);

    $run = queuedRun();
    runSync($run);

    $product = $run->shop->products()->sole();

    expect($product->gtin)->toBe('0123456789101')
        ->and($product->brand->name)->toBe('Acme')
        ->and($product->categories->pluck('name')->sort()->values()->all())->toBe(['Accessories', 'Bags'])
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
        ->and($product->categories)->toBeEmpty()
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

test('reaching the product page limit never archives an unread product', function () {
    config(['services.woocommerce.max_pages' => 1]);
    $run = queuedRun();
    $existing = Product::factory()->for($run->shop)->create([
        'remote_id' => 102,
        'status' => ProductStatus::Active,
    ]);
    fakeStore([remoteProduct(101), remoteProduct(102)], perPage: 1);

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);
    expect($existing->fresh()->status)->toBe(ProductStatus::Active)
        ->and($run->refresh()->status)->not->toBe(SyncRunStatus::Completed)
        ->and($run->archived_products)->toBe(0);
});

test('an invalid catalog response never archives existing products', function (string $body) {
    $run = queuedRun();
    $existing = Product::factory()->for($run->shop)->create([
        'remote_id' => 102,
        'status' => ProductStatus::Active,
    ]);
    Http::fake(function (Request $request) use ($body) {
        if (str_contains($request->url(), 'products/categories') || ($request->data()['type'] ?? '') === 'variable') {
            return Http::response([], 200, ['X-WP-Total' => '0']);
        }

        return Http::response($body, 200);
    });

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);
    expect($existing->fresh()->status)->toBe(ProductStatus::Active)
        ->and($run->refresh()->archived_products)->toBe(0);
})->with(['<html>Maintenance</html>', '{}', 'null', '[{"name":"Missing ID"}]', '[{"id":-1}]']);

test('an unexpectedly empty product page cannot complete a partial catalog', function () {
    $run = queuedRun();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'products/categories') || ($request->data()['type'] ?? '') === 'variable') {
            return Http::response([], 200);
        }

        return Http::response([], 200, ['X-WP-Total' => '10', 'X-WP-TotalPages' => '1']);
    });

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);
    expect($run->refresh()->status)->not->toBe(SyncRunStatus::Completed);
});

test('reaching the category page limit does not import a partial tree', function () {
    config(['services.woocommerce.max_pages' => 1]);
    $run = queuedRun();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'products/categories')) {
            return Http::response([remoteCategory(20, 'Child', 10)], 200, ['X-WP-TotalPages' => '2']);
        }

        return Http::response([], 200);
    });

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);
    expect($run->shop->categories()->count())->toBe(0);
});

test('store redirects are rejected', function () {
    $run = queuedRun();
    Http::fake(fn () => Http::response('', 302, ['Location' => 'https://other.test']));

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);
    Http::assertSentCount(1);
});

test('a truncated final page cannot archive the remaining catalog', function () {
    $run = queuedRun();
    $existing = Product::factory()->for($run->shop)->create([
        'remote_id' => 102,
        'status' => ProductStatus::Active,
    ]);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'products/categories') || ($request->data()['type'] ?? '') === 'variable') {
            return Http::response([], 200);
        }

        return Http::response([remoteProduct(101)], 200, ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']);
    });

    expect(fn () => runSync($run))->toThrow(WooCommerceRequestException::class);
    expect($existing->fresh()->status)->toBe(ProductStatus::Active);
});
