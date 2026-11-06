<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\ProductValidation;

use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;
use WooCommerce\Facebook\Products;
use WC_Product;

/**
 * Test class for ProductValidator integration scenarios.
 *
 * Tests core business logic for determining whether products should be synced to Facebook.
 *
 */
class ProductValidatorTest extends IntegrationTestCase {

	/**
	 * Test product sync when globally enabled
	 */
	public function test_product_sync_when_globally_enabled(): void {
		// Enable Facebook sync globally
		$this->enable_facebook_sync();

		// Create a simple product
		$product = $this->create_simple_product([
			'name' => 'Test Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		// Product should be synced when all conditions are met
		$this->assertProductShouldSync( $product, 'Product should sync when globally enabled and meets all criteria' );
	}

	/**
	 * Test a store that turned product sync off before the setting was retired still syncs.
	 */
	public function test_product_syncs_although_the_retired_store_wide_setting_is_off(): void {
		update_option( \WC_Facebookcommerce_Integration::SETTING_ENABLE_PRODUCT_SYNC, 'no' );

		$product = $this->create_simple_product([
			'name' => 'Test Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		$this->assertProductShouldSync( $product, 'The retired store-wide sync setting must no longer stop products from syncing' );
	}

	/**
	 * Test product sync with draft status
	 */
	public function test_product_sync_with_draft_status(): void {
		$this->enable_facebook_sync();

		// Create a draft product
		$product = $this->create_simple_product([
			'name' => 'Draft Product',
			'regular_price' => '19.99',
			'status' => 'draft',
			'catalog_visibility' => 'visible'
		]);

		// Draft products should not be synced
		$this->assertProductShouldNotSync( $product, 'Draft products should not be synced' );
	}

	/**
	 * Test product sync with private status
	 */
	public function test_product_sync_with_private_status(): void {
		$this->enable_facebook_sync();

		// Create a private product
		$product = $this->create_simple_product([
			'name' => 'Private Product',
			'regular_price' => '19.99',
			'status' => 'private',
			'catalog_visibility' => 'visible'
		]);

		// Private products should not be synced
		$this->assertProductShouldNotSync( $product, 'Private products should not be synced' );
	}

	/**
	 * Test product sync with hidden catalog visibility
	 */
	public function test_product_sync_with_hidden_visibility(): void {
		$this->enable_facebook_sync();

		// Create a hidden product
		$product = $this->create_simple_product([
			'name' => 'Hidden Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'hidden'
		]);

		// Hidden products should not be synced
		$this->assertProductShouldNotSync( $product, 'Hidden products should not be synced' );
	}

	/**
	 * Test product sync with search-only visibility
	 */
	public function test_product_sync_with_search_only_visibility(): void {
		$this->enable_facebook_sync();

		// Create a search-only product
		$product = $this->create_simple_product([
			'name' => 'Search Only Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'search'
		]);

		// Search-only products should NOT be synced when not in search context
		// This follows WooCommerce's core visibility logic
		$this->assertProductShouldNotSync( $product, 'Search-only products should not be synced outside search context' );
	}

	/**
	 * Test product sync with catalog-only visibility
	 */
	public function test_product_sync_with_catalog_only_visibility(): void {
		$this->enable_facebook_sync();

		// Create a catalog-only product
		$product = $this->create_simple_product([
			'name' => 'Catalog Only Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'catalog'
		]);

		// Catalog-only products should be synced
		$this->assertProductShouldSync( $product, 'Catalog-only products should be synced' );
	}

	/**
	 * Test a category excluded before exclusions were retired no longer stops sync
	 */
	public function test_product_syncs_although_its_category_was_excluded(): void {
		$this->enable_facebook_sync();

		// Create a test category
		$category = $this->create_category( 'Excluded Category' );

		// Save the category as excluded, as the retired Product sync tab did
		update_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, [ $category->term_id ] );

		// Create a product in the excluded category
		$product = $this->create_simple_product([
			'name' => 'Product in Excluded Category',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		// Assign product to excluded category
		wp_set_object_terms( $product->get_id(), [ $category->term_id ], 'product_cat' );
		
		// Refresh the product to get updated category data
		$product = wc_get_product( $product->get_id() );

		$this->assertProductShouldSync( $product, 'A saved category exclusion must no longer stop products from syncing' );
	}

	/**
	 * Test a tag excluded before exclusions were retired no longer stops sync
	 */
	public function test_product_syncs_although_its_tag_was_excluded(): void {
		$this->enable_facebook_sync();

		// Create a test tag
		$tag_result = wp_insert_term( 'Excluded Tag', 'product_tag' );
		$tag_id = $tag_result['term_id'];

		// Save the tag as excluded, as the retired Product sync tab did
		update_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_TAG_IDS, [ $tag_id ] );

		// Create a product with the excluded tag
		$product = $this->create_simple_product([
			'name' => 'Product with Excluded Tag',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		// Assign product to excluded tag
		wp_set_object_terms( $product->get_id(), [ $tag_id ], 'product_tag' );
		
		// Refresh the product to get updated tag data
		$product = wc_get_product( $product->get_id() );

		$this->assertProductShouldSync( $product, 'A saved tag exclusion must no longer stop products from syncing' );
	}

	/**
	 * Test product sync explicitly disabled at product level
	 */
	public function test_product_sync_explicitly_disabled(): void {
		$this->enable_facebook_sync();

		// Create a product
		$product = $this->create_simple_product([
			'name' => 'Disabled Sync Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		// Explicitly disable sync for this product
		$this->disable_product_sync( $product );

		// Product should not be synced when explicitly disabled
		$this->assertProductShouldNotSync( $product, 'Products with sync explicitly disabled should not be synced' );
	}

	/**
	 * Test product sync explicitly enabled at product level
	 */
	public function test_product_sync_explicitly_enabled(): void {
		$this->enable_facebook_sync();

		// Create a product
		$product = $this->create_simple_product([
			'name' => 'Enabled Sync Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		// Explicitly enable sync for this product
		$this->enable_product_sync( $product );

		// Product should be synced when explicitly enabled
		$this->assertProductShouldSync( $product, 'Products with sync explicitly enabled should be synced' );
	}

	/**
	 * Test variable product sync validation
	 */
	public function test_variable_product_sync(): void {
		$this->enable_facebook_sync();

		// Create a variable product with variations
		$variable_product = $this->create_variable_product();

		// Variable products themselves should not be synced (only variations)
		// But the validation should pass for the parent product logic
		$validator = facebook_for_woocommerce()->get_product_sync_validator( $variable_product );
		
		// Test that validation passes for variable products
		$this->assertTrue( 
			$validator->passes_all_checks_except_sync_field(), 
			'Variable product should pass validation checks' 
		);
	}

	/**
	 * Test product_should_be_deleted is deprecated and never asks to delete a product, even one in
	 * a category saved as excluded
	 */
	public function test_product_should_be_deleted_is_deprecated_and_false(): void {
		$this->setExpectedDeprecated( Products::class . '::product_should_be_deleted' );

		$category = $this->create_category( 'Test Category' );
		update_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, [ $category->term_id ] );

		$product = $this->create_simple_product([
			'name' => 'Test Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);
		wp_set_object_terms( $product->get_id(), [ $category->term_id ], 'product_cat' );
		$product = wc_get_product( $product->get_id() );

		$this->assertFalse( Products::product_should_be_deleted( $product ) );
	}

	/**
	 * Test product sync with no price
	 */
	public function test_product_sync_with_no_price(): void {
		$this->enable_facebook_sync();

		// Create a product without price
		$product = $this->create_simple_product([
			'name' => 'Product Without Price',
			'regular_price' => '',
			'status' => 'publish',
			'catalog_visibility' => 'visible'
		]);

		// Products without price should still be validated (price validation is separate)
		$this->assertProductShouldSync( $product, 'Products without price should pass basic validation' );
	}

	/**
	 * Test product sync with out of stock products
	 */
	public function test_product_sync_with_out_of_stock(): void {
		$this->enable_facebook_sync();

		// Create an out of stock product
		$product = $this->create_simple_product([
			'name' => 'Out of Stock Product',
			'regular_price' => '19.99',
			'status' => 'publish',
			'catalog_visibility' => 'visible',
			'manage_stock' => true,
			'stock_quantity' => 0,
			'stock_status' => 'outofstock'
		]);

		// Out of stock products should still be synced
		$this->assertProductShouldSync( $product, 'Out of stock products should be synced' );
	}

	/**
	 * Test orphan variation does not fatally error when checking sync field.
	 */
	public function test_orphan_variation_sync_field_check_does_not_fatal(): void {
		$this->enable_facebook_sync();

		$variable_product = $this->create_variable_product();
		$variation_ids    = $variable_product->get_children();

		$this->assertNotEmpty( $variation_ids, 'Variable product should have at least one variation.' );

		$variation_id = (int) $variation_ids[0];

		// Remove parent permanently so the variation has no resolvable WC parent object.
		wp_delete_post( $variable_product->get_id(), true );

		$orphan_variation = wc_get_product( $variation_id );
		$this->assertInstanceOf( WC_Product::class, $orphan_variation );
		$this->assertSame( 'variation', $orphan_variation->get_type() );

		$validator = facebook_for_woocommerce()->get_product_sync_validator( $orphan_variation );

		$this->assertFalse(
			$validator->passes_product_sync_field_check(),
			'Orphan variation should be treated as non-syncable and must not fatal.'
		);
	}

	/**
	 * Test orphan variation is considered not sync-enabled through Products helper.
	 */
	public function test_orphan_variation_is_not_sync_enabled(): void {
		$this->enable_facebook_sync();

		$variable_product = $this->create_variable_product();
		$variation_ids    = $variable_product->get_children();

		$this->assertNotEmpty( $variation_ids, 'Variable product should have at least one variation.' );

		$variation_id = (int) $variation_ids[0];
		wp_delete_post( $variable_product->get_id(), true );

		$orphan_variation = wc_get_product( $variation_id );
		$this->assertInstanceOf( WC_Product::class, $orphan_variation );

		$this->assertFalse(
			Products::is_sync_enabled_for_product( $orphan_variation ),
			'Orphan variation should not be considered sync-enabled.'
		);
	}
}
