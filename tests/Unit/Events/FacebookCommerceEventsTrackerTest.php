<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Events;

use WooCommerce\Facebook\Events\AAMSettings;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;
use WC_Facebookcommerce_EventsTracker;
use ReflectionClass;

/**
 * Unit tests for WC_Facebookcommerce_EventsTracker class.
 *
 * Tests the Facebook Pixel events tracking functionality.
 *
 * @covers WC_Facebookcommerce_EventsTracker
 */
class FacebookCommerceEventsTrackerTest extends AbstractWPUnitTestWithSafeFiltering {

	/**
	 * @var WC_Facebookcommerce_EventsTracker|null
	 */
	private $instance;

	/**
	 * @var AAMSettings|null
	 */
	private $aam_settings;

	/**
	 * @var string|null Original HTTP_USER_AGENT value to restore after each test.
	 */
	private $original_user_agent;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

		// Set a default browser User-Agent so tests are not blocked by crawler detection.
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		$this->aam_settings = new AAMSettings( array(
			'enableAutomaticMatching'        => true,
			'enabledAutomaticMatchingFields' => array( 'em', 'fn', 'ln', 'ph', 'ct', 'st', 'zp', 'country' ),
			'pixelId'                        => 'test_pixel_123',
		) );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		$this->instance     = null;
		$this->aam_settings = null;

		// Restore original User-Agent.
		if ( null === $this->original_user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->original_user_agent;
		}

