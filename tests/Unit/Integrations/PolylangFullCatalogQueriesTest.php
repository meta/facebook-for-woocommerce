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
