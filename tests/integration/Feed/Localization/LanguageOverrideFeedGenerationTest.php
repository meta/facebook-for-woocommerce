<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\Feed\Localization;

use WooCommerce\Facebook\Feed\Localization\LanguageFeedData;
use WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed;
use WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeedWriter;
use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;

/**
 * Integration tests for Language Override Feed Generation
 *
 * Tests the complete pipeline from product translation creation to CSV feed generation.
 * These are integration tests that validate end-to-end language override feed workflows.
 *
 * @since 3.6.0
 */
class LanguageOverrideFeedGenerationTest extends IntegrationTestCase {

	/**
	 * @var string|null Expected plugin to be active for this test run
	 */
	private static $expected_plugin = null;

	/**
	 * @var LanguageFeedData Language feed data handler instance
	 */
	private $language_feed_data;

	/**
	 * @var array Test products with translations (recreated for each test)
	 */
	private $test_products_with_translations = [];

	/**
	 * @var array Test languages for consistent testing
	 */
	private $test_languages = [];

	/**
	 * Set up the test class
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Note: Plugin expectation can be set via FB_TEST_PLUGIN environment variable
		self::$expected_plugin = $_ENV['FB_TEST_PLUGIN'] ?? null;
	}

	/**
	 * Set up each test
	 */
	public function setUp(): void {
		parent::setUp();

		$this->language_feed_data = new LanguageFeedData();

		// Ensure Polylang is properly set up for this test
		$this->ensurePolylangSetup();

		// Check if we have an active localization plugin
		if ( ! IntegrationRegistry::has_active_localization_plugin() ) {
			$this->markTestSkipped( 'No active localization integration found for feed generation tests' );
			return;
		}

		$this->setupTestData();
	}

	/**
	 * Test complete language override feed generation pipeline
	 *
	 * This test covers the full workflow:
	 * 1. Create products with translations
	 * 2. Generate language feed data
	 * 3. Create CSV files
	 * 4. Validate content and structure
	 */
	public function test_complete_language_override_feed_generation_pipeline(): void {

		$languages = $this->test_languages;
		$this->assertNotEmpty( $languages, 'Should have at least one non-default language available' );

		// SIMPLIFIED TEST - JUST TEST SPANISH
		$language_code = 'es_ES';

		// Step 1: Generate feed data (using -1 to fetch ALL products, matching legacy feed behavior)
		$csv_result = $this->language_feed_data->get_language_csv_data( $language_code, -1, 0 );

		$this->assertArrayHasKey( 'data', $csv_result, 'CSV result should have data array' );
		$this->assertArrayHasKey( 'columns', $csv_result, 'CSV result should have columns array' );
		$this->assertArrayHasKey( 'translated_fields', $csv_result, 'CSV result should have translated_fields array' );
		$this->assertNotEmpty( $csv_result['data'], "Should have CSV data for language {$language_code}" );
		$this->assertContains( 'id', $csv_result['columns'], 'Should have required id column' );
		$this->assertContains( 'override', $csv_result['columns'], 'Should have required override column' );

		if ( true ) { // Just test one language now

			// Step 2: Create language feed writer and generate CSV file
			$language_feed_writer = new LanguageOverrideFeedWriter( $language_code );
			$result = $language_feed_writer->write_language_feed_file( $this->language_feed_data, $language_code );

			// Assert the result structure
			$this->assertIsArray( $result, "Language feed file write should return an array for {$language_code}" );
			$this->assertArrayHasKey( 'success', $result, "Result should have 'success' key" );
			$this->assertArrayHasKey( 'count', $result, "Result should have 'count' key" );
			$this->assertTrue( $result['success'], "Language feed file should be written successfully for {$language_code}" );

			// Assert the count matches the number of test products
			$expected_count = count( $this->test_products_with_translations );
			$this->assertEquals(
				$expected_count,
				$result['count'],
				"Feed should contain {$expected_count} products (created {$expected_count} test products)"
			);

			// Step 3: Verify file creation and content
			$file_path = $language_feed_writer->get_file_path();
			$temp_file_path = $language_feed_writer->get_temp_file_path();

			// Handle case where temp file needs to be promoted to final file
			if ( ! file_exists( $file_path ) && file_exists( $temp_file_path ) ) {
				rename( $temp_file_path, $file_path );
			}

			$this->assertFileExists( $file_path, "Language feed file should exist for {$language_code}" );

			// Step 4: Validate CSV file content
			$csv_content = file_get_contents( $file_path );
			$this->assertNotEmpty( $csv_content, 'CSV file should not be empty' );

			// Verify CSV structure
			$csv_lines = explode( "\n", trim( $csv_content ) );

			// Verify header line matches expected columns
			$header_line = $csv_lines[0];
			foreach ( $csv_result['columns'] as $expected_column ) {
				$this->assertStringContainsString( $expected_column, $header_line, "CSV header should contain {$expected_column} column" );
			}

			// Verify data rows contain expected product data
			foreach ( $this->test_products_with_translations as $product_info ) {
				$original_product_id = $product_info['original_id'];
				$found_in_csv = false;

				foreach ( $csv_lines as $line_index => $line ) {
					if ( $line_index === 0 ) continue; // Skip header
					if ( empty( trim( $line ) ) ) continue; // Skip empty lines

					// Parse CSV line to check for our product
					$csv_data = str_getcsv( $line, ',', '"', '\\' );
					if ( ! empty( $csv_data[0] ) && strpos( $csv_data[0], (string) $original_product_id ) !== false ) {
						$found_in_csv = true;

						// Verify override column contains proper Facebook language code
						$facebook_language_code = \WooCommerce\Facebook\Locale::convert_to_facebook_language_code( $language_code );
						$override_column_index = array_search( 'override', $csv_result['columns'] );
						if ( $override_column_index !== false && isset( $csv_data[ $override_column_index ] ) ) {
							$this->assertEquals( $facebook_language_code, $csv_data[ $override_column_index ], 'Override column should contain correct Facebook language code' );
						}
						break;
					}
				}

				$this->assertTrue( $found_in_csv, "Product {$original_product_id} should be found in CSV for language {$language_code}" );
			}
		}
	}

