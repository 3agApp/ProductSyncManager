<?php

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SyncRun;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the dashboard reports an in flight sync', function () {
    $user = User::factory()->create();

    SyncRun::factory()->for($user->currentShop)->running()->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard', $user->currentShop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('activeSyncRun.status', 'running')
            ->where('activeSyncRun.progress', 40)
            ->where('activeSyncRun.processedProducts', 40)
            ->where('activeSyncRun.totalProducts', 100));
});

test('the dashboard reports nothing when no sync is running', function () {
    $user = User::factory()->create();

    SyncRun::factory()->for($user->currentShop)->completed()->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard', $user->currentShop))
        ->assertInertia(fn (Assert $page) => $page->where('activeSyncRun', null));
});

test('the dashboard does not report another shops sync', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    SyncRun::factory()->for($other->currentShop)->running()->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard', $user->currentShop))
        ->assertInertia(fn (Assert $page) => $page->where('activeSyncRun', null));
});

test('the products page exposes the richer product fields', function () {
    $user = User::factory()->create();

    $brand = Brand::factory()->for($user->currentShop)->create(['name' => 'Acme']);

    $bags = Category::factory()->for($user->currentShop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);

    $product = $user->currentShop->products()->create([
        'brand_id' => $brand->id,
        'remote_id' => 500,
        'name' => 'Synced Tote',
        'sku' => 'TOTE-500',
        'gtin' => '5012345678900',
        'description' => 'The long description.',
        'short_description' => 'The short one.',
        'image_url' => 'https://store.test/img/main.jpg',
        'gallery_image_urls' => ['https://store.test/img/alt.jpg'],
        'permalink' => 'https://store.test/product/500',
        'price' => '24.00',
        'stock' => 10,
        'status' => ProductStatus::Active,
    ]);

    $product->categories()->sync([$totes->id]);

    $this
        ->actingAs($user)
        ->get(route('products.index', $user->currentShop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.gtin', '5012345678900')
            ->where('products.data.0.shortDescription', 'The short one.')
            ->where('products.data.0.brand', 'Acme')
            ->where('products.data.0.categories', ['Bags > Totes'])
            ->where('products.data.0.imageUrl', 'https://store.test/img/main.jpg')
            ->where('products.data.0.galleryImageUrls', ['https://store.test/img/alt.jpg'])
            ->where('products.data.0.permalink', 'https://store.test/product/500'));
});

test('a hand made product reports empty collections rather than null', function () {
    $user = User::factory()->create();

    Product::factory()->for($user->currentShop)->create();

    $this
        ->actingAs($user)
        ->get(route('products.index', $user->currentShop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.brand', null)
            ->where('products.data.0.categories', [])
            ->where('products.data.0.galleryImageUrls', [])
            ->where('products.data.0.imageUrl', null)
            ->where('products.data.0.gtin', null));
});
