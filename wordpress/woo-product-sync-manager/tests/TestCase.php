<?php
/**
 * Shared test case.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Orders\Meta;

/**
 * Base class for the plugin's tests, with a factory for marketplace orders.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Start every test without request input or a logged-in user.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( 0 );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * Clear request input again, so a failing test cannot leak into the next file.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * Create an order the way Product Sync Manager imports one.
	 *
	 * @param string $marketplace Marketplace key.
	 * @param array  $meta        Order meta to add or override.
	 * @param string $status      Order status.
	 * @return \WC_Order
	 */
	protected function marketplace_order( $marketplace = Meta::NALDA, array $meta = array(), $status = 'processing' ) {
		$product = wc_get_product( wc_get_product_id_by_sku( 'TOY-100' ) );

		if ( ! $product ) {
			$product = new \WC_Product_Simple();
			$product->set_name( 'Wooden Train Set' );
			$product->set_sku( 'TOY-100' );
			$product->set_regular_price( '49.90' );
			$product->save();
		}

		$order = wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 2 );
		$item->set_subtotal( '84.80' );
		$item->set_total( '84.80' );
		$item->add_meta_data( Meta::ITEM_GTIN, '4006381333931' );
		$item->add_meta_data( Meta::ITEM_CUSTOMER_PRICE, '49.90' );
		$order->add_item( $item );

		$order->set_billing_company( Meta::NALDA === $marketplace ? 'Nalda Marketplace AG' : 'Temu' );
		$order->set_shipping_first_name( 'Anna' );
		$order->set_shipping_last_name( 'Muster' );
		$order->set_shipping_address_1( 'Bahnhofstrasse 1' );
		$order->set_shipping_postcode( '8001' );
		$order->set_shipping_city( 'Zürich' );
		$order->set_shipping_country( 'CH' );

		$defaults = array(
			Meta::MARKETPLACE        => $marketplace,
			Meta::ORDER_ID           => Meta::NALDA === $marketplace ? '402687' : 'PO-192-13753655163510164',
			Meta::END_CUSTOMER_EMAIL => 'anna@example.com',
		);

		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->calculate_totals( false );
		$order->set_status( $status );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Create an ordinary shop order.
	 *
	 * @return \WC_Order
	 */
	protected function shop_order() {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Bert' );
		$order->set_billing_email( 'bert@example.com' );
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}
}
