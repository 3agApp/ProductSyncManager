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

    /**
     * Create or repair the product webhooks in the connection's store.
     *
     * Progress is saved after every webhook, so a setup that fails part way
     * reuses what it already created on the next attempt.
     *
     * @throws WooCommerceWebhookSetupException
     */
    public function handle(WooCommerceConnection $connection): void
    {
        $lock = self::lock($connection->shop_id);

        if (! $lock->get()) {
            throw new WooCommerceWebhookSetupException(__('Webhook setup or a connection update is already in progress. Please wait and try again.'));
        }

        try {
            $connection->refresh();

            if (! $connection->hasUsableCredentials()) {
                throw new WooCommerceWebhookSetupException(__('Save a valid WooCommerce consumer key and secret before setting up webhooks.'));
            }

            $url = $this->publicDeliveryUrl($connection);
            $client = WooCommerceClient::quick($connection)->until(now()->addSeconds(self::SETUP_SECONDS));
            $webhooks = $client->webhooks();

            // Persist before WooCommerce attempts its activation ping or delivery.
            $connection->webhook_secret = $connection->webhookSecret() ?? Str::random(64);
            $connection->webhooks_configured_at = null;
            $connection->save();

            foreach (self::TOPICS as $topic) {
                [$existing, $duplicates] = $this->matchingWebhooks($webhooks, $topic, $url, $connection->webhook_setup[$topic] ?? null);

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

                // A second live hook for the same topic and address would deliver
                // every change twice. Extras are switched off rather than deleted,
                // so nothing a store owner set up by hand is lost.
                foreach ($duplicates as $duplicate) {
                    $client->disableWebhook($duplicate['id']);
                }
            }

            $connection->update(['webhooks_configured_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Get the delivery URL, refusing one WooCommerce could never reach.
     *
     * @throws WooCommerceWebhookSetupException
     */
    private function publicDeliveryUrl(WooCommerceConnection $connection): string
    {
        $url = $connection->webhookDeliveryUrl();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $isHttps = parse_url($url, PHP_URL_SCHEME) === 'https';
        $isLocalName = in_array($host, ['', 'localhost', '::1', '[::1]'], true);
        $isPrivateAddress = filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if (! $isHttps || $isLocalName || $isPrivateAddress) {
            throw new WooCommerceWebhookSetupException(__('Automatic setup needs this app’s public HTTPS address. Configure APP_URL for this installation, then try again.'));
        }

        return $url;
    }

    /**
     * Find the remote webhook to update for a topic, and any live duplicates of it.
     *
     * The webhook setup created before is preferred while it still points
     * where setup left it, which repairs hooks after APP_URL changes.
     * Otherwise the first webhook already pointing at this app is reused.
     * Every other enabled webhook for the topic pointing at this app is a
     * duplicate. One another integration has retargeted is left alone.
     *
     * @param  array<int, array<string, mixed>>  $webhooks
     * @param  array{id: int, delivery_url: string}|null  $tracked
     * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>}
     */
    private function matchingWebhooks(array $webhooks, string $topic, string $url, ?array $tracked): array
    {
        $forTopic = collect($webhooks)->filter(fn (array $webhook) => $webhook['topic'] === $topic);

        $trackedWebhook = $tracked === null ? null : $forTopic->first(
            fn (array $webhook) => $webhook['id'] === $tracked['id'] && $webhook['delivery_url'] === $tracked['delivery_url'],
        );
        $pointingHere = $forTopic->filter(fn (array $webhook) => $webhook['delivery_url'] === $url);

        $existing = $trackedWebhook ?? $pointingHere->first();

        $duplicates = $pointingHere
            ->reject(fn (array $webhook) => $webhook['id'] === ($existing['id'] ?? null)
                || ($webhook['status'] ?? null) === 'disabled')
            ->values()
            ->all();

        return [$existing, $duplicates];
    }
}
