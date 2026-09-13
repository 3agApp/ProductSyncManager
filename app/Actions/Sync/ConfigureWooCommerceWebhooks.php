<?php

namespace App\Actions\Sync;

use App\Models\WooCommerceConnection;
use App\Services\WooCommerce\Exceptions\WooCommerceWebhookSetupException;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ConfigureWooCommerceWebhooks
{
    public const array TOPICS = ['product.created', 'product.updated', 'product.deleted', 'product.restored'];

    /**
     * The longest setup may talk to WooCommerce before it gives up.
     */
    public const int SETUP_SECONDS = 45;

    /**
     * How long the connection lock outlives a request that never releases it.
     *
     * Setup runs inside a web request, and a request killed by the server
     * never reaches its finally block. The lock must still cover a setup that
     * runs to its deadline, but no longer, or a killed request would block
     * saving the connection for that long.
     */
    public const int LOCK_SECONDS = self::SETUP_SECONDS + 15;

    /**
     * The lock shared by webhook setup and connection changes for a shop.
     */
    public static function lock(int $shopId): Lock
    {
        return Cache::lock('woocommerce-connection:'.$shopId, self::LOCK_SECONDS);
    }

    public function handle(WooCommerceConnection $connection): void
    {
        $lock = self::lock($connection->shop_id);
        if (! $lock->get()) {
            throw new WooCommerceWebhookSetupException('Webhook setup or a connection update is already in progress. Please wait and try again.');
        }

        try {
            $connection->refresh();
            if (! $connection->hasUsableCredentials()) {
                throw new WooCommerceWebhookSetupException('Save a valid WooCommerce consumer key and secret before setting up webhooks.');
            }
            $url = $connection->webhookDeliveryUrl();
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! is_string($host)
                || in_array(strtolower($host), ['localhost', '::1', '[::1]'], true)
                || (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                throw new WooCommerceWebhookSetupException('Automatic setup needs this app’s public HTTPS address. Configure APP_URL for this installation, then try again.');
            }

            $client = WooCommerceClient::quick($connection)->until(now()->addSeconds(self::SETUP_SECONDS));
            $webhooks = $client->webhooks();
            $secret = rescue(fn () => $connection->webhook_secret, null, report: false);
            // Persist before WooCommerce attempts its activation ping or delivery.
            $connection->webhook_secret = filled($secret) ? $secret : Str::random(64);
            $connection->webhooks_configured_at = null;
            $connection->save();

            foreach (self::TOPICS as $topic) {
                $tracked = ($connection->webhook_setup ?? [])[$topic] ?? null;
                $existing = collect($webhooks)->first(fn (array $webhook) => $webhook['topic'] === $topic
                    && (($webhook['delivery_url'] === $url)
                        || ($tracked !== null && $webhook['id'] === $tracked['id'] && $webhook['delivery_url'] === $tracked['delivery_url'])));
                $webhook = $client->saveWebhook($existing['id'] ?? null, [
                    'name' => 'ProductSync — '.$topic,
                    'topic' => $topic,
                    'delivery_url' => $url,
                    'secret' => $connection->webhook_secret,
                    'status' => 'active',
                    'api_version' => 'wp_api_v3',
                ]);
                $connection->webhook_setup = [...($connection->webhook_setup ?? []), $topic => ['id' => $webhook['id'], 'delivery_url' => $url]];
                $connection->save();
            }

            $connection->update(['webhooks_configured_at' => now()]);
        } finally {
            $lock->release();
        }
    }
}
