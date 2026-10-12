<?php
/**
 * The buyer's email in the Planzer shipping file.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Integrations;

use WooProductSyncManager\Orders\MarketplaceOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Lets Planzer tell the marketplace's buyer when their parcel is on its way.
 *
 * The "Shipping via Planzer" plugin builds a CSV for each parcel and uses the order's
 * billing email as the address for delivery notifications. On a marketplace order
 * that is the marketplace's mailbox (Nalda) or nothing (Temu), so the buyer's own
 * address is put into the file just before it is uploaded. Only the file changes; the
 * order's billing email is left alone, so WooCommerce still writes to nobody.
 */
class Planzer {

	/**
	 * Index of the receiver email (A1_54) in the file's first line.
	 */
	const EMAIL_FIELD = 54;

	/**
	 * Index of the receiver notification flag (A1_55) in the file's first line.
	 */
	const NOTIFY_FIELD = 55;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'planzer/csv/content', array( $this, 'content' ), 10, 2 );
	}

	/**
	 * Swap the buyer's email into the receiver fields.
	 *
	 * The file is three CRLF-separated lines (groups A, P and O) of ';'-separated
	 * fields. Group A carries 87 fields; anything shorter is left untouched rather than
	 * edited at a guessed position.
	 *
	 * @param string    $content The CSV Planzer is about to upload.
	 * @param \WC_Order $order   The order being shipped.
	 * @return string
	 */
	public function content( $content, $order ) {
		$marketplace_order = MarketplaceOrder::from( $order );

		if ( null === $marketplace_order || '' === $marketplace_order->end_customer_email() ) {
			return $content;
		}

		$lines = explode( "\r\n", (string) $content );

		if ( '' === $lines[0] ) {
			return $content;
		}

		$fields = explode( ';', $lines[0] );

		if ( count( $fields ) <= self::NOTIFY_FIELD ) {
			return $content;
		}

		$fields[ self::EMAIL_FIELD ] = $marketplace_order->end_customer_email();

		// "B" asks Planzer to notify the receiver by email.
		if ( '' === $fields[ self::NOTIFY_FIELD ] ) {
			$fields[ self::NOTIFY_FIELD ] = 'B';
		}

		$lines[0] = implode( ';', $fields );

		return implode( "\r\n", $lines );
	}
}
