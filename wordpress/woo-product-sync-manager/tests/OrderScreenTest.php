<?php
/**
 * Tests for the orders list and the order panel.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Admin\OrderColumn;
use WooProductSyncManager\Admin\OrderPanel;
use WooProductSyncManager\Delivery\Status;
use WooProductSyncManager\Orders\MarketplaceOrder;
use WooProductSyncManager\Orders\Meta;

/**
 * Covers what staff see of a marketplace order and what they can change.
 */
class OrderScreenTest extends TestCase {

	/**
	 * The column sits right after the order status.
	 *
	 * @return void
	 */
	public function test_the_column_follows_the_status() {
		$columns = ( new OrderColumn() )->add_column(
			array(
				'order_number' => 'Order',
				'order_status' => 'Status',
				'order_total'  => 'Total',
			)
		);

		$this->assertSame( array( 'order_number', 'order_status', OrderColumn::COLUMN, 'order_total' ), array_keys( $columns ) );
	}

	/**
	 * The column names the marketplace and shows the delivery state.
	 *
	 * @return void
	 */
	public function test_the_column_shows_marketplace_and_state() {
		$order = $this->marketplace_order( Meta::TEMU );

		ob_start();
		( new OrderColumn() )->render_column( OrderColumn::COLUMN, $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Temu', $html );
		$this->assertStringContainsString( 'In preparation', $html );

		ob_start();
		( new OrderColumn() )->render_column( OrderColumn::COLUMN, $this->shop_order() );
		$this->assertSame( '&mdash;', ob_get_clean() );
	}

	/**
	 * The buyer is named beside the marketplace the order is billed to.
	 *
	 * @return void
	 */
	public function test_the_buyer_name_shows_the_buyer() {
		$column = new OrderColumn();

		$this->assertSame( 'Nalda (Anna Muster)', $column->buyer_name( 'Nalda Marketplace AG', $this->marketplace_order() ) );
		$this->assertSame( 'Bert', $column->buyer_name( 'Bert', $this->shop_order() ) );
	}

	/**
	 * The panel shows what the buyer paid, the commission and what the shop is paid.
	 *
	 * @return void
	 */
	public function test_the_panel_shows_the_amounts() {
		$order = $this->marketplace_order(
			Meta::NALDA,
			array(
				Meta::CUSTOMER_TOTAL => '99.80',
				Meta::COMMISSION     => '14.97',
				Meta::PAYOUT_STATUS  => 'PAID_OUT',
			)
		);

		ob_start();
		( new OrderPanel() )->render( $order );
		$html = ob_get_clean();

		$this->assertStringContainsString( '402687', $html );
		$this->assertStringContainsString( 'anna@example.com', $html );
		$this->assertMatchesRegularExpression( '/wpsm-panel__row--stacked"><span>Buyer email<\/span><span><a href="mailto:anna@example.com"/', $html, 'Long values get a line of their own.' );
		$this->assertStringContainsString( '99.80', $html );
		$this->assertStringContainsString( '15.0%', $html );
		$this->assertStringContainsString( 'Paid out', $html );
		$this->assertStringContainsString( 'Automatic (now: In preparation)', $html );
	}

	/**
	 * Choosing a state by hand saves it as manual; choosing automatic hands it back.
	 *
	 * @return void
	 */
	public function test_saving_the_panel() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = $this->marketplace_order();

		$_POST = array(
			OrderPanel::NONCE_FIELD       => wp_create_nonce( OrderPanel::NONCE_ACTION ),
			'wpsm_delivery_status'        => Status::DISPUTE,
			'wpsm_expected_delivery_date' => '2026-10-20',
			'wpsm_tracking_code'          => '99.00.123',
		);

		( new OrderPanel() )->save( $order->get_id() );

		$saved = MarketplaceOrder::from( wc_get_order( $order->get_id() ) );
		$this->assertSame( Status::DISPUTE, $saved->delivery_status() );
		$this->assertTrue( $saved->delivery_status_is_manual() );
		$this->assertSame( '2026-10-20', $saved->expected_delivery_date() );
		$this->assertSame( '99.00.123', $saved->tracking_code() );

		$_POST['wpsm_delivery_status'] = OrderPanel::AUTOMATIC;
		( new OrderPanel() )->save( $order->get_id() );

		$saved = MarketplaceOrder::from( wc_get_order( $order->get_id() ) );
		$this->assertFalse( $saved->delivery_status_is_manual() );
		$this->assertSame( Status::IN_DELIVERY, $saved->delivery_status(), 'Automatic again, and the order has a tracking code.' );
	}

	/**
	 * The marketplace's bookkeeping on order lines is not listed on the order screen.
	 *
	 * @return void
	 */
	public function test_line_bookkeeping_is_hidden() {
		$hidden = ( new OrderPanel() )->hide_item_meta( array( '_qty' ) );

		$this->assertContains( '_qty', $hidden );
		$this->assertContains( Meta::ITEM_GTIN, $hidden );
		$this->assertContains( Meta::ITEM_CUSTOMER_PRICE, $hidden );
		$this->assertContains( '_nalda_gtin', $hidden );
	}

	/**
	 * Nothing is saved without a valid nonce.
	 *
	 * @return void
	 */
	public function test_saving_needs_the_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = $this->marketplace_order();

		$_POST = array(
			OrderPanel::NONCE_FIELD => 'wrong',
			'wpsm_tracking_code'    => 'X',
		);

		( new OrderPanel() )->save( $order->get_id() );

		$this->assertSame( '', MarketplaceOrder::from( wc_get_order( $order->get_id() ) )->tracking_code() );
	}
}
