<?php

namespace App\Support;

use App\Enums\Marketplace;
use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\Shop;

/**
 * The recent orders a marketplace page lists.
 */
final class MarketplaceOrderList
{
    /**
     * The number of orders shown.
     */
    private const int LIMIT = 20;

    /**
     * Build the page props for one marketplace's recent orders.
     *
     * Orders that need attention come first, however old they are.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function props(Shop $shop, Marketplace $marketplace): array
    {
        $storeUrl = $shop->wooCommerceConnection?->store_url;

        return $shop->marketplaceOrders()
            ->forMarketplace($marketplace)
            ->orderByRaw('case when status in (?, ?) then 0 else 1 end', [MarketplaceOrderStatus::Creating->value, MarketplaceOrderStatus::Failed->value])
            ->latest('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (MarketplaceOrder $order) => [
                'id' => $order->id,
                'externalOrderId' => $order->external_order_id,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'marketplaceStatus' => $order->describeMarketplaceStatus(),
                'deliveryStatus' => $order->delivery_status,
                'needsAttention' => $order->status->needsAttention(),
                'message' => $order->message,
                'orderedAt' => $order->ordered_at?->toISOString(),
                'shipByAt' => $order->ship_by_at?->toISOString(),
                'wooCommerceOrderId' => $order->woocommerce_order_id,
                'wooCommerceUrl' => $order->woocommerce_order_id !== null && $storeUrl !== null
                    ? rtrim($storeUrl, '/').'/wp-admin/admin.php?page=wc-orders&action=edit&id='.$order->woocommerce_order_id
                    : null,
            ])
            ->all();
    }
}
