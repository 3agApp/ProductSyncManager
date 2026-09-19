<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

it('folds the category tree and unfolds it again', function () {
    Product::factory()->for($this->shop)->categorized('Bags > Totes', 'Bags > Backpacks')->create();

    $page = visit(route('categories.index', ['current_shop' => $this->shop->slug]));

    $page->assertSee('Totes')
        ->click('@toggle-all-categories')
        ->assertDontSee('Totes')
        ->click('@toggle-all-categories')
        ->assertSee('Totes')
        ->assertNoJavaScriptErrors();
});

it('reveals a match wherever it sits in the tree', function () {
    Product::factory()->for($this->shop)->categorized('Bags > Totes', 'Homeware > Candles')->create();

    visit(route('categories.index', ['current_shop' => $this->shop->slug]))
        ->fill('@category-filter', 'totes')
        ->assertSeeIn('@category-count', 'of')
        ->assertSee('Totes')
        ->assertDontSee('Candles')
        ->assertNoJavaScriptErrors();
});

it('adds a category and removes it again', function () {
    $page = visit(route('categories.index', ['current_shop' => $this->shop->slug]));

    $page->fill('@category-name', 'Browser Goods')
        ->click('@category-submit')
        ->assertSee('Browser Goods')
        ->assertNoJavaScriptErrors();

    expect($this->shop->categories()->where('name', 'Browser Goods')->exists())->toBeTrue();

    $page->click('@category-delete')
        ->click('@delete-term-confirm')
        ->assertDontSee('Browser Goods')
        ->assertNoJavaScriptErrors();

    expect($this->shop->categories()->where('name', 'Browser Goods')->exists())->toBeFalse();
});
