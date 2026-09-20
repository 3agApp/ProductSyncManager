<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('the profile page shows the identity accounts holds, read only', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@3ag.local']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('auth.user.name', 'Ada Lovelace')
            ->where('auth.user.email', 'ada@3ag.local'),
        );
});

test('there is no route left to edit the profile with', function () {
    expect(Route::has('profile.update'))->toBeFalse();

    $this->actingAs(User::factory()->create())
        ->patch('/settings/profile', ['name' => 'Someone Else', 'email' => 'someone-else@3ag.local'])
        ->assertMethodNotAllowed();
});

test('the email address a user signs in with cannot be changed from here', function () {
    $user = User::factory()->create(['email' => 'ada@3ag.local']);

    $this->actingAs($user)
        ->post('/settings/profile', ['name' => 'Someone Else', 'email' => 'victim@3ag.local'])
        ->assertMethodNotAllowed();

    expect($user->fresh()->email)->toBe('ada@3ag.local');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'confirmation' => 'DELETE',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('the account is kept unless the confirmation is typed exactly', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'confirmation' => 'delete',
        ]);

    $response
        ->assertSessionHasErrors('confirmation')
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
        ->delete(route('profile.destroy'), ['confirmation' => 'DELETE'])
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
        ->delete(route('profile.destroy'), ['confirmation' => 'DELETE'])
        ->assertRedirect('/');

    expect($shop->fresh()->owner()?->id)->toBe($first->id);
});

test('deleting an account winds up a shop nobody else is in', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['confirmation' => 'DELETE'])
        ->assertRedirect('/');

    expect(Shop::withTrashed()->find($shop->id)->trashed())->toBeTrue();
});

test('deleting an account leaves shops it only belonged to alone', function () {
    $owner = User::factory()->create();
    $shop = $owner->currentShop;

    $member = User::factory()->create();
    $shop->memberships()->create(['user_id' => $member->id, 'role' => ShopRole::Member]);

    $this->actingAs($member)
        ->delete(route('profile.destroy'), ['confirmation' => 'DELETE'])
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
        ->delete(route('profile.destroy'), ['confirmation' => 'DELETE'])
        ->assertRedirect('/');

    // The shop is still there, and somebody can still administer it.
    expect($shop->fresh()->trashed())->toBeFalse()
        ->and($shop->fresh()->owner())->not->toBeNull();
});
