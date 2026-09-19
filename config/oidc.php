<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | One entry per OpenID Connect issuer the app signs users in through.
    | App\Services\Auth\AccountsOidc reads the 'accounts' connection and
    | discovers the rest of the endpoints from the issuer itself.
    |
    */

    'connections' => [
        'accounts' => [
            // No default: a fallback issuer sends an environment that forgot
            // ACCOUNTS_URL to discovery on its own host, which fails as an
            // unreadable 500 instead of naming the missing variable.
            'base_url' => env('ACCOUNTS_URL'),
            'client_id' => env('ACCOUNTS_CLIENT_ID'),
            'client_secret' => env('ACCOUNTS_CLIENT_SECRET'),
            'redirect' => env('ACCOUNTS_REDIRECT_URI', env('APP_URL').'/auth/accounts/callback'),
            'scopes' => ['openid', 'profile', 'email'],

            // How long the discovery document is cached for. The issuer only
            // moves its endpoints on a redeploy, so an hour keeps login off
            // the network without pinning a stale document for long.
            'discovery_ttl' => 3600,
        ],
    ],

];
