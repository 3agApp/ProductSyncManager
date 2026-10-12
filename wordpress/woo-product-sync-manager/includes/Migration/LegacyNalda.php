<?php
/**
 * Orders imported by the old Nalda plugin.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Migration;

use WooProductSyncManager\Delivery\Status;
use WooProductSyncManager\Orders\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Copies what the old "WooCommerce Nalda Marketplace Sync" plugin stored onto the keys
 * this plugin reads.
 *
 * That plugin imported Nalda orders before Product Sync Manager did, under `_nalda_*`
 * keys. Copying them means its orders show in the same column and panel as every new
 * one, and Product Sync Manager keeps reporting their delivery state to Nalda. The old
 * keys are left in place, so nothing is lost and the old plugin keeps working until it
 * is switched off.
 *
 * The copy runs in the background, through Action Scheduler, a hundred orders at a
 * time, and only touches orders that have not been copied yet. Running it again is
 * harmless.
 */
class LegacyNalda {

	/**
	 * The Action Scheduler hook a batch runs on.
	 */
	const HOOK = 'wpsm_migrate_legacy_nalda';

	/**
	 * The option holding the data version the site has been migrated to.
	 */
	const VERSION_OPTION = 'wpsm_db_version';

	/**
	 * Orders copied per batch.
	 */
	const BATCH = 100;

	/**
	 * Order meta: old key => new key.
	 *
	 * @var array<string, string>
	 */
	const ORDER_KEYS = array(
		'_nalda_order_id'               => Meta::ORDER_ID,
		'_nalda_end_customer_email'     => Meta::END_CUSTOMER_EMAIL,
		'_nalda_delivery_status'        => Meta::DELIVERY_STATUS,
		'_nalda_expected_delivery_date' => Meta::EXPECTED_DELIVERY_DATE,
		'_nalda_tracking_code'          => Meta::TRACKING_CODE,
		'_nalda_original_total'         => Meta::CUSTOMER_TOTAL,
		'_nalda_total_commission'       => Meta::COMMISSION,
		'_nalda_payout_status'          => Meta::PAYOUT_STATUS,
		'_nalda_created_at'             => Meta::PLACED_AT,
	);

	/**
	 * Item meta: old key => new key.
	 *
	 * @var array<string, string>
	 */
	const ITEM_KEYS = array(
		'_nalda_gtin'           => Meta::ITEM_GTIN,
		'_nalda_customer_price' => Meta::ITEM_CUSTOMER_PRICE,
		'_nalda_commission'     => Meta::ITEM_COMMISSION,
	);

	/**
	 * Register the batch hook, and queue the first batch when the site is behind.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'maybe_queue' ) );
	}

	/**
	 * Queue the first batch, once, when the stored version is older than the plugin's.
	 *
	 * @return void
	 */
	public function maybe_queue() {
		if ( get_option( self::VERSION_OPTION ) === WPSM_DB_VERSION || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK ) ) {
			as_enqueue_async_action( self::HOOK, array(), 'woo-product-sync-manager' );
		}
	}

	/**
	 * Copy one batch, then queue the next or record that the site is done.
	 *
	 * @return int How many orders were copied.
	 */
	public function run() {
		$orders = wc_get_orders(
			array(
				'limit'      => self::BATCH,
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A one-off background migration, a hundred orders at a time.
					array(
						'key'     => '_nalda_order_id',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Meta::MARKETPLACE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			$this->copy( $order );
		}

		if ( count( $orders ) === self::BATCH ) {
			as_enqueue_async_action( self::HOOK, array(), 'woo-product-sync-manager' );
		} else {
			update_option( self::VERSION_OPTION, WPSM_DB_VERSION, false );
		}

		return count( $orders );
	}

	/**
	 * Copy one order's old meta onto the new keys.
	 *
	 * The delivery state is copied as the old plugin last had it. Status would
	 * otherwise work a new one out on this save, and report it to Nalda, for an order
	 * nobody touched; it only takes over from the next real change.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	public function copy( \WC_Order $order ) {
		$order->update_meta_data( Meta::MARKETPLACE, Meta::NALDA );

		foreach ( self::ORDER_KEYS as $old => $new ) {
			$value = $order->get_meta( $old );

			if ( '' !== $value && null !== $value ) {
				$order->update_meta_data( $new, $value );
			}
		}

		foreach ( $order->get_items() as $item ) {
			foreach ( self::ITEM_KEYS as $old => $new ) {
				$value = $item->get_meta( $old );

				if ( '' !== $value && null !== $value ) {
					$item->update_meta_data( $new, $value );
				}
			}

			$item->save();
		}

		Status::suspend();

		try {
			$order->save();
		} finally {
			Status::resume();
		}
	}
}
