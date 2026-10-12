<?php
/**
 * Tests for the delivery state.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Delivery\Status;
use WooProductSyncManager\Orders\MarketplaceOrder;
use WooProductSyncManager\Orders\Meta;

/**
 * Covers how the delivery state follows the order.
 */
class StatusTest extends TestCase {

	/**
	 * A new order waiting to ship is in preparation.
	 *
	 * @return void
	 */
	public function test_processing_is_in_preparation() {
		$order = $this->marketplace_order();

		$this->assertSame( Status::IN_PREPARATION, MarketplaceOrder::from( $order )->delivery_status() );
	}

	/**
	 * Planzer's parcel number becomes the tracking code, and the order is in delivery.
	 *
	 * Planzer writes its number in a status of its own ("Transmit to Planzer"), so the
	 * code, not the status, is what says the parcel has left.
	 *
	 * @return void
	 */
	public function test_a_planzer_parcel_number_puts_the_order_in_delivery() {
		$order = $this->marketplace_order();

		$order->update_meta_data( Status::PLANZER_TRACKING_CODE, '41000123456789' );
		$order->set_status( 'planzer-transmit' );
		$order->save();

		$marketplace_order = MarketplaceOrder::from( wc_get_order( $order->get_id() ) );

		$this->assertSame( '41000123456789', $marketplace_order->tracking_code() );
		$this->assertSame( Status::IN_DELIVERY, $marketplace_order->delivery_status() );
	}

	/**
	 * A tracking code somebody typed in is not replaced by Planzer's.
	 *
	 * @return void
	 */
	public function test_an_existing_tracking_code_is_kept() {
		$order = $this->marketplace_order( Meta::NALDA, array( Meta::TRACKING_CODE => 'TYPED-1' ) );

		$order->update_meta_data( Status::PLANZER_TRACKING_CODE, '41000123456789' );
		$order->save();

		$this->assertSame( 'TYPED-1', MarketplaceOrder::from( wc_get_order( $order->get_id() ) )->tracking_code() );
	}

	/**
	 * Completed, cancelled and refunded orders are delivered, cancelled and returned.
	 *
	 * @return void
	 */
	public function test_final_statuses_map_to_final_states() {
		$expected = array(
			'completed' => Status::DELIVERED,
			'cancelled' => Status::CANCELLED,
			'refunded'  => Status::RETURNED,
		);

		foreach ( $expected as $status => $state ) {
			$order = $this->marketplace_order();
			$order->set_status( $status );
			$order->save();

			$this->assertSame( $state, MarketplaceOrder::from( wc_get_order( $order->get_id() ) )->delivery_status(), $status );
		}
	}

	/**
	 * A status that says nothing about delivery keeps the state the order had.
	 *
	 * @return void
	 */
	public function test_on_hold_keeps_the_current_state() {
		$this->assertSame( Status::IN_DELIVERY, Status::derive( 'on-hold', '', Status::IN_DELIVERY ) );
		$this->assertSame( Status::IN_PREPARATION, Status::derive( 'pending', '', '' ) );
	}

	/**
	 * A state chosen by hand is not overwritten.
	 *
	 * @return void
	 */
	public function test_a_manual_state_is_kept() {
		$order = $this->marketplace_order(
			Meta::NALDA,
			array(
				Meta::DELIVERY_STATUS        => Status::DISPUTE,
				Meta::DELIVERY_STATUS_MANUAL => 'yes',
			)
		);

		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( Status::DISPUTE, MarketplaceOrder::from( wc_get_order( $order->get_id() ) )->delivery_status() );
	}

	/**
	 * Ordinary shop orders are left alone.
	 *
	 * @return void
	 */
	public function test_shop_orders_get_no_state() {
		$order = $this->shop_order();
		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( Meta::DELIVERY_STATUS ) );
	}
}
