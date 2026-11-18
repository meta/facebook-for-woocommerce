<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\ProductValidation;

use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;
use WooCommerce\Facebook\Tests\Integration\PolylangLanguagesTrait;

/**
 * Tests the validator's default language check against a real Polylang.
 *
 * Runs only when Polylang is active (FB_TEST_PLUGIN=polylang).
 */
class ProductValidatorPolylangLanguageTest extends IntegrationTestCase {

	use PolylangLanguagesTrait;

	public function setUp(): void {
		parent::setUp();
		$this->set_up_polylang_languages();
	}

	public function tearDown(): void {
		parent::tearDown();
		$this->restore_polylang_languages();
	}

	public function test_default_language_product_syncs_and_its_translation_does_not(): void {
		$original    = $this->create_simple_product();
		$translation = $this->create_simple_product();
		pll_set_post_language( $original->get_id(), 'en' );
		pll_set_post_language( $translation->get_id(), 'es' );

		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $original )->passes_all_checks() );
		$this->assertFalse( facebook_for_woocommerce()->get_product_sync_validator( $translation )->passes_all_checks() );
	}

	/**
	 * Polylang slugs are free text, so 'br' for pt_BR shares nothing with the locale.
	 */
	public function test_default_language_with_a_slug_unlike_its_locale(): void {
		$this->add_language( 'br', 'pt_BR' );
		$this->set_default_language( 'br' );

		$integration = IntegrationRegistry::get_active_localization_integration();
		$this->assertSame( 'pt_BR', $integration->get_default_language() );
		$this->assertSame( 'br', $integration->get_default_language_identifier() );

		$original    = $this->create_simple_product();
		$translation = $this->create_simple_product();
		pll_set_post_language( $original->get_id(), 'br' );
		pll_set_post_language( $translation->get_id(), 'en' );

		$this->assertTrue(
			facebook_for_woocommerce()->get_product_sync_validator( $original )->passes_all_checks(),
			'A product in the default language belongs in the main catalog whatever the slug.'
		);
		$this->assertFalse( facebook_for_woocommerce()->get_product_sync_validator( $translation )->passes_all_checks() );
	}

	public function test_product_without_a_language_syncs(): void {
		$product = $this->create_simple_product();

		$this->assertFalse( pll_get_post_language( $product->get_id() ) );
		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $product )->passes_all_checks() );
	}
}