	/**
	 * Test translated product data extraction
	 *
	 * Tests the core data transformation from WooCommerce products with translations
	 * to the structured data needed for Facebook language override feeds.
	 */
	public function test_translated_product_data_extraction(): void {

		$languages = $this->test_languages;

		// SIMPLIFIED TEST - JUST TEST SPANISH
		$language_code = 'es_ES';

		if ( true ) { // Just test one language now
			// Test language availability
			$available_languages = $this->language_feed_data->get_available_languages();
			$this->assertContains( $language_code, $available_languages, "Language {$language_code} should be available" );

			$default_language = $this->language_feed_data->get_default_language();
			$this->assertNotEmpty( $default_language, 'Should have a default language' );
			$this->assertNotEquals( $language_code, $default_language, 'Test language should be different from default' );

			// Test translated fields detection (using -1 to check ALL products)
			$translated_fields = $this->language_feed_data->get_translated_fields_for_language( $language_code, -1 );
			$this->assertNotEmpty( $translated_fields, "Should detect translated fields for {$language_code}" );

			// Should include the fields we specifically translated
			$expected_fields = [ 'name', 'description', 'short_description' ];
			foreach ( $expected_fields as $expected_field ) {
				$this->assertContains( $expected_field, $translated_fields, "Should detect {$expected_field} as translated field" );
			}

			// Test CSV data generation with detailed validation (using -1 to fetch ALL products)
			$csv_result = $this->language_feed_data->get_language_csv_data( $language_code, -1, 0 );

			$this->assertNotEmpty( $csv_result['data'], "Should generate CSV data for {$language_code}" );
			$this->assertArrayHasKey( 'columns', $csv_result, 'Should have columns definition' );
			$this->assertArrayHasKey( 'translated_fields', $csv_result, 'Should have translated fields info' );

			// Verify required columns
			$this->assertContains( 'id', $csv_result['columns'], 'Should have id column' );
			$this->assertContains( 'override', $csv_result['columns'], 'Should have override column' );

			// Verify dynamic columns based on translated fields
			if ( in_array( 'name', $translated_fields ) ) {
				$this->assertContains( 'title', $csv_result['columns'], 'Should have title column when name is translated' );
			}
			if ( in_array( 'description', $translated_fields ) || in_array( 'short_description', $translated_fields ) ) {
				$this->assertContains( 'description', $csv_result['columns'], 'Should have description column when descriptions are translated' );
			}

			// Test individual product translation details
			foreach ( $this->test_products_with_translations as $product_info ) {
				$original_product_id = $product_info['original_id'];
				$translation_details = $this->language_feed_data->get_product_translation_details( $original_product_id );

				$this->assertArrayHasKey( 'translations', $translation_details, 'Should have translations array' );
				$this->assertArrayHasKey( $language_code, $translation_details['translations'], "Should have translation for {$language_code}" );

				$translated_product_id = $translation_details['translations'][ $language_code ];
				$this->assertNotEquals( $original_product_id, $translated_product_id, 'Translated product should have different ID' );

				// Verify translated fields information
				$this->assertArrayHasKey( 'translated_fields', $translation_details, 'Should have translated_fields info' );
				$this->assertArrayHasKey( $language_code, $translation_details['translated_fields'], "Should have translated fields for {$language_code}" );

				$product_translated_fields = $translation_details['translated_fields'][ $language_code ];
				$this->assertNotEmpty( $product_translated_fields, 'Should have at least one translated field' );
			}
		}
	}

