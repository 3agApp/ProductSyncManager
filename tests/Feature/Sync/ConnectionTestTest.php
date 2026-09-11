<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

/**
 * @return array<string, string>
 */
function testConnectionData(): array
{
    return [
        'store_url' => 'https://store.example.com',
        'consumer_key' => 'ck_1234567890abcdef',
        'consumer_secret' => 'cs_abcdef1234567890',
    ];
}

test('a working store reports how many products it has', function () {
    Http::fake(fn () => Http::response([], 200, ['X-WP-Total' => '1204']));

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.sync.test', $user->currentShop), testConnectionData())
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Connected. Found 1,204 importable products.',
        ]);
});

test('a successful test marks a stored connection verified', function () {
    Http::fake(fn () => Http::response([], 200, ['X-WP-Total' => '10']));

    $user = User::factory()->create();
    $shop = $user->currentShop;
    $connection = WooCommerceConnection::factory()->for($shop)->create();

    expect($connection->verified_at)->toBeNull();

    $this->actingAs($user)->post(route('shops.sync.test', $shop), testConnectionData());

    expect($connection->fresh()->verified_at)->not->toBeNull();
});

test('rejected credentials report a credential error', function () {
    Http::fake(fn () => Http::response(['message' => 'nope'], 401));

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.sync.test', $user->currentShop), testConnectionData())
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'WooCommerce rejected the API credentials. Check the consumer key and secret.',
        ]);
});

test('an unreachable store reports a connection error', function () {
    Http::fake(fn () => throw new ConnectionException('could not resolve host'));

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.sync.test', $user->currentShop), testConnectionData())
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'The WooCommerce store returned an unexpected response.',
        ]);
});

test('a stored secret is reused when the field is left blank', function () {
    Http::fake(fn () => Http::response([], 200, ['X-WP-Total' => '5']));

    $user = User::factory()->create();
    $shop = $user->currentShop;

    WooCommerceConnection::factory()->for($shop)->create([
        'consumer_key' => 'ck_stored',
        'consumer_secret' => 'cs_stored',
    ]);

    $this
        ->actingAs($user)
        ->post(route('shops.sync.test', $shop), [
            'store_url' => 'https://store.example.com',
            'consumer_key' => '',
            'consumer_secret' => '',
        ])
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Connected. Found 5 importable products.',
        ]);

    Http::assertSent(fn ($request) => $request->hasHeader(
        'Authorization',
        'Basic '.base64_encode('ck_stored:cs_stored'),
    ));
});

test('testing without any credentials asks for them', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.sync.test', $user->currentShop), [
            'store_url' => 'https://store.example.com',
        ])
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Enter a consumer key and secret first.',
        ]);
});

test('members cannot test the connection', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $this
        ->actingAs($member)
        ->post(route('shops.sync.test', $shop), testConnectionData())
        ->assertForbidden();
});
