<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{0: User, 1: Shop}
 */
function invitingShop(string $ownerName = 'Taylor Otwell', string $shopName = 'Laravel Shop'): array
{
    $owner = User::factory()->create(['name' => $ownerName]);
    $shop = Shop::factory()->create(['name' => $shopName]);

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    return [$owner, $shop];
}

test('the invitations page lists the user\'s pending invitations', function () {
    [$owner, $shop] = invitingShop();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'Invited@Example.com',
        'invited_by' => $owner->id,
    ]);

    $this->actingAs($invitedUser)
        ->get(route('invitations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/index')
            ->has('invitations', 1)
            ->where('invitations.0.code', $invitation->code)
            ->where('invitations.0.inviterName', 'Taylor Otwell')
            ->where('invitations.0.shop.name', 'Laravel Shop')
            ->where('invitations.0.shop.slug', $shop->slug)
            ->where('pendingInvitationsCount', 1));
});

test('accepted, expired and other people\'s invitations are left out', function () {
    [$owner, $shop] = invitingShop();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    ShopInvitation::factory()->accepted()->create(['shop_id' => $shop->id, 'email' => 'invited@example.com', 'invited_by' => $owner->id]);
    $expired = ShopInvitation::factory()->expired()->create(['shop_id' => $shop->id, 'email' => 'invited@example.com', 'invited_by' => $owner->id]);
    ShopInvitation::factory()->create(['shop_id' => $shop->id, 'email' => 'someone@example.com', 'invited_by' => $owner->id]);

    $this->actingAs($invitedUser)
        ->get(route('invitations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('invitations', 0)
            ->where('pendingInvitationsCount', 0));

    $this->assertDatabaseHas('shop_invitations', ['id' => $expired->id]);
});

test('every page shares the pending invitation count for the menu', function () {
    [$owner, $shop] = invitingShop();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    ShopInvitation::factory()->count(2)->create(['shop_id' => $shop->id, 'email' => 'invited@example.com', 'invited_by' => $owner->id]);

    $this->actingAs($invitedUser)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('pendingInvitationsCount', 2)
            ->missing('pendingInvitations'));
});

test('declining an invitation returns to the invitations page', function () {
    [$owner, $shop] = invitingShop();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);

    $invitation = ShopInvitation::factory()->create(['shop_id' => $shop->id, 'email' => 'invited@example.com', 'invited_by' => $owner->id]);

    $this->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation))
        ->assertRedirect(route('invitations.index'));

    $this->assertModelMissing($invitation);
});

test('guests cannot see the invitations page', function () {
    $this->get(route('invitations.index'))->assertRedirect(route('login'));
});
