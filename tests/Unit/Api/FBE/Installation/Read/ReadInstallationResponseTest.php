<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\API\FBE\Installation\Read;

use WooCommerce\Facebook\API\FBE\Installation\Read\Response;
use WooCommerce\Facebook\API\Response as ApiResponse;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for FBE Installation Read Response class.
 *
 * @since 3.5.2
 */
class ReadInstallationResponseTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Test that the Response class exists and extends ApiResponse.
	 */
	public function test_response_class_hierarchy() {
		$this->assertTrue( class_exists( Response::class ) );

		$response = new Response( '{}' );
		$this->assertInstanceOf( ApiResponse::class, $response );
		$this->assertInstanceOf( Response::class, $response );
	}

	/**
	 * Test instantiation and to_string method.
	 */
	public function test_instantiation_and_to_string() {
		$installation_data = [
			'pixel_id'                        => 'pixel_id_123',
			'business_manager_id'             => 'business_manager_id_123',
			'ad_account_id'                   => 'ad_account_id_123',
			'catalog_id'                      => 'catalog_id_123',
			'pages'                           => [ 'page_id_123' ],
			'instagram_profiles'              => [ 'instagram_business_id_123' ],
			'commerce_merchant_settings_id'   => 'commerce_merchant_settings_id_123',
			'commerce_partner_integration_id' => 'commerce_partner_integration_id_123',
			'profiles'                        => [ 'profile_1', 'profile_2' ],
		];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertInstanceOf( Response::class, $response );
		$this->assertEquals( $data, $response->to_string() );
	}

	/**
	 * Test get_pixel_id method fallback to top-level pixel_id.
	 */
	public function test_get_pixel_id() {
		$installation_data = [ 'pixel_id' => '1800969360764642' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '1800969360764642', $response->get_pixel_id() );
	}

	/**
	 * Test get_pixel_id method prioritizes installed_features pixel over top-level.
	 */
	public function test_get_pixel_id_from_installed_features() {
		$installation_data = [
			'pixel_id' => '1800969360764642', // Stale pixel ID
			'installed_features' => [
				[
					'feature_type' => 'external_client',
					'connected_assets' => [
						'pixel_id' => '1800969360764642'
					]
				],
				[
					'feature_type' => 'pixel',
					'connected_assets' => [
						'pixel_id' => '1626962704583880' // Current pixel ID
					]
				],
				[
					'feature_type' => 'ads',
					'connected_assets' => [
						'pixel_id' => '1626962704583880'
					]
				]
			]
		];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '1626962704583880', $response->get_pixel_id() );
	}

	/**
	 * Test get_pixel_id method falls back when no pixel feature found.
	 */
	public function test_get_pixel_id_fallback_when_no_pixel_feature() {
		$installation_data = [
			'pixel_id' => '1800969360764642',
			'installed_features' => [
				[
					'feature_type' => 'external_client',
					'connected_assets' => [
						'pixel_id' => '1626962704583880'
					]
				],
				[
					'feature_type' => 'ads',
					'connected_assets' => [
						'pixel_id' => '1626962704583880'
					]
				]
			]
		];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '1800969360764642', $response->get_pixel_id() );
	}

	/**
	 * Test get_pixel_id method handles malformed installed_features gracefully.
	 */
	public function test_get_pixel_id_with_malformed_installed_features() {
		$installation_data = [
			'pixel_id' => '1800969360764642',
			'installed_features' => [
				[
					'feature_type' => 'pixel',
					// Missing connected_assets
				],
				[
					'feature_type' => 'pixel',
					'connected_assets' => [
						// Missing pixel_id
						'other_field' => 'value'
					]
				]
			]
		];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '1800969360764642', $response->get_pixel_id() );
	}

	/**
	 * Test get_business_manager_id method.
	 */
	public function test_get_business_manager_id() {
		$installation_data = [ 'business_manager_id' => 'business_manager_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'business_manager_id_123', $response->get_business_manager_id() );
	}

	/**
	 * Test get_ad_account_id method.
	 */
	public function test_get_ad_account_id() {
		$installation_data = [ 'ad_account_id' => 'ad_account_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'ad_account_id_123', $response->get_ad_account_id() );
	}

	/**
	 * Test get_catalog_id method.
	 */
	public function test_get_catalog_id() {
		$installation_data = [ 'catalog_id' => 'catalog_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'catalog_id_123', $response->get_catalog_id() );
	}

	/**
	 * Test get_catalog_id only returns string or integer IDs, as a string.
	 *
	 * @dataProvider catalog_id_type_provider
	 *
	 * @param mixed  $catalog_id Decoded catalog_id value.
	 * @param string $expected   Expected return value.
	 */
	public function test_get_catalog_id_type_safety( $catalog_id, string $expected ) {
		$data     = json_encode( [ 'data' => [ [ 'catalog_id' => $catalog_id ] ] ] );
		$response = new Response( $data );

		$this->assertSame( $expected, $response->get_catalog_id() );
	}

	/**
	 * Data provider for test_get_catalog_id_type_safety.
	 *
	 * @return array
	 */
	public function catalog_id_type_provider(): array {
		return [
			'string' => [ '1234567890123', '1234567890123' ],
			'int'    => [ 1234567890123, '1234567890123' ],
			'array'  => [ [ '1234567890123' ], '' ],
			'null'   => [ null, '' ],
			'float'  => [ 1.5, '' ],
			'bool'   => [ true, '' ],
		];
	}

	/**
	 * Test get_page_id method with array.
	 */
	public function test_get_page_id_with_array() {
		$installation_data = [ 'pages' => [ 'page_id_123' ] ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'page_id_123', $response->get_page_id() );
	}

	/**
	 * Test get_page_id method with string.
	 */
	public function test_get_page_id_with_string() {
		$installation_data = [ 'pages' => 'page_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		// If pages is not an array then the empty string should be returned
		$this->assertEquals( '', $response->get_page_id() );
	}

	/**
	 * Test get_instagram_business_id method with array.
	 */
	public function test_get_instagram_business_id_with_array() {
		$installation_data = [ 'instagram_profiles' => [ 'instagram_business_id_123' ] ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'instagram_business_id_123', $response->get_instagram_business_id() );
	}

	/**
	 * Test get_instagram_business_id method with string.
	 */
	public function test_get_instagram_business_id_with_string() {
		$installation_data = [ 'instagram_profiles' => 'instagram_business_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'instagram_business_id_123', $response->get_instagram_business_id() );
	}

	/**
	 * Test get_instagram_business_id method with empty array.
	 */
	public function test_get_instagram_business_id_with_empty_array() {
		$installation_data = [ 'instagram_profiles' => [] ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '', $response->get_instagram_business_id() );
	}

	/**
	 * Test get_commerce_merchant_settings_id method.
	 */
	public function test_get_commerce_merchant_settings_id() {
		$installation_data = [ 'commerce_merchant_settings_id' => 'commerce_merchant_settings_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'commerce_merchant_settings_id_123', $response->get_commerce_merchant_settings_id() );
	}

	/**
	 * Test get_commerce_partner_integration_id method.
	 */
	public function test_get_commerce_partner_integration_id() {
		$installation_data = [ 'commerce_partner_integration_id' => 'commerce_partner_integration_id_123' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( 'commerce_partner_integration_id_123', $response->get_commerce_partner_integration_id() );
	}

	/**
	 * Test get_profiles method.
	 */
	public function test_get_profiles() {
		$installation_data = [ 'profiles' => [ 'profile_1', 'profile_2' ] ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( [ 'profile_1', 'profile_2' ], $response->get_profiles() );
	}

	/**
	 * Test get_data method.
	 */
	public function test_get_data() {
		$installation_data = [ 'key' => 'value' ];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertIsArray( $response->get_data() );
		$this->assertEquals( [ 'key' => 'value' ], $response->get_data() );
	}

	/**
	 * Test get_data method with empty array.
	 */
	public function test_get_data_empty_array() {
		$installation_data = [];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertIsArray( $response->get_data() );
		$this->assertEmpty( $response->get_data() );
	}

	/**
	 * Test default return values for each getter method when data array is empty.
	 */
	public function test_default_return_values_with_empty_array() {
		$installation_data = [];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '', $response->get_pixel_id() );
		$this->assertEquals( '', $response->get_business_manager_id() );
		$this->assertEquals( '', $response->get_ad_account_id() );
		$this->assertEquals( '', $response->get_catalog_id() );
		$this->assertEquals( '', $response->get_page_id() );
		$this->assertEquals( '', $response->get_instagram_business_id() );
		$this->assertEquals( '', $response->get_commerce_partner_integration_id() );
		$this->assertEquals( '', $response->get_commerce_partner_integration_id() );
		$this->assertIsArray( $response->get_profiles() );
		$this->assertEmpty( $response->get_profiles() );
	}

	/**
	 * Test default return values for each getter method when data array contains null values.
	 */
	public function test_default_return_values_with_null_values() {
		$installation_data = [
			'pixel_id'                        => null,
			'business_manager_id'             => null,
			'ad_account_id'                   => null,
			'catalog_id'                      => null,
			'pages'                           => null,
			'instagram_profiles'              => null,
			'commerce_merchant_settings_id'   => null,
			'commerce_partner_integration_id' => null,
			'profiles'                        => null,
		];
		$data              = json_encode( [ 'data' => [ $installation_data ] ] );
		$response          = new Response( $data );

		$this->assertEquals( '', $response->get_pixel_id() );
		$this->assertEquals( '', $response->get_business_manager_id() );
		$this->assertEquals( '', $response->get_ad_account_id() );
		$this->assertEquals( '', $response->get_catalog_id() );
		$this->assertEquals( '', $response->get_page_id() );
		$this->assertEquals( '', $response->get_instagram_business_id() );
		$this->assertEquals( '', $response->get_commerce_partner_integration_id() );
		$this->assertEquals( '', $response->get_commerce_partner_integration_id() );
		$this->assertIsArray( $response->get_profiles() );
		$this->assertEmpty( $response->get_profiles() );
	}

	/**
	 * Test read response with error.
	 */
	public function test_read_response_with_error() {
		$json_data = json_encode( array(
			'error' => array(
				'type'    => 'GraphMethodException',
				'message' => 'Installation not found',
				'code'    => 100,
			),
		) );

		$response = new Response( $json_data );

		$this->assertTrue( $response->has_api_error() );
		$this->assertEquals( 'GraphMethodException', $response->get_api_error_type() );
		$this->assertEquals( 'Installation not found', $response->get_api_error_message() );
		$this->assertEquals( 100, $response->get_api_error_code() );
	}

	/**
	 * Test inherited get_id method.
	 */
	public function test_get_id_method() {
		$installation_id = 'installation_id_123';
		$data            = json_encode( [ 'id' => $installation_id ] );
		$response        = new Response( $data );

		$this->assertEquals( $installation_id, $response->get_id() );
	}
}
