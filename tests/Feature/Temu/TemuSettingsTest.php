<?php

use App\Enums\ShopRole;
use App\Enums\SyncRunStatus;
use App\Enums\TemuDefaultBehavior;
use App\Enums\TemuListing;
use App\Enums\TemuOrderStatus;
use App\Enums\TemuProductStatus;
use App\Jobs\SyncTemuProducts;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\TemuConnection;
use App\Models\TemuOrder;
use App\Models\TemuProduct;
use App\Models\TemuSyncRun;
use App\Models\User;
use App\Models\WooCommerceConnection;
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
        'currency' => 'chf',
        'discount_percent' => '12.5',
        'sync_after_sync' => '1',
        'order_shipping' => '5.50',
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
            ->where('connection.discountPercent', 10)
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
        ->and($connection->currency)->toBe('CHF')
        ->and($connection->discount_percent)->toBe('12.50')
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
            'currency' => 'SWISS',
            'default_behavior' => 'sometimes',
            'discount_percent' => '95',
        ]))
        ->assertSessionHasErrors(['app_key', 'currency', 'default_behavior', 'discount_percent']);
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

test('a failed product can be retried from the temu page', function () {
    Bus::fake();

    [$user, $shop] = temuMember(ShopRole::Member);
    TemuConnection::factory()->for($shop)->create();

    $failed = TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create(['status' => TemuProductStatus::Failed, 'goods_id' => null]);
    $other = TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create(['status' => TemuProductStatus::Failed, 'goods_id' => null]);

    $this->actingAs($user)
        ->get(route('shops.temu.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page->where('problems.0.canRetry', true));

    $this->actingAs($user)
        ->post(route('shops.temu.retries.store', $shop), ['record' => $failed->id])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sending the product to Temu again.']);

    expect($failed->refresh()->status)->toBe(TemuProductStatus::Pending)
        ->and($other->refresh()->status)->toBe(TemuProductStatus::Failed);

    Bus::assertDispatched(SyncTemuProducts::class);
});

test('every failed product can be retried at once, even while a sync runs', function () {
    Bus::fake();

    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create();
    $shop->temuSyncRuns()->create(['trigger' => 'manual', 'status' => SyncRunStatus::Running]);

    $records = collect(range(1, 2))->map(fn () => TemuProduct::factory()
        ->forProduct(Product::factory()->for($shop)->create())
        ->create(['status' => TemuProductStatus::Failed, 'goods_id' => null]));
    $onTemu = TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create(['status' => TemuProductStatus::Rejected]);
    $elsewhere = TemuProduct::factory()->forProduct(Product::factory()->create())->create(['status' => TemuProductStatus::Failed, 'goods_id' => null]);

    $this->actingAs($user)
        ->post(route('shops.temu.retries.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 products will be sent again on the next sync.']);

    expect($records->map(fn (TemuProduct $record) => $record->refresh()->status)->unique()->all())->toBe([TemuProductStatus::Pending])
        ->and($onTemu->refresh()->status)->toBe(TemuProductStatus::Rejected)
        ->and($elsewhere->refresh()->status)->toBe(TemuProductStatus::Failed);

    Bus::assertNotDispatched(SyncTemuProducts::class);
});

test('a group of failed products can be retried together', function () {
    Bus::fake();

    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create();

    [$first, $second, $left] = collect(range(1, 3))->map(fn () => TemuProduct::factory()
        ->forProduct(Product::factory()->for($shop)->create())
        ->create(['status' => TemuProductStatus::Failed, 'goods_id' => null]))->all();
    $elsewhere = TemuProduct::factory()->forProduct(Product::factory()->create())->create(['status' => TemuProductStatus::Failed, 'goods_id' => null]);

    $this->actingAs($user)
        ->post(route('shops.temu.retries.store', $shop), ['records' => [$first->id, $second->id, $elsewhere->id]])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sending 2 products to Temu again.']);

    expect($first->refresh()->status)->toBe(TemuProductStatus::Pending)
        ->and($second->refresh()->status)->toBe(TemuProductStatus::Pending)
        ->and($left->refresh()->status)->toBe(TemuProductStatus::Failed)
        ->and($elsewhere->refresh()->status)->toBe(TemuProductStatus::Failed);
});

test('products needing attention are grouped by what fixes them', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create();

    $failed = fn (string $message) => TemuProduct::factory()
        ->forProduct(Product::factory()->for($shop)->create())
        ->create(['status' => TemuProductStatus::Failed, 'goods_id' => null, 'message' => $message]);

    $failed('No categories are recommended. Please complete product name and try again.');
    $failed('System error, please try again later');
    TemuProduct::factory()->forProduct(Product::factory()->for($shop)->create())->create([
        'status' => TemuProductStatus::Draft,
        'temu_sub_status' => 5001,
        'message' => null,
    ]);

    $this->actingAs($user)
        ->get(route('shops.temu.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('problemsTotal', 3)
            ->where('problems', fn ($problems) => collect($problems)
                ->mapWithKeys(fn ($problem) => [$problem['reason']['key'] => $problem['reason']['fix']])
                ->sortKeys()
                ->all() === ['draft' => 'seller_center', 'no-category' => 'title', 'temu-error' => 'retry']));
});

test('retrying with nothing failed says so', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    TemuConnection::factory()->for($shop)->create();

    $this->actingAs($user)
        ->post(route('shops.temu.retries.store', $shop))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'There is nothing to retry.']);
});

