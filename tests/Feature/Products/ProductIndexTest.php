<?php

use App\Enums\ProductStatus;
use App\Enums\ShopRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the products page lists products for the current shop only', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->count(3)->for($shop)->create();
    Product::factory()->count(2)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', $shop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/index')
            ->has('products.data', 3)
            ->where('products.total', 3)
            ->has('products.data.0', fn (Assert $product) => $product->hasAll([
                'id',
                'remoteId',
                'name',
                'sku',
                'gtin',
                'description',
                'shortDescription',
                'brand',
                'categories',
                'imageUrl',
                'galleryImageUrls',
                'permalink',
                'price',
                'stock',
                'status',
                'statusLabel',
                'naldaListing',
                'temuListing',
                'temuName',
                'updatedAt',
                'updatedAtDiff',
            ])),
        );
});

test('products are paginated', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->count(13)->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 3)
            ->where('products.current_page', 2)
            ->where('products.last_page', 2),
        );
});

test('the products page redirects to the last page when the requested page is out of range', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->count(12)->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'page' => 5]))
        ->assertRedirect(route('products.index', [$shop, 'page' => 2]));
});

test('products can be searched by name or sku', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->create(['name' => 'Ceramic Mug', 'sku' => 'MUG-001']);
    Product::factory()->for($shop)->create(['name' => 'Cotton Tote', 'sku' => 'TOT-001']);
    Product::factory()->for($shop)->create(['name' => 'Oak Planter', 'sku' => 'PLN-001']);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'search' => 'mug']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Ceramic Mug')
            ->where('filters.search', 'mug'),
        );

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'search' => 'TOT-']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'TOT-001'),
        );
});

test('products can be filtered by status', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->count(2)->for($shop)->create();
    Product::factory()->draft()->for($shop)->create();
    Product::factory()->archived()->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'status' => ProductStatus::Draft->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.status', ProductStatus::Draft->value)
            ->where('filters.status', ProductStatus::Draft->value),
        );
});

test('unknown status filters are ignored', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->count(2)->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'status' => 'bogus']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('filters.status', null),
        );
});

test('the products page exposes product permissions for the current user', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $this
        ->actingAs($member)
        ->get(route('products.index', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.canCreateProduct', true)
            ->where('permissions.canUpdateProduct', true)
            ->where('permissions.canDeleteProduct', false),
        );
});

test('products can be filtered by category, including everything nested under it', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);

    $inParent = Product::factory()->for($shop)->create(['name' => 'Parent bag']);
    $inChild = Product::factory()->for($shop)->create(['name' => 'Child tote']);
    Product::factory()->for($shop)->create(['name' => 'Unrelated']);

    $inParent->categories()->sync([$bags->id]);
    $inChild->categories()->sync([$totes->id]);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'category' => $bags->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('filters.category', (string) $bags->id));
});

test('products can be filtered down to a single nested category', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);

    Product::factory()->for($shop)->create()->categories()->sync([$bags->id]);
    Product::factory()->for($shop)->create(['name' => 'Only tote'])->categories()->sync([$totes->id]);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'category' => $totes->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Only tote'));
});

test('products with no category at all can be listed', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->categorized('Bags')->create();
    Product::factory()->for($shop)->create(['name' => 'Filed nowhere']);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'category' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Filed nowhere'));
});

test('products can be filtered by brand, and by having none', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $acme = Brand::factory()->for($shop)->create(['name' => 'Acme']);
    Product::factory()->for($shop)->for($acme)->create(['name' => 'An Acme thing']);
    Product::factory()->for($shop)->create(['name' => 'Unbranded thing']);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'brand' => $acme->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'An Acme thing'));

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'brand' => 'none']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Unbranded thing'));
});

test('another shops category cannot be used as a filter', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $outsider = Category::factory()->create();

    Product::factory()->for($shop)->count(2)->create();

    // The unusable filter is dropped rather than hiding the whole catalog.
    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'category' => $outsider->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('filters.category', null));
});

test('search also covers the gtin and the brand name', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $acme = Brand::factory()->for($shop)->create(['name' => 'Acme']);
    Product::factory()->for($shop)->for($acme)->create(['name' => 'Nondescript', 'sku' => 'AAA-1']);
    Product::factory()->for($shop)->create(['name' => 'Other', 'sku' => 'BBB-1', 'gtin' => '5012345678900']);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'search' => 'Acme']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'AAA-1'));

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'search' => '50123456']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.sku', 'BBB-1'));
});

test('products are sorted by name from a to z by default', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->create(['name' => 'Middle']);
    Product::factory()->for($shop)->create(['name' => 'Zebra']);
    Product::factory()->for($shop)->create(['name' => 'Apple']);

    $this
        ->actingAs($user)
        ->get(route('products.index', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Apple')
            ->where('products.data.1.name', 'Middle')
            ->where('products.data.2.name', 'Zebra')
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'));
});

test('numbers in names sort by their value', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    foreach (['Apple 12', '10 Buntstifte', 'apple 3', '2 Buntstifte', 'Apple'] as $name) {
        Product::factory()->for($shop)->create(['name' => $name]);
    }

    $this
        ->actingAs($user)
        ->get(route('products.index', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', '2 Buntstifte')
            ->where('products.data.1.name', '10 Buntstifte')
            ->where('products.data.2.name', 'Apple')
            ->where('products.data.3.name', 'apple 3')
            ->where('products.data.4.name', 'Apple 12'));

    $this->get(route('products.index', [$shop, 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Apple 12')
            ->where('products.data.4.name', '2 Buntstifte'));
});

test('renaming a product updates its sort key', function () {
    $product = Product::factory()->create(['name' => 'Box 9']);

    $product->update(['name' => 'Box 10']);

    expect($product->refresh()->name_sort)->toBe(Product::nameSortKey('Box 10'))
        ->and(Product::nameSortKey('Box 10'))->toBe('box 00000000000000000010');
});

test('a column chosen without a direction starts in its own default order', function (string $sort, string $direction) {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$user->currentShop, 'sort' => $sort]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', $sort)
            ->where('filters.direction', $direction));
})->with([
    'name' => ['name', 'asc'],
    'price' => ['price', 'desc'],
    'stock' => ['stock', 'desc'],
    'updated' => ['updated', 'desc'],
]);

test('products can be sorted by a chosen column', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->create(['name' => 'Middle', 'price' => 20]);
    Product::factory()->for($shop)->create(['name' => 'Cheapest', 'price' => 5]);
    Product::factory()->for($shop)->create(['name' => 'Dearest', 'price' => 90]);

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'sort' => 'price', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.name', 'Cheapest')
            ->where('products.data.2.name', 'Dearest')
            ->where('filters.sort', 'price')
            ->where('filters.direction', 'asc'));
});

test('an unknown sort column falls back to the default', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->count(2)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'sort' => 'price); drop table products--', 'direction' => 'sideways']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'));
});

test('the page size can be changed, and a silly one is ignored', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->count(30)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'per_page' => 25]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 25)
            ->where('filters.perPage', 25));

    $this
        ->actingAs($user)
        ->get(route('products.index', [$shop, 'per_page' => 9999]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 10)
            ->where('filters.perPage', 10));
});

test('the page offers the shops categories and brands as filter options', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    Category::factory()->childOf($bags)->create(['name' => 'Totes']);
    Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->get(route('products.index', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('categoryOptions.0.label', 'Bags')
            ->where('categoryOptions.1.label', 'Bags > Totes')
            ->where('brandOptions.0.label', 'Acme')
            ->has('pageSizes', 4));
});
