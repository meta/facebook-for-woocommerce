<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

use WooCommerce\Facebook\Events\FacebookSignalsState;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for WC_Facebookcommerce_Pixel isolated execution context methods.
 *
 * These tests validate moving from shared JavaScript execution context (enqueue_inline_js)
 * to isolated external script execution (wp_enqueue_script + wp_localize_script).
 *
 * @package FacebookCommerce
 */
class FacebookCommercePixelIsolatedExecutionTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Reset static properties before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset static properties using reflection
		$this->reset_static_properties();

		// Set up a valid pixel ID for tests
		$this->set_pixel_id( '123456789' );
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		$this->reset_static_properties();
		parent::tearDown();
	}

	/**
	 * Reset static properties on WC_Facebookcommerce_Pixel class.
	 * Note: setAccessible() is deprecated in PHP 8.5 but still required for PHP < 8.1
	 */
	private function reset_static_properties(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );

		// Reset $event_queue
		$event_queue = $reflection->getProperty( 'event_queue' );
		$event_queue->setAccessible( true );
		$event_queue->setValue( null, [] );

		// Reset $script_enqueued
		$script_enqueued = $reflection->getProperty( 'script_enqueued' );
		$script_enqueued->setAccessible( true );
		$script_enqueued->setValue( null, false );

		// Reset $hooks_initialized
		$hooks_initialized = $reflection->getProperty( 'hooks_initialized' );
		$hooks_initialized->setAccessible( true );
		$hooks_initialized->setValue( null, false );
	}

	/**
	 * Helper to set pixel ID for tests.
	 */
	private function set_pixel_id( string $pixel_id ): void {
		update_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			array( WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => $pixel_id )
		);
	}

	/**
	 * Helper to get event queue via reflection.
	 */
	private function get_event_queue(): array {
		$reflection  = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$event_queue = $reflection->getProperty( 'event_queue' );
		$event_queue->setAccessible( true );
		return $event_queue->getValue();
	}

	// =========================================================================
	// enqueue_event() Tests
	// =========================================================================

	public function test_enqueue_event_adds_event_to_queue(): void {
		WC_Facebookcommerce_Pixel::enqueue_event(
			'ViewContent',
			array(
				'content_ids'  => array( '123' ),
				'content_type' => 'product',
				'value'        => 29.99,
				'currency'     => 'USD',
			)
		);

		$events = $this->get_event_queue();

		$this->assertCount( 1, $events );
		$this->assertEquals( 'ViewContent', $events[0]['name'] );
		$this->assertEquals( 'track', $events[0]['method'] );
		$this->assertEquals( array( '123' ), $events[0]['params']['content_ids'] );
	}

	public function test_enqueue_event_with_custom_method(): void {
		WC_Facebookcommerce_Pixel::enqueue_event(
			'CustomEvent',
			array( 'key' => 'value' ),
			'trackCustom'
		);

		$events = $this->get_event_queue();

		$this->assertCount( 1, $events );
		$this->assertEquals( 'trackCustom', $events[0]['method'] );
	}

	public function test_enqueue_event_with_event_id(): void {
		WC_Facebookcommerce_Pixel::enqueue_event(
			'AddToCart',
			array( 'content_ids' => array( '456' ) ),
			'track',
			'abc123-event-id'
		);

		$events = $this->get_event_queue();

		$this->assertCount( 1, $events );
		$this->assertArrayHasKey( 'eventId', $events[0] );
		$this->assertEquals( 'abc123-event-id', $events[0]['eventId'] );
	}

	public function test_enqueue_event_without_event_id_does_not_include_key(): void {
		WC_Facebookcommerce_Pixel::enqueue_event(
			'ViewContent',
			array( 'content_ids' => array( '789' ) )
		);

		$events = $this->get_event_queue();

		$this->assertCount( 1, $events );
		$this->assertArrayNotHasKey( 'eventId', $events[0] );
	}

	public function test_enqueue_event_multiple_events(): void {
		WC_Facebookcommerce_Pixel::enqueue_event( 'ViewContent', array( 'id' => '1' ) );
		WC_Facebookcommerce_Pixel::enqueue_event( 'AddToCart', array( 'id' => '2' ) );
		WC_Facebookcommerce_Pixel::enqueue_event( 'Purchase', array( 'id' => '3' ) );

		$events = $this->get_event_queue();

		$this->assertCount( 3, $events );
		$this->assertEquals( 'ViewContent', $events[0]['name'] );
		$this->assertEquals( 'AddToCart', $events[1]['name'] );
		$this->assertEquals( 'Purchase', $events[2]['name'] );
	}

	public function test_enqueue_event_initializes_hooks(): void {
		// Hooks should not be initialized yet
		$reflection        = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$hooks_initialized = $reflection->getProperty( 'hooks_initialized' );
		$hooks_initialized->setAccessible( true );

		$this->assertFalse( $hooks_initialized->getValue() );

		// Adding an event should initialize hooks
		WC_Facebookcommerce_Pixel::enqueue_event( 'ViewContent', array() );

		$this->assertTrue( $hooks_initialized->getValue() );
	}

	// =========================================================================
	// prepare_event_params() Tests
	// =========================================================================

	public function test_prepare_event_params_removes_event_name(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$method     = $reflection->getMethod( 'prepare_event_params' );
		$method->setAccessible( true );

		$params = array(
			'event_name'   => 'ViewContent',
			'content_ids'  => array( '123' ),
			'content_type' => 'product',
		);

		$result = $method->invoke( null, $params, 'ViewContent' );

		$this->assertArrayNotHasKey( 'event_name', $result['params'] );
		$this->assertArrayHasKey( 'content_ids', $result['params'] );
	}

	public function test_prepare_event_params_extracts_event_id(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$method     = $reflection->getMethod( 'prepare_event_params' );
		$method->setAccessible( true );

		$params = array(
			'event_id'    => 'my-unique-event-id',
			'content_ids' => array( '123' ),
		);

		$result = $method->invoke( null, $params, 'ViewContent' );

		$this->assertEquals( 'my-unique-event-id', $result['event_id'] );
		$this->assertArrayNotHasKey( 'event_id', $result['params'] );
	}

	public function test_prepare_event_params_unwraps_custom_data(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$method     = $reflection->getMethod( 'prepare_event_params' );
		$method->setAccessible( true );

		$params = array(
			'custom_data' => array(
				'content_ids'  => array( '123' ),
				'content_type' => 'product',
				'value'        => 29.99,
			),
		);

		$result = $method->invoke( null, $params, 'ViewContent' );

		$this->assertArrayHasKey( 'content_ids', $result['params'] );
		$this->assertArrayHasKey( 'value', $result['params'] );
		$this->assertArrayNotHasKey( 'custom_data', $result['params'] );
	}

	public function test_prepare_event_params_returns_empty_event_id_when_not_present(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$method     = $reflection->getMethod( 'prepare_event_params' );
		$method->setAccessible( true );

		$params = array( 'content_ids' => array( '123' ) );

		$result = $method->invoke( null, $params, 'ViewContent' );

		$this->assertEquals( '', $result['event_id'] );
	}

	public function test_prepare_event_params_adds_version_info(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$method     = $reflection->getMethod( 'prepare_event_params' );
		$method->setAccessible( true );

		$params = array( 'content_ids' => array( '123' ) );

		$result = $method->invoke( null, $params, 'ViewContent' );

		// Version info should be added by build_params()
		$this->assertArrayHasKey( 'source', $result['params'] );
		$this->assertArrayHasKey( 'pluginVersion', $result['params'] );
	}

	// =========================================================================
	// init_external_js_hooks() Tests
	// =========================================================================

	public function test_init_external_js_hooks_registers_wp_enqueue_scripts_action(): void {
		WC_Facebookcommerce_Pixel::init_external_js_hooks();

		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( WC_Facebookcommerce_Pixel::class, 'enqueue_pixel_events_script' ) )
		);
	}

	public function test_init_external_js_hooks_registers_wp_footer_action(): void {
		WC_Facebookcommerce_Pixel::init_external_js_hooks();

		$this->assertNotFalse(
			has_action( 'wp_footer', array( WC_Facebookcommerce_Pixel::class, 'localize_pixel_events_data' ) )
		);
	}

	public function test_init_external_js_hooks_only_initializes_once(): void {
		WC_Facebookcommerce_Pixel::init_external_js_hooks();
		WC_Facebookcommerce_Pixel::init_external_js_hooks();
		WC_Facebookcommerce_Pixel::init_external_js_hooks();

		// Should only have one callback registered (not multiple)
		$reflection        = new ReflectionClass( WC_Facebookcommerce_Pixel::class );
		$hooks_initialized = $reflection->getProperty( 'hooks_initialized' );
		$hooks_initialized->setAccessible( true );

		$this->assertTrue( $hooks_initialized->getValue() );
	}

	// =========================================================================
	// WC_Facebookcommerce_Utils::get_deferred_events_transient_key() Tests
	// =========================================================================

	public function test_get_deferred_events_transient_key_returns_string(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Utils::class );
		$method     = $reflection->getMethod( 'get_deferred_events_transient_key' );
		$method->setAccessible( true );

		$key = $method->invoke( null );

		// Key should be a string (could be empty if no session)
		$this->assertIsString( $key );
	}

	// =========================================================================
	// Event Data Structure Tests
	// =========================================================================

	public function test_queued_event_has_correct_structure(): void {
		WC_Facebookcommerce_Pixel::enqueue_event(
			'ViewContent',
			array(
				'content_ids'  => array( 'SKU123' ),
				'content_type' => 'product',
				'value'        => 99.99,
				'currency'     => 'USD',
			),
			'track',
			'unique-event-123'
		);

		$events = $this->get_event_queue();
		$event  = $events[0];

		// Verify structure matches what pixel-events.js expects
		$this->assertArrayHasKey( 'name', $event );
		$this->assertArrayHasKey( 'params', $event );
		$this->assertArrayHasKey( 'method', $event );
		$this->assertArrayHasKey( 'eventId', $event );

		// Verify types
		$this->assertIsString( $event['name'] );
		$this->assertIsArray( $event['params'] );
		$this->assertIsString( $event['method'] );
		$this->assertIsString( $event['eventId'] );
	}

	public function test_queued_event_params_are_preserved(): void {
		$original_params = array(
			'content_ids'   => array( 'SKU123', 'SKU456' ),
			'content_type'  => 'product',
			'value'         => 149.99,
			'currency'      => 'EUR',
			'content_name'  => 'Test Product',
			'num_items'     => 2,
		);

		WC_Facebookcommerce_Pixel::enqueue_event( 'Purchase', $original_params );

		$events = $this->get_event_queue();
		$params = $events[0]['params'];

		$this->assertEquals( $original_params['content_ids'], $params['content_ids'] );
		$this->assertEquals( $original_params['value'], $params['value'] );
		$this->assertEquals( $original_params['currency'], $params['currency'] );
	}

	// =========================================================================
	// enqueue_deferred_event() Tests
	// =========================================================================

	public function test_enqueue_deferred_event_stores_event_data(): void {
		$this->reset_deferred_events();

		WC_Facebookcommerce_Pixel::enqueue_deferred_event(
			'AddToCart',
			array(
				'content_ids'  => array( '123' ),
				'content_type' => 'product',
			),
			'track',
			'deferred-event-123'
		);

		WC_Facebookcommerce_Utils::save_deferred_events();

		$transient_key   = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertIsArray( $deferred_events );
		$this->assertCount( 1, $deferred_events );
		$this->assertIsArray( $deferred_events[0] );
		$this->assertEquals( 'AddToCart', $deferred_events[0]['name'] );
		$this->assertEquals( 'track', $deferred_events[0]['method'] );
		$this->assertEquals( 'deferred-event-123', $deferred_events[0]['eventId'] );

		// Cleanup
		delete_transient( $transient_key );
	}

	public function test_enqueue_deferred_event_without_event_id(): void {
		$this->reset_deferred_events();

		WC_Facebookcommerce_Pixel::enqueue_deferred_event(
			'ViewContent',
			array( 'content_ids' => array( '456' ) )
		);

		WC_Facebookcommerce_Utils::save_deferred_events();

		$transient_key   = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertCount( 1, $deferred_events );
		$this->assertArrayNotHasKey( 'eventId', $deferred_events[0] );

		// Cleanup
		delete_transient( $transient_key );
	}

	public function test_enqueue_deferred_event_with_custom_method(): void {
		$this->reset_deferred_events();

		WC_Facebookcommerce_Pixel::enqueue_deferred_event(
			'CustomEvent',
			array( 'key' => 'value' ),
			'trackCustom'
		);

		WC_Facebookcommerce_Utils::save_deferred_events();

		$transient_key   = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertCount( 1, $deferred_events );
		$this->assertEquals( 'trackCustom', $deferred_events[0]['method'] );

		// Cleanup
		delete_transient( $transient_key );
	}

	// =========================================================================
	// inject_event() with Rollout Switch Tests
	// =========================================================================

	public function test_build_event_adds_browser_dedup_guard_when_event_id_is_present(): void {
		$event_id = 'add-to-cart-event-123';
		$event    = WC_Facebookcommerce_Pixel::build_event(
			'AddToCart',
			array(
				'content_ids' => array( 'PROD123' ),
				'event_id'    => $event_id,
			),
			'track'
		);

		$this->assertStringContainsString(
			'window.wcFacebookPixelFiredEvents = window.wcFacebookPixelFiredEvents || {};',
			$event
		);
		$this->assertStringContainsString(
			'if (!window.wcFacebookPixelFiredEvents["add-to-cart-event-123"]) {',
			$event
		);
		$this->assertStringContainsString(
			'window.wcFacebookPixelFiredEvents["add-to-cart-event-123"] = true;',
			$event
		);
		$this->assertStringContainsString(
			"var event = { name: 'AddToCart', params: {",
			$event
		);
		$this->assertStringContainsString(
			'eventId: isStale ? null : "add-to-cart-event-123" }',
			$event
		);
		$this->assertStringContainsString(
			'fbq(event.method, event.name, event.params, { eventID: event.eventId });',
			$event
		);
	}

	public function test_build_event_does_not_add_browser_dedup_guard_without_event_id(): void {
		$event = WC_Facebookcommerce_Pixel::build_event(
			'ViewContent',
			array( 'content_ids' => array( 'PROD123' ) ),
			'track'
		);

		$this->assertStringNotContainsString( 'window.wcFacebookPixelFiredEvents', $event );
		$this->assertStringContainsString( "var event = { name: 'ViewContent', params: {", $event );
		$this->assertStringContainsString( "method: 'track', eventId: null }", $event );
	}

	/**
	 * A consent manager or script optimiser can run the Pixel base code after
	 * the event code, so the event code must never call fbq() outside a block
	 * that first checks it exists, must hand the agent and the event to
	 * FacebookSignals when that is present, and must queue the event when
	 * neither is there yet.
	 *
	 * @dataProvider provider_build_event_params
	 */
	public function test_build_event_never_calls_fbq_unguarded( array $params ): void {
		$event = WC_Facebookcommerce_Pixel::build_event( 'ViewContent', $params, 'track' );

		$this->assert_every_fbq_call_is_guarded( $event );
		$this->assertMatchesRegularExpression( '/^\/\* .+ Facebook Integration Event Tracking \*\/\n;\(function\(\) \{\n/', $event );
		$this->assertStringContainsString( 'window.FacebookSignals.setAgent(agent);', $event );
		$this->assertStringContainsString( 'window.FacebookSignals.trackEvent(event.name, event.params, null, event.method, event.eventId);', $event );
		$this->assertStringContainsString( 'window.wcFacebookPixelQueuedEvents.push({ name: event.name, params: event.params, method: event.method, eventId: event.eventId, agent: agent, pixelId: pixelId });', $event );
	}

	public function provider_build_event_params(): array {
		return array(
			'with event id'    => array(
				array(
					'content_ids' => array( 'PROD123' ),
					'event_id'    => 'view-content-123',
				),
			),
			'without event id' => array( array( 'content_ids' => array( 'PROD123' ) ) ),
		);
	}

	/**
	 * The guard check must not be fooled by braces inside string literals, such
	 * as a product name in the JSON-encoded params.
	 */
	public function test_fbq_guard_check_ignores_braces_inside_strings(): void {
		$event = WC_Facebookcommerce_Pixel::build_event(
			'ViewContent',
			array(
				'content_name' => 'Mug } with { braces',
				'content_ids'  => array( 'PROD123' ),
				'event_id'     => 'view-content-123',
			),
			'track'
		);

		$this->assertStringContainsString( '"content_name": "Mug } with { braces"', $event );
		$this->assert_every_fbq_call_is_guarded( $event );
	}

	public function test_fbq_guard_check_detects_an_unguarded_call(): void {
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->expectExceptionMessage( 'Unguarded fbq() call' );

		$this->assert_every_fbq_call_is_guarded(
			"if (window.FacebookSignals) {\n\tfbq('set', 'agent', agent, pixelId);\n}\nif (typeof fbq === 'function') {\n\tfbq('track', 'ViewContent', {});\n}\n"
		);
	}

	/**
	 * Held-mode event code runs before the wrapper when a consent manager delays
	 * the base code, so it must never reference FacebookSignals bare and must
	 * leave the event in the page-level queue instead.
	 */
	public function test_queued_event_code_never_references_the_wrapper_bare(): void {
		$pixel = new WC_Facebookcommerce_Pixel();
		$code  = $pixel->get_queued_event_code(
			'ViewContent',
			array(
				'content_ids' => array( 'PROD123' ),
				'event_id'    => 'view-content-123',
			),
			'track'
		);

		$this->assertDoesNotMatchRegularExpression( '/(?<![\w.])FacebookSignals\./', $code );
		$this->assertMatchesRegularExpression( '/^\/\* .+ Facebook Integration Event Queueing \*\/\n;\(function\(\) \{\n/', $code );
		$this->assertStringContainsString( '"event_name":"ViewContent"', $code );
		$this->assertStringContainsString( "typeof window.FacebookSignals.queueEvent === 'function'", $code );
		$this->assertStringContainsString( 'window.FacebookSignals.queueEvent(event);', $code );
		$this->assertStringContainsString( "window.wcFacebookPixelQueuedEvents.push({ name: event.event_name, params: event.custom_data || {}, method: event.method || 'track', eventId: event.event_id || null, eventTime: event.event_time || null });", $code );
	}

	public function test_held_conditional_scripts_never_reference_the_wrapper_bare(): void {
		FacebookSignalsState::hold();
		try {
			$pixel   = new WC_Facebookcommerce_Pixel();
			$scripts = array(
				$pixel->get_conditional_event_script( 'ViewContent', array( 'content_ids' => array( 'PROD123' ) ), 'wc_fragments_loaded', '' ),
				$pixel->get_conditional_one_time_event_script( 'AddToCart', array( 'content_ids' => array( 'PROD123' ) ), 'added_to_cart' ),
			);
		} finally {
			FacebookSignalsState::release();
		}

		foreach ( $scripts as $script ) {
			$this->assertDoesNotMatchRegularExpression( '/(?<![\w.])FacebookSignals\./', $script );
			$this->assertStringContainsString( "typeof window.FacebookSignals.trackEvent === 'function'", $script );
			$this->assertStringContainsString( 'window.FacebookSignals.trackEvent(event.name, event.params);', $script );
			$this->assertStringContainsString( 'window.wcFacebookPixelQueuedEvents.push(event);', $script );
		}
	}

	/**
	 * The event code's stale-page check reads window.__wc_fb_page_generated. A
	 * consent manager that blocks the Pixel loader by matching connect.facebook.net
	 * must not delay the timestamp with it, or a stale cached page would replay
	 * its shared event ID.
	 */
	public function test_page_generated_timestamp_is_rendered_apart_from_the_pixel_loader(): void {
		$this->reset_static_properties();
		WC_Facebookcommerce_Pixel::set_pixel_id( '123456789' );

		$pixel = new WC_Facebookcommerce_Pixel();
		$code  = $pixel->pixel_base_code();

		preg_match_all( '/<script[^>]*>(.*?)<\/script>/is', $code, $matches );
		$scripts = $matches[1];
		$this->assertNotEmpty( $scripts, 'The base code rendered no script blocks.' );

		$timestamp_index = null;
		$loader_index    = null;
		foreach ( $scripts as $index => $script ) {
			if ( false !== strpos( $script, '__wc_fb_page_generated = ' ) ) {
				$timestamp_index = $index;
			}
			if ( false !== strpos( $script, 'connect.facebook.net' ) ) {
				$loader_index = $index;
			}
		}

		$this->assertNotNull( $timestamp_index, 'The page timestamp is missing.' );
		$this->assertNotNull( $loader_index, 'The Pixel loader is missing.' );
		$this->assertNotSame( $timestamp_index, $loader_index, 'The page timestamp shares a script block with the Pixel loader.' );
		$this->assertLessThan( $loader_index, $timestamp_index, 'The page timestamp must come before the Pixel loader.' );
		$this->assertStringNotContainsString( 'fbq', $scripts[ $timestamp_index ], 'The timestamp block must not match a consent manager rule on fbq.' );
	}

	/**
	 * Asserts that every fbq() call in generated JavaScript sits inside a block
	 * opened by a typeof fbq === 'function' check, whatever its indentation.
	 *
	 * Tracks the brace nesting and remembers the text that opened each block;
	 * braces inside string literals are data and do not count.
	 *
	 * @param string $code Generated JavaScript.
	 */
	private function assert_every_fbq_call_is_guarded( string $code ): void {
		$openers    = array();
		$line_start = 0;
		$calls      = 0;
		$quote      = null;
		$length     = strlen( $code );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $code[ $i ];

			if ( null !== $quote ) {
				if ( '\\' === $char ) {
					$i++;
				} elseif ( $quote === $char ) {
					$quote = null;
				}
				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$quote = $char;
			} elseif ( "\n" === $char ) {
				$line_start = $i + 1;
			} elseif ( '{' === $char ) {
				$openers[] = substr( $code, $line_start, $i - $line_start );
			} elseif ( '}' === $char ) {
				array_pop( $openers );
			} elseif ( 'fbq(' === substr( $code, $i, 4 ) && ( 0 === $i || ! preg_match( '/[\w.]/', $code[ $i - 1 ] ) ) ) {
				$calls++;
				$guarded = false;
				foreach ( $openers as $opener ) {
					if ( false !== strpos( $opener, "typeof fbq === 'function'" ) ) {
						$guarded = true;
						break;
					}
				}
				$this->assertTrue( $guarded, 'Unguarded fbq() call: ' . trim( substr( $code, $line_start, 100 ) ) );
			}
		}

		$this->assertGreaterThanOrEqual( 2, $calls, 'The generated code should set the agent and track the event through fbq() when only the Pixel is present.' );
	}

	public function test_inject_event_uses_isolated_execution_when_switch_enabled(): void {
		$this->reset_static_properties();
		$this->enable_isolated_pixel_execution_switch();

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event(
			'ViewContent',
			array(
				'content_ids'  => array( 'PROD123' ),
				'content_type' => 'product',
				'value'        => 49.99,
				'currency'     => 'USD',
			)
		);

		// Event should be in event_queue (isolated execution)
		$events = $this->get_event_queue();

		$this->assertCount( 1, $events );
		$this->assertEquals( 'ViewContent', $events[0]['name'] );
		$this->assertEquals( 'track', $events[0]['method'] );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	public function test_inject_event_sets_last_event_when_switch_enabled(): void {
		$this->reset_static_properties();
		$this->enable_isolated_pixel_execution_switch();

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event( 'Purchase', array( 'value' => 100 ) );

		$this->assertTrue( $pixel->is_last_event( 'Purchase' ) );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	/**
	 * Test that inject_event uses legacy execution when switch is disabled.
	 *
	 * When the isolated pixel execution switch is disabled, inject_event should:
	 * 1. NOT add events to the event_queue (proves we're not using isolated path)
	 * 2. Set last_event correctly (proves get_event_code() was called in legacy path)
	 * 3. Call enqueue_inline_js() to add inline script via wp_add_inline_script()
	 *
	 */
	public function test_inject_event_uses_legacy_execution_when_switch_disabled(): void {
		$this->reset_static_properties();
		$this->disable_isolated_pixel_execution_switch();

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event(
			'ViewContent',
			array(
				'content_ids'  => array( 'PROD456' ),
				'content_type' => 'product',
			)
		);

		// Event should NOT be in event_queue (legacy uses enqueue_inline_js)
		$events = $this->get_event_queue();
		$this->assertCount( 0, $events );

		// Verify last_event is set (proves get_event_code() was called in legacy path)
		$this->assertTrue( $pixel->is_last_event( 'ViewContent' ) );
	}

	public function test_inject_event_defers_add_to_cart_with_redirect_when_switch_enabled(): void {
		$this->reset_static_properties();
		$this->reset_deferred_events();
		$this->enable_isolated_pixel_execution_switch();

		// Enable cart redirect
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event(
			'AddToCart',
			array( 'content_ids' => array( 'CART123' ) )
		);

		// Event should NOT be in event_queue (deferred)
		$events = $this->get_event_queue();
		$this->assertCount( 0, $events );

		// Save and check deferred events
		WC_Facebookcommerce_Utils::save_deferred_events();
		$transient_key   = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertIsArray( $deferred_events );
		$this->assertCount( 1, $deferred_events );
		$this->assertIsArray( $deferred_events[0] ); // Should be array format (isolated)
		$this->assertEquals( 'AddToCart', $deferred_events[0]['name'] );

		// Cleanup
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );
		delete_transient( $transient_key );
		$this->disable_isolated_pixel_execution_switch();
	}

	public function test_inject_event_defers_add_to_cart_with_redirect_when_switch_disabled(): void {
		$this->reset_static_properties();
		$this->reset_deferred_events();
		$this->disable_isolated_pixel_execution_switch();

		// Enable cart redirect
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event(
			'AddToCart',
			array( 'content_ids' => array( 'CART456' ) )
		);

		// Save and check deferred events
		WC_Facebookcommerce_Utils::save_deferred_events();
		$transient_key   = $this->get_deferred_events_transient_key();
		$deferred_events = get_transient( $transient_key );

		$this->assertIsArray( $deferred_events );
		$this->assertCount( 1, $deferred_events );
		$this->assertIsString( $deferred_events[0] ); // Should be string format (legacy)
		$this->assertStringContainsString( 'fbq', $deferred_events[0] );
		$this->assertStringContainsString( 'AddToCart', $deferred_events[0] );

		// Cleanup
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );
		delete_transient( $transient_key );
	}

	public function test_inject_event_does_not_defer_non_add_to_cart_with_redirect(): void {
		$this->reset_static_properties();
		$this->reset_deferred_events();
		$this->enable_isolated_pixel_execution_switch();

		// Enable cart redirect
		update_option( 'woocommerce_cart_redirect_after_add', 'yes' );

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event(
			'ViewContent',
			array( 'content_ids' => array( 'VIEW123' ) )
		);

		// ViewContent should NOT be deferred even with redirect enabled
		$events = $this->get_event_queue();
		$this->assertCount( 1, $events );
		$this->assertEquals( 'ViewContent', $events[0]['name'] );

		// Cleanup
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );
		$this->disable_isolated_pixel_execution_switch();
	}

	public function test_inject_event_does_not_defer_add_to_cart_without_redirect(): void {
		$this->reset_static_properties();
		$this->reset_deferred_events();
		$this->enable_isolated_pixel_execution_switch();

		// Disable cart redirect
		update_option( 'woocommerce_cart_redirect_after_add', 'no' );

		$pixel = new WC_Facebookcommerce_Pixel();
		$pixel->inject_event(
			'AddToCart',
			array( 'content_ids' => array( 'CART789' ) )
		);

		// AddToCart should NOT be deferred without redirect
		$events = $this->get_event_queue();
		$this->assertCount( 1, $events );
		$this->assertEquals( 'AddToCart', $events[0]['name'] );

		// Cleanup
		$this->disable_isolated_pixel_execution_switch();
	}

	// =========================================================================
	// Helper Methods
	// =========================================================================

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

	/**
	 * Reset deferred events static array.
	 */
	private function reset_deferred_events(): void {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Utils::class );
		$property   = $reflection->getProperty( 'deferred_events' );
		$property->setAccessible( true );
		$property->setValue( null, array() );
	}

	/**
	 * Get the deferred events transient key.
	 */
	private function get_deferred_events_transient_key(): string {
		$reflection = new ReflectionClass( WC_Facebookcommerce_Utils::class );
		$method     = $reflection->getMethod( 'get_deferred_events_transient_key' );
		$method->setAccessible( true );
		return $method->invoke( null );
	}
}
