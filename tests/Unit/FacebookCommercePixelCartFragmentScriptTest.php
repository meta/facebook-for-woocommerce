<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Covers the once-per-browser-session guard on event scripts that travel in
 * WooCommerce cart fragments.
 *
 * WooCommerce stores the AJAX add to cart fragments in sessionStorage and replays
 * them on every later page load, re-executing any inline script they carry. The
 * guard keys on the event ID so the replays do nothing.
 */
class FacebookCommercePixelCartFragmentScriptTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	private function params_with_event_id( string $event_id = 'atc-event-123' ): array {
		return array(
			'content_ids'  => array( 'PROD123' ),
			'content_type' => 'product',
			'event_id'     => $event_id,
		);
	}

	public function test_fragment_script_fires_once_per_browser_session(): void {
		$script = ( new WC_Facebookcommerce_Pixel() )->get_cart_fragment_event_script( 'AddToCart', $this->params_with_event_id() );

		$this->assertStringContainsString( '<script', $script );
		$this->assertStringContainsString( "var key = 'wc_facebook_pixel_fired_fragment_events', id = \"atc-event-123\"", $script );
		$this->assertStringContainsString( 'if (seen[id]) { return; }', $script );
		$this->assertStringContainsString( 'window.sessionStorage.setItem(key, JSON.stringify(seen))', $script );
		$this->assertStringContainsString( "fbq('track', 'AddToCart'", $script );
		$this->assertStringContainsString( '"eventID": "atc-event-123"', $script );
		// The page-level guard stays in place inside the wrapper.
		$this->assertStringContainsString( 'window.wcFacebookPixelFiredEvents["atc-event-123"]', $script );
	}

	public function test_fragment_script_without_event_id_is_not_guarded(): void {
		$script = ( new WC_Facebookcommerce_Pixel() )->get_cart_fragment_event_script(
			'AddToCart',
			array( 'content_ids' => array( 'PROD123' ) )
		);

		$this->assertStringNotContainsString( 'wc_facebook_pixel_fired_fragment_events', $script );
		$this->assertStringContainsString( "fbq('track', 'AddToCart'", $script );
	}

	public function test_guard_wraps_the_code_and_caps_the_stored_ids(): void {
		$code = WC_Facebookcommerce_Pixel::guard_once_per_browser_session( 'atc-event-123', 'fireTheEvent();' );

		$this->assertStringStartsWith( '(function() {', $code );
		$this->assertStringEndsWith( '})();', $code );
		$this->assertStringContainsString( 'fireTheEvent();', $code );
		$this->assertStringContainsString( 'ids.length - ' . WC_Facebookcommerce_Pixel::FRAGMENT_EVENT_IDS_LIMIT, $code );
	}

	public function test_guard_leaves_code_without_event_id_unchanged(): void {
		$this->assertSame( 'fireTheEvent();', WC_Facebookcommerce_Pixel::guard_once_per_browser_session( '', 'fireTheEvent();' ) );
	}

	public function test_conditional_script_guards_its_handler(): void {
		$script = ( new WC_Facebookcommerce_Pixel() )->get_conditional_one_time_event_script( 'AddToCart', $this->params_with_event_id(), 'added_to_cart' );

		$handler_at = strpos( $script, 'function handleAddToCartEvent() {' );
		$guard_at   = strpos( $script, "var key = 'wc_facebook_pixel_fired_fragment_events', id = \"atc-event-123\"" );
		$fire_at    = strpos( $script, "fbq('track', 'AddToCart'" );

		$this->assertNotFalse( $handler_at );
		$this->assertNotFalse( $guard_at );
		$this->assertNotFalse( $fire_at );
		$this->assertLessThan( $guard_at, $handler_at, 'The guard must sit inside the handler, so a replayed listener is a no-op.' );
		$this->assertLessThan( $fire_at, $guard_at );
	}
}
