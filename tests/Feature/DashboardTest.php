<?php

use App\Enums\ProductStatus;
use App\Enums\ShopRole;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
});

test('dashboard includes pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create(['name' => 'Laravel Shop']);

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 1)
        ->where('pendingInvitations.0.code', $invitation->code)
        ->where('pendingInvitations.0.inviterName', 'Taylor Otwell')
        ->where('pendingInvitations.0.shop.name', 'Laravel Shop')
        ->where('pendingInvitations.0.shop.slug', $shop->slug)
        ->missing('pendingInvitations.0.shopName'),
    );
});

test('dashboard does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    ShopInvitation::factory()->accepted()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );
});

test('dashboard excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->expired()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('dashboard does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->expired()->create([
        'shop_id' => $shop->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->has('pendingInvitations', 0),
    );

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('the dashboard reports catalog statistics for the shop', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->branded('Acme')->categorized('Bags > Totes')->create(['price' => 10, 'stock' => 3, 'status' => ProductStatus::Active]);
    Product::factory()->for($shop)->create(['price' => 5, 'stock' => 0, 'status' => ProductStatus::Draft]);
    Product::factory()->for($shop)->create(['price' => 2.5, 'stock' => 10, 'status' => ProductStatus::Active]);
    Product::factory()->for($shop)->create(['price' => 0, 'stock' => 1000, 'status' => ProductStatus::Archived]);
    Product::factory()->create(['price' => 1000, 'stock' => 1000]);

    $this
        ->actingAs($user)
        ->get(route('dashboard', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total', 4)
            ->where('stats.active', 2)
            ->where('stats.draft', 1)
            ->where('stats.archived', 1)
            ->where('stats.lowStock', 1)
            ->where('stats.outOfStock', 1)
            ->where('stats.inventoryValue', fn ($value) => (float) $value === 55.0)
            ->where('stats.brands', 1)
            ->where('stats.categories', 2),
        );
});

test('the dashboard shows the most recently updated products', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    Product::factory()->for($shop)->count(7)->create();
    $newest = Product::factory()->for($shop)->create(['name' => 'Just touched']);

    $this
        ->actingAs($user)
        ->get(route('dashboard', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->has('recentProducts', 5)
            ->where('recentProducts.0.id', $newest->id)
            ->missing('products'),
        );
});
