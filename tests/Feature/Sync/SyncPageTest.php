<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ShopSyncSetting;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('the sync page exposes everything it needs', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    WooCommerceConnection::factory()->for($shop)->create();

    $this
        ->actingAs($user)
        ->get(route('shops.sync.edit', $shop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shops/sync')
            ->where('supportsSync', true)
            ->has('connection')
            ->has('settings')
            ->has('recentRuns')
            ->has('frequencies')
            ->has('timezones')
            ->has('permissions')
            ->where('currentRun', null));
});

test('the schedule falls back to defaults before it is configured', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('shops.sync.edit', $user->currentShop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.isConfigured', false)
            ->where('settings.isEnabled', false)
            ->where('settings.frequency', 'daily')
            ->where('settings.timeOfDay', '02:00')
            ->where('connection', null));
});

test('a running sync is reported as the current run', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    SyncRun::factory()->for($shop)->running()->create();

    $this
        ->actingAs($user)
        ->get(route('shops.sync.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentRun.status', 'running')
            ->where('currentRun.isActive', true)
            ->where('currentRun.progress', 40));
});

test('a poll only returns the props it asked for', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    SyncRun::factory()->for($shop)->running()->create();

    $this
        ->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            // The version has to match or Inertia answers with a 409 instead.
            'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(
                Request::create(route('shops.sync.edit', $shop)),
            ),
            'X-Inertia-Partial-Component' => 'shops/sync',
            'X-Inertia-Partial-Data' => 'currentRun',
        ])
        ->get(route('shops.sync.edit', $shop))
        ->assertOk()
        ->assertJsonPath('props.currentRun.status', 'running')
        ->assertJsonMissingPath('props.recentRuns')
        ->assertJsonMissingPath('props.timezones');
});

test('an existing schedule exposes its saved timezone instead of browser defaults', function () {
    $user = User::factory()->create();
    ShopSyncSetting::factory()->for($user->currentShop)->create(['timezone' => 'Europe/London']);

    $this->actingAs($user)->get(route('shops.sync.edit', $user->currentShop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.isConfigured', true)
            ->where('settings.timezone', 'Europe/London'));
});
