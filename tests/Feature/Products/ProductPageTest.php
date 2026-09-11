<?php

use App\Enums\ShopRole;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the create page renders with the statuses it needs', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->get(route('products.create', $shop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('products/create')
            ->where('shop.slug', $shop->slug)
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
                'brands',
                'categories',
                'imageUrl',
                'galleryImageUrls',
                'permalink',
                'price',
                'stock',
                'status',
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

test('saving a product returns to the dashboard', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->from(route('products.edit', [$shop, $product]))
        ->patch(route('products.update', [$shop, $product]), validProductData())
        ->assertRedirect(route('dashboard', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Product updated.']);
});

test('creating a product returns to the dashboard', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->from(route('products.create', $shop))
        ->post(route('products.store', $shop), validProductData())
        ->assertRedirect(route('dashboard', $shop))
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
            'brands' => ' Acme , Globex ,Acme ',
            'categories' => 'Bags,Accessories',
            'image_url' => 'https://example.com/tote.jpg',
            'gallery_image_urls' => "https://example.com/a.jpg\n\n https://example.com/b.jpg ",
            'permalink' => 'https://example.com/product/tote',
        ]))
        ->assertSessionHasNoErrors();

    $product = $shop->products()->sole();

    expect($product->gtin)->toBe('5012345678900')
        ->and($product->short_description)->toBe('A sturdy everyday tote.')
        ->and($product->brands)->toBe(['Acme', 'Globex'])
        ->and($product->categories)->toBe(['Bags', 'Accessories'])
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

    $product = Product::factory()->for($shop)->create([
        'gtin' => '5012345678900',
        'brands' => ['Acme'],
        'categories' => ['Bags'],
        'gallery_image_urls' => ['https://example.com/a.jpg'],
    ]);

    $this
        ->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), validProductData([
            'gtin' => '',
            'brands' => '',
            'categories' => '',
            'gallery_image_urls' => '',
        ]))
        ->assertSessionHasNoErrors();

    $product->refresh();

    expect($product->gtin)->toBeNull()
        ->and($product->brands)->toBeNull()
        ->and($product->categories)->toBeNull()
        ->and($product->gallery_image_urls)->toBeNull();
});

test('omitting the fields leaves what is stored alone', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $product = Product::factory()->for($shop)->create([
        'gtin' => '5012345678900',
        'brands' => ['Acme'],
    ]);

    // validProductData() carries none of the newer fields.
    $this
        ->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), validProductData())
        ->assertSessionHasNoErrors();

    $product->refresh();

    expect($product->gtin)->toBe('5012345678900')
        ->and($product->brands)->toBe(['Acme']);
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
]);