test('another shop cannot retry temu products', function () {
    $user = User::factory()->create();
    $shop = Shop::factory()->create();

    $this->actingAs($user)
        ->post(route('shops.temu.retries.store', $shop))
        ->assertForbidden();
});

test('a product can carry its own title on temu, which the store sync leaves alone', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();

    $this->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'temu_name' => 'Musselin Krone für Kinder – Rosa',
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->temu_name)->toBe('Musselin Krone für Kinder – Rosa');

    $this->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'temu_name' => '',
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->temu_name)->toBeNull();
});

test('changing a product categories counts as an edit', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    $product = Product::factory()->for($shop)->create();
    $before = $product->updated_at;

    $this->travel(1)->minute();

    $this->actingAs($user)
        ->patch(route('products.update', [$shop, $product]), [
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => $product->price,
            'stock' => $product->stock,
            'status' => $product->status->value,
            'categories' => ['Toys > Crowns'],
        ])
        ->assertSessionHasNoErrors();

    expect($product->refresh()->updated_at->gt($before))->toBeTrue();
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

test('order importing can be turned on, and imported orders are listed', function () {
    $user = User::factory()->create();
    $shop = $user->currentShop;
    WooCommerceConnection::factory()->verified()->for($shop)->create(['store_url' => 'https://toys.example']);

    $this->actingAs($user)
        ->patch(route('shops.temu.update', $shop), temuSettings(['import_orders' => '1', 'order_shipping' => '6']))
        ->assertSessionHasNoErrors();

    expect($shop->temuConnection->fresh()->import_orders)->toBeTrue()
        ->and($shop->temuConnection->fresh()->order_shipping)->toBe('6.00');

    TemuOrder::factory()->for($shop)->create(['parent_order_sn' => 'PO-192-1', 'woocommerce_order_id' => 9001]);
    TemuOrder::factory()->for($shop)->create([
        'parent_order_sn' => 'PO-192-2',
        'status' => TemuOrderStatus::Failed,
        'woocommerce_order_id' => null,
        'message' => 'WooCommerce responded with HTTP 400',
    ]);

    $this->actingAs($user)
        ->get(route('shops.temu.edit', $shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connection.importOrders', true)
            ->has('orders', 2)
            ->where('orders.0.parentOrderSn', 'PO-192-2')
            ->where('orders.0.needsAttention', true)
            ->where('orders.1.wooCommerceUrl', 'https://toys.example/wp-admin/post.php?post=9001&action=edit')
            ->where('orders.1.temuStatus', 'To ship'));
});
