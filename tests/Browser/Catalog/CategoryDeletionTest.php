<?php

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

it('removes a category branch and moves its products to another category', function () {
    Product::factory()->for($this->shop)->categorized('Move root > Move child')
        ->create(['name' => 'Exclusive Item']);
    Product::factory()->for($this->shop)->categorized('Move root > Move child', 'Move destination')
        ->create(['name' => 'Shared Item']);

    visit(route('categories.index', ['current_shop' => $this->shop->slug]))
        ->click('button:has-text("Delete Move root")')
        ->radio('category-children', 'delete')
        ->radio('category-products', 'move')
        ->assertSeeIn('@category-delete-preview', '2 unique products affected')
        ->click('#category-destination')
        ->click('[role="option"]:has-text("Move destination")')
        ->click('@delete-term-confirm')
        ->assertDontSee('Move root')
        ->assertDontSee('Move child')
        ->assertNoJavaScriptErrors();

    expect($this->shop->categories()->where('name', 'Move root')->exists())->toBeFalse()
        ->and($this->shop->categories()->where('name', 'Move child')->exists())->toBeFalse();

    $destination = $this->shop->categories()->where('name', 'Move destination')->sole();

    expect($destination->products()->pluck('name')->sort()->values()->all())
        ->toBe(['Exclusive Item', 'Shared Item']);
});

it('asks the category name to be typed before deleting products permanently', function () {
    Product::factory()->for($this->shop)->categorized('Delete root > Delete child')
        ->create(['name' => 'Exclusive Item']);
    Product::factory()->for($this->shop)->categorized('Delete root > Delete child', 'Delete survivor')
        ->create(['name' => 'Shared Item']);

    visit(route('categories.index', ['current_shop' => $this->shop->slug]))
        ->click('button:has-text("Delete Delete root")')
        ->radio('category-children', 'delete')
        ->radio('category-products', 'delete')
        ->assertSeeIn('@category-delete-preview', '1 product will be permanently deleted. 1 shared product will be kept.')
        ->assertDisabled('@delete-term-confirm')
        ->fill('#category-delete-confirmation', 'Delete root')
        ->click('@delete-term-confirm')
        ->assertNoJavaScriptErrors();

    // The product that lived only under the deleted branch is gone, and the
    // one that also sat elsewhere is kept.
    expect($this->shop->products()->pluck('name')->all())->toBe(['Shared Item']);
});
