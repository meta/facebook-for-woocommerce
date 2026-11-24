<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\FeedGenerator;
use WooCommerce\Facebook\Feed\AbstractFeedFileWriter;
use WooCommerce\Facebook\Feed\AbstractFeed;
use Automattic\WooCommerce\ActionSchedulerJobFramework\Proxies\ActionSchedulerInterface;
use WC_Facebookcommerce;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;
use PHPUnit\Framework\MockObject\MockObject;
use Exception;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for FeedGenerator class.
 *
 * @covers \WooCommerce\Facebook\Feed\FeedGenerator
 * @since 3.5.0
 */
class FeedGeneratorTest extends AbstractWPUnitTestWithSafeFiltering {

	/**
	 * Mock ActionSchedulerInterface instance.
	 *
	 * @var MockObject|ActionSchedulerInterface
	 */
	private $mock_action_scheduler;

	/**
	 * Mock AbstractFeedFileWriter instance.
	 *
	 * @var MockObject|AbstractFeedFileWriter
	 */
	private $mock_feed_writer;

	/**
	 * FeedGenerator instance under test.
	 *
	 * @var FeedGenerator
	 */
	private $feed_generator;

	/**
	 * Test feed name.
	 *
	 * @var string
	 */
	private $feed_name;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mock for ActionSchedulerInterface
		$this->mock_action_scheduler = $this->createMock( ActionSchedulerInterface::class );

		// Create mock for AbstractFeedFileWriter
		$this->mock_feed_writer = $this->createMock( AbstractFeedFileWriter::class );

		// Set test feed name
		$this->feed_name = 'test_feed';

