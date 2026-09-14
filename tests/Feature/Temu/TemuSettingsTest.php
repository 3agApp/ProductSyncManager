<?php

use App\Enums\ShopRole;
use App\Enums\SyncRunStatus;
use App\Enums\TemuDefaultBehavior;
use App\Enums\TemuListing;
use App\Enums\TemuProductStatus;
use App\Jobs\SyncTemuProducts;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\TemuConnection;
use App\Models\TemuProduct;
use App\Models\TemuSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function temuSettings(array $overrides = []): array
{
    return [
        'app_key' => 'd210a2facb5d10b973e8356694f59bbe',
        'app_secret' => 'secret-for-tests',
        'access_token' => 'token-for-tests',
        'default_behavior' => 'exclude_all',
        'sync_after_sync' => '1',
        ...$overrides,
    ];
}

/**
 * @return array{0: User, 1: Shop}
 */
function temuMember(ShopRole $role): array
{
    $owner = User::factory()->create();
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($user, ['role' => $role->value]);

    return [$user, $shop];
}

test('the temu page starts with nothing listed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('shops.temu.edit', $user->currentShop))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shops/temu')
            ->where('connection.isConfigured', false)
            ->where('connection.defaultBehavior', 'exclude_all')
            ->where('connection.currency', 'CHF')
            ->where('connection.gateway', 'https://openapi-b-eu.temu.com/openapi/router')
            ->where('statusCounts', [])
            ->where('problems', [])
            ->has('behaviors', 2)
            ->where('currentRun', null));
});

test('an owner can save the temu settings, with the secret and token encrypted', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->from(route('shops.temu.edit', $shop))
        ->patch(route('shops.temu.update', $shop), temuSettings())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('shops.temu.edit', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Temu settings saved.']);

    $connection = $shop->temuConnection()->sole();
    $raw = DB::table('temu_connections')->where('shop_id', $shop->id)->first();

    expect($connection->app_secret)->toBe('secret-for-tests')
        ->and($connection->access_token)->toBe('token-for-tests')
        ->and($raw->app_secret)->not->toBe('secret-for-tests')
        ->and($raw->access_token)->not->toBe('token-for-tests')
        ->and($connection->currency())->toBe('CHF')
        ->and($connection->default_behavior)->toBe(TemuDefaultBehavior::ExcludeAll)
        ->and($connection->hasUsableCredentials())->toBeTrue();

    $this->get(route('shops.temu.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.appKey', 'd210a2facb5d10b973e8356694f59bbe')
            ->where('connection.hasSecret', true)
            ->where('connection.hasToken', true)
            ->where('connection.isUsable', true)
            ->missing('connection.appSecret')
            ->missing('connection.accessToken'));
});

test('blank secret and token fields keep what is stored', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    TemuConnection::factory()->for($shop)->create([
        'app_key' => 'd210a2facb5d10b973e8356694f59bbe',
        'app_secret' => 'original-secret',
        'access_token' => 'original-token',
        'verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $shop), temuSettings(['app_secret' => '', 'access_token' => '', 'default_behavior' => 'include_all']))
        ->assertSessionHasNoErrors();

    $connection = $shop->temuConnection()->sole();

    expect($connection->app_secret)->toBe('original-secret')
        ->and($connection->access_token)->toBe('original-token')
        ->and($connection->verified_at)->not->toBeNull()
        ->and($connection->default_behavior)->toBe(TemuDefaultBehavior::IncludeAll);
});

test('a new token clears the last successful test', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    TemuConnection::factory()->for($shop)->create([
        'app_key' => 'd210a2facb5d10b973e8356694f59bbe',
        'verified_at' => now(),
        'mall_id' => 42,
    ]);

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $shop), temuSettings(['app_secret' => '', 'access_token' => 'fresh-token']))
        ->assertSessionHasNoErrors();

    $connection = $shop->temuConnection()->sole();

    expect($connection->access_token)->toBe('fresh-token')
        ->and($connection->verified_at)->toBeNull()
        ->and($connection->mall_id)->toBeNull();
});

test('settings can be saved before the access token exists', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $shop), temuSettings(['access_token' => '']))
        ->assertSessionHasNoErrors();

    expect($shop->temuConnection()->sole()->hasUsableCredentials())->toBeFalse();
});

test('a new app key needs its secret', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $user->currentShop), temuSettings(['app_secret' => '']))
        ->assertSessionHasErrors('app_secret');
});

test('the settings are validated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $user->currentShop), temuSettings([
            'app_key' => 'not a key!',
            'default_behavior' => 'sometimes',
        ]))
        ->assertSessionHasErrors(['app_key', 'default_behavior']);
});

test('a member cannot change the temu settings or test the connection', function () {
    [$user, $shop] = temuMember(ShopRole::Member);
    TemuConnection::factory()->for($shop)->create();

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $shop), temuSettings())
        ->assertForbidden();

    $this->post(route('shops.temu.test', $shop))->assertForbidden();
});

test('another shop\'s temu page is not reachable', function () {
    $user = User::factory()->create();
    $otherShop = Shop::factory()->create();

    $this->actingAs($user)
        ->get(route('shops.temu.edit', $otherShop))
        ->assertForbidden();
});

