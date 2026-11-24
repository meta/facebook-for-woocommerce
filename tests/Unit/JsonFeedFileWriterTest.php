<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\JsonFeedFileWriter;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for JsonFeedFileWriter class.
 *
 * @since 3.5.0
 */
class JsonFeedFileWriterTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Instance of JsonFeedFileWriter for testing.
	 *
	 * @var JsonFeedFileWriter
	 */
	private $instance;

	/**
	 * Test feed name.
	 *
	 * @var string
	 */
	private $feed_name = 'test_feed';

	/**
	 * Test header row.
	 *
	 * @var string
	 */
	private $header_row = 'id,title,description';

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock the facebook_for_woocommerce function and feed_manager
		$feed_manager = $this->getMockBuilder( 'stdClass' )
			->addMethods( [ 'get_feed_secret' ] )
			->getMock();

		$feed_manager->method( 'get_feed_secret' )
			->willReturn( 'test_secret_123' );

		$plugin = $this->getMockBuilder( 'stdClass' )
			->addMethods( [ '__get' ] )
			->getMock();

		$plugin->method( '__get' )
			->with( 'feed_manager' )
			->willReturn( $feed_manager );

		// Mock the global function
		if ( ! function_exists( 'facebook_for_woocommerce' ) ) {
			eval( 'function facebook_for_woocommerce() { return $GLOBALS["wc_facebook_commerce"]; }' );
		}
		$GLOBALS['wc_facebook_commerce'] = $plugin;

		$this->instance = new JsonFeedFileWriter( $this->feed_name, $this->header_row );
	}

	/**
	 * Clean up test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		// Clean up any created files
		if ( $this->instance ) {
			$temp_file_path = $this->instance->get_temp_file_path();
			if ( file_exists( $temp_file_path ) ) {
				unlink( $temp_file_path );
			}

			$file_path = $this->instance->get_file_path();
			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
			}

			// Clean up directory
			$file_directory = $this->instance->get_file_directory();
			if ( is_dir( $file_directory ) ) {
				$files = glob( $file_directory . '/*' );
				foreach ( $files as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
				rmdir( $file_directory );
			}
		}

		unset( $GLOBALS['wc_facebook_commerce'] );
		parent::tearDown();
	}

	/**
	 * Test that the class can be instantiated.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::__construct
	 */
	public function test_class_exists_and_can_be_instantiated() {
		$this->assertInstanceOf( JsonFeedFileWriter::class, $this->instance );
	}

	/**
	 * Test that JsonFeedFileWriter extends AbstractFeedFileWriter.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter
	 */
	public function test_extends_abstract_feed_file_writer() {
		$this->assertInstanceOf( 'WooCommerce\Facebook\Feed\AbstractFeedFileWriter', $this->instance );
	}

	/**
	 * Test write_temp_feed_file with valid data.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_with_valid_data() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [
			'id'          => '12345',
			'title'       => 'Test Product',
			'description' => 'This is a test product description',
			'price'       => '29.99',
		];

		// Write data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and contains JSON data
		$temp_file_path = $this->instance->get_temp_file_path();
		$this->assertFileExists( $temp_file_path );

		$file_contents = file_get_contents( $temp_file_path );
		$this->assertNotEmpty( $file_contents );

		// Verify it's valid JSON
		$decoded_data = json_decode( $file_contents, true );
		$this->assertNotNull( $decoded_data );
		$this->assertEquals( $test_data, $decoded_data );
	}

	/**
	 * Test write_temp_feed_file with empty array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_with_empty_array() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [];

		// Write empty data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and contains empty JSON array
		$temp_file_path = $this->instance->get_temp_file_path();
		$this->assertFileExists( $temp_file_path );

		$file_contents = file_get_contents( $temp_file_path );
		$this->assertEquals( '[]', $file_contents );
	}

	/**
	 * Test write_temp_feed_file with complex nested data.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_with_complex_nested_data() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [
			'id'       => '12345',
			'title'    => 'Test Product',
			'variants' => [
				[
					'id'    => 'var1',
					'price' => '19.99',
					'color' => 'red',
				],
				[
					'id'    => 'var2',
					'price' => '24.99',
					'color' => 'blue',
				],
			],
			'metadata' => [
				'tags'       => [ 'tag1', 'tag2', 'tag3' ],
				'categories' => [ 'cat1', 'cat2' ],
			],
		];

		// Write data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and contains correct JSON data
		$temp_file_path = $this->instance->get_temp_file_path();
		$this->assertFileExists( $temp_file_path );

		$file_contents = file_get_contents( $temp_file_path );
		$decoded_data  = json_decode( $file_contents, true );

		$this->assertEquals( $test_data, $decoded_data );
		$this->assertIsArray( $decoded_data['variants'] );
		$this->assertCount( 2, $decoded_data['variants'] );
		$this->assertIsArray( $decoded_data['metadata']['tags'] );
		$this->assertCount( 3, $decoded_data['metadata']['tags'] );
	}

	/**
	 * Test write_temp_feed_file with special characters.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_with_special_characters() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [
			'id'          => '12345',
			'title'       => 'Test "Product" with \'quotes\'',
			'description' => 'Description with special chars: <>&"\' and unicode: café, naïve, 日本語',
			'url'         => 'https://example.com/product?id=123&ref=test',
		];

		// Write data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and special characters are properly encoded
		$temp_file_path = $this->instance->get_temp_file_path();
		$this->assertFileExists( $temp_file_path );

		$file_contents = file_get_contents( $temp_file_path );
		$decoded_data  = json_decode( $file_contents, true );

		$this->assertEquals( $test_data, $decoded_data );
		$this->assertEquals( 'Test "Product" with \'quotes\'', $decoded_data['title'] );
		$this->assertStringContainsString( 'café', $decoded_data['description'] );
		$this->assertStringContainsString( '日本語', $decoded_data['description'] );
	}

	/**
	 * Test write_temp_feed_file throws exception when file cannot be opened.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_throws_exception_when_file_cannot_be_opened() {
		// Create the feed directory
		$this->instance->create_feed_directory();

		// Get temp file path but don't create it
		$temp_file_path = $this->instance->get_temp_file_path();

		// Make the directory read-only to prevent file creation
		$file_directory = $this->instance->get_file_directory();
		chmod( $file_directory, 0444 );

		$test_data = [ 'id' => '12345' ];

		$this->expectException( PluginException::class );
		$this->expectExceptionMessage( 'Unable to open temporary file' );

		try {
			$this->instance->write_temp_feed_file( $test_data );
		} finally {
			// Restore permissions for cleanup
			chmod( $file_directory, 0755 );
		}
	}

	/**
	 * Test write_temp_feed_file appends to existing file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_appends_to_existing_file() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		// Write first data
		$first_data = [ 'id' => '12345', 'title' => 'First Product' ];
		$this->instance->write_temp_feed_file( $first_data );

		// Write second data (should append)
		$second_data = [ 'id' => '67890', 'title' => 'Second Product' ];
		$this->instance->write_temp_feed_file( $second_data );

		// Verify file contains both JSON objects
		$temp_file_path = $this->instance->get_temp_file_path();
		$file_contents  = file_get_contents( $temp_file_path );

		// File should contain both JSON objects concatenated
		$this->assertStringContainsString( '"id":"12345"', $file_contents );
		$this->assertStringContainsString( '"id":"67890"', $file_contents );
	}

	/**
	 * Test that JSON encoding uses wp_json_encode.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_json_encoding_uses_wp_json_encode() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [
			'id'    => '12345',
			'title' => 'Test & Product',
			'url'   => 'https://example.com/test?param=value&other=test',
		];

		// Write data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and uses proper JSON encoding
		$temp_file_path = $this->instance->get_temp_file_path();
		$file_contents  = file_get_contents( $temp_file_path );

		// wp_json_encode should properly encode the data
		$expected_json = wp_json_encode( $test_data );
		$this->assertEquals( $expected_json, $file_contents );
	}

	/**
	 * Test get_file_path returns correct path.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_file_path
	 */
	public function test_get_file_path_returns_correct_path() {
		$file_path = $this->instance->get_file_path();

		$this->assertIsString( $file_path );
		$this->assertStringContainsString( $this->feed_name, $file_path );
		$this->assertStringContainsString( '.json', $file_path );
		$this->assertStringContainsString( 'facebook_for_woocommerce', $file_path );
	}

	/**
	 * Test get_temp_file_path returns correct path.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_temp_file_path
	 */
	public function test_get_temp_file_path_returns_correct_path() {
		$temp_file_path = $this->instance->get_temp_file_path();

		$this->assertIsString( $temp_file_path );
		$this->assertStringContainsString( $this->feed_name, $temp_file_path );
		$this->assertStringContainsString( 'temp_', $temp_file_path );
		$this->assertStringContainsString( '.json', $temp_file_path );
		$this->assertStringContainsString( 'facebook_for_woocommerce', $temp_file_path );
	}

	/**
	 * Test get_file_directory returns correct directory.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_file_directory
	 */
	public function test_get_file_directory_returns_correct_directory() {
		$file_directory = $this->instance->get_file_directory();

		$this->assertIsString( $file_directory );
		$this->assertStringContainsString( 'facebook_for_woocommerce', $file_directory );
		$this->assertStringContainsString( $this->feed_name, $file_directory );
	}

	/**
	 * Test get_file_name returns correct file name with JSON extension.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_file_name
	 */
	public function test_get_file_name_returns_correct_name() {
		$file_name = $this->instance->get_file_name();

		$this->assertIsString( $file_name );
		$this->assertStringContainsString( $this->feed_name, $file_name );
		$this->assertStringContainsString( 'test_secret_123', $file_name );
		$this->assertStringEndsWith( '.json', $file_name );
	}

	/**
	 * Test get_temp_file_name returns correct temporary file name.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_temp_file_name
	 */
	public function test_get_temp_file_name_returns_correct_name() {
		$temp_file_name = $this->instance->get_temp_file_name();

		$this->assertIsString( $temp_file_name );
		$this->assertStringContainsString( $this->feed_name, $temp_file_name );
		$this->assertStringContainsString( 'temp_', $temp_file_name );
		$this->assertStringEndsWith( '.json', $temp_file_name );
	}

	/**
	 * Test FILE_NAME constant is defined correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter
	 */
	public function test_file_name_constant_is_defined() {
		$reflection = new \ReflectionClass( JsonFeedFileWriter::class );
		$this->assertTrue( $reflection->hasConstant( 'FILE_NAME' ) );
		$this->assertEquals( '%s_feed_%s.json', JsonFeedFileWriter::FILE_NAME );
	}

	/**
	 * Test write_temp_feed_file with numeric data.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_with_numeric_data() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [
			'id'       => 12345,
			'price'    => 29.99,
			'quantity' => 100,
			'rating'   => 4.5,
			'is_sale'  => true,
		];

		// Write data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and numeric types are preserved
		$temp_file_path = $this->instance->get_temp_file_path();
		$file_contents  = file_get_contents( $temp_file_path );
		$decoded_data   = json_decode( $file_contents, true );

		$this->assertEquals( $test_data, $decoded_data );
		$this->assertIsInt( $decoded_data['id'] );
		$this->assertIsFloat( $decoded_data['price'] );
		$this->assertIsInt( $decoded_data['quantity'] );
		$this->assertIsFloat( $decoded_data['rating'] );
		$this->assertIsBool( $decoded_data['is_sale'] );
	}

	/**
	 * Test write_temp_feed_file with null values.
	 *
	 * @covers \WooCommerce\Facebook\Feed\JsonFeedFileWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_with_null_values() {
		// Create the feed directory first
		$this->instance->create_feed_directory();

		// Prepare temporary file
		$temp_feed_file = $this->instance->prepare_temporary_feed_file();
		fclose( $temp_feed_file );

		$test_data = [
			'id'          => '12345',
			'title'       => 'Test Product',
			'description' => null,
			'image_url'   => null,
		];

		// Write data to temp file
		$this->instance->write_temp_feed_file( $test_data );

		// Verify file exists and null values are preserved
		$temp_file_path = $this->instance->get_temp_file_path();
		$file_contents  = file_get_contents( $temp_file_path );
		$decoded_data   = json_decode( $file_contents, true );

		$this->assertEquals( $test_data, $decoded_data );
		$this->assertNull( $decoded_data['description'] );
		$this->assertNull( $decoded_data['image_url'] );
	}

	/**
	 * Test create_feed_directory creates directory successfully.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::create_feed_directory
	 */
	public function test_create_feed_directory_creates_directory() {
		$file_directory = $this->instance->get_file_directory();

		// Ensure directory doesn't exist
		if ( is_dir( $file_directory ) ) {
			rmdir( $file_directory );
		}

		$this->instance->create_feed_directory();

		$this->assertDirectoryExists( $file_directory );
	}

	/**
	 * Test temp file and final file have different names.
	 *
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_file_name
	 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter::get_temp_file_name
	 */
	public function test_temp_file_and_final_file_have_different_names() {
		$file_name      = $this->instance->get_file_name();
		$temp_file_name = $this->instance->get_temp_file_name();

		$this->assertNotEquals( $file_name, $temp_file_name );
		$this->assertStringContainsString( 'temp_', $temp_file_name );
		$this->assertStringNotContainsString( 'temp_', $file_name );
	}
}