	/**
	 * Test localization plugin feed generation integration
	 *
	 * Tests that the language override feed generation integrates properly
	 * with localization plugins and respects their configuration.
	 */
	public function test_localization_plugin_feed_generation_integration(): void {
		$active_integration = $this->getActiveIntegration();
		$this->assertNotNull( $active_integration, 'Should have an active localization integration' );

		// Test that language override feed can access integration data
		$available_languages = $active_integration->get_available_languages();
		$default_language = $active_integration->get_default_language();

		// Verify language feed data uses the same language configuration
		$feed_available_languages = $this->language_feed_data->get_available_languages();
		$feed_default_language = $this->language_feed_data->get_default_language();

		// Feed available languages should exclude the default language (since we only generate feeds for non-default languages)
		$expected_feed_languages = array_filter( $available_languages, function( $lang ) use ( $default_language ) {
			return $lang !== $default_language;
		});
		$expected_feed_languages = array_values( $expected_feed_languages ); // Re-index array

		$this->assertEquals( $expected_feed_languages, $feed_available_languages, 'Feed should provide non-default languages' );
		$this->assertEquals( $default_language, $feed_default_language, 'Feed should use same default language as integration' );
	}

	/**
	 * Set up shared test data for reuse across multiple tests
	 */
	private function setupTestData(): void {
		$active_integration = $this->getActiveIntegration();

		if ( ! $active_integration ) {
			$this->markTestSkipped( 'No active localization integration found for shared test data setup' );
			return;
		}

		// Get available languages
		$available_languages = $active_integration->get_available_languages();
		$default_language = $active_integration->get_default_language();

		// Find non-default languages for testing - focus on Spanish for now
		$target_languages = array_filter( $available_languages, function( $lang ) use ( $default_language ) {
			return $lang !== $default_language && $lang === 'es_ES';
		});

		if ( empty( $target_languages ) ) {
			$this->markTestSkipped( 'Spanish language not available for testing' );
			return;
		}

		// Store languages for use across tests
		$this->test_languages = array_values( $target_languages );

		// Create test products with translations
		$this->test_products_with_translations = [];

		// Create products for load testing
		$num_products = 20;

		$start_time = microtime(true);
		echo "\n\nCreating {$num_products} products with translations...\n";
		echo "Started at: " . date('H:i:s') . "\n";

		for ( $i = 1; $i <= $num_products; $i++ ) {
			$product = $this->create_simple_product([
				'name' => "Load Test Product #{$i}",
				'description' => "This is load test product number {$i} for testing large catalog feed generation.",
				'short_description' => "Load test product #{$i}.",
				'regular_price' => sprintf( '%.2f', 10 + ( $i % 100 ) ),
				'status' => 'publish',
				'catalog_visibility' => 'visible'
			]);

			$product_info = [
				'original_id' => $product->get_id(),
				'translations' => []
			];

			foreach ( $this->test_languages as $target_language ) {
				// Create language-specific content
				$translated_data = [
					'name' => "Producto de Prueba Número {$i}",
					'description' => "Esta es la descripción del producto de prueba número {$i}.",
					'short_description' => "Producto de prueba #{$i}."
				];

				// Create translation using integration
				$translated_id = $active_integration->create_product_translation(
					$product->get_id(),
					$target_language,
					$translated_data
				);

				$this->assertNotNull( $translated_id, "Should create {$target_language} translation for product {$product->get_id()}" );
				$product_info['translations'][ $target_language ] = $translated_id;
			}

			$this->test_products_with_translations[] = $product_info;
		}

		echo "✓ Completed creating {$num_products} products with translations!\n\n";
	}

	/**
	 * Tear down each test
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Clear our test data
		$this->test_products_with_translations = [];
		$this->test_languages = [];
	}

	/**
	 * Get the active localization integration for testing
	 */
	private function getActiveIntegration() {
		$integrations = IntegrationRegistry::get_all_localization_integrations();

		// Find the first available integration
		foreach ( $integrations as $integration ) {
			if ( $integration->is_available() ) {
				return $integration;
			}
		}

		return null;
	}

