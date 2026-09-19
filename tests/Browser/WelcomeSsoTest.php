<?php

/**
 * An Inertia <Link> renders a plain anchor too, so the href alone proves
 * nothing. Only a real document load tells them apart: it discards whatever
 * the previous window held. Here the CTA leaves the SPA, fails to reach an
 * identity provider (there is none under test), and returns to the homepage
 * with an error toast -- so the toast marks the end of the round trip.
 */
function assertClickLeavesTheSpa(string $selector): void
{
    $page = visit('/');

    $page->script('window.ssoNavigationProbe = true');

    $page->click($selector)
        ->assertSee('Could not sign in with 3AG Accounts')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.ssoNavigationProbe ?? false'))->toBeFalse();
}

test('the welcome page renders for a guest', function () {
    visit('/')
        ->assertSee('Keep every product in sync')
        ->assertNoJavaScriptErrors();
});

test('clicking log in on the homepage leaves the spa', function () {
    assertClickLeavesTheSpa('@sso-login');
});

test('clicking get started on the homepage leaves the spa', function () {
    assertClickLeavesTheSpa('@sso-get-started');
});

test('clicking start for free on the homepage leaves the spa', function () {
    assertClickLeavesTheSpa('@sso-start');
});
