<?php

use App\Enums\NaldaListing;
use App\Enums\ShopRole;
use App\Enums\TemuListing;
use App\Models\Brand;
use App\Models\NaldaConnection;
use App\Models\Product;
use App\Models\Shop;
use App\Models\TemuConnection;
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

test('the brands page offers the listing choices only for connected marketplaces', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Brand::factory()->for($shop)->temuListing(TemuListing::Include)->create(['name' => 'Acme']);
    TemuConnection::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('brands.index', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasTemu', true)
            ->where('hasNalda', false)
            ->where('brands.0.temuListing', 'include')
            ->where('brands.0.naldaListing', 'default')
            ->where('temuListings.0.label', 'Follow shop default'));

    NaldaConnection::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('brands.index', $shop))
        ->assertInertia(fn (Assert $page) => $page->where('hasNalda', true));
});

test('a brand listing choice can be changed without touching its name', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['temu_listing' => 'exclude'])
        ->assertSessionHasNoErrors();

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['nalda_listing' => 'include'])
        ->assertSessionHasNoErrors();

    $brand->refresh();

    expect($brand->temu_listing)->toBe(TemuListing::Exclude)
        ->and($brand->nalda_listing)->toBe(NaldaListing::Include)
        ->and($brand->name)->toBe('Acme')
        ->and($brand->slug)->toBe('acme');
});

test('a brand can sell on temu at its own discount, and go back to the shop discount', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create(['name' => 'Acme']);
    TemuConnection::factory()->for($shop)->create(['discount_percent' => 10]);

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['temu_discount_percent' => '15'])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->temu_discount_percent)->toBe('15.00')
        ->and($brand->name)->toBe('Acme');

    $this
        ->actingAs($user)
        ->get(route('brands.index', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('brands.0.temuDiscountPercent', 15)
            ->where('temuDiscountPercent', 10));

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['temu_discount_percent' => ''])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->temu_discount_percent)->toBeNull();

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['temu_discount_percent' => '120'])
        ->assertSessionHasErrors('temu_discount_percent');
});

test('renaming a brand keeps its listing choices', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->temuListing(TemuListing::Exclude)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['name' => 'Globex'])
        ->assertSessionHasNoErrors();

    expect($brand->refresh()->name)->toBe('Globex')
        ->and($brand->temu_listing)->toBe(TemuListing::Exclude);
});

test('an unknown brand listing choice is rejected', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$shop, $brand]), ['temu_listing' => 'sometimes', 'name' => ''])
        ->assertSessionHasErrors(['temu_listing', 'name']);

    expect($brand->refresh()->temu_listing)->toBe(TemuListing::Default);
});

test('a new brand still needs a name', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('brands.store', $shop), ['temu_listing' => 'include'])
        ->assertSessionHasErrors('name');

    expect($shop->brands()->count())->toBe(0);
});

test('a non member cannot change a brand listing choice', function () {
    $user = User::factory()->create();
    $brand = Brand::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('brands.update', [$brand->shop, $brand]), ['temu_listing' => 'include'])
        ->assertForbidden();

    expect($brand->refresh()->temu_listing)->toBe(TemuListing::Default);
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
