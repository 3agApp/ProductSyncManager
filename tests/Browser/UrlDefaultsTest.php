<?php

use App\Models\User;

/**
 * installUrlDefaults() reads the shop out of the page Inertia boots
 * from, because the first render happens before any navigate event. These
 * assert the shape it depends on, so a change to how Inertia ships that page
 * fails here rather than silently going back to unresolved URLs.
 */
test('the booted page carries the current shop where the url defaults look for it', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('dashboard', $user->currentShop));
    $page->assertNoJavaScriptErrors();

    $slug = $page->script(<<<'JS'
        JSON.parse(
            document.querySelector('script[data-page="app"][type="application/json"]').textContent
        ).props.currentShop.slug
    JS);

    expect($slug)->toBe($user->currentShop->slug);
});

test('no shop leaves the defaults empty rather than guessing', function () {
    $user = User::factory()->withoutShop()->create();

    $this->actingAs($user);

    $page = visit(route('onboarding'));
    $page->assertNoJavaScriptErrors();

    $current = $page->script(<<<'JS'
        JSON.parse(
            document.querySelector('script[data-page="app"][type="application/json"]').textContent
        ).props.currentShop
    JS);

    expect($current)->toBeNull();
});
