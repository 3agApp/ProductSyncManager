<?php
/**
 * The amounts on a marketplace order.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what the buyer paid and what the marketplace pays the shop.
 *
 * The order total is what the shop is paid: Product Sync Manager writes the lines
 * net of commission (Nalda) or at the base price plus the shipping Temu pays.
 */
class Money {

	/**
	 * The order being read.
	 *
	 * @var \WC_Order
	 */
	private $order;

	/**
	 * Read an order's amounts.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function __construct( \WC_Order $order ) {
		$this->order = $order;
	}

	/**
	 * What the buyer paid, or null when it is not known.
	 *
	 * @return float|null
	 */
	public function customer_total() {
		return $this->amount( Meta::CUSTOMER_TOTAL );
	}

	/**
	 * The marketplace's commission, or null when it takes none.
	 *
	 * @return float|null
	 */
	public function commission() {
		return $this->amount( Meta::COMMISSION );
	}

	/**
	 * The commission as a share of what the buyer paid, in percent.
	 *
	 * @return float|null
	 */
	public function commission_percent() {
		$total      = $this->customer_total();
		$commission = $this->commission();

		if ( null === $total || null === $commission || $total <= 0 ) {
			return null;
		}

		return $commission / $total * 100;
	}

	/**
	 * The marketplace's payout state, as it reports it.
	 *
	 * @return string
	 */
	public function payout_status() {
		return strtoupper( (string) $this->order->get_meta( Meta::PAYOUT_STATUS ) );
	}

	/**
	 * What the marketplace pays for shipping, or null.
	 *
	 * @return float|null
	 */
	public function shipping_payout() {
		return $this->amount( Meta::SHIPPING_PAYOUT );
	}

	/**
	 * What the shop is paid: the order total, which is already net of commission.
	 *
	 * @return float
	 */
	public function revenue() {
		return (float) $this->order->get_total();
	}

	/**
	 * Read a stored amount.
	 *
	 * @param string $key Meta key.
	 * @return float|null
	 */
	private function amount( $key ) {
		$value = $this->order->get_meta( $key );

		return is_numeric( $value ) ? (float) $value : null;
	}
}
