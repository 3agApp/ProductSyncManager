<?php
/**
 * Tests for the delivery note.
 *
 * @package WooProductSyncManager
 */

namespace WooProductSyncManager\Tests;

use WooProductSyncManager\Delivery\Note;
use WooProductSyncManager\Orders\MarketplaceOrder;

/**
 * Covers what the parcel's delivery note says.
 */
class NoteTest extends TestCase {

	/**
	 * The buyer is the recipient, and the prices are what the buyer paid.
	 *
	 * @return void
	 */
	public function test_the_note_is_addressed_to_the_buyer() {
		$html = ( new Note() )->render( MarketplaceOrder::from( $this->marketplace_order() ) );

		$this->assertStringContainsString( 'Anna Muster', $html );
		$this->assertStringContainsString( 'Bahnhofstrasse 1', $html );
		$this->assertStringContainsString( 'TOY-100', $html );
		$this->assertStringContainsString( '49.90', $html, 'The buyer price, not the net price.' );
		$this->assertStringContainsString( '99.80', $html );
		$this->assertStringContainsString( 'via Nalda', $html );
	}

	/**
	 * The note prints in German or English whatever wp-admin is in, and offers both.
	 *
	 * @return void
	 */
	public function test_the_note_can_be_printed_in_german_or_english() {
		$order = MarketplaceOrder::from( $this->marketplace_order() );

		$german  = ( new Note() )->render( $order, 'de_DE' );
		$english = ( new Note() )->render( $order, 'en_US' );

		$this->assertStringContainsString( 'Lieferschein', $german );
		$this->assertMatchesRegularExpression( '/aria-current="true">Deutsch</', $german );
		$this->assertStringContainsString( 'Delivery note', $english );
		$this->assertMatchesRegularExpression( '/aria-current="true">English</', $english );
		$this->assertStringContainsString( 'lang=en_US', $german );
		$this->assertFalse( is_locale_switched(), 'The previous language is restored.' );
	}

	/**
	 * A language the note does not offer falls back to wp-admin's.
	 *
	 * @return void
	 */
	public function test_an_unknown_language_is_ignored() {
		$html = ( new Note() )->render( MarketplaceOrder::from( $this->marketplace_order() ), 'fr_FR' );

		$this->assertStringContainsString( 'Delivery note', $html );
	}

	/**
	 * The link carries a nonce for that one order.
	 *
	 * @return void
	 */
	public function test_the_link_is_nonced_per_order() {
		$order = $this->marketplace_order();
		$url   = Note::url( $order );

		// wp_nonce_url() returns the URL escaped for HTML.
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );

		$this->assertSame( Note::ACTION, $query['action'] );
		$this->assertSame( (string) $order->get_id(), $query['order_id'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], Note::ACTION . '_' . $order->get_id() ) );
	}
}