test('testing the connection stores the store and token expiry', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $connection = TemuConnection::factory()->for($shop)->create();

    $expires = now()->addMonths(3)->startOfSecond();

    Http::fake(['*' => Http::response([
        'success' => true,
        'errorCode' => 1000000,
        'result' => ['mallId' => 634418212345, 'expiredTime' => $expires->getTimestamp()],
    ])]);

    $this->actingAs($user)
        ->post(route('shops.temu.test', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Connected to Temu.']);

    $connection->refresh();

    expect($connection->verified_at)->not->toBeNull()
        ->and($connection->mall_id)->toBe(634418212345)
        ->and($connection->token_expires_at->getTimestamp())->toBe($expires->getTimestamp());
});

test('a refused token is reported by the connection test', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create();

    Http::fake(['*' => Http::response(['success' => false, 'errorCode' => 7000016, 'errorMsg' => 'access_token is invalid'])]);

    $this->actingAs($user)
        ->post(route('shops.temu.test', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Temu rejected the app key, secret or access token. access_token is invalid']);

    expect($shop->temuConnection->refresh()->verified_at)->toBeNull();
});

test('testing without a token asks for one', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create(['access_token' => null]);

    Http::fake();

    $this->actingAs($user)
        ->post(route('shops.temu.test', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Save the app key, secret and access token first.']);

    Http::assertNothingSent();
});

test('sync now queues one run at a time', function () {
    Bus::fake();

    [$user, $shop] = temuMember(ShopRole::Member);
    TemuConnection::factory()->for($shop)->create();

    $this->actingAs($user)
        ->post(route('shops.temu.syncs.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Temu sync queued.']);

    Bus::assertDispatched(SyncTemuProducts::class);

    $this->post(route('shops.temu.syncs.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A Temu sync is already in progress.']);

    expect($shop->temuSyncRuns()->count())->toBe(1);
});

test('sync now needs credentials', function () {
    Bus::fake();

    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->withoutCredentials()->create();

    $this->actingAs($user)
        ->post(route('shops.temu.syncs.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Save the Temu app key, secret and access token before syncing.']);

    Bus::assertNothingDispatched();
});

test('the page counts products by status and lists the ones needing attention', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create();

    $failed = Product::factory()->for($shop)->create(['name' => 'Broken Kite']);
    TemuProduct::factory()->forProduct($failed)->create(['status' => TemuProductStatus::Failed, 'goods_id' => null, 'message' => 'Upload 3 to 10 images']);
    TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create();
    TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create();

    $this->actingAs($user)
        ->get(route('shops.temu.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('statusCounts', [
                ['status' => 'active', 'label' => 'Active', 'count' => 2],
                ['status' => 'failed', 'label' => 'Failed', 'count' => 1],
            ])
            ->has('problems', 1)
            ->where('problems.0.productName', 'Broken Kite')
            ->where('problems.0.message', 'Upload 3 to 10 images'));
});

test('a finished woocommerce sync queues a temu sync when the shop wants one', function () {
    Bus::fake([SyncTemuProducts::class]);

    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create(['sync_after_sync' => true]);

    $method = new ReflectionMethod(SyncWooCommerceProducts::class, 'syncToTemu');
    $run = SyncRun::factory()->for($shop)->create();

    $method->invoke(new SyncWooCommerceProducts($run), $shop);

    Bus::assertDispatched(SyncTemuProducts::class);
});

test('a stalled temu run is failed by the scheduler', function () {
    $shop = Shop::factory()->create();
    $run = TemuSyncRun::factory()->for($shop)->queued()->create();
    $run->forceFill(['created_at' => now()->subHours(3)])->save();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    expect($run->refresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($run->message)->toBe('The Temu sync timed out.');
});

test('the scheduler checks on products temu is still processing', function () {
    Bus::fake([SyncTemuProducts::class]);

    $shop = Shop::factory()->create();
    TemuConnection::factory()->for($shop)->create();
    TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create([
        'status' => TemuProductStatus::Processing,
        'checked_at' => now()->subMinutes(20),
    ]);

    $settled = Shop::factory()->create();
    TemuConnection::factory()->for($settled)->create();
    TemuProduct::factory()->forProduct(Product::factory()->for($settled)->create())->create();

    $this->artisan('sync:dispatch-due')->assertSuccessful();

    Bus::assertDispatchedTimes(SyncTemuProducts::class, 1);

    expect($shop->temuSyncRuns()->count())->toBe(1)
        ->and($settled->temuSyncRuns()->count())->toBe(0);
});

test('a product can be set to always include or exclude on temu', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    expect($product->refresh()->temu_listing)->toBe(TemuListing::Default);

    $this->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'temu_listing' => 'include',
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->temu_listing)->toBe(TemuListing::Include);
});

test('products can be included on temu in bulk', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $products = Product::factory()->for($shop)->count(2)->create();

    $this->actingAs($user)
        ->post(route('products.bulk', $shop), [
            'action' => 'set-temu-listing',
            'ids' => $products->pluck('id')->all(),
            'temu_listing' => 'include',
        ])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 products updated for Temu.']);

    expect(Product::query()->where('temu_listing', 'include')->count())->toBe(2);
});
