<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\Localization\LanguageFeedData;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for LanguageFeedData class.
 *
 * @since 3.6.0
 */
class LanguageFeedDataTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * @var LanguageFeedData
	 */
	private $instance;

	/**
	 * @var array Test products to clean up
	 */
	private $test_products = [];

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->instance = new LanguageFeedData();
		$this->test_products = [];
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up test products
		foreach ( $this->test_products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->test_products = [];

		parent::tearDown();
	}

	/**
	 * Test that the class exists and can be instantiated.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData
	 */
	public function test_class_exists_and_can_be_instantiated() {
		$this->assertTrue( class_exists( LanguageFeedData::class ) );
		$this->assertInstanceOf( LanguageFeedData::class, $this->instance );
	}

	/**
	 * Test get_available_languages returns array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_available_languages
	 */
	public function test_get_available_languages_returns_array() {
		$result = $this->instance->get_available_languages();
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_default_language returns null or string.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_default_language
	 */
	public function test_get_default_language_returns_null_or_string() {
		$result = $this->instance->get_default_language();
		$this->assertTrue( is_null( $result ) || is_string( $result ) );
	}

	/**
	 * Test get_products_from_default_language returns array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_products_from_default_language
	 */
	public function test_get_products_from_default_language_returns_array() {
		$result = $this->instance->get_products_from_default_language( 10, 0 );
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_products_from_default_language with custom limit.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_products_from_default_language
	 */
	public function test_get_products_from_default_language_with_custom_limit() {
		$result = $this->instance->get_products_from_default_language( 5, 0 );
		$this->assertIsArray( $result );
		$this->assertLessThanOrEqual( 5, count( $result ) );
	}

	/**
	 * Test get_products_from_default_language with offset.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_products_from_default_language
	 */
	public function test_get_products_from_default_language_with_offset() {
		$result = $this->instance->get_products_from_default_language( 10, 5 );
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_product_translation_details returns array with expected structure.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_product_translation_details
	 */
	public function test_get_product_translation_details_returns_expected_structure() {
		$result = $this->instance->get_product_translation_details( 123 );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'product_id', $result );
		$this->assertArrayHasKey( 'default_language', $result );
		$this->assertArrayHasKey( 'translations', $result );
		$this->assertArrayHasKey( 'translation_status', $result );
		$this->assertArrayHasKey( 'translated_fields', $result );
	}

	/**
	 * Test get_product_translation_details fallback structure.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_product_translation_details
	 */
	public function test_get_product_translation_details_fallback_structure() {
		$result = $this->instance->get_product_translation_details( 999 );
		
		$this->assertIsArray( $result );
		$this->assertEquals( 999, $result['product_id'] );
		$this->assertIsArray( $result['translations'] );
		$this->assertIsArray( $result['translation_status'] );
		$this->assertIsArray( $result['translated_fields'] );
	}

	/**
	 * Test get_translated_fields_for_language returns array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_translated_fields_for_language
	 */
	public function test_get_translated_fields_for_language_returns_array() {
		$result = $this->instance->get_translated_fields_for_language( 'es_ES', 100 );
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_translated_fields_for_language with different language codes.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_translated_fields_for_language
	 */
	public function test_get_translated_fields_for_language_with_different_codes() {
		$result_es = $this->instance->get_translated_fields_for_language( 'es_ES', 50 );
		$result_fr = $this->instance->get_translated_fields_for_language( 'fr_FR', 50 );
		
		$this->assertIsArray( $result_es );
		$this->assertIsArray( $result_fr );
	}

	/**
	 * Test get_language_csv_data returns expected structure.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_language_csv_data
	 */
	public function test_get_language_csv_data_returns_expected_structure() {
		$result = $this->instance->get_language_csv_data( 'es_ES', 10, 0 );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'columns', $result );
		$this->assertArrayHasKey( 'translated_fields', $result );
	}

	/**
	 * Test get_language_csv_data has required columns.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_language_csv_data
	 */
	public function test_get_language_csv_data_has_required_columns() {
		$result = $this->instance->get_language_csv_data( 'es_ES', 10, 0 );
		
		$this->assertIsArray( $result['columns'] );
		$this->assertContains( 'id', $result['columns'] );
		$this->assertContains( 'override', $result['columns'] );
	}

	/**
	 * Test get_language_csv_data data is array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_language_csv_data
	 */
	public function test_get_language_csv_data_data_is_array() {
		$result = $this->instance->get_language_csv_data( 'es_ES', 10, 0 );
		$this->assertIsArray( $result['data'] );
	}

	/**
	 * Test get_language_csv_data with pagination.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_language_csv_data
	 */
	public function test_get_language_csv_data_with_pagination() {
		$result = $this->instance->get_language_csv_data( 'es_ES', 5, 10 );
		
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'columns', $result );
	}

	/**
	 * Test get_language_csv_data with different language codes.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_language_csv_data
	 */
	public function test_get_language_csv_data_with_different_languages() {
		$result_es = $this->instance->get_language_csv_data( 'es_ES', 10, 0 );
		$result_fr = $this->instance->get_language_csv_data( 'fr_FR', 10, 0 );
		$result_de = $this->instance->get_language_csv_data( 'de_DE', 10, 0 );
		
		$this->assertIsArray( $result_es );
		$this->assertIsArray( $result_fr );
		$this->assertIsArray( $result_de );
	}

	/**
	 * Test get_language_csv_data translated_fields is array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedData::get_language_csv_data
	 */
	public function test_get_language_csv_data_translated_fields_is_array() {
		$result = $this->instance->get_language_csv_data( 'es_ES', 10, 0 );
		$this->assertIsArray( $result['translated_fields'] );
	}
}
