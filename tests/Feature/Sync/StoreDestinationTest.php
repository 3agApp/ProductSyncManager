<?php

use App\Enums\SyncRunStatus;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\User;
use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceDestinationException;
use App\Services\WooCommerce\Exceptions\WooCommerceRequestException;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
});

const BLOCKED_MESSAGE = 'The store address points to a private or reserved network, so it cannot be reached.';

function storeConnection(string $url = 'https://store.example.com'): WooCommerceConnection
{
    return WooCommerceConnection::factory()->create(['store_url' => $url]);
}

/**
 * Fake the store, keeping the request options each call was sent with.
 *
 * @param  array<int, array<string, mixed>>  $sent
 */
function fakeStoreRecordingOptions(array &$sent): void
{
    Http::fake(function ($request, array $options) use (&$sent) {
        $sent[] = $options;

        return Http::response([], 200, ['X-WP-Total' => '3']);
    });
}

test('a store address resolving to a non-public address is refused', function (array $addresses) {
    $this->resolveHostsTo($addresses);
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.connection.update', $user->currentShop), connectionData())
        ->assertSessionHasErrors(['store_url' => 'The store address must be a public address.']);

    expect($user->currentShop->wooCommerceConnection()->count())->toBe(0);
})->with([
    'loopback' => [['127.0.0.1']],
    'private network' => [['10.0.0.5']],
    'cloud metadata' => [['169.254.169.254']],
    'carrier-grade nat' => [['100.64.0.1']],
    'multicast' => [['224.0.0.1']],
    'ipv6 loopback' => [['::1']],
    'ipv6 unique local' => [['fd00::1']],
    'ipv4-mapped ipv6' => [['::ffff:127.0.0.1']],
    'nat64' => [['64:ff9b::a00:1']],
    'one private answer among public ones' => [['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c', '10.0.0.1']],
]);

test('private and loopback store addresses written directly are refused', function (string $url) {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('shops.sync.connection.update', $user->currentShop), connectionData(['store_url' => $url]))
        ->assertSessionHasErrors(['store_url' => 'The store address must be a public address.']);
})->with([
    'localhost' => ['https://localhost'],
    'private ipv4 literal' => ['https://10.0.0.1'],
    'ipv6 loopback literal' => ['https://[::1]'],
]);

test('every request is pinned to the addresses that were just checked', function (string $url, string $pin) {
    $this->resolveHostsTo(['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']);
    $sent = [];
    fakeStoreRecordingOptions($sent);

    WooCommerceClient::quick(storeConnection($url))->ping();

    expect($sent)->not->toBeEmpty();

    foreach ($sent as $options) {
        expect($options['curl'][CURLOPT_RESOLVE])->toBe([$pin]);
    }
})->with([
    'default port' => ['https://store.example.com', 'store.example.com:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'],
    'custom port' => ['https://store.example.com:8443', 'store.example.com:8443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]'],
]);

test('a public ip literal is contacted without a pin', function () {
    $sent = [];
    fakeStoreRecordingOptions($sent);

    WooCommerceClient::quick(storeConnection('https://93.184.215.14'))->ping();

    expect($sent)->not->toBeEmpty()
        ->and($sent[0])->not->toHaveKey('curl');
});

test('a saved store whose dns now answers privately is never contacted', function () {
    $connection = storeConnection();
    $this->resolveHostsTo(['10.0.0.1']);
    Http::fake();

    expect(fn () => WooCommerceClient::quick($connection)->ping())
        ->toThrow(WooCommerceDestinationException::class);

    Http::assertNothingSent();
});

test('a store host that does not resolve is never contacted', function () {
    $connection = storeConnection();
    $this->resolveHostsTo([]);
    Http::fake();

    expect(fn () => WooCommerceClient::quick($connection)->ping())
        ->toThrow(WooCommerceRequestException::class, 'could not be resolved');

    Http::assertNothingSent();
});

test('a sync to a blocked address fails the run without retrying', function () {
    $run = queuedRun();
    $this->resolveHostsTo(['169.254.169.254']);
    Http::fake();

    SyncWooCommerceProducts::dispatch($run);

    $run->refresh();

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->message)->toBe(BLOCKED_MESSAGE);

    Http::assertNothingSent();
});

test('a host that rebinds to a private address after validation is not contacted', function () {
    // Public while the form is validated, private by the time the client connects.
    $lookups = 0;
    $this->resolveHostsTo(function () use (&$lookups) {
        return ++$lookups === 1 ? ['93.184.215.14'] : ['127.0.0.1'];
    });
    Http::fake();

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('shops.sync.test', $user->currentShop), testConnectionData())
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => BLOCKED_MESSAGE]);

    expect($lookups)->toBe(2);

    Http::assertNothingSent();
});

test('private hosts are reachable when explicitly allowed', function () {
    config(['services.woocommerce.allow_private_hosts' => true]);
    $this->resolveHostsTo(['10.0.0.1']);
    $sent = [];
    fakeStoreRecordingOptions($sent);

    WooCommerceClient::quick(storeConnection())->ping();

    expect($sent)->not->toBeEmpty()
        ->and($sent[0])->not->toHaveKey('curl');
});
