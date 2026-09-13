<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

/**
 * Fail the save once the category rows start being written, which is after
 * the brand and the product itself have already been saved.
 */
function failWhenCategoriesAreWritten(): void
{
    Category::created(fn () => throw new RuntimeException('Category write failed'));
}

test('a product create that fails midway leaves nothing behind', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    failWhenCategoriesAreWritten();

    $this->withoutExceptionHandling()->actingAs($user);

    expect(fn () => $this->post(route('products.store', $shop), validProductData([
        'brand' => 'Acme',
        'categories' => 'Bags > Totes',
    ])))->toThrow(RuntimeException::class, 'Category write failed');

    expect($shop->products()->count())->toBe(0)
        ->and($shop->brands()->count())->toBe(0)
        ->and($shop->categories()->count())->toBe(0);
});

test('a product update that fails midway keeps the product as it was', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $product = Product::factory()->for($shop)->categorized('Shoes')->create([
        'name' => 'Original name',
        'brand_id' => null,
    ]);

    failWhenCategoriesAreWritten();

    $this->withoutExceptionHandling()->actingAs($user);

    expect(fn () => $this->patch(route('products.update', [$shop, $product]), validProductData([
        'name' => 'Renamed',
        'brand' => 'Acme',
        'categories' => 'Bags',
    ])))->toThrow(RuntimeException::class, 'Category write failed');

    $product->refresh();

    expect($product->name)->toBe('Original name')
        ->and($product->brand_id)->toBeNull()
        ->and($shop->brands()->count())->toBe(0)
        ->and($product->categories->pluck('name')->all())->toBe(['Shoes'])
        ->and($shop->categories()->pluck('name')->all())->toBe(['Shoes']);
});
