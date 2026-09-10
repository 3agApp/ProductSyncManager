<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the shops index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('shops.index'));

    $response->assertOk();
});

test('shops can be created', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('shops.store'), [
            'name' => 'Test Shop',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('shops', [
        'name' => 'Test Shop',
        'is_personal' => false,
    ]);
});

test('personal shop returns the shop owned by the user', function () {
    $otherUser = User::factory()->create();
    $user = User::factory()->make();
    $user->save();

    $otherUser->personalShop()->members()->attach($user, [
        'role' => ShopRole::Member->value,
    ]);

    $personalShop = Shop::factory()->personal()->create();
    $personalShop->members()->attach($user, [
        'role' => ShopRole::Owner->value,
    ]);

    expect($personalShop->is($user->personalShop()))->toBeTrue();
});

test('shop slug uses next available suffix', function () {
    $user = User::factory()->create();

    Shop::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Shop::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Shop::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this
        ->actingAs($user)
        ->post(route('shops.store'), [
            'name' => 'Acme',
        ]);

    $this->assertDatabaseHas('shops', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('the shop edit page can be rendered', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get(route('shops.edit', $shop));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shops/edit')
            ->where('members.0.role', ShopRole::Owner->value)
            ->where('members.0.role_label', ShopRole::Owner->label()),
        );
});

test('shops can be updated by owners', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create(['name' => 'Original Name']);

    $shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->patch(route('shops.update', $shop), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('shops.edit', $shop->fresh()));

    $this->assertDatabaseHas('shops', [
        'id' => $shop->id,
        'name' => 'Updated Name',
    ]);
});

test('shops cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->patch(route('shops.update', $shop), [
            'name' => 'Updated Name',
        ]);

    $response->assertForbidden();
});

test('shops can be deleted by owners', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.destroy', $shop), [
            'name' => $shop->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('shops', [
        'id' => $shop->id,
    ]);
});

test('shop deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.destroy', $shop), [
            'name' => 'Wrong Name',
        ]);

    $response->assertSessionHasErrors('name');

    $this->assertDatabaseHas('shops', [
        'id' => $shop->id,
        'deleted_at' => null,
    ]);
});

test('deleting current shop switches to alphabetically first remaining shop', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zuluShop = Shop::factory()->create(['name' => 'Zulu Shop']);
    $zuluShop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $alphaShop = Shop::factory()->create(['name' => 'Alpha Shop']);
    $alphaShop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $betaShop = Shop::factory()->create(['name' => 'Beta Shop']);
    $betaShop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $user->update(['current_shop_id' => $zuluShop->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.destroy', $zuluShop), [
            'name' => $zuluShop->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('shops', [
        'id' => $zuluShop->id,
    ]);

    expect($user->fresh()->current_shop_id)->toEqual($alphaShop->id);
});

test('deleting current shop falls back to personal shop when alphabetically first', function () {
    $user = User::factory()->create();
    $personalShop = $user->personalShop();
    $shop = Shop::factory()->create(['name' => 'Zulu Shop']);
    $shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $user->update(['current_shop_id' => $shop->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.destroy', $shop), [
            'name' => $shop->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('shops', [
        'id' => $shop->id,
    ]);

    expect($user->fresh()->current_shop_id)->toEqual($personalShop->id);
});

test('deleting non current shop leaves current shop unchanged', function () {
    $user = User::factory()->create();
    $personalShop = $user->personalShop();
    $shop = Shop::factory()->create();
    $shop->members()->attach($user, ['role' => ShopRole::Owner->value]);

    $user->update(['current_shop_id' => $personalShop->id]);

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.destroy', $shop), [
            'name' => $shop->name,
        ]);

    $response->assertRedirect();

    $this->assertSoftDeleted('shops', [
        'id' => $shop->id,
    ]);

    expect($user->fresh()->current_shop_id)->toEqual($personalShop->id);
});

test('members can leave non personal shops', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('shops.leave', $shop));

    $response->assertRedirect(route('shops.index'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => "You left the shop \"{$shop->name}\""]);

    expect($member->fresh()->belongsToShop($shop))->toBeFalse();
});

test('leaving current shop switches to alphabetically first remaining shop', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Mike']);

    $zuluShop = Shop::factory()->create(['name' => 'Zulu Shop']);
    $zuluShop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $zuluShop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $alphaShop = Shop::factory()->create(['name' => 'Alpha Shop']);
    $alphaShop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $betaShop = Shop::factory()->create(['name' => 'Beta Shop']);
    $betaShop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $member->update(['current_shop_id' => $zuluShop->id]);

    $response = $this
        ->actingAs($member)
        ->delete(route('shops.leave', $zuluShop));

    $response->assertRedirect(route('shops.index'));

    expect($member->fresh()->belongsToShop($zuluShop))->toBeFalse();
    expect($member->fresh()->current_shop_id)->toEqual($alphaShop->id);
});

test('personal shops cannot be left', function () {
    $user = User::factory()->create();
    $personalShop = $user->personalShop();

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.leave', $personalShop));

    $response->assertForbidden();

    expect($user->fresh()->belongsToShop($personalShop))->toBeTrue();
});

test('shop owners cannot leave their shop', function () {
    $owner = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('shops.leave', $shop));

    $response->assertForbidden();

    expect($owner->fresh()->belongsToShop($shop))->toBeTrue();
});

test('users cannot leave shops they dont belong to', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.leave', $shop));

    $response->assertForbidden();
});

test('deleting shop switches other affected users to their personal shop', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $shop = Shop::factory()->create();
    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $owner->update(['current_shop_id' => $shop->id]);
    $member->update(['current_shop_id' => $shop->id]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('shops.destroy', $shop), [
            'name' => $shop->name,
        ]);

    $response->assertRedirect();

    expect($member->fresh()->current_shop_id)->toEqual($member->personalShop()->id);
});

test('personal shops cannot be deleted', function () {
    $user = User::factory()->create();

    $personalShop = $user->personalShop();

    $response = $this
        ->actingAs($user)
        ->delete(route('shops.destroy', $personalShop), [
            'name' => $personalShop->name,
        ]);

    $response->assertForbidden();

    $this->assertDatabaseHas('shops', [
        'id' => $personalShop->id,
        'deleted_at' => null,
    ]);
});

test('shops cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->delete(route('shops.destroy', $shop), [
            'name' => $shop->name,
        ]);

    $response->assertForbidden();
});

test('users can switch shops', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($user, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($user)
        ->post(route('shops.switch', $shop));

    $response->assertRedirect();

    expect($user->fresh()->current_shop_id)->toEqual($shop->id);
});

test('users cannot switch to shop they dont belong to', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('shops.switch', $shop));

    $response->assertForbidden();
});

test('guests cannot access shops', function () {
    $response = $this->get(route('shops.index'));

    $response->assertRedirect(route('login'));
});
