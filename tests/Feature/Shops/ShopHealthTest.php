<?php

use App\Enums\ShopRole;
use App\Enums\TemuProductStatus;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\SyncRun;
use App\Models\TemuConnection;
use App\Models\TemuProduct;
use App\Models\User;
use App\Models\WooCommerceConnection;
use App\Notifications\Shops\ShopInvitation as ShopInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->shop = Shop::factory()->create();
    $this->shop->members()->attach($this->owner, ['role' => ShopRole::Owner->value]);
});

test('a healthy shop has nothing that needs attention', function () {
    $this->actingAs($this->owner)
        ->get(route('shops.edit', $this->shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention', [])
            ->has('integrations', 3)
            ->where('integrations.0.key', 'sync')
            ->where('integrations.0.status', 'not_configured'));
});

test('expired invitations need attention and are marked expired', function () {
    ShopInvitation::factory()->expired()->create([
        'shop_id' => $this->shop->id,
        'invited_by' => $this->owner->id,
    ]);
    ShopInvitation::factory()->expiresIn(2)->create([
        'shop_id' => $this->shop->id,
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->get(route('shops.edit', $this->shop))
        ->assertInertia(fn (Assert $page) => $page
            ->has('attention', 1)
            ->where('attention.0.key', 'expired-invitations')
            ->where('attention.0.target', 'invitations')
            ->has('invitations', 2)
            ->where('invitations.0.is_expired', true)
            ->where('invitations.1.is_expired', false));
});

test('a failed sync and failing Temu products need attention', function () {
    WooCommerceConnection::factory()->for($this->shop)->create();
    SyncRun::factory()->for($this->shop)->failed()->create();
    TemuConnection::factory()->for($this->shop)->create();
    TemuProduct::factory()->for($this->shop)->count(2)->create(['status' => TemuProductStatus::Failed]);
    TemuProduct::factory()->for($this->shop)->create(['status' => TemuProductStatus::Active]);

    $this->actingAs($this->owner)
        ->get(route('shops.edit', $this->shop))
        ->assertInertia(fn (Assert $page) => $page
            ->where('attention.0.key', 'sync-failed')
            ->where('attention.0.description', 'WooCommerce rejected the credentials.')
            ->where('attention.1.key', 'temu-products')
            ->where('attention.1.title', '2 products need attention on Temu')
            ->where('integrations.0.status', 'failing')
            ->where('integrations.2.status', 'connected'));
});

test('members do not see invitation problems they cannot act on', function () {
    $member = User::factory()->create();
    $this->shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    ShopInvitation::factory()->expired()->create([
        'shop_id' => $this->shop->id,
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($member)
        ->get(route('shops.edit', $this->shop))
        ->assertInertia(fn (Assert $page) => $page->where('attention', []));
});

test('an invitation can be resent with a fresh expiry', function () {
    Notification::fake();

    $invitation = ShopInvitation::factory()->expired()->create([
        'shop_id' => $this->shop->id,
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner)
        ->post(route('shops.invitations.resend', [$this->shop, $invitation]))
        ->assertRedirect(route('shops.edit', $this->shop));

    expect($invitation->fresh()->isExpired())->toBeFalse();

    Notification::assertSentOnDemand(ShopInvitationNotification::class);
});

test('a member cannot resend an invitation', function () {
    Notification::fake();

    $member = User::factory()->create();
    $this->shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $this->shop->id,
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($member)
        ->post(route('shops.invitations.resend', [$this->shop, $invitation]))
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('an invitation from another shop cannot be resent through this one', function () {
    $invitation = ShopInvitation::factory()->create();

    $this->actingAs($this->owner)
        ->post(route('shops.invitations.resend', [$this->shop, $invitation]))
        ->assertNotFound();
});

test('the old appearance address leads to the profile page', function () {
    $this->actingAs($this->owner)
        ->get(route('appearance.edit'))
        ->assertRedirect('/settings/profile#appearance');
});
