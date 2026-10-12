<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'woocommerce' => [
        'per_page' => (int) env('WOOCOMMERCE_PER_PAGE', 100),
        'retries' => (int) env('WOOCOMMERCE_RETRIES', 3),
        'max_pages' => (int) env('WOOCOMMERCE_MAX_PAGES', 500),
        // Lets a store live on a private or loopback address, for a fake
        // store during local development. Never enable it where the app runs
        // next to anything a user should not reach through it.
        'allow_private_hosts' => (bool) env('WOOCOMMERCE_ALLOW_PRIVATE_HOSTS', env('APP_ENV') === 'local'),
    ],

    'nalda' => [
        // Every seller uploads to the same server, so only the user and
        // password are stored per shop and no address comes from a form.
        'sftp_host' => env('NALDA_SFTP_HOST', 'sftp.nalda.com'),
        'sftp_port' => (int) env('NALDA_SFTP_PORT', 2022),
        'sftp_timeout' => (int) env('NALDA_SFTP_TIMEOUT', 20),
        // The seller API orders are read from; the key is the shop's own.
        'api_url' => env('NALDA_API_URL', 'https://sellers-api.nalda.com'),
        'api_timeout' => (int) env('NALDA_API_TIMEOUT', 30),
    ],

    'temu' => [
        // Swiss, EU and UK stores are all served by the EU gateway. The app
        // key, secret and access token belong to each shop's own
        // self-developed app, so they are stored per shop and not here.
        'base_url' => env('TEMU_BASE_URL', 'https://openapi-b-eu.temu.com/openapi/router'),
        'timeout' => (int) env('TEMU_TIMEOUT', 30),
    ],

];
