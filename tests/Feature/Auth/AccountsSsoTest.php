<?php

use App\Models\ShopInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use ThreeAg\AccountsOidc\AccountsOidcException;

test('the login route hands the guest off to accounts', function () {
    $this->get(route('login'))->assertRedirect(route('auth.accounts.redirect'));
});

test('the register route hands the guest off to accounts too', function () {
    $this->get(route('register'))->assertRedirect(route('auth.accounts.redirect'));
});

test('an invitation code survives the round trip through accounts', function () {
    $this->get(route('login', ['invitation' => 'the-code']))
        ->assertRedirect(route('auth.accounts.redirect'))
        ->assertSessionHas('shop_invitation', 'the-code');
});

test('the redirect sends the guest to the discovered authorization endpoint', function () {
    fakeAccounts();

    $response = $this->get(route('auth.accounts.redirect'));

    $location = (string) $response->headers->get('Location');

    expect($location)->toStartWith('https://accounts.test/oauth/authorize?');

    $query = [];
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query)
        ->toHaveKey('client_id', 'test-client-id')
        ->toHaveKey('redirect_uri', 'http://localhost/auth/accounts/callback')
        ->toHaveKey('response_type', 'code')
        ->toHaveKey('scope', 'openid profile email')
        // Accounts shows the continue-as / switch-account screen every time.
        ->toHaveKey('prompt', 'consent')
        ->toHaveKey('code_challenge_method', 'S256');

    expect(session('accounts_oidc_state'))->toBe($query['state']);
});

test('the code challenge is the S256 hash of the stored verifier', function () {
    fakeAccounts();

    $response = $this->get(route('auth.accounts.redirect'));

    $query = [];
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    $expected = rtrim(strtr(base64_encode(hash('sha256', (string) session('accounts_oidc_verifier'), true)), '+/', '-_'), '=');

    expect($query['code_challenge'])->toBe($expected);
});

test('a misconfigured identity provider sends the guest home instead of erroring', function () {
    Exceptions::fake();

    config()->set('oidc.connections.accounts.base_url', null);

    $this->get(route('auth.accounts.redirect'))
        ->assertRedirect('/')
        ->assertInertiaFlash('toast.type', 'error');

    Exceptions::assertReported(AccountsOidcException::class);
});

test('an unreachable identity provider sends the guest home instead of erroring', function () {
    Exceptions::fake();

    Http::fake(['accounts.test/*' => Http::response('', 503)]);

    $this->get(route('auth.accounts.redirect'))
        ->assertRedirect('/')
        ->assertInertiaFlash('toast.type', 'error');

    Exceptions::assertReported(AccountsOidcException::class);
});

test('the accounts callback creates a local user and signs them in', function () {
    signInThroughAccounts();

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']))
        ->assertRedirect(route('onboarding'));

    $user = User::query()->where('email', 'signer@example.com')->firstOrFail();

    expect($user->sso_id)->toBe('oidc-sub-42')
        ->and($user->name)->toBe('The Signer')
        ->and($user->email_verified_at)->not->toBeNull();

    expect(Auth::id())->toBe($user->id);
});

test('the code is exchanged with the verifier the redirect stored', function () {
    signInThroughAccounts();

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']));

    Http::assertSent(fn ($request) => $request->url() === 'https://accounts.test/oauth/token'
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'the-code'
        && $request['code_verifier'] === 'a-verifier'
        && $request['client_secret'] === 'test-client-secret');
});

test('an existing user is matched on their subject even after an email change', function () {
    $user = User::factory()->create(['sso_id' => 'oidc-sub-42', 'email' => 'old@example.com']);

    signInThroughAccounts();

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']));

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->email)->toBe('signer@example.com');

    expect(Auth::id())->toBe($user->id);
});

test('an account that predates single sign-on is adopted by email', function () {
    $user = User::factory()->create(['email' => 'signer@example.com']);

    signInThroughAccounts();

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']));

    expect(User::query()->count())->toBe(1)
        ->and($user->fresh()->sso_id)->toBe('oidc-sub-42');

    expect(Auth::id())->toBe($user->id);
});

test('a signed-in user with a pending invitation lands on the invitations page', function () {
    $owner = User::factory()->create();

    ShopInvitation::factory()->create([
        'shop_id' => $owner->currentShop->id,
        'email' => 'signer@example.com',
        'invited_by' => $owner->id,
    ]);

    signInThroughAccounts();

    $this->withSession(['shop_invitation' => 'the-code'])
        ->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']))
        ->assertRedirect(route('invitations.index'));
});

test('a callback whose state does not match the session is refused', function () {
    Exceptions::fake();

    signInThroughAccounts();

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'forged']))
        ->assertRedirect('/')
        ->assertInertiaFlash('toast.type', 'error');

    $this->assertGuest();

    Http::assertNotSent(fn ($request) => $request->url() === 'https://accounts.test/oauth/token');
});

test('a callback cannot be replayed once its state is spent', function () {
    signInThroughAccounts();

    $callback = route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']);

    $this->get($callback);

    Auth::logout();
    Exceptions::fake();

    $this->get($callback)->assertRedirect('/');

    $this->assertGuest();
});

test('accounts without an email address cannot sign in', function () {
    signInThroughAccounts(['email' => null]);

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']))
        ->assertRedirect('/')
        ->assertInertiaFlash('toast.message', '3AG Accounts did not return an email address.');

    $this->assertGuest();

    expect(User::query()->count())->toBe(0);
});

test('an address Accounts reports as unverified is refused', function () {
    signInThroughAccounts(['email_verified' => false]);

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']))
        ->assertRedirect('/')
        ->assertInertiaFlash('toast.type', 'error');

    $this->assertGuest();

    expect(User::query()->count())->toBe(0);
});

test('an unverified address cannot take over an account that already exists', function () {
    $victim = User::factory()->create(['email' => 'signer@example.com', 'sso_id' => null]);

    signInThroughAccounts(['email_verified' => false]);

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']));

    $this->assertGuest();

    expect($victim->fresh()->sso_id)->toBeNull();
});

test('an issuer that sends no email_verified claim is still trusted', function () {
    // The claim is optional in OIDC, so a missing one means the issuer did
    // not say -- not that it said no. Refusing here would lock every user
    // out of the app the moment Accounts stopped sending it.
    signInThroughAccounts(['email_verified' => null]);

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']));

    expect(User::query()->where('email', 'signer@example.com')->exists())->toBeTrue();

    $this->assertAuthenticated();
});

test('a nameless account falls back to the local part of the email', function () {
    signInThroughAccounts(['name' => null]);

    $this->get(route('auth.accounts.callback', ['code' => 'the-code', 'state' => 'state']));

    expect(User::query()->firstOrFail()->name)->toBe('signer');
});

test('logging out ends the local session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect('/');

    $this->assertGuest();
});
