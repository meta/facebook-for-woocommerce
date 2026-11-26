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
		$attached_to_variations = $this->attach_language_taxonomy_to_variations();

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
			$this->detach_language_taxonomy_from_variations( $attached_to_variations );
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
		$attached_to_variations = $this->attach_language_taxonomy_to_variations();

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
			$this->detach_language_taxonomy_from_variations( $attached_to_variations );
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
	 * Polylang's answer for a product without a language does not hide the product from the catalog.
	 *
	 * Polylang answers the WPML filters through its compatibility layer and defines
	 * ICL_LANGUAGE_CODE, so the legacy WPML visibility check runs on Polylang stores. A product in
	 * no language read as "not the default language" and reached the catalog hidden.
	 */
	public function test_polylang_product_without_language_is_not_hidden_by_the_wpml_visibility_check() {
		if ( ! function_exists( 'PLL' ) || ! function_exists( 'pll_set_post_language' ) || ! class_exists( '\PLL_WPML_Compat' ) ) {
			$this->markTestSkipped( 'Polylang is not active for this test run' );
		}

		$model = PLL()->model;
		if ( ! $model->get_language( 'en' ) || ! $model->get_language( 'es' ) ) {
			$this->markTestSkipped( 'Polylang has no English and Spanish languages in this test run' );
		}

		// Polylang's own answer to the WPML filter, registered the way its compatibility layer does.
		if ( ! function_exists( 'wpml_get_language_information' ) ) {
			$legacy_api = dirname( ( new \ReflectionClass( '\PLL_WPML_Compat' ) )->getFileName() ) . '/wpml-legacy-api.php';
			if ( file_exists( $legacy_api ) ) {
				require_once $legacy_api;
			}
		}
		if ( ! function_exists( 'wpml_get_language_information' ) ) {
			$this->markTestSkipped( 'Polylang\'s WPML compatibility API is not available in this test run' );
		}

		$translate_products = static function ( $post_types ) {
			$post_types['product'] = 'product';
			return $post_types;
		};
		add_filter( 'pll_get_post_types', $translate_products );
		$model->cache->clean( 'post_types' );
		add_filter( 'wpml_post_language_details', 'wpml_get_language_information', 10, 2 );

		$previous_default_lang = \WooCommerce\Facebook\WPMLInjector::$default_lang;
		$previous_settings     = \WooCommerce\Facebook\WPMLInjector::$settings;

		\WooCommerce\Facebook\WPMLInjector::$default_lang = pll_default_language();
		\WooCommerce\Facebook\WPMLInjector::$settings     = null;

		try {
			$english_product       = $this->create_simple_product();
			$spanish_product       = $this->create_simple_product();
			$language_less_product = $this->create_simple_product();

			pll_set_post_language( $english_product->get_id(), 'en' );
			pll_set_post_language( $spanish_product->get_id(), 'es' );
			wp_delete_object_term_relationships( $language_less_product->get_id(), 'language' );

			$language_less_details = apply_filters( 'wpml_post_language_details', null, $language_less_product->get_id() );
			$hides_language_less   = \WooCommerce\Facebook\WPMLInjector::should_hide( $language_less_product->get_id() );
			$hides_english         = \WooCommerce\Facebook\WPMLInjector::should_hide( $english_product->get_id() );
			$hides_spanish         = \WooCommerce\Facebook\WPMLInjector::should_hide( $spanish_product->get_id() );
		} finally {
			\WooCommerce\Facebook\WPMLInjector::$default_lang = $previous_default_lang;
			\WooCommerce\Facebook\WPMLInjector::$settings     = $previous_settings;

			remove_filter( 'wpml_post_language_details', 'wpml_get_language_information', 10 );
			remove_filter( 'pll_get_post_types', $translate_products );
			$model->cache->clean( 'post_types' );
		}

		$this->assertSame( 'en', pll_default_language(), 'English is the default language for this test.' );
		$this->assertSame( [], $language_less_details, 'Polylang reports a product without a language as an empty array.' );
		$this->assertFalse( $hides_language_less, 'A product in no language is not hidden.' );
		$this->assertFalse( $hides_english, 'A product in the default language is not hidden.' );
		$this->assertTrue( $hides_spanish, 'A product in another language is still hidden.' );
	}

	/**
	 * Deleting a variable product from the catalog queues the removal of variations outside the request language.
	 *
	 * The metabox delete action queues the remote deletes, then resets the product in every
	 * language. The deletes were read from WooCommerce's narrowed children query, so a variation
	 * outside the request language lost its Facebook item ID with no delete queued.
	 */
	public function test_polylang_deleting_a_variable_product_queues_deletes_for_variations_outside_the_request_language() {
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
		$attached_to_variations = $this->attach_language_taxonomy_to_variations();

		// Narrow queries to Spanish the way PLL_Admin_Filters_Post and PLL_Frontend do.
		$narrow_to_spanish = static function ( $query ) use ( $model, $spanish ) {
			$pll_query = new \PLL_Query( $query, $model );
			$pll_query->filter_query( $spanish );
		};

		$sync_handler = facebook_for_woocommerce()->get_products_sync_handler();
		$requests     = new \ReflectionProperty( $sync_handler, 'requests' );
		$requests->setAccessible( true );
		$requests_before = $requests->getValue( $sync_handler );

		$fixtures = [];
		$queued   = [];

		try {
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

				foreach ( $variation_ids as $variation_id ) {
					update_post_meta( $variation_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'item-' . $variation_id );
				}

				// A pending update for each variation, and an empty children cache.
				$sync_handler->create_or_update_products( $variation_ids );
				delete_transient( 'wc_product_children_' . $parent->get_id() );

				$fixtures[ $label ] = [
					'parent_id'     => $parent->get_id(),
					'variation_ids' => $variation_ids,
				];
			}

			add_action( 'parse_query', $narrow_to_spanish );

			// What the metabox delete action does.
			foreach ( $fixtures as $fixture ) {
				$this->integration->delete_fb_product( wc_get_product( $fixture['parent_id'] ) );
				$this->integration->reset_single_product( $fixture['parent_id'] );
			}

			$queued = $requests->getValue( $sync_handler );
		} finally {
			remove_action( 'parse_query', $narrow_to_spanish );
			remove_filter( 'pll_get_post_types', $translate_products );
			$this->detach_language_taxonomy_from_variations( $attached_to_variations );
			$model->cache->clean( 'post_types' );
			$requests->setValue( $sync_handler, $requests_before );
		}

		foreach ( $fixtures as $label => $fixture ) {
			foreach ( $fixture['variation_ids'] as $variation_id ) {
				$index = \WooCommerce\Facebook\Products\Sync::PRODUCT_INDEX_PREFIX . \WC_Facebookcommerce_Utils::get_fb_retailer_id( wc_get_product( $variation_id ) );

				$this->assertSame( '', get_post_meta( $variation_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ), "The item ID of variation {$variation_id} of the {$label} is cleared." );
				$this->assertArrayHasKey( $index, $queued, "Variation {$variation_id} of the {$label} is queued." );
				$this->assertSame( \WooCommerce\Facebook\Products\Sync::ACTION_DELETE, $queued[ $index ], "Variation {$variation_id} of the {$label} is queued for deletion." );
			}
		}
	}

	/**
	 * A product class that narrows WooCommerce's own children still sees them in every language on a Polylang store.
	 *
	 * The class keeps the first variation of parent::get_children(). In a Spanish request with an
	 * empty children cache that lookup found nothing for an English product, so its sync-enabled
	 * variation was rejected and the parent was saved as not synced, which later syncs kept to.
	 */
	public function test_polylang_sync_check_accepts_the_variation_a_product_class_keeps_outside_the_request_language() {
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
		$attached_to_variations = $this->attach_language_taxonomy_to_variations();

		// Narrow queries to Spanish the way PLL_Admin_Filters_Post and PLL_Frontend do.
		$narrow_to_spanish = static function ( $query ) use ( $model, $spanish ) {
			$pll_query = new \PLL_Query( $query, $model );
			$pll_query->filter_query( $spanish );
		};

		$sync_meta_key = \WooCommerce\Facebook\Products::get_product_sync_meta_key();
		$parent_id     = 0;
		$use_class     = static function ( $class_name, $product_type, $post_type, $product_id ) use ( &$parent_id ) {
			return (int) $product_id === $parent_id ? Variable_Product_Keeping_Its_First_Child::class : $class_name;
		};

		try {
			$parent        = $this->create_variable_product();
			$parent_id     = $parent->get_id();
			$variation_ids = array_map( 'intval', $parent->get_children() );
			$this->assertGreaterThan( 1, count( $variation_ids ) );

			foreach ( array_merge( [ $parent_id ], $variation_ids ) as $post_id ) {
				pll_set_post_language( $post_id, 'en' );
			}

			// The other variations have sync disabled, so the outcome depends on the one the class keeps.
			foreach ( array_slice( $variation_ids, 1 ) as $sibling_id ) {
				update_post_meta( $sibling_id, $sync_meta_key, 'no' );
			}

			// A first sync in a request that has not loaded the product: nothing saved, nothing cached.
			delete_post_meta( $parent_id, $sync_meta_key );
			delete_transient( 'wc_product_children_' . $parent_id );

			add_filter( 'woocommerce_product_class', $use_class, 10, 4 );
			add_action( 'parse_query', $narrow_to_spanish );

			$this->assertInstanceOf( Variable_Product_Keeping_Its_First_Child::class, wc_get_product( $parent_id ) );

			$validator = facebook_for_woocommerce()->get_product_sync_validator( wc_get_product( $variation_ids[0] ) );
			$method    = new \ReflectionMethod( $validator, 'validate_product_sync_field' );
			$method->setAccessible( true );

			try {
				$method->invoke( $validator );
				$rejection = '';
			} catch ( \Exception $exception ) {
				$rejection = $exception->getMessage();
			}

			$parent_sync    = get_post_meta( $parent_id, $sync_meta_key, true );
			$children_cache = get_transient( 'wc_product_children_' . $parent_id );
		} finally {
			remove_action( 'parse_query', $narrow_to_spanish );
			remove_filter( 'woocommerce_product_class', $use_class, 10 );
			remove_filter( 'pll_get_post_types', $translate_products );
			$this->detach_language_taxonomy_from_variations( $attached_to_variations );
			$model->cache->clean( 'post_types' );
		}

		$this->assertSame( '', $rejection, 'The variation the class keeps passes the sync check.' );
		$this->assertSame( 'yes', $parent_sync, 'The parent is saved as synced.' );
		$this->assertFalse( $children_cache, 'The children cache storefront requests use is left empty.' );
	}

	/**
	 * A language's translation feed has its rows whichever language the request is in on a Polylang store.
	 *
	 * The feed starts from the default language's products and looks up their translations. That
	 * first query carried no 'lang', so with Spanish selected in the Polylang admin it only saw
	 * Spanish products, found no English originals and wrote a feed with nothing but the header.
	 * A variable product's rows come from its variations, read the same way.
	 */
	public function test_polylang_translation_feed_has_rows_in_another_request_language() {
		if ( ! function_exists( 'PLL' ) || ! function_exists( 'pll_set_post_language' ) || ! class_exists( '\PLL_Query' ) ) {
			$this->markTestSkipped( 'Polylang is not active for this test run' );
		}

		$integration = IntegrationRegistry::get_active_localization_integration();
		if ( ! $integration || 'Polylang' !== $integration->get_plugin_name() ) {
			$this->markTestSkipped( 'Polylang is not the active localization integration in this test run' );
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
		$attached_to_variations = $this->attach_language_taxonomy_to_variations();

		// Narrow queries to Spanish the way PLL_Admin_Filters_Post and PLL_Frontend do.
		$narrow_to_spanish = static function ( $query ) use ( $model, $spanish ) {
			$pll_query = new \PLL_Query( $query, $model );
			$pll_query->filter_query( $spanish );
		};

		$translation = [
			'name'              => 'Producto traducido',
			'description'       => 'Una descripción traducida.',
			'short_description' => 'Traducido.',
		];

		try {
			$simple_product   = $this->create_simple_product();
			$variable_product = $this->create_variable_product();
			$variation_ids    = array_map( 'intval', $variable_product->get_children() );
			$this->assertNotEmpty( $variation_ids );

			foreach ( array_merge( [ $simple_product->get_id(), $variable_product->get_id() ], $variation_ids ) as $post_id ) {
				pll_set_post_language( $post_id, 'en' );
			}

			$this->assertNotNull( $integration->create_product_translation( $simple_product->get_id(), 'es_ES', $translation ) );
			$this->assertNotNull( $integration->create_product_translation( $variable_product->get_id(), 'es_ES', $translation ) );

			delete_transient( 'wc_product_children_' . $variable_product->get_id() );

			add_action( 'parse_query', $narrow_to_spanish );

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
			$originals = array_map( 'intval', $integration->get_products_from_default_language( -1, 0 ) );
			$csv       = ( new \WooCommerce\Facebook\Feed\Localization\LanguageFeedData() )->get_language_csv_data( 'es_ES', -1, 0 );
		} finally {
			remove_action( 'parse_query', $narrow_to_spanish );
			remove_filter( 'pll_get_post_types', $translate_products );
			$this->detach_language_taxonomy_from_variations( $attached_to_variations );
			$model->cache->clean( 'post_types' );
		}

		$row_ids = array_map( 'strval', array_column( $csv['data'], 'id' ) );

		$this->assertNotContains( $simple_product->get_id(), $narrowed, 'A product query without lang does not see the English originals.' );
		$this->assertContains( $simple_product->get_id(), $originals, 'The English simple product is found.' );
		$this->assertContains( $variable_product->get_id(), $originals, 'The English variable product is found.' );
		$this->assertContains( \WC_Facebookcommerce_Utils::get_fb_retailer_id( $simple_product ), $row_ids, 'The simple product has a translation row.' );

		foreach ( $variation_ids as $variation_id ) {
			$this->assertContains( \WC_Facebookcommerce_Utils::get_fb_retailer_id( wc_get_product( $variation_id ) ), $row_ids, "Variation {$variation_id} has a translation row." );
		}
	}

	/**
	 * Attaches Polylang's language taxonomy to variations, as Polylang does at startup on a store
	 * where variations are translated. The pll_get_post_types filter is added too late for that here.
	 *
	 * @return bool Whether the taxonomy was attached by this call.
	 */
	private function attach_language_taxonomy_to_variations(): bool {
		if ( is_object_in_taxonomy( 'product_variation', 'language' ) ) {
			return false;
		}

		return register_taxonomy_for_object_type( 'language', 'product_variation' );
	}

	/**
	 * Undoes attach_language_taxonomy_to_variations().
	 *
	 * @param bool $attached What attach_language_taxonomy_to_variations() returned.
	 */
	private function detach_language_taxonomy_from_variations( bool $attached ): void {
		if ( $attached ) {
			unregister_taxonomy_for_object_type( 'language', 'product_variation' );
		}
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
		// FB_TEST_PLUGIN holds a comma-separated list, e.g. "polylang,wcpos".
		foreach ( [ 'wpml', 'polylang' ] as $plugin ) {
			if ( \Automattic\WooCommerce\GoogleListingsAndAds\Tests\fb_test_plugin_requested( $plugin ) ) {
				return $plugin;
			}
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
		// Only run for Polylang tests. Polylang may be one of several requested plugins.
		if ( ! \Automattic\WooCommerce\GoogleListingsAndAds\Tests\fb_test_plugin_requested( 'polylang' ) ) {
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

/**
 * A variable product whose class narrows the children WooCommerce reads for it.
 */
class Variable_Product_Keeping_Its_First_Child extends \WC_Product_Variable {

	public function get_children( $visible_only = '' ) {
		return array_slice( parent::get_children( $visible_only ), 0, 1 );
	}
}
