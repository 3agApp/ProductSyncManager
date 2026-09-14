<?php

use App\Enums\ShopRole;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('the create page renders with the statuses it needs', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Brand::factory()->for($shop)->create(['name' => 'Globex']);
    Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $this
        ->actingAs($user)
        ->get(route('products.create', $shop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/create')
            ->where('shop.slug', $shop->slug)
            ->where('brands', ['Acme', 'Globex'])
            ->has('categories')
            ->has('statuses', 3));
});

test('the edit page renders the product being edited', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create(['name' => 'Classic Tote']);

    $this
        ->actingAs($user)
        ->get(route('products.edit', [$shop, $product]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/edit')
            ->where('shop.slug', $shop->slug)
            ->where('product.id', $product->id)
            ->where('product.name', 'Classic Tote')
            ->has('product', fn (Assert $props) => $props->hasAll([
                'id',
                'remoteId',
                'name',
                'sku',
                'gtin',
                'description',
                'shortDescription',
                'brand',
                'categories',
                'imageUrl',
                'galleryImageUrls',
                'permalink',
                'price',
                'stock',
                'status',
                'naldaListing',
            ]))
            ->has('statuses', 3));
});

test('members can reach both product forms', function (string $routeName) {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $product = Product::factory()->for($shop)->create();

    $parameters = $routeName === 'products.edit' ? [$shop, $product] : [$shop];

    $this->actingAs($member)->get(route($routeName, $parameters))->assertOk();
})->with(['products.create', 'products.edit']);

test('a product from another shop cannot be edited through this shop', function () {
    $user = User::factory()->create();
    $otherShop = Shop::factory()->create();
    $foreign = Product::factory()->for($otherShop)->create();

    $this
        ->actingAs($user)
        ->get(route('products.edit', [$user->currentShop, $foreign]))
        ->assertNotFound();
});

test('non members cannot reach the product forms', function () {
    $outsider = User::factory()->create();
    $shop = Shop::factory()->create();

    $this->actingAs($outsider)->get(route('products.create', $shop))->assertForbidden();
});

test('guests are sent to login', function () {
    $user = User::factory()->create();

    $this->get(route('products.create', $user->currentShop))->assertRedirect(route('login'));
});

test('saving a product returns to the product list', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->from(route('products.edit', [$shop, $product]))
        ->patch(route('products.update', [$shop, $product]), validProductData())
        ->assertRedirect(route('products.index', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product updated.']);
});

test('creating a product returns to the product list', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->from(route('products.create', $shop))
        ->post(route('products.store', $shop), validProductData())
        ->assertRedirect(route('products.index', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product created.']);
});

test('list fields are split, trimmed and de-duplicated', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData([
            'gtin' => '5012345678900',
            'short_description' => 'A sturdy everyday tote.',
            'categories' => 'Bags,Accessories',
            'image_url' => 'https://example.com/tote.jpg',
            'gallery_image_urls' => "https://example.com/a.jpg\n\n https://example.com/b.jpg ",
            'permalink' => 'https://example.com/product/tote',
        ]))
        ->assertSessionHasNoErrors();

    $product = $shop->products()->sole();

    expect($product->gtin)->toBe('5012345678900')
        ->and($product->short_description)->toBe('A sturdy everyday tote.')
        ->and($product->categories->pluck('name')->all())->toBe(['Bags', 'Accessories'])
        ->and($product->image_url)->toBe('https://example.com/tote.jpg')
        ->and($product->gallery_image_urls)->toBe([
            'https://example.com/a.jpg',
            'https://example.com/b.jpg',
        ])
        ->and($product->permalink)->toBe('https://example.com/product/tote');
});

test('submitting the fields empty clears them', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $product = Product::factory()
        ->for($shop)
        ->for(Brand::factory()->for($shop))
        ->categorized('Bags')
        ->create([
            'gtin' => '5012345678900',
            'gallery_image_urls' => ['https://example.com/a.jpg'],
        ]);

    $this
        ->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), validProductData([
            'gtin' => '',
            'brand' => '',
            'categories' => '',
            'gallery_image_urls' => '',
        ]))
        ->assertSessionHasNoErrors();

    $product->refresh();

    expect($product->gtin)->toBeNull()
        ->and($product->brand_id)->toBeNull()
        ->and($product->categories)->toBeEmpty()
        ->and($product->gallery_image_urls)->toBeNull();
});

