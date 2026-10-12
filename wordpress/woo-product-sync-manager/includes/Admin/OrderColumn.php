<?php
/**
 * Marketplace orders on the orders screen.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Admin;

use WooProductSyncManager\Orders\MarketplaceOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Marks marketplace orders in the orders list.
 *
 * The order is billed to the marketplace, so WooCommerce would list every one of them
 * under "Nalda Marketplace" or "Temu". The buyer's name is put back beside it, and a
 * column shows the marketplace and the delivery state it is being sent.
 *
 * Both list hooks are the High-Performance Order Storage ones: the plugin does not
 * boot without HPOS, so there is no legacy posts-screen path.
 */
class OrderColumn {

	/**
	 * The column key.
	 */
	const COLUMN = 'wpsm_marketplace';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'woocommerce_admin_order_buyer_name', array( $this, 'buyer_name' ), 10, 2 );
	}

	/**
	 * Add the marketplace column after the order status.
	 *
	 * @param array $columns Existing columns.
	 * @return array Columns, with ours inserted.
	 */
	public function add_column( $columns ) {
		$inserted = array();

		foreach ( $columns as $key => $label ) {
			$inserted[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$inserted[ self::COLUMN ] = __( 'Marketplace', 'woo-product-sync-manager' );
			}
		}

		if ( ! isset( $inserted[ self::COLUMN ] ) ) {
			$inserted[ self::COLUMN ] = __( 'Marketplace', 'woo-product-sync-manager' );
		}

		return $inserted;
	}

	/**
	 * Print the marketplace column for one order.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  The order.
	 * @return void
	 */
	public function render_column( $column, $order ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$marketplace_order = MarketplaceOrder::from( $order );

		if ( null === $marketplace_order ) {
			echo '&mdash;';

			return;
		}

		printf(
			'<span class="wpsm-marketplace wpsm-marketplace--%1$s">%2$s</span>',
			esc_attr( $marketplace_order->marketplace() ),
			esc_html( $marketplace_order->marketplace_name() )
		);

		$badge = Badges::delivery_status( $marketplace_order->delivery_status() );

		if ( '' !== $badge ) {
			echo '<br>' . $badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badges escapes every part it builds.
		}
	}

	/**
	 * Show the buyer beside the marketplace the order is billed to.
	 *
	 * @param string    $buyer_name The name WooCommerce would show.
	 * @param \WC_Order $order      The order.
	 * @return string
	 */
	public function buyer_name( $buyer_name, $order ) {
		$marketplace_order = MarketplaceOrder::from( $order );

		if ( null === $marketplace_order ) {
			return $buyer_name;
		}

		$name = $marketplace_order->end_customer_name();

		if ( '' === $name ) {
			return $marketplace_order->marketplace_name();
		}

		return sprintf(
			/* translators: 1: marketplace name, for example "Nalda", 2: the buyer's name. */
			__( '%1$s (%2$s)', 'woo-product-sync-manager' ),
			$marketplace_order->marketplace_name(),
			$name
		);
	}
}
