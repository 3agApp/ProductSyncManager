<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot visit the invitations page', function () {
    $this->get(route('invitations.index'))->assertRedirect(route('login'));
});

test('the invitations page lists pending invitations for the authenticated user', function () {
    $owner = User::factory()->create(['name' => 'Taylor Otwell']);
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create(['name' => 'Laravel Shop']);

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/index')
        ->has('invitations', 1)
        ->where('invitations.0.code', $invitation->code)
        ->where('invitations.0.inviterName', 'Taylor Otwell')
        ->where('invitations.0.shop.name', 'Laravel Shop')
        ->where('invitations.0.shop.slug', $shop->slug)
        ->where('invitations.0.roleLabel', $invitation->role->label())
        ->where('pendingInvitationsCount', 1)
        ->missing('invitations.0.shopName'),
    );
});

test('the invitations page does not include accepted invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    ShopInvitation::factory()->accepted()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/index')
        ->has('invitations', 0),
    );
});

test('the invitations page excludes expired invitations without deleting them', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->expired()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/index')
        ->has('invitations', 0),
    );

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('the invitations page does not include or delete other users invitations', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->expired()->create([
        'shop_id' => $shop->id,
        'email' => 'someone@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->get(route('invitations.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('invitations/index')
        ->has('invitations', 0),
    );

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('the pending invitation count is shared with every page', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'Invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    ShopInvitation::factory()->count(2)->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $this
        ->actingAs($invitedUser)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('pendingInvitationsCount', 2)
            ->missing('pendingInvitations'),
        );
});
