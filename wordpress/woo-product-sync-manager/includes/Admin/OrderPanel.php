<?php
/**
 * The marketplace panel on the order screen.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WooProductSyncManager\Delivery\Note;
use WooProductSyncManager\Delivery\Status;
use WooProductSyncManager\Orders\MarketplaceOrder;
use WooProductSyncManager\Orders\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Shows what the marketplace knows about an order, and lets staff correct the
 * delivery details it is sent.
 *
 * Everything above the line is read-only: it is what Product Sync Manager imported.
 * The delivery state, expected date and tracking code below it are what Product Sync
 * Manager reports back to the marketplace.
 */
class OrderPanel {

	/**
	 * Nonce action for the panel's fields.
	 */
	const NONCE_ACTION = 'wpsm_order_panel';

	/**
	 * Nonce field name.
	 */
	const NONCE_FIELD = 'wpsm_order_panel_nonce';

	/**
	 * Select value meaning "work the state out automatically".
	 */
	const AUTOMATIC = 'automatic';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );

		/*
		 * After WooCommerce's own order save, which runs at 40: that save may change
		 * the status, and Status works the delivery state out from it. A state chosen
		 * here has to be written last to win.
		 */
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save' ), 50 );

		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_item_meta' ) );
	}

	/**
	 * Keep the bookkeeping on order lines out of the order screen.
	 *
	 * The order screen lists every item meta key, underscored or not, unless it is
	 * declared hidden. The marketplace's GTIN, price and commission are for Product
	 * Sync Manager and the panel, and the old Nalda plugin's copies of them are only
	 * kept for the record.
	 *
	 * @param array $hidden Keys WooCommerce already hides.
	 * @return array
	 */
	public function hide_item_meta( $hidden ) {
		return array_merge(
			(array) $hidden,
			array(
				Meta::ITEM_GTIN,
				Meta::ITEM_CUSTOMER_PRICE,
				Meta::ITEM_COMMISSION,
				Meta::ITEM_MARKETPLACE_ID,
				'_nalda_gtin',
				'_nalda_customer_price',
				'_nalda_original_price',
				'_nalda_commission',
				'_nalda_net_price',
			)
		);
	}

	/**
	 * Add the panel, on marketplace orders only.
	 *
	 * @param string             $screen        Screen ID.
	 * @param \WP_Post|\WC_Order $post_or_order The order being edited.
	 * @return void
	 */
	public function add_meta_box( $screen, $post_or_order = null ) {
		if ( OrderUtil::get_order_admin_screen() !== $screen ) {
			return;
		}

		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : null;

		if ( null === MarketplaceOrder::from( $order ) ) {
			return;
		}

		add_meta_box(
			'wpsm-marketplace-order',
			__( 'Marketplace', 'woo-product-sync-manager' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Print the panel.
	 *
	 * @param \WC_Order $order The order being edited.
	 * @return void
	 */
	public function render( $order ) {
		$marketplace_order = MarketplaceOrder::from( $order );

		if ( null === $marketplace_order ) {
			return;
		}

		$money    = $marketplace_order->money();
		$currency = array( 'currency' => $order->get_currency() );

		echo '<div class="wpsm-panel">';

		$this->row(
			sprintf(
				/* translators: %s: marketplace name, for example "Nalda". */
				__( '%s order', 'woo-product-sync-manager' ),
				$marketplace_order->marketplace_name()
			),
			esc_html( $marketplace_order->marketplace_order_id() )
		);

		if ( '' !== $marketplace_order->end_customer_email() ) {
			$this->row(
				__( 'Buyer email', 'woo-product-sync-manager' ),
				sprintf(
					'<a href="mailto:%1$s">%2$s</a>',
					esc_attr( $marketplace_order->end_customer_email() ),
					esc_html( $marketplace_order->end_customer_email() )
				)
			);
		}

		echo '<hr>';

		if ( null !== $money->customer_total() ) {
			$this->row( __( 'Buyer paid', 'woo-product-sync-manager' ), wp_kses_post( wc_price( $money->customer_total(), $currency ) ) );
		}

		if ( null !== $money->commission() ) {
			$percent = $money->commission_percent();

			$this->row(
				null === $percent
					? __( 'Commission', 'woo-product-sync-manager' )
					: sprintf(
						/* translators: %s: commission as a percentage, for example "15.0". */
						__( 'Commission (%s%%)', 'woo-product-sync-manager' ),
						number_format_i18n( $percent, 1 )
					),
				'&minus;' . wp_kses_post( wc_price( $money->commission(), $currency ) )
			);
		}

		if ( null !== $money->shipping_payout() ) {
			$this->row( __( 'Shipping paid by the marketplace', 'woo-product-sync-manager' ), wp_kses_post( wc_price( $money->shipping_payout(), $currency ) ) );
		}

		$this->row( '<strong>' . esc_html__( 'Your revenue', 'woo-product-sync-manager' ) . '</strong>', '<strong>' . wp_kses_post( wc_price( $money->revenue(), $currency ) ) . '</strong>', false );

		if ( $marketplace_order->is_nalda() ) {
			$this->row( __( 'Payout', 'woo-product-sync-manager' ), Badges::payout_status( $money->payout_status() ) );
		}

		if ( null !== $marketplace_order->ship_by() ) {
			$this->row( __( 'Ship by', 'woo-product-sync-manager' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $marketplace_order->ship_by()->getTimestamp() ) ) );
		}

		echo '<hr>';

		$this->render_delivery_fields( $marketplace_order );

		printf(
			'<p><a class="button" href="%1$s" target="_blank" rel="noopener">%2$s</a></p>',
			esc_url( Note::url( $order ) ),
			esc_html__( 'Delivery note', 'woo-product-sync-manager' )
		);

		echo '</div>';
	}

	/**
	 * Print the delivery state, expected date and tracking code fields.
	 *
	 * @param MarketplaceOrder $marketplace_order The order.
	 * @return void
	 */
	private function render_delivery_fields( MarketplaceOrder $marketplace_order ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$selected = $marketplace_order->delivery_status_is_manual() ? $marketplace_order->delivery_status() : self::AUTOMATIC;
		$current  = Status::states()[ $marketplace_order->delivery_status() ]['label'] ?? $marketplace_order->delivery_status();

		echo '<p><label for="wpsm_delivery_status">' . esc_html__( 'Delivery status sent to the marketplace', 'woo-product-sync-manager' ) . '</label>';
		echo '<select id="wpsm_delivery_status" name="wpsm_delivery_status" class="widefat">';

		printf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( self::AUTOMATIC ),
			selected( $selected, self::AUTOMATIC, false ),
			esc_html(
				sprintf(
					/* translators: %s: the delivery state worked out from the order, for example "In delivery". */
					__( 'Automatic (now: %s)', 'woo-product-sync-manager' ),
					'' !== $current ? $current : __( 'none', 'woo-product-sync-manager' )
				)
			)
		);

		foreach ( Status::states() as $state => $config ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $state ),
				selected( $selected, $state, false ),
				esc_html( $config['label'] )
			);
		}

		echo '</select></p>';

		printf(
			'<p><label for="wpsm_expected_delivery_date">%1$s</label><input type="date" id="wpsm_expected_delivery_date" name="wpsm_expected_delivery_date" class="widefat" value="%2$s"></p>',
			esc_html__( 'Expected delivery date', 'woo-product-sync-manager' ),
			esc_attr( $marketplace_order->expected_delivery_date() )
		);

		printf(
			'<p><label for="wpsm_tracking_code">%1$s</label><input type="text" id="wpsm_tracking_code" name="wpsm_tracking_code" class="widefat" value="%2$s"></p>',
			esc_html__( 'Tracking code', 'woo-product-sync-manager' ),
			esc_attr( $marketplace_order->tracking_code() )
		);
	}

	/**
	 * Print one label and value row.
	 *
	 * @param string $label         Label, escaped here unless already HTML.
	 * @param string $value         Value, already escaped.
	 * @param bool   $escape_label  Whether to escape the label.
	 * @return void
	 */
	private function row( $label, $value, $escape_label = true ) {
		printf(
			'<p class="wpsm-panel__row"><span>%1$s</span><span>%2$s</span></p>',
			$escape_label ? esc_html( $label ) : $label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Callers pass escaped HTML when they turn escaping off.
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every caller escapes the value it builds.
		);
	}

	/**
	 * Save the delivery fields.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function save( $order_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( null === MarketplaceOrder::from( $order ) ) {
			return;
		}

		$state = isset( $_POST['wpsm_delivery_status'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsm_delivery_status'] ) ) : self::AUTOMATIC;
		$date  = isset( $_POST['wpsm_expected_delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsm_expected_delivery_date'] ) ) : '';
		$code  = isset( $_POST['wpsm_tracking_code'] ) ? sanitize_text_field( wp_unslash( $_POST['wpsm_tracking_code'] ) ) : '';

		if ( array_key_exists( $state, Status::states() ) ) {
			$order->update_meta_data( Meta::DELIVERY_STATUS, $state );
			$order->update_meta_data( Meta::DELIVERY_STATUS_MANUAL, 'yes' );
		} else {
			// Back to automatic: Status works the state out again when the order saves.
			$order->delete_meta_data( Meta::DELIVERY_STATUS_MANUAL );
		}

		if ( '' === $date || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$order->update_meta_data( Meta::EXPECTED_DELIVERY_DATE, $date );
		}

		$order->update_meta_data( Meta::TRACKING_CODE, $code );
		$order->save();
	}
}
