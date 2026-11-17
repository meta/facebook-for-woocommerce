<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Handlers;

use WooCommerce\Facebook\API;
use WooCommerce\Facebook\API\CommerceIntegration\Configuration\Update\Response as UpdateResponse;
use WooCommerce\Facebook\API\FBE\Installation\Read\Response as InstallationResponse;
use WooCommerce\Facebook\Handlers\Connection;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for the CPI-preferred asset-mapping ladder.
 */
class ConnectionAssetMappingTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Verifies that resolving a missing CPI ID via lookup also applies its asset mapping.
	 */
	public function test_lookup_hit_supplies_id_and_mapping(): void {
		$external_business_id = wp_generate_uuid4();

		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, $external_business_id );
		delete_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function ( $response, $args, $url ) {
				$this->assertStringContainsString( 'external_business_id=', $url );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'                              => 'cpi-from-lookup',
							'pixel_id'                        => 'pixel-from-lookup',
							'catalog_id'                      => 'catalog-from-lookup',
							'commerce_merchant_settings_id'   => 'cms-from-lookup',
						)
					),
				);
			},
			10,
			3
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->never() )->method( 'repair_commerce_integration' );
		$api->expects( $this->never() )->method( 'get_installation_ids' );
		$api->expects( $this->once() )
			->method( 'update_commerce_integration' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$connection = new Connection( $this->mock_plugin( $api ) );
		$connection->refresh_installation_data();

		$this->assertSame( 'cpi-from-lookup', get_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID ) );
		$this->assertSame( 'pixel-from-lookup', get_option( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID ) );
		$this->assertSame( 'catalog-from-lookup', get_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
	}

	/**
	 * Verifies that a known CPI ID refreshes its mapping from the integration read.
	 */
	public function test_read_hit_refreshes_mapping_for_known_cpi(): void {
		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, wp_generate_uuid4() );
		update_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'stored-cpi' );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'id'         => 'stored-cpi',
							'pixel_id'   => 'pixel-from-read',
							'catalog_id' => 'catalog-from-read',
						)
					),
				);
			}
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->never() )->method( 'repair_commerce_integration' );
		$api->expects( $this->never() )->method( 'get_installation_ids' );
		$api->expects( $this->once() )
			->method( 'update_commerce_integration' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$connection = new Connection( $this->mock_plugin( $api ) );
		$connection->refresh_installation_data();

		$this->assertSame( 'pixel-from-read', get_option( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID ) );
		$this->assertSame( 'catalog-from-read', get_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
	}

	/**
	 * Verifies that the legacy FBE install read is the last resort.
	 */
	public function test_fbe_install_read_is_last_resort(): void {
		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, wp_generate_uuid4() );
		update_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'stored-cpi' );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Commerce Integration API unavailable in this test.' );
			}
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->once() )
			->method( 'get_installation_ids' )
			->willReturn(
				new InstallationResponse(
					wp_json_encode(
						array(
							'data' => array(
								array(
									'catalog_id'                    => 'catalog-from-fbe',
									'commerce_merchant_settings_id' => 'cms-from-fbe',
								),
							),
						)
					)
				)
			);
		$api->expects( $this->once() )
			->method( 'update_commerce_integration' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$connection = new Connection( $this->mock_plugin( $api ) );
		$connection->refresh_installation_data();

		$this->assertSame( 'catalog-from-fbe', get_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
		$this->assertSame( 'cms-from-fbe', get_option( Connection::OPTION_COMMERCE_MERCHANT_SETTINGS_ID ) );
	}

	/**
	 * A Graph error or an empty result from the legacy FBE install read is a failure, not a refresh.
	 *
	 * @dataProvider provide_failed_fbe_install_reads
	 *
	 * @param array $body Decoded FBE install read response body.
	 */
	public function test_fbe_install_read_error_is_not_reported_as_refresh( array $body ): void {
		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, wp_generate_uuid4() );
		update_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'stored-cpi' );
		update_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'catalog-kept' );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Commerce Integration API unavailable in this test.' );
			}
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->once() )
			->method( 'get_installation_ids' )
			->willReturn( new InstallationResponse( wp_json_encode( $body ) ) );
		$api->method( 'update_commerce_integration' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$messages = array();
		$plugin   = $this->mock_plugin( $api );
		$plugin->method( 'log' )->willReturnCallback(
			function ( $message ) use ( &$messages ) {
				$messages[] = $message;
			}
		);

		$connection = new Connection( $plugin );
		$connection->refresh_installation_data();

		$this->assertNotContains( 'Refreshed asset mapping from the legacy FBE install read.', $messages );
		$this->assertNotEmpty(
			array_filter(
				$messages,
				function ( $message ) {
					return 0 === strpos( $message, 'Could not refresh installation data.' );
				}
			)
		);
		$this->assertSame( 'catalog-kept', get_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
	}

	/**
	 * FBE install read responses that did not refresh anything.
	 *
	 * @return array
	 */
	public function provide_failed_fbe_install_reads(): array {
		return array(
			'graph error'  => array(
				array(
					'error' => array(
						'message' => 'Unsupported get request.',
						'type'    => 'GraphMethodException',
						'code'    => 100,
					),
				),
			),
			'no install'   => array( array( 'data' => array() ) ),
			'empty install' => array( array( 'data' => array( array() ) ) ),
		);
	}

	/**
	 * A numeric catalog ID from the legacy FBE install read must not throw a TypeError.
	 */
	public function test_fbe_install_read_accepts_numeric_catalog_id(): void {
		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, wp_generate_uuid4() );
		update_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, 'stored-cpi' );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Commerce Integration API unavailable in this test.' );
			}
		);

		$api = $this->createMock( API::class );
		$api->method( 'get_installation_ids' )
			->willReturn( new InstallationResponse( wp_json_encode( array( 'data' => array( array( 'catalog_id' => 1234567890123 ) ) ) ) ) );
		$api->method( 'update_commerce_integration' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$connection = new Connection( $this->mock_plugin( $api ) );
		$connection->refresh_installation_data();

		$this->assertSame( '1234567890123', get_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
	}

	/**
	 * Builds a plugin mock serving the given API client.
	 *
	 * @param API $api Mocked API client.
	 * @return \WC_Facebookcommerce
	 */
	private function mock_plugin( API $api ): \WC_Facebookcommerce {
		$plugin = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_api', 'get_version', 'log' ) )
			->getMock();
		$plugin->method( 'get_api' )->willReturn( $api );
		$plugin->method( 'get_version' )->willReturn( 'test-version' );

		return $plugin;
	}
}
