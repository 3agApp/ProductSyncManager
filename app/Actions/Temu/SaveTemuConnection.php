<?php

namespace App\Actions\Temu;

use App\Enums\TemuDefaultBehavior;
use App\Models\Shop;
use App\Models\TemuConnection;

class SaveTemuConnection
{
    /**
     * Create or update a shop's Temu settings.
     *
     * A blank secret or token means "keep what is stored", so the settings
     * form never has to round trip either through the browser. Clearing the
     * app key disconnects the shop and forgets both.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(Shop $shop, array $data): TemuConnection
    {
        $connection = $shop->temuConnection ?? new TemuConnection;
        $connection->shop_id = $shop->id;

        $appKey = trim((string) ($data['app_key'] ?? ''));
        $secret = (string) ($data['app_secret'] ?? '');
        $token = trim((string) ($data['access_token'] ?? ''));

        if ($appKey === '') {
            $connection->app_key = null;
            $connection->app_secret = null;
            $connection->access_token = null;
        } else {
            $connection->app_key = $appKey;

            if ($secret !== '') {
                $connection->app_secret = $secret;
            }

            if ($token !== '') {
                $connection->access_token = $token;
            }
        }

        // Any credential change invalidates the last successful test and
        // what it learned about the token.
        if ($connection->isDirty(['app_key', 'app_secret', 'access_token'])) {
            $connection->verified_at = null;
            $connection->token_expires_at = null;
            $connection->mall_id = null;
        }

        $connection->default_behavior = TemuDefaultBehavior::from((string) $data['default_behavior']);
        $connection->currency = strtoupper((string) $data['currency']);
        $connection->discount_percent = number_format((float) $data['discount_percent'], 2, '.', '');
        $connection->sync_after_sync = (bool) ($data['sync_after_sync'] ?? false);
        $connection->import_orders = (bool) ($data['import_orders'] ?? false);
        $connection->order_shipping = number_format((float) $data['order_shipping'], 2, '.', '');
        // Only imported orders can be confirmed, so this follows the import switch.
        $connection->confirm_shipments = $connection->import_orders && (bool) ($data['confirm_shipments'] ?? false);

        $connection->save();

        $shop->setRelation('temuConnection', $connection);

        return $connection->refresh();
    }
}
