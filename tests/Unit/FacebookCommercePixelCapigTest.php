<?php
/**
 * Copyright (c) Meta, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Tests that the pixel carries the merchant's Meta-enabled Conversions API opt-in.
 *
 * When opted in, fbq('optinMetaEnabledCapi', pixelId) must run before
 * fbq('init', ...). The base code passes the choice to FacebookSignals as
 * `capig`, and FacebookSignals emits the command when it initialises the pixel,
 * which covers both the immediate init and the one deferred until signals are
 * released.
 *
 * @covers WC_Facebookcommerce_Pixel::pixel_base_code
 */
class FacebookCommercePixelCapigTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	public function setUp(): void {
		parent::setUp();

		WC_Facebookcommerce_Pixel::$render_cache = array();
		update_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			array( WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '123456789' )
		);
	}

	public function tearDown(): void {
		WC_Facebookcommerce_Pixel::$render_cache = array();

		parent::tearDown();
	}

	/**
	 * Renders the pixel base code.
	 *
	 * @return string
	 */
	private function render(): string {
		return ( new WC_Facebookcommerce_Pixel() )->pixel_base_code();
	}

	public function test_opted_in_by_default() {
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG );

		$this->assertMatchesRegularExpression( '/capig:\s*true/', $this->render() );
	}

	public function test_opting_out_reaches_the_pixel() {
		update_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG, 'no' );

		$this->assertMatchesRegularExpression( '/capig:\s*false/', $this->render() );
	}

	public function test_filter_overrides_the_setting() {
		update_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG, 'yes' );
		$this->add_filter_with_safe_teardown( 'wc_facebook_is_capig_enabled', '__return_false' );

		$this->assertMatchesRegularExpression( '/capig:\s*false/', $this->render() );
	}

	public function test_opt_in_command_is_sent_only_when_opted_in() {
		$this->assertMatchesRegularExpression(
			"/if \\(this\\._config\\.capig === true\\) \\{\\s*fbq\\('optinMetaEnabledCapi', this\\._pixelId\\);\\s*\\}/",
			$this->render()
		);
	}

	public function test_opt_in_command_comes_before_pixel_init() {
		$output = $this->render();

		$opt_in = strpos( $output, "fbq('optinMetaEnabledCapi'" );
		$init   = strpos( $output, "fbq('init', this._pixelId" );

		$this->assertNotFalse( $opt_in );
		$this->assertNotFalse( $init );
		$this->assertLessThan( $init, $opt_in );
	}
}
