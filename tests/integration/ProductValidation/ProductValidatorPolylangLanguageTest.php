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
use WooCommerce\Facebook\Integrations\Polylang;
use WooCommerce\Facebook\RolloutSwitches;
use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;

/**
 * Tests the validator's default language check against a real Polylang.
 *
 * Runs only when Polylang is active (FB_TEST_PLUGIN=polylang).
 */
class ProductValidatorPolylangLanguageTest extends IntegrationTestCase {

	/** @var string|null Polylang's default language slug before the test */
	private $previous_default_slug;

	public function setUp(): void {
		parent::setUp();

		IntegrationRegistry::clear_cache();
		if ( ! function_exists( 'PLL' ) || ! IntegrationRegistry::get_active_localization_integration() instanceof Polylang ) {
			$this->markTestSkipped( 'Polylang is not active' );
		}

		// The language check only runs while language override feeds are on.
		update_option(
			'wc_facebook_for_woocommerce_rollout_switches',
			array( RolloutSwitches::SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED => 'yes' )
		);

		$this->previous_default_slug = PLL()->options['default_lang'];

		/*
		 * Start from the languages the database holds. WordPress deletes all terms after each
		 * test class, Polylang's languages among them, while Polylang keeps serving its copy.
		 */
		PLL()->model->clean_languages_cache();
		$this->add_language( 'en', 'en_US' );
		$this->add_language( 'es', 'es_ES' );
		$this->set_default_language( 'en' );
	}

	public function tearDown(): void {
		if ( null !== $this->previous_default_slug ) {
			PLL()->options['default_lang'] = $this->previous_default_slug;
		}

		parent::tearDown();

		// The languages a test added are rolled back with the database; drop Polylang's copy of them too.
		if ( null !== $this->previous_default_slug ) {
			PLL()->model->clean_languages_cache();
		}
	}

	/**
	 * Adds a language unless the site already has it.
	 *
	 * @param string $slug   Polylang slug
	 * @param string $locale WordPress locale
	 */
	private function add_language( string $slug, string $locale ): void {
		if ( PLL()->model->get_language( $slug ) ) {
			return;
		}

		$result = PLL()->model->add_language(
			array(
				'name'       => $locale,
				'slug'       => $slug,
				'locale'     => $locale,
				'rtl'        => 0,
				'term_group' => 0,
			)
		);
		$this->assertNotWPError( $result );
		PLL()->model->clean_languages_cache();
	}

	/**
	 * Makes a language the default one for the rest of the test.
	 *
	 * @param string $slug Polylang slug
	 */
	private function set_default_language( string $slug ): void {
		PLL()->options['default_lang'] = $slug;
		PLL()->model->clean_languages_cache();

		$this->assertSame( $slug, pll_default_language() );
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
