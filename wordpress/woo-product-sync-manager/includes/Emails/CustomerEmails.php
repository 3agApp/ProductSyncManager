<?php
/**
 * No customer emails for marketplace orders.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Emails;

use WooProductSyncManager\Orders\MarketplaceOrder;

defined( 'ABSPATH' ) || exit;

/**
 * Stops WooCommerce writing to anybody about a marketplace order.
 *
 * The marketplace owns the relationship with the buyer and sends its own messages.
 * The billing address belongs to the marketplace (Nalda's orders mailbox) or has no
 * email at all (Temu), so a WooCommerce customer email would either land in the
 * marketplace's inbox or go nowhere. Taking the recipient away stops every one of them
 * however the order is changed, from the screen, from REST or from another plugin.
 */
class CustomerEmails {

	/**
	 * The WooCommerce emails that are sent to the customer about an order.
	 *
	 * @var array<int, string>
	 */
	const EMAILS = array(
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_invoice',
		'customer_note',
		'customer_refunded_order',
		'customer_partially_refunded_order',
		'customer_cancelled_order',
		'customer_failed_order',
	);

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		foreach ( self::EMAILS as $email ) {
			add_filter( 'woocommerce_email_recipient_' . $email, array( $this, 'recipient' ), 10, 2 );
		}
	}

	/**
	 * Remove the recipient when the order came from a marketplace.
	 *
	 * @param string    $recipient Who the email would go to.
	 * @param \WC_Order $order     The order it is about.
	 * @return string
	 */
	public function recipient( $recipient, $order ) {
		return null === MarketplaceOrder::from( $order ) ? $recipient : '';
	}
}
