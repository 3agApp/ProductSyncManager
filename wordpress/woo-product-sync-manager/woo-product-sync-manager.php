<?php
/**
 * Plugin Name:          Product Sync Manager for WooCommerce
 * Plugin URI:           https://github.com/3agApp/ProductSyncManager
 * Update URI:           https://github.com/3agApp/ProductSyncManager
 * Description:          Shows the marketplace orders Product Sync Manager imports from Nalda and Temu: an orders column, an order panel, the delivery status the marketplace is sent, a delivery note, and no customer emails.
 * Version:              0.1.1
 * Requires at least:    7.0
 * Tested up to:         7.1
 * Requires PHP:         8.2
 * Requires Plugins:     woocommerce
 * Author:               3AG
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woo-product-sync-manager
 * Domain Path:          /languages
 * WC requires at least: 11.0
 * WC tested up to:      11.2
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager;

defined( 'ABSPATH' ) || exit;

define( 'WPSM_VERSION', '0.1.1' );
define( 'WPSM_PLUGIN_FILE', __FILE__ );
define( 'WPSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPSM_MIN_WC_VERSION', '11.0' );

/**
 * Version of the data this plugin keeps on orders.
 *
 * Bumped whenever an upgrade has to rewrite stored data, which is what makes an
 * existing site run Migration\LegacyNalda (or a later step) on the next load.
 */
define( 'WPSM_DB_VERSION', '1.1.0' );

/**
 * Load the Composer autoloader.
 *
 * A distributed build always ships vendor/, but a git checkout may not have run
 * `composer install` yet. Fail loudly in the admin rather than fatally.
 *
 * @return bool True when the autoloader was loaded.
 */
function load_autoloader() {
	$autoloader = WPSM_PLUGIN_DIR . 'vendor/autoload.php';

	if ( ! is_readable( $autoloader ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_autoloader_notice' );
		return false;
	}

	require_once $autoloader;
	return true;
}

/**
 * Show an admin notice explaining that the Composer dependencies are missing.
 *
 * @return void
 */
function render_missing_autoloader_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__(
			'Product Sync Manager for WooCommerce is missing its dependencies. Run "composer install" in the plugin directory.',
			'woo-product-sync-manager'
		)
	);
}

/**
 * Show an admin notice explaining that WooCommerce is required.
 *
 * @return void
 */
function render_missing_woocommerce_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: minimum supported WooCommerce version. */
				__( 'Product Sync Manager for WooCommerce requires WooCommerce %s or newer to be installed and active.', 'woo-product-sync-manager' ),
				WPSM_MIN_WC_VERSION
			)
		)
	);
}

/**
 * Show an admin notice explaining that High-Performance Order Storage is required.
 *
 * @return void
 */
function render_hpos_required_notice() {
	printf(
		'<div class="notice notice-error"><p>%1$s</p><p><a href="%2$s">%3$s</a></p></div>',
		esc_html__(
			'Product Sync Manager for WooCommerce requires High-Performance Order Storage. Marketplace orders are not shown until it is enabled.',
			'woo-product-sync-manager'
		),
		esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ),
		esc_html__( 'Enable High-Performance Order Storage', 'woo-product-sync-manager' )
	);
}

/**
 * Determine whether a supported version of WooCommerce is active.
 *
 * The "Requires Plugins" header does not enforce a minimum WooCommerce version, so
 * that is checked here.
 *
 * @return bool True when WooCommerce is active and new enough.
 */
function is_woocommerce_supported() {
	if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) ) {
		return false;
	}

	return version_compare( WC_VERSION, WPSM_MIN_WC_VERSION, '>=' );
}

/**
 * Determine whether High-Performance Order Storage is the active order store.
 *
 * Every screen and hook here reads marketplace orders through the HPOS order screen
 * and the order CRUD; against the legacy post store the column and the panel would
 * register on screens that are never shown.
 *
 * @return bool True when HPOS is enabled.
 */
function is_hpos_enabled() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
		return false;
	}

	return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * High-Performance Order Storage must be declared before WooCommerce initialises,
 * otherwise the plugin is listed as incompatible.
 *
 * @return void
 */
function declare_woocommerce_compatibility() {
	if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPSM_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPSM_PLUGIN_FILE, true );
}

/**
 * Boot the plugin once all other plugins are loaded.
 *
 * @return void
 */
function bootstrap() {
	if ( ! is_woocommerce_supported() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_missing_woocommerce_notice' );
		return;
	}

	if ( ! is_hpos_enabled() ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\render_hpos_required_notice' );
		return;
	}

	Plugin::instance()->init();
}

if ( load_autoloader() ) {
	/*
	 * Registered ahead of the requirement gates, not inside bootstrap(). An update is
	 * often the thing that fixes a plugin sitting inert behind one of those gates.
	 */
	( new Updates\Updater() )->register();

	add_action( 'before_woocommerce_init', __NAMESPACE__ . '\\declare_woocommerce_compatibility' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
}
