<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Unit tests for the WhatsApp utility message order hook.
 */

namespace WooCommerce\Facebook\Tests\Handlers;

use WooCommerce\Facebook\Handlers\WhatsAppConnection;
use WooCommerce\Facebook\RolloutSwitches;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Covers WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event::process_wc_order_status_changed().
 *
 * A store that never connected WhatsApp utility messaging must not log anything for
 * its orders: the old per-order lines ("Customer Events Post API call for Order id N
 * skipped due to missing Order info" / "Failed due to failed connection") were read by
 * merchants as the Purchase event failing (issue #4019). A connected store keeps its
 * lines, worded so they are recognisably about WhatsApp.
 *
 * @package WooCommerce\Facebook\Tests\Unit\Handlers
 */
class WhatsAppUtilityEventTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * URLs an outbound HTTP request was attempted against during the test.
	 *
	 * @var string[]
	 */
	private $http_requests = [];

	/**
	 * Messages written through wc_get_logger() during the test.
	 *
	 * @var string[]
	 */
	private $log_messages = [];

	public function setUp(): void {
		parent::setUp();
		$this->http_requests = [];
		$this->log_messages  = [];

		// Intercept all outbound HTTP: record the URL and short-circuit with a fake 200.
		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->http_requests[] = $url;
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '{}',
				);
			},
			10,
			3
		);

		// Replace the WooCommerce logger with a mock that records every message, whichever
		// level it is written at, so the lines the hook writes can be asserted.
		$logger = $this->createMock( \WC_Logger_Interface::class );
		$record = function ( $message ) {
			$this->log_messages[] = (string) $message;
		};
		foreach ( array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' ) as $level ) {
			$logger->method( $level )->willReturnCallback( $record );
		}
		$logger->method( 'log' )->willReturnCallback(
			function ( $level, $message ) use ( $record ) {
				$record( $message );
			}
		);
		$this->add_filter_with_safe_teardown(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		// Keep the onboarding gate out of the picture: these tests cover the connection gate.
		$this->mock_set_option(
			'wc_facebook_for_woocommerce_rollout_switches',
			array( RolloutSwitches::SWITCH_WA_CUSTOMER_EVENTS_GATING_ENABLED => 'no' )
		);
	}

	public function tearDown(): void {
		parent::tearDown();
		// The filter is gone; make wc_get_logger() drop the cached mock on its next call.
		wc_get_logger();
	}

	private function processor(): \WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event {
		return new \WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event( facebook_for_woocommerce() );
	}

	/**
	 * Stores the options a connected WhatsApp utility messaging install has.
	 */
	private function connect_whatsapp(): void {
		$this->mock_set_option( WhatsAppConnection::OPTION_WA_UTILITY_ACCESS_TOKEN, 'test-token' );
		$this->mock_set_option( WhatsAppConnection::OPTION_WA_INSTALLATION_ID, '123456789' );
	}

	private function create_order( bool $with_contact_details ): \WC_Order {
		$order = wc_create_order();
		if ( $with_contact_details ) {
			$order->set_billing_first_name( 'Jane' );
			$order->set_billing_phone( '15551234567' );
			$order->set_billing_country( 'US' );
		}
		$order->save();
		return $order;
	}

	private function fire_processing( \WC_Order $order ): void {
		$this->processor()->process_wc_order_status_changed( (string) $order->get_id(), 'pending', 'processing' );
	}

	/**
	 * @return string[] recorded request URLs that targeted the customer_events endpoint
	 */
	private function customer_events_requests(): array {
		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $url ) {
					return false !== strpos( (string) $url, 'customer_events' );
				}
			)
		);
	}

	private function assert_no_line_reads_like_a_purchase_failure(): void {
		foreach ( $this->log_messages as $message ) {
			$this->assertStringNotContainsString( 'Customer Events Post', $message );
			$this->assertStringContainsString( 'WhatsApp', $message, 'Every line the hook writes must say it is about WhatsApp.' );
		}
	}

	/** Store without WhatsApp: an order changing status writes no log line and sends nothing. */
	public function test_not_connected_store_logs_nothing_and_sends_nothing() {
		$this->fire_processing( $this->create_order( true ) );

		$this->assertSame( array(), $this->log_messages, 'A store that never connected WhatsApp must not log per order.' );
		$this->assertCount( 0, $this->customer_events_requests() );
	}

	/** Connected store, order without a phone number or first name: one WhatsApp line, no request. */
	public function test_connected_store_without_contact_details_logs_why_nothing_was_sent() {
		$this->connect_whatsapp();
		$order = $this->create_order( false );

		$this->fire_processing( $order );

		$this->assertCount( 0, $this->customer_events_requests() );
		$this->assertCount( 1, $this->log_messages );
		$this->assertStringContainsString( "WhatsApp utility message for order {$order->get_id()} not sent", $this->log_messages[0] );
		$this->assertStringContainsString( 'phone number', $this->log_messages[0] );
		$this->assert_no_line_reads_like_a_purchase_failure();
	}

	/** Connected store, order with contact details: the customer event is sent and logged as sent. */
	public function test_connected_store_with_contact_details_sends_the_customer_event() {
		$this->connect_whatsapp();
		$order = $this->create_order( true );

		$this->fire_processing( $order );

		$this->assertCount( 1, $this->customer_events_requests() );
		$this->assertNotEmpty( $this->log_messages );
		$this->assertStringContainsString( "WhatsApp utility message for order {$order->get_id()} sent.", end( $this->log_messages ) );
		$this->assert_no_line_reads_like_a_purchase_failure();
	}

	/** Statuses that have no WhatsApp event are ignored even on a connected store. */
	public function test_unsupported_status_is_ignored() {
		$this->connect_whatsapp();
		$order = $this->create_order( true );

		$this->processor()->process_wc_order_status_changed( (string) $order->get_id(), 'pending', 'on-hold' );

		$this->assertSame( array(), $this->log_messages );
		$this->assertCount( 0, $this->customer_events_requests() );
	}
}
