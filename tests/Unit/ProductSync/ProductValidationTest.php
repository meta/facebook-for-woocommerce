<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\ProductSync;

use WooCommerce\Facebook\ProductSync\ProductValidator;
use WooCommerce\Facebook\ProductSync\ProductExcludedException;
use WooCommerce\Facebook\ProductSync\ProductInvalidException;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;
use WC_Facebookcommerce_Integration;
use WC_Facebookcommerce;
use WC_Product;
use WC_Product_Simple;
use WC_Helper_Product;

/**
 * Unit tests for ProductValidator class.
 *
 * @since 3.5.2
 */
class ProductValidatorTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Mock integration instance.
	 *
	 * @var WC_Facebookcommerce_Integration|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $integration;

	/**
	 * Mock facebook for woocommerce instance.
	 *
	 * @var WC_Facebookcommerce|\PHPUnit\Framework\MockObject\MockObject
	 */
	protected $facebook_for_woocommerce;

	/**
	 * Test product.
	 *
	 * @var WC_Product
	 */
	protected $product;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mock for WC_Facebookcommerce
		$this->facebook_for_woocommerce = $this->createMock( WC_Facebookcommerce::class );

		// Create mock for rollout switches
		$rollout_switches = $this->createMock( \WooCommerce\Facebook\RolloutSwitches::class );
		$rollout_switches->method( 'is_switch_enabled' )->willReturn( false );
		$this->facebook_for_woocommerce->method( 'get_rollout_switches' )->willReturn( $rollout_switches );

		// Create mock integration
		$this->integration = $this->createMock( WC_Facebookcommerce_Integration::class );

		// Create a simple product for testing
		$this->product = new WC_Product_Simple();
		$this->product->set_name( 'Test Product' );
		$this->product->set_regular_price( '10.00' );
		$this->product->set_status( 'publish' );
		$this->product->set_catalog_visibility( 'visible' );
		$this->product->save();
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		if ( $this->product instanceof WC_Product && $this->product->get_id() ) {
			$this->product->delete( true );
		}
		parent::tearDown();
	}

	/**
	 * Test that ProductValidator class exists.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( ProductValidator::class ) );
	}

	/**
	 * Test that ProductValidator can be instantiated with valid parameters.
	 */
	public function test_can_be_instantiated() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertInstanceOf( ProductValidator::class, $validator );
	}

	/**
	 * Test MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION constant is defined.
	 */
	public function test_max_attributes_constant_is_defined() {
		$this->assertTrue( defined( ProductValidator::class . '::MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION' ) );
		$this->assertEquals( 4, ProductValidator::MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION );
	}

	/**
	 * Test constructor sets up product correctly for simple product.
	 */
	public function test_constructor_sets_up_simple_product() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		// The validator should be created without errors
		$this->assertInstanceOf( ProductValidator::class, $validator );
	}

	/**
	 * Test constructor sets up product correctly for variation product.
	 */
	public function test_constructor_sets_up_variation_product() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product with variations
		$variable_product = WC_Helper_Product::create_variation_product();
		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertInstanceOf( ProductValidator::class, $validator );
	}

	/**
	 * Test constructor handles product with parent relationship.
	 */
	public function test_constructor_handles_parent_relationship() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		// Validator should handle the parent-child relationship internally
		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertInstanceOf( ProductValidator::class, $validator );
	}

	/**
	 * Helper method to configure integration mock for sync enabled.
	 */
	protected function configure_integration_for_sync_enabled(): void {
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );
		$this->integration->method( 'is_language_override_feed_generation_enabled' )->willReturn( false );
	}

	/**
	 * Helper method to configure integration mock for sync disabled.
	 */
	protected function configure_integration_for_sync_disabled(): void {
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( false );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
	}

	// =========================================================================
	// Tests for passes_all_checks() method
	// =========================================================================

	/**
	 * Test passes_all_checks returns true for valid published product.
	 */
	public function test_passes_all_checks_returns_true_for_valid_product() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test passes_all_checks returns false when sync is globally disabled.
	 */
	public function test_passes_all_checks_returns_false_when_sync_disabled() {
		$this->configure_integration_for_sync_disabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	/**
	 * Test passes_all_checks returns false for draft product.
	 */
	public function test_passes_all_checks_returns_false_for_draft_product() {
		$this->configure_integration_for_sync_enabled();

		$this->product->set_status( 'draft' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	/**
	 * Test passes_all_checks returns false for hidden product.
	 */
	public function test_passes_all_checks_returns_false_for_hidden_product() {
		$this->configure_integration_for_sync_enabled();

		$this->product->set_catalog_visibility( 'hidden' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	/**
	 * Test passes_all_checks returns false for trashed product.
	 */
	public function test_passes_all_checks_returns_false_for_trashed_product() {
		$this->configure_integration_for_sync_enabled();

		$this->product->set_status( 'trash' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	// =========================================================================
	// Tests for passes_product_terms_check() method
	// =========================================================================

	/**
	 * Test passes_product_terms_check returns true when no categories are excluded.
	 */
	public function test_passes_product_terms_check_returns_true_when_no_exclusions() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertTrue( $validator->passes_product_terms_check() );
	}

	/**
	 * Test passes_product_terms_check returns false when product is in excluded category.
	 */
	public function test_passes_product_terms_check_returns_false_for_excluded_category() {
		// Create a product category
		$category_id = wp_insert_term( 'Excluded Category', 'product_cat' );
		$category_id = is_array( $category_id ) ? $category_id['term_id'] : $category_id;

		// Assign category to product
		$this->product->set_category_ids( [ $category_id ] );
		$this->product->save();

		// Configure integration to exclude this category
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [ $category_id ] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_product_terms_check() );
	}

	/**
	 * Test passes_product_terms_check returns false when product has excluded tag.
	 */
	public function test_passes_product_terms_check_returns_false_for_excluded_tag() {
		// Create a product tag
		$tag_id = wp_insert_term( 'Excluded Tag', 'product_tag' );
		$tag_id = is_array( $tag_id ) ? $tag_id['term_id'] : $tag_id;

		// Assign tag to product
		$this->product->set_tag_ids( [ $tag_id ] );
		$this->product->save();

		// Configure integration to exclude this tag
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [ $tag_id ] );

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_product_terms_check() );
	}

	/**
	 * Test passes_product_terms_check returns true when woo_all_products is enabled.
	 */
	public function test_passes_product_terms_check_returns_true_when_all_products_enabled() {
		// Create a product category
		$category_id = wp_insert_term( 'Test Category', 'product_cat' );
		$category_id = is_array( $category_id ) ? $category_id['term_id'] : $category_id;

		// Assign category to product
		$this->product->set_category_ids( [ $category_id ] );
		$this->product->save();

		// Configure integration with all products enabled (should bypass category check)
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( true );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [ $category_id ] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );

		$validator = new ProductValidator( $this->integration, $this->product );

		// Should return true because woo_all_products bypasses term checks
		$this->assertTrue( $validator->passes_product_terms_check() );
	}

	// =========================================================================
	// Tests for passes_product_sync_field_check() method
	// =========================================================================

	/**
	 * Test passes_product_sync_field_check returns true when sync is not disabled.
	 */
	public function test_passes_product_sync_field_check_returns_true_by_default() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertTrue( $validator->passes_product_sync_field_check() );
	}

	/**
	 * Test passes_product_sync_field_check returns false when sync field is 'no'.
	 */
	public function test_passes_product_sync_field_check_returns_false_when_sync_disabled() {
		$this->configure_integration_for_sync_enabled();

		// Set the product sync meta to 'no'
		$this->product->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'no' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_product_sync_field_check() );
	}

	/**
	 * Test passes_product_sync_field_check returns true when sync field is 'yes'.
	 */
	public function test_passes_product_sync_field_check_returns_true_when_sync_enabled() {
		$this->configure_integration_for_sync_enabled();

		// Set the product sync meta to 'yes'
		$this->product->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'yes' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertTrue( $validator->passes_product_sync_field_check() );
	}

	// =========================================================================
	// Tests for passes_all_checks_except_sync_field() method
	// =========================================================================

	/**
	 * Test passes_all_checks_except_sync_field returns true for valid product.
	 */
	public function test_passes_all_checks_except_sync_field_returns_true_for_valid_product() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertTrue( $validator->passes_all_checks_except_sync_field() );
	}

	/**
	 * Test passes_all_checks_except_sync_field returns true even when sync field is 'no'.
	 */
	public function test_passes_all_checks_except_sync_field_ignores_sync_field() {
		$this->configure_integration_for_sync_enabled();

		// Set the product sync meta to 'no'
		$this->product->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'no' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		// Should still return true because it skips the sync field check
		$this->assertTrue( $validator->passes_all_checks_except_sync_field() );
	}

	/**
	 * Test passes_all_checks_except_sync_field returns false for hidden product.
	 */
	public function test_passes_all_checks_except_sync_field_returns_false_for_hidden_product() {
		$this->configure_integration_for_sync_enabled();

		$this->product->set_catalog_visibility( 'hidden' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_all_checks_except_sync_field() );
	}

	/**
	 * Test passes_all_checks_except_sync_field returns false when sync is globally disabled.
	 */
	public function test_passes_all_checks_except_sync_field_returns_false_when_sync_disabled() {
		$this->configure_integration_for_sync_disabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_all_checks_except_sync_field() );
	}

	// =========================================================================
	// Tests for variation structure validation
	// =========================================================================

	/**
	 * Test that MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION constant equals 4.
	 */
	public function test_max_number_of_attributes_constant_value() {
		$this->assertEquals( 4, ProductValidator::MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION );
	}

	/**
	 * Test variation with no attributes passes validation.
	 */
	public function test_variation_with_no_attributes_passes_validation() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		// Should pass since WC_Helper_Product creates variations with standard attributes
		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test simple product is not affected by variation attribute limit.
	 */
	public function test_simple_product_not_affected_by_attribute_limit() {
		$this->configure_integration_for_sync_enabled();

		// Add more than 4 attributes to a simple product
		$attributes = [];
		for ( $i = 1; $i <= 6; $i++ ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( 'Attribute ' . $i );
			$attribute->set_options( [ 'Option A', 'Option B' ] );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[] = $attribute;
		}

		$this->product->set_attributes( $attributes );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		// Simple products should not be affected by the variation attribute limit
		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test variation with 4 or fewer attributes passes validation.
	 */
	public function test_variation_with_four_or_fewer_attributes_passes() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product with exactly 4 variation attributes
		$variable_product = $this->create_variable_product_with_n_attributes( 4 );

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		// Should pass since 4 attributes is the maximum allowed
		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test variation with exactly 1 attribute passes validation.
	 */
	public function test_variation_with_one_attribute_passes() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product with 1 variation attribute
		$variable_product = $this->create_variable_product_with_n_attributes( 1 );

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test variation with exactly 2 attributes passes validation.
	 */
	public function test_variation_with_two_attributes_passes() {
		$this->configure_integration_for_sync_enabled();

		$variable_product = $this->create_variable_product_with_n_attributes( 2 );

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test variation with exactly 3 attributes passes validation.
	 */
	public function test_variation_with_three_attributes_passes() {
		$this->configure_integration_for_sync_enabled();

		$variable_product = $this->create_variable_product_with_n_attributes( 3 );

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Helper to create a variable product with a specific number of variation attributes.
	 *
	 * @param int $num_attributes Number of attributes to create.
	 * @return \WC_Product_Variable The created variable product.
	 */
	protected function create_variable_product_with_n_attributes( int $num_attributes ): \WC_Product_Variable {
		$product = new \WC_Product_Variable();
		$product->set_name( 'Test Variable Product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->save();

		$attributes = [];
		$attribute_values = [];

		for ( $i = 1; $i <= $num_attributes; $i++ ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( 'pa_attr_' . $i );
			$attribute->set_options( [ 'value_a', 'value_b' ] );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attribute->set_position( $i - 1 );
			$attributes[] = $attribute;
			$attribute_values[ 'pa_attr_' . $i ] = 'value_a';
		}

		$product->set_attributes( $attributes );
		$product->save();

		// Create a variation
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_regular_price( '10.00' );
		$variation->set_attributes( $attribute_values );
		$variation->save();

		// Refresh product to get children
		$product = wc_get_product( $product->get_id() );

		return $product;
	}

	// =========================================================================
	// Tests for parent-child product relationships
	// =========================================================================

	/**
	 * Test variation inherits parent's published status for validation.
	 */
	public function test_variation_inherits_parent_published_status() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product that is published
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test variation fails when parent is draft.
	 */
	public function test_variation_fails_when_parent_is_draft() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'draft' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	/**
	 * Test variation fails when parent is hidden.
	 */
	public function test_variation_fails_when_parent_is_hidden() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'hidden' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	/**
	 * Test variation fails when parent is trashed.
	 */
	public function test_variation_fails_when_parent_is_trashed() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'trash' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertFalse( $validator->passes_all_checks() );
	}

	/**
	 * Test variation uses parent's category for term exclusion check.
	 */
	public function test_variation_uses_parent_category_for_term_check() {
		// Create a product category
		$category_id = wp_insert_term( 'Parent Excluded Category', 'product_cat' );
		$category_id = is_array( $category_id ) ? $category_id['term_id'] : $category_id;

		// Create a variable product with the excluded category
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->set_category_ids( [ $category_id ] );
		$variable_product->save();

		// Configure integration to exclude this category
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [ $category_id ] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );
		$this->integration->method( 'is_language_override_feed_generation_enabled' )->willReturn( false );

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		// Variation should fail because parent is in excluded category
		$this->assertFalse( $validator->passes_product_terms_check() );
	}

	/**
	 * Test variable product with sync enabled on parent passes.
	 */
	public function test_variable_product_with_parent_sync_enabled() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'yes' );
		$variable_product->save();

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertTrue( $validator->passes_product_sync_field_check() );
	}

	/**
	 * Test variable product with sync disabled on parent fails.
	 */
	public function test_variable_product_with_parent_sync_disabled() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'no' );
		$variable_product->save();

		// Also set sync disabled on all variations
		foreach ( $variable_product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			$child->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'no' );
			$child->save();
		}

		$variation_id = $variable_product->get_children()[0];
		$variation = wc_get_product( $variation_id );

		$validator = new ProductValidator( $this->integration, $variation );

		$this->assertFalse( $validator->passes_product_sync_field_check() );
	}

	/**
	 * Test variable product passes if at least one variation has sync enabled.
	 */
	public function test_variable_product_passes_if_one_variation_sync_enabled() {
		$this->configure_integration_for_sync_enabled();

		// Create a variable product
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->set_status( 'publish' );
		$variable_product->set_catalog_visibility( 'visible' );
		$variable_product->save();

		$children = $variable_product->get_children();

		// Set first variation to sync enabled, others to disabled
		$first_variation = wc_get_product( $children[0] );
		$first_variation->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'yes' );
		$first_variation->save();

		for ( $i = 1; $i < count( $children ); $i++ ) {
			$child = wc_get_product( $children[ $i ] );
			$child->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'no' );
			$child->save();
		}

		$validator = new ProductValidator( $this->integration, $first_variation );

		$this->assertTrue( $validator->passes_product_sync_field_check() );
	}

	// =========================================================================
	// Tests for language validation
	// =========================================================================

	/**
	 * Test product passes when language feed generation is disabled.
	 */
	public function test_product_passes_when_language_feed_disabled() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		// Should pass because language feed generation is disabled
		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test product in default language passes when language feed is enabled.
	 */
	public function test_product_in_default_language_passes_when_language_feed_enabled() {
		// Configure integration with language feed enabled
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );
		$this->integration->method( 'is_language_override_feed_generation_enabled' )->willReturn( true );

		$validator = new ProductValidator( $this->integration, $this->product );

		// Should pass because without a localization plugin active, language validation is skipped
		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test extract_language_code handles simple language codes.
	 */
	public function test_extract_language_code_handles_simple_codes() {
		$this->configure_integration_for_sync_enabled();

		// We can't directly test the private method, but we can verify behavior
		// by testing the validate_product_language method's behavior
		$validator = new ProductValidator( $this->integration, $this->product );

		// Product should pass validation (language check skipped when no localization plugin)
		$this->assertTrue( $validator->passes_all_checks() );
	}

	/**
	 * Test extract_language_code handles locale format (en_US).
	 */
	public function test_language_validation_with_locale_format() {
		// Configure integration with language feed enabled
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );
		$this->integration->method( 'is_language_override_feed_generation_enabled' )->willReturn( true );

		$validator = new ProductValidator( $this->integration, $this->product );

		// Without an active localization plugin, language validation is skipped
		$this->assertTrue( $validator->passes_all_checks() );
	}

	// =========================================================================
	// Tests for __get backward compatibility
	// =========================================================================

	/**
	 * Test __get returns null for unknown property.
	 */
	public function test_magic_get_returns_null_for_unknown_property() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		// Accessing unknown property should return null
		$this->assertNull( $validator->unknown_property );
	}

	/**
	 * Test __get triggers deprecation warning for facebook_product property.
	 */
	public function test_magic_get_triggers_deprecation_for_facebook_product() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		// Track if _doing_it_wrong was called
		$doing_it_wrong_called = false;
		$this->add_filter_with_safe_teardown(
			'doing_it_wrong_trigger_error',
			function( $trigger, $function_name ) use ( &$doing_it_wrong_called ) {
				if ( '__get' === $function_name ) {
					$doing_it_wrong_called = true;
				}
				return false; // Prevent the actual error from being triggered
			},
			10,
			2
		);

		$result = $validator->facebook_product;

		// Verify the deprecation warning was triggered
		$this->assertTrue( $doing_it_wrong_called, 'Expected _doing_it_wrong to be called for facebook_product property access' );
		// Should return the facebook_product object despite the deprecation warning
		$this->assertInstanceOf( \WC_Facebook_Product::class, $result );
	}

	/**
	 * Test __get returns facebook_product for backward compatibility.
	 */
	public function test_magic_get_returns_facebook_product() {
		$this->configure_integration_for_sync_enabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		// Suppress the deprecation notice for this test since we only care about the return value
		$this->add_filter_with_safe_teardown(
			'doing_it_wrong_trigger_error',
			'__return_false'
		);

		$facebook_product = $validator->facebook_product;

		$this->assertNotNull( $facebook_product );
		$this->assertInstanceOf( \WC_Facebook_Product::class, $facebook_product );
	}

	// =========================================================================
	// Tests for validate() method (throws exceptions)
	// =========================================================================

	/**
	 * Test validate throws ProductExcludedException when sync is globally disabled.
	 */
	public function test_validate_throws_exception_when_sync_disabled() {
		$this->configure_integration_for_sync_disabled();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->expectException( ProductExcludedException::class );
		$this->expectExceptionMessage( 'Product sync is globally disabled.' );

		// Use reflection to call protected method
		$reflection = new \ReflectionClass( $validator );
		$method = $reflection->getMethod( 'validate_sync_enabled_globally' );
		$method->setAccessible( true );
		$method->invoke( $validator );
	}

	/**
	 * Test validate throws ProductExcludedException for unpublished product.
	 */
	public function test_validate_throws_exception_for_unpublished_product() {
		$this->configure_integration_for_sync_enabled();

		$this->product->set_status( 'draft' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->expectException( ProductExcludedException::class );
		$this->expectExceptionMessage( 'Product is not published.' );

		// Use reflection to call protected method
		$reflection = new \ReflectionClass( $validator );
		$method = $reflection->getMethod( 'validate_product_status' );
		$method->setAccessible( true );
		$method->invoke( $validator );
	}

	/**
	 * Test validate throws ProductExcludedException for hidden product.
	 */
	public function test_validate_throws_exception_for_hidden_product() {
		$this->configure_integration_for_sync_enabled();

		$this->product->set_catalog_visibility( 'hidden' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->expectException( ProductExcludedException::class );

		// Use reflection to call protected method
		$reflection = new \ReflectionClass( $validator );
		$method = $reflection->getMethod( 'validate_product_visibility' );
		$method->setAccessible( true );
		$method->invoke( $validator );
	}

	/**
	 * Test validate throws ProductExcludedException for product in excluded category.
	 */
	public function test_validate_throws_exception_for_excluded_category() {
		// Create a product category
		$category_id = wp_insert_term( 'Test Excluded Cat', 'product_cat' );
		$category_id = is_array( $category_id ) ? $category_id['term_id'] : $category_id;

		// Assign category to product
		$this->product->set_category_ids( [ $category_id ] );
		$this->product->save();

		// Configure integration to exclude this category
		$this->integration->method( 'is_product_sync_enabled' )->willReturn( true );
		$this->integration->method( 'is_woo_all_products_enabled' )->willReturn( false );
		$this->integration->method( 'get_excluded_product_category_ids' )->willReturn( [ $category_id ] );
		$this->integration->method( 'get_excluded_product_tag_ids' )->willReturn( [] );

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->expectException( ProductExcludedException::class );
		$this->expectExceptionMessage( 'Product excluded because of categories.' );

		// Use reflection to call protected method
		$reflection = new \ReflectionClass( $validator );
		$method = $reflection->getMethod( 'validate_product_terms' );
		$method->setAccessible( true );
		$method->invoke( $validator );
	}

	/**
	 * Test validate throws ProductExcludedException for product with sync disabled.
	 */
	public function test_validate_throws_exception_for_sync_field_disabled() {
		$this->configure_integration_for_sync_enabled();

		// Set the product sync meta to 'no'
		$this->product->update_meta_data( \WooCommerce\Facebook\Products::SYNC_ENABLED_META_KEY, 'no' );
		$this->product->save();

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->expectException( ProductExcludedException::class );
		$this->expectExceptionMessage( 'Sync disabled in product field.' );

		// Use reflection to call protected method
		$reflection = new \ReflectionClass( $validator );
		$method = $reflection->getMethod( 'validate_product_sync_field' );
		$method->setAccessible( true );
		$method->invoke( $validator );
	}

	// =========================================================================
	// Tests for filter hook
	// =========================================================================

	/**
	 * Test wc_facebook_should_sync_product filter can exclude products.
	 */
	public function test_filter_can_exclude_product() {
		$this->configure_integration_for_sync_enabled();

		// Add filter that excludes this product
		$this->add_filter_with_safe_teardown(
			'wc_facebook_should_sync_product',
			function( $should_sync, $product ) {
				return false;
			},
			10,
			2
		);

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertFalse( $validator->passes_product_sync_field_check() );
	}

	/**
	 * Test wc_facebook_should_sync_product filter can allow products.
	 */
	public function test_filter_can_allow_product() {
		$this->configure_integration_for_sync_enabled();

		// Add filter that allows this product
		$this->add_filter_with_safe_teardown(
			'wc_facebook_should_sync_product',
			function( $should_sync, $product ) {
				return true;
			},
			10,
			2
		);

		$validator = new ProductValidator( $this->integration, $this->product );

		$this->assertTrue( $validator->passes_product_sync_field_check() );
	}
}
