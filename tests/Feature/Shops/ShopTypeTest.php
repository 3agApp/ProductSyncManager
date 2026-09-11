<?php

use App\Enums\ShopRole;
use App\Enums\ShopType;
use App\Models\Shop;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a personal shop defaults to the wordpress type', function () {
    $user = User::factory()->create();

    expect($user->currentShop->type)->toBe(ShopType::Wordpress);
});

test('a shop is created with the chosen type', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.store'), [
            'name' => 'Type Test Shop',
            'type' => ShopType::Wordpress->value,
        ])
        ->assertSessionHasNoErrors();

    $this->assertDatabaseHas('shops', [
        'name' => 'Type Test Shop',
        'type' => ShopType::Wordpress->value,
    ]);
});

test('an unknown type is rejected', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.store'), ['name' => 'Bad Type Shop', 'type' => 'shopify'])
        ->assertSessionHasErrors('type');

    $this->assertDatabaseMissing('shops', ['name' => 'Bad Type Shop']);
});

test('an omitted type keeps the shop type unchanged', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->patch(route('shops.update', $shop), ['name' => 'Renamed Shop'])
        ->assertSessionHasNoErrors();

    expect($shop->fresh()->type)->toBe(ShopType::Wordpress);
});

test('members cannot change the shop type', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $this
        ->actingAs($member)
        ->patch(route('shops.update', $shop), [
            'name' => $shop->name,
            'type' => ShopType::Wordpress->value,
        ])
        ->assertForbidden();
});

test('the shop edit page exposes the type and the available types', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('shops.edit', $user->currentShop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shops/edit')
            ->where('shop.type', 'wordpress')
            ->where('shop.typeLabel', 'WordPress')
            ->where('shopTypes.0.value', 'wordpress')
            ->where('shopTypes.0.label', 'WordPress'));
});
