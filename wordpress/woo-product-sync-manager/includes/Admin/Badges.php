<?php
/**
 * Status badges.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Admin;

use WooProductSyncManager\Delivery\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the coloured status badges the column and the panel share.
 */
final class Badges {

	/**
	 * A badge for a delivery state, or an empty string when there is none.
	 *
	 * A state this plugin does not know is still shown, in grey, with its code made
	 * readable — Product Sync Manager may record a marketplace's state before the
	 * plugin learns its name.
	 *
	 * @param string $state Delivery state.
	 * @return string Escaped HTML.
	 */
	public static function delivery_status( $state ) {
		if ( '' === $state ) {
			return '';
		}

		$states = Status::states();
		$config = $states[ $state ] ?? array(
			'label'      => ucfirst( strtolower( str_replace( '_', ' ', $state ) ) ),
			'color'      => '#50575e',
			'background' => '#f0f0f1',
		);

		return self::badge( $config['label'], $config['color'], $config['background'] );
	}

	/**
	 * A badge for a payout state.
	 *
	 * @param string $state Payout state as the marketplace reports it.
	 * @return string Escaped HTML.
	 */
	public static function payout_status( $state ) {
		switch ( $state ) {
			case 'PAID_OUT':
				return self::badge( __( 'Paid out', 'woo-product-sync-manager' ), '#1e6b1e', '#eaf6ea' );
			case 'PAY_OUT_IN_PROGRESS':
				return self::badge( __( 'Payout in progress', 'woo-product-sync-manager' ), '#0a6b8a', '#e8f6fa' );
			case 'ERROR':
				return self::badge( __( 'Payout error', 'woo-product-sync-manager' ), '#a12622', '#fbeaea' );
			case '':
			case 'OPEN':
				return self::badge( __( 'Open', 'woo-product-sync-manager' ), '#9a6700', '#fef8e8' );
		}

		return self::badge( ucfirst( strtolower( str_replace( '_', ' ', $state ) ) ), '#50575e', '#f0f0f1' );
	}

	/**
	 * Build one badge.
	 *
	 * @param string $label      Text.
	 * @param string $color      Text colour.
	 * @param string $background Background colour.
	 * @return string Escaped HTML.
	 */
	private static function badge( $label, $color, $background ) {
		return sprintf(
			'<span class="wpsm-badge" style="color:%1$s;background:%2$s">%3$s</span>',
			esc_attr( $color ),
			esc_attr( $background ),
			esc_html( $label )
		);
	}
}
