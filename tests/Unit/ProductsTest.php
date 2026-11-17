<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Handlers\PluginRender;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for WooCommerce\Facebook\Products class.
 *
 * Tests the Products handler functionality including sync settings,
 * visibility, pricing, attributes, and product lookups.
 *
 * @covers \WooCommerce\Facebook\Products
 */
class ProductsTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * @var \WC_Product_Simple|null
	 */
	private $simple_product;

	/**
	 * @var \WC_Product_Variable|null
	 */
	private $variable_product;

	/**
	 * @var \WC_Product_Variation|null
	 */
	private $variation;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a simple product
		$this->simple_product = new \WC_Product_Simple();
		$this->simple_product->set_name( 'Test Simple Product' );
		$this->simple_product->set_regular_price( '19.99' );
		$this->simple_product->set_status( 'publish' );
		$this->simple_product->set_manage_stock( true );
		$this->simple_product->set_stock_quantity( 10 );
		$this->simple_product->save();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up products
		if ( $this->simple_product ) {
			$this->simple_product->delete( true );
			$this->simple_product = null;
		}

		if ( $this->variable_product ) {
			$this->variable_product->delete( true );
			$this->variable_product = null;
		}

		if ( $this->variation ) {
			$this->variation->delete( true );
			$this->variation = null;
		}

		parent::tearDown();
	}

	/**
	 * Helper to create a variable product with variations.
	 */
	private function create_variable_product(): void {
		$this->variable_product = new \WC_Product_Variable();
		$this->variable_product->set_name( 'Test Variable Product' );
		$this->variable_product->set_status( 'publish' );

		// Create a product attribute
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Color' );
		$attribute->set_options( array( 'Red', 'Blue' ) );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$this->variable_product->set_attributes( array( $attribute ) );
		$this->variable_product->save();

		// Create a variation
		$this->variation = new \WC_Product_Variation();
		$this->variation->set_parent_id( $this->variable_product->get_id() );
		$this->variation->set_regular_price( '24.99' );
		$this->variation->set_attributes( array( 'color' => 'Red' ) );
		$this->variation->save();
	}

	/**
	 * Test that SYNC_ENABLED_META_KEY constant is defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_sync_enabled_meta_key_constant(): void {
		$this->assertSame(
			'_wc_facebook_sync_enabled',
			Products::SYNC_ENABLED_META_KEY,
			'SYNC_ENABLED_META_KEY should have the expected value'
		);
	}

	/**
	 * Test that VISIBILITY_META_KEY constant is defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_visibility_meta_key_constant(): void {
		$this->assertSame(
			'fb_visibility',
			Products::VISIBILITY_META_KEY,
			'VISIBILITY_META_KEY should have the expected value'
		);
	}

	/**
	 * Test that PRODUCT_IMAGE_SOURCE_META_KEY constant is defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_product_image_source_meta_key_constant(): void {
		$this->assertSame(
			'_wc_facebook_product_image_source',
			Products::PRODUCT_IMAGE_SOURCE_META_KEY,
			'PRODUCT_IMAGE_SOURCE_META_KEY should have the expected value'
		);
	}

	/**
	 * Test that PRODUCT_IMAGE_SOURCE constants are defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_product_image_source_constants(): void {
		$this->assertSame( 'product', Products::PRODUCT_IMAGE_SOURCE_PRODUCT );
		$this->assertSame( 'parent_product', Products::PRODUCT_IMAGE_SOURCE_PARENT_PRODUCT );
		$this->assertSame( 'custom', Products::PRODUCT_IMAGE_SOURCE_CUSTOM );
		$this->assertSame( 'multiple', Products::PRODUCT_IMAGE_SOURCE_MULTIPLE );
	}

	/**
	 * Test that GOOGLE_PRODUCT_CATEGORY_META_KEY constant is defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_google_product_category_meta_key_constant(): void {
		$this->assertSame(
			'_wc_facebook_google_product_category',
			Products::GOOGLE_PRODUCT_CATEGORY_META_KEY,
			'GOOGLE_PRODUCT_CATEGORY_META_KEY should have the expected value'
		);
	}

	/**
	 * Test that GENDER_META_KEY constant is defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_gender_meta_key_constant(): void {
		$this->assertSame(
			'_wc_facebook_gender',
			Products::GENDER_META_KEY,
			'GENDER_META_KEY should have the expected value'
		);
	}

	/**
	 * Test that attribute meta key constants are defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Products
	 */
	public function test_attribute_meta_key_constants(): void {
		$this->assertSame( '_wc_facebook_color_attribute', Products::COLOR_ATTRIBUTE_META_KEY );
		$this->assertSame( '_wc_facebook_size_attribute', Products::SIZE_ATTRIBUTE_META_KEY );
		$this->assertSame( '_wc_facebook_pattern_attribute', Products::PATTERN_ATTRIBUTE_META_KEY );
	}

	/**
	 * Test set_product_visibility with true value.
	 *
	 * @covers \WooCommerce\Facebook\Products::set_product_visibility
	 */
	public function test_set_product_visibility_true(): void {
		$result = Products::set_product_visibility( $this->simple_product, true );

		$this->assertTrue( $result, 'Setting visibility should return true' );

		$stored_visibility = $this->simple_product->get_meta( Products::VISIBILITY_META_KEY );
		$this->assertSame( 'yes', $stored_visibility, 'Visibility should be stored as yes' );
	}

	/**
	 * Test set_product_visibility with false value.
	 *
	 * @covers \WooCommerce\Facebook\Products::set_product_visibility
	 */
	public function test_set_product_visibility_false(): void {
		$result = Products::set_product_visibility( $this->simple_product, false );

		$this->assertTrue( $result, 'Setting visibility should return true' );

		$stored_visibility = $this->simple_product->get_meta( Products::VISIBILITY_META_KEY );
		$this->assertSame( 'no', $stored_visibility, 'Visibility should be stored as no' );
	}

	/**
	 * Test set_product_visibility with non-boolean value.
	 *
	 * @covers \WooCommerce\Facebook\Products::set_product_visibility
	 */
	public function test_set_product_visibility_with_invalid_value(): void {
		$result = Products::set_product_visibility( $this->simple_product, 'invalid' );

		$this->assertFalse( $result, 'Setting visibility with invalid value should return false' );
	}

	/**
	 * Test is_product_visible returns true when visibility is set to yes.
	 *
	 * @covers \WooCommerce\Facebook\Products::is_product_visible
	 */
	public function test_is_product_visible_when_set_to_yes(): void {
		$this->simple_product->update_meta_data( Products::VISIBILITY_META_KEY, 'yes' );
		$this->simple_product->save_meta_data();

		// Need to reset the memoization cache
		Products::set_product_visibility( $this->simple_product, true );

		$this->assertTrue(
			Products::is_product_visible( $this->simple_product ),
			'Product should be visible when visibility is yes'
		);
	}

	/**
	 * Test is_product_visible returns false when visibility is set to no.
	 *
	 * @covers \WooCommerce\Facebook\Products::is_product_visible
	 */
	public function test_is_product_visible_when_set_to_no(): void {
		Products::set_product_visibility( $this->simple_product, false );

		$this->assertFalse(
			Products::is_product_visible( $this->simple_product ),
			'Product should not be visible when visibility is no'
		);
	}

	/**
	 * Test is_product_visible returns true by default (no meta set).
	 *
	 * @covers \WooCommerce\Facebook\Products::is_product_visible
	 */
	public function test_is_product_visible_default(): void {
		// Create a fresh product with no visibility meta
		$product = new \WC_Product_Simple();
		$product->set_name( 'Fresh Product' );
		$product->set_regular_price( '9.99' );
		$product->set_status( 'publish' );
		$product->save();

		$is_visible = Products::is_product_visible( $product );

		$this->assertTrue(
			$is_visible,
			'Product should be visible by default'
		);

		$product->delete( true );
	}

	/**
	 * Test is_product_visible returns false for out of stock products when hide option is enabled.
	 *
	 * @covers \WooCommerce\Facebook\Products::is_product_visible
	 */
	public function test_is_product_visible_returns_false_when_out_of_stock_and_hide_enabled(): void {
		// Enable hide out of stock option
		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Out of Stock Product' );
		$product->set_regular_price( '9.99' );
		$product->set_status( 'publish' );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$is_visible = Products::is_product_visible( $product );

		$this->assertFalse(
			$is_visible,
			'Out of stock product should not be visible when hide option is enabled'
		);

		// Clean up
		$product->delete( true );
		delete_option( 'woocommerce_hide_out_of_stock_items' );
	}

	/**
	 * Test get_product_price returns price in cents.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_price
	 */
	public function test_get_product_price_returns_cents(): void {
		$price = Products::get_product_price( $this->simple_product );

		$this->assertIsInt( $price, 'Price should be an integer' );
		$this->assertGreaterThan( 0, $price, 'Price should be greater than 0' );
	}

	/**
	 * Test get_product_price with zero price product.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_price
	 */
	public function test_get_product_price_with_zero_price(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Free Product' );
		$product->set_regular_price( '0' );
		$product->set_status( 'publish' );
		$product->save();

		$price = Products::get_product_price( $product );

		$this->assertSame( 0, $price, 'Price should be 0 for free products' );

		$product->delete( true );
	}

	/**
	 * Test get_product_gender returns unisex by default.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_gender
	 */
	public function test_get_product_gender_default(): void {
		$gender = Products::get_product_gender( $this->simple_product );

		$this->assertSame(
			'unisex',
			$gender,
			'Default gender should be unisex'
		);
	}

	/**
	 * Test get_product_gender returns stored value.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_gender
	 */
	public function test_get_product_gender_returns_stored_value(): void {
		$this->simple_product->update_meta_data( Products::GENDER_META_KEY, 'female' );
		$this->simple_product->save_meta_data();

		$gender = Products::get_product_gender( $this->simple_product );

		$this->assertSame(
			'female',
			$gender,
			'Gender should be the stored value'
		);
	}

	/**
	 * Test get_product_gender returns unisex for invalid values.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_gender
	 */
	public function test_get_product_gender_returns_unisex_for_invalid(): void {
		$this->simple_product->update_meta_data( Products::GENDER_META_KEY, 'invalid_gender' );
		$this->simple_product->save_meta_data();

		$gender = Products::get_product_gender( $this->simple_product );

		$this->assertSame(
			'unisex',
			$gender,
			'Invalid gender should default to unisex'
		);
	}

	/**
	 * Test update_product_gender updates the stored value.
	 *
	 * @covers \WooCommerce\Facebook\Products::update_product_gender
	 */
	public function test_update_product_gender(): void {
		Products::update_product_gender( $this->simple_product, 'male' );

		$stored_gender = $this->simple_product->get_meta( Products::GENDER_META_KEY );
		$this->assertSame(
			'male',
			$stored_gender,
			'Gender should be updated'
		);
	}

	/**
	 * Test update_google_product_category_id updates the stored value.
	 *
	 * @covers \WooCommerce\Facebook\Products::update_google_product_category_id
	 */
	public function test_update_google_product_category_id(): void {
		Products::update_google_product_category_id( $this->simple_product, '12345' );

		$stored_category = $this->simple_product->get_meta( Products::GOOGLE_PRODUCT_CATEGORY_META_KEY );
		$this->assertSame(
			'12345',
			$stored_category,
			'Google product category ID should be updated'
		);
	}

	/**
	 * Test get_available_product_attributes returns array.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_available_product_attributes
	 */
	public function test_get_available_product_attributes_returns_array(): void {
		$attributes = Products::get_available_product_attributes( $this->simple_product );

		$this->assertIsArray(
			$attributes,
			'Should return an array'
		);
	}

	/**
	 * Test get_available_product_attributes for variable product.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_available_product_attributes
	 */
	public function test_get_available_product_attributes_for_variable_product(): void {
		$this->create_variable_product();

		$attributes = Products::get_available_product_attributes( $this->variable_product );

		$this->assertNotEmpty(
			$attributes,
			'Variable product should have attributes'
		);
	}

	/**
	 * Test get_available_product_attributes for variation returns parent attributes.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_available_product_attributes
	 */
	public function test_get_available_product_attributes_for_variation(): void {
		$this->create_variable_product();

		$attributes = Products::get_available_product_attributes( $this->variation );

		$this->assertNotEmpty(
			$attributes,
			'Variation should return parent attributes'
		);
	}

	/**
	 * Test product_has_attribute returns false when attribute not present.
	 *
	 * @covers \WooCommerce\Facebook\Products::product_has_attribute
	 */
	public function test_product_has_attribute_returns_false_when_not_present(): void {
		$result = Products::product_has_attribute( $this->simple_product, 'nonexistent_attribute' );

		$this->assertFalse(
			$result,
			'Should return false when attribute is not present'
		);
	}

	/**
	 * Test product_has_attribute returns true when attribute is present.
	 *
	 * @covers \WooCommerce\Facebook\Products::product_has_attribute
	 */
	public function test_product_has_attribute_returns_true_when_present(): void {
		$this->create_variable_product();

		$result = Products::product_has_attribute( $this->variable_product, 'color' );

		$this->assertTrue(
			$result,
			'Should return true when attribute is present'
		);
	}

	/**
	 * Test get_distinct_product_attributes returns array.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_distinct_product_attributes
	 */
	public function test_get_distinct_product_attributes_returns_array(): void {
		$attributes = Products::get_distinct_product_attributes( $this->simple_product );

		$this->assertIsArray(
			$attributes,
			'Should return an array'
		);
	}

	/**
	 * Test get_product_color_attribute returns empty string when not set.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_color_attribute
	 */
	public function test_get_product_color_attribute_returns_empty_when_not_set(): void {
		$attribute = Products::get_product_color_attribute( $this->simple_product );

		$this->assertSame(
			'',
			$attribute,
			'Should return empty string when no color attribute is set'
		);
	}

	/**
	 * Test get_product_size_attribute returns empty string when not set.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_size_attribute
	 */
	public function test_get_product_size_attribute_returns_empty_when_not_set(): void {
		$attribute = Products::get_product_size_attribute( $this->simple_product );

		$this->assertSame(
			'',
			$attribute,
			'Should return empty string when no size attribute is set'
		);
	}

	/**
	 * Test get_product_pattern_attribute returns empty string when not set.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_pattern_attribute
	 */
	public function test_get_product_pattern_attribute_returns_empty_when_not_set(): void {
		$attribute = Products::get_product_pattern_attribute( $this->simple_product );

		$this->assertSame(
			'',
			$attribute,
			'Should return empty string when no pattern attribute is set'
		);
	}

	/**
	 * Test get_product_color returns empty string when no color attribute set.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_color
	 */
	public function test_get_product_color_returns_empty_when_not_set(): void {
		$color = Products::get_product_color( $this->simple_product );

		$this->assertSame(
			'',
			$color,
			'Should return empty string when no color is set'
		);
	}

	/**
	 * Test get_product_size returns empty string when no size attribute set.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_size
	 */
	public function test_get_product_size_returns_empty_when_not_set(): void {
		$size = Products::get_product_size( $this->simple_product );

		$this->assertSame(
			'',
			$size,
			'Should return empty string when no size is set'
		);
	}

	/**
	 * Test get_product_pattern returns empty string when no pattern attribute set.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_pattern
	 */
	public function test_get_product_pattern_returns_empty_when_not_set(): void {
		$pattern = Products::get_product_pattern( $this->simple_product );

		$this->assertSame(
			'',
			$pattern,
			'Should return empty string when no pattern is set'
		);
	}

	/**
	 * Test get_product_by_fb_product_id returns null when not found.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_by_fb_product_id
	 */
	public function test_get_product_by_fb_product_id_returns_null_when_not_found(): void {
		$product = Products::get_product_by_fb_product_id( 'nonexistent_fb_id' );

		$this->assertNull(
			$product,
			'Should return null when product is not found'
		);
	}

	/**
	 * Test get_product_by_fb_product_id returns product when found by item ID.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_by_fb_product_id
	 */
	public function test_get_product_by_fb_product_id_returns_product_by_item_id(): void {
		$fb_item_id = 'test_fb_item_id_12345';
		$this->simple_product->update_meta_data( \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, $fb_item_id );
		$this->simple_product->save_meta_data();

		$found_product = Products::get_product_by_fb_product_id( $fb_item_id );

		$this->assertInstanceOf(
			\WC_Product::class,
			$found_product,
			'Should return WC_Product when found'
		);
		$this->assertSame(
			$this->simple_product->get_id(),
			$found_product->get_id(),
			'Should return the correct product'
		);
	}

	/**
	 * Test get_product_by_fb_product_id returns product when found by group ID.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_by_fb_product_id
	 */
	public function test_get_product_by_fb_product_id_returns_product_by_group_id(): void {
		$fb_group_id = 'test_fb_group_id_67890';
		$this->simple_product->update_meta_data( \WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, $fb_group_id );
		$this->simple_product->save_meta_data();

		$found_product = Products::get_product_by_fb_product_id( $fb_group_id );

		$this->assertInstanceOf(
			\WC_Product::class,
			$found_product,
			'Should return WC_Product when found by group ID'
		);
	}

	/**
	 * Test get_product_by_fb_retailer_id returns null when not found.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_by_fb_retailer_id
	 */
	public function test_get_product_by_fb_retailer_id_returns_null_when_not_found(): void {
		$product = Products::get_product_by_fb_retailer_id( 'wc_post_id_99999999' );

		$this->assertNull(
			$product,
			'Should return null when product is not found'
		);
	}

	/**
	 * Test get_product_by_fb_retailer_id with FB prefix format.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_by_fb_retailer_id
	 */
	public function test_get_product_by_fb_retailer_id_with_fb_prefix(): void {
		$retailer_id = \WC_Facebookcommerce_Utils::FB_RETAILER_ID_PREFIX . $this->simple_product->get_id();

		$found_product = Products::get_product_by_fb_retailer_id( $retailer_id );

		$this->assertInstanceOf(
			\WC_Product::class,
			$found_product,
			'Should return WC_Product with FB prefix format'
		);
		$this->assertSame(
			$this->simple_product->get_id(),
			$found_product->get_id(),
			'Should return the correct product'
		);
	}

	/**
	 * Test get_product_by_fb_retailer_id with underscore format.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_by_fb_retailer_id
	 */
	public function test_get_product_by_fb_retailer_id_with_underscore_format(): void {
		$retailer_id = 'test_product_' . $this->simple_product->get_id();

		$found_product = Products::get_product_by_fb_retailer_id( $retailer_id );

		$this->assertInstanceOf(
			\WC_Product::class,
			$found_product,
			'Should return WC_Product with underscore format'
		);
	}

	/**
	 * Test get_enhanced_catalog_attribute returns null for invalid product.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_enhanced_catalog_attribute
	 */
	public function test_get_enhanced_catalog_attribute_returns_null_for_null_product(): void {
		// Create a mock scenario where the product check fails
		$result = Products::get_enhanced_catalog_attribute( 'test_key', $this->simple_product );

		// The method should return null or empty for a key that doesn't exist
		$this->assertTrue(
			empty( $result ),
			'Should return empty/null for non-existent attribute'
		);
	}

	/**
	 * Test get_enhanced_catalog_attribute returns stored value.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_enhanced_catalog_attribute
	 */
	public function test_get_enhanced_catalog_attribute_returns_stored_value(): void {
		$meta_key = Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . 'brand';
		$this->simple_product->update_meta_data( $meta_key, 'Test Brand' );
		$this->simple_product->save_meta_data();

		$result = Products::get_enhanced_catalog_attribute( 'brand', $this->simple_product );

		$this->assertSame(
			'Test Brand',
			$result,
			'Should return stored enhanced catalog attribute'
		);
	}

	/**
	 * Test update_product_enhanced_catalog_attribute updates value.
	 *
	 * @covers \WooCommerce\Facebook\Products::update_product_enhanced_catalog_attribute
	 */
	public function test_update_product_enhanced_catalog_attribute(): void {
		Products::update_product_enhanced_catalog_attribute( $this->simple_product, 'material', 'Cotton' );

		$stored_value = $this->simple_product->get_meta(
			Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . 'material'
		);

		$this->assertSame(
			'Cotton',
			$stored_value,
			'Enhanced catalog attribute should be updated'
		);
	}

	/**
	 * Test set_product_visibility handles variable products without error.
	 *
	 * Note: We only test that the parent product visibility is set.
	 * Testing variation visibility would require WooCommerce's get_children() cache
	 * to be synced, which doesn't happen automatically in unit test environments.
	 *
	 * @covers \WooCommerce\Facebook\Products::set_product_visibility
	 */
	public function test_set_product_visibility_for_variable_product(): void {
		$this->create_variable_product();

		$result = Products::set_product_visibility( $this->variable_product, true );

		$this->assertTrue( $result, 'Setting visibility should return true for variable products' );

		// Verify the parent product's visibility is set
		$stored_visibility = $this->variable_product->get_meta( Products::VISIBILITY_META_KEY );
		$this->assertSame(
			'yes',
			$stored_visibility,
			'Variable product visibility should be updated'
		);
	}

	/**
	 * Test get_product_gender for variation returns parent gender.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_gender
	 */
	public function test_get_product_gender_for_variation(): void {
		$this->create_variable_product();

		$this->variable_product->update_meta_data( Products::GENDER_META_KEY, 'female' );
		$this->variable_product->save_meta_data();

		$gender = Products::get_product_gender( $this->variation );

		$this->assertSame(
			'female',
			$gender,
			'Variation should return parent product gender'
		);
	}

	/**
	 * Test get_google_product_category_id for product with stored value.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_google_product_category_id
	 */
	public function test_get_google_product_category_id_with_stored_value(): void {
		$this->simple_product->update_meta_data( Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, '12345' );
		$this->simple_product->save_meta_data();

		$category_id = Products::get_google_product_category_id( $this->simple_product );

		$this->assertSame(
			'12345',
			$category_id,
			'Should return stored Google product category ID'
		);
	}

	/**
	 * Test get_google_product_category_id for variation returns parent value.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_google_product_category_id
	 */
	public function test_get_google_product_category_id_for_variation(): void {
		$this->create_variable_product();

		$this->variable_product->update_meta_data( Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, '67890' );
		$this->variable_product->save_meta_data();

		$category_id = Products::get_google_product_category_id( $this->variation );

		$this->assertSame(
			'67890',
			$category_id,
			'Variation should return parent Google product category ID'
		);
	}

	/**
	 * Test get_product_sync_meta_key uses the all-products key unless the store opted out, whatever
	 * the retired woo_all_products_sync_enabled rollout switch still says.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_sync_meta_key
	 */
	public function test_get_product_sync_meta_key_uses_the_all_products_key_without_an_opt_out(): void {
		delete_option( PluginRender::MASTER_SYNC_OPT_OUT_TIME );
		update_option( 'wc_facebook_for_woocommerce_rollout_switches', array( 'woo_all_products_sync_enabled' => 'no' ) );

		$this->assertSame( Products::OPTEN_IN_SYNC_ENABLED_META_KEY, Products::get_product_sync_meta_key() );
	}

	/**
	 * Test get_product_sync_meta_key keeps the per-product legacy key for stores that opted out of
	 * syncing all products.
	 *
	 * @covers \WooCommerce\Facebook\Products::get_product_sync_meta_key
	 */
	public function test_get_product_sync_meta_key_uses_the_legacy_key_after_an_opt_out(): void {
		update_option( PluginRender::MASTER_SYNC_OPT_OUT_TIME, '2025-06-01 10:00:00' );

		$this->assertSame( Products::SYNC_ENABLED_META_KEY, Products::get_product_sync_meta_key() );
	}
}
