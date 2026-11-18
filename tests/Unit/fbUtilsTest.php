<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare(strict_types=1);


class fbUtilsTest extends \WP_UnitTestCase {

    /** @var \WC_Product */
    private $product;

    public function setUp(): void {
        parent::setUp();

        // Create a simple product for testing
        $this->product = new \WC_Product_Simple();
        $this->product->set_name('Test Product');
        $this->product->set_regular_price('10.00');
        $this->product->set_sku('test-product-' . uniqid());
        $this->product->save();
    }

    public function tearDown(): void {
        // Clean up the test product
        if ($this->product && $this->product->get_id()) {
            wp_delete_post($this->product->get_id(), true);
        }

        parent::tearDown();
    }

    public function testRemoveHtmlTags() {
        $string = '<p>Hello World!</p>';
        $expectedOutput = 'Hello World!';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testKeepHtmlTags() {
        $string = '<p>Hello World!</p>';
        $expectedOutput = '<p>Hello World!</p>';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, false);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testReplaceSpecialCharacters() {
        $string = 'Hello &amp; World!';
        $expectedOutput = 'Hello & World!';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testEmptyString() {
        $string = '';
        $expectedOutput = '';
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    public function testNullString() {
        $string = null;
        $expectedOutput = null;
        $actualOutput = WC_Facebookcommerce_Utils::clean_string($string, true);
        $this->assertEquals($expectedOutput, $actualOutput);
    }

    	/**
	 * Test is_woocommerce_attribute_summary method with various inputs
	 */
	public function test_is_woocommerce_attribute_summary() {
		// Test cases that should be detected as attribute summaries
		$attribute_summaries = [
			'1: kids',
			'Size: Large',
			'Color: Red',
			'Size: Large, Color: Red',
			'pa_color: Blue',
			'age_group: adults',
			'1: kids, 2: summer',
			'Brand: Nike, Size: XL, Color: Black',
			'material: cotton',
			'gender: female',
			'123: test',
			'pa_size: medium',
		];

		foreach ($attribute_summaries as $summary) {
			$this->assertTrue(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($summary),
				"'{$summary}' should be detected as an attribute summary"
			);
		}

		// Test cases that should NOT be detected as attribute summaries
		$real_descriptions = [
			'This is a genuine product description.',
			'A high-quality item with excellent features.',
			'Features include comfort and style.',
			'Made with premium materials',
			'Perfect for kids and adults alike',
			'Available in multiple sizes',
			'',
			'Short description without colons',
			'This product has: great features', // Contains colon but not in attribute format
			'Description with, commas but no colons',
			'Multi-line description
			with line breaks',
			'Very long description that exceeds typical attribute length and contains various punctuation marks!',
		];

		foreach ($real_descriptions as $description) {
			$this->assertFalse(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($description),
				"'{$description}' should NOT be detected as an attribute summary"
			);
		}
	}

	/**
	 * Test is_woocommerce_attribute_summary with edge cases
	 */
	public function test_is_woocommerce_attribute_summary_edge_cases() {
		// Test empty and null values
		$this->assertFalse(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary(''));
		$this->assertFalse(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary(null));

		// Test whitespace handling
		$this->assertTrue(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('  Size: Large  '));
		$this->assertTrue(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary("\tColor: Red\n"));

		// Test complex attribute patterns
		$this->assertTrue(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('Size: Large, Material: Cotton, Color: Blue'));
		$this->assertTrue(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('pa_brand: Nike, pa_size: XL'));

		// Test borderline cases that should be detected
		$this->assertTrue(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('A: B'));
		$this->assertTrue(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('1: 2'));

		// Test cases that look like attributes but aren't
		$this->assertFalse(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('Time: 3:30 PM'));
		$this->assertFalse(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('Ratio: 1:2:3'));
		$this->assertFalse(\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary('URL: https://example.com'));
	}

	/**
	 * Test is_woocommerce_attribute_summary with numeric attribute names
	 */
	public function test_is_woocommerce_attribute_summary_numeric_attributes() {
		// These should be detected as numeric attribute summaries
		$numeric_attributes = [
			'1: kids',
			'2: adults',
			'123: test',
			'1: value, 2: another',
			'999: long_value_name',
		];

		foreach ($numeric_attributes as $attr) {
			$this->assertTrue(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($attr),
				"'{$attr}' should be detected as a numeric attribute summary"
			);
		}
	}

	/**
	 * Test is_woocommerce_attribute_summary with WooCommerce prefix patterns
	 */
	public function test_is_woocommerce_attribute_summary_pa_prefix() {
		// These should be detected as WooCommerce attribute patterns
		$pa_attributes = [
			'pa_color: red',
			'pa_size: large',
			'pa_material: cotton',
			'pa_brand: nike',
			'pa_123: value',
		];

		foreach ($pa_attributes as $attr) {
			$this->assertTrue(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($attr),
				"'{$attr}' should be detected as a pa_ attribute summary"
			);
		}
	}

	/**
	 * Test is_woocommerce_attribute_summary with common Facebook attribute names
	 */
	public function test_is_woocommerce_attribute_summary_common_names() {
		// These should be detected as common Facebook attribute patterns
		$common_attributes = [
			'size: large',
			'color: blue',
			'brand: nike',
			'material: cotton',
			'style: modern',
			'type: shirt',
			'gender: unisex',
			'age_group: adult',
			'SIZE: LARGE', // Test case insensitivity
			'Color: Red', // Test mixed case
		];

		foreach ($common_attributes as $attr) {
			$this->assertTrue(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($attr),
				"'{$attr}' should be detected as a common attribute summary"
			);
		}
	}

	/**
	 * Test is_woocommerce_attribute_summary with multiple attributes
	 */
	public function test_is_woocommerce_attribute_summary_multiple_attributes() {
		// These should be detected as multi-attribute summaries
		$multi_attributes = [
			'Size: Large, Color: Blue',
			'Brand: Nike, Size: XL, Color: Black',
			'1: kids, 2: summer, 3: outdoor',
			'pa_color: red, pa_size: medium',
			'material: cotton, color: blue, size: large',
			'type: shirt, gender: male, age_group: adult',
		];

		foreach ($multi_attributes as $attr) {
			$this->assertTrue(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($attr),
				"'{$attr}' should be detected as a multi-attribute summary"
			);
		}
	}

	/**
	 * Test is_woocommerce_attribute_summary integration with get_fb_short_description
	 */
	public function test_is_woocommerce_attribute_summary_integration() {
		// Create a variation product to test the integration
		$variable_product = WC_Helper_Product::create_variation_product();
		$variation = wc_get_product($variable_product->get_children()[0]);

		// Create a mock post object with attribute summary in post_excerpt
		$post_data = (object) [
			'post_excerpt' => '1: kids, Color: Red',
			'post_content' => 'Real product description',
			'post_title' => 'Test Product'
		];

		// Mock the get_post_data method to return our test data
		$fb_product = new class($variation) extends \WC_Facebook_Product {
			private $mock_post_data;

			public function set_mock_post_data($data) {
				$this->mock_post_data = $data;
			}

			public function get_post_data() {
				return $this->mock_post_data;
			}
		};

		$fb_product->set_mock_post_data($post_data);

		// Test that attribute summary is detected and skipped
		$short_description = $fb_product->get_fb_short_description();

		// Should not return the attribute summary
		$this->assertNotEquals('1: kids, Color: Red', $short_description);

		// Should return empty string since no valid short description found
		$this->assertEquals('', $short_description);

		// Test with a real description that shouldn't be detected as attribute summary
		$post_data->post_excerpt = 'This is a real product description with features.';
		$fb_product->set_mock_post_data($post_data);

		$short_description = $fb_product->get_fb_short_description();
		$this->assertEquals('This is a real product description with features.', $short_description);
	}

	/**
	 * Test is_woocommerce_attribute_summary with malformed patterns
	 */
	public function test_is_woocommerce_attribute_summary_malformed_patterns() {
		// These should NOT be detected as attribute summaries
		$malformed_patterns = [
			': value', // Missing attribute name
			'attribute :', // Missing value
			'attribute: ', // Empty value
			': ', // Both missing
			'no colon here',
			'multiple:colons:here',
			'Space Before: Colon',
			'attribute:value:extra', // Too many parts
		];

		foreach ($malformed_patterns as $pattern) {
			$this->assertFalse(
				\WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary($pattern),
				"'{$pattern}' should NOT be detected as an attribute summary"
			);
		}
	}

	/**
	 * Test get_product_category_ids returns array for valid product
	 */
	public function test_get_product_category_ids_returns_array_for_valid_product() {
		$result = \WC_Facebookcommerce_Utils::get_product_category_ids($this->product->get_id());
		$this->assertIsArray($result);
	}

	/**
	 * Test get_product_category_ids returns empty array for invalid product ID
	 */
	public function test_get_product_category_ids_returns_empty_array_for_invalid_product() {
		$result = \WC_Facebookcommerce_Utils::get_product_category_ids(999999999);
		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * Test get_product_category_ids returns category IDs for product with categories
	 */
	public function test_get_product_category_ids_returns_category_ids() {
		// Create a category
		$category_id = wp_insert_term('Test Category', 'product_cat');
		$category_id = $category_id['term_id'];

		// Assign category to product
		$this->product->set_category_ids([$category_id]);
		$this->product->save();

		$result = \WC_Facebookcommerce_Utils::get_product_category_ids($this->product->get_id());

		$this->assertIsArray($result);
		$this->assertContains($category_id, $result);

		// Cleanup
		wp_delete_term($category_id, 'product_cat');
	}

	// =========================================================================
	// Deferred Events Tests
	// Tests for the deferred events mechanism.
	// Supports both legacy (JS code string) and isolated execution (event data array) formats.
	// =========================================================================

	/**
	 * Test add_deferred_event stores event data array (isolated execution format).
	 */
	public function test_add_deferred_event_stores_event_data_array(): void {
		$this->reset_deferred_events();

		$event_data = array(
			'name'    => 'AddToCart',
			'params'  => array( 'content_ids' => array( '123' ) ),
			'method'  => 'track',
			'eventId' => 'deferred-event-id',
		);

		WC_Facebookcommerce_Utils::add_deferred_event( $event_data );
		WC_Facebookcommerce_Utils::save_deferred_events();

		$transient_key = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertIsArray( $deferred_events );
		$this->assertCount( 1, $deferred_events );
		$this->assertIsArray( $deferred_events[0] );
		$this->assertEquals( 'AddToCart', $deferred_events[0]['name'] );
		$this->assertEquals( 'deferred-event-id', $deferred_events[0]['eventId'] );

		// Cleanup
		delete_transient( $transient_key );
	}

	/**
	 * Test add_deferred_event stores JS code string (legacy format).
	 */
	public function test_add_deferred_event_stores_legacy_js_string(): void {
		$this->reset_deferred_events();

		$js_code = "/* WooCommerce Facebook Integration Event Tracking */\nfbq('track', 'AddToCart', {});";

		WC_Facebookcommerce_Utils::add_deferred_event( $js_code );
		WC_Facebookcommerce_Utils::save_deferred_events();

		$transient_key = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertIsArray( $deferred_events );
		$this->assertCount( 1, $deferred_events );
		$this->assertIsString( $deferred_events[0] );
		$this->assertStringContainsString( 'fbq', $deferred_events[0] );

		// Cleanup
		delete_transient( $transient_key );
	}

	/**
	 * Test add_deferred_event appends multiple events.
	 */
	public function test_add_deferred_event_appends_to_existing(): void {
		$this->reset_deferred_events();

		WC_Facebookcommerce_Utils::add_deferred_event( array(
			'name'   => 'AddToCart',
			'params' => array( 'id' => '1' ),
			'method' => 'track',
		) );
		WC_Facebookcommerce_Utils::add_deferred_event( array(
			'name'   => 'AddToCart',
			'params' => array( 'id' => '2' ),
			'method' => 'track',
		) );

		WC_Facebookcommerce_Utils::save_deferred_events();

		$transient_key = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertCount( 2, $deferred_events );

		// Cleanup
		delete_transient( $transient_key );
	}

	/**
	 * Test print_deferred_events handles isolated execution format (event data arrays).
	 */
	public function test_print_deferred_events_handles_isolated_format(): void {
		$this->reset_deferred_events();
		$this->reset_pixel_event_queue();
		$this->enable_isolated_pixel_execution_switch();

		$transient_key = $this->get_deferred_events_transient_key();

		// Store isolated execution format events (arrays)
		set_transient(
			$transient_key,
			array(
				array( 'name' => 'AddToCart', 'params' => array( 'id' => '1' ), 'method' => 'track' ),
				array( 'name' => 'ViewContent', 'params' => array( 'id' => '2' ), 'method' => 'track', 'eventId' => 'evt-123' ),
			),
			DAY_IN_SECONDS
		);

		WC_Facebookcommerce_Utils::print_deferred_events();

		// Check that events were added to WC_Facebookcommerce_Pixel::$event_queue
		$event_queue = $this->get_pixel_event_queue();

		$this->assertCount( 2, $event_queue );
		$this->assertEquals( 'AddToCart', $event_queue[0]['name'] );
		$this->assertEquals( 'ViewContent', $event_queue[1]['name'] );
		$this->assertEquals( 'evt-123', $event_queue[1]['eventId'] );

		// Transient should be deleted
		$this->assertFalse( get_transient( $transient_key ) );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	/**
	 * Test print_deferred_events handles legacy format (JS code strings).
	 */
	public function test_print_deferred_events_handles_legacy_format(): void {
		$this->reset_deferred_events();

		$transient_key = $this->get_deferred_events_transient_key();

		// Store legacy format events (strings)
		$js_code_1 = "fbq('track', 'AddToCart', {});";
		$js_code_2 = "fbq('track', 'ViewContent', {});";

		set_transient(
			$transient_key,
			array( $js_code_1, $js_code_2 ),
			DAY_IN_SECONDS
		);

		// Capture output
		ob_start();
		WC_Facebookcommerce_Utils::print_deferred_events();
		$output = ob_get_clean();

		// Should output a single script tag with both events combined
		$this->assertStringContainsString( '<script>', $output );
		$this->assertStringContainsString( '</script>', $output );
		$this->assertStringContainsString( $js_code_1, $output );
		$this->assertStringContainsString( $js_code_2, $output );

		// Transient should be deleted
		$this->assertFalse( get_transient( $transient_key ) );
	}

	/**
	 * Test print_deferred_events handles mixed formats (both arrays and strings).
	 */
	public function test_print_deferred_events_handles_mixed_formats(): void {
		$this->reset_deferred_events();
		$this->reset_pixel_event_queue();
		$this->enable_isolated_pixel_execution_switch();

		$transient_key = $this->get_deferred_events_transient_key();

		// Store mixed format events
		$js_code = "fbq('track', 'Purchase', {});";
		$event_array = array( 'name' => 'AddToCart', 'params' => array( 'id' => '1' ), 'method' => 'track' );

		set_transient(
			$transient_key,
			array( $js_code, $event_array ),
			DAY_IN_SECONDS
		);

		// Capture output
		ob_start();
		WC_Facebookcommerce_Utils::print_deferred_events();
		$output = ob_get_clean();

		// Legacy event should be in script output
		$this->assertStringContainsString( '<script>', $output );
		$this->assertStringContainsString( $js_code, $output );

		// Isolated event should be in event_queue
		$event_queue = $this->get_pixel_event_queue();
		$this->assertCount( 1, $event_queue );
		$this->assertEquals( 'AddToCart', $event_queue[0]['name'] );

		// Transient should be deleted
		$this->assertFalse( get_transient( $transient_key ) );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	/**
	 * Test print_deferred_events clears transient after loading.
	 */
	public function test_print_deferred_events_clears_transient(): void {
		$this->reset_deferred_events();

		$transient_key = $this->get_deferred_events_transient_key();

		set_transient(
			$transient_key,
			array( array( 'name' => 'AddToCart', 'params' => array(), 'method' => 'track' ) ),
			DAY_IN_SECONDS
		);

		WC_Facebookcommerce_Utils::print_deferred_events();

		// Transient should be deleted after loading
		$this->assertFalse( get_transient( $transient_key ) );
	}

	/**
	 * Test print_deferred_events handles empty transient gracefully.
	 */
	public function test_print_deferred_events_handles_empty_transient(): void {
		$this->reset_deferred_events();

		// Should not throw error with no deferred events
		WC_Facebookcommerce_Utils::print_deferred_events();

		$this->assertTrue( true ); // If we get here, no error was thrown
	}

	/**
	 * Test get_deferred_events_transient_key returns a string.
	 */
	public function test_get_deferred_events_transient_key_returns_string(): void {
		$key = $this->get_deferred_events_transient_key();
		$this->assertIsString( $key );
	}

	/**
	 * Test save_deferred_events merges with existing transient data.
	 */
	public function test_save_deferred_events_merges_with_existing(): void {
		$this->reset_deferred_events();

		$transient_key = $this->get_deferred_events_transient_key();

		// Set existing event in transient
		set_transient(
			$transient_key,
			array( array( 'name' => 'ViewContent', 'params' => array(), 'method' => 'track' ) ),
			DAY_IN_SECONDS
		);

		// Add new event
		WC_Facebookcommerce_Utils::add_deferred_event( array(
			'name'   => 'AddToCart',
			'params' => array(),
			'method' => 'track',
		) );
		WC_Facebookcommerce_Utils::save_deferred_events();

		$deferred_events = get_transient( $transient_key );

		// Should have both events
		$this->assertCount( 2, $deferred_events );

		// Cleanup
		delete_transient( $transient_key );
	}

	/**
	 * Test print_deferred_events uses default method when not specified.
	 */
	public function test_print_deferred_events_uses_default_method(): void {
		$this->reset_deferred_events();
		$this->reset_pixel_event_queue();
		$this->enable_isolated_pixel_execution_switch();

		$transient_key = $this->get_deferred_events_transient_key();

		// Store event without method specified
		set_transient(
			$transient_key,
			array( array( 'name' => 'AddToCart', 'params' => array() ) ),
			DAY_IN_SECONDS
		);

		WC_Facebookcommerce_Utils::print_deferred_events();

		$event_queue = $this->get_pixel_event_queue();

		$this->assertCount( 1, $event_queue );
		$this->assertEquals( 'track', $event_queue[0]['method'] );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	/**
	 * Test print_deferred_events uses empty eventId when not specified.
	 */
	public function test_print_deferred_events_handles_missing_event_id(): void {
		$this->reset_deferred_events();
		$this->reset_pixel_event_queue();
		$this->enable_isolated_pixel_execution_switch();

		$transient_key = $this->get_deferred_events_transient_key();

		// Store event without eventId
		set_transient(
			$transient_key,
			array( array( 'name' => 'AddToCart', 'params' => array(), 'method' => 'track' ) ),
			DAY_IN_SECONDS
		);

		WC_Facebookcommerce_Utils::print_deferred_events();

		$event_queue = $this->get_pixel_event_queue();

		$this->assertCount( 1, $event_queue );
		// eventId should not be set when empty
		$this->assertArrayNotHasKey( 'eventId', $event_queue[0] );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	/**
	 * Helper to reset deferred events array.
	 */
	private function reset_deferred_events(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Utils::class );
		$property = $reflection->getProperty( 'deferred_events' );
		$property->setAccessible( true );
		$property->setValue( null, [] );
	}

	/**
	 * Helper to reset pixel event queue array.
	 */
	private function reset_pixel_event_queue(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$property = $reflection->getProperty( 'event_queue' );
		$property->setAccessible( true );
		$property->setValue( null, [] );

		// Also reset hooks_initialized
		$hooks_initialized = $reflection->getProperty( 'hooks_initialized' );
		$hooks_initialized->setAccessible( true );
		$hooks_initialized->setValue( null, false );
	}

	/**
	 * Regression: subtracting money in binary floating point leaves values a hair under the
	 * decimal they represent, and scaling before floor() turned that hair into a lost cent.
	 * 21.99 - 12.00 - 4.00 is 5.99, and used to be reported as 5.98.
	 *
	 * @dataProvider provider_truncate_float_number_representation_error
	 *
	 * @param float $value    Value to truncate.
	 * @param float $expected Expected result.
	 */
	public function test_truncate_float_number_is_not_defeated_by_representation_error( float $value, float $expected ): void {
		$this->assertSame( $expected, WC_Facebookcommerce_Utils::truncate_float_number( $value, 2 ) );
	}

	/**
	 * Sums that are exact in decimal but not in binary floating point.
	 *
	 * @return array<string, array{float, float}>
	 */
	public function provider_truncate_float_number_representation_error(): array {
		return array(
			'reported order: 21.99 total less 12.00 shipping and 4.00 cost' => array( 21.99 - 12.00 - 4.00, 5.99 ),
			'0.1 + 0.2'         => array( 0.1 + 0.2, 0.3 ),
			'1.00 - 0.42'       => array( 1.00 - 0.42, 0.58 ),
			'100.10 - 50.05'    => array( 100.10 - 50.05, 50.05 ),
			'3 * 1.15'          => array( 3 * 1.15, 3.45 ),
		);
	}

	/**
	 * Digits the caller genuinely asked to drop are still dropped: the fix corrects the
	 * scaling artifact, it does not turn truncation into rounding.
	 *
	 * @dataProvider provider_truncate_float_number_truncates
	 *
	 * @param float $value    Value to truncate.
	 * @param int   $points   Decimal places to keep.
	 * @param float $expected Expected result.
	 */
	public function test_truncate_float_number_still_truncates( float $value, int $points, float $expected ): void {
		$this->assertSame( $expected, WC_Facebookcommerce_Utils::truncate_float_number( $value, $points ) );
	}

	/**
	 * Values with real precision beyond the requested points.
	 *
	 * @return array<string, array{float, int, float}>
	 */
	public function provider_truncate_float_number_truncates(): array {
		return array(
			'halfway is not rounded up'   => array( 5.985, 2, 5.98 ),
			'well past halfway stays put' => array( 5.999, 2, 5.99 ),
			'exact value is untouched'    => array( 5.99, 2, 5.99 ),
			'whole number is untouched'   => array( 100.0, 2, 100.0 ),
			'zero points'                 => array( 5.99, 0, 5.0 ),
			'four points'                 => array( 5.99999, 4, 5.9999 ),
		);
	}

	/**
	 * Helper to get pixel event queue via reflection.
	 */
	private function get_pixel_event_queue(): array {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$property = $reflection->getProperty( 'event_queue' );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/**
	 * Helper to get the deferred events transient key.
	 */
	private function get_deferred_events_transient_key(): string {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Utils::class );
		$method = $reflection->getMethod( 'get_deferred_events_transient_key' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}

	/**
	 * A repeat call returns the same variation IDs as the first one.
	 *
	 * When the query result comes from the cache, core returns the 'id=>parent' map keyed
	 * 'post_parent:<ID>', so the second call in a request returned those strings in place of the
	 * variation IDs.
	 */
	public function test_get_all_product_ids_for_sync_returns_variation_ids_on_a_repeat_call(): void {
		$variable_product = \WC_Helper_Product::create_variation_product();
		$variation_ids    = array_map( 'intval', $variable_product->get_children() );

		$first  = array_values( \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );
		$second = array_values( \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );

		$this->assertNotEmpty( $variation_ids );
		$this->assertSame( array(), array_values( array_diff( $variation_ids, $first ) ), 'The first call returns every variation.' );
		$this->assertContainsOnly( 'int', $second, true, 'A repeat call returns IDs, not cache keys.' );
		$this->assertSame( $first, $second, 'A repeat call returns the same IDs.' );
		$this->assertNotContains( $variable_product->get_id(), $second, 'Variable parents are not product items.' );
	}

	/**
	 * A repeat call on a store without variations returns the same products, with no error.
	 *
	 * A cached empty result leaves the query's posts unset.
	 */
	public function test_get_all_product_ids_for_sync_handles_a_repeat_call_without_variations(): void {
		$first  = array_values( \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );
		$second = array_values( \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() );

		$this->assertContains( $this->product->get_id(), $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * Enable the isolated pixel execution rollout switch.
	 */
	private function enable_isolated_pixel_execution_switch(): void {
		$options = get_option( 'wc_facebook_for_woocommerce_rollout_switches', array() );
		$options[ \WooCommerce\Facebook\RolloutSwitches::SWITCH_ISOLATED_PIXEL_EXECUTION_ENABLED ] = 'yes';
		update_option( 'wc_facebook_for_woocommerce_rollout_switches', $options );
	}

	/**
	 * Disable the isolated pixel execution rollout switch.
	 */
	private function disable_isolated_pixel_execution_switch(): void {
		$options = get_option( 'wc_facebook_for_woocommerce_rollout_switches', array() );
		$options[ \WooCommerce\Facebook\RolloutSwitches::SWITCH_ISOLATED_PIXEL_EXECUTION_ENABLED ] = 'no';
		update_option( 'wc_facebook_for_woocommerce_rollout_switches', $options );
	}
}
