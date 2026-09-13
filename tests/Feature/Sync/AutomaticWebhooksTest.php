<?php

use App\Actions\Sync\ConfigureWooCommerceWebhooks;
use App\Actions\Sync\SaveWooCommerceConnection;
use App\Enums\ShopRole;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    config(['app.url' => 'https://productsync.example.com']);
    $this->owner = User::factory()->create();
    $this->shop = $this->owner->currentShop;
    $this->connection = WooCommerceConnection::factory()->for($this->shop)->create();
    $this->actingAs($this->owner);
});

function setupProductWebhooks($test)
{
    return $test->from(route('shops.sync.edit', $test->shop))
        ->post(route('shops.sync.webhooks.store', $test->shop));
}

/** @return array<string, mixed> */
function remoteWebhookRecord(int $id, string $topic, string $url): array
{
    return ['id' => $id, 'topic' => $topic, 'delivery_url' => $url, 'status' => 'active'];
}

test('one click creates all four active webhooks with a persisted secret and trusted delivery URL', function () {
    $id = 0;
    Http::fake(function (Request $request) use (&$id) {
        if ($request->method() === 'GET') {
            return Http::response([]);
        }
        expect($request->method())->toBe('POST')
            ->and($request['secret'])->toBe($this->connection->fresh()->webhook_secret)
            ->and($request['api_version'])->toBe('wp_api_v3')
            ->and($request['delivery_url'])->toBe($this->connection->webhookDeliveryUrl());

        return Http::response(['id' => ++$id, ...$request->data()], 201);
    });
    setupProductWebhooks($this)->assertSessionHasNoErrors()->assertInertiaFlash('toast', [
        'type' => 'success', 'message' => 'All four product webhooks are configured and active in WooCommerce.',
    ]);
    $connection = $this->connection->fresh();
    expect($connection->webhook_setup)->toHaveCount(4)
        ->and($connection->webhooks_configured_at)->not->toBeNull()
        ->and(strlen($connection->webhook_secret))->toBe(64)
        ->and($connection->getRawOriginal('webhook_secret'))->not->toBe($connection->webhook_secret);
    Http::assertSentCount(5);
});

test('read-only key errors are reported after attempting setup', function () {
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response([])
        : Http::response(['code' => 'woocommerce_rest_authentication_error', 'message' => 'The API key provided does not have write permissions.'], 401));
    setupProductWebhooks($this)->assertSessionHasErrors(['webhooks' => 'This API key does not have permission to manage webhooks. Save a Read/Write key belonging to a WooCommerce user who can manage webhooks, then try again.']);
    Http::assertSentCount(2);
    expect($this->connection->fresh()->webhooks_configured_at)->toBeNull();
});

test('permission and credential errors have actionable messages', function (int $status, string $code, string $expected) {
    Http::fake(fn () => Http::response(['code' => $code], $status));
    setupProductWebhooks($this)->assertSessionHasErrors(['webhooks' => $expected]);
    Http::assertSentCount(1);
})->with([
    [403, 'woocommerce_rest_cannot_view', 'This API key does not have permission to manage webhooks. Save a Read/Write key belonging to a WooCommerce user who can manage webhooks, then try again.'],
    [401, 'woocommerce_rest_authentication_error', 'WooCommerce rejected the API credentials. Check the consumer key and secret.'],
    [429, 'rate_limit', 'WooCommerce is limiting requests. Wait a moment, then try setup again.'],
]);

test('repeated setup reuses existing hooks including disabled ones and leaves unrelated destinations alone', function () {
    $remote = [];
    foreach (ConfigureWooCommerceWebhooks::TOPICS as $index => $topic) {
        $remote[] = [...remoteWebhookRecord($index + 1, $topic, $this->connection->webhookDeliveryUrl()), 'status' => 'disabled'];
    }
    $remote[] = remoteWebhookRecord(99, 'product.updated', 'https://unrelated.example.com/hook');
    $this->connection->update(['webhook_secret' => str_repeat('s', 40)]);
    Http::fake(function (Request $request) use ($remote) {
        if ($request->method() === 'GET') {
            return Http::response($remote);
        }
        expect($request->method())->toBe('PUT')->and($request['secret'])->toBe(str_repeat('s', 40));
        $id = (int) basename(parse_url($request->url(), PHP_URL_PATH));
        expect($id)->not->toBe(99);

        return Http::response(['id' => $id, ...$request->data()]);
    });
    setupProductWebhooks($this)->assertSessionHasNoErrors();
    setupProductWebhooks($this)->assertSessionHasNoErrors();
    Http::assertSentCount(10);
});

test('a timed out create is not blindly retried and the next click discovers it', function () {
    $remote = [];
    $timeout = true;
    Http::fake(function (Request $request) use (&$remote, &$timeout) {
        if ($request->method() === 'GET') {
            return Http::response(array_values($remote));
        }
        $id = $request->method() === 'POST' ? count($remote) + 1 : (int) basename(parse_url($request->url(), PHP_URL_PATH));
        $remote[$id] = ['id' => $id, ...$request->data()];
        if ($timeout) {
            $timeout = false;
            throw new ConnectionException('response lost');
        }

        return Http::response($remote[$id]);
    });
    setupProductWebhooks($this)->assertSessionHasErrors('webhooks');
    Http::assertSentCount(1); // Laravel does not record a fake that throws.
    expect($remote)->toHaveCount(1);
    setupProductWebhooks($this)->assertSessionHasNoErrors();
    expect($remote)->toHaveCount(4)->and($this->connection->fresh()->webhook_setup)->toHaveCount(4);
});

