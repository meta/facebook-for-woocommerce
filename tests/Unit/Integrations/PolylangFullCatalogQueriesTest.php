<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Integrations;

use Automattic\WooCommerce\ActionSchedulerJobFramework\Proxies\ActionScheduler;
use WooCommerce\Facebook\Jobs\ResetAllProductsFBSettings;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * The full sync and both product resets must cover the whole catalog on a Polylang store.
 *
 * Polylang adds a language condition to every post query on a translated post type that carries
 * no 'lang' argument: the admin's content language filter in an admin request, the request's
 * language in a frontend or loopback request. Products in another language, or in none, then drop
 * out. These tests reproduce that narrowing with a 'language' taxonomy and a parse_query hook that
 * follow Polylang's rule (PLL_Query::filter_query), so they run without Polylang installed.
 *
 * Products and variations are both translated here, as with Polylang for WooCommerce, so the
 * variation queries are held to the same rule. The variations carry no language of their own.
 */
class PolylangFullCatalogQueriesTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var bool Whether this test registered the language taxonomy. */
	private $registered_language_taxonomy = false;

	/** @var int Term taxonomy ID of the simulated request language. */
	private $language_tt_id;

	/** @var callable The simulated Polylang query filter. */
	private $language_filter;

	/** @var \WC_Product_Simple A product in the request's language. */
	private $english_product;

	/** @var \WC_Product_Simple A product with no language. */
	private $language_less_product;

	/** @var \WC_Product_Variable A variable product with no language. */
	private $language_less_variable_product;

	/** @var int[] The variations of the variable product, which have no language either. */
	private $language_less_variation_ids;

	/** @var \WC_Product_Variable A variable product in a language other than the request's. */
	private $other_language_variable_product;

	/** @var int[] The variations of that product, in the same other language. */
	private $other_language_variation_ids;

	/** @var string[] Feed files written by a test. */
	private $feed_files = array();

	public function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'language' ) ) {
			register_taxonomy(
				'language',
				array( 'product', 'product_variation' ),
				array(
					'public'    => false,
					'query_var' => 'lang',
					'rewrite'   => false,
				)
			);
			$this->registered_language_taxonomy = true;
		}

		$term                 = wp_insert_term( 'English', 'language', array( 'slug' => 'en-test' ) );
		$this->language_tt_id = (int) $term['term_taxonomy_id'];

		$this->english_product                = \WC_Helper_Product::create_simple_product();
		$this->language_less_product          = \WC_Helper_Product::create_simple_product();
		$this->language_less_variable_product = \WC_Helper_Product::create_variation_product();

		// Read before the filter is on: WooCommerce's own children query is narrowed like any other.
		$this->language_less_variation_ids = array_map( 'intval', $this->language_less_variable_product->get_children() );

		wp_set_object_terms( $this->english_product->get_id(), array( (int) $term['term_id'] ), 'language' );

		// A variable product and its variations in another language, as a translation would be.
		$other_term                            = wp_insert_term( 'Serbian', 'language', array( 'slug' => 'sr-test' ) );
		$this->other_language_variable_product = \WC_Helper_Product::create_variation_product();
		$this->other_language_variation_ids    = array_map( 'intval', $this->other_language_variable_product->get_children() );

		foreach ( array_merge( array( $this->other_language_variable_product->get_id() ), $this->other_language_variation_ids ) as $post_id ) {
			wp_set_object_terms( $post_id, array( (int) $other_term['term_id'] ), 'language' );
		}

		$tt_id                 = $this->language_tt_id;
		$this->language_filter = static function ( $query ) use ( $tt_id ) {
			$query_vars = &$query->query_vars;

			// Polylang leaves a query alone once it carries a 'lang' argument, even an empty one.
			$post_types = (array) ( $query_vars['post_type'] ?? array() );
			if ( isset( $query_vars['lang'] ) || ! array_intersect( $post_types, array( 'product', 'product_variation' ) ) ) {
				return;
			}

			$tax_query   = is_array( $query_vars['tax_query'] ?? null ) ? $query_vars['tax_query'] : array();
			$tax_query[] = array(
				'taxonomy' => 'language',
				'field'    => 'term_taxonomy_id',
				'terms'    => array( $tt_id ),
				'operator' => 'IN',
			);

			$query_vars['tax_query'] = $tax_query;
		};

		add_action( 'parse_query', $this->language_filter, 6 );
	}

	public function tearDown(): void {
		remove_action( 'parse_query', $this->language_filter, 6 );

		foreach ( $this->feed_files as $feed_file ) {
			if ( file_exists( $feed_file ) ) {
				unlink( $feed_file );
			}
		}

		if ( $this->registered_language_taxonomy ) {
			unregister_taxonomy( 'language' );
		}

		set_current_screen( 'front' );

		parent::tearDown();
	}

	/**
	 * Guards the simulation: a product or variation query without 'lang' only sees the request's language.
	 */
	public function test_simulated_language_filter_hides_products_and_variations_in_no_language() {
		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertSame( array( $this->english_product->get_id() ), array_map( 'intval', $product_ids ) );

		$variation_ids = get_posts(
			array(
				'post_type'      => 'product_variation',
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertNotEmpty( $this->language_less_variation_ids );
		$this->assertSame( array(), $variation_ids );
	}

	/**
	 * "Sync now" queues every published product and variation, whatever the request's language.
	 */
	public function test_full_sync_ids_cover_products_in_every_language_and_in_none() {
		$product_ids = array_map( 'intval', \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );

		$this->assertContains( $this->english_product->get_id(), $product_ids );
		$this->assertContains( $this->language_less_product->get_id(), $product_ids );

		$this->assertNotEmpty( $this->language_less_variation_ids );
		foreach ( $this->language_less_variation_ids as $variation_id ) {
			$this->assertContains( $variation_id, $product_ids, 'Variations of a product in no language are queued.' );
		}

		$this->assertNotContains( $this->language_less_variable_product->get_id(), $product_ids, 'Variable parents are not product items.' );
	}

	/**
	 * A variation outside the request's language passes the sync check when its parent has no saved sync setting.
	 *
	 * The check then looks for a sync-enabled variation of the parent. WooCommerce's children
	 * query is narrowed when its children cache is empty, so the check found none, saved the
	 * parent's setting as 'no' and rejected the variation with "Sync disabled in product field".
	 */
	public function test_sync_validator_accepts_variations_outside_the_request_language() {
		$sync_meta_key = Products::get_product_sync_meta_key();

		foreach ( $this->get_variable_products_outside_the_request_language() as $label => $fixture ) {
			$parent_id = $fixture['parent']->get_id();
			$this->forget_parent_settings_and_children( $parent_id );

			$variation = wc_get_product( $fixture['variation_ids'][0] );
			$validator = facebook_for_woocommerce()->get_product_sync_validator( $variation );
			$method    = new \ReflectionMethod( $validator, 'validate_product_sync_field' );
			$method->setAccessible( true );

			try {
				$method->invoke( $validator );
				$rejection = '';
			} catch ( \Exception $exception ) {
				$rejection = $exception->getMessage();
			}

			$this->assertSame( '', $rejection, "The variation of the {$label} passes the sync check." );
			$this->assertSame( 'yes', get_post_meta( $parent_id, $sync_meta_key, true ), "The {$label} is saved as synced." );
		}
	}

	/**
	 * A variable product passes the sync check when its variations are outside the request's language.
	 */
	public function test_sync_validator_accepts_variable_products_outside_the_request_language() {
		foreach ( $this->get_variable_products_outside_the_request_language() as $label => $fixture ) {
			$this->forget_parent_settings_and_children( $fixture['parent']->get_id() );

			$validator = facebook_for_woocommerce()->get_product_sync_validator( wc_get_product( $fixture['parent']->get_id() ) );
			$method    = new \ReflectionMethod( $validator, 'validate_product_sync_field' );
			$method->setAccessible( true );

			try {
				$method->invoke( $validator );
				$rejection = '';
			} catch ( \Exception $exception ) {
				$rejection = $exception->getMessage();
			}

			$this->assertSame( '', $rejection, "The {$label} passes the sync check." );
		}
	}

	/**
	 * The feed file written from the full sync IDs has a visible row for every variation, whatever the request's language.
	 *
	 * Covers the sync check above and the row's visibility, which falls back to the parent's
	 * variations in the same way when the parent has no saved visibility.
	 */
	public function test_full_sync_feed_has_visible_rows_for_variations_outside_the_request_language() {
		$fixtures = $this->get_variable_products_outside_the_request_language();
		foreach ( $fixtures as $fixture ) {
			$this->forget_parent_settings_and_children( $fixture['parent']->get_id() );
		}

		$rows = $this->write_feed_and_read_rows( \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );

		$this->assertArrayHasKey( (string) $this->english_product->get_id(), $rows, 'A product in the request language is in the feed.' );

		foreach ( $fixtures as $label => $fixture ) {
			$parent_id = $fixture['parent']->get_id();

			foreach ( $fixture['variation_ids'] as $variation_id ) {
				$retailer_id = \WC_Facebookcommerce_Utils::get_fb_retailer_id( wc_get_product( $variation_id ) );

				$this->assertArrayHasKey( $retailer_id, $rows, "Variation {$variation_id} of the {$label} is in the feed." );
				$this->assertSame( \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE, $rows[ $retailer_id ]['visibility'], "Variation {$variation_id} of the {$label} is visible." );
			}

			$this->assertSame( 'yes', get_post_meta( $parent_id, Products::get_product_sync_meta_key(), true ), "The {$label} is saved as synced." );
			$this->assertSame( 'yes', get_post_meta( $parent_id, Products::VISIBILITY_META_KEY, true ), "The {$label} is saved as visible." );
		}
	}

	/**
	 * The reset job pages through every product in ID order, whatever the request's language.
	 */
	public function test_reset_job_batches_cover_products_in_every_language_and_in_none() {
		$job = $this->create_reset_job();

		$collected = array();
		for ( $batch_number = 1; $batch_number <= 10; $batch_number++ ) {
			$batch = $job->get_batch( $batch_number );
			if ( empty( $batch ) ) {
				break;
			}
			$this->assertLessThanOrEqual( 2, count( $batch ) );
			$collected = array_merge( $collected, $batch );
		}

		$expected = array(
			$this->english_product->get_id(),
			$this->language_less_product->get_id(),
			$this->language_less_variable_product->get_id(),
		);

		$this->assertSame( array(), array_values( array_diff( $expected, $collected ) ), 'Every product is handed to the job.' );
		$this->assertSame( array_unique( $collected ), $collected, 'No product is handed to the job twice.' );

		$sorted = $collected;
		sort( $sorted, SORT_NUMERIC );
		$this->assertSame( $sorted, $collected, 'Batches follow ascending product IDs so the offset stays stable.' );
	}

	/**
	 * Running the reset job's batches clears the Facebook IDs of every product and variation.
	 *
	 * The job resets one product at a time, and each reset looks up the product's variations.
	 * WooCommerce's own children query is narrowed like any other when its children cache is
	 * empty, so variations in another language, or in none, kept their Facebook IDs.
	 */
	public function test_reset_job_batches_clear_variations_in_every_language_and_in_none() {
		$this->assertNotEmpty( $this->language_less_variation_ids );

		$post_ids = array_merge(
			array(
				$this->english_product->get_id(),
				$this->language_less_product->get_id(),
				$this->language_less_variable_product->get_id(),
			),
			$this->language_less_variation_ids
		);

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'group-' . $post_id );
			update_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'item-' . $post_id );
			update_post_meta( $post_id, Products::VISIBILITY_META_KEY, 'yes' );
		}

		// An empty children cache, as in a request that has not loaded the product before.
		delete_transient( 'wc_product_children_' . $this->language_less_variable_product->get_id() );

		$job = $this->create_reset_job();
		for ( $batch_number = 1; $batch_number <= 10; $batch_number++ ) {
			if ( empty( $job->run_batch( $batch_number ) ) ) {
				break;
			}
		}

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( '', get_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ), "Group ID of post {$post_id} is cleared." );
			$this->assertSame( '', get_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ), "Item ID of post {$post_id} is cleared." );
			$this->assertSame( '', get_post_meta( $post_id, Products::VISIBILITY_META_KEY, true ), "Visibility of post {$post_id} is cleared." );
		}
	}

	/**
	 * The legacy AJAX reset clears the Facebook IDs of every product and variation.
	 */
	public function test_legacy_reset_clears_products_in_every_language_and_in_none() {
		$this->assertNotEmpty( $this->language_less_variation_ids );

		$post_ids = array_merge(
			array(
				$this->english_product->get_id(),
				$this->language_less_product->get_id(),
				$this->language_less_variable_product->get_id(),
			),
			$this->language_less_variation_ids
		);

		foreach ( $post_ids as $post_id ) {
			update_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'group-' . $post_id );
			update_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'item-' . $post_id );
			update_post_meta( $post_id, Products::VISIBILITY_META_KEY, 'yes' );
		}

		set_current_screen( 'dashboard' );

		$this->assertTrue( facebook_for_woocommerce()->get_integration()->reset_all_products() );

		foreach ( $post_ids as $post_id ) {
			$this->assertSame( '', get_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ), "Group ID of post {$post_id} is cleared." );
			$this->assertSame( '', get_post_meta( $post_id, \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ), "Item ID of post {$post_id} is cleared." );
			$this->assertSame( '', get_post_meta( $post_id, Products::VISIBILITY_META_KEY, true ), "Visibility of post {$post_id} is cleared." );
		}
	}

	/**
	 * Gets the variable products a narrowed query does not see, with their variations.
	 *
	 * @return array<string, array{parent: \WC_Product_Variable, variation_ids: int[]}>
	 */
	private function get_variable_products_outside_the_request_language() {
		$this->assertNotEmpty( $this->other_language_variation_ids );
		$this->assertNotEmpty( $this->language_less_variation_ids );

		return array(
			'variable product in another language' => array(
				'parent'        => $this->other_language_variable_product,
				'variation_ids' => $this->other_language_variation_ids,
			),
			'variable product in no language'      => array(
				'parent'        => $this->language_less_variable_product,
				'variation_ids' => $this->language_less_variation_ids,
			),
		);
	}

	/**
	 * Puts a variable product in the state of a first sync in a request that has not loaded it:
	 * no saved sync or visibility setting, and an empty WooCommerce children cache.
	 *
	 * @param int $parent_id The variable product ID.
	 */
	private function forget_parent_settings_and_children( int $parent_id ) {
		delete_post_meta( $parent_id, Products::SYNC_ENABLED_META_KEY );
		delete_post_meta( $parent_id, Products::OPTEN_IN_SYNC_ENABLED_META_KEY );
		delete_post_meta( $parent_id, Products::VISIBILITY_META_KEY );
		delete_transient( 'wc_product_children_' . $parent_id );
	}

	/**
	 * Writes the product feed file for the given IDs and reads it back.
	 *
	 * @param int[] $product_ids The product IDs to write.
	 * @return array<string, array<string, string>> The feed rows as column => value, keyed by the row's ID.
	 */
	private function write_feed_and_read_rows( array $product_ids ) {
		$feed_path = get_temp_dir() . uniqid( 'fb-polylang-feed-', true ) . '.csv';
		$temp_path = $feed_path . '.tmp';

		$this->feed_files[] = $feed_path;
		$this->feed_files[] = $temp_path;

		$use_feed_path = static function () use ( $feed_path ) {
			return $feed_path;
		};
		$use_temp_path = static function () use ( $temp_path ) {
			return $temp_path;
		};

		add_filter( 'wc_facebook_product_catalog_feed_file_path', $use_feed_path );
		add_filter( 'wc_facebook_product_catalog_temp_feed_file_path', $use_temp_path );

		try {
			$written = ( new \WC_Facebook_Product_Feed() )->write_product_feed_file( $product_ids );
		} finally {
			remove_filter( 'wc_facebook_product_catalog_feed_file_path', $use_feed_path );
			remove_filter( 'wc_facebook_product_catalog_temp_feed_file_path', $use_temp_path );
		}

		$this->assertTrue( $written, 'The feed file is written.' );

		$handle = fopen( $feed_path, 'r' );
		$header = array_map( 'trim', fgetcsv( $handle, 0, ',', '"', '\\' ) );
		$rows   = array();

		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) ) {
			if ( count( $row ) === count( $header ) ) {
				$row                = array_combine( $header, $row );
				$rows[ $row['id'] ] = $row;
			}
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Creates the reset job with a small batch size and its batch steps exposed.
	 *
	 * @return ResetAllProductsFBSettings
	 */
	private function create_reset_job() {
		return new class( new ActionScheduler() ) extends ResetAllProductsFBSettings {
			public function get_batch_size(): int {
				return 2;
			}

			public function get_batch( int $batch_number ): array {
				return $this->get_items_for_batch( $batch_number, array() );
			}

			public function run_batch( int $batch_number ): array {
				$items = $this->get_items_for_batch( $batch_number, array() );
				$this->process_items( $items, array() );

				return $items;
			}
		};
	}
}