		// Instantiate FeedGenerator with mocks
		$this->feed_generator = new FeedGenerator(
			$this->mock_action_scheduler,
			$this->mock_feed_writer,
			$this->feed_name
		);
	}

	/**
	 * Tear down test fixtures.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that constructor properly sets feed_writer and feed_name properties.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::__construct
	 * @return void
	 */
	public function test_constructor_sets_properties() {
		$reflection = new ReflectionClass( $this->feed_generator );

		// Test feed_writer property
		$feed_writer_property = $reflection->getProperty( 'feed_writer' );
		$feed_writer_property->setAccessible( true );
		$this->assertSame( $this->mock_feed_writer, $feed_writer_property->getValue( $this->feed_generator ) );

		// Test feed_name property
		$feed_name_property = $reflection->getProperty( 'feed_name' );
		$feed_name_property->setAccessible( true );
		$this->assertEquals( 'test_feed', $feed_name_property->getValue( $this->feed_generator ) );
	}

	/**
	 * Test that handle_start creates directory and prepares temporary file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_start
	 * @return void
	 */
	public function test_handle_start_creates_directory_and_prepares_file() {
		// Expect create_files_to_protect_feed_directory to be called once
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'create_files_to_protect_feed_directory' );

		// Expect prepare_temporary_feed_file to be called once
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'prepare_temporary_feed_file' );

		// Use reflection to call protected handle_start method
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'handle_start' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator );
	}

	/**
	 * Test that handle_end promotes file and triggers action.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_end
	 * @return void
	 */
	public function test_handle_end_promotes_file_and_triggers_action() {
		// Expect promote_temp_file to be called once
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'promote_temp_file' );

		// Track if action was triggered
		$action_triggered = false;
		$action_name = AbstractFeed::FEED_GEN_COMPLETE_ACTION . $this->feed_name;

		// Add action hook listener
		$filter = $this->add_filter_with_safe_teardown(
			$action_name,
			function() use ( &$action_triggered ) {
				$action_triggered = true;
			}
		);

		// Use reflection to call protected handle_end method
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'handle_end' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator );

		// Verify action was triggered
		$this->assertTrue( $action_triggered, 'Feed generation complete action should be triggered' );

		// Clean up filter
		$filter->teardown_safely_immediately();
	}

	/**
	 * Test that get_items_for_batch returns empty array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_items_for_batch
	 * @return void
	 */
	public function test_get_items_for_batch_returns_empty_array() {
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'get_items_for_batch' );
		$method->setAccessible( true );

		// Test with batch number 1
		$result = $method->invoke( $this->feed_generator, 1, array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_items_for_batch with different batch numbers.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_items_for_batch
	 * @return void
	 */
	public function test_get_items_for_batch_with_different_batch_numbers() {
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'get_items_for_batch' );
		$method->setAccessible( true );

		$batch_numbers = array( 0, 1, 5, 10 );

		foreach ( $batch_numbers as $batch_number ) {
			$result = $method->invoke( $this->feed_generator, $batch_number, array() );
			$this->assertIsArray( $result, "Result should be array for batch number {$batch_number}" );
			$this->assertEmpty( $result, "Result should be empty for batch number {$batch_number}" );
		}
	}

	/**
	 * Test that process_items calls feed_writer write_temp_feed_file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::process_items
	 * @return void
	 */
	public function test_process_items_calls_feed_writer() {
		// Create test items array
		$items = array(
			array( 'id' => 1, 'name' => 'Product 1' ),
			array( 'id' => 2, 'name' => 'Product 2' ),
			array( 'id' => 3, 'name' => 'Product 3' ),
		);

		// Expect write_temp_feed_file to be called once with items
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'write_temp_feed_file' )
			->with( $items );

		// Use reflection to call protected process_items method
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'process_items' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator, $items, array() );
	}

	/**
	 * Test process_items with empty array.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::process_items
	 * @return void
	 */
	public function test_process_items_with_empty_array() {
		// Expect write_temp_feed_file to be called once with empty array
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'write_temp_feed_file' )
			->with( array() );

		// Use reflection to call protected process_items method
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'process_items' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator, array(), array() );
	}

	/**
	 * Test that process_item does nothing (empty implementation).
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::process_item
	 * @return void
	 */
	public function test_process_item_does_nothing() {
		// Create a test item object
		$item = (object) array( 'id' => 1, 'name' => 'Test Item' );

		// Use reflection to call protected process_item method
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'process_item' );
		$method->setAccessible( true );

		// Method should execute without errors (no assertions needed as method is empty)
		$method->invoke( $this->feed_generator, $item, array() );

		// If we reach here without exception, test passes
		$this->assertTrue( true );
	}

	/**
	 * Test that get_name returns correct format.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_name
	 * @return void
	 */
	public function test_get_name_returns_correct_format() {
		$result = $this->feed_generator->get_name();
		$this->assertEquals( 'test_feed_feed_generator', $result );
	}

	/**
	 * Test get_name with different feed names.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_name
	 * @return void
	 */
	public function test_get_name_with_different_feed_names() {
		$test_cases = array(
			'product' => 'product_feed_generator',
			'inventory' => 'inventory_feed_generator',
			'example' => 'example_feed_generator',
		);

		foreach ( $test_cases as $feed_name => $expected ) {
			$generator = new FeedGenerator(
				$this->mock_action_scheduler,
				$this->mock_feed_writer,
				$feed_name
			);

			$result = $generator->get_name();
			$this->assertEquals( $expected, $result, "Feed name should be correct for '{$feed_name}'" );
		}
	}

	/**
	 * Test that get_plugin_name returns plugin ID.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_plugin_name
	 * @return void
	 */
	public function test_get_plugin_name_returns_plugin_id() {
		$result = $this->feed_generator->get_plugin_name();
		$this->assertEquals( WC_Facebookcommerce::PLUGIN_ID, $result );
	}

	/**
	 * Test that get_batch_size returns one.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_batch_size
	 * @return void
	 */
	public function test_get_batch_size_returns_one() {
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'get_batch_size' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->feed_generator );
		$this->assertEquals( 1, $result );
		$this->assertIsInt( $result );
	}

	/**
	 * Test handle_start with exception from create_files_to_protect_feed_directory.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_start
	 * @return void
	 */
	public function test_handle_start_with_exception_from_create_files() {
		// Mock feed_writer to throw exception
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'create_files_to_protect_feed_directory' )
			->willThrowException( new Exception( 'Failed to create directory' ) );

		// Expect exception to be thrown
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to create directory' );

		// Use reflection to call handle_start
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'handle_start' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator );
	}

	/**
	 * Test handle_start with exception from prepare_temporary_feed_file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_start
	 * @return void
	 */
	public function test_handle_start_with_exception_from_prepare_file() {
		// Mock create_files_to_protect_feed_directory to succeed
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'create_files_to_protect_feed_directory' );

		// Mock prepare_temporary_feed_file to throw exception
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'prepare_temporary_feed_file' )
			->willThrowException( new Exception( 'Failed to prepare file' ) );

		// Expect exception to be thrown
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to prepare file' );

		// Use reflection to call handle_start
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'handle_start' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator );
	}

	/**
	 * Test handle_end with exception from promote_temp_file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_end
	 * @return void
	 */
	public function test_handle_end_with_exception() {
		// Mock promote_temp_file to throw exception
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'promote_temp_file' )
			->willThrowException( new Exception( 'Failed to promote file' ) );

		// Expect exception to be thrown
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to promote file' );

		// Use reflection to call handle_end
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'handle_end' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator );
	}

	/**
	 * Test process_items with exception from write_temp_feed_file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::process_items
	 * @return void
	 */
	public function test_process_items_with_exception() {
		$items = array( array( 'id' => 1 ) );

		// Mock write_temp_feed_file to throw exception
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'write_temp_feed_file' )
			->with( $items )
			->willThrowException( new Exception( 'Failed to write file' ) );

		// Expect exception to be thrown
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Failed to write file' );

		// Use reflection to call process_items
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'process_items' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator, $items, array() );
	}

	/**
	 * Test that action hook is triggered with correct feed name.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_end
	 * @return void
	 */
	public function test_action_hook_triggered_with_correct_feed_name() {
		// Create FeedGenerator with custom feed name
		$custom_feed_name = 'custom_feed';
		$generator = new FeedGenerator(
			$this->mock_action_scheduler,
			$this->mock_feed_writer,
			$custom_feed_name
		);

		// Track if action was triggered
		$action_triggered = false;
		$action_name = AbstractFeed::FEED_GEN_COMPLETE_ACTION . $custom_feed_name;

		// Add action hook listener
		$filter = $this->add_filter_with_safe_teardown(
			$action_name,
			function() use ( &$action_triggered ) {
				$action_triggered = true;
			}
		);

		// Mock promote_temp_file
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'promote_temp_file' );

		// Use reflection to call handle_end
		$reflection = new ReflectionClass( $generator );
		$method = $reflection->getMethod( 'handle_end' );
		$method->setAccessible( true );
		$method->invoke( $generator );

		// Verify action was triggered
		$this->assertTrue( $action_triggered, "Action '{$action_name}' should be triggered" );

		// Clean up filter
		$filter->teardown_safely_immediately();
	}

	/**
	 * Test that constructor accepts ActionSchedulerInterface.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::__construct
	 * @return void
	 */
	public function test_constructor_accepts_action_scheduler_interface() {
		$mock_scheduler = $this->createMock( ActionSchedulerInterface::class );
		$mock_writer = $this->createMock( AbstractFeedFileWriter::class );

		$generator = new FeedGenerator( $mock_scheduler, $mock_writer, 'test' );

		$this->assertInstanceOf( FeedGenerator::class, $generator );
	}

	/**
	 * Test that feed_name property is accessible via get_name.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::get_name
	 * @return void
	 */
	public function test_feed_name_accessible_via_get_name() {
		$feed_names = array( 'product', 'inventory', 'example', 'test_123', 'my-feed' );

		foreach ( $feed_names as $feed_name ) {
			$generator = new FeedGenerator(
				$this->mock_action_scheduler,
				$this->mock_feed_writer,
				$feed_name
			);

			$expected = $feed_name . '_feed_generator';
			$this->assertEquals( $expected, $generator->get_name() );
		}
	}

	/**
	 * Test process_items with various item structures.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::process_items
	 * @return void
	 */
	public function test_process_items_with_various_structures() {
		$test_cases = array(
			'simple_array' => array( 1, 2, 3 ),
			'associative_array' => array( 'key1' => 'value1', 'key2' => 'value2' ),
			'nested_array' => array(
				array( 'id' => 1, 'data' => array( 'nested' => true ) ),
			),
			'mixed_types' => array( 1, 'string', true, null ),
		);

		foreach ( $test_cases as $case_name => $items ) {
			// Create new mock for each test case
			$mock_writer = $this->createMock( AbstractFeedFileWriter::class );
			$mock_writer->expects( $this->once() )
				->method( 'write_temp_feed_file' )
				->with( $items );

			$generator = new FeedGenerator(
				$this->mock_action_scheduler,
				$mock_writer,
				'test'
			);

			$reflection = new ReflectionClass( $generator );
			$method = $reflection->getMethod( 'process_items' );
			$method->setAccessible( true );
			$method->invoke( $generator, $items, array() );
		}
	}

	/**
	 * Test that handle_end triggers action after promoting file.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedGenerator::handle_end
	 * @return void
	 */
	public function test_handle_end_action_triggered_after_promote() {
		$promote_called = false;
		$action_triggered = false;
		$action_name = AbstractFeed::FEED_GEN_COMPLETE_ACTION . $this->feed_name;

		// Mock promote_temp_file to track when it's called
		$this->mock_feed_writer->expects( $this->once() )
			->method( 'promote_temp_file' )
			->willReturnCallback( function() use ( &$promote_called, &$action_triggered ) {
				$promote_called = true;
				// Action should not be triggered yet
				$this->assertFalse( $action_triggered, 'Action should not be triggered before promote completes' );
			} );

		// Add action hook listener
		$filter = $this->add_filter_with_safe_teardown(
			$action_name,
			function() use ( &$action_triggered, &$promote_called ) {
				$action_triggered = true;
				// Promote should have been called first
				$this->assertTrue( $promote_called, 'Promote should be called before action is triggered' );
			}
		);

		// Use reflection to call handle_end
		$reflection = new ReflectionClass( $this->feed_generator );
		$method = $reflection->getMethod( 'handle_end' );
		$method->setAccessible( true );
		$method->invoke( $this->feed_generator );

		// Verify both were called
		$this->assertTrue( $promote_called, 'Promote should be called' );
		$this->assertTrue( $action_triggered, 'Action should be triggered' );

		// Clean up filter
		$filter->teardown_safely_immediately();
	}
}
