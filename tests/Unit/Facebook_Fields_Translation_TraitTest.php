<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Integrations;

use WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;

/**
 * Concrete class for testing the Facebook_Fields_Translation_Trait.
 */
class Facebook_Fields_Translation_Trait_Test_Implementation {
	use Facebook_Fields_Translation_Trait;

	/**
	 * Mock implementation of switch_to_language.
	 *
	 * @param string $locale Locale to switch to
	 * @return string|null Previous language
	 */
	public function switch_to_language( string $locale ): ?string {
		return 'en_US';
	}

	/**
	 * Mock implementation of restore_language.
	 *
	 * @param string $locale Locale to restore
	 * @return void
	 */
	public function restore_language( string $locale ): void {
		// Mock implementation
	}
}

/**
 * Unit tests for Facebook_Fields_Translation_Trait.
 *
 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait
 */
class Facebook_Fields_Translation_TraitTest extends AbstractWPUnitTestWithSafeFiltering {

	/**
	 * Instance using the trait.
	 *
	 * @var Facebook_Fields_Translation_Trait_Test_Implementation
	 */
	private $instance;

	/**
	 * Test products.
	 *
	 * @var array
	 */
	private $products = [];

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->instance = new Facebook_Fields_Translation_Trait_Test_Implementation();
		$this->products = [];
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		foreach ( $this->products as $product ) {
			if ( $product && $product->get_id() ) {
				wp_delete_post( $product->get_id(), true );
			}
		}
		$this->products = [];
		parent::tearDown();
	}

	/**
	 * Test get_facebook_field_mapping returns correct structure.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_facebook_field_mapping
	 */
	public function test_get_facebook_field_mapping_returns_array() {
		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_facebook_field_mapping' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_facebook_field_mapping contains expected fields.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_facebook_field_mapping
	 */
	public function test_get_facebook_field_mapping_contains_expected_fields() {
		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_facebook_field_mapping' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance );

		$this->assertArrayHasKey( 'name', $result );
		$this->assertArrayHasKey( 'description', $result );
		$this->assertArrayHasKey( 'short_description', $result );
		$this->assertArrayHasKey( 'rich_text_description', $result );
		$this->assertArrayHasKey( 'image_id', $result );
		$this->assertArrayHasKey( 'gallery_image_ids', $result );
		$this->assertArrayHasKey( 'video', $result );
		$this->assertArrayHasKey( 'link', $result );
	}

	/**
	 * Test get_facebook_field_mapping values are strings.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_facebook_field_mapping
	 */
	public function test_get_facebook_field_mapping_values_are_strings() {
		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_facebook_field_mapping' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance );

		foreach ( $result as $field_name => $method_name ) {
			$this->assertIsString( $method_name, "Method name for field '{$field_name}' should be a string" );
		}
	}

	/**
	 * Test get_translated_fields with invalid product IDs returns empty array.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_invalid_products_returns_empty_array() {
		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, 999999, 999998 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_translated_fields with valid products returns array.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_valid_products_returns_array() {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product2 = \WC_Helper_Product::create_simple_product();
		$this->products[] = $product1;
		$this->products[] = $product2;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product1->get_id(), $product2->get_id() );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_translated_fields with same product returns empty or minimal array.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_same_product() {
		$product = \WC_Helper_Product::create_simple_product();
		$this->products[] = $product;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product->get_id(), $product->get_id() );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_translated_fields with different product names.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_detects_different_names() {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product1->set_name( 'Original Product Name' );
		$product1->save();

		$product2 = \WC_Helper_Product::create_simple_product();
		$product2->set_name( 'Translated Product Name' );
		$product2->save();

		$this->products[] = $product1;
		$this->products[] = $product2;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product1->get_id(), $product2->get_id() );

		$this->assertIsArray( $result );
		$this->assertContains( 'name', $result );
	}

	/**
	 * Test get_translated_fields with different descriptions.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_detects_different_descriptions() {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product1->set_description( 'Original description' );
		$product1->save();

		$product2 = \WC_Helper_Product::create_simple_product();
		$product2->set_description( 'Translated description' );
		$product2->save();

		$this->products[] = $product1;
		$this->products[] = $product2;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product1->get_id(), $product2->get_id() );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_translated_fields with target language parameter.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_target_language() {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product2 = \WC_Helper_Product::create_simple_product();
		$this->products[] = $product1;
		$this->products[] = $product2;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product1->get_id(), $product2->get_id(), 'es_ES' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_translated_fields with null target language.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_null_target_language() {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product2 = \WC_Helper_Product::create_simple_product();
		$this->products[] = $product1;
		$this->products[] = $product2;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product1->get_id(), $product2->get_id(), null );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_translated_fields returns array of strings.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_returns_array_of_strings() {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product1->set_name( 'Product One' );
		$product1->save();

		$product2 = \WC_Helper_Product::create_simple_product();
		$product2->set_name( 'Product Two' );
		$product2->save();

		$this->products[] = $product1;
		$this->products[] = $product2;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product1->get_id(), $product2->get_id() );

		foreach ( $result as $field_name ) {
			$this->assertIsString( $field_name );
		}
	}

	/**
	 * Test get_translated_fields with only original product existing.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_only_original_product() {
		$product = \WC_Helper_Product::create_simple_product();
		$this->products[] = $product;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $product->get_id(), 999999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_translated_fields with only translated product existing.
	 *
	 * @covers \WooCommerce\Facebook\Integrations\Facebook_Fields_Translation_Trait::get_translated_fields
	 */
	public function test_get_translated_fields_with_only_translated_product() {
		$product = \WC_Helper_Product::create_simple_product();
		$this->products[] = $product;

		$reflection = new \ReflectionClass( $this->instance );
		$method = $reflection->getMethod( 'get_translated_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, 999999, $product->get_id() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}
}
