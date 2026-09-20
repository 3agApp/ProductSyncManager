<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@3ag.local',
    ]);

    $this->actingAs($this->user);
});

it('shows the identity Accounts holds without offering to edit it', function () {
    $page = visit(route('profile.edit'));

    $page->assertSee('Ada Lovelace')
        ->assertSee('ada@3ag.local')
        ->assertNoJavaScriptErrors();

    // Nothing to type into and nothing to submit: the only inputs left on the
    // page belong to the delete-account dialog, which is still the user's own.
    expect($page->script('document.querySelectorAll(\'input[name="email"], input[name="name"]\').length'))->toBe(0);
});

it('points the user at Accounts to change any of it', function () {
    visit(route('profile.edit'))
        ->assertAttribute('@accounts-link', 'href', rtrim((string) config('oidc.connections.accounts.base_url'), '/'))
        ->assertNoJavaScriptErrors();
});
