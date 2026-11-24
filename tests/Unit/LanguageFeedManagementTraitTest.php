<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for LanguageFeedManagementTrait.
 *
 * @since 3.6.0
 */
class LanguageFeedManagementTraitTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Concrete test class that uses the trait.
	 *
	 * @var ConcreteLanguageFeedManagementClass
	 */
	private $instance;

	/**
	 * Mock API instance.
	 *
	 * @var \WooCommerce\Facebook\API|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_api;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mock API
		$this->mock_api = $this->createMock( \WooCommerce\Facebook\API::class );

		// Create instance of concrete class using the trait
		$this->instance = new ConcreteLanguageFeedManagementClass();
		$this->instance->set_mock_api( $this->mock_api );

		// Mock the feed secret
		$this->mock_set_option( 'wc_facebook_feed_url_secret', 'test_secret_123' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test retrieve_or_create_language_feed_id returns existing feed ID.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::retrieve_or_create_language_feed_id
	 */
	public function test_retrieve_or_create_language_feed_id_returns_existing_feed() {
		$language_code = 'es_ES';
		$expected_feed_id = '123456789';

		// Mock catalog ID
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_123' );

		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );

		// Mock facebook_for_woocommerce() function
		if ( ! function_exists( 'WooCommerce\Facebook\Feed\Localization\facebook_for_woocommerce' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Feed\Localization;
				function facebook_for_woocommerce() {
					return $GLOBALS["test_plugin_mock"];
				}
			' );
		}
		$GLOBALS['test_plugin_mock'] = $mock_plugin;

		// Mock API response with existing feed
		$feed_nodes = [
			[ 'id' => '111111111' ],
			[ 'id' => $expected_feed_id ],
			[ 'id' => '333333333' ],
		];

		$this->mock_api->method( 'read_feeds' )
			->with( 'catalog_123' )
			->willReturn( (object) [ 'data' => $feed_nodes ] );

		// Mock feed metadata for the matching feed
		$this->mock_api->method( 'read_feed' )
			->willReturnCallback( function( $feed_id ) use ( $expected_feed_id ) {
				if ( $feed_id === $expected_feed_id ) {
					return [ 'name' => 'WooCommerce Language Override Feed (ES_XX)' ];
				}
				return [ 'name' => 'Other Feed' ];
			} );

		$result = $this->instance->retrieve_or_create_language_feed_id( $language_code );

		$this->assertEquals( $expected_feed_id, $result );

		// Verify feed ID was stored
		$this->assertOptionUpdated( 'wc_facebook_language_feed_ids', [ $language_code => $expected_feed_id ] );
	}

	/**
	 * Test retrieve_or_create_language_feed_id creates new feed when none exists.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::retrieve_or_create_language_feed_id
	 */
	public function test_retrieve_or_create_language_feed_id_creates_new_feed() {
		$language_code = 'fr_FR';
		$expected_feed_id = '987654321';

		// Mock catalog ID
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_456' );

		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );

		if ( ! function_exists( 'WooCommerce\Facebook\Feed\Localization\facebook_for_woocommerce' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Feed\Localization;
				function facebook_for_woocommerce() {
					return $GLOBALS["test_plugin_mock"];
				}
			' );
		}
		$GLOBALS['test_plugin_mock'] = $mock_plugin;

		// Mock API response with no matching feeds
		$feed_nodes = [
			[ 'id' => '111111111' ],
		];

		$this->mock_api->method( 'read_feeds' )
			->with( 'catalog_456' )
			->willReturn( (object) [ 'data' => $feed_nodes ] );

		$this->mock_api->method( 'read_feed' )
			->willReturn( [ 'name' => 'Other Feed' ] );

		// Mock create_feed to return new feed ID
		$this->mock_api->method( 'create_feed' )
			->willReturn( [ 'id' => $expected_feed_id ] );

		$result = $this->instance->retrieve_or_create_language_feed_id( $language_code );

		$this->assertEquals( $expected_feed_id, $result );

		// Verify feed ID was stored
		$this->assertOptionUpdated( 'wc_facebook_language_feed_ids', [ $language_code => $expected_feed_id ] );
	}

	/**
	 * Test retrieve_or_create_language_feed_id returns empty string on failure.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::retrieve_or_create_language_feed_id
	 */
	public function test_retrieve_or_create_language_feed_id_returns_empty_on_failure() {
		$language_code = 'de_DE';

		// Mock catalog ID as empty (failure case)
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( '' );

		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );

		if ( ! function_exists( 'WooCommerce\Facebook\Feed\Localization\facebook_for_woocommerce' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Feed\Localization;
				function facebook_for_woocommerce() {
					return $GLOBALS["test_plugin_mock"];
				}
			' );
		}
		$GLOBALS['test_plugin_mock'] = $mock_plugin;

		$result = $this->instance->retrieve_or_create_language_feed_id( $language_code );

		$this->assertEquals( '', $result );
	}

	/**
	 * Test retrieve_or_create_language_feed_id handles API exception.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::retrieve_or_create_language_feed_id
	 */
	public function test_retrieve_or_create_language_feed_id_handles_api_exception() {
		$language_code = 'it_IT';

		// Mock catalog ID
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_789' );

		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );

		if ( ! function_exists( 'WooCommerce\Facebook\Feed\Localization\facebook_for_woocommerce' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Feed\Localization;
				function facebook_for_woocommerce() {
					return $GLOBALS["test_plugin_mock"];
				}
			' );
		}
		$GLOBALS['test_plugin_mock'] = $mock_plugin;

		// Mock API to throw exception
		$this->mock_api->method( 'read_feeds' )
			->willThrowException( new \Exception( 'API Error' ) );

		$result = $this->instance->retrieve_or_create_language_feed_id( $language_code );

		$this->assertEquals( '', $result );
	}

	/**
	 * Test generate_language_feed_filename with default parameters.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_filename
	 */
	public function test_generate_language_feed_filename_default() {
		$language_code = 'es_ES';

		$filename = ConcreteLanguageFeedManagementClass::generate_language_feed_filename( $language_code );

		$this->assertIsString( $filename );
		$this->assertStringContainsString( 'facebook_language_feed_', $filename );
		$this->assertStringContainsString( 'es_XX', $filename );
		$this->assertStringContainsString( '.csv', $filename );
		$this->assertStringNotContainsString( 'temp_', $filename );
	}

	/**
	 * Test generate_language_feed_filename for Facebook API.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_filename
	 */
	public function test_generate_language_feed_filename_for_facebook_api() {
		$language_code = 'fr_FR';

		$filename = ConcreteLanguageFeedManagementClass::generate_language_feed_filename( $language_code, true );

		$this->assertIsString( $filename );
		$this->assertStringContainsString( 'facebook_language_feed_', $filename );
		$this->assertStringContainsString( 'fr_XX', $filename );
		$this->assertStringContainsString( '.csv', $filename );
		$this->assertStringNotContainsString( 'temp_', $filename );
	}

	/**
	 * Test generate_language_feed_filename for temporary file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_filename
	 */
	public function test_generate_language_feed_filename_temp_file() {
		$language_code = 'de_DE';

		$filename = ConcreteLanguageFeedManagementClass::generate_language_feed_filename( $language_code, false, true );

		$this->assertIsString( $filename );
		$this->assertStringContainsString( 'facebook_language_feed_temp_', $filename );
		$this->assertStringContainsString( 'de_DE', $filename );
		$this->assertStringContainsString( '.csv', $filename );
	}

	/**
	 * Test generate_language_feed_filename with different languages.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_filename
	 */
	public function test_generate_language_feed_filename_with_different_languages() {
		$test_cases = [
			'es_ES' => 'es_XX',
			'fr_FR' => 'fr_XX',
			'de_DE' => 'de_DE',
			'it_IT' => 'it_IT',
			'pt_BR' => 'pt_XX',
			'zh_CN' => 'zh_CN',
			'zh_TW' => 'zh_TW',
			'ja_JP' => 'ja_XX',
		];

		foreach ( $test_cases as $language_code => $expected_fb_code ) {
			$filename = ConcreteLanguageFeedManagementClass::generate_language_feed_filename( $language_code );

			$this->assertStringContainsString( $expected_fb_code, $filename );
			$this->assertStringContainsString( '.csv', $filename );
		}
	}

	/**
	 * Test generate_language_feed_filename consistency.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_filename
	 */
	public function test_generate_language_feed_filename_consistency() {
		$language_code = 'es_ES';

		$filename1 = ConcreteLanguageFeedManagementClass::generate_language_feed_filename( $language_code );
		$filename2 = ConcreteLanguageFeedManagementClass::generate_language_feed_filename( $language_code );

		// Should generate the same filename for the same language
		$this->assertEquals( $filename1, $filename2 );
	}

	/**
	 * Test generate_language_feed_name.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_name
	 */
	public function test_generate_language_feed_name() {
		$language_code = 'es_ES';

		$name = ConcreteLanguageFeedManagementClass::generate_language_feed_name( $language_code );

		$this->assertEquals( 'WooCommerce Language Override Feed (ES_XX)', $name );
	}

	/**
	 * Test generate_language_feed_name with different languages.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_name
	 */
	public function test_generate_language_feed_name_with_different_languages() {
		$test_cases = [
			'es_ES' => 'WooCommerce Language Override Feed (ES_XX)',
			'fr_FR' => 'WooCommerce Language Override Feed (FR_XX)',
			'de_DE' => 'WooCommerce Language Override Feed (DE_DE)',
			'it_IT' => 'WooCommerce Language Override Feed (IT_IT)',
			'pt_BR' => 'WooCommerce Language Override Feed (PT_XX)',
			'zh_CN' => 'WooCommerce Language Override Feed (ZH_CN)',
			'zh_TW' => 'WooCommerce Language Override Feed (ZH_TW)',
			'ja_JP' => 'WooCommerce Language Override Feed (JA_XX)',
		];

		foreach ( $test_cases as $language_code => $expected_name ) {
			$name = ConcreteLanguageFeedManagementClass::generate_language_feed_name( $language_code );
			$this->assertEquals( $expected_name, $name );
		}
	}

	/**
	 * Test generate_language_feed_name format.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_name
	 */
	public function test_generate_language_feed_name_format() {
		$language_code = 'fr_FR';

		$name = ConcreteLanguageFeedManagementClass::generate_language_feed_name( $language_code );

		$this->assertStringStartsWith( 'WooCommerce Language Override Feed (', $name );
		$this->assertStringEndsWith( ')', $name );
		$this->assertStringContainsString( 'FR_XX', $name );
	}

	/**
	 * Test store_language_feed_id stores feed ID correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::store_language_feed_id
	 */
	public function test_store_language_feed_id() {
		$language_code = 'es_ES';
		$feed_id = '123456789';

		$this->instance->test_store_language_feed_id( $language_code, $feed_id );

		$stored_feeds = $this->mock_get_option( 'wc_facebook_language_feed_ids', [] );

		$this->assertIsArray( $stored_feeds );
		$this->assertArrayHasKey( $language_code, $stored_feeds );
		$this->assertEquals( $feed_id, $stored_feeds[ $language_code ] );
	}

	/**
	 * Test store_language_feed_id with multiple languages.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::store_language_feed_id
	 */
	public function test_store_language_feed_id_multiple_languages() {
		$feeds = [
			'es_ES' => '111111111',
			'fr_FR' => '222222222',
			'de_DE' => '333333333',
		];

		foreach ( $feeds as $language_code => $feed_id ) {
			$this->instance->test_store_language_feed_id( $language_code, $feed_id );
		}

		$stored_feeds = $this->mock_get_option( 'wc_facebook_language_feed_ids', [] );

		$this->assertIsArray( $stored_feeds );
		$this->assertCount( 3, $stored_feeds );

		foreach ( $feeds as $language_code => $feed_id ) {
			$this->assertArrayHasKey( $language_code, $stored_feeds );
			$this->assertEquals( $feed_id, $stored_feeds[ $language_code ] );
		}
	}

	/**
	 * Test store_language_feed_id updates existing feed ID.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::store_language_feed_id
	 */
	public function test_store_language_feed_id_updates_existing() {
		$language_code = 'es_ES';
		$old_feed_id = '111111111';
		$new_feed_id = '999999999';

		// Store initial feed ID
		$this->instance->test_store_language_feed_id( $language_code, $old_feed_id );

		$stored_feeds = $this->mock_get_option( 'wc_facebook_language_feed_ids', [] );
		$this->assertEquals( $old_feed_id, $stored_feeds[ $language_code ] );

		// Update with new feed ID
		$this->instance->test_store_language_feed_id( $language_code, $new_feed_id );

		$stored_feeds = $this->mock_get_option( 'wc_facebook_language_feed_ids', [] );
		$this->assertEquals( $new_feed_id, $stored_feeds[ $language_code ] );
	}

	/**
	 * Test get_feed_secret returns the feed secret.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::get_feed_secret
	 */
	public function test_get_feed_secret() {
		// Mock the Products\Feed::get_feed_secret() method
		if ( ! class_exists( 'WooCommerce\Facebook\Products\Feed' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Products;
				class Feed {
					public static function get_feed_secret() {
						return "test_secret_123";
					}
				}
			' );
		}

		$secret = $this->instance->test_get_feed_secret();

		$this->assertIsString( $secret );
		$this->assertEquals( 'test_secret_123', $secret );
	}

	/**
	 * Test get_api returns API instance.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::get_api
	 */
	public function test_get_api_returns_api_instance() {
		$api = $this->instance->test_get_api();

		$this->assertInstanceOf( \WooCommerce\Facebook\API::class, $api );
	}

	/**
	 * Test get_api caches API instance.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::get_api
	 */
	public function test_get_api_caches_instance() {
		$api1 = $this->instance->test_get_api();
		$api2 = $this->instance->test_get_api();

		// Should return the same instance
		$this->assertSame( $api1, $api2 );
	}
}

