<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Admin;

use WooCommerce\Facebook\Admin\Product_Categories;
use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Saving a product category re-syncs the products in it.
 */
class Product_CategoriesSaveSyncTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var Sync|\PHPUnit\Framework\MockObject\MockObject */
	private $sync_handler;

	public function setUp(): void {
		parent::setUp();

		$this->sync_handler = $this->createMock( Sync::class );
	}

	/**
	 * Saves the term with the plugin instance swapped for one returning the mocked sync handler.
	 *
	 * The swap happens only now so creating the fixtures still runs against the real plugin.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy the term belongs to.
	 */
	private function save_term( int $term_id, string $taxonomy = 'product_cat' ): void {
		$this->use_mocked_plugin();

		( new Product_Categories() )->save_google_product_category_and_enhanced_attributes( $term_id, 0, $taxonomy );
	}

	/**
	 * Swaps the plugin instance for one returning the mocked sync handler.
	 *
	 * The store reports as disconnected, so the product set sync that also runs on category saves stays out.
	 */
	private function use_mocked_plugin(): void {
		$connection = $this->createMock( \WooCommerce\Facebook\Handlers\Connection::class );
		$connection->method( 'is_connected' )->willReturn( false );

		$plugin = $this->createMock( \WC_Facebookcommerce::class );
		$plugin->method( 'get_products_sync_handler' )->willReturn( $this->sync_handler );
		$plugin->method( 'get_connection_handler' )->willReturn( $connection );

		$this->add_filter_with_safe_teardown(
			'wc_facebook_instance',
			function () use ( $plugin ) {
				return $plugin;
			}
		);
	}

	/**
	 * Expects one sync request and returns a reference to the IDs it queues, sorted once read.
	 *
	 * @return \ArrayObject
	 */
	private function capture_queued_ids(): \ArrayObject {
		$queued = new \ArrayObject();
		$this->sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->willReturnCallback(
				function ( array $ids ) use ( $queued ) {
					$queued->exchangeArray( $ids );
				}
			);

		return $queued;
	}

	/**
	 * Saves the category and returns the IDs queued for sync, sorted.
	 *
	 * @param int $term_id Category term ID.
	 * @return int[]
	 */
	private function save_category_and_get_queued_ids( int $term_id ): array {
		$queued = array();
		$this->sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->willReturnCallback(
				function ( array $ids ) use ( &$queued ) {
					$queued = $ids;
				}
			);

		$this->save_term( $term_id );

		return $this->sorted( $queued );
	}

	private function create_category( string $name ): int {
		return (int) wp_insert_term( $name, 'product_cat' )['term_id'];
	}

	private function add_to_category( \WC_Product $product, int $term_id ): \WC_Product {
		$product->set_category_ids( array( $term_id ) );
		$product->save();

		return $product;
	}

	private function sorted( array $ids ): array {
		$ids = array_map( 'intval', $ids );
		sort( $ids );

		return $ids;
	}

	/**
	 * A simple product is queued by its own ID, not by its position in the query result.
	 */
	public function test_saving_category_syncs_simple_products_by_their_ids() {
		$term_id = $this->create_category( 'QA UI & Sets' );
		$simple  = $this->add_to_category( \WC_Helper_Product::create_simple_product(), $term_id );

		$this->assertSame( array( $simple->get_id() ), $this->save_category_and_get_queued_ids( $term_id ) );
	}

	/**
	 * Simple products are queued by ID and variable products by their variations, never the parent.
	 */
	public function test_saving_category_syncs_simple_products_and_variations() {
		$term_id  = $this->create_category( 'QA mixed' );
		$simple   = $this->add_to_category( \WC_Helper_Product::create_simple_product(), $term_id );
		$variable = $this->add_to_category( \WC_Helper_Product::create_variation_product(), $term_id );

		$queued = $this->save_category_and_get_queued_ids( $term_id );

		$this->assertSame( $this->sorted( array_merge( array( $simple->get_id() ), $variable->get_children() ) ), $queued );
		$this->assertNotContains( $variable->get_id(), $queued );
	}

	/**
	 * Every product is queued, not just the first page of a default-sized query.
	 */
	public function test_saving_category_syncs_more_products_than_posts_per_page() {
		update_option( 'posts_per_page', 10 );

		$term_id  = $this->create_category( 'QA large' );
		$expected = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$expected[] = $this->add_to_category( \WC_Helper_Product::create_simple_product(), $term_id )->get_id();
		}

		$this->assertSame( $this->sorted( $expected ), $this->save_category_and_get_queued_ids( $term_id ) );
	}

	/**
	 * Terms of other taxonomies are left alone, even when a product category shares their slug.
	 */
	public function test_saving_term_of_other_taxonomy_does_not_sync_products() {
		$category_id = $this->create_category( 'QA shared' );
		$this->add_to_category( \WC_Helper_Product::create_simple_product(), $category_id );

		$tag_id = (int) wp_insert_term( 'QA shared', 'product_tag' )['term_id'];
		$this->assertSame( get_term( $category_id )->slug, get_term( $tag_id )->slug );

		$this->sync_handler->expects( $this->never() )->method( 'create_or_update_products' );

		$this->save_term( $tag_id, 'product_tag' );
	}

	/**
	 * Products assigned to a subcategory only are left out: what is sent for a product depends on the
	 * categories assigned to it, not on their parents.
	 */
	public function test_saving_category_does_not_sync_products_of_subcategories() {
		$parent_id = $this->create_category( 'QA parent' );
		$child_id  = (int) wp_insert_term( 'QA child', 'product_cat', array( 'parent' => $parent_id ) )['term_id'];
		$in_parent = $this->add_to_category( \WC_Helper_Product::create_simple_product(), $parent_id );
		$this->add_to_category( \WC_Helper_Product::create_simple_product(), $child_id );

		// The category hierarchy is cached in an option, which this test case isolates, and clean_term_cache()
		// rebuilds it only once per PHP process. Drop the cached copy so a subcategory would be found.
		$this->mock_set_option( 'product_cat_children', false );

		$this->assertSame( array( $in_parent->get_id() ), $this->save_category_and_get_queued_ids( $parent_id ) );
	}

	/**
	 * Renaming a category together with its slug still re-syncs its products. edit_term fires before
	 * WordPress clears the term cache, so the category it reads still has the old slug.
	 */
	public function test_renaming_category_with_new_slug_syncs_its_products() {
		$term_id = $this->create_category( 'QA UI & Sets' );
		$simple  = $this->add_to_category( \WC_Helper_Product::create_simple_product(), $term_id );
		get_term( $term_id, 'product_cat' ); // warm the term cache, as the edit screen does

		$this->use_mocked_plugin();
		$queued = $this->capture_queued_ids();

		$handler = new Product_Categories();
		$this->add_filter_with_safe_teardown( 'edit_term', array( $handler, 'save_google_product_category_and_enhanced_attributes' ), 10, 3 );

		wp_update_term(
			$term_id,
			'product_cat',
			array(
				'name' => 'QA UI renamed & more',
				'slug' => 'qa-ui-renamed-more',
			)
		);

		$this->assertSame( array( $simple->get_id() ), $this->sorted( $queued->getArrayCopy() ) );
	}
}
