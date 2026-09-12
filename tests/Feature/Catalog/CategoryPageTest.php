<?php

use App\Enums\ShopRole;
use App\Http\Requests\Catalog\SaveCategoryRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the categories page lists the tree with paths, depth and counts', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);
    Category::factory()->create(['name' => 'Someone Else']);

    Product::factory()->for($shop)->create()->categories()->sync([$totes->id]);

    $this
        ->actingAs($user)
        ->get(route('categories.index', $shop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('categories/index')
            ->has('categories', 2)
            ->where('categories.0.name', 'Bags')
            ->where('categories.0.path', 'Bags')
            ->where('categories.0.depth', 0)
            ->where('categories.0.childCount', 1)
            ->where('categories.1.name', 'Totes')
            ->where('categories.1.path', 'Bags > Totes')
            ->where('categories.1.depth', 1)
            ->where('categories.1.productCount', 1));
});

test('a category can be added at the top of the tree', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->from(route('categories.index', $shop))
        ->post(route('categories.store', $shop), [
            'name' => '  Bags  ',
            'parent_id' => SaveCategoryRequest::NO_PARENT,
        ])
        ->assertRedirect(route('categories.index', $shop))
        ->assertSessionHasNoErrors();

    $category = $shop->categories()->sole();

    expect($category->name)->toBe('Bags')
        ->and($category->slug)->toBe('bags')
        ->and($category->parent_id)->toBeNull();
});

test('a category can be added under another', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);

    $this
        ->actingAs($user)
        ->post(route('categories.store', $shop), ['name' => 'Totes', 'parent_id' => $bags->id])
        ->assertSessionHasNoErrors();

    expect($shop->categories()->where('slug', 'totes')->sole()->parent_id)->toBe($bags->id);
});

test('a parent from another shop is rejected', function () {
    $user = User::factory()->create();
    $outsider = Category::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('categories.store', $user->currentShop), [
            'name' => 'Totes',
            'parent_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('parent_id');

    expect($user->currentShop->categories()->count())->toBe(0);
});

test('a category cannot be filed under itself', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);

    $this
        ->actingAs($user)
        ->patch(route('categories.update', [$shop, $bags]), [
            'name' => 'Bags',
            'parent_id' => $bags->id,
        ])
        ->assertSessionHasErrors('parent_id');

    expect($bags->refresh()->parent_id)->toBeNull();
});

test('a category cannot be filed under its own subcategory', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);
    $canvas = Category::factory()->childOf($totes)->create(['name' => 'Canvas']);

    $this
        ->actingAs($user)
        ->patch(route('categories.update', [$shop, $bags]), [
            'name' => 'Bags',
            'parent_id' => $canvas->id,
        ])
        ->assertSessionHasErrors('parent_id');

    expect($bags->refresh()->parent_id)->toBeNull();
});

test('a category can be moved to the top of the tree', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);

    $this
        ->actingAs($user)
        ->patch(route('categories.update', [$shop, $totes]), [
            'name' => 'Totes',
            'parent_id' => SaveCategoryRequest::NO_PARENT,
        ])
        ->assertSessionHasNoErrors();

    expect($totes->refresh()->parent_id)->toBeNull();
});

test('a category the shop already has by another spelling is rejected', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Category::factory()->for($shop)->create(['name' => 'Bags']);

    $this
        ->actingAs($user)
        ->post(route('categories.store', $shop), ['name' => 'BAGS'])
        ->assertSessionHasErrors('name');

    expect($shop->categories()->count())->toBe(1);
});

test('deleting a category promotes its children and keeps its products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $bags = Category::factory()->for($shop)->create(['name' => 'Bags']);
    $totes = Category::factory()->childOf($bags)->create(['name' => 'Totes']);

    $product = Product::factory()->for($shop)->create();
    $product->categories()->sync([$bags->id]);

    $this
        ->actingAs($user)
        ->from(route('categories.index', $shop))
        ->delete(route('categories.destroy', [$shop, $bags]))
        ->assertRedirect(route('categories.index', $shop));

    expect($totes->refresh()->parent_id)->toBeNull()
        ->and($shop->categories()->count())->toBe(1)
        ->and($product->refresh()->exists)->toBeTrue()
        ->and($product->categories)->toBeEmpty();
});

test('a category in another shop cannot be reached through this one', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $this
        ->actingAs($user)
        ->delete(route('categories.destroy', [$user->currentShop, $category]))
        ->assertNotFound();
});

test('members can add and edit categories but not delete them', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $category = Category::factory()->for($shop)->create();

    $this->actingAs($member)
        ->post(route('categories.store', $shop), ['name' => 'Bags'])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->patch(route('categories.update', [$shop, $category]), ['name' => 'Renamed'])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->delete(route('categories.destroy', [$shop, $category]))
        ->assertForbidden();
});

test('non members cannot reach the categories page', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $this->actingAs($user)->get(route('categories.index', $shop))->assertForbidden();
});

test('guests are sent to login', function () {
    $shop = Shop::factory()->create();

    $this->get(route('categories.index', $shop))->assertRedirect(route('login'));
});
