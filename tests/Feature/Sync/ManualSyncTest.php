<?php

use App\Enums\ShopRole;
use App\Enums\SyncTrigger;
use App\Jobs\SyncWooCommerceProducts;
use App\Models\Shop;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WooCommerceConnection;
use Illuminate\Support\Facades\Queue;

/**
 * Create a connected shop whose members hold the given roles.
 *
 * @param  array<string, ShopRole>  $roles
 * @return array{0: Shop, 1: array<string, User>}
 */
function connectedShopWithRoles(array $roles): array
{
    $shop = Shop::factory()->create();
    WooCommerceConnection::factory()->for($shop)->create();

    $users = [];

    foreach ($roles as $key => $role) {
        $user = User::factory()->create();
        $shop->members()->attach($user, ['role' => $role->value]);
        $users[$key] = $user;
    }

    return [$shop, $users];
}

test('shop members of every role can start a sync', function (ShopRole $role) {
    Queue::fake();

    [$shop, $users] = connectedShopWithRoles(['actor' => $role]);

    $this
        ->actingAs($users['actor'])
        ->from(route('shops.sync.edit', $shop))
        ->post(route('shops.sync.runs.store', $shop))
        ->assertRedirect(route('shops.sync.edit', $shop))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Sync started.']);

    Queue::assertPushed(SyncWooCommerceProducts::class);

    expect($shop->syncRuns()->sole()->trigger)->toBe(SyncTrigger::Manual)
        ->and($shop->syncRuns()->sole()->triggered_by)->toBe($users['actor']->id);
})->with([
    'owner' => [ShopRole::Owner],
    'admin' => [ShopRole::Admin],
    'member' => [ShopRole::Member],
]);

test('a second sync is refused while one is running', function () {
    Queue::fake();

    [$shop, $users] = connectedShopWithRoles(['owner' => ShopRole::Owner]);
    SyncRun::factory()->for($shop)->running()->create();

    $this
        ->actingAs($users['owner'])
        ->post(route('shops.sync.runs.store', $shop))
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => 'A sync is already running for this shop.',
        ]);

    Queue::assertNothingPushed();

    expect($shop->syncRuns()->count())->toBe(1);
});

test('a shop without a connection cannot sync', function () {
    Queue::fake();

    $user = User::factory()->create();
    $shop = $user->currentShop;

    $this
        ->actingAs($user)
        ->post(route('shops.sync.runs.store', $shop))
        ->assertInertiaFlash('toast', [
            'type' => 'error',
            'message' => 'Connect this shop to WooCommerce before syncing.',
        ]);

    Queue::assertNothingPushed();

    expect($shop->syncRuns()->count())->toBe(0);
});

test('non members cannot start a sync', function () {
    Queue::fake();

    $outsider = User::factory()->create();
    [$shop] = connectedShopWithRoles([]);

    $this
        ->actingAs($outsider)
        ->post(route('shops.sync.runs.store', $shop))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('guests cannot start a sync', function () {
    [$shop] = connectedShopWithRoles([]);

    $this->post(route('shops.sync.runs.store', $shop))->assertRedirect(route('login'));
});
