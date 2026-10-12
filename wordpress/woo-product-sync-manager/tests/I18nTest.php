<?php
/**
 * Tests for the translations.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use MO;
use PO;
use WooProductSyncManager\Plugin;
use WP_UnitTestCase;

/**
 * Covers the shipped catalogues and the German locale mapping.
 *
 * The plugin is bilingual by requirement, so an untranslated string is a defect rather
 * than a nicety: WordPress falls back to English silently, one label at a time, and
 * nobody notices until a German shop manager is reading half an English screen.
 */
class I18nTest extends WP_UnitTestCase {

	/**
	 * Directory holding the catalogues.
	 *
	 * @var string
	 */
	private $languages;

	/**
	 * Locate the language directory and WordPress's PO/MO readers.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->languages = dirname( __DIR__ ) . '/languages';

		require_once ABSPATH . WPINC . '/pomo/po.php';
		require_once ABSPATH . WPINC . '/pomo/mo.php';
	}

	/**
	 * Restore English between tests, whatever a test switched to.
	 *
	 * @return void
	 */
	public function tear_down() {
		/*
		 * Only the catalogue is dropped, so the next test loads it for whatever locale
		 * it asks for. The `locale` filter is not touched here: with_locale() removes
		 * its own in a finally block, and clearing the hook wholesale would also
		 * discard filters that belong to WordPress or to another test.
		 */
		unload_textdomain( 'woo-product-sync-manager', true );

		parent::tear_down();
	}

	/**
	 * Run a callback with WordPress reporting the given locale.
	 *
	 * `switch_to_locale()` is not usable here: it returns false for any locale whose
	 * *core* language pack is not installed, and the test site has none. Filtering
	 * `locale` and dropping the loaded catalogue drives the same just-in-time loading
	 * path WordPress uses on a real site — including the mofile filter this plugin
	 * hangs the German fallback on.
	 *
	 * @param string   $locale   Locale to pretend the site is running.
	 * @param callable $callback Assertions to run.
	 * @return void
	 */
	private function with_locale( $locale, callable $callback ) {
		$filter = static function () use ( $locale ) {
			return $locale;
		};

		add_filter( 'locale', $filter );
		unload_textdomain( 'woo-product-sync-manager', true );

		try {
			$callback();
		} finally {
			remove_filter( 'locale', $filter );
			unload_textdomain( 'woo-product-sync-manager', true );
		}
	}

	/**
	 * The locales the plugin maintains a catalogue for.
	 *
	 * @return array
	 */
	public function german_locales() {
		return array(
			'informal' => array( Plugin::GERMAN_INFORMAL ),
			'formal'   => array( Plugin::GERMAN_FORMAL ),
		);
	}

	/**
	 * Read a PO file.
	 *
	 * @param string $file Path.
	 * @return PO
	 */
	private function read_po( $file ) {
		$po = new PO();
		$po->import_from_file( $file );

		return $po;
	}

