<?php
/**
 * The delivery state a marketplace is sent.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Delivery;

use WooProductSyncManager\Orders\MarketplaceOrder;
use WooProductSyncManager\Orders\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps an order's delivery state in step with what happens to it in WooCommerce.
 *
 * Product Sync Manager reads the state off the order and reports it to the
 * marketplace. It is worked out from the order every time the order is saved, so
 * nobody has to set it by hand:
 *
 * - processing, on its way to Planzer, is IN_PREPARATION;
 * - an order carrying a tracking code is IN_DELIVERY — Planzer writes the code the
 *   moment the parcel is handed over, whatever the order status;
 * - completed is DELIVERED, cancelled is CANCELLED and refunded is RETURNED.
 *
 * A state chosen in the order panel is kept: the manual flag stops this class from
 * touching it until somebody switches the order back to automatic.
 */
class Status {

	const IN_PREPARATION   = 'IN_PREPARATION';
	const READY_TO_COLLECT = 'READY_TO_COLLECT';
	const IN_DELIVERY      = 'IN_DELIVERY';
	const DELIVERED        = 'DELIVERED';
	const CANCELLED        = 'CANCELLED';
	const RETURNED         = 'RETURNED';
	const DISPUTE          = 'DISPUTE';

	/**
	 * The meta key the Planzer shipping plugin keeps its parcel number in.
	 */
	const PLANZER_TRACKING_CODE = 'planzer_tracking_code';

	/**
	 * Whether update() is switched off for the current request.
	 *
	 * @var bool
	 */
	private static $suspended = false;

	/**
	 * Stop working delivery states out until resume() is called.
	 *
	 * For a save that copies stored data rather than records something that happened,
	 * such as the migration of the old Nalda plugin's orders.
	 *
	 * @return void
	 */
	public static function suspend() {
		self::$suspended = true;
	}

	/**
	 * Work delivery states out again.
	 *
	 * @return void
	 */
	public static function resume() {
		self::$suspended = false;
	}

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_before_order_object_save', array( $this, 'update' ) );
	}

	/**
	 * Every state an order can be given, with its label and badge colours.
	 *
	 * @return array<string, array{label: string, color: string, background: string}>
	 */
	public static function states() {
		return array(
			self::IN_PREPARATION   => array(
				'label'      => __( 'In preparation', 'woo-product-sync-manager' ),
				'color'      => '#9a6700',
				'background' => '#fef8e8',
			),
			self::READY_TO_COLLECT => array(
				'label'      => __( 'Ready to collect', 'woo-product-sync-manager' ),
				'color'      => '#0a6b8a',
				'background' => '#e8f6fa',
			),
			self::IN_DELIVERY      => array(
				'label'      => __( 'In delivery', 'woo-product-sync-manager' ),
				'color'      => '#0a6b8a',
				'background' => '#e8f6fa',
			),
			self::DELIVERED        => array(
				'label'      => __( 'Delivered', 'woo-product-sync-manager' ),
				'color'      => '#1e6b1e',
				'background' => '#eaf6ea',
			),
			self::CANCELLED        => array(
				'label'      => __( 'Cancelled', 'woo-product-sync-manager' ),
				'color'      => '#a12622',
				'background' => '#fbeaea',
			),
			self::RETURNED         => array(
				'label'      => __( 'Returned', 'woo-product-sync-manager' ),
				'color'      => '#a12622',
				'background' => '#fbeaea',
			),
			self::DISPUTE          => array(
				'label'      => __( 'Dispute', 'woo-product-sync-manager' ),
				'color'      => '#a12622',
				'background' => '#fbeaea',
			),
		);
	}

	/**
	 * Bring a marketplace order's tracking code and delivery state up to date.
	 *
	 * Runs on every save, before the order is written, so the change lands in the same
	 * write as whatever caused it — a status change, Planzer's parcel number — and
	 * Product Sync Manager never sees one without the other.
	 *
	 * @param \WC_Order $order The order about to be saved.
	 * @return void
	 */
	public function update( $order ) {
		$marketplace_order = MarketplaceOrder::from( $order );

		if ( self::$suspended || null === $marketplace_order ) {
			return;
		}

		$planzer = trim( (string) $order->get_meta( self::PLANZER_TRACKING_CODE ) );

		if ( '' === $marketplace_order->tracking_code() && '' !== $planzer ) {
			$order->update_meta_data( Meta::TRACKING_CODE, $planzer );
		}

		if ( $marketplace_order->delivery_status_is_manual() ) {
			return;
		}

		$state = self::derive( $order->get_status(), $marketplace_order->tracking_code(), $marketplace_order->delivery_status() );

		if ( $state !== $marketplace_order->delivery_status() ) {
			$order->update_meta_data( Meta::DELIVERY_STATUS, $state );
		}
	}

	/**
	 * Work out the delivery state for an order status.
	 *
	 * A status that says nothing about delivery — on hold, failed, pending payment —
	 * keeps whatever state the order already has.
	 *
	 * @param string $status   WooCommerce order status, without the "wc-" prefix.
	 * @param string $tracking The order's tracking code.
	 * @param string $current  The state the order has now.
	 * @return string
	 */
	public static function derive( $status, $tracking, $current ) {
		switch ( $status ) {
			case 'completed':
				return self::DELIVERED;
			case 'cancelled':
				return self::CANCELLED;
			case 'refunded':
				return self::RETURNED;
		}

		if ( '' !== $tracking ) {
			return self::IN_DELIVERY;
		}

		if ( 'processing' === $status ) {
			return self::IN_PREPARATION;
		}

		return '' !== $current ? $current : self::IN_PREPARATION;
	}
}
