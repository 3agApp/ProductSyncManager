<?php
/**
 * Marketplace details in the shop's own order emails.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Emails;

use WooProductSyncManager\Orders\MarketplaceOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the marketplace order and the buyer to the emails the shop receives.
 *
 * The "new order" email would otherwise show the marketplace as the customer and
 * nothing about who the parcel is for.
 */
class AdminDetails {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render' ), 10, 3 );
	}

	/**
	 * Print the details after the order table, in admin emails only.
	 *
	 * @param \WC_Order $order         The order.
	 * @param bool      $sent_to_admin Whether the email goes to the shop.
	 * @param bool      $plain_text    Whether the email is plain text.
	 * @return void
	 */
	public function render( $order, $sent_to_admin, $plain_text = false ) {
		$marketplace_order = MarketplaceOrder::from( $order );

		if ( ! $sent_to_admin || null === $marketplace_order ) {
			return;
		}

		$rows = $this->rows( $marketplace_order );

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( __( 'Marketplace details', 'woo-product-sync-manager' ) ) ) . "\n\n";

			foreach ( $rows as $label => $value ) {
				echo esc_html( $label . ': ' . $value ) . "\n";
			}

			return;
		}

		echo '<h2>' . esc_html__( 'Marketplace details', 'woo-product-sync-manager' ) . '</h2>';
		echo '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;margin-bottom:20px"><tbody>';

		foreach ( $rows as $label => $value ) {
			printf(
				'<tr><th scope="row" style="text-align:left">%1$s</th><td>%2$s</td></tr>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * The label and value pairs to show.
	 *
	 * @param MarketplaceOrder $marketplace_order The order.
	 * @return array<string, string>
	 */
	private function rows( MarketplaceOrder $marketplace_order ) {
		$order    = $marketplace_order->order();
		$money    = $marketplace_order->money();
		$currency = $order->get_currency();
		$rows     = array(
			__( 'Marketplace', 'woo-product-sync-manager' ) => $marketplace_order->marketplace_name(),
			__( 'Marketplace order', 'woo-product-sync-manager' ) => $marketplace_order->marketplace_order_id(),
		);

		if ( null !== $money->customer_total() ) {
			$rows[ __( 'Buyer paid', 'woo-product-sync-manager' ) ] = $this->amount( $money->customer_total(), $currency );
		}

		if ( null !== $money->commission() ) {
			$rows[ __( 'Commission', 'woo-product-sync-manager' ) ] = $this->amount( $money->commission(), $currency );
		}

		$rows[ __( 'Your revenue', 'woo-product-sync-manager' ) ] = $this->amount( $money->revenue(), $currency );

		if ( null !== $marketplace_order->ship_by() ) {
			$rows[ __( 'Ship by', 'woo-product-sync-manager' ) ] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $marketplace_order->ship_by()->getTimestamp() );
		}

		$address = array_filter(
			array(
				$marketplace_order->end_customer_name(),
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				trim( $order->get_shipping_postcode() . ' ' . $order->get_shipping_city() ),
				$order->get_shipping_country(),
			)
		);

		$rows[ __( 'Ship to', 'woo-product-sync-manager' ) ] = implode( ', ', $address );

		if ( '' !== $marketplace_order->end_customer_email() ) {
			$rows[ __( 'Buyer email', 'woo-product-sync-manager' ) ] = $marketplace_order->end_customer_email();
		}

		return $rows;
	}

	/**
	 * Format an amount as plain text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function amount( $amount, $currency ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}
}
