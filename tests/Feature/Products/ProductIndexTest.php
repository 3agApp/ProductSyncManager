<?php

use App\Enums\ProductStatus;
use App\Enums\ShopRole;
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
