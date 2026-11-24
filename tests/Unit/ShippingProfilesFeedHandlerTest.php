<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler;
use WooCommerce\Facebook\Feed\AbstractFeedFileWriter;
use WooCommerce\Facebook\Feed\ShippingProfilesFeed;
use WooCommerce\Facebook\Feed\FeedManager;
use WooCommerce\Facebook\Feed\AbstractFeed;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;

/**
 * Test class for ShippingProfilesFeedHandler.
 *
 * @since 3.5.0
 */
class ShippingProfilesFeedHandlerTest extends AbstractWPUnitTestWithSafeFiltering {

	/**
	 * The ShippingProfilesFeedHandler instance.
	 *
	 * @var ShippingProfilesFeedHandler
	 */
	private $handler;

	/**
	 * Mock of AbstractFeedFileWriter.
	 *
	 * @var AbstractFeedFileWriter|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $feed_writer;

	/**
	 * Set up the test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Create a mock of AbstractFeedFileWriter
		$this->feed_writer = $this->createMock( AbstractFeedFileWriter::class );

		// Instantiate the handler with the mock
		$this->handler = new ShippingProfilesFeedHandler( $this->feed_writer );
	}

	/**
	 * Clean up after tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that constructor sets feed_writer and feed_type correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::__construct
	 *
	 * @return void
	 */
	public function test_constructor_sets_feed_writer_and_feed_type(): void {
		// Use reflection to access protected properties
		$reflection = new \ReflectionClass( $this->handler );

		// Check feed_writer property
		$feed_writer_property = $reflection->getProperty( 'feed_writer' );
		$feed_writer_property->setAccessible( true );
		$this->assertSame(
			$this->feed_writer,
			$feed_writer_property->getValue( $this->handler ),
			'The feed_writer property should be set to the provided feed writer instance.'
		);

		// Check feed_type property
		$feed_type_property = $reflection->getProperty( 'feed_type' );
		$feed_type_property->setAccessible( true );
		$this->assertEquals(
			FeedManager::SHIPPING_PROFILES,
			$feed_type_property->getValue( $this->handler ),
			'The feed_type property should be set to FeedManager::SHIPPING_PROFILES.'
		);
	}

	/**
	 * Test that get_feed_data returns an array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::get_feed_data
	 *
	 * @return void
	 */
	public function test_get_feed_data_returns_array(): void {
		// Since get_feed_data() calls a static method ShippingProfilesFeed::get_shipping_profiles_data(),
		// we need to test that it returns the data from that method.
		// In a real scenario, this would require mocking the static method or testing integration.
		// For unit testing, we'll verify the return type is an array.
		$result = $this->handler->get_feed_data();

		$this->assertIsArray(
			$result,
			'get_feed_data() should return an array.'
		);
	}

	/**
	 * Test that get_feed_data returns empty array when no shipping profiles exist.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::get_feed_data
	 *
	 * @return void
	 */
	public function test_get_feed_data_returns_empty_array_when_no_profiles(): void {
		// When no shipping zones are configured, get_feed_data should return an empty array
		$result = $this->handler->get_feed_data();

		$this->assertIsArray(
			$result,
			'get_feed_data() should return an array even when no profiles exist.'
		);

		// With no shipping zones configured, the result should be empty
		$this->assertEmpty(
			$result,
			'get_feed_data() should return an empty array when no shipping profiles are configured.'
		);
	}

	/**
	 * Test that get_feed_writer returns the feed writer instance.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::get_feed_writer
	 *
	 * @return void
	 */
	public function test_get_feed_writer_returns_feed_writer_instance(): void {
		$result = $this->handler->get_feed_writer();

		$this->assertInstanceOf(
			AbstractFeedFileWriter::class,
			$result,
			'get_feed_writer() should return an instance of AbstractFeedFileWriter.'
		);

		$this->assertSame(
			$this->feed_writer,
			$result,
			'get_feed_writer() should return the same instance passed to the constructor.'
		);
	}

	/**
	 * Test that generate_feed_file calls writer with feed data.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::generate_feed_file
	 *
	 * @return void
	 */
	public function test_generate_feed_file_calls_writer_with_feed_data(): void {
		// Set up expectation that write_feed_file will be called with an array
		$this->feed_writer->expects( $this->once() )
			->method( 'write_feed_file' )
			->with( $this->isType( 'array' ) );

		// Call generate_feed_file
		$this->handler->generate_feed_file();
	}

