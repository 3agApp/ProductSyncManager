<?php

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
