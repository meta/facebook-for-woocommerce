<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\Feed\Localization;

use WooCommerce\Facebook\Feed\Localization\LanguageFeedData;
use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Integrations\Polylang;
use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for localization integration setup and error handling
 *
 * Tests edge cases and error conditions not covered by end-to-end feed generation tests.
 *
 * @since 3.6.0
 */
class LocalizationIntegrationTest extends IntegrationTestCase {

	/**
	 * @var string|null Expected plugin to be active for this test run
	 */
	private static $expected_plugin = null;

	/**
	 * Set up the test class
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$expected_plugin = self::getExpectedPlugin();
	}

	/**
	 * Set up each test
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure Polylang is properly set up for this test
		$this->ensurePolylangSetup();
	}

	/**
	 * Test that the expected plugin is active if specified
	 */
	public function test_expected_plugin_is_active() {
		if ( ! self::$expected_plugin ) {
			$this->markTestSkipped( 'No specific plugin expected for this test run' );
			return;
		}

		$detected_plugin = $this->detectActiveLocalizationPlugin();

		$this->assertEquals(
			self::$expected_plugin,
			$detected_plugin,
			sprintf(
				'Expected %s plugin to be active, but %s was detected. Make sure you\'re using the correct bootstrap file.',
				strtoupper( self::$expected_plugin ),
				$detected_plugin ? strtoupper( $detected_plugin ) : 'no plugin'
			)
		);
	}

	/**
	 * Only published translations should contribute language override rows.
	 *
	 * @dataProvider unpublished_translation_statuses
	 * @param string $status Translation post status.
	 */
	public function test_unpublished_polylang_translations_are_excluded( string $status ): void {
		$polylang = new Polylang();
		if ( ! $polylang->is_available() ) {
			$this->markTestSkipped( 'An active Polylang integration is required.' );
		}

		$product    = $this->create_simple_product( [ 'name' => 'Original product' ] );
		$product_id = $product->get_id();
		$spanish_id = $polylang->create_product_translation( $product_id, 'es_ES', [ 'name' => 'Producto traducido' ] );
		$french_id  = $polylang->create_product_translation( $product_id, 'fr_FR', [ 'name' => 'Produit traduit' ] );
		$this->assertNotNull( $spanish_id );
		$this->assertNotNull( $french_id );
		pll_save_post_translations( [ 'en' => $product_id, 'es' => $spanish_id, 'fr' => $french_id ] );

		$feed_data = new LanguageFeedData();
		$this->assertCount( 1, $feed_data->get_language_csv_data( 'es_ES', -1 )['data'] );

		if ( 'trash' === $status ) {
			wp_trash_post( $spanish_id );
		} else {
			wp_update_post( [
				'ID'            => $spanish_id,
				'post_status'   => $status,
				'post_date'     => '2099-01-01 00:00:00',
				'post_date_gmt' => '2099-01-01 00:00:00',
			] );
		}
		$this->assertSame( $status, get_post_status( $spanish_id ) );
		$this->assertSame( $spanish_id, pll_get_post( $product_id, 'es' ), 'Polylang still resolves the unpublished translation.' );

		$details = $polylang->get_product_translation_details( $product_id );
		$this->assertArrayNotHasKey( 'es_ES', $details['translations'] );
		$this->assertArrayNotHasKey( 'es_ES', $details['translation_status'] );
		$this->assertArrayNotHasKey( 'es_ES', $details['translated_fields'] ?? [] );
		$this->assertSame( $french_id, $details['translations']['fr_FR'] );
		$this->assertSame( 'complete', $details['translation_status']['fr_FR'] );
		$this->assertSame( [], $feed_data->get_language_csv_data( 'es_ES', -1 )['data'] );
		$this->assertCount( 1, $feed_data->get_language_csv_data( 'fr_FR', -1 )['data'] );

		if ( 'trash' === $status ) {
			wp_untrash_post( $spanish_id );
		}
		wp_update_post( [
			'ID'            => $spanish_id,
			'post_status'   => 'publish',
			'post_date'     => current_time( 'mysql' ),
			'post_date_gmt' => current_time( 'mysql', true ),
		] );
		$details = $polylang->get_product_translation_details( $product_id );
		$this->assertSame( $spanish_id, $details['translations']['es_ES'] );
		$this->assertSame( 'complete', $details['translation_status']['es_ES'] );
		$this->assertCount( 1, $feed_data->get_language_csv_data( 'es_ES', -1 )['data'] );
	}

