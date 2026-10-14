<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\API\Plugin\Settings;

use WooCommerce\Facebook\API\CommerceIntegration\Client;
use WooCommerce\Facebook\API\CommerceIntegration\Exception;
use WooCommerce\Facebook\API\CommerceIntegration\Finalize\Response as FinalizeResponse;
use WooCommerce\Facebook\API\Plugin\Settings\Handler;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Tests the settings update finalize-install migration flow.
 *
 * These tests use no Meta entities; all IDs are opaque local fixtures.
 */
class HandlerFinalizeInstallTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var array Checkout setup requests sent to Graph: [ url, method, decoded body ]. */
	private $checkout_requests = array();

	/** @var array|\WP_Error Response returned for checkout setup requests. */
	private $checkout_response;

	/**
	 * Intercepts checkout setup requests so no test reaches Graph.
	 */
	public function setUp(): void {
		parent::setUp();
		// /checkout-link/ only routes with pretty permalinks; the test install defaults to plain.
		$this->mock_set_option( 'permalink_structure', '/%postname%/' );
		$this->checkout_response = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => '{"success":true}',
		);
		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function( $preempt, $args, $url ) {
				if ( false === strpos( (string) $url, '/cms-' ) ) {
					return $preempt;
				}

				$this->checkout_requests[] = array( $url, $args['method'], json_decode( $args['body'], true ) );

				return $this->checkout_response;
			},
			10,
			3
		);
	}

	/**
	 * Cleans up shared transient state.
	 */
	public function tearDown(): void {
		delete_transient( Logger::LOGGING_MESSAGE_QUEUE );
		delete_transient( 'wc_facebook_connection_invalid' );
		parent::tearDown();
	}

	/**
	 * Tests that finalize-install assets override the legacy install message.
	 */
	public function test_finalize_install_response_is_the_primary_asset_source(): void {
		$this->set_external_business_id( 'store-ebid-123' );
		$this->mock_set_option( Logger::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'catalog-final' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'cpi-final' );
		$option_updates = array();
		$this->record_option_updates( $option_updates );
		$final_response = new FinalizeResponse(
			wp_json_encode(
				array(
					'id'                            => 'cpi-final',
					'external_business_id'          => 'store-ebid-123',
					'installation_status'           => 'ACCESS_TOKEN_DEPOSITED',
					'commerce_merchant_settings_id' => 'cms-final',
					'catalog_id'                    => 'catalog-final',
					'pixel_id'                      => 'pixel-final',
				)
			)
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )
			->method( 'finalize_install' )
			->with( 'fresh-suat', 'store-ebid-123', facebook_for_woocommerce()->get_version() )
			->willReturnCallback(
				function() use ( $final_response ) {
					$this->assertSame( 'fresh-suat', get_option( \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN ) );

					return $final_response;
				}
			);

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array(
					'access_token'                           => 'fresh-suat',
					'external_business_id'                   => 'untrusted-browser-ebid',
					'commerce_partner_integration_id'        => 'cpi-legacy',
					'commerce_merchant_settings_id'          => 'cms-legacy',
					'product_catalog_id'                     => 'catalog-legacy',
					'pixel_id'                               => 'pixel-legacy',
					'installed_features'                     => array(
						array(
							'feature_type'     => 'pixel',
							'connected_assets' => array( 'pixel_id' => 'pixel-installed-feature' ),
						),
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fresh-suat', $option_updates[ \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN ] );
		$this->assertSame( 'cpi-final', $option_updates[ \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID ] );
		$this->assertSame( 'cms-final', $option_updates[ \WC_Facebookcommerce_Integration::OPTION_COMMERCE_MERCHANT_SETTINGS_ID ] );
		$this->assertSame( 'catalog-final', $option_updates[ \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ] );
		$this->assertSame( 'pixel-final', $option_updates[ \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID ] );

		$log = $this->find_finalize_install_log();
		$this->assertSame( 'success', $log['event_type'] );
		$this->assertSame( 'cpi-final', $log['extra_data']['commerce_partner_integration_id'] );
		$this->assertSame( 200, $log['extra_data']['http_status'] );
		$this->assertStringNotContainsString( 'fresh-suat', wp_json_encode( $log ) );
	}

	/**
	 * Tests that optional legacy assets remain available during the migration.
	 */
	public function test_sparse_finalize_response_preserves_current_install_optional_assets(): void {
		$this->set_external_business_id( 'store-ebid-123' );
		$this->mock_set_option( Logger::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'catalog-legacy' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'cpi-final' );

		$client = $this->createMock( Client::class );
		$client->method( 'finalize_install' )->willReturn(
			new FinalizeResponse(
				'{"id":"cpi-final","external_business_id":"store-ebid-123","installation_status":"ACCESS_TOKEN_DEPOSITED"}'
			)
		);

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array(
					'access_token'                  => 'fresh-suat',
					'commerce_merchant_settings_id' => 'cms-legacy',
					'product_catalog_id'            => 'catalog-legacy',
					'pixel_id'                      => 'pixel-top-level',
					'installed_features'            => array(
						array(
							'feature_type'     => 'pixel',
							'connected_assets' => array( 'pixel_id' => 'pixel-installed-feature' ),
						),
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_MERCHANT_SETTINGS_ID, 'cms-legacy' );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'catalog-legacy' );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, 'pixel-installed-feature' );

		$log = $this->find_finalize_install_log();
		$this->assertSame( 'success_with_legacy_asset_fallback', $log['event_type'] );
		$this->assertSame(
			'commerce_merchant_settings_id,product_catalog_id,pixel_id',
			$log['extra_data']['legacy_asset_fallback_fields']
		);
	}

	/**
	 * Tests that the current FBE install payload remains the rollout fallback.
	 */
	public function test_finalize_install_failure_uses_current_install_payload_as_fallback(): void {
		$this->set_external_business_id( 'store-ebid-123' );
		$this->mock_set_option( Logger::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'catalog-legacy' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'cpi-legacy' );

		$client = $this->createMock( Client::class );
		$client->method( 'finalize_install' )
			->willThrowException( new Exception( 'Service unavailable.', 'server_error', 503 ) );

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array(
					'access_token'                           => 'fresh-suat',
					'commerce_partner_integration_id'        => 'cpi-legacy',
					'commerce_merchant_settings_id'          => 'cms-legacy',
					'product_catalog_id'                     => 'catalog-legacy',
					'pixel_id'                               => 'pixel-legacy',
					'installed_features'                     => array(
						array(
							'feature_type'     => 'pixel',
							'connected_assets' => array( 'pixel_id' => 'pixel-installed-feature' ),
						),
					),
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'cpi-legacy' );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_MERCHANT_SETTINGS_ID, 'cms-legacy' );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'catalog-legacy' );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, 'pixel-installed-feature' );

		$log = $this->find_finalize_install_log();
		$this->assertSame( 'fallback', $log['event_type'] );
		$this->assertSame( 'commerce_partner_integration_finalize_install', $log['event'] );
		$this->assertSame( 'store-ebid-123', $log['extra_data']['external_business_id'] );
		$this->assertSame( 'cpi-legacy', $log['extra_data']['commerce_partner_integration_id'] );
		$this->assertSame( 'server_error', $log['extra_data']['failure_reason'] );
		$this->assertSame( 503, $log['extra_data']['http_status'] );
		$this->assertStringNotContainsString( 'fresh-suat', wp_json_encode( $log ) );
		$this->assertSame( array(), $this->checkout_requests, 'Checkout is only set up after finalize-install succeeds.' );
	}

	/**
	 * Tests that a successful finalize-install sets up checkout for the integration country.
	 */
	public function test_successful_finalize_sets_up_checkout_for_the_integration_country(): void {
		$this->finalize_with_response( array( 'country_code' => 'GB' ) );

		$this->assertSame(
			array(
				array(
					'checkout_config' => array(
						'checkout_url' => home_url( '/checkout-link/' ),
						'country_code' => 'GB',
						'publish_shop' => true,
					),
				),
			),
			array_column( $this->checkout_requests, 2 )
		);
		$this->assertSame( 'POST', $this->checkout_requests[0][1] );
		$this->assertStringEndsWith( '/cms-final', wp_parse_url( $this->checkout_requests[0][0], PHP_URL_PATH ) );
		$log = $this->find_checkout_setup_log();
		$this->assertSame( 'success', $log['event_type'] );
		$this->assertSame( home_url( '/checkout-link/' ), $log['extra_data']['checkout_url'] );
	}

	/**
	 * Tests that checkout falls back to the store's base country when the integration has none.
	 */
	public function test_checkout_setup_falls_back_to_the_store_base_country(): void {
		$this->mock_set_option( 'woocommerce_default_country', 'CA' );

		$this->finalize_with_response( array() );

		$this->assertCount( 1, $this->checkout_requests );
		$this->assertSame( 'CA', $this->checkout_requests[0][2]['checkout_config']['country_code'] );
	}

	/**
	 * Tests that the checkout URL uses the site address when WordPress lives in a subdirectory.
	 */
	public function test_checkout_url_uses_the_site_address_not_the_wordpress_address(): void {
		$this->mock_set_option( 'siteurl', 'http://example.org/wp' );
		$this->mock_set_option( 'home', 'http://example.org' );

		$this->finalize_with_response( array( 'country_code' => 'US' ) );

		$this->assertCount( 1, $this->checkout_requests );
		$this->assertSame( 'http://example.org/checkout-link/', $this->checkout_requests[0][2]['checkout_config']['checkout_url'] );
	}

	/**
	 * Tests that checkout is skipped and logged on plain permalinks, where /checkout-link/ does not route.
	 */
	public function test_checkout_setup_is_skipped_on_plain_permalinks(): void {
		$this->mock_set_option( 'permalink_structure', '' );

		$this->finalize_with_response( array( 'country_code' => 'US' ) );

		$this->assertSame( array(), $this->checkout_requests );
		$log = $this->find_checkout_setup_log();
		$this->assertSame( 'skipped', $log['event_type'] );
		$this->assertFalse( $log['extra_data']['pretty_permalinks'] );
	}

	/**
	 * Tests that checkout is skipped quietly without a Commerce Merchant Settings ID.
	 */
	public function test_checkout_setup_is_skipped_quietly_without_a_commerce_merchant_settings_id(): void {
		$this->finalize_with_response( array( 'commerce_merchant_settings_id' => '' ) );

		$this->assertSame( array(), $this->checkout_requests );
		$events = array_column( (array) get_transient( Logger::LOGGING_MESSAGE_QUEUE ), 'event' );
		$this->assertNotContains( 'commerce_merchant_settings_checkout_setup', $events );
	}

	/**
	 * Tests that a failed checkout setup is logged and never fails the installation.
	 *
	 * @dataProvider checkout_setup_failure_provider
	 *
	 * @param array|\WP_Error $checkout_response Response returned for the checkout setup request.
	 * @param string          $expected_error Error recorded in the log.
	 */
	public function test_checkout_setup_failure_does_not_fail_the_install( $checkout_response, string $expected_error ): void {
		$this->checkout_response = $checkout_response;

		$response = $this->finalize_with_response( array( 'country_code' => 'US' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_MERCHANT_SETTINGS_ID, 'cms-final' );
		$log = $this->find_checkout_setup_log();
		$this->assertSame( 'failed', $log['event_type'] );
		$this->assertStringContainsString( $expected_error, $log['extra_data']['error'] );
	}

	/**
	 * Checkout setup failure modes: a Graph error response and a transport error.
	 *
	 * @return array
	 */
	public function checkout_setup_failure_provider(): array {
		return array(
			'graph error'     => array(
				array(
					'response' => array( 'code' => 400, 'message' => 'Bad Request' ),
					'body'     => '{"error":{"message":"Invalid parameter","type":"OAuthException","code":100}}',
				),
				'Invalid parameter',
			),
			'transport error' => array( new \WP_Error( 'http_request_failed', 'Connection timed out.' ), 'Connection timed out.' ),
		);
	}

	/**
	 * Tests that authentication failures do not mark the connection complete.
	 */
	public function test_finalize_install_authentication_failure_does_not_use_fallback(): void {
		$this->set_external_business_id( 'store-ebid-123' );
		$this->mock_set_option( Logger::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );
		$this->mock_set_option( \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN, 'previous-access-token' );
		$option_updates = array();
		$this->record_option_updates( $option_updates );
		delete_transient( 'wc_facebook_connection_invalid' );

		$client = $this->createMock( Client::class );
		$client->method( 'finalize_install' )
			->willThrowException( new Exception( 'Unauthorized.', 'unauthorized', 401 ) );

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array(
					'access_token'                    => 'invalid-suat',
					'commerce_partner_integration_id' => 'cpi-legacy',
				)
			)
		);

		$this->assertSame( 401, $response->get_status() );
		// A rejected token is dropped rather than rolled back, so the store is left disconnected.
		$this->assertSame( '', $option_updates[ \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN ] );
		$this->assertOptionNotUpdated( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		$this->assertOptionNotUpdated( \WC_Facebookcommerce_Integration::OPTION_HAS_CONNECTED_FBE_2 );
		$this->assertNotFalse( get_transient( 'wc_facebook_connection_invalid' ) );

		$log = $this->find_finalize_install_log();
		$this->assertSame( 'failed_authentication', $log['event_type'] );
		$this->assertSame( 'unauthorized', $log['extra_data']['failure_reason'] );
		$this->assertSame( 401, $log['extra_data']['http_status'] );
		$this->assertStringNotContainsString( 'invalid-suat', wp_json_encode( $log ) );
	}

	/**
	 * Tests that an EBID mismatch cannot fall back to unverified message assets.
	 */
	public function test_finalize_install_ebid_mismatch_does_not_use_fallback(): void {
		$this->set_external_business_id( 'store-ebid-123' );

		$client = $this->createMock( Client::class );
		$client->method( 'finalize_install' )
			->willThrowException(
				new Exception(
					'External business ID mismatch.',
					'external_business_id_mismatch'
				)
			);

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array(
					'access_token'                    => 'fresh-suat',
					'commerce_partner_integration_id' => 'cpi-legacy',
				)
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertOptionUpdated( \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN, '' );
		$this->assertOptionNotUpdated( \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		$this->assertOptionNotUpdated( \WC_Facebookcommerce_Integration::OPTION_HAS_CONNECTED_FBE_2 );
	}

	/**
	 * Tests that token validation happens before the outbound request.
	 */
	public function test_missing_token_does_not_call_finalize_install(): void {
		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'finalize_install' );

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array()
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertOptionNotUpdated( \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN );
	}

	/**
	 * Tests that finalization is not attempted when token persistence fails.
	 */
	public function test_token_persistence_failure_does_not_call_finalize_install(): void {
		$option_updates = array();
		$this->record_option_updates( $option_updates );
		$this->add_filter_with_safe_teardown(
			'pre_option',
			function( $value, $option_name ) {
				if ( \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN === $option_name ) {
					return 'previous-token';
				}

				return $value;
			},
			20,
			2
		);

		$client = $this->createMock( Client::class );
		$client->expects( $this->never() )->method( 'finalize_install' );

		$response = ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request(
				array(
					'access_token' => 'fresh-suat',
				)
			)
		);

		$this->assertSame( 500, $response->get_status() );
		// A token that could not be stored is cleared, not rolled back.
		$this->assertSame( '', $option_updates[ \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN ] );
		$this->assertOptionNotUpdated( \WC_Facebookcommerce_Integration::OPTION_HAS_CONNECTED_FBE_2 );
	}

	/**
	 * Sets the stable EBID returned by the connection handler.
	 *
	 * @param string $external_business_id External business ID.
	 * @return void
	 */
	private function set_external_business_id( string $external_business_id ): void {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_external_business_id',
			function() use ( $external_business_id ) {
				return $external_business_id;
			},
			10,
			2
		);
	}

	/**
	 * Records option writes before the isolation helper short-circuits them.
	 *
	 * @param array $updates Array updated by reference as options are written.
	 * @return void
	 */
	private function record_option_updates( array &$updates ) {
		$this->add_filter_with_safe_teardown(
			'pre_update_option',
			function( $value, $option_name ) use ( &$updates ) {
				$updates[ $option_name ] = $value;
				return $value;
			},
			5,
			2
		);
	}

	/**
	 * Finds the finalize-install event in the Meta logging queue.
	 *
	 * @return array
	 */
	private function find_finalize_install_log(): array {
		$logs = get_transient( Logger::LOGGING_MESSAGE_QUEUE );
		$this->assertIsArray( $logs );

		foreach ( $logs as $log ) {
			if ( 'commerce_partner_integration_finalize_install' === ( $log['event'] ?? '' ) ) {
				return $log;
			}
		}

		$this->fail( 'Finalize-install log was not queued.' );
	}

	/**
	 * Runs a finalize-install that succeeds with the given response fields.
	 *
	 * @param array $fields Finalize-install response fields overriding the defaults.
	 * @return \WP_REST_Response
	 */
	private function finalize_with_response( array $fields ): \WP_REST_Response {
		$this->set_external_business_id( 'store-ebid-123' );
		$this->mock_set_option( Logger::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );

		$client = $this->createMock( Client::class );
		$client->method( 'finalize_install' )->willReturn(
			new FinalizeResponse(
				wp_json_encode(
					array_merge(
						array(
							'id'                            => 'cpi-final',
							'external_business_id'          => 'store-ebid-123',
							'installation_status'           => 'ACCESS_TOKEN_DEPOSITED',
							'commerce_merchant_settings_id' => 'cms-final',
						),
						$fields
					)
				)
			)
		);

		return ( new Handler( $client ) )->handle_finalize_install(
			$this->create_update_request( array( 'access_token' => 'fresh-suat' ) )
		);
	}

	/**
	 * Finds the checkout setup event in the Meta logging queue.
	 *
	 * @return array
	 */
	private function find_checkout_setup_log(): array {
		$logs = get_transient( Logger::LOGGING_MESSAGE_QUEUE );
		$this->assertIsArray( $logs );

		foreach ( $logs as $log ) {
			if ( 'commerce_merchant_settings_checkout_setup' === ( $log['event'] ?? '' ) ) {
				return $log;
			}
		}

		$this->fail( 'Checkout setup log was not queued.' );
	}

	/**
	 * Creates a REST request mock with the supplied update data.
	 *
	 * @param array $data Request data.
	 * @return \WP_REST_Request
	 */
	private function create_update_request( array $data ): \WP_REST_Request {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_json_params' )->willReturn( $data );
		$request->method( 'get_params' )->willReturn( $data );

		return $request;
	}
}