		parent::tearDown();
	}

	/**
	 * Create an instance of the events tracker with pixel enabled.
	 *
	 * @return WC_Facebookcommerce_EventsTracker
	 */
	private function create_tracker_with_pixel_enabled(): WC_Facebookcommerce_EventsTracker {
		$filter = $this->add_filter_with_safe_teardown(
			'facebook_for_woocommerce_integration_pixel_enabled',
			function() {
				return true;
			}
		);

		$tracker = new WC_Facebookcommerce_EventsTracker( array(), $this->aam_settings );

		return $tracker;
	}

	/**
	 * Remove all purchase-related hooks registered by the tracker constructor.
	 * Call this before creating orders to prevent hooks from firing during order creation.
	 */
	private function remove_purchase_hooks(): void {
		remove_action( 'woocommerce_new_order', array( $this->instance, 'inject_purchase_event' ), 10 );
		remove_action( 'woocommerce_process_shop_order_meta', array( $this->instance, 'inject_purchase_event' ), 20 );
		remove_action( 'woocommerce_checkout_update_order_meta', array( $this->instance, 'inject_purchase_event' ), 30 );
		remove_action( 'woocommerce_thankyou', array( $this->instance, 'inject_purchase_event' ), 40 );
	}

	/**
	 * Create an instance of the events tracker with pixel disabled.
	 *
	 * @return WC_Facebookcommerce_EventsTracker
	 */
	private function create_tracker_with_pixel_disabled(): WC_Facebookcommerce_EventsTracker {
		$filter = $this->add_filter_with_safe_teardown(
			'facebook_for_woocommerce_integration_pixel_enabled',
			function() {
				return false;
			}
		);

		$tracker = new WC_Facebookcommerce_EventsTracker( array(), $this->aam_settings );
		$filter->teardown_safely_immediately();

		return $tracker;
	}

	/**
	 * Test that get_param_builder returns a value or null.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_param_builder
	 */
	public function test_get_param_builder_returns_value_or_null(): void {
		$result = WC_Facebookcommerce_EventsTracker::get_param_builder();

		$this->assertTrue(
			is_null( $result ) || is_object( $result ),
			'get_param_builder should return null or an object'
		);
	}

	/**
	 * Test that get_scalar_request_values keeps scalar values (as strings) and drops
	 * array-shaped or object values.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_scalar_request_values
	 */
	public function test_get_scalar_request_values_drops_non_scalar_values(): void {
		$values = array(
			'_fbc'   => array( 'x' => '1' ),
			'_fbp'   => 'fb.1.1700000000000.123456789',
			'fbclid' => array( 'nested' => array( 'deeper' => '1' ) ),
			'count'  => 5,
			'flag'   => true,
			'object' => new \stdClass(),
			'none'   => null,
		);

		$this->assertSame(
			array(
				'_fbp'  => 'fb.1.1700000000000.123456789',
				'count' => '5',
				'flag'  => '1',
			),
			WC_Facebookcommerce_EventsTracker::get_scalar_request_values( $values )
		);

		$this->assertSame( array(), WC_Facebookcommerce_EventsTracker::get_scalar_request_values( null ) );
		$this->assertSame( array(), WC_Facebookcommerce_EventsTracker::get_scalar_request_values( 'not-an-array' ) );
	}

	/**
	 * Test that array-shaped attribution cookies and query parameters (e.g. a
	 * `Cookie: _fbc[x]=1` header) are ignored instead of crashing the request with a
	 * TypeError inside the vendor ParamBuilder.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_param_builder
	 * @covers WC_Facebookcommerce_EventsTracker::get_fbc
	 * @covers WC_Facebookcommerce_EventsTracker::get_fbp
	 */
	public function test_get_param_builder_ignores_array_shaped_cookies_and_query_params(): void {
		$original_state  = $this->reset_param_builder_state();
		$original_cookie = $_COOKIE;
		$original_get    = $_GET;

		$_COOKIE['_fbc'] = array( 'x' => '1' );
		$_COOKIE['_fbp'] = array( 'x' => '1' );
		$_COOKIE['_fbi'] = array( 'x' => '1' );
		$_GET['fbclid']  = array( 'x' => '1' );

		try {
			$param_builder = WC_Facebookcommerce_EventsTracker::get_param_builder();

			$this->assertInstanceOf( \FacebookAds\ParamBuilder::class, $param_builder );
			$this->assertNull(
				WC_Facebookcommerce_EventsTracker::get_fbc(),
				'A malformed _fbc cookie must be treated as absent'
			);

			$fbp = WC_Facebookcommerce_EventsTracker::get_fbp();
			$this->assertIsString( $fbp );
			$this->assertStringStartsWith( 'fb.', $fbp, 'A malformed _fbp cookie must be replaced by a freshly generated one' );
		} finally {
			$_COOKIE = $original_cookie;
			$_GET    = $original_get;
			$this->restore_param_builder_state( $original_state );
		}
	}

	/**
	 * Test that a well-formed _fbc cookie still flows through ParamBuilder after the
	 * scalar filtering, so the fix does not regress normal attribution.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_param_builder
	 * @covers WC_Facebookcommerce_EventsTracker::get_fbc
	 */
	public function test_get_param_builder_keeps_well_formed_fbc_cookie(): void {
		$original_state  = $this->reset_param_builder_state();
		$original_cookie = $_COOKIE;

		$_COOKIE['_fbc'] = 'fb.1.1700000000000.AbCdEf';

		try {
			WC_Facebookcommerce_EventsTracker::get_param_builder();

			$this->assertStringStartsWith(
				'fb.1.1700000000000.AbCdEf',
				(string) WC_Facebookcommerce_EventsTracker::get_fbc(),
				'A well-formed _fbc cookie must be preserved'
			);
		} finally {
			$_COOKIE = $original_cookie;
			$this->restore_param_builder_state( $original_state );
		}
	}

	/**
	 * Test that constructing the tracker, which runs param_builder_server_setup on every
	 * front-end request, survives array-shaped attribution cookies.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::param_builder_server_setup
	 */
	public function test_param_builder_server_setup_survives_array_shaped_cookies(): void {
		$original_state  = $this->reset_param_builder_state();
		$original_cookie = $_COOKIE;

		$_COOKIE['_fbc'] = array( 'x' => '1' );
		$_COOKIE['_fbp'] = array( 'x' => '1' );

		try {
			$this->instance = $this->create_tracker_with_pixel_enabled();

			$this->assertInstanceOf( WC_Facebookcommerce_EventsTracker::class, $this->instance );
		} finally {
			$_COOKIE = $original_cookie;
			$this->restore_param_builder_state( $original_state );
		}
	}

	/**
	 * Test that get_tracked_events returns an array.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_tracked_events
	 */
	public function test_get_tracked_events_returns_array(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$result = $this->instance->get_tracked_events();

		$this->assertIsArray( $result, 'get_tracked_events should return an array' );
	}

	/**
	 * Test that get_pending_events returns an array.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_pending_events
	 */
	public function test_get_pending_events_returns_array(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$result = $this->instance->get_pending_events();

		$this->assertIsArray( $result, 'get_pending_events should return an array' );
	}

	/**
	 * Test that send_pending_events does nothing when no pending events.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::send_pending_events
	 */
	public function test_send_pending_events_does_nothing_when_empty(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$this->instance->send_pending_events();

		$this->assertTrue( true, 'send_pending_events should handle empty pending events' );
	}

	/**
	 * Test that maybe_add_product_search_event_to_session returns the redirect value.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::maybe_add_product_search_event_to_session
	 */
	public function test_maybe_add_product_search_event_to_session_returns_redirect(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$result_false = $this->instance->maybe_add_product_search_event_to_session( false );
		$this->assertFalse( $result_false, 'Should return false when passed false' );

		$result_true = $this->instance->maybe_add_product_search_event_to_session( true );
		$this->assertTrue( $result_true, 'Should return true when passed true' );
	}

	/**
	 * Test that inject_base_pixel outputs nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_base_pixel
	 */
	public function test_inject_base_pixel_outputs_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		ob_start();
		$this->instance->inject_base_pixel();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'inject_base_pixel should output nothing when pixel is disabled' );
	}

	/**
	 * Test that inject_page_view_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_page_view_event
	 */
	public function test_inject_page_view_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_page_view_event();

		$this->assertTrue( true, 'inject_page_view_event should handle disabled pixel' );
	}

	/**
	 * Test that inject_view_category_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_view_category_event
	 */
	public function test_inject_view_category_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_view_category_event();

		$this->assertTrue( true, 'inject_view_category_event should handle disabled pixel' );
	}

	/**
	 * Test that inject_view_content_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_view_content_event
	 */
	public function test_inject_view_content_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_view_content_event();

		$this->assertTrue( true, 'inject_view_content_event should handle disabled pixel' );
	}

	/**
	 * Test that inject_add_to_cart_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_add_to_cart_event
	 */
	public function test_inject_add_to_cart_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_add_to_cart_event( 'cart_key', 123, 1, 0 );

		$this->assertTrue( true, 'inject_add_to_cart_event should handle disabled pixel' );
	}
	/**
	 * Test that inject_add_to_cart_event does nothing with invalid product_id.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_add_to_cart_event
	 */
	public function test_inject_add_to_cart_event_does_nothing_with_invalid_product_id(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Product ID of 0 should be invalid
		$this->instance->inject_add_to_cart_event( 'cart_key', 0, 1, 0 );

		$this->assertEmpty(
			$this->instance->get_tracked_events(),
			'inject_add_to_cart_event should not track with invalid product_id'
		);
	}

	/**
	 * Test that inject_add_to_cart_event does nothing with zero quantity.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_add_to_cart_event
	 */
	public function test_inject_add_to_cart_event_does_nothing_with_zero_quantity(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Quantity of 0 should be invalid
		$this->instance->inject_add_to_cart_event( 'cart_key', 123, 0, 0 );

		$this->assertEmpty(
			$this->instance->get_tracked_events(),
			'inject_add_to_cart_event should not track with zero quantity'
		);
	}

	/**
	 * Test that inject_base_pixel_noscript outputs nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_base_pixel_noscript
	 */
	public function test_inject_base_pixel_noscript_outputs_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		ob_start();
		$this->instance->inject_base_pixel_noscript();
		$output = ob_get_clean();

		$this->assertEmpty( $output, 'inject_base_pixel_noscript should output nothing when pixel is disabled' );
	}

	/**
	 * Test that inject_initiate_checkout_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_initiate_checkout_event
	 */
	public function test_inject_initiate_checkout_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_initiate_checkout_event();

		$this->assertTrue( true, 'inject_initiate_checkout_event should handle disabled pixel' );
	}

	/**
	 * Test that inject_initiate_checkout_event does nothing when cart is null.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_initiate_checkout_event
	 */
	public function test_inject_initiate_checkout_event_does_nothing_when_cart_is_null(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// When WC()->cart is null, the method should bail early
		$this->instance->inject_initiate_checkout_event();

		$this->assertTrue( true, 'inject_initiate_checkout_event should handle null cart' );
	}

	/**
	 * Test that inject_search_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_search_event
	 */
	public function test_inject_search_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$query = new \WP_Query();
		$this->instance->inject_search_event( $query );

		$this->assertTrue( true, 'inject_search_event should handle disabled pixel' );
	}

	/**
	 * Test that inject_search_event does nothing when not main query.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_search_event
	 */
	public function test_inject_search_event_does_nothing_when_not_main_query(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Create a query that is not the main query
		$query = new \WP_Query();
		$this->instance->inject_search_event( $query );

		$this->assertTrue( true, 'inject_search_event should handle non-main query' );
	}

	/**
	 * Test that maybe_inject_search_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::maybe_inject_search_event
	 */
	public function test_maybe_inject_search_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->maybe_inject_search_event();

		$this->assertTrue( true, 'maybe_inject_search_event should handle disabled pixel' );
	}

	/**
	 * Test that send_search_event does nothing when search event is null.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::send_search_event
	 */
	public function test_send_search_event_does_nothing_when_null(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// No search event has been created, so this should do nothing
		$this->instance->send_search_event();

		$this->assertTrue( true, 'send_search_event should handle null search event' );
	}

	/**
	 * Test that actually_inject_search_event does nothing when search event is null.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::actually_inject_search_event
	 */
	public function test_actually_inject_search_event_does_nothing_when_null(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// No search event has been created, so this should do nothing
		$this->instance->actually_inject_search_event();

		$this->assertTrue( true, 'actually_inject_search_event should handle null search event' );
	}

	/**
	 * Test that inject_purchase_event does nothing when user is admin.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_purchase_event
	 */
	public function test_inject_purchase_event_does_nothing_when_admin_user(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Create an admin user and set as current user
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->instance->inject_purchase_event( 999 );

		$this->assertTrue( true, 'inject_purchase_event should not track for admin users' );

		// Clean up
		wp_set_current_user( 0 );
	}

	/**
	 * Test that inject_purchase_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_purchase_event
	 */
	public function test_inject_purchase_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_purchase_event( 999 );

		$this->assertTrue( true, 'inject_purchase_event should handle disabled pixel' );
	}

	/**
	 * Test that inject_purchase_event does nothing with invalid order.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_purchase_event
	 */
	public function test_inject_purchase_event_does_nothing_with_invalid_order(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Use an order ID that doesn't exist
		$this->instance->inject_purchase_event( 999999 );

		$this->assertEmpty(
			$this->instance->get_tracked_events(),
			'inject_purchase_event should not track with invalid order'
		);
	}

	/**
	 * Test that is_purchase_trackable_order returns true only for shop_order.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::is_purchase_trackable_order
	 */
	public function test_is_purchase_trackable_order_only_allows_shop_order(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$shop_order = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_type' ) )
			->getMock();
		$shop_order->method( 'get_type' )->willReturn( 'shop_order' );

		$shop_subscription = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_type' ) )
			->getMock();
		$shop_subscription->method( 'get_type' )->willReturn( 'shop_subscription' );

		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'is_purchase_trackable_order' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->instance, $shop_order ) );
		$this->assertFalse( $method->invoke( $this->instance, $shop_subscription ) );
	}

	/**
	 * Test that inject_purchase_event sends CAPI event for server context.
	 *
	 * When triggered by a server-side hook (e.g., woocommerce_new_order),
	 * the method should send a CAPI event.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_purchase_event
	 */
	public function test_inject_purchase_event_sends_capi_for_server_context(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();
		$this->remove_purchase_hooks();

		// Create a valid order with processing status
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->set_total( 100 );
		$order->save();

		// Simulate server hook by calling inject_purchase_event via do_action
		// This sets current_action() to 'woocommerce_new_order'
		add_action( 'woocommerce_new_order', array( $this->instance, 'inject_purchase_event' ), 10 );
		do_action( 'woocommerce_new_order', $order->get_id(), $order );
		remove_action( 'woocommerce_new_order', array( $this->instance, 'inject_purchase_event' ), 10 );

		$tracked_events = $this->instance->get_tracked_events();

		$this->assertNotEmpty(
			$tracked_events,
			'inject_purchase_event should track CAPI event for server context'
		);
		$this->assertCount(
			1,
			$tracked_events,
			'inject_purchase_event should track exactly one CAPI event for server context'
		);

		// Clean up
		$order->delete( true );
	}

	/**
	 * Test that inject_purchase_event does NOT send CAPI event for browser context.
	 *
	 * When triggered by the browser-side hook (woocommerce_thankyou),
	 * the method should NOT send a CAPI event (only inject pixel).
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_purchase_event
	 */
	public function test_inject_purchase_event_does_not_send_capi_for_browser_context(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();
		$this->remove_purchase_hooks();

		// Create a valid order with processing status
		$order = wc_create_order();
		$order->set_status( 'processing' );
		$order->set_total( 100 );
		$order->save();

		// Simulate that server context already ran and set the server meta
		$order->add_meta_data( '_meta_purchase_tracked_server', true, true );
		$order->save();

		// Simulate browser hook (thank you page) - should NOT send CAPI since server already did
		add_action( 'woocommerce_thankyou', array( $this->instance, 'inject_purchase_event' ), 40 );
		do_action( 'woocommerce_thankyou', $order->get_id() );
		remove_action( 'woocommerce_thankyou', array( $this->instance, 'inject_purchase_event' ), 40 );

		$tracked_events = $this->instance->get_tracked_events();

		$this->assertEmpty(
			$tracked_events,
			'inject_purchase_event should NOT track CAPI event for browser context when server already tracked'
		);

		// Clean up
		$order->delete( true );
	}


	/**
	 * Test that inject_purchase_event only sends one CAPI event when both hooks fire.
	 *
	 * When both server and browser hooks fire (typical checkout flow),
	 * only one CAPI event should be sent (from server context).
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_purchase_event
	 */
	public function test_inject_purchase_event_sends_single_capi_for_full_checkout_flow(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();
		$this->remove_purchase_hooks();

		// Server hook first, fired by WooCommerce while it creates the order (as in a
		// real checkout). Tracking metadata is saved once that save has finished.
		add_action( 'woocommerce_new_order', array( $this->instance, 'inject_purchase_event' ), 10, 2 );
		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->set_total( 100 );
		$order->save();
		remove_action( 'woocommerce_new_order', array( $this->instance, 'inject_purchase_event' ), 10 );

		// Simulate browser hook second (thank you page)
		add_action( 'woocommerce_thankyou', array( $this->instance, 'inject_purchase_event' ), 40 );
		do_action( 'woocommerce_thankyou', $order->get_id() );
		remove_action( 'woocommerce_thankyou', array( $this->instance, 'inject_purchase_event' ), 40 );

		$tracked_events = $this->instance->get_tracked_events();

		$this->assertCount(
			1,
			$tracked_events,
			'inject_purchase_event should track exactly one CAPI event when both server and browser hooks fire'
		);

		// Clean up
		$order->delete( true );
	}

	/**
	 * Test that renewal-order meta copy excludes Purchase tracking keys.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::exclude_purchase_tracking_meta_from_renewal_orders
	 */
	public function test_exclude_purchase_tracking_meta_from_renewal_orders_removes_tracking_keys(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$data = array(
			'_meta_purchase_tracked_server'  => '1',
			'_meta_purchase_tracked_browser' => '1',
			'_meta_event_id'                 => 'event-id',
			'_subscription_renewal'          => '123',
		);

		$to_order = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_type' ) )
			->getMock();
		$to_order->method( 'get_type' )->willReturn( 'shop_order' );

		$from_subscription = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_type' ) )
			->getMock();
		$from_subscription->method( 'get_type' )->willReturn( 'shop_subscription' );

		$result = $this->instance->exclude_purchase_tracking_meta_from_renewal_orders( $data, $to_order, $from_subscription );

		$this->assertArrayNotHasKey( '_meta_purchase_tracked_server', $result );
		$this->assertArrayNotHasKey( '_meta_purchase_tracked_browser', $result );
		$this->assertArrayNotHasKey( '_meta_event_id', $result );
		$this->assertArrayHasKey( '_subscription_renewal', $result );
	}

	/**
	 * Test that renewal-order meta copy leaves data unchanged for non-subscription sources.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::exclude_purchase_tracking_meta_from_renewal_orders
	 */
	public function test_exclude_purchase_tracking_meta_from_renewal_orders_keeps_data_for_non_subscription_source(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$data = array(
			'_meta_purchase_tracked_server'  => '1',
			'_meta_purchase_tracked_browser' => '1',
			'_meta_event_id'                 => 'event-id',
		);

		$to_order = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_type' ) )
			->getMock();
		$to_order->method( 'get_type' )->willReturn( 'shop_order' );

		$from_order = $this->getMockBuilder( \WC_Order::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_type' ) )
			->getMock();
		$from_order->method( 'get_type' )->willReturn( 'shop_order' );

		$result = $this->instance->exclude_purchase_tracking_meta_from_renewal_orders( $data, $to_order, $from_order );

		$this->assertSame( $data, $result );
	}

	/**
	 * Test that inject_subscribe_event does nothing when function not available.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_subscribe_event
	 */
	public function test_inject_subscribe_event_does_nothing_when_function_unavailable(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// wcs_get_subscriptions_for_order doesn't exist, so this should bail
		$this->instance->inject_subscribe_event( 999 );

		$this->assertTrue( true, 'inject_subscribe_event should handle missing WooCommerce Subscriptions' );
	}

	/**
	 * Test that inject_subscribe_event does nothing when pixel is disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_subscribe_event
	 */
	public function test_inject_subscribe_event_does_nothing_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$this->instance->inject_subscribe_event( 999 );

		$this->assertTrue( true, 'inject_subscribe_event should handle disabled pixel' );
	}

	/**
	 * Test that the constructor registers track_cf7_lead_event on wpcf7_mail_sent.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::__construct
	 */
	public function test_constructor_registers_track_cf7_lead_event_on_wpcf7_mail_sent(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$this->assertTrue(
			has_action( 'wpcf7_mail_sent', array( $this->instance, 'track_cf7_lead_event' ) ) !== false,
			'Constructor should register track_cf7_lead_event on wpcf7_mail_sent'
		);
	}

	/**
	 * Test that the constructor registers the CF7 browser pixel code on the REST feedback response.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::__construct
	 */
	public function test_constructor_registers_cf7_lead_event_pixel_code_on_feedback_response(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$this->assertTrue(
			has_filter( 'wpcf7_feedback_response', array( $this->instance, 'inject_cf7_lead_event_pixel_code' ) ) !== false,
			'Constructor should register inject_cf7_lead_event_pixel_code on wpcf7_feedback_response'
		);
	}

	/**
	 * Test that the constructor registers the CF7 footer listener on wp_footer.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::__construct
	 */
	public function test_constructor_registers_cf7_lead_event_listener_on_footer(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$this->assertTrue(
			has_action( 'wp_footer', array( $this->instance, 'inject_cf7_lead_event_listener' ) ) !== false,
			'Constructor should register inject_cf7_lead_event_listener on wp_footer'
		);
	}

	/**
	 * Test that add_filter_for_add_to_cart_fragments adds filter when redirect disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::add_filter_for_add_to_cart_fragments
	 */
	public function test_add_filter_for_add_to_cart_fragments_adds_filter(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Set cart redirect to 'no'
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );

		$this->instance->add_filter_for_add_to_cart_fragments();

		$this->assertTrue(
			has_filter( 'woocommerce_add_to_cart_fragments', array( $this->instance, 'add_add_to_cart_event_fragment' ) ) !== false,
			'add_filter_for_add_to_cart_fragments should add the fragment filter'
		);

		// Clean up
		delete_option( 'woocommerce_cart_redirect_after_add' );
	}

	/**
	 * Test that add_filter_for_add_to_cart_fragments does nothing when redirect enabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::add_filter_for_add_to_cart_fragments
	 */
	public function test_add_filter_for_add_to_cart_fragments_does_nothing_when_redirect(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Set cart redirect to 'yes'
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );

		$this->instance->add_filter_for_add_to_cart_fragments();

		$this->assertFalse(
			has_filter( 'woocommerce_add_to_cart_fragments', array( $this->instance, 'add_add_to_cart_event_fragment' ) ),
			'add_filter_for_add_to_cart_fragments should not add filter when redirect is enabled'
		);

		// Clean up
		delete_option( 'woocommerce_cart_redirect_after_add' );
	}

	/**
	 * Test that add_add_to_cart_event_fragment returns fragments unchanged when product invalid.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::add_add_to_cart_event_fragment
	 */
	public function test_add_add_to_cart_event_fragment_returns_unchanged_when_invalid(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$_POST['product_id'] = 999999; // Non-existent product
		$_POST['quantity']   = 1;

		$fragments         = array( 'test' => 'value' );
		$result_fragments = $this->instance->add_add_to_cart_event_fragment( $fragments );

		$this->assertSame(
			$fragments,
			$result_fragments,
			'Fragments should be unchanged when product is invalid'
		);

		unset( $_POST['product_id'], $_POST['quantity'] );
	}

	/**
	 * Test that add_conditional_add_to_cart_event_fragment returns fragments when pixel disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::add_conditional_add_to_cart_event_fragment
	 */
	public function test_add_conditional_add_to_cart_event_fragment_when_disabled(): void {
		$this->instance = $this->create_tracker_with_pixel_disabled();

		$fragments         = array( 'test' => 'value' );
		$result_fragments = $this->instance->add_conditional_add_to_cart_event_fragment( $fragments );

		$this->assertSame(
			$fragments,
			$result_fragments,
			'Fragments should be unchanged when pixel is disabled'
		);
	}

	/**
	 * Test that get_tracked_events returns empty array initially.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_tracked_events
	 */
	public function test_get_tracked_events_returns_empty_initially(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$events = $this->instance->get_tracked_events();

		$this->assertIsArray( $events );
		$this->assertEmpty( $events, 'Tracked events should be empty initially' );
	}

	/**
	 * Test that get_pending_events returns empty array initially.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_pending_events
	 */
	public function test_get_pending_events_returns_empty_initially(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$events = $this->instance->get_pending_events();

		$this->assertIsArray( $events );
		$this->assertEmpty( $events, 'Pending events should be empty initially' );
	}

	/**
	 * Test that add_filter_for_conditional_add_to_cart_fragment adds filter when redirect disabled.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::add_filter_for_conditional_add_to_cart_fragment
	 */
	public function test_add_filter_for_conditional_add_to_cart_fragment_adds_filter(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Set cart redirect to 'no'
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );

		$this->instance->add_filter_for_conditional_add_to_cart_fragment();

		$this->assertTrue(
			has_filter( 'woocommerce_add_to_cart_fragments', array( $this->instance, 'add_conditional_add_to_cart_event_fragment' ) ) !== false,
			'add_filter_for_conditional_add_to_cart_fragment should add the fragment filter'
		);

		// Clean up
		delete_option( 'woocommerce_cart_redirect_after_add' );
	}

	/**
	 * Test that param_builder_client_setup does nothing when not connected.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::param_builder_client_setup
	 */
	public function test_param_builder_client_setup_does_nothing_when_not_connected(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// When not connected, this should do nothing without errors
		$this->instance->param_builder_client_setup();

		$this->assertTrue( true, 'param_builder_client_setup should handle disconnected state' );
	}

	/**
	 * Test that tracker can be constructed with user info array.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::__construct
	 */
	public function test_constructor_accepts_user_info_array(): void {
		$filter = $this->add_filter_with_safe_teardown(
			'facebook_for_woocommerce_integration_pixel_enabled',
			function() {
				return true;
			}
		);

		$user_info = array(
			'em' => 'test@example.com',
			'fn' => 'John',
			'ln' => 'Doe',
		);

		$tracker = new WC_Facebookcommerce_EventsTracker( $user_info, $this->aam_settings );

		$this->assertInstanceOf(
			WC_Facebookcommerce_EventsTracker::class,
			$tracker,
			'Tracker should be instantiated with user info'
		);
	}

	/**
	 * Test that tracker can be constructed with empty user info.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::__construct
	 */
	public function test_constructor_accepts_empty_user_info(): void {
		$filter = $this->add_filter_with_safe_teardown(
			'facebook_for_woocommerce_integration_pixel_enabled',
			function() {
				return true;
			}
		);

		$tracker = new WC_Facebookcommerce_EventsTracker( array(), $this->aam_settings );

		$this->assertInstanceOf(
			WC_Facebookcommerce_EventsTracker::class,
			$tracker,
			'Tracker should be instantiated with empty user info'
		);
	}

	/**
	 * Test inject_view_content_event does nothing when post ID not set.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_view_content_event
	 */
	public function test_inject_view_content_event_does_nothing_when_no_post(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Ensure $post is not set
		global $post;
		$original_post = $post;
		$post          = null;

		$this->instance->inject_view_content_event();

		$this->assertTrue( true, 'inject_view_content_event should handle missing post' );

		// Restore
		$post = $original_post;
	}

	/**
	 * Set up a mock param_builder that returns test cookies.
	 *
	 * @param string $cookie_name The name of the test cookie to return.
	 * @return mixed the mock param_builder
	 */
	private function create_mock_param_builder_with_cookies( string $cookie_name ) {
		$mock_cookie        = new \stdClass();
		$mock_cookie->name  = $cookie_name;
		$mock_cookie->value = 'test_value';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mock_cookie->max_age = 3600;
		$mock_cookie->domain  = '';

		$mock_param_builder = new class( $mock_cookie ) {
			private $cookie;
			private $was_called = false;
			public function __construct( $cookie ) {
				$this->cookie = $cookie;
			}

			public function getCookiesToSet() {
				$this->was_called = true;
				return array( $this->cookie );
			}

			public function wasGetCookiesToSetCalled() {
				return $this->was_called;
			}

			public function getFbc() {
				return null;
			}

			public function getFbp() {
				return null;
			}
		};
		return $mock_param_builder;
	}

	/**
	 * Clears the tracker's static ParamBuilder state so the next get_param_builder()
	 * call processes the current superglobals.
	 *
	 * @return array<string, mixed> original static values keyed by property name.
	 */
	private function reset_param_builder_state(): array {
		$original = array();
		$ref      = new ReflectionClass( WC_Facebookcommerce_EventsTracker::class );

		foreach ( array( 'param_builder', 'cached_fbc', 'cached_fbp' ) as $prop_name ) {
			$prop = $ref->getProperty( $prop_name );
			$prop->setAccessible( true );
			$original[ $prop_name ] = $prop->getValue();
			$prop->setValue( null, null );
		}

		return $original;
	}

	/**
	 * Restores the static ParamBuilder state captured by reset_param_builder_state().
	 *
	 * @param array<string, mixed> $original original static values keyed by property name.
	 */
	private function restore_param_builder_state( array $original ): void {
		$ref = new ReflectionClass( WC_Facebookcommerce_EventsTracker::class );

		foreach ( $original as $prop_name => $value ) {
			$prop = $ref->getProperty( $prop_name );
			$prop->setAccessible( true );
			$prop->setValue( null, $value );
		}
	}

	/**
	 * Set the param_builder static value and return the original.
	 *
	 * @param mixed $param_builder The value to update param_builder to.
	 * @return mixed The previous param_builder value
	 */
	private function install_param_builder_mock( $param_builder ) {
		$original_param_builder = null;
		if ( class_exists( 'WC_Facebookcommerce_EventsTracker' ) ) {
			$ref = new ReflectionClass( 'WC_Facebookcommerce_EventsTracker' );
			if ( $ref->hasProperty( 'param_builder' ) ) {
				$prop = $ref->getProperty( 'param_builder' );
				$prop->setAccessible( true );
				$original_param_builder = $prop->getValue();
				$prop->setValue( null, $param_builder );
			}
		}

		return $original_param_builder;
	}

	/**
	 * Data provider for testing setcookie behavior based on pixel enabled filter.
	 *
	 * @return array Test cases with format: [pixel_enabled, expected_setcookie_called]
	 */
	public function setcookie_behavior_provider(): array {
		return array(
			'pixel enabled - setcookie should be called'     => array(
				'pixel_enabled'   => true,
				'expected_called' => true,
			),
			'pixel disabled - setcookie should not be called' => array(
				'pixel_enabled'   => false,
				'expected_called' => false,
			),
		);
	}

	/**
	 * Test that param_builder_server_setup calls setcookie when pixel is enabled
	 * and does not call setcookie when pixel is disabled.
	 *
	 * @dataProvider setcookie_behavior_provider
	 * @covers WC_Facebookcommerce_EventsTracker::param_builder_server_setup
	 *
	 * @param bool $pixel_enabled Whether the pixel should be enabled via the filter.
	 * @param bool $expected_setcookie_called Whether setcookie is expected to be called.
	 */
	public function test_param_builder_server_setup_setcookie_behavior( bool $pixel_enabled, bool $expected_called ): void {
		$test_cookie_name = 'fbp_test_' . uniqid();

		// Set up mock param_builder before creating the tracker.
		$mock_param_builder = $this->create_mock_param_builder_with_cookies( $test_cookie_name );
		$original_param_builder = $this->install_param_builder_mock( $mock_param_builder );

		// Create tracker with appropriate pixel setting.
		if ( $pixel_enabled ) {
			$this->create_tracker_with_pixel_enabled();
		} else {
			$this->create_tracker_with_pixel_disabled();
		}

		$wasCalled = $mock_param_builder->wasGetCookiesToSetCalled();

		// Restore original param_builder.
		$this->install_param_builder_mock( $original_param_builder );

		// Assert the expected behavior.
		$this->assertEquals(
			$expected_called,
			$wasCalled,
			$pixel_enabled
				? 'param_builder_server_setup should complete when pixel is enabled'
				: 'param_builder_server_setup should return early when pixel is disabled'
		);
	}

	/**
	 * Test that register_store_api_endpoint_data registers the endpoint when function exists.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::register_store_api_endpoint_data
	 */
	public function test_register_store_api_endpoint_data_registers_when_function_exists(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// The method should not throw when function doesn't exist
		// (it will return early in that case)
		$this->instance->register_store_api_endpoint_data();

		$this->assertTrue( true, 'register_store_api_endpoint_data should not throw' );
	}

	/**
	 * Test that get_store_api_pixel_event_data returns empty array when no pending event.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_store_api_pixel_event_data
	 */
	public function test_get_store_api_pixel_event_data_returns_empty_when_no_pending_event(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$result = $this->instance->get_store_api_pixel_event_data();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result, 'Should return empty array when no pending event' );
	}

	/**
	 * Test that get_store_api_pixel_event_data returns pending event and clears it.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_store_api_pixel_event_data
	 */
	public function test_get_store_api_pixel_event_data_returns_and_clears_pending_event(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Set up a pending event using reflection
		$pending_event = array(
			'event'  => 'AddToCart',
			'params' => array(
				'content_ids'  => '["product_123"]',
				'value'        => 29.99,
				'currency'     => 'USD',
				'event_id'     => 'test-event-id-123',
			),
		);

		$reflection = new ReflectionClass( $this->instance );
		$property = $reflection->getProperty( 'pending_store_api_pixel_event' );
		$property->setAccessible( true );
		$property->setValue( $this->instance, $pending_event );

		// First call should return the event
		$result = $this->instance->get_store_api_pixel_event_data();

		$this->assertSame( $pending_event, $result, 'Should return the pending event' );

		// Second call should return empty (event was cleared)
		$result_second = $this->instance->get_store_api_pixel_event_data();

		$this->assertIsArray( $result_second );
		$this->assertEmpty( $result_second, 'Should return empty array after event was cleared' );
	}

	/**
	 * Test that get_store_api_pixel_event_schema returns correct schema structure.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::get_store_api_pixel_event_schema
	 */
	public function test_get_store_api_pixel_event_schema_returns_correct_structure(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$schema = $this->instance->get_store_api_pixel_event_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'event', $schema, 'Schema should have event key' );
		$this->assertArrayHasKey( 'params', $schema, 'Schema should have params key' );

		// Check event schema
		$this->assertSame( 'string', $schema['event']['type'], 'Event type should be string' );
		$this->assertTrue( $schema['event']['readonly'], 'Event should be readonly' );

		// Check params schema
		$this->assertSame( 'object', $schema['params']['type'], 'Params type should be object' );
		$this->assertTrue( $schema['params']['readonly'], 'Params should be readonly' );
	}

	/**
	 * Test that inject_add_to_cart_event stores pending pixel event in session.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_add_to_cart_event
	 */
	public function test_inject_add_to_cart_event_stores_pending_pixel_event(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Create a test product
		$product = \WC_Helper_Product::create_simple_product();

		// Add to cart to trigger the hook
		WC()->cart->add_to_cart( $product->get_id(), 2 );

		// Check that pending pixel event was stored using reflection
		$reflection = new ReflectionClass( $this->instance );
		$property = $reflection->getProperty( 'pending_store_api_pixel_event' );
		$property->setAccessible( true );
		$pending_event = $property->getValue( $this->instance );

		$this->assertIsArray( $pending_event, 'Pending event should be stored' );
		$this->assertArrayHasKey( 'event', $pending_event, 'Pending event should have event key' );
		$this->assertArrayHasKey( 'params', $pending_event, 'Pending event should have params key' );
		$this->assertSame( 'AddToCart', $pending_event['event'], 'Event should be AddToCart' );

		// Check params structure
		$params = $pending_event['params'];
		$this->assertArrayHasKey( 'content_ids', $params, 'Params should have content_ids' );
		$this->assertArrayHasKey( 'content_name', $params, 'Params should have content_name' );
		$this->assertArrayHasKey( 'content_type', $params, 'Params should have content_type' );
		$this->assertArrayHasKey( 'contents', $params, 'Params should have contents' );
		$this->assertArrayHasKey( 'value', $params, 'Params should have value' );
		$this->assertArrayHasKey( 'currency', $params, 'Params should have currency' );
		$this->assertArrayHasKey( 'event_id', $params, 'Params should have event_id' );

		// Verify values
		$this->assertSame( 'product', $params['content_type'], 'Content type should be product' );
		$this->assertSame( get_woocommerce_currency(), $params['currency'], 'Currency should match store currency' );
		$this->assertNotEmpty( $params['event_id'], 'Event ID should not be empty' );

		// Clean up
		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test that inject_add_to_cart_event stores same event_id for deduplication.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_add_to_cart_event
	 */
	public function test_inject_add_to_cart_event_stores_same_event_id(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Create a test product
		$product = \WC_Helper_Product::create_simple_product();

		// Add to cart to trigger the hook
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		// Get event ID from session (for AJAX fragments)
		$event_id_from_session = WC()->session->get( 'facebook_for_woocommerce_add_to_cart_event_id' );

		// Get event ID from class property (for Store API) using reflection
		$reflection = new ReflectionClass( $this->instance );
		$property = $reflection->getProperty( 'pending_store_api_pixel_event' );
		$property->setAccessible( true );
		$pending_event = $property->getValue( $this->instance );
		$event_id_from_pending = $pending_event['params']['event_id'] ?? null;

		$this->assertNotEmpty( $event_id_from_session, 'Event ID should be stored in session' );
		$this->assertNotEmpty( $event_id_from_pending, 'Event ID should be in pending event params' );
		$this->assertSame(
			$event_id_from_session,
			$event_id_from_pending,
			'Both event IDs should be the same for deduplication'
		);

		// Clean up
		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test that pending pixel event params match fragment params format.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::inject_add_to_cart_event
	 */
	public function test_pending_pixel_event_matches_fragment_params_format(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		// Create a test product with specific price
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '49.99' );
		$product->set_price( '49.99' );
		$product->save();

		// Re-fetch product to ensure price is loaded correctly
		$product = wc_get_product( $product->get_id() );

		$quantity = 3;

		// Add to cart
		WC()->cart->add_to_cart( $product->get_id(), $quantity );

		// Get pending event using reflection
		$reflection = new ReflectionClass( $this->instance );
		$property = $reflection->getProperty( 'pending_store_api_pixel_event' );
		$property->setAccessible( true );
		$pending_event = $property->getValue( $this->instance );
		$params = $pending_event['params'];

		// Verify content_ids is JSON encoded
		$decoded_content_ids = json_decode( $params['content_ids'], true );
		$this->assertIsArray( $decoded_content_ids, 'content_ids should be JSON encoded array' );

		// Verify contents is JSON encoded
		$decoded_contents = json_decode( $params['contents'], true );
		$this->assertIsArray( $decoded_contents, 'contents should be JSON encoded array' );
		$this->assertSame( $quantity, $decoded_contents[0]['quantity'], 'Quantity should match' );

		// Verify value calculation
		$expected_value = (float) $product->get_price() * $quantity;
		$this->assertEquals( $expected_value, $params['value'], 'Value should be price * quantity' );

		// Clean up
		WC()->cart->empty_cart();
		$product->delete( true );
	}
	/**
	 * Test that is_crawler_request detects known crawler user agents.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::is_crawler_request
	 * @dataProvider crawler_user_agent_provider
	 *
	 * @param string $user_agent The user agent string to test.
	 * @param bool   $expected   Whether the request should be detected as a crawler.
	 */
	public function test_is_crawler_request( string $user_agent, bool $expected ): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'is_crawler_request' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->instance, $event );

		$this->assertSame( $expected, $result, "User agent '{$user_agent}' should " . ( $expected ? '' : 'not ' ) . 'be detected as a crawler' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Data provider for crawler detection tests.
	 *
	 * @return array
	 */
	public function crawler_user_agent_provider(): array {
		return array(
			'meta external agent'       => array(
				'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
				true,
			),
			'meta external ads'         => array(
				'meta-externalads/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
				true,
			),
			'meta web indexer'          => array(
				'meta-webindexer/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)',
				true,
			),
			'generic crawler in UA'     => array(
				'SomeBot/2.0 (compatible; crawler; +http://example.com)',
				true,
			),
			'empty user agent'          => array(
				'',
				false,
			),
			'chrome desktop browser'    => array(
				'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				false,
			),
			'safari desktop browser'    => array(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
				false,
			),
			'mobile safari browser'     => array(
				'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
				false,
			),
			'firefox browser'           => array(
				'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
				false,
			),
		);
	}

	/**
	 * Test that is_crawler_request returns true when no user agent header is set.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::is_crawler_request
	 */
	public function test_is_crawler_request_returns_true_when_no_user_agent(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		unset( $_SERVER['HTTP_USER_AGENT'] );

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'is_crawler_request' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $this->instance, $event ), 'Missing user agent should not be detected as crawler' );
	}

	/**
	 * Test that send_api_event sends nothing while the request is the crash guard's self-check.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::send_api_event
	 */
	public function test_send_api_event_skips_during_crash_guard_self_check(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$handler_ref = new ReflectionClass( \WooCommerce\Facebook\Framework\PluginCrashHandler::class );
		$token_prop  = $handler_ref->getProperty( 'self_check_token' );
		$token_prop->setAccessible( true );
		$token_prop->setValue( null, 'abc123' );

		try {
			$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
			$reflection = new ReflectionClass( $this->instance );
			$method     = $reflection->getMethod( 'send_api_event' );
			$method->setAccessible( true );

			$this->assertFalse( $method->invoke( $this->instance, $event, false ) );
			$this->assertEmpty( $this->instance->get_tracked_events(), 'A self-check is not a visitor and must leave no trace at Meta.' );
			$this->assertEmpty( $this->instance->get_pending_events() );
		} finally {
			$token_prop->setValue( null, '' );
		}
	}

	/**
	 * Test that send_api_event skips tracking for crawler requests.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::send_api_event
	 */
	public function test_send_api_event_skips_for_crawler_request(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$_SERVER['HTTP_USER_AGENT'] = 'meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)';

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'send_api_event' );
		$method->setAccessible( true );
		$method->invoke( $this->instance, $event, false );

		$this->assertEmpty(
			$this->instance->get_tracked_events(),
			'Crawler requests should not add to tracked events'
		);
		$this->assertEmpty(
			$this->instance->get_pending_events(),
			'Crawler requests should not add to pending events'
		);

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Test that send_api_event skips CAPI events when the connection is flagged invalid.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::send_api_event
	 */
	public function test_send_api_event_skips_when_connection_invalid(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		set_transient( 'wc_facebook_connection_invalid', time(), DAY_IN_SECONDS );

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'send_api_event' );
		$method->setAccessible( true );
		$method->invoke( $this->instance, $event, false );

		$this->assertEmpty(
			$this->instance->get_tracked_events(),
			'Events should not be tracked while the connection is invalid'
		);
		$this->assertEmpty(
			$this->instance->get_pending_events(),
			'Events should not be pending while the connection is invalid'
		);

		delete_transient( 'wc_facebook_connection_invalid' );
	}

	/**
	 * Test that send_api_event proceeds for real browser requests.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::send_api_event
	 */
	public function test_send_api_event_proceeds_for_real_browser(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'send_api_event' );
		$method->setAccessible( true );
		$method->invoke( $this->instance, $event, false );

		$this->assertNotEmpty(
			$this->instance->get_tracked_events(),
			'Real browser requests should be tracked'
		);
		$this->assertNotEmpty(
			$this->instance->get_pending_events(),
			'Real browser requests with send_now=false should be pending'
		);

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Test that the wc_facebook_crawler_user_agent_patterns filter can add custom patterns.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::is_crawler_request
	 */
	public function test_is_crawler_request_custom_pattern_filter(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$_SERVER['HTTP_USER_AGENT'] = 'CustomScraper/1.0';

		$filter = $this->add_filter_with_safe_teardown(
			'wc_facebook_crawler_user_agent_patterns',
			function ( $patterns ) {
				$patterns[] = 'customscraper';
				return $patterns;
			}
		);

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'is_crawler_request' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->instance, $event ), 'Custom pattern should be detected via filter' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Test that the wc_facebook_is_crawler_request filter can override crawler detection.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::is_crawler_request
	 */
	public function test_is_crawler_request_override_filter(): void {
		$this->instance = $this->create_tracker_with_pixel_enabled();

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0';

		$filter = $this->add_filter_with_safe_teardown(
			'wc_facebook_is_crawler_request',
			function () {
				return true;
			}
		);

		$event      = new \WooCommerce\Facebook\Events\Event( array( 'event_name' => 'PageView' ) );
		$reflection = new ReflectionClass( $this->instance );
		$method     = $reflection->getMethod( 'is_crawler_request' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->instance, $event ), 'Override filter should force crawler detection' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Builds a Purchase payload through inject_purchase_event() and returns its custom_data.
	 *
	 * Value Optimization is switched on, and the COGS provider is replaced with a stub so
	 * the test does not depend on a Cost of Goods integration being installed.
	 *
	 * @param float      $shipping_total Shipping cost excluding tax.
	 * @param float      $cart_tax       Tax on the items.
	 * @param float      $shipping_tax   Tax on the shipping.
	 * @param float|bool $cogs           Value the COGS provider should report.
	 * @param float      $product_price  Price of the single ordered item.
	 * @return array The event's custom_data.
	 */
	private function build_purchase_custom_data( float $shipping_total, float $cart_tax, float $shipping_tax, $cogs, float $product_price = 100.0 ): array {
		update_option(
			'wc_facebook_for_woocommerce_rollout_switches',
			array( \WooCommerce\Facebook\RolloutSwitches::SWITCH_VALUE_OPTIMIZATION_ENABLED => 'yes' )
		);

		// Purchases are not tracked for users who can manage WooCommerce.
		wp_set_current_user( 0 );

		$this->instance = $this->create_tracker_with_pixel_enabled();
		$this->remove_purchase_hooks();

		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( $product_price );
		$product->save();

		$order = new \WC_Order();
		$order->add_product( $product, 1 );
		$order->set_shipping_total( $shipping_total );
		$order->set_cart_tax( $cart_tax );
		$order->set_shipping_tax( $shipping_tax );
		$order->set_total( $product_price + $shipping_total + $cart_tax + $shipping_tax );
		$order->set_status( 'processing' );
		$order->save();

		// Stub the COGS provider so no Cost of Goods plugin is required.
		$stub = new class( $cogs ) {
			/** @var float|bool */
			private $cogs;

			/**
			 * @param float|bool $cogs Value to report.
			 */
			public function __construct( $cogs ) {
				$this->cogs = $cogs;
			}

			/**
			 * @param array $product_quantities Unused.
			 * @return float|bool
			 */
			public function calculate_cogs_for_products( $product_quantities ) {
				return $this->cogs;
			}
		};

		$reflection = new ReflectionClass( $this->instance );
		$property   = $reflection->getProperty( 'cogs_provider' );
		$property->setAccessible( true );
		$property->setValue( $this->instance, $stub );

		$this->instance->inject_purchase_event( $order->get_id() );

		$events = $this->instance->get_tracked_events();
		$this->assertNotEmpty( $events, 'Expected a Purchase event to be tracked' );

		$data = end( $events )->get_data();

		return $data['custom_data'] ?? array();
	}

	/**
	 * Regression: get_total_tax() already includes shipping tax, so shipping tax must not
	 * be subtracted twice when deriving net revenue.
	 *
	 * Item 100 + shipping 10 + cart tax 20 + shipping tax 2 = total 132.
	 * Net of tax and shipping: 132 - 22 - 10 = 100. With COGS 40, net revenue is 60.
	 * The old arithmetic subtracted the 2 again and produced 58.
	 */
	public function test_purchase_net_revenue_does_not_subtract_shipping_tax_twice(): void {
		$custom_data = $this->build_purchase_custom_data( 10.0, 20.0, 2.0, 40.0 );

		$this->assertArrayHasKey( 'net_revenue', $custom_data );
		$this->assertEquals( 60.0, (float) $custom_data['net_revenue'] );
	}

	/**
	 * With no shipping tax the two formulas agree, so this pins the base case.
	 */
	public function test_purchase_net_revenue_without_shipping_tax(): void {
		$custom_data = $this->build_purchase_custom_data( 10.0, 20.0, 0.0, 40.0 );

		$this->assertArrayHasKey( 'net_revenue', $custom_data );
		$this->assertEquals( 60.0, (float) $custom_data['net_revenue'] );
	}

	/**
	 * A COGS provider that cannot price the order suppresses net revenue entirely.
	 */
	public function test_purchase_omits_net_revenue_when_cogs_unavailable(): void {
		$custom_data = $this->build_purchase_custom_data( 10.0, 20.0, 2.0, false );

		$this->assertArrayNotHasKey( 'net_revenue', $custom_data );
		$this->assertArrayHasKey( 'value', $custom_data );
	}

	/**
	 * COGS above the net order value means no positive profit, so nothing is reported.
	 */
	public function test_purchase_omits_net_revenue_when_profit_not_positive(): void {
		$custom_data = $this->build_purchase_custom_data( 10.0, 20.0, 2.0, 150.0 );

		$this->assertArrayNotHasKey( 'net_revenue', $custom_data );
	}

	/**
	 * Regression: a 9.99 item with 12.00 flat rate shipping, no tax, and a 4.00 cost of goods
	 * nets exactly 5.99. In binary floating point 21.99 - 12.00 - 4.00 lands just under that, and
	 * truncating the scaled value used to report 5.98.
	 */
	public function test_purchase_net_revenue_does_not_lose_a_cent_to_float_error(): void {
		$custom_data = $this->build_purchase_custom_data( 12.0, 0.0, 0.0, 4.0, 9.99 );

		$this->assertArrayHasKey( 'net_revenue', $custom_data );
		$this->assertSame( 5.99, (float) $custom_data['net_revenue'] );
	}
}
