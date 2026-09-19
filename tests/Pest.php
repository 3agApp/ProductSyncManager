<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Answer the Accounts endpoints the OIDC client calls, and put the session in
 * the state a redirect would have left it in.
 *
 * Pass a null claim to drop it from the userinfo response, or an 'endpoints'
 * key to reshape the discovery document.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeAccounts(array $claims = [], array $endpoints = []): void
{
    Http::fake([
        'accounts.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://accounts.test',
            'authorization_endpoint' => 'https://accounts.test/oauth/authorize',
            'token_endpoint' => 'https://accounts.test/oauth/token',
            'userinfo_endpoint' => 'https://accounts.test/oauth/userinfo',
            ...$endpoints,
        ]),
        'accounts.test/oauth/token' => Http::response(['access_token' => 'an-access-token']),
        'accounts.test/oauth/userinfo' => Http::response(array_filter([
            'sub' => 'oidc-sub-42',
            'email' => 'signer@example.com',
            'name' => 'The Signer',
            ...$claims,
        ], fn (mixed $value) => $value !== null)),
    ]);
}

/**
 * Put the session in the state the redirect leaves it in, so a callback with
 * ?state=state&code=code is treated as the answer to it.
 */
function signInThroughAccounts(array $claims = []): void
{
    fakeAccounts($claims);

    test()->withSession([
        'accounts_oidc_state' => 'state',
        'accounts_oidc_verifier' => 'a-verifier',
    ]);
}
