<?php
/**
 * The delivery note for a marketplace order.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Delivery;

use WooProductSyncManager\Orders\MarketplaceOrder;
use WooProductSyncManager\Orders\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Prints a delivery note to put in the parcel.
 *
 * The parcel goes to the marketplace's buyer, who has never heard of the shop's
 * billing arrangement with the marketplace, so the note names the buyer as the
 * recipient and the prices the buyer paid. It is an HTML page the browser prints or
 * saves as PDF: no PDF library to ship, and it prints the same on every machine.
 */
class Note {

	/**
	 * The admin-post action.
	 */
	const ACTION = 'wpsm_delivery_note';

	/**
	 * The languages the note can be printed in, by locale.
	 *
	 * The parcel's buyer may not read the shop's language, so the note offers both,
	 * whatever language wp-admin is in. The names are not translated: each is
	 * written the way its own readers look for it.
	 *
	 * @var array<string, string>
	 */
	const LANGUAGES = array(
		'de_DE' => 'Deutsch',
		'en_US' => 'English',
	);

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The URL that prints an order's delivery note.
	 *
	 * @param \WC_Order $order  The order.
	 * @param string    $locale One of LANGUAGES, or empty for wp-admin's language.
	 * @return string
	 */
	public static function url( \WC_Order $order, $locale = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action'   => self::ACTION,
						'order_id' => $order->get_id(),
						'lang'     => $locale,
					)
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * Print the note, after checking the request.
	 *
	 * @return void
	 */
	public function handle() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		check_admin_referer( self::ACTION . '_' . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to print delivery notes.', 'woo-product-sync-manager' ), '', array( 'response' => 403 ) );
		}

		$marketplace_order = MarketplaceOrder::from( wc_get_order( $order_id ) );

		if ( null === $marketplace_order ) {
			wp_die( esc_html__( 'This order did not come from a marketplace.', 'woo-product-sync-manager' ), '', array( 'response' => 404 ) );
		}

		$locale = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo $this->render( $marketplace_order, $locale ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The template escapes everything it prints.
		exit;
	}

	/**
	 * Build the note's HTML.
	 *
	 * @param MarketplaceOrder $marketplace_order The order.
	 * @param string           $locale            One of LANGUAGES, or empty for wp-admin's language.
	 * @return string
	 */
	public function render( MarketplaceOrder $marketplace_order, $locale = '' ) {
		$switched = isset( self::LANGUAGES[ $locale ] ) && switch_to_locale( $locale );

		try {
			return $this->render_in_current_locale( $marketplace_order );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Build the note's HTML in whatever language is active.
	 *
	 * @param MarketplaceOrder $marketplace_order The order.
	 * @return string
	 */
	private function render_in_current_locale( MarketplaceOrder $marketplace_order ) {
		$order = $marketplace_order->order();
		$note  = array(
			'order'          => $order,
			'marketplace'    => $marketplace_order,
			'store_name'     => get_bloginfo( 'name' ),
			'store_address'  => $this->store_address(),
			'logo_url'       => $this->logo_url(),
			'lines'          => $this->lines( $order ),
			'shipping_types' => $this->shipping_types(),
			'languages'      => $this->languages( $order ),
		);

		ob_start();
		include WPSM_PLUGIN_DIR . 'templates/delivery-note.php';

		return (string) ob_get_clean();
	}

	/**
	 * The language buttons: name, link, and whether it is the one showing.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, array{name: string, url: string, active: bool}>
	 */
	private function languages( \WC_Order $order ) {
		$current   = is_locale_switched() ? get_locale() : determine_locale();
		$languages = array();

		foreach ( self::LANGUAGES as $locale => $name ) {
			$languages[] = array(
				'name'   => $name,
				'url'    => self::url( $order, $locale ),
				'active' => strtok( $current, '_' ) === strtok( $locale, '_' ),
			);
		}

		return $languages;
	}

	/**
	 * The lines, priced at what the buyer paid where the marketplace said.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<int, array{sku: string, name: string, quantity: int, unit: float, total: float}>
	 */
	private function lines( \WC_Order $order ) {
		$lines = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$quantity = max( 1, (int) $item->get_quantity() );
			$paid     = $item->get_meta( Meta::ITEM_CUSTOMER_PRICE );
			$unit     = is_numeric( $paid ) ? (float) $paid : (float) $order->get_item_total( $item, true );
			$product  = $item->get_product();

			$lines[] = array(
				'sku'      => $product ? (string) $product->get_sku() : '',
				'name'     => $item->get_name(),
				'quantity' => $quantity,
				'unit'     => $unit,
				'total'    => $unit * $quantity,
			);
		}

		return $lines;
	}

	/**
	 * The shop's address, one line per part.
	 *
	 * @return array<int, string>
	 */
	private function store_address() {
		$countries = WC()->countries;
		$country   = $countries->get_base_country();

		return array_values(
			array_filter(
				array(
					$countries->get_base_address(),
					$countries->get_base_address_2(),
					trim( $countries->get_base_postcode() . ' ' . $countries->get_base_city() ),
					$countries->countries[ $country ] ?? $country,
				)
			)
		);
	}

	/**
	 * The site logo, or an empty string.
	 *
	 * @return string
	 */
	private function logo_url() {
		$logo = (int) get_theme_mod( 'custom_logo' );
		$url  = $logo ? wp_get_attachment_image_url( $logo, 'medium' ) : '';

		if ( ! $url ) {
			$url = (string) get_option( 'woocommerce_email_header_image', '' );
		}

		return (string) $url;
	}

	/**
	 * The names of the shop's enabled shipping methods, for the tick boxes.
	 *
	 * @return array<int, string>
	 */
	private function shipping_types() {
		$zones = array_map(
			static fn( $zone ) => new \WC_Shipping_Zone( $zone['id'] ),
			\WC_Shipping_Zones::get_zones()
		);

		$zones[] = new \WC_Shipping_Zone( 0 );
		$names   = array();

		foreach ( $zones as $zone ) {
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				$names[] = (string) $method->get_title();
			}
		}

		return array_values( array_unique( array_filter( $names ) ) );
	}
}
