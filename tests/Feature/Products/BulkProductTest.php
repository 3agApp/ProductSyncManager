<?php

use App\Enums\ProductStatus;
use App\Enums\ShopRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('a brand can be set across many products at once', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create();
    $products = Product::factory()->for($shop)->count(3)->create();

    $this
        ->actingAs($user)
        ->from(route('products.index', $shop))
        ->post(route('products.bulk', $shop), [
            'action' => 'set-brand',
            'ids' => $products->pluck('id')->all(),
            'brand_id' => $brand->id,
        ])
        ->assertRedirect(route('products.index', $shop))
        ->assertSessionHasNoErrors();

    expect($shop->products()->where('brand_id', $brand->id)->count())->toBe(3);
});

test('a brand can be cleared across many products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create();
    $products = Product::factory()->for($shop)->for($brand)->count(2)->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'set-brand',
            'ids' => $products->pluck('id')->all(),
            'brand_id' => null,
        ])
        ->assertSessionHasNoErrors();

    expect($shop->products()->whereNull('brand_id')->count())->toBe(2);
});

test('products can be filed under a category in bulk, without duplicating links', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $category = Category::factory()->for($shop)->create();

    $alreadyFiled = Product::factory()->for($shop)->create();
    $alreadyFiled->categories()->sync([$category->id]);
    $others = Product::factory()->for($shop)->count(2)->create();

    $ids = [$alreadyFiled->id, ...$others->pluck('id')->all()];

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'add-category',
            'ids' => $ids,
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('category_product')->where('category_id', $category->id)->count())->toBe(3);
});

test('products can be taken out of a category in bulk', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $category = Category::factory()->for($shop)->create();

    $products = Product::factory()->for($shop)->count(3)->create();

    foreach ($products as $product) {
        $product->categories()->sync([$category->id]);
    }

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'remove-category',
            'ids' => $products->take(2)->pluck('id')->all(),
            'category_id' => $category->id,
        ])
        ->assertSessionHasNoErrors();

    expect(DB::table('category_product')->where('category_id', $category->id)->count())->toBe(1);
});

test('filing products under a category or taking them out counts as an edit', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $category = Category::factory()->for($shop)->create();
    [$added, $untouched] = Product::factory()->for($shop)->count(2)->create()->all();
    $removed = Product::factory()->for($shop)->create();
    $removed->categories()->sync([$category->id]);

    $this->travel(1)->minute();

    $this->actingAs($user)->post(route('products.bulk', $shop), [
        'action' => 'add-category',
        'ids' => [$added->id],
        'category_id' => $category->id,
    ]);

    $this->actingAs($user)->post(route('products.bulk', $shop), [
        'action' => 'remove-category',
        'ids' => [$removed->id, $untouched->id],
        'category_id' => $category->id,
    ]);

    expect($added->updated_at->lt($added->refresh()->updated_at))->toBeTrue()
        ->and($removed->updated_at->lt($removed->refresh()->updated_at))->toBeTrue()
        ->and($untouched->updated_at->eq($untouched->refresh()->updated_at))->toBeTrue();
});

test('a status can be set across many products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $products = Product::factory()->for($shop)->count(3)->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'set-status',
            'ids' => $products->pluck('id')->all(),
            'status' => ProductStatus::Archived->value,
        ])
        ->assertSessionHasNoErrors();

    expect($shop->products()->where('status', ProductStatus::Archived)->count())->toBe(3);
});

test('products can be deleted in bulk, taking their category links with them', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $category = Category::factory()->for($shop)->create();
    $products = Product::factory()->for($shop)->count(2)->create();

    foreach ($products as $product) {
        $product->categories()->sync([$category->id]);
    }

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'delete',
            'ids' => $products->pluck('id')->all(),
        ])
        ->assertSessionHasNoErrors();

    expect($shop->products()->count())->toBe(0)
        ->and(DB::table('category_product')->count())->toBe(0)
        ->and($shop->categories()->count())->toBe(1);
});

test('products belonging to another shop are left untouched', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $mine = Product::factory()->for($shop)->create();
    $theirs = Product::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'delete',
            'ids' => [$mine->id, $theirs->id],
        ])
        ->assertSessionHasNoErrors();

    $this->assertModelMissing($mine);
    $this->assertModelExists($theirs);
});

test('a brand from another shop cannot be applied', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $outsider = Brand::factory()->create();
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'set-brand',
            'ids' => [$product->id],
            'brand_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('brand_id');

    expect($product->refresh()->brand_id)->toBeNull();
});

test('a category from another shop cannot be applied', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $outsider = Category::factory()->create();
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'add-category',
            'ids' => [$product->id],
            'category_id' => $outsider->id,
        ])
        ->assertSessionHasErrors('category_id');

    expect(DB::table('category_product')->count())->toBe(0);
});

test('an unknown action is refused', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'drop-everything',
            'ids' => [$product->id],
        ])
        ->assertForbidden();

    $this->assertModelExists($product);
});

test('a member may edit in bulk but not delete in bulk', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $product = Product::factory()->for($shop)->create();

    $this->actingAs($member)
        ->post(route('products.bulk', $shop), [
            'action' => 'set-status',
            'ids' => [$product->id],
            'status' => ProductStatus::Draft->value,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($member)
        ->post(route('products.bulk', $shop), [
            'action' => 'delete',
            'ids' => [$product->id],
        ])
        ->assertForbidden();

    $this->assertModelExists($product);
});

test('more products than the cap are refused', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $user->currentShop), [
            'action' => 'delete',
            'ids' => range(1, 501),
        ])
        ->assertSessionHasErrors('ids');
});

test('a selection of products that no longer exist reports rather than crashing', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('products.bulk', $user->currentShop), [
            'action' => 'delete',
            'ids' => [999999],
        ])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Those products are no longer available.']);
});