test('completed hooks are kept when a later create fails', function () {
    $writes = 0;
    Http::fake(function (Request $request) use (&$writes) {
        if ($request->method() === 'GET') {
            return Http::response([]);
        }
        if (++$writes === 2) {
            return Http::response(['message' => 'private diagnostic secret'], 500);
        }

        return Http::response(['id' => 1, ...$request->data()], 201);
    });
    setupProductWebhooks($this)->assertSessionHasErrors('webhooks');
    expect($writes)->toBe(2)
        ->and($this->connection->fresh()->webhook_setup)->toHaveCount(1)
        ->and($this->connection->fresh()->webhooks_configured_at)->toBeNull();
});

test('tracked webhooks can be repaired after the public app URL changes', function () {
    $remote = [];
    $tracked = [];
    foreach (ConfigureWooCommerceWebhooks::TOPICS as $index => $topic) {
        $url = 'https://old.example.com/webhook';
        $remote[] = remoteWebhookRecord($index + 1, $topic, $url);
        $tracked[$topic] = ['id' => $index + 1, 'delivery_url' => $url];
    }
    $this->connection->update(['webhook_setup' => $tracked]);
    Http::fake(fn (Request $request) => $request->method() === 'GET' ? Http::response($remote)
        : Http::response(['id' => (int) basename(parse_url($request->url(), PHP_URL_PATH)), ...$request->data()]));
    setupProductWebhooks($this)->assertSessionHasNoErrors();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('malformed or incomplete lists do not cause duplicate creations', function (mixed $body, array $headers) {
    Http::fake(fn () => Http::response($body, 200, $headers));
    setupProductWebhooks($this)->assertSessionHasErrors('webhooks');
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->with([
    ['not json', []],
    [[], ['X-WP-Total' => '2', 'X-WP-TotalPages' => '1']],
    [[['id' => 1]], []],
]);

test('members and outsiders cannot start automatic setup', function (bool $member) {
    $user = User::factory()->create();
    if ($member) {
        $this->shop->members()->attach($user, ['role' => ShopRole::Member->value]);
    }
    $this->actingAs($user);
    setupProductWebhooks($this)->assertForbidden();
    Http::assertNothingSent();
})->with([true, false]);

test('the setup lock prevents simultaneous creation and credential changes', function () {
    $lock = Cache::lock('woocommerce-connection:'.$this->shop->id, 600);
    $lock->get();
    try {
        setupProductWebhooks($this)->assertSessionHasErrors('webhooks');
        expect(fn () => app(SaveWooCommerceConnection::class)->handle($this->shop, ['store_url' => 'https://changed.example.com']))->toThrow(ValidationException::class);
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('changing stores clears webhook ownership and setup status', function () {
    $this->connection->update(['webhook_setup' => ['product.created' => ['id' => 1, 'delivery_url' => 'https://old.example.com']], 'webhooks_configured_at' => now()]);
    $saved = app(SaveWooCommerceConnection::class)->handle($this->shop, ['store_url' => 'https://new-store.example.com']);
    expect($saved->webhook_setup)->toBeNull()->and($saved->webhooks_configured_at)->toBeNull();
});

test('non-public callback configuration is reported on click without contacting WooCommerce', function () {
    config(['app.url' => 'http://localhost']);
    setupProductWebhooks($this)->assertSessionHasErrors('webhooks');
    Http::assertNothingSent();
});

test('pagination discovers existing hooks beyond the first page before creating any', function () {
    $first = array_map(fn ($id) => remoteWebhookRecord($id, 'order.updated', 'https://other.example.com'), range(1, 100));
    $last = array_map(fn ($index, $topic) => remoteWebhookRecord($index + 101, $topic, $this->connection->webhookDeliveryUrl()), array_keys(ConfigureWooCommerceWebhooks::TOPICS), ConfigureWooCommerceWebhooks::TOPICS);
    Http::fake(function (Request $request) use ($first, $last) {
        if ($request->method() === 'GET') {
            return Http::response($request['page'] === 1 ? $first : $last, 200, ['X-WP-Total' => '104', 'X-WP-TotalPages' => '2']);
        }

        return Http::response(['id' => (int) basename(parse_url($request->url(), PHP_URL_PATH)), ...$request->data()]);
    });
    setupProductWebhooks($this)->assertSessionHasNoErrors();
    Http::assertSentCount(6);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
});

test('webhooks retargeted by another integration are left untouched', function () {
    $this->connection->update(['webhook_setup' => ['product.created' => ['id' => 1, 'delivery_url' => 'https://previous.example.com']]]);
    $remote = [remoteWebhookRecord(1, 'product.created', 'https://unrelated.example.com')];
    $id = 1;
    Http::fake(function (Request $request) use ($remote, &$id) {
        if ($request->method() === 'GET') {
            return Http::response($remote);
        }

        return Http::response(['id' => ++$id, ...$request->data()], 201);
    });
    setupProductWebhooks($this)->assertSessionHasNoErrors();
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});
