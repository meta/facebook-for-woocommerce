<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\Feed\Localization;

use WooCommerce\Facebook\Integrations\IntegrationRegistry;
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
	 * "Sync now" and the product reset job cover the whole catalog on a Polylang store.
	 *
	 * Polylang narrows product queries to the request's language: the admin's content language
	 * filter in an admin request, the default or cookie language in the Action Scheduler async
	 * runner. Products in another language, or in none, were left out of both, and so were their
	 * variations where those are translated too.
	 */
	public function test_polylang_full_sync_and_reset_cover_products_outside_the_request_language() {
		if ( ! function_exists( 'PLL' ) || ! function_exists( 'pll_set_post_language' ) || ! class_exists( '\PLL_Query' ) ) {
			$this->markTestSkipped( 'Polylang is not active for this test run' );
		}

		$model   = PLL()->model;
		$english = $model->get_language( 'en' );
		if ( ! $english || ! $model->get_language( 'es' ) ) {
			$this->markTestSkipped( 'Polylang has no English and Spanish languages in this test run' );
		}

		// Products and variations are translated post types, as with Polylang for WooCommerce.
		$translate_products = static function ( $post_types ) {
			$post_types['product']           = 'product';
			$post_types['product_variation'] = 'product_variation';
			return $post_types;
		};
		add_filter( 'pll_get_post_types', $translate_products );
		$model->cache->clean( 'post_types' );

		// Narrow queries to English the way PLL_Admin_Filters_Post and PLL_Frontend do.
		$narrow_to_english = static function ( $query ) use ( $model, $english ) {
			$pll_query = new \PLL_Query( $query, $model );
			$pll_query->filter_query( $english );
		};

		try {
			$this->assertTrue( $model->is_translated_post_type( 'product' ), 'Polylang translates products for this test.' );
			$this->assertTrue( $model->is_translated_post_type( 'product_variation' ), 'Polylang translates variations for this test.' );

			$english_product       = $this->create_simple_product();
			$spanish_product       = $this->create_simple_product();
			$language_less_product = $this->create_simple_product();

			pll_set_post_language( $english_product->get_id(), 'en' );
			pll_set_post_language( $spanish_product->get_id(), 'es' );
			wp_delete_object_term_relationships( $language_less_product->get_id(), 'language' );

			$expected = [ $english_product->get_id(), $spanish_product->get_id(), $language_less_product->get_id() ];

			// A variable product in no language, with variations in no language and Facebook IDs to reset.
			$variable_product = $this->create_variable_product();
			$variation_ids    = array_map( 'intval', $variable_product->get_children() );
			$this->assertNotEmpty( $variation_ids );

			foreach ( array_merge( [ $variable_product->get_id() ], $variation_ids ) as $post_id ) {
				wp_delete_object_term_relationships( $post_id, 'language' );
				update_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'item-' . $post_id );
			}

			// An empty children cache, as in a request that has not loaded the product before.
			delete_transient( 'wc_product_children_' . $variable_product->get_id() );

			add_action( 'parse_query', $narrow_to_english );

			$narrowed = array_map(
				'intval',
				get_posts(
					[
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'fields'         => 'ids',
						'posts_per_page' => -1,
					]
				)
			);
			$narrowed_variations = get_posts(
				[
					'post_type'      => 'product_variation',
					'post_parent'    => $variable_product->get_id(),
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				]
			);
			$sync_ids            = array_map( 'intval', \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );

			$job    = facebook_for_woocommerce()->job_manager->reset_all_product_fb_settings;
			$method = new \ReflectionMethod( $job, 'get_items_for_batch' );
			$method->setAccessible( true );
			$reset_ids = $method->invoke( $job, 1, [] );

			// Run the batch as the job does, which resets each product and looks up its variations.
			$process = new \ReflectionMethod( $job, 'process_items' );
			$process->setAccessible( true );
			$process->invoke( $job, $reset_ids, [] );
		} finally {
			remove_action( 'parse_query', $narrow_to_english );
			remove_filter( 'pll_get_post_types', $translate_products );
			$model->cache->clean( 'post_types' );
		}

		$this->assertSame( [ $english_product->get_id() ], array_values( array_intersect( $expected, $narrowed ) ), 'A product query without lang only sees the request language.' );
		$this->assertSame( [], array_values( array_diff( $expected, $sync_ids ) ), 'The full sync queues products in every language and in none.' );
		$this->assertSame( [], array_values( array_diff( $expected, $reset_ids ) ), 'The reset job covers products in every language and in none.' );

		$this->assertSame( [], $narrowed_variations, 'A variation query without lang only sees the request language.' );
		$this->assertSame( [], array_values( array_diff( $variation_ids, $sync_ids ) ), 'The full sync queues variations in no language.' );
		$this->assertContains( $variable_product->get_id(), $reset_ids, 'The reset job covers a variable product in no language.' );
		$this->assertSame( '', get_post_meta( $variable_product->get_id(), \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ), 'The reset batch clears the variable product.' );
		foreach ( $variation_ids as $variation_id ) {
			$this->assertSame( '', get_post_meta( $variation_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ), "The reset batch clears variation {$variation_id}." );
		}
	}

	/**
	 * The full sync feed has a visible row for variations outside the request language on a Polylang store.
	 *
	 * When a variable product has no saved sync or visibility setting, the sync check and the feed
	 * row both look at the product's variations. WooCommerce's children query is narrowed to the
	 * request's language when its children cache is empty, so they found none: the variation was
	 * rejected with "Sync disabled in product field" and the parent was saved as not synced.
	 */
	public function test_polylang_full_sync_feed_includes_variations_outside_the_request_language() {
		if ( ! function_exists( 'PLL' ) || ! function_exists( 'pll_set_post_language' ) || ! class_exists( '\PLL_Query' ) ) {
			$this->markTestSkipped( 'Polylang is not active for this test run' );
		}

		$model   = PLL()->model;
		$spanish = $model->get_language( 'es' );
		if ( ! $spanish || ! $model->get_language( 'en' ) ) {
			$this->markTestSkipped( 'Polylang has no English and Spanish languages in this test run' );
		}

		// Products and variations are translated post types, as with Polylang for WooCommerce.
		$translate_products = static function ( $post_types ) {
			$post_types['product']           = 'product';
			$post_types['product_variation'] = 'product_variation';
			return $post_types;
		};
		add_filter( 'pll_get_post_types', $translate_products );
		$model->cache->clean( 'post_types' );

		// Narrow queries to Spanish the way PLL_Admin_Filters_Post and PLL_Frontend do.
		$narrow_to_spanish = static function ( $query ) use ( $model, $spanish ) {
			$pll_query = new \PLL_Query( $query, $model );
			$pll_query->filter_query( $spanish );
		};

		$feed_path     = get_temp_dir() . uniqid( 'fb-polylang-feed-', true ) . '.csv';
		$temp_path     = $feed_path . '.tmp';
		$use_feed_path = static function () use ( $feed_path ) {
			return $feed_path;
		};
		$use_temp_path = static function () use ( $temp_path ) {
			return $temp_path;
		};
		add_filter( 'wc_facebook_product_catalog_feed_file_path', $use_feed_path );
		add_filter( 'wc_facebook_product_catalog_temp_feed_file_path', $use_temp_path );

		$sync_meta_key = \WooCommerce\Facebook\Products::get_product_sync_meta_key();
		$fixtures      = [];
		$rows          = [];

		try {
			$this->assertTrue( $model->is_translated_post_type( 'product_variation' ), 'Polylang translates variations for this test.' );

			// A variable product in the default language and one in no language, each with its variations.
			foreach ( [ 'variable product in English' => 'en', 'variable product in no language' => '' ] as $label => $language ) {
				$parent        = $this->create_variable_product();
				$variation_ids = array_map( 'intval', $parent->get_children() );
				$this->assertNotEmpty( $variation_ids );

				foreach ( array_merge( [ $parent->get_id() ], $variation_ids ) as $post_id ) {
					if ( $language ) {
						pll_set_post_language( $post_id, $language );
					} else {
						wp_delete_object_term_relationships( $post_id, 'language' );
					}
				}

				// A first sync in a request that has not loaded the product: nothing saved, nothing cached.
				delete_post_meta( $parent->get_id(), $sync_meta_key );
				delete_post_meta( $parent->get_id(), \WooCommerce\Facebook\Products::VISIBILITY_META_KEY );
				delete_transient( 'wc_product_children_' . $parent->get_id() );

				$fixtures[ $label ] = [
					'parent_id'     => $parent->get_id(),
					'variation_ids' => $variation_ids,
				];
			}

			add_action( 'parse_query', $narrow_to_spanish );

			$written = ( new \WC_Facebook_Product_Feed() )->write_product_feed_file( \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );
			$this->assertTrue( $written, 'The feed file is written.' );

			$handle = fopen( $feed_path, 'r' );
			$header = array_map( 'trim', fgetcsv( $handle, 0, ',', '"', '\\' ) );
			while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
				if ( count( $row ) === count( $header ) ) {
					$row                = array_combine( $header, $row );
					$rows[ $row['id'] ] = $row;
				}
			}
			fclose( $handle );
		} finally {
			remove_action( 'parse_query', $narrow_to_spanish );
			remove_filter( 'pll_get_post_types', $translate_products );
			remove_filter( 'wc_facebook_product_catalog_feed_file_path', $use_feed_path );
			remove_filter( 'wc_facebook_product_catalog_temp_feed_file_path', $use_temp_path );
			$model->cache->clean( 'post_types' );

			foreach ( [ $feed_path, $temp_path ] as $file ) {
				if ( file_exists( $file ) ) {
					unlink( $file );
				}
			}
		}

		foreach ( $fixtures as $label => $fixture ) {
			foreach ( $fixture['variation_ids'] as $variation_id ) {
				$retailer_id = \WC_Facebookcommerce_Utils::get_fb_retailer_id( wc_get_product( $variation_id ) );

				$this->assertArrayHasKey( $retailer_id, $rows, "Variation {$variation_id} of the {$label} is in the feed." );
				$this->assertSame( \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE, $rows[ $retailer_id ]['visibility'], "Variation {$variation_id} of the {$label} is visible." );
			}

			$this->assertSame( 'yes', get_post_meta( $fixture['parent_id'], $sync_meta_key, true ), "The {$label} is saved as synced." );
			$this->assertSame( 'yes', get_post_meta( $fixture['parent_id'], \WooCommerce\Facebook\Products::VISIBILITY_META_KEY, true ), "The {$label} is saved as visible." );
		}
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
