<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Notifications\Shops\ShopInvitation as ShopInvitationNotification;
use Illuminate\Support\Facades\Notification;

test('shop invitations can be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('shops.invitations.store', $shop), [
            'email' => 'invited@example.com',
            'role' => ShopRole::Member->value,
        ]);

    $response->assertRedirect(route('shops.edit', $shop));

    $this->assertDatabaseHas('shop_invitations', [
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'role' => ShopRole::Member->value,
    ]);
});

test('invitation email for existing users uses login route', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => $invitedUser->email,
        'invited_by' => $owner->id,
    ]);

    $mail = (new ShopInvitationNotification($invitation))->toMail($invitedUser);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('dashboard', implode(' ', $mail->introLines));
});

test('invitation email for unknown users uses login route', function () {
    $owner = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'unknown@example.com',
        'invited_by' => $owner->id,
    ]);

    $mail = (new ShopInvitationNotification($invitation))->toMail((object) []);

    expect($mail->actionUrl)->toBe(route('login', ['invitation' => $invitation->code]));
    $this->assertStringContainsString('log in', strtolower(implode(' ', $mail->introLines)));
});

test('shop invitations can be created by admins', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($admin, ['role' => ShopRole::Admin->value]);

    $response = $this
        ->actingAs($admin)
        ->post(route('shops.invitations.store', $shop), [
            'email' => 'invited@example.com',
            'role' => ShopRole::Member->value,
        ]);

    $response->assertRedirect(route('shops.edit', $shop));
});

test('existing shop members cannot be invited', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'member@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($owner)
        ->post(route('shops.invitations.store', $shop), [
            'email' => 'member@example.com',
            'role' => ShopRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('duplicate invitations cannot be created', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $shop = Shop::factory()->create();
    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->post(route('shops.invitations.store', $shop), [
            'email' => 'invited@example.com',
            'role' => ShopRole::Member->value,
        ]);

    $response->assertSessionHasErrors('email');
});

test('shop invitations cannot be created by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);
    $shop->members()->attach($member, ['role' => ShopRole::Member->value]);

    $response = $this
        ->actingAs($member)
        ->post(route('shops.invitations.store', $shop), [
            'email' => 'invited@example.com',
            'role' => ShopRole::Member->value,
        ]);

    $response->assertForbidden();
});

test('shop invitations can be cancelled by owners', function () {
    $owner = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($owner)
        ->delete(route('shops.invitations.destroy', [$shop, $invitation]));

    $response->assertRedirect(route('shops.edit', $shop));

    $this->assertDatabaseMissing('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('shop invitations can be accepted', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'role' => ShopRole::Member,
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertRedirect(route('dashboard'));
    $response->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Invitation accepted.']);

    expect($invitedUser->fresh()->belongsToShop($shop))->toBeTrue();
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});

test('shop invitations can be declined by the invited user', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertRedirect(route('invitations.index'));

    $this->assertDatabaseMissing('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('shop invitations cannot be declined by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('accepted shop invitations cannot be declined', function () {
    $owner = User::factory()->create();
    $invitedUser = User::factory()->create(['email' => 'invited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->accepted()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($invitedUser)
        ->delete(route('invitations.decline', $invitation));

    $response->assertSessionHasErrors('invitation');

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitation->id,
    ]);
});

test('shop invitations cannot be accepted by uninvited user', function () {
    $owner = User::factory()->create();
    $uninvitedUser = User::factory()->create(['email' => 'uninvited@example.com']);
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $invitation = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'email' => 'invited@example.com',
        'invited_by' => $owner->id,
    ]);

    $response = $this
        ->actingAs($uninvitedUser)
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($uninvitedUser->fresh()->belongsToShop($shop))->toBeFalse();
});

test('expired invitations cannot be accepted', function () {
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
        ->post(route('invitations.accept', $invitation));

    $response->assertSessionHasErrors('invitation');

    expect($invitedUser->fresh()->belongsToShop($shop))->toBeFalse();
});
