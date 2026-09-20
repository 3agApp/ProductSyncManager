<?php

use App\Models\User;

test('a signed in visitor to a guest page is sent to their dashboard', function (string $route) {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route($route))
        ->assertRedirect(route('dashboard.redirect'));
})->with(['login', 'register', 'password.request']);

test('a signed in visitor with no shop is sent to onboarding rather than erroring', function (string $route) {
    // route('dashboard') needs a current_shop, so Laravel's default guest
    // redirect raised a missing parameter error for anyone without one.
    $user = User::factory()->withoutShop()->create();

    $this
        ->actingAs($user)
        ->get(route($route))
        ->assertRedirect(route('dashboard.redirect'));

    $this
        ->actingAs($user)
        ->get(route('dashboard.redirect'))
        ->assertRedirect(route('onboarding', absolute: false));
})->with(['login', 'register', 'password.request']);

test('an unverified visitor to a guest page ends up at the verification prompt', function () {
    $user = User::factory()->unverified()->create();

    $this
        ->actingAs($user)
        ->get(route('register'))
        ->assertRedirect(route('dashboard.redirect'));

    $this
        ->actingAs($user)
        ->get(route('dashboard.redirect'))
        ->assertRedirect(route('verification.notice'));
});
