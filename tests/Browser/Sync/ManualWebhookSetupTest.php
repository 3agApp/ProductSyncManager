<?php

use App\Models\User;

/**
 * The manual webhook panel keeps the secret write-only: it is never sent back
 * to the browser, and the field says so once one is stored.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->shop = $this->user->currentShop;

    $this->actingAs($this->user);
});

it('saves a webhook secret without ever showing it again', function () {
    $secret = 'browser-webhook-secret-0123456789abcdef';

    $page = visit(route('shops.sync.edit', $this->shop))
        ->fill('@store-url-input', 'https://store.example.com')
        ->fill('@consumer-key-input', 'ck_test')
        ->fill('@consumer-secret-input', 'cs_test')
        ->click('@connection-save')
        ->assertEnabled('@webhook-setup-button')
        ->click('summary:has-text("Manual webhook setup")')
        ->fill('#webhook_secret', $secret)
        ->click('button:has-text("Save manual webhook settings")')
        ->assertValue('#webhook_secret', '')
        ->assertAttribute('#webhook_secret', 'placeholder', 'Configured — leave blank to keep')
        ->assertNoJavaScriptErrors();

    expect($this->shop->wooCommerceConnection->fresh()->webhook_secret)->toBe($secret);

    $page->assertDontSee($secret);

    // The stored secret survives a reload and is still not disclosed.
    visit(route('shops.sync.edit', $this->shop))
        ->click('summary:has-text("Manual webhook setup")')
        ->assertValue('#webhook_secret', '')
        ->assertDontSee($secret)
        ->assertNoJavaScriptErrors();
});

it('shows a delivery url pointing at this installation', function () {
    $page = visit(route('shops.sync.edit', $this->shop))
        ->fill('@store-url-input', 'https://store.example.com')
        ->fill('@consumer-key-input', 'ck_test')
        ->fill('@consumer-secret-input', 'cs_test')
        ->click('@connection-save')
        ->assertEnabled('@webhook-setup-button')
        ->click('summary:has-text("Manual webhook setup")');

    $connection = $this->shop->wooCommerceConnection;

    expect($page->script("document.querySelector('#webhook_url').value"))
        ->toEndWith('/webhooks/woocommerce/'.$connection->id);

    $page->assertNoJavaScriptErrors();
});

it('fits the manual webhook panel on desktop and mobile without scrolling sideways', function (int $width) {
    $page = visit(route('shops.sync.edit', $this->shop))
        ->resize($width, 1000)
        ->fill('@store-url-input', 'https://store.example.com')
        ->fill('@consumer-key-input', 'ck_test')
        ->fill('@consumer-secret-input', 'cs_test')
        ->click('@connection-save')
        ->assertEnabled('@webhook-setup-button')
        ->click('summary:has-text("Manual webhook setup")');

    expect($page->script('document.documentElement.scrollWidth > window.innerWidth'))
        ->toBeFalse("the page scrolls sideways at {$width}px");

    $page->assertNoJavaScriptErrors();
})->with([1280, 390]);
