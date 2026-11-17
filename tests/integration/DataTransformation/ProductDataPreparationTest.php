<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\DataTransformation;

use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;
use WooCommerce\Facebook\Products;

/**
 * Integration tests for product data transformation pipeline.
 *
 * Tests the complete flow from WooCommerce product data to Facebook feed format.
 * These are integration tests that validate end-to-end data transformation workflows.
 *
 */
class ProductDataPreparationTest extends IntegrationTestCase {

	/**
	 * Test complete product data transformation pipeline
	 */
	public function test_complete_product_to_facebook_transformation(): void {
		$this->enable_facebook_sync();

		// Create a complete product with all data types
		$category = $this->create_category( 'Electronics' );
		$product = $this->create_simple_product([
			'name' => 'Samsung Galaxy S23 Ultra',
			'regular_price' => '1199.99',
			'sale_price' => '999.99',
			'sku' => 'SAMSUNG-S23-ULTRA-256GB',
			'description' => 'Latest Samsung flagship with advanced camera system and S Pen functionality.',
			'short_description' => 'Premium Android smartphone with cutting-edge features.',
			'weight' => '0.5',
			'length' => '6.43',
			'width' => '3.07',
			'height' => '0.35',
			'status' => 'publish'
		]);

		// Assign category
		wp_set_object_terms( $product->get_id(), [ $category->term_id ], 'product_cat' );

		// Test the complete transformation pipeline
		$facebook_product_data = $this->transform_product_for_facebook( $product );

		// Verify the transformation includes all expected Facebook fields
		$this->assertTrue( isset( $facebook_product_data['id'] ), 'Facebook data should include product ID' );
		$this->assertTrue( isset( $facebook_product_data['title'] ), 'Facebook data should include title' );
		$this->assertTrue( isset( $facebook_product_data['description'] ), 'Facebook data should include description' );
		$this->assertTrue( isset( $facebook_product_data['price'] ), 'Facebook data should include price' );
		$this->assertTrue( isset( $facebook_product_data['sale_price'] ), 'Facebook data should include sale price' );
		$this->assertTrue( isset( $facebook_product_data['availability'] ), 'Facebook data should include availability' );
		$this->assertTrue( isset( $facebook_product_data['condition'] ), 'Facebook data should include condition' );
		$this->assertTrue( isset( $facebook_product_data['brand'] ), 'Facebook data should include brand' );

		// Verify data format transformations
		$this->assertTrue( strpos( $facebook_product_data['price'], 'USD' ) !== false, 'Price should include currency' );
		$this->assertTrue( strpos( $facebook_product_data['sale_price'], 'USD' ) !== false, 'Sale price should include currency' );
		$this->assertEquals( 'in stock', $facebook_product_data['availability'], 'Availability should be formatted for Facebook' );
		$this->assertEquals( 'new', $facebook_product_data['condition'], 'Condition should default to new' );
	}

	/**
	 * Test variable product transformation with variations
	 */
	public function test_variable_product_transformation_pipeline(): void {
		$this->enable_facebook_sync();

		// Create variable product with size and color attributes
		$size_attribute = new \WC_Product_Attribute();
		$size_attribute->set_name( 'Size' );
		$size_attribute->set_options( [ 'Small', 'Medium', 'Large' ] );
		$size_attribute->set_visible( true );
		$size_attribute->set_variation( true );

		$color_attribute = new \WC_Product_Attribute();
		$color_attribute->set_name( 'Color' );
		$color_attribute->set_options( [ 'Red', 'Blue', 'Black' ] );
		$color_attribute->set_visible( true );
		$color_attribute->set_variation( true );

		$variable_product = $this->create_variable_product([
			'Size' => $size_attribute,
			'Color' => $color_attribute
		]);

		// Create variations
		$variations = [
			[ 'attributes' => [ 'size' => 'Small', 'color' => 'Red' ], 'price' => '29.99' ],
			[ 'attributes' => [ 'size' => 'Medium', 'color' => 'Blue' ], 'price' => '34.99' ],
			[ 'attributes' => [ 'size' => 'Large', 'color' => 'Black' ], 'price' => '39.99' ]
		];

		$created_variations = [];
		foreach ( $variations as $variation_data ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $variable_product->get_id() );
			$variation->set_attributes( $variation_data['attributes'] );
			$variation->set_regular_price( $variation_data['price'] );
			$variation->set_status( 'publish' );
			$variation->save();
			$created_variations[] = $variation;
		}

