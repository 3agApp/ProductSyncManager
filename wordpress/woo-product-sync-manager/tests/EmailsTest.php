<?php
/**
 * Tests for the emails.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Emails\AdminDetails;
use WooProductSyncManager\Orders\Meta;

/**
 * Covers who is written to about a marketplace order.
 */
class EmailsTest extends TestCase {

	/**
	 * Every customer email loses its recipient on a marketplace order.
	 *
	 * @return void
	 */
	public function test_customer_emails_have_no_recipient() {
		$order = $this->marketplace_order();

		foreach ( array( 'customer_processing_order', 'customer_completed_order', 'customer_invoice', 'customer_note' ) as $email ) {
			$this->assertSame( '', apply_filters( 'woocommerce_email_recipient_' . $email, 'orders@nalda.com', $order ), $email );
		}
	}

	/**
	 * Ordinary shop orders still email the customer.
	 *
	 * @return void
	 */
	public function test_shop_orders_keep_their_recipient() {
		$this->assertSame(
			'bert@example.com',
			apply_filters( 'woocommerce_email_recipient_customer_processing_order', 'bert@example.com', $this->shop_order() )
		);
	}

	/**
	 * The shop's own email names the marketplace order and the buyer.
	 *
	 * @return void
	 */
	public function test_admin_emails_carry_the_marketplace_details() {
		$order   = $this->marketplace_order( Meta::TEMU, array( Meta::SHIP_BY => '2026-10-13T22:30:00+00:00' ) );
		$details = new AdminDetails();

		ob_start();
		$details->render( $order, true, false );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'PO-192-13753655163510164', $html );
		$this->assertStringContainsString( 'Anna Muster', $html );
		$this->assertStringContainsString( 'Ship by', $html );

		ob_start();
		$details->render( $order, false, false );
		$this->assertSame( '', ob_get_clean(), 'Never in an email to the customer.' );
	}
}
