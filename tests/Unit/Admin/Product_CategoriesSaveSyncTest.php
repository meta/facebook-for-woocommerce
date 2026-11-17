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
		$plugin = $this->createMock( \WC_Facebookcommerce::class );
		$plugin->method( 'get_products_sync_handler' )->willReturn( $this->sync_handler );

		$this->add_filter_with_safe_teardown(
			'wc_facebook_instance',
			function () use ( $plugin ) {
				return $plugin;
			}
		);

		( new Product_Categories() )->save_google_product_category_and_enhanced_attributes( $term_id, 0, $taxonomy );
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
}
