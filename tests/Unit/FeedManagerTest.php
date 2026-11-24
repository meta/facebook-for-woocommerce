<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\FeedManager;
use WooCommerce\Facebook\Feed\AbstractFeed;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for FeedManager class.
 *
 * @package WooCommerce\Facebook\Tests\Unit
 * @since 3.5.0
 */
class FeedManagerTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * FeedManager instance for testing.
	 *
	 * @var FeedManager
	 */
	private $instance;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->instance = new FeedManager();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that the FeedManager class exists and can be instantiated.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::__construct
	 */
	public function test_class_exists_and_can_be_instantiated() {
		$this->assertTrue( class_exists( FeedManager::class ) );
		$this->assertInstanceOf( FeedManager::class, $this->instance );
	}

	/**
	 * Test that get_active_feed_types returns all feed types.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_active_feed_types
	 */
	public function test_get_active_feed_types() {
		$feed_types = FeedManager::get_active_feed_types();

		$this->assertIsArray( $feed_types );
		$this->assertCount( 4, $feed_types );
		$this->assertContains( FeedManager::PROMOTIONS, $feed_types );
		$this->assertContains( FeedManager::RATINGS_AND_REVIEWS, $feed_types );
		$this->assertContains( FeedManager::SHIPPING_PROFILES, $feed_types );
		$this->assertContains( FeedManager::NAVIGATION_MENU, $feed_types );
	}

	/**
	 * Test that get_active_feed_types is a static method.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_active_feed_types
	 */
	public function test_get_active_feed_types_is_static() {
		$feed_types = FeedManager::get_active_feed_types();

		$this->assertIsArray( $feed_types );
		$this->assertNotEmpty( $feed_types );
	}

	/**
	 * Test that constructor creates all feed instances.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::__construct
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::create_feed
	 */
	public function test_constructor_creates_all_feed_instances() {
		$reflection = new \ReflectionClass( $this->instance );
		$property   = $reflection->getProperty( 'feed_instances' );
		$property->setAccessible( true );
		$feed_instances = $property->getValue( $this->instance );

		$this->assertIsArray( $feed_instances );
		$this->assertCount( 4, $feed_instances );

		// Verify each feed type has an instance
		$this->assertArrayHasKey( FeedManager::PROMOTIONS, $feed_instances );
		$this->assertArrayHasKey( FeedManager::RATINGS_AND_REVIEWS, $feed_instances );
		$this->assertArrayHasKey( FeedManager::SHIPPING_PROFILES, $feed_instances );
		$this->assertArrayHasKey( FeedManager::NAVIGATION_MENU, $feed_instances );

		// Verify each instance is of AbstractFeed type
		foreach ( $feed_instances as $feed_instance ) {
			$this->assertInstanceOf( AbstractFeed::class, $feed_instance );
		}
	}

	/**
	 * Test get_feed_instance with valid promotions feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_with_promotions_feed_type() {
		$feed = $this->instance->get_feed_instance( FeedManager::PROMOTIONS );

		$this->assertInstanceOf( AbstractFeed::class, $feed );
		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\PromotionsFeed::class, $feed );
	}

	/**
	 * Test get_feed_instance with valid ratings and reviews feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_with_ratings_and_reviews_feed_type() {
		$feed = $this->instance->get_feed_instance( FeedManager::RATINGS_AND_REVIEWS );

		$this->assertInstanceOf( AbstractFeed::class, $feed );
		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\RatingsAndReviewsFeed::class, $feed );
	}

	/**
	 * Test get_feed_instance with valid shipping profiles feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_with_shipping_profiles_feed_type() {
		$feed = $this->instance->get_feed_instance( FeedManager::SHIPPING_PROFILES );

		$this->assertInstanceOf( AbstractFeed::class, $feed );
		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\ShippingProfilesFeed::class, $feed );
	}

	/**
	 * Test get_feed_instance with valid navigation menu feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_with_navigation_menu_feed_type() {
		$feed = $this->instance->get_feed_instance( FeedManager::NAVIGATION_MENU );

		$this->assertInstanceOf( AbstractFeed::class, $feed );
		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\NavigationMenuFeed::class, $feed );
	}

	/**
	 * Test get_feed_instance with invalid feed type throws exception.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_with_invalid_feed_type() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Feed type invalid_feed does not exist.' );

		$this->instance->get_feed_instance( 'invalid_feed' );
	}

	/**
	 * Test get_feed_instance throws exception for nonexistent feed with empty string.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_throws_exception_for_empty_string() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Feed type  does not exist.' );

		$this->instance->get_feed_instance( '' );
	}

	/**
	 * Test get_feed_instance throws exception for nonexistent feed with random string.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_instance
	 */
	public function test_get_feed_instance_throws_exception_for_random_string() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Feed type random_nonexistent_feed does not exist.' );

		$this->instance->get_feed_instance( 'random_nonexistent_feed' );
	}

	/**
	 * Test run_all_feed_uploads calls regenerate_feed on all feed instances.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::run_all_feed_uploads
	 */
	public function test_run_all_feed_uploads() {
		// Create mock feed instances
		$mock_feed_1 = $this->getMockBuilder( AbstractFeed::class )
			->disableOriginalConstructor()
			->getMock();
		$mock_feed_1->expects( $this->once() )
			->method( 'regenerate_feed' );

		$mock_feed_2 = $this->getMockBuilder( AbstractFeed::class )
			->disableOriginalConstructor()
			->getMock();
		$mock_feed_2->expects( $this->once() )
			->method( 'regenerate_feed' );

		$mock_feed_3 = $this->getMockBuilder( AbstractFeed::class )
			->disableOriginalConstructor()
			->getMock();
		$mock_feed_3->expects( $this->once() )
			->method( 'regenerate_feed' );

		$mock_feed_4 = $this->getMockBuilder( AbstractFeed::class )
			->disableOriginalConstructor()
			->getMock();
		$mock_feed_4->expects( $this->once() )
			->method( 'regenerate_feed' );

		// Use reflection to replace feed_instances with mocks
		$reflection = new \ReflectionClass( $this->instance );
		$property   = $reflection->getProperty( 'feed_instances' );
		$property->setAccessible( true );
		$property->setValue(
			$this->instance,
			array(
				FeedManager::PROMOTIONS          => $mock_feed_1,
				FeedManager::RATINGS_AND_REVIEWS => $mock_feed_2,
				FeedManager::SHIPPING_PROFILES   => $mock_feed_3,
				FeedManager::NAVIGATION_MENU     => $mock_feed_4,
			)
		);

		// Call the method
		$this->instance->run_all_feed_uploads();
	}

	/**
	 * Test get_feed_secret for promotions feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_for_promotions() {
		$secret = $this->instance->get_feed_secret( FeedManager::PROMOTIONS );

		$this->assertIsString( $secret );
		$this->assertNotEmpty( $secret );
	}

	/**
	 * Test get_feed_secret for ratings and reviews feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_for_ratings_and_reviews() {
		$secret = $this->instance->get_feed_secret( FeedManager::RATINGS_AND_REVIEWS );

		$this->assertIsString( $secret );
		$this->assertNotEmpty( $secret );
	}

	/**
	 * Test get_feed_secret for shipping profiles feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_for_shipping_profiles() {
		$secret = $this->instance->get_feed_secret( FeedManager::SHIPPING_PROFILES );

		$this->assertIsString( $secret );
		$this->assertNotEmpty( $secret );
	}

	/**
	 * Test get_feed_secret for navigation menu feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_for_navigation_menu() {
		$secret = $this->instance->get_feed_secret( FeedManager::NAVIGATION_MENU );

		$this->assertIsString( $secret );
		$this->assertNotEmpty( $secret );
	}

	/**
	 * Test get_feed_secret returns same secret on multiple calls.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_returns_same_secret_on_multiple_calls() {
		$secret_1 = $this->instance->get_feed_secret( FeedManager::PROMOTIONS );
		$secret_2 = $this->instance->get_feed_secret( FeedManager::PROMOTIONS );

		$this->assertEquals( $secret_1, $secret_2 );
	}

	/**
	 * Test get_feed_secret creates secret if not exists.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_creates_secret_if_not_exists() {
		$feed_type = FeedManager::PROMOTIONS;

		// Get the secret (should create it)
		$secret = $this->instance->get_feed_secret( $feed_type );

		$this->assertIsString( $secret );
		$this->assertNotEmpty( $secret );

		// Verify subsequent calls return the same secret
		$secret_2 = $this->instance->get_feed_secret( $feed_type );
		$this->assertEquals( $secret, $secret_2 );
	}

	/**
	 * Test that PROMOTIONS constant is defined.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::PROMOTIONS
	 */
	public function test_promotions_constant_is_defined() {
		$this->assertTrue( defined( FeedManager::class . '::PROMOTIONS' ) );
		$this->assertEquals( 'promotions', FeedManager::PROMOTIONS );
	}

	/**
	 * Test that RATINGS_AND_REVIEWS constant is defined.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::RATINGS_AND_REVIEWS
	 */
	public function test_ratings_and_reviews_constant_is_defined() {
		$this->assertTrue( defined( FeedManager::class . '::RATINGS_AND_REVIEWS' ) );
		$this->assertEquals( 'ratings_and_reviews', FeedManager::RATINGS_AND_REVIEWS );
	}

	/**
	 * Test that SHIPPING_PROFILES constant is defined.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::SHIPPING_PROFILES
	 */
	public function test_shipping_profiles_constant_is_defined() {
		$this->assertTrue( defined( FeedManager::class . '::SHIPPING_PROFILES' ) );
		$this->assertEquals( 'shipping_profiles', FeedManager::SHIPPING_PROFILES );
	}

	/**
	 * Test that NAVIGATION_MENU constant is defined.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::NAVIGATION_MENU
	 */
	public function test_navigation_menu_constant_is_defined() {
		$this->assertTrue( defined( FeedManager::class . '::NAVIGATION_MENU' ) );
		$this->assertEquals( 'navigation_menu', FeedManager::NAVIGATION_MENU );
	}

	/**
	 * Test that all feed type constants have correct values.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::PROMOTIONS
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::RATINGS_AND_REVIEWS
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::SHIPPING_PROFILES
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::NAVIGATION_MENU
	 */
	public function test_all_feed_type_constants_have_correct_values() {
		$expected_constants = array(
			'PROMOTIONS'          => 'promotions',
			'RATINGS_AND_REVIEWS' => 'ratings_and_reviews',
			'SHIPPING_PROFILES'   => 'shipping_profiles',
			'NAVIGATION_MENU'     => 'navigation_menu',
		);

		foreach ( $expected_constants as $constant_name => $expected_value ) {
			$this->assertTrue(
				defined( FeedManager::class . '::' . $constant_name ),
				"Constant {$constant_name} should be defined"
			);
			$this->assertEquals(
				$expected_value,
				constant( FeedManager::class . '::' . $constant_name ),
				"Constant {$constant_name} should have value '{$expected_value}'"
			);
		}
	}

	/**
	 * Test that create_feed creates correct feed instance for promotions.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::create_feed
	 */
	public function test_create_feed_creates_promotions_feed() {
		$reflection = new \ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'create_feed' );
		$method->setAccessible( true );

		$feed = $method->invoke( $this->instance, FeedManager::PROMOTIONS );

		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\PromotionsFeed::class, $feed );
	}

	/**
	 * Test that create_feed creates correct feed instance for ratings and reviews.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::create_feed
	 */
	public function test_create_feed_creates_ratings_and_reviews_feed() {
		$reflection = new \ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'create_feed' );
		$method->setAccessible( true );

		$feed = $method->invoke( $this->instance, FeedManager::RATINGS_AND_REVIEWS );

		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\RatingsAndReviewsFeed::class, $feed );
	}

	/**
	 * Test that create_feed creates correct feed instance for shipping profiles.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::create_feed
	 */
	public function test_create_feed_creates_shipping_profiles_feed() {
		$reflection = new \ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'create_feed' );
		$method->setAccessible( true );

		$feed = $method->invoke( $this->instance, FeedManager::SHIPPING_PROFILES );

		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\ShippingProfilesFeed::class, $feed );
	}

	/**
	 * Test that create_feed creates correct feed instance for navigation menu.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::create_feed
	 */
	public function test_create_feed_creates_navigation_menu_feed() {
		$reflection = new \ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'create_feed' );
		$method->setAccessible( true );

		$feed = $method->invoke( $this->instance, FeedManager::NAVIGATION_MENU );

		$this->assertInstanceOf( \WooCommerce\Facebook\Feed\NavigationMenuFeed::class, $feed );
	}

	/**
	 * Test that create_feed throws exception for invalid feed type.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::create_feed
	 */
	public function test_create_feed_throws_exception_for_invalid_feed_type() {
		$reflection = new \ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'create_feed' );
		$method->setAccessible( true );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Invalid feed type invalid_type' );

		$method->invoke( $this->instance, 'invalid_type' );
	}

	/**
	 * Test that get_feed_secret delegates to feed instance.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_get_feed_secret_delegates_to_feed_instance() {
		$feed_type = FeedManager::PROMOTIONS;

		// Get secret from manager
		$secret_from_manager = $this->instance->get_feed_secret( $feed_type );

		// Get secret directly from feed instance
		$feed_instance        = $this->instance->get_feed_instance( $feed_type );
		$secret_from_instance = $feed_instance->get_feed_secret();

		// They should be the same
		$this->assertEquals( $secret_from_instance, $secret_from_manager );
	}

	/**
	 * Test that different feed types have different secrets.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_feed_secret
	 */
	public function test_different_feed_types_have_different_secrets() {
		$secret_promotions = $this->instance->get_feed_secret( FeedManager::PROMOTIONS );
		$secret_reviews    = $this->instance->get_feed_secret( FeedManager::RATINGS_AND_REVIEWS );
		$secret_shipping   = $this->instance->get_feed_secret( FeedManager::SHIPPING_PROFILES );
		$secret_menu       = $this->instance->get_feed_secret( FeedManager::NAVIGATION_MENU );

		// All secrets should be different
		$this->assertNotEquals( $secret_promotions, $secret_reviews );
		$this->assertNotEquals( $secret_promotions, $secret_shipping );
		$this->assertNotEquals( $secret_promotions, $secret_menu );
		$this->assertNotEquals( $secret_reviews, $secret_shipping );
		$this->assertNotEquals( $secret_reviews, $secret_menu );
		$this->assertNotEquals( $secret_shipping, $secret_menu );
	}

	/**
	 * Test that feed instances are properly initialized.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::__construct
	 */
	public function test_feed_instances_are_properly_initialized() {
		$reflection = new \ReflectionClass( $this->instance );
		$property   = $reflection->getProperty( 'feed_instances' );
		$property->setAccessible( true );
		$feed_instances = $property->getValue( $this->instance );

		// Verify all instances are not null
		foreach ( $feed_instances as $feed_type => $feed_instance ) {
			$this->assertNotNull( $feed_instance, "Feed instance for {$feed_type} should not be null" );
			$this->assertInstanceOf( AbstractFeed::class, $feed_instance, "Feed instance for {$feed_type} should be an AbstractFeed" );
		}
	}

	/**
	 * Test that get_active_feed_types returns array with correct count.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_active_feed_types
	 */
	public function test_get_active_feed_types_returns_correct_count() {
		$feed_types = FeedManager::get_active_feed_types();

		$this->assertCount( 4, $feed_types, 'Should return exactly 4 feed types' );
	}

	/**
	 * Test that get_active_feed_types returns unique values.
	 *
	 * @covers \WooCommerce\Facebook\Feed\FeedManager::get_active_feed_types
	 */
	public function test_get_active_feed_types_returns_unique_values() {
		$feed_types = FeedManager::get_active_feed_types();

		$unique_feed_types = array_unique( $feed_types );

		$this->assertEquals( $feed_types, $unique_feed_types, 'Feed types should be unique' );
	}
}