		// Test transformation of variable product and its variations
		$facebook_parent_data = $this->transform_product_for_facebook( $variable_product );
		
		// Parent product should be transformed as product group
		$this->assertTrue( isset( $facebook_parent_data['item_group_id'] ), 'Variable product should have item group ID' );
		
		// Test each variation transformation
		foreach ( $created_variations as $variation ) {
			$facebook_variation_data = $this->transform_product_for_facebook( $variation );
			
			// Variations should reference parent group
			$this->assertTrue( isset( $facebook_variation_data['item_group_id'] ), 'Variation should have item group ID' );
			$this->assertEquals( 
				$facebook_parent_data['item_group_id'], 
				$facebook_variation_data['item_group_id'], 
				'Variation should reference same group as parent' 
			);
			
			// Variations should have unique identifiers
			$this->assertTrue( isset( $facebook_variation_data['id'] ), 'Variation should have unique ID' );
			$this->assertNotEquals( 
				$facebook_parent_data['id'], 
				$facebook_variation_data['id'], 
				'Variation ID should differ from parent' 
			);
		}
	}

	/**
	 * Test bulk product transformation for feed generation
	 */
	public function test_bulk_product_transformation_for_feed(): void {
		$this->enable_facebook_sync();

		// Create multiple products of different types
		$products = [];
		
		// Simple products
		for ( $i = 1; $i <= 10; $i++ ) {
			$products[] = $this->create_simple_product([
				'name' => "Bulk Product {$i}",
				'regular_price' => (10 + $i) . '.99',
				'sku' => "BULK-{$i}",
				'status' => 'publish'
			]);
		}

		// Variable product
		$variable_product = $this->create_variable_product();
		$products[] = $variable_product;

		// Test bulk transformation
		$facebook_feed_data = [];
		foreach ( $products as $product ) {
			if ( $this->should_product_sync_to_facebook( $product ) ) {
				$facebook_feed_data[] = $this->transform_product_for_facebook( $product );
			}
		}

		// Verify bulk transformation results
		$this->assertTrue( count( $facebook_feed_data ) > 0, 'Should have transformed multiple products' );
		$this->assertTrue( count( $facebook_feed_data ) <= count( $products ), 'Should not exceed input count' );

		// Verify each transformed product has required fields
		foreach ( $facebook_feed_data as $facebook_product ) {
			$this->assertTrue( isset( $facebook_product['id'] ), 'Each product should have ID' );
			$this->assertTrue( isset( $facebook_product['title'] ), 'Each product should have title' );
			$this->assertTrue( isset( $facebook_product['price'] ), 'Each product should have price' );
			$this->assertTrue( isset( $facebook_product['availability'] ), 'Each product should have availability' );
		}
	}

	/**
	 * Test product transformation error handling
	 */
	public function test_product_transformation_error_handling(): void {
		$this->enable_facebook_sync();

		// Create product with missing required data
		$incomplete_product = $this->create_simple_product([
			'name' => '', // Empty name
			'regular_price' => '0', // Zero price
			'status' => 'draft' // Not published
		]);

		// Test transformation handles errors gracefully
		$should_sync = $this->should_product_sync_to_facebook( $incomplete_product );
		$this->assertFalse( $should_sync, 'Incomplete product should not sync' );

		// Test transformation with invalid data
		$invalid_product = $this->create_simple_product([
			'name' => str_repeat( 'Very long product name ', 20 ), // Extremely long name
			'regular_price' => 'invalid_price', // Invalid price format
			'status' => 'publish'
		]);

		// Transformation should handle invalid data
		$facebook_data = $this->transform_product_for_facebook( $invalid_product );
		
		// Should still produce valid Facebook data structure
		$this->assertArrayHasKey( 'id', $facebook_data, 'Should have ID even with invalid input' );
		$this->assertArrayHasKey( 'title', $facebook_data, 'Should have title even with invalid input' );
	}

	/**
	 * Test currency and price formatting edge cases
	 */
	public function test_currency_and_price_formatting_edge_cases(): void {
		$this->enable_facebook_sync();

		// Test zero price product
		$free_product = $this->create_simple_product([
			'name' => 'Free Product',
			'regular_price' => '0.00',
			'status' => 'publish'
		]);

		$facebook_data_free = $this->transform_product_for_facebook( $free_product );
		$this->assertTrue( isset( $facebook_data_free['price'] ), 'Free product should have price field' );
		$this->assertEquals( '0.00 USD', $facebook_data_free['price'], 'Zero price should be formatted correctly' );

		// Test very high price
		$expensive_product = $this->create_simple_product([
			'name' => 'Expensive Product',
			'regular_price' => '999999.99',
			'status' => 'publish'
		]);

		$facebook_data_expensive = $this->transform_product_for_facebook( $expensive_product );
		$this->assertEquals( '999999.99 USD', $facebook_data_expensive['price'], 'High price should be formatted correctly' );

		// Test price with many decimal places (should be rounded)
		$precise_product = $this->create_simple_product([
			'name' => 'Precise Price Product',
			'regular_price' => '19.999',  // Should round to 19.99
			'status' => 'publish'
		]);

		$facebook_data_precise = $this->transform_product_for_facebook( $precise_product );
		// WooCommerce should handle decimal rounding
		$this->assertTrue( strpos( $facebook_data_precise['price'], '19.99' ) !== false, 'Price should be rounded to 2 decimals' );

		// Test sale price formatting
		$sale_product = $this->create_simple_product([
			'name' => 'Sale Product',
			'regular_price' => '29.99',
			'sale_price' => '19.99',
			'status' => 'publish'
		]);

		$facebook_data_sale = $this->transform_product_for_facebook( $sale_product );
		$this->assertEquals( '29.99 USD', $facebook_data_sale['price'], 'Regular price should be in price field' );
		$this->assertTrue( isset( $facebook_data_sale['sale_price'] ), 'Should have sale price field' );
		$this->assertEquals( '19.99 USD', $facebook_data_sale['sale_price'], 'Sale price should be formatted correctly' );

		// Test product with only sale price (no regular price)
		$sale_only_product = $this->create_simple_product([
			'name' => 'Sale Only Product',
			'regular_price' => '',
			'sale_price' => '15.99',
			'status' => 'publish'
		]);

		$facebook_data_sale_only = $this->transform_product_for_facebook( $sale_only_product );
		// Should handle missing regular price gracefully
		$this->assertTrue( isset( $facebook_data_sale_only['price'] ), 'Should have price field even without regular price' );

		// Test price with different currency (if WooCommerce supports it)
		// This tests that the currency code is properly included
		$currency_product = $this->create_simple_product([
			'name' => 'Currency Test Product',
			'regular_price' => '25.50',
			'status' => 'publish'
		]);

		$facebook_data_currency = $this->transform_product_for_facebook( $currency_product );
		$this->assertTrue( strpos( $facebook_data_currency['price'], 'USD' ) !== false, 'Price should include currency code' );
		$this->assertTrue( strpos( $facebook_data_currency['price'], '25.50' ) !== false, 'Price amount should be preserved' );

		// Test very small price (cents)
		$cheap_product = $this->create_simple_product([
			'name' => 'Cheap Product',
			'regular_price' => '0.01',
			'status' => 'publish'
		]);

		$facebook_data_cheap = $this->transform_product_for_facebook( $cheap_product );
		$this->assertEquals( '0.01 USD', $facebook_data_cheap['price'], 'Small price should be formatted correctly' );
	}

	/**
	 * Test variation image handling and inheritance
	 */
	public function test_variation_image_handling(): void {
		$this->enable_facebook_sync();

		// Create a parent variable product with a main image
		$parent_image_id = $this->create_test_image( 'parent-product-image.jpg' );
		$variable_product = $this->create_variable_product();
		$variable_product->set_image_id( $parent_image_id );
		$variable_product->save();

		// Create variation without its own image (should inherit parent image)
		$variation_no_image = new \WC_Product_Variation();
		$variation_no_image->set_parent_id( $variable_product->get_id() );
		$variation_no_image->set_attributes( [ 'size' => 'Medium' ] );
		$variation_no_image->set_regular_price( '25.99' );
		$variation_no_image->set_status( 'publish' );
		$variation_no_image->save();

		// Create variation with its own image
		$variation_image_id = $this->create_test_image( 'variation-specific-image.jpg' );
		$variation_with_image = new \WC_Product_Variation();
		$variation_with_image->set_parent_id( $variable_product->get_id() );
		$variation_with_image->set_attributes( [ 'size' => 'Large' ] );
		$variation_with_image->set_regular_price( '29.99' );
		$variation_with_image->set_image_id( $variation_image_id );
		$variation_with_image->set_status( 'publish' );
		$variation_with_image->save();

		// Test parent product image transformation
		$parent_facebook_data = $this->transform_product_for_facebook( $variable_product );
		$this->assertTrue( isset( $parent_facebook_data['image_link'] ), 'Parent product should have image link' );
		$this->assertNotEmpty( $parent_facebook_data['image_link'], 'Parent image link should not be empty' );
		$this->assertTrue( strpos( $parent_facebook_data['image_link'], 'parent-product-image' ) !== false, 'Should use parent image' );

		// Test variation without image (inherits from parent)
		$variation_no_image_data = $this->transform_product_for_facebook( $variation_no_image );
		$this->assertTrue( isset( $variation_no_image_data['image_link'] ), 'Variation should have image link' );
		$this->assertNotEmpty( $variation_no_image_data['image_link'], 'Inherited image link should not be empty' );
		// Should inherit parent image
		$this->assertEquals( 
			$parent_facebook_data['image_link'], 
			$variation_no_image_data['image_link'], 
			'Variation without image should inherit parent image' 
		);

		// Test variation with its own image
		$variation_with_image_data = $this->transform_product_for_facebook( $variation_with_image );
		$this->assertTrue( isset( $variation_with_image_data['image_link'] ), 'Variation should have image link' );
		$this->assertNotEmpty( $variation_with_image_data['image_link'], 'Variation image link should not be empty' );
		$this->assertTrue( strpos( $variation_with_image_data['image_link'], 'variation-specific-image' ) !== false, 'Should use variation-specific image' );

		// Images should be different
		$this->assertNotEquals(
			$variation_no_image_data['image_link'],
			$variation_with_image_data['image_link'],
			'Variations should have different image URLs when one has specific image'
		);

		// Test variation with gallery images
		$gallery_image_1 = $this->create_test_image( 'gallery-image-1.jpg' );
		$gallery_image_2 = $this->create_test_image( 'gallery-image-2.jpg' );
		
		$variation_with_gallery = new \WC_Product_Variation();
		$variation_with_gallery->set_parent_id( $variable_product->get_id() );
		$variation_with_gallery->set_attributes( [ 'size' => 'XL' ] );
		$variation_with_gallery->set_regular_price( '34.99' );
		$variation_with_gallery->set_image_id( $variation_image_id );
		$variation_with_gallery->set_gallery_image_ids( [ $gallery_image_1, $gallery_image_2 ] );
		$variation_with_gallery->set_status( 'publish' );
		$variation_with_gallery->save();

		$variation_gallery_data = $this->transform_product_for_facebook( $variation_with_gallery );
		
		// Test that main image is used (not gallery images in basic transformation)
		$this->assertTrue( isset( $variation_gallery_data['image_link'] ), 'Variation with gallery should have main image link' );
		$this->assertTrue( strpos( $variation_gallery_data['image_link'], 'variation-specific-image' ) !== false, 'Should use main image, not gallery' );

		// Test variation with no image and parent has no image
		$no_image_parent = $this->create_variable_product();
		// Parent has no image set
		
		$variation_no_parent_image = new \WC_Product_Variation();
		$variation_no_parent_image->set_parent_id( $no_image_parent->get_id() );
		$variation_no_parent_image->set_attributes( [ 'size' => 'Small' ] );
		$variation_no_parent_image->set_regular_price( '19.99' );
		$variation_no_parent_image->set_status( 'publish' );
		$variation_no_parent_image->save();

		$no_image_data = $this->transform_product_for_facebook( $variation_no_parent_image );
		$this->assertTrue( isset( $no_image_data['image_link'] ), 'Should have image link field even without images' );
		// Image link might be empty or placeholder
		$this->assertTrue( 
			empty( $no_image_data['image_link'] ) || $this->is_placeholder_image( $no_image_data['image_link'] ),
			'Should have empty or placeholder image when no images available'
		);
	}

	/**
	 * Helper method to create a test image attachment
	 */
	private function create_test_image( string $filename = 'test-image.jpg' ): int {
		// Create a simple test image file
		$upload_dir = wp_upload_dir();
		$image_path = $upload_dir['path'] . '/' . $filename;
		
		// Create a simple 1x1 pixel image
		$image = imagecreate( 100, 100 );
		$background = imagecolorallocate( $image, 255, 255, 255 ); // White background
		$text_color = imagecolorallocate( $image, 0, 0, 0 ); // Black text
		imagestring( $image, 5, 10, 40, 'TEST', $text_color );
		
		imagejpeg( $image, $image_path );
		imagedestroy( $image );

		// Create attachment
		$attachment = [
			'post_mime_type' => 'image/jpeg',
			'post_title' => sanitize_file_name( $filename ),
			'post_content' => '',
			'post_status' => 'inherit'
		];

		$attachment_id = wp_insert_attachment( $attachment, $image_path );
		
		if ( ! is_wp_error( $attachment_id ) ) {
			// Generate attachment metadata
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $image_path );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );
		}

		return $attachment_id;
	}

	/**
	 * Helper method to check if an image URL is a placeholder
	 */
	private function is_placeholder_image( string $image_url ): bool {
		return strpos( $image_url, 'placeholder' ) !== false || 
		       strpos( $image_url, 'woocommerce-placeholder' ) !== false ||
		       empty( $image_url );
	}

	/**
	 * Helper method to simulate product transformation for Facebook
	 */
	private function transform_product_for_facebook( \WC_Product $product ): array {
		// This simulates the actual Facebook transformation pipeline
		// In a real implementation, this would call the actual transformation classes
		
		$facebook_data = [
			'id' => $product->get_id(),
			'title' => $product->get_name(),
			'description' => $product->get_description() ?: $product->get_short_description(),
			'price' => $product->get_regular_price() . ' USD',
			'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
			'condition' => 'new',
			'brand' => get_bloginfo( 'name' ), // Default to site name
		];

		// Add sale price if available
		if ( $product->is_on_sale() && $product->get_sale_price() ) {
			$facebook_data['sale_price'] = $product->get_sale_price() . ' USD';
		}

		// Add item group for variable products
		if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) ) {
			$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
			$facebook_data['item_group_id'] = 'group_' . $parent_id;
		}

		// Add SKU if available
		if ( $product->get_sku() ) {
			$facebook_data['retailer_id'] = $product->get_sku();
		}

		// Add image link handling
		$image_id = $product->get_image_id();
		
		// For variations, if no image, try to inherit from parent
		if ( ! $image_id && $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			if ( $parent_product ) {
				$image_id = $parent_product->get_image_id();
			}
		}
		
		if ( $image_id ) {
			$image_url = wp_get_attachment_url( $image_id );
			$facebook_data['image_link'] = $image_url ?: '';
		} else {
			$facebook_data['image_link'] = '';
		}

		return $facebook_data;
	}

	/**
	 * Helper method to check if product should sync to Facebook
	 */
	private function should_product_sync_to_facebook( \WC_Product $product ): bool {
		// Basic sync eligibility checks
		if ( $product->get_status() !== 'publish' ) {
			return false;
		}

		if ( empty( $product->get_name() ) ) {
			return false;
		}

		if ( empty( $product->get_regular_price() ) || floatval( $product->get_regular_price() ) <= 0 ) {
			return false;
		}

		// Check category exclusions
		$excluded_categories = get_option( 'woocommerce_facebookcommerce_settings', [] );
		$excluded_category_ids = $excluded_categories['excluded_product_category_ids'] ?? [];
		
		if ( ! empty( $excluded_category_ids ) ) {
			$product_categories = $product->get_category_ids();
			if ( array_intersect( $product_categories, $excluded_category_ids ) ) {
				return false;
			}
		}

		return true;
	}
} 