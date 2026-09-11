<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function connectionData(array $overrides = []): array
{
    return [
        'store_url' => 'https://store.example.com',
        'consumer_key' => 'ck_1234567890abcdef',
        'consumer_secret' => 'cs_abcdef1234567890',
        ...$overrides,
    ];
}

/**
 * Attach a user to a shop with the given role.
 */
function shopMemberWithRole(ShopRole $role): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($user, ['role' => $role->value]);

    return [$user, $shop];
}

test('an owner can save a woocommerce connection', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->from(route('shops.sync.edit', $shop))
        ->patch(route('shops.sync.connection.update', $shop), connectionData())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('shops.sync.edit', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connection saved.']);

    $connection = $shop->wooCommerceConnection()->sole();

    expect($connection->store_url)->toBe('https://store.example.com')
        ->and($connection->consumer_secret)->toBe('cs_abcdef1234567890')
        ->and($connection->secret_last_four)->toBe('7890');
});

test('credentials are encrypted at rest', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)->patch(route('shops.sync.connection.update', $shop), connectionData());

    $raw = DB::table('woocommerce_connections')->where('shop_id', $shop->id)->first();

    expect($raw->consumer_secret)->not->toBe('cs_abcdef1234567890')
        ->and($raw->consumer_key)->not->toBe('ck_1234567890abcdef')
        // Round tripping through the model still gives the plaintext back.
        ->and($shop->wooCommerceConnection()->sole()->consumer_secret)->toBe('cs_abcdef1234567890');
});

test('a blank secret keeps the stored one', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    WooCommerceConnection::factory()->for($shop)->create([
        'consumer_secret' => 'cs_original_secret',
    ]);

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.connection.update', $shop), connectionData([
            'consumer_key' => '',
            'consumer_secret' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect($shop->wooCommerceConnection()->sole()->consumer_secret)->toBe('cs_original_secret');
});

test('the secret is never sent to the browser', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    WooCommerceConnection::factory()->for($shop)->create([
        'consumer_secret' => 'cs_supersecretvalue',
        'secret_last_four' => 'alue',
    ]);

    $this
        ->actingAs($user)
        ->get(route('shops.sync.edit', $shop))
        ->assertOk()
        ->assertDontSee('cs_supersecretvalue')
        ->assertInertia(fn (Assert $page) => $page
            ->component('shops/sync')
            ->where('connection.consumerSecretPreview', 'cs_••••alue'));
});

test('connection data is validated', function (array $overrides, string $field) {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.connection.update', $shop), connectionData($overrides))
        ->assertSessionHasErrors($field);

    expect($shop->wooCommerceConnection()->count())->toBe(0);
})->with([
    'missing store url' => [['store_url' => ''], 'store_url'],
    'not a url' => [['store_url' => 'not a url'], 'store_url'],
    'unsupported scheme' => [['store_url' => 'ftp://store.example.com'], 'store_url'],
    'missing key' => [['consumer_key' => ''], 'consumer_key'],
    'missing secret' => [['consumer_secret' => ''], 'consumer_secret'],
]);

test('members cannot configure the connection', function () {
    [$member, $shop] = shopMemberWithRole(ShopRole::Member);

    $this
        ->actingAs($member)
        ->patch(route('shops.sync.connection.update', $shop), connectionData())
        ->assertForbidden();
});

test('admins can configure the connection', function () {
    [$admin, $shop] = shopMemberWithRole(ShopRole::Admin);

    $this
        ->actingAs($admin)
        ->patch(route('shops.sync.connection.update', $shop), connectionData())
        ->assertSessionHasNoErrors();

    expect($shop->wooCommerceConnection()->count())->toBe(1);
});

test('non members cannot reach another shops sync settings', function () {
    $outsider = User::factory()->create();
    $shop = Shop::factory()->create();

    $this->actingAs($outsider)->get(route('shops.sync.edit', $shop))->assertForbidden();
});

test('guests are redirected to login', function () {
    $shop = Shop::factory()->create();

    $this->get(route('shops.sync.edit', $shop))->assertRedirect(route('login'));
});
