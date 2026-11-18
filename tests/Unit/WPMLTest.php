<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

use WooCommerce\Facebook\WPMLInjector;
use WooCommerce\Facebook\WPMLLanguageStatus;

class WPMLTest extends \WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering {

	/** @var int $fake_product_id */
	private $fake_product_id = 1;

	/**
	 * Tears down the fixture, for example, close a network connection.
	 *
	 * This method is called after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		WPMLInjector::$settings     = null;
		WPMLInjector::$default_lang = null;
		// No need to manually remove filters, parent tearDown will handle it
		parent::tear_down();
	}

	public function test_should_hide_product_when_wpml_filter_not_applied() {
		$this->assertTrue( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	public function test_product_hidden_when_wpml_filter_returns_wp_error() {
		$filter = $this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function() {
				return new WP_Error();
			}
		);

		$this->assertTrue( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	public function test_product_hidden_no_settings_and_not_default() {
		WPMLInjector::$default_lang = 'en_US';

		$filter = $this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function() {
				return [
					'language_code' => 'fr_FR',
				];
			}
		);

		$this->assertTrue( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	public function test_product_not_hidden_no_settings_and_default() {
		WPMLInjector::$default_lang = 'en_US';
		$filter = $this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function() {
				return [
					'language_code' => 'en_US',
				];
			}
		);

		$this->assertFalse( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	public function test_product_hidden_language_setting_not_visible() {
		WPMLInjector::$settings = [
			'fr_FR' => WPMLLanguageStatus::HIDDEN,
		];

		$filter = $this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function() {
				return [
					'language_code' => 'fr_FR',
				];
			}
		);

		$this->assertTrue( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	public function test_product_not_hidden_language_setting_visible() {
		WPMLInjector::$settings = [
			'fr_FR' => WPMLLanguageStatus::VISIBLE,
		];

		$filter = $this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function() {
				return [
					'language_code' => 'fr_FR',
				];
			}
		);

		$this->assertFalse( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	/**
	 * A product without a language is not hidden on a store where WPML itself is not running.
	 *
	 * Polylang answers the WPML filters through its compatibility layer and defines
	 * ICL_LANGUAGE_CODE, so the check runs there too. It reports a product in no language as an
	 * empty array, which read as "not the default language" and hid the product.
	 *
	 * @dataProvider provide_language_details_without_a_language
	 *
	 * @param array $language_details The answer to the wpml_post_language_details filter.
	 */
	public function test_product_without_language_not_hidden_when_wpml_is_not_running( array $language_details ) {
		WPMLInjector::$default_lang = 'en';

		$this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function () use ( $language_details ) {
				return $language_details;
			}
		);

		$this->assertFalse( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	/**
	 * The same product is not hidden either once the store has saved language visibility settings.
	 *
	 * @dataProvider provide_language_details_without_a_language
	 *
	 * @param array $language_details The answer to the wpml_post_language_details filter.
	 */
	public function test_product_without_language_not_hidden_with_language_settings( array $language_details ) {
		WPMLInjector::$default_lang = 'en';
		WPMLInjector::$settings     = [
			'en' => WPMLLanguageStatus::VISIBLE,
			'fr' => WPMLLanguageStatus::HIDDEN,
		];

		$this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function () use ( $language_details ) {
				return $language_details;
			}
		);

		$this->assertFalse( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	/**
	 * WPML's own answers are left as they are: a product it reports without a language stays hidden.
	 *
	 * @dataProvider provide_language_details_without_a_language
	 *
	 * @param array $language_details The answer to the wpml_post_language_details filter.
	 */
	public function test_product_without_language_stays_hidden_when_wpml_is_running( array $language_details ) {
		WPMLInjector::$default_lang = 'en';

		$this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function () use ( $language_details ) {
				return $language_details;
			}
		);

		$injector_on_wpml = new class() extends WPMLInjector {
			public function __construct() {}

			protected static function is_wpml_running(): bool {
				return true;
			}
		};

		$this->assertTrue( $injector_on_wpml::should_hide( $this->fake_product_id ) );
	}

	/**
	 * A product in another language is still hidden when WPML is not running.
	 */
	public function test_product_in_another_language_still_hidden_when_wpml_is_not_running() {
		WPMLInjector::$default_lang = 'en';

		$this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function () {
				return [
					'language_code' => 'es',
				];
			}
		);

		$this->assertTrue( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	/**
	 * The wc_facebook_wpml_should_hide_product filter still has the last word for a product without a language.
	 */
	public function test_product_without_language_can_be_hidden_with_the_filter() {
		WPMLInjector::$default_lang = 'en';

		$this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function () {
				return [];
			}
		);
		$this->add_filter_with_safe_teardown( 'wc_facebook_wpml_should_hide_product', '__return_true' );

		$this->assertTrue( WPMLInjector::should_hide( $this->fake_product_id ) );
	}

	/**
	 * The product data sent to the catalog keeps a product without a language visible.
	 *
	 * Runs in its own process because the visibility override only runs once ICL_LANGUAGE_CODE
	 * is defined, as Polylang's compatibility layer does, and a constant cannot be taken back.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_prepared_product_without_language_is_visible_when_wpml_is_not_running() {
		define( 'ICL_LANGUAGE_CODE', 'all' );

		WPMLInjector::$default_lang = 'en';
		WPMLInjector::$settings     = null;

		$language_less_product = WC_Helper_Product::create_simple_product();
		$translated_product    = WC_Helper_Product::create_simple_product();

		// Not kept on the test case: a closure there cannot be passed back from the separate process when the test fails.
		$translated_product_id = $translated_product->get_id();
		add_filter(
			'wpml_post_language_details',
			static function ( $details, $product_id ) use ( $translated_product_id ) {
				return (int) $product_id === $translated_product_id ? [ 'language_code' => 'es' ] : [];
			},
			10,
			2
		);

		$language_less_data = ( new WC_Facebook_Product( $language_less_product ) )->prepare_product( null, WC_Facebook_Product::PRODUCT_PREP_TYPE_FEED );
		$translated_data    = ( new WC_Facebook_Product( $translated_product ) )->prepare_product( null, WC_Facebook_Product::PRODUCT_PREP_TYPE_FEED );

		$this->assertSame( WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE, $language_less_data['visibility'], 'A product in no language is visible.' );
		$this->assertSame( WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN, $translated_data['visibility'], 'A product in another language is still hidden.' );
	}

	/**
	 * The shapes in which a compatibility layer reports a product without a language.
	 *
	 * @return array<string, array{0: array}>
	 */
	public function provide_language_details_without_a_language(): array {
		return [
			'empty array, as Polylang answers' => [ [] ],
			'null language code'               => [ [ 'language_code' => null ] ],
			'empty language code'              => [ [ 'language_code' => '' ] ],
		];
	}
}
