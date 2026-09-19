<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('the columns fortify left behind are gone', function () {
    expect(Schema::hasTable('passkeys'))->toBeFalse();

    expect(Schema::hasColumn('users', 'two_factor_secret'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_recovery_codes'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeFalse();
});

test('the shared auth prop carries no secrets', function () {
    $user = User::factory()->create();

    expect($user->fresh()->toArray())->not->toHaveKeys([
        'password',
        'remember_token',
        'sso_id',
    ]);
});

test('the dashboard does not hand the browser the password hash', function () {
    $user = User::factory()->create();

    $user->forceFill(['sso_id' => 'oidc-sub-42'])->save();

    $this->actingAs($user->fresh())
        ->get(route('dashboard', ['current_shop' => $user->currentShop->slug]))
        ->assertOk()
        ->assertDontSee($user->fresh()->password)
        ->assertDontSee('oidc-sub-42');
});
