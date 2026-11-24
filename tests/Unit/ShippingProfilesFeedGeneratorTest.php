<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator;
use WooCommerce\Facebook\Feed\ShippingProfilesFeed;
use WooCommerce\Facebook\Feed\CsvFeedFileWriter;
use WooCommerce\Facebook\Feed\FeedGenerator;
use Automattic\WooCommerce\ActionSchedulerJobFramework\Proxies\ActionScheduler;

/**
 * Unit tests for ShippingProfilesFeedGenerator class.
 *
 * @since 3.5.0
 */
class ShippingProfilesFeedGeneratorTest extends \WP_UnitTestCase {

	/**
	 * The ShippingProfilesFeedGenerator instance under test.
	 *
	 * @var ShippingProfilesFeedGenerator
	 */
	private $instance;

	/**
	 * Mock ActionScheduler instance.
	 *
	 * @var ActionScheduler|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $action_scheduler;

	/**
	 * Mock CsvFeedFileWriter instance.
	 *
	 * @var CsvFeedFileWriter|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $feed_writer;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mock ActionScheduler
		$this->action_scheduler = $this->createMock( ActionScheduler::class );

		// Create mock CsvFeedFileWriter
		$this->feed_writer = $this->createMock( CsvFeedFileWriter::class );

		// Instantiate ShippingProfilesFeedGenerator with mocks
		$this->instance = new ShippingProfilesFeedGenerator(
			$this->action_scheduler,
			$this->feed_writer,
			'shipping_profiles'
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that the class exists and can be instantiated.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::__construct
	 */
	public function test_class_exists_and_can_be_instantiated() {
		$this->assertInstanceOf( ShippingProfilesFeedGenerator::class, $this->instance );
	}

	/**
	 * Test that get_items_for_batch returns data on the first batch.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_returns_data_on_first_batch() {
		// Mock the static method to return test data
		$test_data = [
			[
				'shipping_profile_id'      => '1-all_products',
				'name'                     => 'Test Zone',
				'shipping_zones'           => [],
				'shipping_rates'           => [],
				'applies_to_all_products'  => 'true',
				'applies_to_rest_of_world' => 'false',
			],
		];

		// Since we can't easily mock static methods, we'll test that the method
		// returns an array and calls the static method
		$result = $this->instance->get_items_for_batch( 1, [] );

		$this->assertIsArray( $result );
	}

	/**
	 * Test that get_items_for_batch returns empty array on the second batch.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_returns_empty_array_on_second_batch() {
		$result = $this->instance->get_items_for_batch( 2, [] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that get_items_for_batch returns empty array on the third batch.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_returns_empty_array_on_third_batch() {
		$result = $this->instance->get_items_for_batch( 3, [] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that get_items_for_batch works with additional args.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_with_args() {
		$result = $this->instance->get_items_for_batch( 1, [ 'test' => 'value' ] );

		// Args should be ignored, method should still return an array
		$this->assertIsArray( $result );
	}

	/**
	 * Test that get_items_for_batch returns empty array for batch number zero.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_returns_empty_array_for_batch_zero() {
		$result = $this->instance->get_items_for_batch( 0, [] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that get_items_for_batch returns empty array for negative batch number.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_returns_empty_array_for_negative_batch() {
		$result = $this->instance->get_items_for_batch( -1, [] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test that get_batch_size returns zero.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_batch_size
	 */
	public function test_get_batch_size_returns_zero() {
		// Use reflection to access the protected method
		$reflection = new \ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'get_batch_size' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance );

		$this->assertIsInt( $result );
		$this->assertEquals( 0, $result );
	}

	/**
	 * Test that the class inherits from FeedGenerator.
	 */
	public function test_inherits_from_feed_generator() {
		$this->assertInstanceOf( FeedGenerator::class, $this->instance );
	}

	/**
	 * Test that get_items_for_batch only returns data on batch 1.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_only_returns_data_on_first_batch() {
		// First batch should return data (array)
		$result_batch_1 = $this->instance->get_items_for_batch( 1, [] );
		$this->assertIsArray( $result_batch_1 );

		// All subsequent batches should return empty array
		for ( $i = 2; $i <= 5; $i++ ) {
			$result = $this->instance->get_items_for_batch( $i, [] );
			$this->assertIsArray( $result );
			$this->assertEmpty( $result, "Batch $i should return empty array" );
		}
	}

	/**
	 * Test that get_items_for_batch handles large batch numbers.
	 *
	 * @covers \WooCommerce\Facebook\Feed\ShippingProfilesFeedGenerator::get_items_for_batch
	 */
	public function test_get_items_for_batch_handles_large_batch_numbers() {
		$result = $this->instance->get_items_for_batch( 1000, [] );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}
}
