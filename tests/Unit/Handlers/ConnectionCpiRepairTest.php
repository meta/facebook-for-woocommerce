<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Handlers;

use WooCommerce\Facebook\API;
use WooCommerce\Facebook\API\CommerceIntegration\Configuration\Update\Response as UpdateResponse;
use WooCommerce\Facebook\API\CommerceIntegration\Repair\Response as RepairResponse;
use WooCommerce\Facebook\API\FBE\Installation\Read\Response as InstallationResponse;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Handlers\Connection;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;
use WooCommerce\Facebook\Utilities\Heartbeat;

/**
 * Unit tests for repairing missing Commerce Partner Integration data.
 */
class ConnectionCpiRepairTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Verifies that the daily refresh repairs a connected merchant with no CPI before updating its configuration.
	 *
	 * No persistent entities are required for this test, so a data preparer is unnecessary.
	 */
	public function test_missing_cpi_is_repaired_and_configuration_is_updated(): void {
		$external_business_id            = wp_generate_uuid4();
		$commerce_partner_integration_id = wp_generate_uuid4();

		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, $external_business_id );
		delete_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		// 404 the Commerce Integration API lookup: only "no integration exists" may fall through to repair,
		// so this is the one reason that legitimately reaches the repair rung.
		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'title'  => 'Commerce Partner Integration not found',
							'status' => 404,
						)
					),
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'cookies'  => array(),
				);
			}
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->once() )
			->method( 'get_installation_ids' )
			->with( $external_business_id )
			->willReturn( new InstallationResponse( wp_json_encode( array( 'data' => array( array() ) ) ) ) );
		$api->expects( $this->once() )
			->method( 'repair_commerce_integration' )
			->with(
				$external_business_id,
				site_url( '/' ),
				admin_url(),
				'test-version'
			)
			->willReturn(
				new RepairResponse(
					wp_json_encode(
						array(
							'success' => true,
							'id'      => $commerce_partner_integration_id,
						)
					)
				)
			);
		$api->expects( $this->once() )
			->method( 'update_commerce_integration' )
			->with(
				$commerce_partner_integration_id,
				'test-version',
				admin_url(),
				$this->anything(),
				get_woocommerce_currency(),
				$this->anything()
			)
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$plugin = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_api', 'get_version', 'log' ) )
			->getMock();
		$plugin->method( 'get_api' )->willReturn( $api );
		$plugin->method( 'get_version' )->willReturn( 'test-version' );

		$connection = new Connection( $plugin );

		$this->assertSame( 10, has_action( Heartbeat::DAILY, array( $connection, 'refresh_installation_data' ) ) );

		$connection->refresh_installation_data();

		$this->assertSame(
			$commerce_partner_integration_id,
			get_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID )
		);
	}

	/**
	 * Verifies that a failing legacy FBE installation read does not skip the CPI repair.
	 */
	public function test_cpi_repair_runs_when_fbe_install_read_fails(): void {
		$external_business_id            = wp_generate_uuid4();
		$commerce_partner_integration_id = wp_generate_uuid4();

		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, $external_business_id );
		delete_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		// 404 the Commerce Integration API lookup: only "no integration exists" may fall through to repair,
		// so this is the one reason that legitimately reaches the repair rung.
		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'title'  => 'Commerce Partner Integration not found',
							'status' => 404,
						)
					),
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'cookies'  => array(),
				);
			}
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->once() )
			->method( 'get_installation_ids' )
			->with( $external_business_id )
			->willThrowException( new ApiException( 'FBE endpoint retired' ) );
		$api->expects( $this->once() )
			->method( 'repair_commerce_integration' )
			->willReturn(
				new RepairResponse(
					wp_json_encode(
						array(
							'success' => true,
							'id'      => $commerce_partner_integration_id,
						)
					)
				)
			);
		$api->expects( $this->once() )
			->method( 'update_commerce_integration' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$plugin = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_api', 'get_version', 'log' ) )
			->getMock();
		$plugin->method( 'get_api' )->willReturn( $api );
		$plugin->method( 'get_version' )->willReturn( 'test-version' );

		$connection = new Connection( $plugin );
		$connection->refresh_installation_data();

		$this->assertSame(
			$commerce_partner_integration_id,
			get_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID )
		);
	}

	/**
	 * Repair can mint an integration, so it must only run when the lookup proves none
	 * exists. A transport failure leaves the remote state unknown; falling through on it
	 * would let a transient blip replace a healthy stored ID.
	 *
	 * @return void
	 */
	public function test_transient_lookup_failure_does_not_reach_repair(): void {
		$external_business_id = wp_generate_uuid4();

		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, $external_business_id );
		delete_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'Simulated transport failure.' );
			}
		);

		$api = $this->createMock( API::class );
		$api->expects( $this->never() )->method( 'repair_commerce_integration' );
		$api->method( 'get_installation_ids' )
			->willThrowException( new ApiException( 'FBE endpoint retired' ) );

		$plugin = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_api', 'get_version', 'log' ) )
			->getMock();
		$plugin->method( 'get_api' )->willReturn( $api );
		$plugin->method( 'get_version' )->willReturn( 'test-version' );

		$connection = new Connection( $plugin );
		$connection->refresh_installation_data();

		$this->assertEmpty(
			get_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID ),
			'A transient lookup failure must not mint a Commerce Partner Integration.'
		);
	}

	/**
	 * A numeric ID in the repair response must neither throw a TypeError nor abort the
	 * configuration push that follows it in the same daily run.
	 *
	 * @return void
	 */
	public function test_repair_accepts_numeric_integration_id(): void {
		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		update_option( Connection::OPTION_EXTERNAL_BUSINESS_ID, wp_generate_uuid4() );
		delete_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );
		delete_transient( '_wc_facebook_for_woocommerce_refresh_installation_data' );

		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'title' => 'Commerce Partner Integration not found' ) ),
					'response' => array(
						'code'    => 404,
						'message' => 'Not Found',
					),
					'cookies'  => array(),
				);
			}
		);

		$api = $this->createMock( API::class );
		$api->method( 'get_installation_ids' )
			->willReturn( new InstallationResponse( wp_json_encode( array( 'data' => array( array() ) ) ) ) );
		$api->expects( $this->once() )
			->method( 'repair_commerce_integration' )
			->willReturn(
				new RepairResponse(
					wp_json_encode(
						array(
							'success' => true,
							'id'      => 1234567890123,
						)
					)
				)
			);
		$api->expects( $this->once() )
			->method( 'update_commerce_integration' )
			->with( '1234567890123' )
			->willReturn( new UpdateResponse( wp_json_encode( array( 'success' => true ) ) ) );

		$plugin = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_api', 'get_version', 'log' ) )
			->getMock();
		$plugin->method( 'get_api' )->willReturn( $api );
		$plugin->method( 'get_version' )->willReturn( 'test-version' );

		$connection = new Connection( $plugin );
		$connection->refresh_installation_data();

		$this->assertSame( '1234567890123', get_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID ) );
	}
}
