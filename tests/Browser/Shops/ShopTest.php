<?php

use App\Models\Shop;
use App\Models\User;

/**
 * The shop type select, from each place it can be reached.
 *
 * A select that throws when opened takes the whole page with it, so each of
 * these opens it, picks from it, and checks the page is still standing.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

it('offers the shop type in the new shop dialog', function () {
    visit(route('shops.index'))
        ->click('@shops-new-shop-button')
        ->click('@create-shop-type')
        ->click('[role="option"]:has-text("WordPress")')
        ->assertSee('WordPress')
        ->assertNoJavaScriptErrors();
});

it('offers the shop type in the dialog opened from the switcher', function () {
    visit(route('dashboard', ['current_shop' => $this->shop->slug]))
        ->click('@shop-switcher-trigger')
        ->click('@shop-switcher-new-shop')
        ->click('@create-shop-type')
        ->click('[role="option"]:has-text("WordPress")')
        ->assertSee('WordPress')
        ->assertNoJavaScriptErrors();
});

it('offers the shop type on the shop settings page', function () {
    visit(route('shops.edit', $this->shop))
        ->click('@shop-type-input')
        ->click('[role="option"]:has-text("WordPress")')
        ->assertSee('WordPress')
        ->assertNoJavaScriptErrors();
});

it('creates a shop and switches to it', function () {
    visit(route('shops.index'))
        ->click('@shops-new-shop-button')
        ->fill('@create-shop-name', 'Browser Test Emporium')
        ->click('@create-shop-submit')
        ->assertSee('Edit Browser Test Emporium')
        ->assertNoJavaScriptErrors();

    $created = Shop::where('name', 'Browser Test Emporium')->sole();

    expect($this->user->fresh()->currentShop->id)->toBe($created->id);
});
