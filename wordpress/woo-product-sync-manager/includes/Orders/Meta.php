<?php
/**
 * The meta keys marketplace orders carry.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Names every order and item meta key Product Sync Manager writes.
 *
 * Product Sync Manager creates marketplace orders over the WooCommerce REST API and
 * writes these keys; the Laravel app spells them the same way. This is the one place
 * the plugin names them, so a key can never be spelled two ways.
 */
final class Meta {

	/**
	 * Which marketplace the order came from: "nalda" or "temu".
	 */
	const MARKETPLACE = '_wpsm_marketplace';

	/**
	 * The marketplace's own order number.
	 */
	const ORDER_ID = '_wpsm_marketplace_order_id';

	/**
	 * The buyer's email, kept off the billing address so WooCommerce never writes to it.
	 */
	const END_CUSTOMER_EMAIL = '_wpsm_end_customer_email';

	/**
	 * The delivery state the marketplace is sent, in Nalda's vocabulary.
	 */
	const DELIVERY_STATUS = '_wpsm_delivery_status';

	/**
	 * "yes" when somebody chose the delivery state by hand.
	 */
	const DELIVERY_STATUS_MANUAL = '_wpsm_delivery_status_manual';

	/**
	 * Expected delivery date, as Y-m-d.
	 */
	const EXPECTED_DELIVERY_DATE = '_wpsm_expected_delivery_date';

	/**
	 * The parcel's tracking code.
	 */
	const TRACKING_CODE = '_wpsm_tracking_code';

	/**
	 * What the buyer paid the marketplace.
	 */
	const CUSTOMER_TOTAL = '_wpsm_customer_total';

	/**
	 * The marketplace's commission (Nalda).
	 */
	const COMMISSION = '_wpsm_commission';

	/**
	 * The marketplace's payout state for the order (Nalda).
	 */
	const PAYOUT_STATUS = '_wpsm_payout_status';

	/**
	 * What the marketplace pays for shipping (Temu).
	 */
	const SHIPPING_PAYOUT = '_wpsm_shipping_payout';

	/**
	 * Latest time the parcel has to leave, as ISO 8601 (Temu).
	 */
	const SHIP_BY = '_wpsm_ship_by';

	/**
	 * When the order was placed on the marketplace, as ISO 8601.
	 */
	const PLACED_AT = '_wpsm_placed_at';

	/**
	 * Item meta: the GTIN the marketplace sold.
	 */
	const ITEM_GTIN = '_wpsm_gtin';

	/**
	 * Item meta: the price the buyer paid per unit.
	 */
	const ITEM_CUSTOMER_PRICE = '_wpsm_customer_price';

	/**
	 * Item meta: the marketplace's commission on the line.
	 */
	const ITEM_COMMISSION = '_wpsm_commission';

	/**
	 * Item meta: the marketplace's own number for the line (Temu's orderSn).
	 */
	const ITEM_MARKETPLACE_ID = '_wpsm_marketplace_item_id';

	/**
	 * Nalda.
	 */
	const NALDA = 'nalda';

	/**
	 * Temu.
	 */
	const TEMU = 'temu';
}
