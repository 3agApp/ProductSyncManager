<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

it('keeps an added category as a chip and marks the form unsaved', function () {
    $product = Product::factory()->for($this->shop)->create(['name' => 'Editable Product']);

    visit(route('products.edit', ['current_shop' => $this->shop->slug, 'product' => $product->id]))
        ->assertSee('Edit product')
        ->fill('@product-categories', 'Browser > Nested')
        ->click('@category-add')
        ->assertSeeIn('@category-chips', 'Browser > Nested')
        ->assertPresent('@unsaved-notice')
        ->assertNoJavaScriptErrors();
});

it('creates a product with a category from the form', function () {
    visit(route('products.create', ['current_shop' => $this->shop->slug]))
        ->fill('@product-name', 'Browser Made Product')
        ->fill('@product-sku', 'BROWSER-1')
        ->fill('@product-price', '12.50')
        ->fill('@product-stock', '10')
        ->fill('@product-categories', 'Browser > Nested')
        ->click('@category-add')
        ->click('@product-submit')
        ->assertPathEndsWith('/products')
        ->assertNoJavaScriptErrors();

    $product = $this->shop->products()->where('sku', 'BROWSER-1')->sole();

    expect($product->name)->toBe('Browser Made Product')
        ->and($product->categories()->pluck('name')->all())->toContain('Nested');
});
