<?php
/**
 * Delivery note.
 *
 * Rendered by WooProductSyncManager\Delivery\Note, which passes $note.
 *
 * @package WooProductSyncManager
 *
 * @var array $note {
 *     @type \WC_Order                                       $order          The order.
 *     @type \WooProductSyncManager\Orders\MarketplaceOrder $marketplace    The order, read as a marketplace order.
 *     @type string                                          $store_name     Shop name.
 *     @type string[]                                        $store_address  Shop address lines.
 *     @type string                                          $logo_url       Shop logo, or empty.
 *     @type array[]                                         $lines          Lines: sku, name, quantity, unit, total.
 *     @type string[]                                        $shipping_types Shipping method names.
 * }
 */

defined( 'ABSPATH' ) || exit;

$wpsm_order       = $note['order'];
$wpsm_marketplace = $note['marketplace'];
$wpsm_currency    = array( 'currency' => $wpsm_order->get_currency() );
$wpsm_total       = array_sum( array_column( $note['lines'], 'total' ) );
$wpsm_country     = WC()->countries->countries[ $wpsm_order->get_shipping_country() ] ?? $wpsm_order->get_shipping_country();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<title>
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: order number. */
			__( 'Delivery note %s', 'woo-product-sync-manager' ),
			$wpsm_order->get_order_number()
		)
	);
	?>
	</title>
	<style>
		body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.4; color: #222; margin: 0; }
		.note { max-width: 800px; margin: 0 auto; padding: 24px; }
		.head, .parties { width: 100%; margin-bottom: 28px; border-collapse: collapse; }
		.head td, .parties td { vertical-align: top; width: 50%; }
		.title { text-align: right; }
		.title h1 { margin: 0 0 6px; font-size: 24px; }
		.title p { margin: 0; color: #555; }
		.marketplace { color: #c05a00; font-weight: bold; }
		.parties h2 { margin: 0 0 8px; font-size: 13px; color: #555; border-bottom: 2px solid #222; padding-bottom: 4px; }
		.parties td:first-child { padding-right: 24px; }
		.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
		.lines th { background: #f3f3f3; text-align: left; }
		.lines th, .lines td { border: 1px solid #ccc; padding: 7px; }
		.num { text-align: right; white-space: nowrap; }
		.centre { text-align: center; }
		.total { margin: 0 0 28px auto; font-size: 14px; font-weight: bold; }
		.total td { padding: 4px 8px; }
		.delivery { width: 100%; border: 1px solid #ccc; border-collapse: collapse; }
		.delivery td { padding: 8px; }
		.delivery td:first-child { width: 140px; background: #f3f3f3; font-weight: bold; }
		.box { display: inline-block; width: 12px; height: 12px; border: 1px solid #222; margin: 0 4px 0 12px; vertical-align: middle; }
		.actions { text-align: right; margin-bottom: 12px; }
		@media print { .actions { display: none; } .note { padding: 0; } }
	</style>
</head>
<body>
<div class="note">
	<p class="actions"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print', 'woo-product-sync-manager' ); ?></button></p>

	<table class="head">
		<tr>
			<td>
				<?php if ( '' !== $note['logo_url'] ) : ?>
					<img src="<?php echo esc_url( $note['logo_url'] ); ?>" alt="<?php echo esc_attr( $note['store_name'] ); ?>" style="max-height:60px;max-width:220px">
				<?php else : ?>
					<strong style="font-size:18px"><?php echo esc_html( $note['store_name'] ); ?></strong>
				<?php endif; ?>
			</td>
			<td class="title">
				<h1><?php esc_html_e( 'Delivery note', 'woo-product-sync-manager' ); ?></h1>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: order number. */
							__( 'Order %s', 'woo-product-sync-manager' ),
							$wpsm_order->get_order_number()
						)
					);
					?>
					<br>
					<?php echo esc_html( $wpsm_order->get_date_created() ? $wpsm_order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?>
					<br>
					<span class="marketplace">
						<?php echo esc_html( $wpsm_marketplace->marketplace_name() . ' ' . $wpsm_marketplace->marketplace_order_id() ); ?>
					</span>
				</p>
			</td>
		</tr>
	</table>

	<table class="parties">
		<tr>
			<td>
				<h2><?php esc_html_e( 'Seller', 'woo-product-sync-manager' ); ?></h2>
				<strong><?php echo esc_html( $note['store_name'] ); ?></strong><br>
				<?php echo implode( '<br>', array_map( 'esc_html', $note['store_address'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line is escaped. ?>
			</td>
			<td>
				<h2><?php esc_html_e( 'Recipient', 'woo-product-sync-manager' ); ?></h2>
				<strong><?php echo esc_html( $wpsm_marketplace->end_customer_name() ); ?></strong><br>
				<?php
				$wpsm_address = array_filter(
					array(
						$wpsm_order->get_shipping_address_1(),
						$wpsm_order->get_shipping_address_2(),
						trim( $wpsm_order->get_shipping_postcode() . ' ' . $wpsm_order->get_shipping_city() ),
						$wpsm_country,
					)
				);
				echo implode( '<br>', array_map( 'esc_html', $wpsm_address ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line is escaped.
				?>
				<br>
				<span class="marketplace">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: marketplace name, for example "Nalda". */
							__( 'via %s', 'woo-product-sync-manager' ),
							$wpsm_marketplace->marketplace_name()
						)
					);
					?>
				</span>
			</td>
		</tr>
	</table>

	<table class="lines">
		<thead>
			<tr>
				<th class="centre"><?php esc_html_e( 'No.', 'woo-product-sync-manager' ); ?></th>
				<th><?php esc_html_e( 'Article no.', 'woo-product-sync-manager' ); ?></th>
				<th><?php esc_html_e( 'Description', 'woo-product-sync-manager' ); ?></th>
				<th class="centre"><?php esc_html_e( 'Quantity', 'woo-product-sync-manager' ); ?></th>
				<th class="num"><?php esc_html_e( 'Unit price', 'woo-product-sync-manager' ); ?></th>
				<th class="num"><?php esc_html_e( 'Total', 'woo-product-sync-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $note['lines'] as $wpsm_index => $wpsm_line ) : ?>
				<tr>
					<td class="centre"><?php echo esc_html( (string) ( $wpsm_index + 1 ) ); ?></td>
					<td><?php echo esc_html( $wpsm_line['sku'] ); ?></td>
					<td><?php echo esc_html( $wpsm_line['name'] ); ?></td>
					<td class="centre"><?php echo esc_html( (string) $wpsm_line['quantity'] ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $wpsm_line['unit'], $wpsm_currency ) ); ?></td>
					<td class="num"><?php echo wp_kses_post( wc_price( $wpsm_line['total'], $wpsm_currency ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<table class="total">
		<tr>
			<td><?php esc_html_e( 'Total', 'woo-product-sync-manager' ); ?></td>
			<td class="num"><?php echo wp_kses_post( wc_price( $wpsm_total, $wpsm_currency ) ); ?></td>
		</tr>
	</table>

	<?php if ( array() !== $note['shipping_types'] ) : ?>
		<table class="delivery">
			<tr>
				<td><?php esc_html_e( 'Delivery type', 'woo-product-sync-manager' ); ?></td>
				<td>
					<?php foreach ( $note['shipping_types'] as $wpsm_type ) : ?>
						<span class="box"></span><?php echo esc_html( $wpsm_type ); ?>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
	<?php endif; ?>
</div>
</body>
</html>
