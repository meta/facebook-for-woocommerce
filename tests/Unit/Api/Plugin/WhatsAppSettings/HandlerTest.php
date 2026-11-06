<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Unit tests for the WhatsApp Settings REST handler.
 */

namespace WooCommerce\Facebook\Tests\API\Plugin\WhatsAppSettings;

use WooCommerce\Facebook\API\Plugin\WhatsAppSettings\Handler;
use WooCommerce\Facebook\API\Plugin\WhatsAppSettings\OnboardingComplete\Request as OnboardingCompleteRequest;
use WooCommerce\Facebook\Handlers\WhatsAppConnection;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Covers the WA_CONNECT push endpoint that marks onboarding complete, and the
 * failure logging of the update and uninstall endpoints.
 *
 * @package WooCommerce\Facebook\Tests\Unit\API\Plugin\WhatsAppSettings
 */
class HandlerTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Lines written through wc_get_logger() during the test, as "level: message".
	 *
	 * @var string[]
	 */
	private $log_lines = [];

	public function setUp(): void {
		parent::setUp();
		$this->log_lines = [];

		// Replace the WooCommerce logger with a mock that records every line with its level.
		$logger = $this->createMock( \WC_Logger_Interface::class );
		foreach ( array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' ) as $level ) {
			$logger->method( $level )->willReturnCallback(
				function ( $message ) use ( $level ) {
					$this->log_lines[] = $level . ': ' . (string) $message;
				}
			);
		}
		$this->add_filter_with_safe_teardown(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);
	}

	public function tearDown(): void {
		parent::tearDown();
		// The filter is gone; make wc_get_logger() drop the cached mock on its next call.
		wc_get_logger();
	}

	public function test_handle_onboarding_complete_marks_onboarding_complete() {
		// Precondition: not yet onboarded.
		$connection = facebook_for_woocommerce()->get_whatsapp_connection_handler();
		$this->assertFalse( $connection->is_onboarding_complete() );

		$response = ( new Handler() )->handle_onboarding_complete( new \WP_REST_Request( 'POST' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $connection->is_onboarding_complete() );
	}

	public function test_onboarding_complete_request_js_definition() {
		$request = new OnboardingCompleteRequest( new \WP_REST_Request( 'POST' ) );

		$this->assertSame( 'whatsapp_settings/onboarding_complete', $request->get_endpoint() );
		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( 'notifyWhatsAppOnboardingComplete', $request->get_js_function_name() );
		$this->assertTrue( OnboardingCompleteRequest::is_js_exposable() );
	}

	/** A failing settings update logs an error line that carries the exception message. */
	public function test_update_failure_logs_the_exception_message() {
		// Make the first option write throw, as a failing database would.
		$this->add_filter_with_safe_teardown(
			'pre_update_option_' . WhatsAppConnection::OPTION_WA_UTILITY_ACCESS_TOKEN,
			static function () {
				throw new \Exception( 'database is read-only' );
			}
		);

		$request = new \WP_REST_Request( 'POST' );
		foreach ( array( 'access_token', 'business_id', 'phone_number_id', 'waba_id', 'wa_installation_id' ) as $param ) {
			$request->set_param( $param, 'test-' . $param );
		}

		$response = ( new Handler() )->handle_update( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertCount( 1, $this->log_lines );
		$this->assertStringStartsWith( 'error: Failed to handle_update for WhatsApp', $this->log_lines[0] );
		$this->assertStringContainsString( 'database is read-only', $this->log_lines[0] );
		$this->assertStringNotContainsString( '%s', $this->log_lines[0] );
	}

	/** A failing uninstall logs an error line that carries the exception message. */
	public function test_uninstall_failure_logs_the_exception_message() {
		// delete_option() only fires its action for a stored row, so store one for real
		// (the test transaction rolls it back).
		add_option( WhatsAppConnection::OPTION_WA_UTILITY_ACCESS_TOKEN, 'test-token' );

		// Make the first option deletion throw.
		$this->add_filter_with_safe_teardown(
			'delete_option',
			static function ( $option ) {
				if ( WhatsAppConnection::OPTION_WA_UTILITY_ACCESS_TOKEN === $option ) {
					throw new \Exception( 'options table locked' );
				}
			}
		);

		$response = ( new Handler() )->handle_uninstall( new \WP_REST_Request( 'POST' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertCount( 1, $this->log_lines );
		$this->assertStringStartsWith( 'error: Failed to handle_uninstall for WhatsApp', $this->log_lines[0] );
		$this->assertStringContainsString( 'options table locked', $this->log_lines[0] );
		$this->assertStringNotContainsString( '%s', $this->log_lines[0] );
	}
}
