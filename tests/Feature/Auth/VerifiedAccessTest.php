<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('an unverified user is sent to the verification prompt', function (Closure $route) {
    $user = User::factory()->unverified()->create();

    $this
        ->actingAs($user)
        ->get($route($user))
        ->assertRedirect(route('verification.notice'));
})->with([
    // The user's own shop, so this is the verification check answering
    // and not the membership check behind it.
    'dashboard' => [fn (User $user) => route('dashboard', $user->currentShop)],
    'products' => [fn (User $user) => route('products.index', $user->currentShop)],
    'onboarding' => [fn () => route('onboarding')],
    'invitations' => [fn () => route('invitations.index')],
    'appearance settings' => [fn () => route('appearance.edit')],
    'shop list' => [fn () => route('shops.index')],
    'bare dashboard path' => [fn () => '/dashboard'],
]);

test('an unverified user can still reach the profile page to correct their address', function () {
    $user = User::factory()->unverified()->create();

    $this
        ->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk();
});

test('a verified user reaches their own dashboard', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard', $user->currentShop))
        ->assertOk();
});

test('registering sends the verification mail and leaves the account unverified', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Rita Hasler',
        'email' => 'rita@example.com',
        'password' => 'password-that-is-long-enough',
        'password_confirmation' => 'password-that-is-long-enough',
    ]);

    $user = User::whereEmail('rita@example.com')->sole();

    expect($user->hasVerifiedEmail())->toBeFalse();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('the bare dashboard path lands on the current shop', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect("/{$user->currentShop->slug}/dashboard");
});

test('the bare dashboard path sends a user with no shop to onboarding', function () {
    $user = User::factory()->withoutShop()->create();

    $this
        ->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('onboarding', absolute: false));
});
