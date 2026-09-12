<?php

use App\Enums\ProductStatus;
use App\Enums\ShopRole;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validProductData(array $overrides = []): array
{
    return [
        'name' => 'Classic Cotton Tote Bag',
        'sku' => 'TOT-10421',
        'description' => 'A sturdy everyday tote.',
        'price' => '24.00',
        'stock' => 120,
        'status' => ProductStatus::Active->value,
        ...$overrides,
    ];
}

test('shop owners can create products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $response = $this
        ->actingAs($user)
        ->from(route('products.index', $shop))
        ->post(route('products.store', $shop), validProductData());

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.index', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product created.']);

    $this->assertDatabaseHas('products', [
        'shop_id' => $shop->id,
        'name' => 'Classic Cotton Tote Bag',
        'sku' => 'TOT-10421',
        'price' => '24.00',
        'stock' => 120,
        'status' => ProductStatus::Active->value,
    ]);
});

test('product data is validated', function (array $overrides, string $field) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData($overrides))
        ->assertSessionHasErrors($field);

    expect($shop->products()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'missing sku' => [['sku' => ''], 'sku'],
    'negative price' => [['price' => '-1'], 'price'],
    'price with too many decimals' => [['price' => '1.999'], 'price'],
    'negative stock' => [['stock' => -5], 'stock'],
    'fractional stock' => [['stock' => '1.5'], 'stock'],
    'unknown status' => [['status' => 'deleted'], 'status'],
]);

test('skus must be unique within a shop', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->create(['sku' => 'TOT-10421']);

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData())
        ->assertSessionHasErrors('sku');

    expect($shop->products()->count())->toBe(1);
});

test('the same sku can be used in different shops', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->create(['sku' => 'TOT-10421']);

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData())
        ->assertSessionHasNoErrors();

    expect($shop->products()->where('sku', 'TOT-10421')->exists())->toBeTrue();
});

test('products can be updated', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create(['sku' => 'TOT-10421']);

    $response = $this
        ->actingAs($user)
        ->from(route('products.index', $shop))
        ->patch(route('products.update', [$shop, $product]), validProductData([
            'name' => 'Updated Tote Bag',
            'price' => '19.99',
            'stock' => 3,
            'status' => ProductStatus::Draft->value,
        ]));

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('products.index', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product updated.']);

    $product->refresh();

    expect($product->name)->toBe('Updated Tote Bag')
        ->and($product->price)->toBe('19.99')
        ->and($product->stock)->toBe(3)
        ->and($product->status)->toBe(ProductStatus::Draft);
});

test('members can create and update products', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $this
        ->actingAs($member)
        ->post(route('products.store', $shop), validProductData())
        ->assertSessionHasNoErrors();

    $product = $shop->products()->firstOrFail();

    $this
        ->actingAs($member)
        ->patch(route('products.update', [$shop, $product]), validProductData(['name' => 'Renamed Tote']))
        ->assertSessionHasNoErrors();

    expect($product->fresh()->name)->toBe('Renamed Tote');
});

test('members cannot delete products', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($member)
        ->delete(route('products.destroy', [$shop, $product]))
        ->assertForbidden();

    $this->assertModelExists($product);
});

test('admins can delete products', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($admin, ['role' => ShopRole::Admin->value]);

    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($admin)
        ->delete(route('products.destroy', [$shop, $product]))
        ->assertRedirect();

    $this->assertModelMissing($product);
});

test('owners can delete products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->from(route('products.index', $shop))
        ->delete(route('products.destroy', [$shop, $product]))
        ->assertRedirect(route('products.index', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product deleted.']);

    $this->assertModelMissing($product);
});

test('users cannot manage products in shops they do not belong to', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData())
        ->assertForbidden();

    $this
        ->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), validProductData())
        ->assertForbidden();

    $this
        ->actingAs($user)
        ->delete(route('products.destroy', [$shop, $product]))
        ->assertForbidden();

    expect($shop->products()->count())->toBe(1);
});

test('products from another shop cannot be modified through the current shop', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $otherShop = Shop::factory()->create();
    $otherShop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $foreignProduct = Product::factory()->for($otherShop)->create();

    $this
        ->actingAs($user)
        ->patch(route('products.update', [$shop, $foreignProduct]), validProductData())
        ->assertNotFound();

    $this
        ->actingAs($user)
        ->delete(route('products.destroy', [$shop, $foreignProduct]))
        ->assertNotFound();

    $this->assertModelExists($foreignProduct);
});

test('guests cannot manage products', function () {
    $shop = Shop::factory()->create();

    $this
        ->post(route('products.store', $shop), validProductData())
        ->assertRedirect(route('login'));
});
