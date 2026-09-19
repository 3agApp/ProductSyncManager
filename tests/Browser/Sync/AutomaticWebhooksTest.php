<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Pest\Browser\Api\AwaitableWebpage;

/**
 * What the page shows after automatic webhook setup runs.
 *
 * The rules behind setup, including the four webhooks it creates and its
 * idempotency, are covered by tests/Feature/Sync/AutomaticWebhooksTest.php.
 * This only covers what reaches the screen.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    // Setup backs off between WooCommerce calls; without this the request
    // outlives the browser's patience.
    Sleep::fake();

    // The store is never really called. Reading the webhook list always
    // works; only writing is refused, and only for the read-only key, which
    // is how WooCommerce reports a key without write permission.
    $id = 0;
    Http::fake(function (Request $request) use (&$id) {
        if ($request->method() === 'GET') {
            return Http::response([]);
        }

        $credentials = base64_decode(str_replace('Basic ', '', $request->header('Authorization')[0] ?? ''));

        if (str_starts_with($credentials, 'ck_readonly:')) {
            return Http::response([
                'code' => 'woocommerce_rest_authentication_error',
                'message' => 'The API key provided does not have write permissions.',
            ], 401);
        }

        return Http::response(['id' => ++$id, ...$request->data()], 201);
    });

    $this->actingAs($this->user);
});

/**
 * Give the app a public address for the webhooks to be delivered to.
 *
 * The test server sets app.url to its own loopback address when it boots, and
 * automatic setup refuses to hand WooCommerce an address it could not reach,
 * so this has to be applied once the server is already up.
 */
function useAPublicAppUrl(): void
{
    config(['app.url' => 'https://productsync.example.com']);
}

/**
 * Wait for automatic webhook setup to report its result.
 *
 * Setup is an async request, so the result only reaches the page once it
 * answers. This waits for the wording it produces rather than for a fixed
 * delay, so a slower machine does not fail the test.
 */
function waitForWebhookSetup(AwaitableWebpage $page, string $reports): AwaitableWebpage
{
    $settled = $page->script(<<<JS
        new Promise((resolve) => {
            const found = () => document.body.innerText.includes({$reports});
            if (found()) {
                return resolve(true);
            }
            const timer = setInterval(() => {
                if (found()) {
                    clearInterval(timer);
                    resolve(true);
                }
            }, 50);
            setTimeout(() => {
                clearInterval(timer);
                resolve(false);
            }, 15000);
        })
    JS);

    expect($settled)->toBeTrue("webhook setup never reported [{$reports}]");

    return $page;
}

it('reports that a read-only key cannot manage webhooks, then succeeds with a read/write key', function () {
    $page = visit(route('shops.sync.edit', $this->shop));

    useAPublicAppUrl();

    $page = $page->fill('@store-url-input', 'https://store.example.com')
        ->fill('@consumer-key-input', 'ck_readonly')
        ->fill('@consumer-secret-input', 'cs_test')
        ->click('@connection-save')
        ->assertEnabled('@webhook-setup-button')
        ->assertMissing('@webhook-setup-error')
        ->click('@webhook-setup-button');

    waitForWebhookSetup($page, json_encode('does not have permission to manage webhooks'))
        ->assertSeeIn('@webhook-setup-error', 'This API key does not have permission to manage webhooks.')
        ->assertSeeIn('@webhook-setup-error', 'Read/Write');

    $page->fill('@consumer-key-input', 'ck_write')
        ->click('@connection-save')
        ->assertEnabled('@webhook-setup-button')
        ->click('@webhook-setup-button');

    waitForWebhookSetup($page, json_encode('Product webhooks were configured'))
        ->assertPresent('@webhook-setup-success')
        ->assertNoJavaScriptErrors();

    expect($this->shop->wooCommerceConnection->fresh()->webhook_setup)->toHaveCount(4);
});

it('fits the webhook section on desktop and mobile without scrolling sideways', function (int $width) {
    $page = visit(route('shops.sync.edit', $this->shop))->resize($width, 1000);

    useAPublicAppUrl();

    $page->fill('@store-url-input', 'https://store.example.com')
        ->fill('@consumer-key-input', 'ck_write')
        ->fill('@consumer-secret-input', 'cs_test')
        ->click('@connection-save')
        ->assertEnabled('@webhook-setup-button')
        ->click('@webhook-setup-button');

    waitForWebhookSetup($page, json_encode('Product webhooks were configured'))->assertPresent('@webhook-setup-success');

    expect($page->script('document.documentElement.scrollWidth > window.innerWidth'))
        ->toBeFalse("the page scrolls sideways at {$width}px");

    $page->assertNoJavaScriptErrors();
})->with([1280, 390]);