	/**
	 * @return array Unpublished translation statuses.
	 */
	public function unpublished_translation_statuses(): array {
		return [
			'trashed'   => [ 'trash' ],
			'draft'     => [ 'draft' ],
			'pending'   => [ 'pending' ],
			'private'   => [ 'private' ],
			'scheduled' => [ 'future' ],
		];
	}

	/**
	 * Get the expected plugin from command line arguments or environment variables
	 */
	private static function getExpectedPlugin(): ?string {
		$env_plugin = getenv( 'FB_TEST_PLUGIN' );
		if ( $env_plugin && in_array( strtolower( $env_plugin ), [ 'wpml', 'polylang' ], true ) ) {
			return strtolower( $env_plugin );
		}

		return null;
	}

	/**
	 * Detect which localization plugin is currently active using the IntegrationRegistry
	 */
	private function detectActiveLocalizationPlugin(): ?string {
		$integrations = IntegrationRegistry::get_all_localization_integrations();

		foreach ( $integrations as $plugin_key => $integration ) {
			if ( $integration->is_available() ) {
				return $plugin_key;
			}
		}

		return null;
	}

	/**
	 * Ensure Polylang is properly set up for testing
	 */
	private function ensurePolylangSetup(): void {
		// Only run for Polylang tests
		$test_plugin = getenv( 'FB_TEST_PLUGIN' );
		if ( $test_plugin !== 'polylang' ) {
			return;
		}

		global $wpdb;

		// Check if Polylang language terms exist
		$polylang_terms = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}terms t
			INNER JOIN {$wpdb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy IN ('language', 'term_language')"
		);

		// If no Polylang terms exist, recreate them
		if ( $polylang_terms == 0 ) {
			$this->recreatePolylangLanguages();
		}
	}

	/**
	 * Recreate Polylang language terms
	 */
	private function recreatePolylangLanguages(): void {
		$languages_to_recreate = [
			[
				'name'        => 'English',
				'slug'        => 'en',
				'locale'      => 'en_US',
				'rtl'         => false,
				'term_group'  => 1,
				'flag'        => 'us',
			],
			[
				'name'        => 'Español',
				'slug'        => 'es',
				'locale'      => 'es_ES',
				'rtl'         => false,
				'term_group'  => 2,
				'flag'        => 'es',
			],
			[
				'name'        => 'Français',
				'slug'        => 'fr',
				'locale'      => 'fr_FR',
				'rtl'         => false,
				'term_group'  => 3,
				'flag'        => 'fr',
			],
			[
				'name'        => 'Deutsch',
				'slug'        => 'de',
				'locale'      => 'de_DE',
				'rtl'         => false,
				'term_group'  => 4,
				'flag'        => 'de',
			]
		];

		foreach ( $languages_to_recreate as $lang_data ) {
			$existing_term = get_term_by( 'slug', $lang_data['slug'], 'language' );
			if ( $existing_term ) {
				continue;
			}

			$language_description = serialize([
				'locale' => $lang_data['locale'],
				'rtl' => $lang_data['rtl'] ? 1 : 0,
				'flag_code' => $lang_data['flag']
			]);

			$term_result = wp_insert_term( $lang_data['name'], 'language', [
				'slug' => $lang_data['slug'],
				'description' => $language_description
			]);

			if ( ! is_wp_error( $term_result ) ) {
				$term_id = $term_result['term_id'];
				wp_update_term( $term_id, 'language', [ 'term_group' => $lang_data['term_group'] ] );

				foreach ( [ 'post_language', 'term_language' ] as $taxonomy ) {
					wp_insert_term( 'pll_' . $lang_data['slug'], $taxonomy, [
						'slug' => 'pll_' . $lang_data['slug'],
						'description' => 'Language term for ' . $lang_data['name']
					]);
				}
			}
		}

		$polylang_options = get_option( 'polylang', [] );
		if ( empty( $polylang_options['default_lang'] ) ) {
			$polylang_options['default_lang'] = 'en';
			update_option( 'polylang', $polylang_options );
		}

		if ( empty( $polylang_options['post_types'] ) ) {
			$polylang_options['post_types'] = [ 'post', 'page', 'product' ];
			$polylang_options['taxonomies'] = [ 'category', 'post_tag', 'product_cat', 'product_tag' ];
			update_option( 'polylang', $polylang_options );
		}
	}

}