test('omitting the fields leaves what is stored alone', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $brand = Brand::factory()->for($shop)->create(['name' => 'Acme']);

    $product = Product::factory()
        ->for($shop)
        ->for($brand)
        ->categorized('Bags')
        ->create(['gtin' => '5012345678900']);

    // validProductData() carries none of the newer fields.
    $this
        ->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), validProductData())
        ->assertSessionHasNoErrors();

    $product->refresh();

    expect($product->gtin)->toBe('5012345678900')
        ->and($product->brand_id)->toBe($brand->id)
        ->and($product->categories->pluck('name')->all())->toBe(['Bags']);
});

test('a typed brand becomes a row on the shop', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData(['brand' => ' Acme ']))
        ->assertSessionHasNoErrors();

    $brand = $shop->brands()->sole();

    expect($brand->name)->toBe('Acme')
        ->and($brand->slug)->toBe('acme')
        ->and($shop->products()->sole()->brand_id)->toBe($brand->id);
});

test('a brand spelled differently joins the row it already has', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $brand = Brand::factory()->for($shop)->create(['name' => 'Acme', 'slug' => 'acme']);

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData(['brand' => 'ACME']))
        ->assertSessionHasNoErrors();

    expect($shop->brands()->count())->toBe(1)
        ->and($shop->products()->sole()->brand_id)->toBe($brand->id);
});

test('a written path files the product under a nested category', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData([
            'categories' => 'Bags > Totes, Accessories',
        ]))
        ->assertSessionHasNoErrors();

    $totes = $shop->categories()->where('slug', 'totes')->sole();
    $bags = $shop->categories()->where('slug', 'bags')->sole();

    expect($totes->parent_id)->toBe($bags->id)
        ->and($bags->parent_id)->toBeNull()
        ->and($shop->products()->sole()->categories->pluck('slug')->sort()->values()->all())
        ->toBe(['accessories', 'totes']);
});

test('a product is filed under many categories and a category holds many products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->categorized('Bags', 'Accessories')->create();
    Product::factory()->for($shop)->categorized('Bags')->create();

    $bags = $shop->categories()->where('slug', 'bags')->sole();

    expect($shop->products()->first()->categories)->toHaveCount(2)
        ->and($bags->products)->toHaveCount(2);
});

test('deleting a product leaves its categories standing', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $product = Product::factory()->for($shop)->categorized('Bags')->create();

    $this
        ->actingAs($user)
        ->delete(route('products.destroy', [$shop, $product]))
        ->assertSessionHasNoErrors();

    expect($shop->categories()->count())->toBe(1)
        ->and(DB::table('category_product')->count())->toBe(0);
});

test('brands belong to the shop that made them', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $other = Shop::factory()->create();

    Brand::factory()->for($other)->create(['name' => 'Acme', 'slug' => 'acme']);

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData(['brand' => 'Acme']))
        ->assertSessionHasNoErrors();

    expect($shop->brands()->count())->toBe(1)
        ->and($other->brands()->count())->toBe(1)
        ->and($shop->products()->sole()->brand->shop_id)->toBe($shop->id);
});

test('the newer fields are validated', function (array $overrides, string $field) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData($overrides))
        ->assertSessionHasErrors($field);

    expect($shop->products()->count())->toBe(0);
})->with([
    'primary image is not a url' => [['image_url' => 'not-a-url'], 'image_url'],
    'gallery holds a non url' => [['gallery_image_urls' => 'nope'], 'gallery_image_urls.0'],
    'store link is not a url' => [['permalink' => 'nope'], 'permalink'],
    'gtin too long' => [['gtin' => str_repeat('9', 65)], 'gtin'],
    'brand too long' => [['brand' => str_repeat('a', 256)], 'brand'],
]);

test('a submitted list keeps entries that contain the separator', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    // The picker submits discrete values, so a comma inside one of them is
    // part of the name rather than a break between two.
    $this
        ->actingAs($user)
        ->post(route('products.store', $shop), validProductData([
            'categories' => ['Toys, Games', 'Bags > Totes'],
        ]))
        ->assertSessionHasNoErrors();

    expect($shop->categories()->pluck('name')->sort()->values()->all())
        ->toBe(['Bags', 'Totes', 'Toys, Games']);
});
