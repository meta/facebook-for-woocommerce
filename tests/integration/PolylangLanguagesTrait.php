<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration;

use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Integrations\Polylang;
use WooCommerce\Facebook\RolloutSwitches;

/**
 * Gives a test a real Polylang with languages of its own choosing.
 *
 * The test is skipped unless Polylang is active (FB_TEST_PLUGIN=polylang). Call
 * set_up_polylang_languages() after parent::setUp() and restore_polylang_languages()
 * after parent::tearDown().
 */
trait PolylangLanguagesTrait {

	/** @var string|null Polylang's default language slug before the test */
	private $previous_default_slug;

	/**
	 * Starts the test with English as the default language, Spanish as a second
	 * language and language override feeds on.
	 */
	private function set_up_polylang_languages(): void {
		IntegrationRegistry::clear_cache();
		if ( ! function_exists( 'PLL' ) || ! IntegrationRegistry::get_active_localization_integration() instanceof Polylang ) {
			$this->markTestSkipped( 'Polylang is not active' );
		}

		// Only default language products go to the main catalog while language override feeds are on.
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

	/**
	 * Puts Polylang back the way the test found it.
	 */
	private function restore_polylang_languages(): void {
		if ( null === $this->previous_default_slug ) {
			return;
		}

		PLL()->options['default_lang'] = $this->previous_default_slug;

		// The languages a test added are rolled back with the database; drop Polylang's copy of them too.
		PLL()->model->clean_languages_cache();
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
}
