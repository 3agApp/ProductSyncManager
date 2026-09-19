<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('the shared auth prop carries no secrets', function () {
    $user = User::factory()->create();

    // The two-factor columns outlived the feature that wrote them, so a user
    // who set 2FA up before single sign-on still has a secret in the row.
    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => 'encrypted-secret',
        'two_factor_recovery_codes' => 'encrypted-codes',
        'two_factor_confirmed_at' => now(),
    ]);

    $shared = $user->fresh()->toArray();

    expect($shared)->not->toHaveKeys([
        'password',
        'remember_token',
        'sso_id',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
    ]);
});

test('the dashboard does not hand the browser a two factor secret', function () {
    $user = User::factory()->create();

    DB::table('users')->where('id', $user->id)->update([
        'two_factor_secret' => 'encrypted-secret',
    ]);

    $this->actingAs($user->fresh())
        ->get(route('dashboard', ['current_shop' => $user->currentShop->slug]))
        ->assertOk()
        ->assertDontSee('encrypted-secret');
});
