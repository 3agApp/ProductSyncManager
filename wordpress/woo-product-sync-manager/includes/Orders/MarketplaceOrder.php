<?php
/**
 * A WooCommerce order that came from a marketplace.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what Product Sync Manager recorded on an imported order.
 *
 * The column, the panel, the emails and the Planzer filter all ask this class (and
 * Money, for the amounts) rather than reading meta themselves.
 */
class MarketplaceOrder {

	/**
	 * The order being read.
	 *
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * Use from() instead.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function __construct( \WC_Order $order ) {
		$this->order = $order;
	}

	/**
	 * Read an order, or get null when it did not come from a marketplace.
	 *
	 * @param mixed $order An order, or anything else.
	 * @return self|null
	 */
	public static function from( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$marketplace = (string) $order->get_meta( Meta::MARKETPLACE );

		if ( ! array_key_exists( $marketplace, self::marketplaces() ) ) {
			return null;
		}

		return new self( $order );
	}

	/**
	 * The marketplaces this plugin knows, by key, with their display names.
	 *
	 * The names are brands and are not translated.
	 *
	 * @return array<string, string>
	 */
	public static function marketplaces() {
		return array(
			Meta::NALDA => 'Nalda',
			Meta::TEMU  => 'Temu',
		);
	}

	/**
	 * The order this reads.
	 *
	 * @return \WC_Order
	 */
	public function order() {
		return $this->order;
	}

	/**
	 * The marketplace key.
	 *
	 * @return string
	 */
	public function marketplace() {
		return (string) $this->order->get_meta( Meta::MARKETPLACE );
	}

	/**
	 * The marketplace's display name.
	 *
	 * @return string
	 */
	public function marketplace_name() {
		return self::marketplaces()[ $this->marketplace() ];
	}

	/**
	 * Whether the order came from Nalda.
	 *
	 * @return bool
	 */
	public function is_nalda() {
		return Meta::NALDA === $this->marketplace();
	}

	/**
	 * Whether the order came from Temu.
	 *
	 * @return bool
	 */
	public function is_temu() {
		return Meta::TEMU === $this->marketplace();
	}

	/**
	 * The marketplace's order number.
	 *
	 * @return string
	 */
	public function marketplace_order_id() {
		return (string) $this->order->get_meta( Meta::ORDER_ID );
	}

	/**
	 * The buyer's email address, when the marketplace shared one.
	 *
	 * @return string
	 */
	public function end_customer_email() {
		$email = (string) $this->order->get_meta( Meta::END_CUSTOMER_EMAIL );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * The buyer's name, from the shipping address.
	 *
	 * @return string
	 */
	public function end_customer_name() {
		return trim( $this->order->get_shipping_first_name() . ' ' . $this->order->get_shipping_last_name() );
	}

	/**
	 * The delivery state the marketplace is sent.
	 *
	 * @return string
	 */
	public function delivery_status() {
		return strtoupper( (string) $this->order->get_meta( Meta::DELIVERY_STATUS ) );
	}

	/**
	 * Whether the delivery state was chosen by hand.
	 *
	 * @return bool
	 */
	public function delivery_status_is_manual() {
		return 'yes' === $this->order->get_meta( Meta::DELIVERY_STATUS_MANUAL );
	}

	/**
	 * The expected delivery date, as Y-m-d, or an empty string.
	 *
	 * @return string
	 */
	public function expected_delivery_date() {
		return (string) $this->order->get_meta( Meta::EXPECTED_DELIVERY_DATE );
	}

	/**
	 * The tracking code, or an empty string.
	 *
	 * @return string
	 */
	public function tracking_code() {
		return (string) $this->order->get_meta( Meta::TRACKING_CODE );
	}

	/**
	 * The order's amounts: what the buyer paid, commission, payouts.
	 *
	 * @return Money
	 */
	public function money() {
		return new Money( $this->order );
	}

	/**
	 * The latest time the parcel has to leave, or null.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function ship_by() {
		return $this->time( Meta::SHIP_BY );
	}

	/**
	 * When the order was placed on the marketplace, or null.
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function placed_at() {
		return $this->time( Meta::PLACED_AT );
	}

	/**
	 * Read a stored time.
	 *
	 * @param string $key Meta key.
	 * @return \DateTimeImmutable|null
	 */
	private function time( $key ) {
		$value = (string) $this->order->get_meta( $key );

		if ( '' === $value ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Exception $exception ) {
			// A date nobody can read is shown as unknown, the same as a missing one:
			// it was written by Product Sync Manager, and the panel only displays it.
			return null;
		}
	}
}
