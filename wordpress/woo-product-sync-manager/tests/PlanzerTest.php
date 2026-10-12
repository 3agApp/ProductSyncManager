<?php
/**
 * Tests for the Planzer shipping file.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Integrations\Planzer;
use WooProductSyncManager\Orders\Meta;

/**
 * Covers the buyer's email going into Planzer's file.
 */
class PlanzerTest extends TestCase {

	/**
	 * A Planzer file: group A with 87 fields, then groups P and O.
	 *
	 * @param string $email What field A1_54 holds.
	 * @param string $flag  What field A1_55 holds.
	 * @return string
	 */
	private function csv( $email, $flag = '' ) {
		$group_a      = array_fill( 0, 87, 'x' );
		$group_a[54]  = $email;
		$group_a[55]  = $flag;
		$group_a_line = implode( ';', $group_a );

		return $group_a_line . "\r\nP;1;2\r\nO;3;4";
	}

	/**
	 * The buyer's email replaces the marketplace's, and email notification is switched on.
	 *
	 * @return void
	 */
	public function test_the_buyer_email_goes_into_the_file() {
		$content = apply_filters( 'planzer/csv/content', $this->csv( 'orders@nalda.com' ), $this->marketplace_order() );
		$fields  = explode( ';', explode( "\r\n", $content )[0] );

		$this->assertSame( 'anna@example.com', $fields[ Planzer::EMAIL_FIELD ] );
		$this->assertSame( 'B', $fields[ Planzer::NOTIFY_FIELD ] );
		$this->assertStringEndsWith( "\r\nP;1;2\r\nO;3;4", $content );
	}

	/**
	 * A notification setting already in the file is kept.
	 *
	 * @return void
	 */
	public function test_an_existing_notification_setting_is_kept() {
		$content = ( new Planzer() )->content( $this->csv( '', 'S' ), $this->marketplace_order( Meta::TEMU ) );

		$this->assertSame( 'S', explode( ';', $content )[ Planzer::NOTIFY_FIELD ] );
	}

	/**
	 * Shop orders, orders without a buyer email and short files are left alone.
	 *
	 * @return void
	 */
	public function test_other_files_are_left_alone() {
		$planzer = new Planzer();
		$csv     = $this->csv( 'bert@example.com' );

		$this->assertSame( $csv, $planzer->content( $csv, $this->shop_order() ) );
		$this->assertSame( $csv, $planzer->content( $csv, $this->marketplace_order( Meta::TEMU, array( Meta::END_CUSTOMER_EMAIL => '' ) ) ) );
		$this->assertSame( 'a;b', $planzer->content( 'a;b', $this->marketplace_order() ) );
	}
}
