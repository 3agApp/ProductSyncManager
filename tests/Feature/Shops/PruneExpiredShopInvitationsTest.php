<?php

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;

test('expired invitations are deleted by the scheduled cleanup', function () {
    $this->travelTo(now()->startOfDay());

    $owner = User::factory()->create();
    $shop = Shop::factory()->create();

    $shop->members()->attach($owner, ['role' => ShopRole::Owner->value]);

    $expiredInvitation = ShopInvitation::factory()->expired()->create([
        'shop_id' => $shop->id,
        'invited_by' => $owner->id,
    ]);

    $unexpiredInvitation = ShopInvitation::factory()->expiresIn(1)->create([
        'shop_id' => $shop->id,
        'invited_by' => $owner->id,
    ]);

    $invitationWithoutExpiration = ShopInvitation::factory()->create([
        'shop_id' => $shop->id,
        'invited_by' => $owner->id,
    ]);

    $this->artisan('schedule:run')->assertSuccessful();

    $this->assertDatabaseMissing('shop_invitations', [
        'id' => $expiredInvitation->id,
    ]);

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $unexpiredInvitation->id,
    ]);

    $this->assertDatabaseHas('shop_invitations', [
        'id' => $invitationWithoutExpiration->id,
    ]);
});
