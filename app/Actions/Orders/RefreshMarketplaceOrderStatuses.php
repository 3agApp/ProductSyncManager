<?php

namespace App\Actions\Orders;

use App\Enums\Marketplace;
use App\Models\Shop;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceClient;
use App\Support\MarketplaceOrderMeta as Meta;
use Carbon\CarbonImmutable;

/**
 * Reads the delivery details of marketplace orders back from the store.
 *
 * The Product Sync Manager plugin keeps each marketplace order's delivery
 * state, expected date and tracking code on the WooCommerce order, worked out
 * from its status and Planzer's parcel number or chosen by hand. Orders the
 * store changed since the last check are read, and their details copied onto
 * the rows this app reports to the marketplaces from.
 */
class RefreshMarketplaceOrderStatuses
{
    /**
     * Minutes each check reaches back before the last one, so an order saved
     * while the last check ran is not missed.
     */
    private const int OVERLAP_MINUTES = 10;

    /**
     * Days read on the first check.
     */
    private const int FIRST_DAYS = 60;

    /**
     * The most pages of a hundred orders read in one check.
     */
    private const int MAX_PAGES = 50;

    /**
     * Copy the details of every changed marketplace order.
     *
     * @return int How many rows were updated.
     *
     * @throws WooCommerceException
     */
    public function handle(Shop $shop): int
    {
        $connection = $shop->wooCommerceConnection;
        $client = new WooCommerceClient($connection);
        $since = $connection->marketplace_orders_checked_at?->subMinutes(self::OVERLAP_MINUTES)
            ?? now()->subDays(self::FIRST_DAYS);
        $startedAt = now();
        $updated = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $client->orderPage($page, ['modified_after' => $since->toIso8601String(), 'status' => 'any']);

            foreach ($result['orders'] as $order) {
                $updated += $this->copy($shop, $order) ? 1 : 0;
            }

            if ($result['orders'] === [] || ($result['pages'] !== null && $page >= $result['pages'])) {
                break;
            }
        }

        $connection->update(['marketplace_orders_checked_at' => $startedAt]);

        return $updated;
    }

    /**
     * Copy one order's delivery details onto its row.
     *
     * @param  array<string, mixed>  $order
     */
    private function copy(Shop $shop, array $order): bool
    {
        $marketplace = Marketplace::tryFrom((string) Meta::read($order, Meta::MARKETPLACE));
        $id = Meta::read($order, Meta::ORDER_ID);

        if ($marketplace === null || $id === null) {
            return false;
        }

        $row = $shop->marketplaceOrders()->forMarketplace($marketplace)->where('external_order_id', $id)->first();

        if ($row === null) {
            return false;
        }

        $expected = Meta::read($order, Meta::EXPECTED_DELIVERY_DATE);

        $row->update([
            'woocommerce_order_id' => $row->woocommerce_order_id ?? (int) $order['id'],
            'delivery_status' => Meta::read($order, Meta::DELIVERY_STATUS) ?: null,
            'tracking_code' => Meta::read($order, Meta::TRACKING_CODE) ?: null,
            'expected_delivery_on' => $expected !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) === 1 ? $expected : null,
            'woocommerce_modified_at' => is_string($order['date_modified_gmt'] ?? null)
                ? CarbonImmutable::parse($order['date_modified_gmt'].'Z')
                : null,
        ]);

        return true;
    }
}
