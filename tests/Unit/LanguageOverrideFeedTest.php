<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed;
use WooCommerce\Facebook\Feed\Localization\LanguageFeedData;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for LanguageOverrideFeed class.
 *
 * @since 3.6.0
 */
class LanguageOverrideFeedTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var LanguageOverrideFeed */
	private $instance;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\WC_Facebookcommerce */
	private $plugin_mock;

	/** @var \PHPUnit\Framework\MockObject\MockObject */
	private $integration_mock;

	/** @var \PHPUnit\Framework\MockObject\MockObject */
	private $connection_handler_mock;

	/** @var \PHPUnit\Framework\MockObject\MockObject */
	private $api_mock;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->plugin_mock = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->getMock();

		$this->integration_mock = $this->getMockBuilder( \WC_Facebookcommerce_Integration::class )
			->disableOriginalConstructor()
			->getMock();

		$this->connection_handler_mock = $this->getMockBuilder( 'stdClass' )
			->addMethods( [ 'is_connected', 'get_commerce_partner_integration_id', 'get_commerce_merchant_settings_id', 'get_access_token' ] )
			->getMock();

		$this->api_mock = $this->getMockBuilder( \WooCommerce\Facebook\API::class )
			->disableOriginalConstructor()
			->getMock();

		$this->plugin_mock->method( 'get_integration' )->willReturn( $this->integration_mock );
		$this->plugin_mock->method( 'get_connection_handler' )->willReturn( $this->connection_handler_mock );
		$this->plugin_mock->method( 'get_api' )->willReturn( $this->api_mock );
		$this->plugin_mock->method( 'get_id_dasherized' )->willReturn( 'facebook-for-woocommerce' );

		$GLOBALS['wc_facebook_commerce'] = $this->plugin_mock;

		$this->instance = new LanguageOverrideFeed();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['wc_facebook_commerce'] );
		parent::tearDown();
	}

	/**
	 * Test that the class can be instantiated.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed::__construct
	 */
	public function test_class_exists_and_can_be_instantiated() {
		$this->assertInstanceOf( LanguageOverrideFeed::class, $this->instance );
	}

	/**
	 * Test should_skip_feed when language override is disabled.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed::should_skip_feed
	 */
	public function test_should_skip_feed_when_language_override_disabled() {
		$this->integration_mock->method( 'is_language_override_feed_generation_enabled' )->willReturn( false );

		$result = $this->instance->should_skip_feed();

		$this->assertTrue( $result );
	}

	/**
	 * Test should_skip_feed when no valid connection.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed::should_skip_feed
	 */
	public function test_should_skip_feed_when_no_valid_connection() {
		$this->integration_mock->method( 'is_language_override_feed_generation_enabled' )->willReturn( true );
		$this->connection_handler_mock->method( 'get_commerce_partner_integration_id' )->willReturn( '' );
		$this->connection_handler_mock->method( 'get_commerce_merchant_settings_id' )->willReturn( '' );
		$this->connection_handler_mock->method( 'get_access_token' )->willReturn( '' );

		$result = $this->instance->should_skip_feed();

		$this->assertTrue( $result );
	}

	/**
	 * Test get_language_feed_url generates correct URL.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed::get_language_feed_url
	 */
	public function test_get_language_feed_url() {
		$language_code = 'es_ES';
		$url = $this->instance->get_language_feed_url( $language_code );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'wc-api=wc_facebook_get_feed_data_language_override', $url );
		$this->assertStringContainsString( 'language=es_ES', $url );
		$this->assertStringContainsString( 'secret=', $url );
	}

	/**
	 * Test generate_language_feed_filename returns correct format.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_filename
	 */
	public function test_generate_language_feed_filename() {
		$language_code = 'es_ES';
		$filename = LanguageOverrideFeed::generate_language_feed_filename( $language_code );

		$this->assertIsString( $filename );
		$this->assertStringContainsString( 'facebook_language_feed_', $filename );
		$this->assertStringContainsString( 'es_ES', $filename );
		$this->assertStringContainsString( '.csv', $filename );
	}

	/**
	 * Test generate_language_feed_name returns correct format.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageFeedManagementTrait::generate_language_feed_name
	 */
	public function test_generate_language_feed_name() {
		$language_code = 'de_DE';
		$feed_name = LanguageOverrideFeed::generate_language_feed_name( $language_code );

		$this->assertEquals( 'WooCommerce Language Override Feed (DE_DE)', $feed_name );
	}

	/**
	 * Test that language feed data is lazy-loaded.
	 *
	 * @covers \WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed::get_language_feed_data
	 */
	public function test_language_feed_data_lazy_loading() {
		$reflection = new \ReflectionClass( $this->instance );
		$property = $reflection->getProperty( 'language_feed_data' );
		$property->setAccessible( true );

		$this->assertNull( $property->getValue( $this->instance ) );

		$method = $reflection->getMethod( 'get_language_feed_data' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->instance );

		$this->assertInstanceOf( LanguageFeedData::class, $result );
		$this->assertInstanceOf( LanguageFeedData::class, $property->getValue( $this->instance ) );
	}
}
