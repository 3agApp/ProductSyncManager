<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * An OpenID Connect authorization code client for 3AG Accounts.
 *
 * Accounts publishes a discovery document, so the only endpoint this has to
 * be told is the issuer; everything else is read from there and cached. The
 * code is exchanged over a back channel and the claims come from the
 * userinfo endpoint, which keeps the id_token out of the flow entirely.
 */
class AccountsOidc
{
    /**
     * Session keys holding the single-use values that tie a callback to the
     * redirect that started it.
     */
    private const string STATE_KEY = 'accounts_oidc_state';

    private const string VERIFIER_KEY = 'accounts_oidc_verifier';

    /**
     * Build the URL that sends the guest to Accounts to sign in.
     *
     * prompt=consent forces Accounts to show the continue-as / switch-account
     * screen even when the browser already has an Accounts session.
     *
     * @throws AccountsOidcException
     */
    public function authorizeUrl(Request $request): string
    {
        $state = Str::random(40);
        $verifier = Str::random(96);

        $request->session()->put(self::STATE_KEY, $state);
        $request->session()->put(self::VERIFIER_KEY, $verifier);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->setting('client_id'),
            'redirect_uri' => $this->setting('redirect'),
            'scope' => implode(' ', (array) config('oidc.connections.accounts.scopes', [])),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ]);

        return $this->endpoint('authorization_endpoint').'?'.$query;
    }

    /**
     * Turn the code Accounts sent back into the claims about the signer.
     *
     * The state and verifier are pulled rather than read, so a callback that
     * is replayed or forged finds nothing to match against.
     *
     * @throws AccountsOidcException
     */
    public function user(Request $request): AccountsUser
    {
        $state = $request->session()->pull(self::STATE_KEY);
        $verifier = $request->session()->pull(self::VERIFIER_KEY);

        $returned = $request->query('state');

        if (! is_string($state) || ! is_string($returned) || ! hash_equals($state, $returned)) {
            throw new AccountsOidcException('The Accounts callback did not match the request that started it.');
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            throw new AccountsOidcException('Accounts refused the request: '.$error);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            throw new AccountsOidcException('The Accounts callback carried no authorization code.');
        }

        return $this->claims($this->accessToken($code, (string) $verifier));
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * @throws AccountsOidcException
     */
    private function accessToken(string $code, string $verifier): string
    {
        $response = $this->request(
            fn () => Http::asForm()->post($this->endpoint('token_endpoint'), [
                'grant_type' => 'authorization_code',
                'client_id' => $this->setting('client_id'),
                'client_secret' => $this->setting('client_secret'),
                'redirect_uri' => $this->setting('redirect'),
                'code_verifier' => $verifier,
                'code' => $code,
            ]),
            'exchange the authorization code',
        );

        $token = $response['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new AccountsOidcException('Accounts returned no access token.');
        }

        return $token;
    }

    /**
     * Read the signer's claims from the userinfo endpoint.
     *
     * @throws AccountsOidcException
     */
    private function claims(string $accessToken): AccountsUser
    {
        $claims = $this->request(
            fn () => Http::withToken($accessToken)->acceptJson()->get($this->endpoint('userinfo_endpoint')),
            'read the user',
        );

        $subject = $claims['sub'] ?? null;

        if (! is_string($subject) || $subject === '') {
            throw new AccountsOidcException('Accounts returned no subject claim.');
        }

        $email = $claims['email'] ?? null;
        $name = $claims['name'] ?? null;

        return new AccountsUser(
            id: $subject,
            email: is_string($email) ? $email : null,
            name: is_string($name) ? $name : null,
            emailVerified: $this->emailVerified($claims),
        );
    }

    /**
     * Read the email_verified claim, if the issuer sent one.
     *
     * The claim is optional in OIDC, so its absence is not a denial -- it
     * means the issuer did not say, and null keeps that distinct from an
     * explicit false the caller should refuse. Accounts sends a JSON bool;
     * the filter also copes with the "true"/"false" strings some issuers
     * send, and answers null for anything it cannot read either way.
     *
     * @param  array<string, mixed>  $claims
     */
    private function emailVerified(array $claims): ?bool
    {
        if (! array_key_exists('email_verified', $claims)) {
            return null;
        }

        return filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Get one endpoint from the issuer's discovery document.
     *
     * @throws AccountsOidcException
     */
    private function endpoint(string $name): string
    {
        $endpoint = $this->discovery()[$name] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new AccountsOidcException("Accounts published no {$name}.");
        }

        return $endpoint;
    }

    /**
     * Fetch the issuer's discovery document, cached between logins.
     *
     * @return array<string, mixed>
     *
     * @throws AccountsOidcException
     */
    private function discovery(): array
    {
        $issuer = rtrim($this->setting('base_url'), '/');

        return Cache::remember(
            'oidc.accounts.discovery:'.$issuer,
            (int) config('oidc.connections.accounts.discovery_ttl', 3600),
            fn () => $this->request(
                fn () => Http::acceptJson()->get($issuer.'/.well-known/openid-configuration'),
                'reach 3AG Accounts',
            ),
        );
    }

    /**
     * Read a value the environment has to provide.
     *
     * @throws AccountsOidcException
     */
    private function setting(string $key): string
    {
        $value = config("oidc.connections.accounts.{$key}");

        if (! is_string($value) || $value === '') {
            throw new AccountsOidcException("The Accounts connection has no {$key} configured.");
        }

        return $value;
    }

    /**
     * Send one request and decode its JSON, turning every failure into the
     * one exception the controller reports.
     *
     * @param  callable(): Response  $send
     * @return array<string, mixed>
     *
     * @throws AccountsOidcException
     */
    private function request(callable $send, string $action): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $exception) {
            throw new AccountsOidcException("Could not {$action}: ".$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new AccountsOidcException("Could not {$action}: Accounts answered {$response->status()}.");
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new AccountsOidcException("Could not {$action}: Accounts did not answer with JSON.");
        }

        return $decoded;
    }
}
