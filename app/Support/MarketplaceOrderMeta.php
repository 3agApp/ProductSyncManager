<?php

namespace App\Support;

/**
 * The WooCommerce order meta marketplace orders carry.
 *
 * The Product Sync Manager plugin reads these keys to show the order and
 * writes the delivery ones back, so they must be spelled exactly as the
 * plugin's WooProductSyncManager\Orders\Meta spells them.
 */
final class MarketplaceOrderMeta
{
    public const string MARKETPLACE = '_wpsm_marketplace';

    public const string ORDER_ID = '_wpsm_marketplace_order_id';

    public const string END_CUSTOMER_EMAIL = '_wpsm_end_customer_email';

    public const string DELIVERY_STATUS = '_wpsm_delivery_status';

    public const string EXPECTED_DELIVERY_DATE = '_wpsm_expected_delivery_date';

    public const string TRACKING_CODE = '_wpsm_tracking_code';

    public const string CUSTOMER_TOTAL = '_wpsm_customer_total';

    public const string COMMISSION = '_wpsm_commission';

    public const string PAYOUT_STATUS = '_wpsm_payout_status';

    public const string SHIPPING_PAYOUT = '_wpsm_shipping_payout';

    public const string SHIP_BY = '_wpsm_ship_by';

    public const string PLACED_AT = '_wpsm_placed_at';

    public const string ITEM_GTIN = '_wpsm_gtin';

    public const string ITEM_CUSTOMER_PRICE = '_wpsm_customer_price';

    public const string ITEM_COMMISSION = '_wpsm_commission';

    public const string ITEM_MARKETPLACE_ID = '_wpsm_marketplace_item_id';

    /**
     * Turn key => value pairs into WooCommerce's meta_data list, leaving out
     * empty values.
     *
     * @param  array<string, string|int|float|null>  $values
     * @return array<int, array{key: string, value: string}>
     */
    public static function list(array $values): array
    {
        $list = [];

        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '') {
                $list[] = ['key' => $key, 'value' => (string) $value];
            }
        }

        return $list;
    }

    /**
     * Read one value out of a WooCommerce order's meta_data list.
     *
     * @param  array<string, mixed>  $order
     */
    public static function read(array $order, string $key): ?string
    {
        foreach (is_array($order['meta_data'] ?? null) ? $order['meta_data'] : [] as $meta) {
            if (is_array($meta) && ($meta['key'] ?? null) === $key) {
                return is_scalar($meta['value'] ?? null) ? (string) $meta['value'] : null;
            }
        }

        return null;
    }
}
