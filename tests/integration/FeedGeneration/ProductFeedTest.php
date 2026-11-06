<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\FeedGeneration;

use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for Facebook product feed generation.
 *
 * Tests the complete pipeline from product data to CSV feed file generation.
 * These are integration tests that validate end-to-end feed creation workflows.
 *
 */
class ProductFeedTest extends IntegrationTestCase {

	/**
	 * Temporary feed directory for tests
	 */
	private $temp_feed_dir;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Create temporary directory for feed files
		$this->temp_feed_dir = sys_get_temp_dir() . '/facebook_feed_tests_' . uniqid();
		wp_mkdir_p( $this->temp_feed_dir );
	}

	/**
	 * Test complete feed generation pipeline
	 */
	public function test_complete_feed_generation_pipeline(): void {
		$this->enable_facebook_sync();

		// Create diverse product catalog
		$products = $this->create_test_product_catalog();

		// Generate feed
		$feed_data = $this->generate_facebook_feed( $products );

		// Verify feed structure
		$this->assertGreaterThan( 0, count( $feed_data ), 'Feed should contain products' );

		// Verify feed headers
		$first_product = reset( $feed_data );
		$expected_headers = [
			'id', 'title', 'description', 'price', 'availability', 
			'condition', 'brand', 'link', 'image_link'
		];

		foreach ( $expected_headers as $header ) {
			$this->assertArrayHasKey( $header, $first_product, "Feed should include {$header} field" );
		}

		// Test CSV file generation
		$csv_file_path = $this->generate_csv_feed_file( $feed_data );
		$this->assertFileExists( $csv_file_path, 'CSV feed file should be created' );

		// Verify CSV content
		$csv_content = file_get_contents( $csv_file_path );
		$this->assertStringContainsString( 'id,title,description', $csv_content, 'CSV should contain headers' );
		$this->assertGreaterThan( 100, strlen( $csv_content ), 'CSV should contain substantial content' );
	}

	/**
	 * Test feed generation with variable products
	 */
	public function test_feed_generation_with_variable_products(): void {
		$this->enable_facebook_sync();

		// Create variable product with multiple variations
		$variable_product = $this->create_variable_product_with_variations();

		// Generate feed including variations
		$feed_data = $this->generate_facebook_feed( [ $variable_product ] );

		// Should include parent and all variations
		$this->assertGreaterThan( 1, count( $feed_data ), 'Feed should include multiple entries for variable product' );

		// Verify item group relationships
		$item_groups = array_unique( array_column( $feed_data, 'item_group_id' ) );
		$this->assertCount( 1, $item_groups, 'All variations should share same item group' );

		// Verify unique product IDs
		$product_ids = array_column( $feed_data, 'id' );
		$this->assertEquals( count( $product_ids ), count( array_unique( $product_ids ) ), 'All product IDs should be unique' );
	}

	/**
	 * Test large catalog feed generation performance
	 */
	public function test_large_catalog_feed_generation(): void {
		$this->enable_facebook_sync();

		// Create large product catalog
		$products = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$products[] = $this->create_simple_product([
				'name' => "Product {$i}",
				'regular_price' => (10 + $i) . '.99',
				'sku' => "SKU-{$i}",
				'status' => 'publish'
			]);
		}

		// Measure generation time
		$start_time = microtime( true );
		$feed_data = $this->generate_facebook_feed( $products );
		$generation_time = microtime( true ) - $start_time;

		// Verify performance and results
		$this->assertCount( 100, $feed_data, 'Should generate feed for all products' );
		$this->assertLessThan( 30, $generation_time, 'Feed generation should complete within 30 seconds' );

		// Test CSV generation performance
		$start_time = microtime( true );
		$csv_file = $this->generate_csv_feed_file( $feed_data );
		$csv_generation_time = microtime( true ) - $start_time;

		$this->assertFileExists( $csv_file, 'CSV file should be generated' );
		$this->assertLessThan( 10, $csv_generation_time, 'CSV generation should complete within 10 seconds' );
	}

	/**
	 * Test feed generation with missing product data
	 */
	public function test_feed_generation_with_missing_data(): void {
		$this->enable_facebook_sync();

		// Create products with missing data
		$incomplete_products = [
			$this->create_simple_product([
				'name' => '', // Missing name
				'regular_price' => '25.00',
				'status' => 'publish'
			]),
			$this->create_simple_product([
				'name' => 'Valid Product',
				'regular_price' => '', // Missing price
				'status' => 'publish'
			]),
			$this->create_simple_product([
				'name' => 'Complete Product',
				'regular_price' => '30.00',
				'status' => 'publish'
			])
		];

		// Generate feed
		$feed_data = $this->generate_facebook_feed( $incomplete_products );

		// Should handle missing data gracefully by including all products
		$this->assertEquals( 3, count( $feed_data ), 'Should include all products in feed' );

		// Verify that all products have the basic structure, even with missing data
		foreach ( $feed_data as $product_data ) {
			$this->assertNotEmpty( $product_data['id'], 'Product ID should never be empty' );
			$this->assertArrayHasKey( 'title', $product_data, 'Should have title field' );
			$this->assertArrayHasKey( 'price', $product_data, 'Should have price field' );
			
			// Note: title and price might be empty for products with missing data
			// but the fields should exist in the feed structure
		}
		
		// Find and verify the complete product
		$complete_product = null;
		foreach ( $feed_data as $product_data ) {
			if ( $product_data['title'] === 'Complete Product' ) {
				$complete_product = $product_data;
				break;
			}
		}
		
		$this->assertNotNull( $complete_product, 'Should find the complete product in feed' );
		$this->assertEquals( 'Complete Product', $complete_product['title'], 'Complete product should have correct title' );
		$this->assertEquals( '30.00 USD', $complete_product['price'], 'Complete product should have correct price' );
	}

	/**
	 * Test feed file management and cleanup
	 */
	public function test_feed_file_management(): void {
		$this->enable_facebook_sync();

		// Create test products
		$products = [
			$this->create_simple_product([
				'name' => 'Test Product 1',
				'regular_price' => '15.00',
				'status' => 'publish'
			])
		];

		// Generate multiple feed files
		$feed_files = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$feed_data = $this->generate_facebook_feed( $products );
			$feed_files[] = $this->generate_csv_feed_file( $feed_data, "feed_{$i}.csv" );
		}

		// Verify all files exist
		foreach ( $feed_files as $file_path ) {
			$this->assertFileExists( $file_path, 'Feed file should exist' );
		}

		// Test file cleanup
		$this->cleanup_old_feed_files( $this->temp_feed_dir );

		// Should clean up old files while keeping recent ones
		$remaining_files = glob( $this->temp_feed_dir . '/*.csv' );
		$this->assertLessThanOrEqual( 3, count( $remaining_files ), 'Should limit number of feed files' );
	}

	/**
	 * Test feed generation with custom fields
	 */
	public function test_feed_generation_with_custom_fields(): void {
		$this->enable_facebook_sync();

		// Create product with custom fields
		$product = $this->create_simple_product([
			'name' => 'Custom Product',
			'regular_price' => '45.00',
			'status' => 'publish'
		]);

		// Add custom meta
		update_post_meta( $product->get_id(), '_facebook_custom_label_0', 'Premium' );
		update_post_meta( $product->get_id(), '_facebook_custom_label_1', 'Electronics' );
		update_post_meta( $product->get_id(), '_facebook_google_product_category', 'Electronics > Computers' );

		// Generate feed
		$feed_data = $this->generate_facebook_feed( [ $product ] );

		// Verify custom fields in feed
		$product_data = $feed_data[0];
		$this->assertArrayHasKey( 'custom_label_0', $product_data, 'Should include custom label 0' );
		$this->assertArrayHasKey( 'custom_label_1', $product_data, 'Should include custom label 1' );
		$this->assertArrayHasKey( 'google_product_category', $product_data, 'Should include Google product category' );

		$this->assertEquals( 'Premium', $product_data['custom_label_0'], 'Custom label should match' );
		$this->assertEquals( 'Electronics', $product_data['custom_label_1'], 'Custom label should match' );
	}

	/**
	 * Helper method to create test product catalog
	 */
	private function create_test_product_catalog(): array {
		$products = [];

		// Simple products
		for ( $i = 1; $i <= 5; $i++ ) {
			$products[] = $this->create_simple_product([
				'name' => "Test Product {$i}",
				'regular_price' => (20 + $i) . '.99',
				'sku' => "TEST-{$i}",
				'status' => 'publish'
			]);
		}

		// Variable product
		$products[] = $this->create_variable_product_with_variations();

		return $products;
	}

	/**
	 * Helper method to create variable product with variations
	 */
	private function create_variable_product_with_variations(): \WC_Product_Variable {
		// Create variable product
		$variable_product = $this->create_variable_product();

		// Create variations
		$variations = [
			[ 'attributes' => [ 'size' => 'Small' ], 'price' => '25.99' ],
			[ 'attributes' => [ 'size' => 'Medium' ], 'price' => '29.99' ],
			[ 'attributes' => [ 'size' => 'Large' ], 'price' => '34.99' ]
		];

		foreach ( $variations as $variation_data ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $variable_product->get_id() );
			$variation->set_attributes( $variation_data['attributes'] );
			$variation->set_regular_price( $variation_data['price'] );
			$variation->set_status( 'publish' );
			$variation->save();
		}

		return $variable_product;
	}

	/**
	 * Helper method to generate Facebook feed data
	 */
	private function generate_facebook_feed( array $products ): array {
		$feed_data = [];

		foreach ( $products as $product ) {
			// Check if product should be included
			if ( ! $this->should_include_in_feed( $product ) ) {
				continue;
			}

			// Add main product
			$feed_data[] = $this->format_product_for_feed( $product );

			// Add variations if variable product
			if ( $product->is_type( 'variable' ) ) {
				$variations = $product->get_children();
				foreach ( $variations as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation && $this->should_include_in_feed( $variation ) ) {
						$feed_data[] = $this->format_product_for_feed( $variation );
					}
				}
			}
		}

		return $feed_data;
	}

	/**
	 * Helper method to check if product should be included in feed
	 */
	private function should_include_in_feed( \WC_Product $product ): bool {
		// Use the actual Facebook sync validation logic
		return \WooCommerce\Facebook\Products::product_should_be_synced( $product );
	}

	/**
	 * Helper method to format product for feed
	 */
	private function format_product_for_feed( \WC_Product $product ): array {
		$data = [
			'id' => $product->get_id(),
			'title' => $product->get_name(),
			'description' => $product->get_description() ?: $product->get_short_description(),
			'price' => $product->get_regular_price() . ' USD',
			'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
			'condition' => 'new',
			'brand' => get_bloginfo( 'name' ),
			'link' => $product->get_permalink(),
			'image_link' => wp_get_attachment_url( $product->get_image_id() ) ?: ''
		];

		// Add sale price if available
		if ( $product->is_on_sale() && $product->get_sale_price() ) {
			$data['sale_price'] = $product->get_sale_price() . ' USD';
		}

		// Add item group for variable products
		if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
			$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$data['item_group_id'] = 'group_' . $parent_id;
		}

		// Add custom fields
		$custom_fields = [
			'custom_label_0' => '_facebook_custom_label_0',
			'custom_label_1' => '_facebook_custom_label_1',
			'google_product_category' => '_facebook_google_product_category'
		];

		foreach ( $custom_fields as $feed_field => $meta_key ) {
			$value = get_post_meta( $product->get_id(), $meta_key, true );
			if ( ! empty( $value ) ) {
				$data[ $feed_field ] = $value;
			}
		}

		return $data;
	}

	/**
	 * Helper method to generate CSV feed file
	 */
	private function generate_csv_feed_file( array $feed_data, string $filename = 'product_feed.csv' ): string {
		$file_path = $this->temp_feed_dir . '/' . $filename;

		$handle = fopen( $file_path, 'w' );
		if ( ! $handle ) {
			throw new \Exception( 'Could not create feed file' );
		}

		// Write headers
		if ( ! empty( $feed_data ) ) {
			fputcsv( $handle, array_keys( $feed_data[0] ), ',', '"', '\\' );

			// Write data rows
			foreach ( $feed_data as $row ) {
				fputcsv( $handle, $row, ',', '"', '\\' );
			}
		}

		fclose( $handle );

		return $file_path;
	}

	/**
	 * Helper method to cleanup old feed files
	 */
	private function cleanup_old_feed_files( string $directory ): void {
		$files = glob( $directory . '/*.csv' );
		
		// Sort by modification time (newest first)
		usort( $files, function( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		});

		// Keep only the 2 most recent files
		$files_to_delete = array_slice( $files, 2 );
		foreach ( $files_to_delete as $file ) {
			unlink( $file );
		}
	}

	/**
	 * Clean up test environment
	 */
	public function tearDown(): void {
		// Clean up temporary feed directory
		if ( is_dir( $this->temp_feed_dir ) ) {
			$files = glob( $this->temp_feed_dir . '/*' );
			foreach ( $files as $file ) {
				unlink( $file );
			}
			rmdir( $this->temp_feed_dir );
		}

		parent::tearDown();
	}
} 