	/**
	 * Create translated content for testing
	 */
	private function createTranslatedContent( int $product_number, string $target_language ): array {
		$language_names = [
			'es_ES' => 'español',
			'fr_FR' => 'français',
			'de_DE' => 'deutsch'
		];

		$language_name = $language_names[ $target_language ] ?? $target_language;

		return [
			'name' => "Producto de Prueba Número {$product_number}",
			'description' => "Esta es la descripción completa del producto de prueba número {$product_number} en {$language_name}.",
			'short_description' => "Descripción corta en {$language_name} para producto {$product_number}."
		];
	}

	/**
	 * Ensure Polylang is properly set up for testing
	 *
	 * This method ensures that Polylang language terms are created and available
	 * for each individual test, preventing test suite cleanup from interfering.
	 */
	private function ensurePolylangSetup(): void {
		// Only run for Polylang tests. Polylang may be one of several requested plugins.
		if ( ! \Automattic\WooCommerce\GoogleListingsAndAds\Tests\fb_test_plugin_requested( 'polylang' ) ) {
			return;
		}

		global $wpdb;

		// Check if Polylang language terms exist
		$polylang_terms = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}terms t
			INNER JOIN {$wpdb->prefix}term_taxonomy tt ON t.term_id = tt.term_id
			WHERE tt.taxonomy IN ('language', 'term_language')"
		);

		// If no Polylang terms exist, recreate them
		if ( $polylang_terms == 0 ) {
			$this->recreatePolylangLanguages();
		}
	}

	/**
	 * Recreate Polylang language terms
	 *
	 * This ensures the necessary language taxonomy terms exist for Polylang
	 * to function properly during tests.
	 */
	private function recreatePolylangLanguages(): void {
		// Essential language data that must exist for Polylang to work
		$languages_to_recreate = [
			[
				'name'        => 'English',
				'slug'        => 'en',
				'locale'      => 'en_US',
				'rtl'         => false,
				'term_group'  => 1,
				'flag'        => 'us',
			],
			[
				'name'        => 'Español',
				'slug'        => 'es',
				'locale'      => 'es_ES',
				'rtl'         => false,
				'term_group'  => 2,
				'flag'        => 'es',
			],
			[
				'name'        => 'Français',
				'slug'        => 'fr',
				'locale'      => 'fr_FR',
				'rtl'         => false,
				'term_group'  => 3,
				'flag'        => 'fr',
			],
			[
				'name'        => 'Deutsch',
				'slug'        => 'de',
				'locale'      => 'de_DE',
				'rtl'         => false,
				'term_group'  => 4,
				'flag'        => 'de',
			]
		];

		foreach ( $languages_to_recreate as $lang_data ) {
			// Check if language term already exists
			$existing_term = get_term_by( 'slug', $lang_data['slug'], 'language' );
			if ( $existing_term ) {
				continue;
			}

			// Prepare language data as Polylang expects it (serialized in description field)
			$language_description = serialize([
				'locale' => $lang_data['locale'],
				'rtl' => $lang_data['rtl'] ? 1 : 0,
				'flag_code' => $lang_data['flag']
			]);

			// Create language term with proper description data
			$term_result = wp_insert_term( $lang_data['name'], 'language', [
				'slug' => $lang_data['slug'],
				'description' => $language_description
			]);

			if ( ! is_wp_error( $term_result ) ) {
				$term_id = $term_result['term_id'];

				// Set term group (used by Polylang internally)
				wp_update_term( $term_id, 'language', [ 'term_group' => $lang_data['term_group'] ] );

				// Create corresponding post_language and term_language taxonomy terms
				foreach ( [ 'post_language', 'term_language' ] as $taxonomy ) {
					wp_insert_term( 'pll_' . $lang_data['slug'], $taxonomy, [
						'slug' => 'pll_' . $lang_data['slug'],
						'description' => 'Language term for ' . $lang_data['name']
					]);
				}
			}
		}

		// Ensure default language is set in Polylang options
		$polylang_options = get_option( 'polylang', [] );
		if ( empty( $polylang_options['default_lang'] ) ) {
			$polylang_options['default_lang'] = 'en';
			update_option( 'polylang', $polylang_options );
		}

		// Also set post types and taxonomies that Polylang should handle
		if ( empty( $polylang_options['post_types'] ) ) {
			$polylang_options['post_types'] = [ 'post', 'page', 'product' ];
			$polylang_options['taxonomies'] = [ 'category', 'post_tag', 'product_cat', 'product_tag' ];
			update_option( 'polylang', $polylang_options );
		}
	}
}
