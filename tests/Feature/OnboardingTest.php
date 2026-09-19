<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot visit onboarding', function () {
    $this->get(route('onboarding'))->assertRedirect(route('login'));
});

test('users without a shop see the onboarding page', function () {
    $user = User::factory()->withoutShop()->create();

    $this->actingAs($user)
        ->get(route('onboarding'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding')
            ->where('currentShop', null)
            ->where('pendingInvitationsCount', 0),
        );
});

test('onboarding shows how many invitations are waiting', function () {
    $owner = User::factory()->create();
    $user = User::factory()->withoutShop()->create(['email' => 'invited@example.com']);

    ShopInvitation::factory()->create([
        'shop_id' => $owner->currentShop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($user)
        ->get(route('onboarding'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding')
            ->where('pendingInvitationsCount', 1),
        );
});

test('users with a shop are sent from onboarding to their dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding'))
        ->assertRedirect(route('dashboard', ['current_shop' => $user->currentShop->slug]));
});

test('users whose current shop is unset are sent to a shop they belong to', function () {
    $user = User::factory()->withoutShop()->create();
    $shop = Shop::factory()->create();
    $shop->members()->attach($user, ['role' => ShopRole::Member->value]);

    $this->actingAs($user)
        ->get(route('onboarding'))
        ->assertRedirect(route('dashboard', ['current_shop' => $shop->slug]));
});

test('creating a first shop makes it the current shop', function () {
    $user = User::factory()->withoutShop()->create();

    $this->actingAs($user)
        ->post(route('shops.store'), ['name' => 'First Shop'])
        ->assertRedirect(route('shops.edit', ['shop' => 'first-shop']));

    $shop = Shop::where('name', 'First Shop')->firstOrFail();

    expect($user->fresh()->current_shop_id)->toBe($shop->id)
        ->and($user->fresh()->ownsShop($shop))->toBeTrue();
});

test('users without a shop are sent to onboarding after signing in', function () {
    $user = User::factory()->withoutShop()->create(['sso_id' => 'oidc-sub-onboarding']);

    signInThroughAccounts(['sub' => 'oidc-sub-onboarding', 'email' => $user->email]);

    $this->get(route('auth.accounts.callback', ['code' => 'code', 'state' => 'state']))
        ->assertRedirect(route('onboarding'));
});

test('declining the last invitation without a shop returns to onboarding', function () {
    $owner = User::factory()->create();
    $user = User::factory()->withoutShop()->create(['email' => 'invited@example.com']);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $owner->currentShop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($user)
        ->delete(route('invitations.decline', $invitation))
        ->assertRedirect(route('onboarding'));
});
