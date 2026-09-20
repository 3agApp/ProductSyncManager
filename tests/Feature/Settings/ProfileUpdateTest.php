<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
test('deleting an account hands its shops to the longest standing admin', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $newestAdmin = User::factory()->create();
    $oldestAdmin = User::factory()->create();
    $member = User::factory()->create();

    // Created out of order, so the test proves it picks by standing rather
    // than by whichever row happens to come back first.
    $shop->memberships()->create(['user_id' => $member->id, 'role' => ShopRole::Member]);
    $shop->memberships()->create(['user_id' => $newestAdmin->id, 'role' => ShopRole::Admin])
        ->forceFill(['created_at' => now()->addDay()])->save();
    $shop->memberships()->create(['user_id' => $oldestAdmin->id, 'role' => ShopRole::Admin])
        ->forceFill(['created_at' => now()->subDay()])->save();

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect($shop->fresh()->owner()?->id)->toBe($oldestAdmin->id)
        ->and($oldestAdmin->fresh()->ownsShop($shop))->toBeTrue();
});

test('deleting an account falls back to the longest standing member', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $first = User::factory()->create();
    $second = User::factory()->create();

    $shop->memberships()->create(['user_id' => $second->id, 'role' => ShopRole::Member])
        ->forceFill(['created_at' => now()->addDay()])->save();
    $shop->memberships()->create(['user_id' => $first->id, 'role' => ShopRole::Member])
        ->forceFill(['created_at' => now()->subDay()])->save();

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect($shop->fresh()->owner()?->id)->toBe($first->id);
});

test('deleting an account winds up a shop nobody else is in', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect(Shop::withTrashed()->find($shop->id)->trashed())->toBeTrue();
});

test('deleting an account leaves shops it only belonged to alone', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $member = User::factory()->create();
    $shop->memberships()->create(['user_id' => $member->id, 'role' => ShopRole::Member]);

    $this->actingAs($member)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    expect($shop->fresh()->owner()?->id)->toBe($owner->id)
        ->and($shop->fresh()->members()->count())->toBe(1);
});

test('no shop is left without an owner when its owner goes', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $member = User::factory()->create();
    $shop->memberships()->create(['user_id' => $member->id, 'role' => ShopRole::Member]);

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    // The shop is still there, and somebody can still administer it.
    expect($shop->fresh()->trashed())->toBeFalse()
        ->and($shop->fresh()->owner())->not->toBeNull();
});
