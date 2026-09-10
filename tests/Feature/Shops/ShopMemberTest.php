<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;

test('shop member roles can be updated by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('shops.members.update', [$shop, $member]), [
            'role' => ShopRole::Admin->value,
        ]);

    $response->assertRedirect(route('shops.edit', $shop));

    expect($shop->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(ShopRole::Admin->value);
});

test('shop member roles cannot be updated by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($admin, ['role' => ShopRole::Admin->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->patch(route('shops.members.update', [$shop, $member]), [
            'role' => ShopRole::Admin->value,
        ]);

    $response->assertForbidden();
});

test('shop members can be removed by owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('shops.members.destroy', [$shop, $member]));

    $response->assertRedirect(route('shops.edit', $shop));

    expect($member->fresh()->belongsToShop($shop))->toBeFalse();
});

test('shop members cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($admin, ['role' => ShopRole::Admin->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($admin)
        ->delete(route('shops.members.destroy', [$shop, $member]));

    $response->assertForbidden();
});

test('shop owner cannot be removed', function () {
    $owner = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('shops.members.destroy', [$shop, $owner]));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToShop($shop))->toBeTrue();
});

test('shop member role cannot be set to owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->patch(route('shops.members.update', [$shop, $member]), [
            'role' => ShopRole::Owner->value,
        ]);

    $response->assertSessionHasErrors('role');

    expect($shop->members()->where('user_id', $member->id)->first()->pivot->role->value)->toEqual(ShopRole::Member->value);
});

test('removed member current shop is set to personal shop', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalShop = $member->personalShop();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $member->update(['current_shop_id' => $shop->id]);

    $this
        ->actingAs($owner)
        ->delete(route('shops.members.destroy', [$shop, $member]));

    expect($member->fresh()->current_shop_id)->toEqual($personalShop->id);
});
