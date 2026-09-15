<?php

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

test('a missing page renders the app error page', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 404),
        );
});

test('a shop the user does not belong to renders the forbidden page', function () {
    $user = User::factory()->create();
    $otherShop = Shop::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard', ['current_shop' => $otherShop->slug]))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 403),
        );
});

test('server errors render the error page when debug mode is off', function () {
    config(['app.debug' => false]);

    Route::get('/boom', fn () => throw new RuntimeException('Boom'))->middleware('web');

    $this->get('/boom')
        ->assertInternalServerError()
        ->assertInertia(fn (Assert $page) => $page
            ->component('error-page')
            ->where('status', 500),
        );
});

test('server errors keep the debug screen when debug mode is on', function () {
    config(['app.debug' => true]);

    Route::get('/boom', fn () => throw new RuntimeException('Boom'))->middleware('web');

    $this->get('/boom')
        ->assertInternalServerError()
        ->assertDontSee('"component":"error-page"', false);
});

test('json requests still get json errors', function () {
    $this->getJson('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('an expired session sends the user back with a message', function () {
    Route::post('/expired', fn () => abort(419))->middleware('web');

    $this->from('/settings/profile')
        ->post('/expired')
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});
