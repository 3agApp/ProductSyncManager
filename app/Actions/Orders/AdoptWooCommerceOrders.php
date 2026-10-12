<?php

namespace App\Actions\Orders;

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Models\Shop;
use App\Services\WooCommerce\Exceptions\WooCommerceException;
use App\Services\WooCommerce\WooCommerceClient;
use App\Support\MarketplaceOrderMeta as Meta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Records the marketplace orders a store already has, so none is imported twice.
 *
 * Before this app imported Nalda orders, the old "Nalda Marketplace Sync"
 * plugin did, under `_nalda_*` meta; the Product Sync Manager plugin copies
 * those onto `_wpsm_*` keys. Every order carrying either is recorded here as
 * imported. Nothing in the store is changed.
 */
class AdoptWooCommerceOrders
{
    /**
     * The most pages of a hundred orders read.
     */
    private const int MAX_PAGES = 100;

    /**
     * Record the store's marketplace orders created since a date.
     *
     * @return int How many orders were newly recorded.
     *
     * @throws WooCommerceException
     */
    public function handle(Shop $shop, CarbonInterface $since): int
    {
        $client = new WooCommerceClient($shop->wooCommerceConnection);
        $adopted = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $client->orderPage($page, ['after' => $since->toIso8601String(), 'status' => 'any']);

            foreach ($result['orders'] as $order) {
                $adopted += $this->adopt($shop, $order) ? 1 : 0;
            }

            if ($result['orders'] === [] || ($result['pages'] !== null && $page >= $result['pages'])) {
                break;
            }
        }

        return $adopted;
    }

    /**
     * Record one WooCommerce order, when it came from a marketplace.
     *
     * @param  array<string, mixed>  $order
     */
    private function adopt(Shop $shop, array $order): bool
    {
        $marketplace = Marketplace::tryFrom((string) Meta::read($order, Meta::MARKETPLACE));
        $id = Meta::read($order, Meta::ORDER_ID);

        if ($id === null && Meta::read($order, '_nalda_order_id') !== null) {
            $marketplace = Marketplace::Nalda;
            $id = Meta::read($order, '_nalda_order_id');
        }

        if ($marketplace === null || $id === null || $id === '') {
            return false;
        }

        $read = fn (string $key, string $legacy): ?string => Meta::read($order, $key) ?? Meta::read($order, $legacy);
        $delivery = $read(Meta::DELIVERY_STATUS, '_nalda_delivery_status');
        $expected = $read(Meta::EXPECTED_DELIVERY_DATE, '_nalda_expected_delivery_date');

        $row = $shop->marketplaceOrders()->firstOrCreate(
            ['marketplace' => $marketplace, 'external_order_id' => $id],
            [
                'marketplace_status' => $delivery ?? '',
                'status' => MarketplaceOrderStatus::Imported,
                'woocommerce_order_id' => (int) $order['id'],
                'customer_total' => $read(Meta::CUSTOMER_TOTAL, '_nalda_original_total'),
                'commission' => $read(Meta::COMMISSION, '_nalda_total_commission'),
                'payout_status' => $read(Meta::PAYOUT_STATUS, '_nalda_payout_status'),
                'delivery_status' => $delivery,
                'tracking_code' => $read(Meta::TRACKING_CODE, '_nalda_tracking_code'),
                'gtins' => $this->gtins($order),
                'expected_delivery_on' => $expected !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) === 1 ? $expected : null,
                'ordered_at' => is_string($order['date_created_gmt'] ?? null) ? CarbonImmutable::parse($order['date_created_gmt'].'Z') : null,
                'imported_at' => now(),
            ],
        );

        return $row->wasRecentlyCreated;
    }

    /**
     * The GTINs on a WooCommerce order's lines.
     *
     * @param  array<string, mixed>  $order
     * @return array<int, string>
     */
    private function gtins(array $order): array
    {
        $gtins = [];

        foreach (is_array($order['line_items'] ?? null) ? $order['line_items'] : [] as $line) {
            $gtin = is_array($line) ? (Meta::read($line, Meta::ITEM_GTIN) ?? Meta::read($line, '_nalda_gtin')) : null;

            if ($gtin !== null && $gtin !== '') {
                $gtins[] = $gtin;
            }
        }

        return array_values(array_unique($gtins));
    }
}
