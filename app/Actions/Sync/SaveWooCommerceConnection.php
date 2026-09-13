<?php

namespace App\Actions\Sync;

use App\Models\Shop;
use App\Models\WooCommerceConnection;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Validation\ValidationException;

class SaveWooCommerceConnection
{
    /**
     * How long to wait for another save or a webhook setup to finish.
     *
     * A save holds the lock for milliseconds, so waiting briefly lets two
     * saves in quick succession both land instead of the second failing.
     */
    private const int WAIT_SECONDS = 5;

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
        $lock = ConfigureWooCommerceWebhooks::lock($shop->id);

        try {
            $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // Only webhook setup holds the lock for longer than the wait.
            throw ValidationException::withMessages([
                'store_url' => __('Webhook setup is still running for this shop. Try saving again in a minute.'),
            ]);
        }

        try {
            $shop->unsetRelation('wooCommerceConnection');

            return $this->save($shop, $data);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $data */
    private function save(Shop $shop, array $data): WooCommerceConnection
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
            $connection->webhook_setup = null;
            $connection->webhooks_configured_at = null;
        }

        $webhookSecret = trim((string) ($data['webhook_secret'] ?? ''));
        if ($webhookSecret !== '') {
            $connection->webhook_secret = $webhookSecret;
        }

        if ($connection->isDirty('webhook_secret')) {
            $connection->webhooks_configured_at = null;
        }

        $connection->save();

        return $connection->refresh();
    }
}
