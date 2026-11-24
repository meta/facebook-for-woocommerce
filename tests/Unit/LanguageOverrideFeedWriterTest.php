<?php
declare( strict_types=1 );

/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter;
use WooCommerce\Facebook\Feed\Localization\LanguageFeedData;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for LanguageOverrideFeedWriter class.
 *
 * @since 3.6.0
 */
class LanguageOverrideFeedWriterTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Instance of LanguageOverrideFeedWriter for testing.
	 *
	 * @var LanguageOverrideFeedWriter
	 */
	private $writer;

	/**
	 * Test language code.
	 *
	 * @var string
	 */
	private $test_language_code = 'es_ES';

	/**
	 * Temporary files created during tests.
	 *
	 * @var array
	 */
	private $temp_files = [];

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->writer = new LanguageOverrideFeedWriter( $this->test_language_code );
		$this->temp_files = [];
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clean up any temporary files created during tests
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file );
			}
		}

		// Clean up test directories
		$upload_dir = wp_upload_dir( null, false );
		$test_dir = trailingslashit( $upload_dir['basedir'] ) . 'facebook_for_woocommerce/language_override_es';
		if ( is_dir( $test_dir ) ) {
			$this->remove_directory_recursively( $test_dir );
		}

		parent::tearDown();
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 */
	private function remove_directory_recursively( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? $this->remove_directory_recursively( $path ) : @unlink( $path );
		}
		@rmdir( $dir );
	}

	/**
	 * Test that constructor sets the language code correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::__construct
	 */
	public function test_constructor_sets_language_code(): void {
		$writer = new LanguageOverrideFeedWriter( 'fr_FR' );
		$this->assertEquals( 'fr_FR', $writer->get_language_code() );
	}

	/**
	 * Test that constructor accepts custom delimiters.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::__construct
	 */
	public function test_constructor_accepts_custom_delimiters(): void {
		$writer = new LanguageOverrideFeedWriter( 'de_DE', ';', "'", '/' );
		$this->assertInstanceOf( LanguageOverrideFeedWriter::class, $writer );
	}

	/**
	 * Test that get_language_code returns the correct language code.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_language_code
	 */
	public function test_get_language_code_returns_correct_code(): void {
		$this->assertEquals( $this->test_language_code, $this->writer->get_language_code() );
	}

	/**
	 * Test that get_file_name returns correct format.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_file_name
	 */
	public function test_get_file_name_returns_correct_format(): void {
		$file_name = $this->writer->get_file_name();

		// Should contain language code and be a CSV file
		$this->assertStringContainsString( 'facebook_language_feed_', $file_name );
		$this->assertStringContainsString( 'es', $file_name );
		$this->assertStringEndsWith( '.csv', $file_name );
	}

	/**
	 * Test that get_file_name applies the filter.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_file_name
	 */
	public function test_get_file_name_applies_filter(): void {
		$expected_filename = 'custom_language_feed.csv';

		$filter = $this->add_filter_with_safe_teardown(
			'wc_facebook_language_override_feed_file_name',
			function( $file_name, $language_code ) use ( $expected_filename ) {
				$this->assertEquals( 'es_ES', $language_code );
				return $expected_filename;
			},
			10,
			2
		);

		$file_name = $this->writer->get_file_name();
		$this->assertEquals( $expected_filename, $file_name );

		$filter->teardown_safely_immediately();
	}

	/**
	 * Test that get_temp_file_name returns correct format.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_temp_file_name
	 */
	public function test_get_temp_file_name_returns_correct_format(): void {
		$temp_file_name = $this->writer->get_temp_file_name();

		// Should contain temp prefix and language code
		$this->assertStringContainsString( 'temp_', $temp_file_name );
		$this->assertStringContainsString( 'facebook_language_feed_', $temp_file_name );
		$this->assertStringContainsString( 'es', $temp_file_name );
		$this->assertStringEndsWith( '.csv', $temp_file_name );
	}

	/**
	 * Test that get_temp_file_name applies the filter.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_temp_file_name
	 */
	public function test_get_temp_file_name_applies_filter(): void {
		$expected_filename = 'custom_temp_language_feed.csv';

		$filter = $this->add_filter_with_safe_teardown(
			'wc_facebook_language_override_temp_feed_file_name',
			function( $file_name, $language_code ) use ( $expected_filename ) {
				$this->assertEquals( 'es_ES', $language_code );
				return $expected_filename;
			},
			10,
			2
		);

		$temp_file_name = $this->writer->get_temp_file_name();
		$this->assertEquals( $expected_filename, $temp_file_name );

		$filter->teardown_safely_immediately();
	}

	/**
	 * Test that write_temp_feed_file writes data correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_writes_data(): void {
		// Create a temporary directory for testing
		$upload_dir = wp_upload_dir( null, false );
		$test_dir = trailingslashit( $upload_dir['basedir'] ) . 'facebook_for_woocommerce/language_override_es';
		wp_mkdir_p( $test_dir );

		// Create a temporary file
		$temp_file_path = $test_dir . '/test_temp_feed.csv';
		$this->temp_files[] = $temp_file_path;

		// Write header first
		$header_file = fopen( $temp_file_path, 'w' );
		fputcsv( $header_file, [ 'id', 'title', 'description' ] );
		fclose( $header_file );

		// Prepare test data
		$test_data = [
			[ '123', 'Product Title', 'Product Description' ],
			[ '456', 'Another Product', 'Another Description' ],
		];

		// Mock the get_temp_file_path method to return our test path
		$writer = $this->getMockBuilder( LanguageOverrideFeedWriter::class )
			->setConstructorArgs( [ $this->test_language_code ] )
			->onlyMethods( [ 'get_temp_file_path' ] )
			->getMock();

		$writer->method( 'get_temp_file_path' )
			->willReturn( $temp_file_path );

		// Write the data
		$writer->write_temp_feed_file( $test_data );

		// Verify the file exists and contains the data
		$this->assertFileExists( $temp_file_path );

		$file_contents = file_get_contents( $temp_file_path );
		$this->assertStringContainsString( '123', $file_contents );
		$this->assertStringContainsString( 'Product Title', $file_contents );
		$this->assertStringContainsString( '456', $file_contents );
	}

	/**
	 * Test that write_temp_feed_file throws exception on invalid path.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_temp_feed_file
	 */
	public function test_write_temp_feed_file_throws_exception_on_invalid_path(): void {
		$this->expectException( \WooCommerce\Facebook\Framework\Plugin\Exception::class );
		$this->expectExceptionMessage( 'Could not open temp file for writing' );

		// Mock the get_temp_file_path method to return an invalid path
		$writer = $this->getMockBuilder( LanguageOverrideFeedWriter::class )
			->setConstructorArgs( [ $this->test_language_code ] )
			->onlyMethods( [ 'get_temp_file_path' ] )
			->getMock();

		$writer->method( 'get_temp_file_path' )
			->willReturn( '/invalid/path/that/does/not/exist/file.csv' );

		$test_data = [ [ '123', 'Test' ] ];
		$writer->write_temp_feed_file( $test_data );
	}

	/**
	 * Test that write_language_feed_file succeeds with valid data.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_language_feed_file
	 */
	public function test_write_language_feed_file_success(): void {
		// Mock LanguageFeedData
		$language_feed_data = $this->createMock( LanguageFeedData::class );
		$language_feed_data->method( 'get_language_csv_data' )
			->willReturn( [
				'columns' => [ 'id', 'title', 'description' ],
				'data' => [
					[
						'id' => '123',
						'title' => 'Product Title',
						'description' => 'Product Description',
					],
					[
						'id' => '456',
						'title' => 'Another Product',
						'description' => 'Another Description',
					],
				],
			] );

		// Create a temporary directory for testing
		$upload_dir = wp_upload_dir( null, false );
		$test_dir = trailingslashit( $upload_dir['basedir'] ) . 'facebook_for_woocommerce/language_override_es';
		wp_mkdir_p( $test_dir );

		$result = $this->writer->write_language_feed_file( $language_feed_data, $this->test_language_code );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, $result['count'] );
	}

	/**
	 * Test that write_language_feed_file handles empty data correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_language_feed_file
	 */
	public function test_write_language_feed_file_with_empty_data(): void {
		// Mock LanguageFeedData with empty data
		$language_feed_data = $this->createMock( LanguageFeedData::class );
		$language_feed_data->method( 'get_language_csv_data' )
			->willReturn( [
				'columns' => [ 'id', 'override' ],
				'data' => [],
			] );

		// Create a temporary directory for testing
		$upload_dir = wp_upload_dir( null, false );
		$test_dir = trailingslashit( $upload_dir['basedir'] ) . 'facebook_for_woocommerce/language_override_es';
		wp_mkdir_p( $test_dir );

		$result = $this->writer->write_language_feed_file( $language_feed_data, $this->test_language_code );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 0, $result['count'] );
	}

	/**
	 * Test that write_language_feed_file handles exceptions correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_language_feed_file
	 */
	public function test_write_language_feed_file_handles_exception(): void {
		// Mock LanguageFeedData to throw an exception
		$language_feed_data = $this->createMock( LanguageFeedData::class );
		$language_feed_data->method( 'get_language_csv_data' )
			->willThrowException( new \Exception( 'Test exception' ) );

		$result = $this->writer->write_language_feed_file( $language_feed_data, $this->test_language_code );

		$this->assertFalse( $result['success'] );
		$this->assertEquals( 0, $result['count'] );
	}

	/**
	 * Test that write_language_feed_file returns correct product count.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_language_feed_file
	 */
	public function test_write_language_feed_file_returns_correct_count(): void {
		// Mock LanguageFeedData with specific number of products
		$language_feed_data = $this->createMock( LanguageFeedData::class );
		$language_feed_data->method( 'get_language_csv_data' )
			->willReturn( [
				'columns' => [ 'id', 'title' ],
				'data' => [
					[ 'id' => '1', 'title' => 'Product 1' ],
					[ 'id' => '2', 'title' => 'Product 2' ],
					[ 'id' => '3', 'title' => 'Product 3' ],
					[ 'id' => '4', 'title' => 'Product 4' ],
					[ 'id' => '5', 'title' => 'Product 5' ],
				],
			] );

		// Create a temporary directory for testing
		$upload_dir = wp_upload_dir( null, false );
		$test_dir = trailingslashit( $upload_dir['basedir'] ) . 'facebook_for_woocommerce/language_override_es';
		wp_mkdir_p( $test_dir );

		$result = $this->writer->write_language_feed_file( $language_feed_data, $this->test_language_code );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 5, $result['count'] );
	}

	/**
	 * Test that write_language_feed_file sets dynamic headers correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::write_language_feed_file
	 */
	public function test_write_language_feed_file_sets_dynamic_headers(): void {
		// Mock LanguageFeedData with custom columns
		$language_feed_data = $this->createMock( LanguageFeedData::class );
		$language_feed_data->method( 'get_language_csv_data' )
			->willReturn( [
				'columns' => [ 'id', 'title', 'description', 'price' ],
				'data' => [
					[
						'id' => '123',
						'title' => 'Product',
						'description' => 'Description',
						'price' => '10.00',
					],
				],
			] );

		// Create a temporary directory for testing
		$upload_dir = wp_upload_dir( null, false );
		$test_dir = trailingslashit( $upload_dir['basedir'] ) . 'facebook_for_woocommerce/language_override_es';
		wp_mkdir_p( $test_dir );

		$result = $this->writer->write_language_feed_file( $language_feed_data, $this->test_language_code );

		$this->assertTrue( $result['success'] );
		$this->assertEquals( 1, $result['count'] );
	}

	/**
	 * Test that different language codes create different instances.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::__construct
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_language_code
	 */
	public function test_different_language_codes_create_different_instances(): void {
		$writer_es = new LanguageOverrideFeedWriter( 'es_ES' );
		$writer_fr = new LanguageOverrideFeedWriter( 'fr_FR' );
		$writer_de = new LanguageOverrideFeedWriter( 'de_DE' );

		$this->assertEquals( 'es_ES', $writer_es->get_language_code() );
		$this->assertEquals( 'fr_FR', $writer_fr->get_language_code() );
		$this->assertEquals( 'de_DE', $writer_de->get_language_code() );
	}

	/**
	 * Test that file names are different for different languages.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter::get_file_name
	 */
	public function test_file_names_differ_by_language(): void {
		$writer_es = new LanguageOverrideFeedWriter( 'es_ES' );
		$writer_fr = new LanguageOverrideFeedWriter( 'fr_FR' );

		$file_name_es = $writer_es->get_file_name();
		$file_name_fr = $writer_fr->get_file_name();

		$this->assertNotEquals( $file_name_es, $file_name_fr );
		$this->assertStringContainsString( 'es', $file_name_es );
		$this->assertStringContainsString( 'fr', $file_name_fr );
	}
}
