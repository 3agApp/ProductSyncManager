<?php

use App\Enums\ProductStatus;
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

test('the dashboard exposes the richer product fields', function () {
    $user = User::factory()->create();

    $user->currentShop->products()->create([
        'remote_id' => 500,
        'name' => 'Synced Tote',
        'sku' => 'TOTE-500',
        'gtin' => '5012345678900',
        'description' => 'The long description.',
        'short_description' => 'The short one.',
        'brands' => ['Acme'],
        'categories' => ['Bags', 'Accessories'],
        'image_url' => 'https://store.test/img/main.jpg',
        'gallery_image_urls' => ['https://store.test/img/alt.jpg'],
        'permalink' => 'https://store.test/product/500',
        'price' => '24.00',
        'stock' => 10,
        'status' => ProductStatus::Active,
    ]);

    $this
        ->actingAs($user)
        ->get(route('dashboard', $user->currentShop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.gtin', '5012345678900')
            ->where('products.data.0.shortDescription', 'The short one.')
            ->where('products.data.0.brands', ['Acme'])
            ->where('products.data.0.categories', ['Bags', 'Accessories'])
            ->where('products.data.0.imageUrl', 'https://store.test/img/main.jpg')
            ->where('products.data.0.galleryImageUrls', ['https://store.test/img/alt.jpg'])
            ->where('products.data.0.permalink', 'https://store.test/product/500'));
});

test('a hand made product reports empty collections rather than null', function () {
    $user = User::factory()->create();

    Product::factory()->for($user->currentShop)->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard', $user->currentShop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.brands', [])
            ->where('products.data.0.categories', [])
            ->where('products.data.0.galleryImageUrls', [])
            ->where('products.data.0.imageUrl', null)
            ->where('products.data.0.gtin', null));
});