	/**
	 * Whether an entry is plugin header metadata rather than an interface string.
	 *
	 * The plugin name, its URI and the author are not translated: a plugin is listed
	 * under one name in every language, and translating a URL is meaningless.
	 *
	 * @param object $entry PO entry.
	 * @return bool
	 */
	private function is_metadata( $entry ) {
		foreach ( (array) $entry->extracted_comments as $comment ) {
			if ( preg_match( '/^(Plugin Name|Plugin URI|Author|Author URI) of the plugin$/m', trim( $comment ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every catalogue the plugin ships is present in all three forms.
	 *
	 * The .po is the source, the .mo is the catalogue older readers expect, and the
	 * .l10n.php is what WordPress 6.5 and newer actually loads. Shipping the source
	 * without the compiled files means shipping no translation at all.
	 *
	 * @dataProvider german_locales
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	public function test_catalogue_is_compiled( $locale ) {
		foreach ( array( 'po', 'mo', 'l10n.php' ) as $extension ) {
			$file = sprintf( '%s/woo-product-sync-manager-%s.%s', $this->languages, $locale, $extension );

			$this->assertFileExists( $file );
			$this->assertGreaterThan( 0, filesize( $file ), $file . ' is empty.' );
		}
	}

	/**
	 * Every string extracted from the source is translated in every catalogue.
	 *
	 * @dataProvider german_locales
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	public function test_catalogue_is_complete( $locale ) {
		$pot = $this->read_po( $this->languages . '/woo-product-sync-manager.pot' );
		$po  = $this->read_po( sprintf( '%s/woo-product-sync-manager-%s.po', $this->languages, $locale ) );

		$this->assertNotEmpty( $pot->entries, 'The POT is empty; run ./bin/make-translations.sh.' );

		$untranslated = array();
		$absent       = array();

		foreach ( $pot->entries as $key => $entry ) {
			if ( $this->is_metadata( $entry ) ) {
				continue;
			}

			if ( ! isset( $po->entries[ $key ] ) ) {
				$absent[] = $entry->singular;
				continue;
			}

			$translated = $po->entries[ $key ];

			/*
			 * An entry nobody has touched carries an *empty* translations array rather than
			 * an array holding an empty string — POMO drops `msgstr ""` on the way in. So
			 * the loop below never ran for exactly the entries it was written to catch, and
			 * this whole assertion passed over 80 untranslated strings without a word. The
			 * empty case has to be tested before iterating, not inside the iteration.
			 */
			$translations = array_filter( (array) $translated->translations, 'strlen' );

			if ( empty( $translations ) ) {
				$untranslated[] = $entry->singular;
				continue;
			}

			foreach ( (array) $translated->translations as $translation ) {
				if ( '' === trim( (string) $translation ) ) {
					$untranslated[] = $entry->singular;
					break;
				}
			}

			if ( in_array( 'fuzzy', (array) $translated->flags, true ) ) {
				$untranslated[] = $entry->singular;
			}
		}

		$this->assertSame(
			array(),
			$absent,
			sprintf( 'Strings missing from the %s catalogue. Run ./bin/make-translations.sh.', $locale )
		);

		$this->assertSame(
			array(),
			$untranslated,
			sprintf( 'Strings still untranslated (or fuzzy) in %s.', $locale )
		);
	}

	/**
	 * The compiled catalogue carries the same translations as its source.
	 *
	 * Editing a .po without recompiling is the easiest way to ship a translation that
	 * looks complete in the repository and does nothing on the site.
	 *
	 * @dataProvider german_locales
	 *
	 * @param string $locale Locale.
	 * @return void
	 */
	public function test_compiled_catalogue_matches_its_source( $locale ) {
		$po = $this->read_po( sprintf( '%s/woo-product-sync-manager-%s.po', $this->languages, $locale ) );

		$mo = new MO();
		$mo->import_from_file( sprintf( '%s/woo-product-sync-manager-%s.mo', $this->languages, $locale ) );

		$stale = array();

		foreach ( $po->entries as $key => $entry ) {
			if ( ! array_filter( (array) $entry->translations, 'strlen' ) ) {
				continue;
			}

			if ( ! isset( $mo->entries[ $key ] ) || $mo->entries[ $key ]->translations !== $entry->translations ) {
				$stale[] = $entry->singular;
			}
		}

		$this->assertSame(
			array(),
			$stale,
			sprintf( 'The compiled %s catalogue is out of date. Run ./bin/make-translations.sh.', $locale )
		);

		// The PHP catalogue is what WordPress prefers, so it has to agree as well.
		$php = include sprintf( '%s/woo-product-sync-manager-%s.l10n.php', $this->languages, $locale );

		$this->assertIsArray( $php );
		$this->assertArrayHasKey( 'messages', $php );
		$this->assertSame( 'Lieferschein', $php['messages']['Delivery note'] );
	}

	/**
	 * Switching to German translates the interface.
	 *
	 * @return void
	 */
	public function test_german_translates_the_interface() {
		$this->assertSame( 'Delivery note', __( 'Delivery note', 'woo-product-sync-manager' ) );

		$this->with_locale(
			Plugin::GERMAN_INFORMAL,
			function () {
				$this->assertSame( 'Lieferschein', __( 'Delivery note', 'woo-product-sync-manager' ) );
				$this->assertSame( 'In Zustellung', __( 'In delivery', 'woo-product-sync-manager' ) );
			}
		);

		// English is what an unswitched site still gets.
		$this->assertSame( 'Delivery note', __( 'Delivery note', 'woo-product-sync-manager' ) );
	}

	/**
	 * The informal and formal catalogues address the reader differently.
	 *
	 * This is the whole reason for shipping two of them: WordPress's own de_DE says
	 * "du", and a plugin saying "Sie" in the middle of it reads as a different product.
	 *
	 * @return void
	 */
	public function test_registers_differ_between_the_two_catalogues() {
		$this->with_locale(
			Plugin::GERMAN_INFORMAL,
			function () {
				$this->assertStringStartsWith( 'Du darfst', __( 'You are not allowed to print delivery notes.', 'woo-product-sync-manager' ) );
				$this->assertSame( 'Dein Erlös', __( 'Your revenue', 'woo-product-sync-manager' ) );
			}
		);

		$this->with_locale(
			Plugin::GERMAN_FORMAL,
			function () {
				$this->assertStringStartsWith( 'Sie dürfen', __( 'You are not allowed to print delivery notes.', 'woo-product-sync-manager' ) );
				$this->assertSame( 'Ihr Erlös', __( 'Your revenue', 'woo-product-sync-manager' ) );
			}
		);
	}

	/**
	 * Austrian and Swiss shops get German rather than English.
	 *
	 * @return void
	 */
	public function test_other_german_locales_fall_back_to_a_shipped_catalogue() {
		foreach ( array( 'de_AT', 'de_CH', 'de_CH_informal' ) as $locale ) {
			$this->with_locale(
				$locale,
				function () use ( $locale ) {
					$this->assertSame(
						'Lieferschein',
						__( 'Delivery note', 'woo-product-sync-manager' ),
						$locale . ' did not fall back to the German catalogue.'
					);
				}
			);
		}

		// A locale with no catalogue of ours is still English rather than German.
		$this->with_locale(
			'fr_FR',
			function () {
				$this->assertSame( 'Delivery note', __( 'Delivery note', 'woo-product-sync-manager' ) );
			}
		);
	}

	/**
	 * The fallback leaves everything that is not a missing German catalogue alone.
	 *
	 * @return void
	 */
	public function test_locale_mapping_is_narrow() {
		$plugin = Plugin::instance();
		$path   = $this->languages . '/woo-product-sync-manager-%s.mo';

		// Another plugin's catalogue is none of our business.
		$other = sprintf( $path, 'de_AT' );
		$this->assertSame( $other, $plugin->map_german_locale( $other, 'some-other-plugin' ) );

		// A locale that exists is loaded as asked.
		$existing = sprintf( $path, Plugin::GERMAN_FORMAL );
		$this->assertSame( $existing, $plugin->map_german_locale( $existing, 'woo-product-sync-manager' ) );

		// A missing non-German catalogue is left missing rather than turned into German.
		$french = sprintf( $path, 'fr_FR' );
		$this->assertSame( $french, $plugin->map_german_locale( $french, 'woo-product-sync-manager' ) );

		// A missing German one is redirected, formal to formal.
		$this->assertSame(
			sprintf( $path, Plugin::GERMAN_INFORMAL ),
			$plugin->map_german_locale( sprintf( $path, 'de_AT' ), 'woo-product-sync-manager' )
		);
		$this->assertSame(
			sprintf( $path, Plugin::GERMAN_FORMAL ),
			$plugin->map_german_locale( sprintf( $path, 'de_AT_formal' ), 'woo-product-sync-manager' )
		);
	}
}
