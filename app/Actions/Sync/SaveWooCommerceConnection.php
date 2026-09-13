<?php

namespace App\Actions\Sync;

use App\Models\Shop;
use App\Models\WooCommerceConnection;

class SaveWooCommerceConnection
{
    /**
     * Create or update a shop's WooCommerce connection.
     *
     * Blank credential fields mean "keep what is stored", so the settings
     * form never has to round trip the secret through the browser.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Shop $shop, array $data): WooCommerceConnection
    {
        $connection = $shop->wooCommerceConnection ?? new WooCommerceConnection;
        $connection->shop_id = $shop->id;

        $connection->store_url = rtrim((string) $data['store_url'], '/');

        $key = trim((string) ($data['consumer_key'] ?? ''));
        $secret = trim((string) ($data['consumer_secret'] ?? ''));

        if ($key !== '') {
            $connection->consumer_key = $key;
            $connection->key_last_four = substr($key, -4);
        }

        if ($secret !== '') {
            $connection->consumer_secret = $secret;
            $connection->secret_last_four = substr($secret, -4);
        }

        // Any credential or address change invalidates the last successful test.
        if ($connection->isDirty(['store_url', 'consumer_key', 'consumer_secret'])) {
            $connection->verified_at = null;
        }

        if ($connection->isDirty('store_url')) {
            $connection->webhook_secret = null;
        }

        $webhookSecret = trim((string) ($data['webhook_secret'] ?? ''));
        if ($webhookSecret !== '') {
            $connection->webhook_secret = $webhookSecret;
        }

        $connection->save();

        return $connection->refresh();
    }
}