/**
 * Concrete class for testing the LanguageFeedManagementTrait.
 *
 * This class uses the trait and exposes private/protected methods for testing.
 */
class ConcreteLanguageFeedManagementClass {
	use LanguageFeedManagementTrait;

	/**
	 * Mock API instance for testing.
	 *
	 * @var \WooCommerce\Facebook\API
	 */
	private $mock_api_instance;

	/**
	 * Set mock API instance.
	 *
	 * @param \WooCommerce\Facebook\API $api Mock API instance.
	 */
	public function set_mock_api( $api ) {
		$this->mock_api_instance = $api;
		$this->api = $api;
	}

	/**
	 * Expose get_api for testing.
	 *
	 * @return \WooCommerce\Facebook\API
	 */
	public function test_get_api() {
		return $this->get_api();
	}

	/**
	 * Expose store_language_feed_id for testing.
	 *
	 * @param string $language_code Language code.
	 * @param string $feed_id Feed ID.
	 */
	public function test_store_language_feed_id( string $language_code, string $feed_id ): void {
		$this->store_language_feed_id( $language_code, $feed_id );
	}

	/**
	 * Expose get_feed_secret for testing.
	 *
	 * @return string
	 */
	public function test_get_feed_secret(): string {
		return $this->get_feed_secret();
	}
}
