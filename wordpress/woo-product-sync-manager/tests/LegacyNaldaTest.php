<?php
/**
 * Tests for the migration of the old Nalda plugin's orders.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Delivery\Status;
use WooProductSyncManager\Migration\LegacyNalda;
use WooProductSyncManager\Orders\MarketplaceOrder;
use WooProductSyncManager\Orders\Meta;

/**
 * Covers copying `_nalda_*` meta onto this plugin's keys.
 */
class LegacyNaldaTest extends TestCase {

	/**
	 * An order as the old plugin left it: completed, but still IN_DELIVERY for Nalda.
	 *
	 * @return \WC_Order
	 */
	private function legacy_order() {
		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'Puzzle' );
		$item->set_quantity( 1 );
		$item->add_meta_data( '_nalda_gtin', '0195122352295' );
		$item->add_meta_data( '_nalda_customer_price', '29.90' );
		$item->add_meta_data( '_nalda_commission', '4.49' );
		$order->add_item( $item );

		foreach (
			array(
				'_nalda_order_id'           => '402687',
				'_nalda_end_customer_email' => 'anna@example.com',
				'_nalda_delivery_status'    => 'IN_DELIVERY',
				'_nalda_tracking_code'      => '99.00.123',
				'_nalda_original_total'     => '29.90',
				'_nalda_total_commission'   => '4.49',
				'_nalda_payout_status'      => 'PAY_OUT_IN_PROGRESS',
				'_nalda_created_at'         => '2026-10-01T10:00:00Z',
			) as $key => $value
		) {
			$order->update_meta_data( $key, $value );
		}

		$order->set_status( 'completed' );
		$order->save();

		return $order;
	}

	/**
	 * Every old key lands on its new key, and the old keys stay.
	 *
	 * @return void
	 */
	public function test_a_batch_copies_the_old_meta() {
		$order = $this->legacy_order();

		$this->assertSame( 1, ( new LegacyNalda() )->run() );

		$order             = wc_get_order( $order->get_id() );
		$marketplace_order = MarketplaceOrder::from( $order );
		$item              = current( $order->get_items() );

		$this->assertNotNull( $marketplace_order );
		$this->assertTrue( $marketplace_order->is_nalda() );
		$this->assertSame( '402687', $marketplace_order->marketplace_order_id() );
		$this->assertSame( 'anna@example.com', $marketplace_order->end_customer_email() );
		$this->assertSame( '99.00.123', $marketplace_order->tracking_code() );
		$this->assertSame( 4.49, $marketplace_order->money()->commission() );
		$this->assertSame( 'PAY_OUT_IN_PROGRESS', $marketplace_order->money()->payout_status() );
		$this->assertSame( '0195122352295', $item->get_meta( Meta::ITEM_GTIN ) );
		$this->assertSame( '29.90', $item->get_meta( Meta::ITEM_CUSTOMER_PRICE ) );
		$this->assertSame( '402687', $order->get_meta( '_nalda_order_id' ), 'The old keys are kept.' );
	}

	/**
	 * The copy keeps the old delivery state rather than working out a new one.
	 *
	 * The order is completed, which would otherwise make it DELIVERED and report that
	 * to Nalda for an order nobody touched.
	 *
	 * @return void
	 */
	public function test_the_copy_keeps_the_old_delivery_state() {
		$order = $this->legacy_order();

		( new LegacyNalda() )->run();

		$this->assertSame( Status::IN_DELIVERY, MarketplaceOrder::from( wc_get_order( $order->get_id() ) )->delivery_status() );
	}

	/**
	 * A kept state that the rules would change stays put on the next save.
	 *
	 * @return void
	 */
	public function test_a_state_the_rules_would_change_is_kept_as_manual() {
		$order = $this->legacy_order();

		( new LegacyNalda() )->run();

		$order = wc_get_order( $order->get_id() );
		$order->add_order_note( 'Packed again.' );
		$order->save();

		$marketplace_order = MarketplaceOrder::from( wc_get_order( $order->get_id() ) );

		$this->assertTrue( $marketplace_order->delivery_status_is_manual() );
		$this->assertSame( Status::IN_DELIVERY, $marketplace_order->delivery_status() );
	}

	/**
	 * A state the rules agree with stays automatic.
	 *
	 * @return void
	 */
	public function test_a_state_the_rules_agree_with_stays_automatic() {
		$order = $this->legacy_order();
		$order->update_meta_data( '_nalda_delivery_status', 'DELIVERED' );
		$order->save();

		( new LegacyNalda() )->run();

		$this->assertFalse( MarketplaceOrder::from( wc_get_order( $order->get_id() ) )->delivery_status_is_manual() );
	}

	/**
	 * Orders an earlier version copied are checked too, without copying them again.
	 *
	 * @return void
	 */
	public function test_orders_copied_before_are_checked() {
		$order = $this->legacy_order();
		$order->update_meta_data( Meta::MARKETPLACE, Meta::NALDA );
		$order->update_meta_data( Meta::DELIVERY_STATUS, 'RETURNED' );
		$order->update_meta_data( Meta::TRACKING_CODE, 'kept' );
		$order->set_status( 'cancelled' );
		Status::suspend();
		$order->save();
		Status::resume();

		$this->assertSame( 0, ( new LegacyNalda() )->run() );

		$marketplace_order = MarketplaceOrder::from( wc_get_order( $order->get_id() ) );

		$this->assertTrue( $marketplace_order->delivery_status_is_manual() );
		$this->assertSame( Status::RETURNED, $marketplace_order->delivery_status() );
		$this->assertSame( 'kept', $marketplace_order->tracking_code(), 'Nothing is copied again.' );
	}

	/**
	 * Running again copies nothing new, and the finished run records the version.
	 *
	 * @return void
	 */
	public function test_running_again_is_harmless() {
		delete_option( LegacyNalda::VERSION_OPTION );
		$this->legacy_order();

		$migration = new LegacyNalda();

		$this->assertSame( 1, $migration->run() );
		$this->assertSame( 0, $migration->run() );
		$this->assertSame( WPSM_DB_VERSION, get_option( LegacyNalda::VERSION_OPTION ) );
	}
}
