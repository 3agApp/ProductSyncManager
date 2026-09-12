<?php

use App\Enums\ShopRole;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the brands page lists the shop brands with their product counts', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $acme = Brand::factory()->for($shop)->create(['name' => 'Acme']);
    Brand::factory()->for($shop)->create(['name' => 'Globex']);
    Brand::factory()->create(['name' => 'Someone Else']);

    Product::factory()->for($shop)->for($acme)->count(2)->create();

    $this
        ->actingAs($user)
        ->get(route('brands.index', $shop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('brands/index')
            ->has('brands', 2)
            ->where('brands.0.name', 'Acme')
            ->where('brands.0.productCount', 2)
            ->where('brands.1.name', 'Globex')
            ->where('brands.1.productCount', 0));
});

test('a brand can be added', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->from(route('brands.index', $shop))
        ->post(route('brands.store', $shop), ['name' => '  Acme  '])
        ->assertRedirect(route('brands.index', $shop))
        ->assertSessionHasNoErrors();

    $brand = $shop->brands()->sole();

    expect($brand->name)->toBe('Acme')->and($brand->slug)->toBe('acme');
});

test('a brand the shop already has by another spelling is rejected', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->post(route('brands.store', $shop), ['name' => 'ACME'])
        ->assertSessionHasErrors('name');

    expect($shop->brands()->count())->toBe(1);
});

test('a name with nothing sluggable in it is rejected', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('brands.store', $user->currentShop), ['name' => '###'])
        ->assertSessionHasErrors('name');
});

test('another shop using the same brand name does not block it', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Brand::factory()->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->post(route('brands.store', $shop), ['name' => 'Acme'])
        ->assertSessionHasNoErrors();

    expect($shop->brands()->count())->toBe(1);
});

test('a brand can be renamed, and its slug follows', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['name' => 'Acme Corp'])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->name)->toBe('Acme Corp')
        ->and($brand->slug)->toBe('acme-corp');
});

test('renaming a brand to its own name is allowed', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['name' => 'Acme'])
        ->assertSessionHasNoErrors();
});

test('deleting a brand leaves its products without one', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create();
    $product = Product::factory()->for($shop)->for($brand)->create();

    $this
        ->actingAs($user)
        ->from(route('brands.index', $shop))
        ->delete(route('brands.destroy', [$shop, $brand]))
        ->assertRedirect(route('brands.index', $shop));

    expect($product->refresh()->brand_id)->toBeNull()
        ->and($shop->brands()->count())->toBe(0);
});

test('a brand in another shop cannot be reached through this one', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$user->currentShop, $brand]), ['name' => 'Mine'])
        ->assertNotFound();
});

test('members can add and rename brands but not delete them', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $brand = Brand::factory()->for($shop)->create();

    $this->actingAs($member)
        ->post(route('brands.store', $shop), ['name' => 'Acme'])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->patch(route('brands.update', [$shop, $brand]), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->delete(route('brands.destroy', [$shop, $brand]))
        ->assertForbidden();
});

test('non members cannot reach the brands page', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $this->actingAs($user)->get(route('brands.index', $shop))->assertForbidden();
});

test('guests are sent to login', function () {
    $shop = Shop::factory()->create();

    $this->get(route('brands.index', $shop))->assertRedirect(route('login'));
});