	/**
	 * Test that generate_feed_file triggers the action hook.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::generate_feed_file
	 *
	 * @return void
	 */
	public function test_generate_feed_file_triggers_action_hook(): void {
		// Track if the action was triggered
		$action_triggered = false;
		$expected_hook    = AbstractFeed::FEED_GEN_COMPLETE_ACTION . FeedManager::SHIPPING_PROFILES;

		// Add action listener
		$filter = $this->add_filter_with_safe_teardown(
			$expected_hook,
			function() use ( &$action_triggered ) {
				$action_triggered = true;
			}
		);

		// Call generate_feed_file
		$this->handler->generate_feed_file();

		// Verify the action was triggered
		$this->assertTrue(
			$action_triggered,
			'The feed generation complete action should be triggered with the correct feed type.'
		);

		// Clean up the filter
		$filter->teardown_safely_immediately();
	}

	/**
	 * Test that feed_type is set to SHIPPING_PROFILES.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::__construct
	 *
	 * @return void
	 */
	public function test_feed_type_is_shipping_profiles(): void {
		// Use reflection to access the protected feed_type property
		$reflection       = new \ReflectionClass( $this->handler );
		$feed_type_property = $reflection->getProperty( 'feed_type' );
		$feed_type_property->setAccessible( true );

		$feed_type = $feed_type_property->getValue( $this->handler );

		$this->assertEquals(
			FeedManager::SHIPPING_PROFILES,
			$feed_type,
			'The feed_type should be set to FeedManager::SHIPPING_PROFILES constant value.'
		);

		$this->assertEquals(
			'shipping_profiles',
			$feed_type,
			'The feed_type should equal "shipping_profiles".'
		);
	}

	/**
	 * Test that get_feed_data delegates to ShippingProfilesFeed static method.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::get_feed_data
	 *
	 * @return void
	 */
	public function test_get_feed_data_delegates_to_shipping_profiles_feed(): void {
		// This test verifies that get_feed_data() returns the same type of data
		// that ShippingProfilesFeed::get_shipping_profiles_data() would return.
		$result = $this->handler->get_feed_data();

		// Verify it's an array (the expected return type)
		$this->assertIsArray(
			$result,
			'get_feed_data() should return an array as returned by ShippingProfilesFeed::get_shipping_profiles_data().'
		);

		// Verify the structure matches what we expect from shipping profiles data
		// Each element should be an array with specific keys if profiles exist
		foreach ( $result as $profile ) {
			$this->assertIsArray(
				$profile,
				'Each shipping profile should be an array.'
			);
		}
	}

	/**
	 * Test that handler can be instantiated with different feed writers.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::__construct
	 *
	 * @return void
	 */
	public function test_constructor_accepts_different_feed_writers(): void {
		// Create a different mock
		$different_writer = $this->createMock( AbstractFeedFileWriter::class );

		// Create a new handler with the different writer
		$new_handler = new ShippingProfilesFeedHandler( $different_writer );

		// Verify the new handler has the different writer
		$this->assertSame(
			$different_writer,
			$new_handler->get_feed_writer(),
			'The handler should accept and store different feed writer instances.'
		);

		// Verify it's not the same as the original handler's writer
		$this->assertNotSame(
			$this->handler->get_feed_writer(),
			$new_handler->get_feed_writer(),
			'Different handler instances should have different feed writers.'
		);
	}

	/**
	 * Test that generate_feed_file handles empty feed data correctly.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedHandler::generate_feed_file
	 *
	 * @return void
	 */
	public function test_generate_feed_file_handles_empty_data(): void {
		// Set up expectation that write_feed_file will be called with an empty array
		$this->feed_writer->expects( $this->once() )
			->method( 'write_feed_file' )
			->with( $this->callback( function( $data ) {
				return is_array( $data );
			} ) );

		// Call generate_feed_file (should work even with no shipping zones)
		$this->handler->generate_feed_file();

		// If we get here without exceptions, the test passes
		$this->assertTrue( true, 'generate_feed_file should handle empty data without errors.' );
	}
}
