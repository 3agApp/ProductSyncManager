<?php
/**
 * Plugin bootstrap.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WooProductSyncManager\Admin\OrderColumn;
use WooProductSyncManager\Admin\OrderPanel;
use WooProductSyncManager\Delivery\Note;
use WooProductSyncManager\Delivery\Status;
use WooProductSyncManager\Emails\AdminDetails;
use WooProductSyncManager\Emails\CustomerEmails;
use WooProductSyncManager\Integrations\Planzer;
use WooProductSyncManager\Migration\LegacyNalda;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * The plugin's text domain.
	 *
	 * @var string
	 */
	const TEXT_DOMAIN = 'woo-product-sync-manager';

	/**
	 * The German catalogues that ship with the plugin.
	 *
	 * WordPress never falls back between German locales, so map_german_locale() points
	 * the others at whichever of these matches their register.
	 *
	 * @var string
	 */
	const GERMAN_INFORMAL = 'de_DE';
	const GERMAN_FORMAL   = 'de_DE_formal';

	/**
	 * The single shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already registered the plugin's hooks.
	 *
	 * @var bool
	 */
	private $initialised = false;

	/**
	 * Use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'load_textdomain_mofile', array( $this, 'map_german_locale' ), 10, 2 );

		/*
		 * Outside the is_admin() branch: an order changes status from REST, from cron
		 * and from other plugins as often as from its screen, and every one of those
		 * saves has to keep the delivery state right and send the buyer nothing.
		 */
		( new Status() )->register();
		( new CustomerEmails() )->register();
		( new AdminDetails() )->register();
		( new Planzer() )->register();
		( new LegacyNalda() )->register();

		if ( is_admin() ) {
			( new OrderColumn() )->register();
			( new OrderPanel() )->register();
			( new Note() )->register();

			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		}
	}

	/**
	 * Load the stylesheet on the orders screens.
	 *
	 * @return void
	 */
	public function enqueue_admin_styles() {
		$screen = get_current_screen();

		if ( null === $screen || OrderUtil::get_order_admin_screen() !== $screen->id ) {
			return;
		}

		wp_enqueue_style( 'wpsm-admin', WPSM_PLUGIN_URL . 'assets/css/admin.css', array(), WPSM_VERSION );
	}

	/**
	 * Load the plugin translations.
	 *
	 * Hooked to `init` because loading a text domain earlier is deprecated.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WPSM_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Serve the German catalogues to every German locale.
	 *
	 * A shop set to `de_CH`, `de_AT` or `de_CH_informal` asks for a catalogue that does
	 * not exist and would silently fall back to English. Those requests are pointed at
	 * the catalogue matching their register instead. A locale that has a catalogue of
	 * its own is left alone.
	 *
	 * @param string $mofile Path to the catalogue WordPress is about to load.
	 * @param string $domain Text domain being loaded.
	 * @return string
	 */
	public function map_german_locale( $mofile, $domain ) {
		if ( self::TEXT_DOMAIN !== $domain || file_exists( $mofile ) ) {
			return $mofile;
		}

		if ( ! preg_match( '/-(de(?:_[A-Za-z]+)*)\.mo$/', basename( $mofile ), $matches ) ) {
			return $mofile;
		}

		$register = str_ends_with( $matches[1], '_formal' ) ? self::GERMAN_FORMAL : self::GERMAN_INFORMAL;
		$fallback = dirname( $mofile ) . '/' . self::TEXT_DOMAIN . '-' . $register . '.mo';

		return file_exists( $fallback ) ? $fallback : $mofile;
	}
}
