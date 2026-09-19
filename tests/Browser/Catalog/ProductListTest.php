<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

it('filters the product list by brand and clears the filter again', function () {
    Product::factory()->for($this->shop)->branded('Initech')->create(['name' => 'Initech Widget']);
    Product::factory()->for($this->shop)->create(['name' => 'Plain Widget', 'brand_id' => null]);

    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->assertSee('Initech Widget')
        ->assertSee('Plain Widget')
        ->click('@product-brand-filter')
        ->click('[role="option"]:has-text("No brand")')
        ->assertQueryStringHas('brand', 'none')
        ->assertSeeIn('@product-count', 'of 1 products')
        ->assertSee('Plain Widget')
        ->assertDontSee('Initech Widget')
        ->click('@clear-filters')
        ->assertSeeIn('@product-count', 'of 2 products')
        ->assertSee('Initech Widget')
        ->assertNoJavaScriptErrors();
});

it('filters the product list by category', function () {
    Product::factory()->for($this->shop)->categorized('Bags > Totes')->create(['name' => 'Tote In Category']);
    Product::factory()->for($this->shop)->create(['name' => 'Product Without Category']);

    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->click('@product-category-filter')
        ->click('[role="option"]:has-text("Bags > Totes")')
        ->assertQueryStringHas('category')
        ->assertSeeIn('@product-count', 'of 1 products')
        ->assertSee('Tote In Category')
        ->assertDontSee('Product Without Category')
        ->assertNoJavaScriptErrors();
});

it('sorts the product table and turns the order around', function () {
    Product::factory()->for($this->shop)->create(['name' => 'Cheap Product', 'price' => '5.00']);
    Product::factory()->for($this->shop)->create(['name' => 'Costly Product', 'price' => '500.00']);

    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->click('@sort-price')
        ->assertQueryStringHas('sort', 'price')
        // Clicking the same column again turns the order around.
        ->click('@sort-price')
        ->assertQueryStringHas('direction', 'asc')
        ->assertNoJavaScriptErrors();
});

it('changes how many products a page shows', function () {
    Product::factory()->count(3)->for($this->shop)->create();

    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->click('@per-page')
        ->click('[role="option"]:has-text("25 / page")')
        ->assertQueryStringHas('per_page', '25')
        ->assertNoJavaScriptErrors();
});

it('changes several products at once', function () {
    Product::factory()->count(3)->for($this->shop)->create();

    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->click('@select-all')
        ->assertSee('selected')
        ->click('@bulk-status')
        ->click('[role="menuitem"]:has-text("Draft")')
        ->assertSee('products updated')
        ->assertNoJavaScriptErrors();

    expect($this->shop->products()->where('status', 'draft')->count())->toBe(3);
});

it('keeps both the search and the sort when they are chosen together', function () {
    Product::factory()->for($this->shop)->create(['name' => 'Canvas Bag', 'price' => '10.00']);

    // The search is debounced, so choosing a sort before it lands must not
    // drop either choice.
    visit(route('products.index', ['current_shop' => $this->shop->slug]))
        ->fill('@product-search', 'Bag')
        ->click('@sort-price')
        ->assertQueryStringHas('sort', 'price')
        ->assertQueryStringHas('search', 'Bag')
        ->assertValue('@product-search', 'Bag')
        ->assertNoJavaScriptErrors();
});
