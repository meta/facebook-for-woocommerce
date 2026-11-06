<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

use WooCommerce\Facebook\API;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;

/**
 * Api unit test clas.
 */
class ApiTest extends \WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering {

	/**
	 * Facebook Graph API endpoint.
	 *
	 * @var string
	 */
	private $endpoint = Api::GRAPH_API_URL;

	/**
	 * Facebook Graph API version.
	 *
	 * @var string
	 */
	private $version = Api::API_VERSION;

	/**
	 * @var Api
	 */
	private $api;

	/**
	 * Runs before each test is executed.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->api = new Api( 'test-api-key-9678djyad552' );
	}

	/**
	 * Tests preform request succeeds.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_perform_request_performs_successful_request() {
		$response = function( $result, $parsed_args, $url ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/726635365295186?fields=name", $url );
			return [
				'body'     => '{"name":"WooCommerce Catalog","id":"726635365295186"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_catalog( '726635365295186' );

		$this->assertEquals( '726635365295186', $response->id );
		$this->assertEquals( 'WooCommerce Catalog', $response->name );
	}

	/**
	 * Tests preform request method returns WP_Error response.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_perform_request_produces_wp_error() {
		$this->expectException( ApiException::class );
		$this->expectExceptionCode( 007 );
		$this->expectExceptionMessage( 'WP Error Message' );

		$response = function( $result, $parsed_args, $url ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/726635365295186?fields=name", $url );
			return new WP_Error( 007, 'WP Error Message' );
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$this->api->get_catalog( '726635365295186' );
	}

	/**
	 * Tests get installation ids returns installation ids.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_get_installation_ids_returns_installation_ids_request() {
		$external_business_id = 'wordpress-facebook-62c3f1add134a';

		$response = function( $result, $parsed_args, $url ) use ( $external_business_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/fbe_business/fbe_installs?fbe_external_business_id={$external_business_id}", $url );
			return [
				'body'     => '{"data":[{"business_manager_id":"973766133343161","commerce_merchant_settings_id":"1118975075354165","onsite_eligible":false,"pixel_id":"1964583793745557","profiles":["101332922643063"],"ad_account_id":"0","catalog_id":"726635365295186","pages":["101332922643063"],"token_type":"User","installed_features":[{"feature_instance_id":"762898101511280","feature_type":"pixel","connected_assets":{"page_id":"101332922643063","pixel_id":"1964583793745557"},"additional_info":{"onsite_eligible":false}},{"feature_instance_id":"562415265365817","feature_type":"catalog","connected_assets":{"catalog_id":"726635365295186","page_id":"101332922643063","pixel_id":"1964583793745557"},"additional_info":{"onsite_eligible":false}},{"feature_instance_id":"554457382898066","feature_type":"fb_shop","connected_assets":{"catalog_id":"726635365295186","commerce_merchant_settings_id":"1118975075354165","page_id":"101332922643063"},"additional_info":{"onsite_eligible":false}}]}]}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_installation_ids( $external_business_id );

		$this->assertEquals( '101332922643063', $response->get_page_id() );
	}

	/**
	 * Tests get catalog method returns Facebook Catalog name and id.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_get_catalog_returns_catalog_id_and_name_request() {
		$catalog_id = '726635365295186';

		$response = function( $result, $parsed_args, $url ) use ( $catalog_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$catalog_id}?fields=name", $url );
			return [
				'body'     => '{"name":"WooCommerce Catalog","id":"726635365295186"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_catalog( $catalog_id );

		$this->assertEquals( '726635365295186', $response->id );
		$this->assertEquals( 'WooCommerce Catalog', $response->name );
	}

	/**
	 * Tests get user returns information about user.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_get_user_returns_user_information_request() {
		$user_id = '';

		$response = function( $result, $parsed_args, $url ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/me", $url );
			return [
				'body'     => '{"name":"WooCommerce Integration System User","id":"111189594891749"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_user( $user_id );

		$this->assertEquals( '111189594891749', $response->id );
		$this->assertEquals( 'WooCommerce Integration System User', $response->name );
	}

	/**
	 * Tests delete mbe request performs a request to delete facebook connection.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_delete_mbe_connection_deletes_mbe_request() {
		$external_business_id = 'wordpress-facebook-62c3f1add134a';

		$response = function( $result, $parsed_args, $url ) use ( $external_business_id ) {
			$this->assertEquals( 'DELETE', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/fbe_business/fbe_installs", $url );
			$this->assertEquals( '{"fbe_external_business_id":"wordpress-facebook-62c3f1add134a"}', $parsed_args['body'] );
			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->delete_mbe_connection( $external_business_id );

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests get business configuration performs a request for business configuration.
	 *
	 * @return void
	 * @throws ApiException In case of failed request.
	 */
	public function test_get_business_configuration_returns_business_configuration_request() {
		$external_business_id = 'wordpress-facebook-62c3f1add134a';

		$response = function( $result, $parsed_args, $url ) use ( $external_business_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/fbe_business?fbe_external_business_id={$external_business_id}", $url );
			return [
				'body'     => '{"business":{"name":"WordPress-Facebook"},"catalogs":[{"feature_instance_id":"562415265365817","enabled":true}],"catalog_feed_scheduled":{"enabled":false},"fb_shops":[{"feature_instance_id":"554457382898066","enabled":true}],"ig_cta":{"enabled":false},"ig_shopping":{"enabled":false},"page_card":{"enabled":false},"page_cta":{"enabled":false},"page_post":{"enabled":false},"page_shop":{"enabled":false},"pixels":[{"feature_instance_id":"762898101511280","enabled":true}],"thread_intent":{"enabled":false}}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_business_configuration( $external_business_id );

		$this->assertFalse( $response->is_ig_shopping_enabled() );
		$this->assertFalse( $response->is_ig_cta_enabled() );
	}

	/**
	 * Tests send item updates sends updates to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_send_item_updates_sends_item_updates_request() {
		$facebook_catalog_id = '726635365295186';
		$requests            = [
			[
				'method' => 'UPDATE',
				'data'   => [
					'title'                     => 'Belt',
					'description'               => '20 pcs. available',
					'image_link'                => 'https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/belt-2.jpg',
					'additional_image_link'     => [
						'https://wordpress-facebook.ddev.site/wp-content/uploads/2022/04/belt-2.jpg',
					],
					'link'                      => 'https://wordpress-facebook.ddev.site/product/belt/',
					'brand'                     => 'WordPress-Facebook',
					'price'                     => '71 USD',
					'availability'              => 'in stock',
					'visibility'                => 'published',
					'sale_price_effective_date' => '',
					'sale_price'                => '',
					'google_product_category'   => '169',
					'item_group_id'             => 'woo-belt_96',
					'condition'                 => 'new',
					'id'                        => 'woo-belt_96',
				],
			],
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_catalog_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_catalog_id}/items_batch", $url );
			$this->assertEquals( '{"allow_upsert":true,"requests":[{"method":"UPDATE","data":{"title":"Belt","description":"20 pcs. available","image_link":"https:\/\/woocommercecore.mystagingwebsite.com\/wp-content\/uploads\/2017\/12\/belt-2.jpg","additional_image_link":["https:\/\/wordpress-facebook.ddev.site\/wp-content\/uploads\/2022\/04\/belt-2.jpg"],"link":"https:\/\/wordpress-facebook.ddev.site\/product\/belt\/","brand":"WordPress-Facebook","price":"71 USD","availability":"in stock","visibility":"published","sale_price_effective_date":"","sale_price":"","google_product_category":"169","item_group_id":"woo-belt_96","condition":"new","id":"woo-belt_96"}}],"item_type":"PRODUCT_ITEM"}', $parsed_args['body'] );
			return [
				'body'     => '{"handles":["AcxXZQArDYIk_pcuR24pgt7wn1yhMUtaOUtktoX2vynDEGSWohLVDm6yeHhPJMa_9BYK0aOjcpciowYNI8WECT3o"]}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->send_item_updates( $facebook_catalog_id, $requests );

		$this->assertEquals(
			[ 'AcxXZQArDYIk_pcuR24pgt7wn1yhMUtaOUtktoX2vynDEGSWohLVDm6yeHhPJMa_9BYK0aOjcpciowYNI8WECT3o' ],
			$response->handles
		);
	}

	/**
	 * Tests create product group prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_product_group_performs_create_product_group_request() {
		$facebook_product_catalog_id = '726635365295186';
		$data                        = [
			'retailer_id' => 'woo-vneck-tee_91',
			'variants'    => [
				[
					'product_field' => 'color',
					'label'         => 'Color',
					'options'       => [ 'Red', 'Green', 'Blue' ],
				],
				[
					'product_field' => 'size',
					'label'         => 'Size',
					'options'       => [ 'Small', 'Medium', 'Large' ],
				],
			],
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_catalog_id}/product_groups", $url );
			$this->assertEquals( '{"retailer_id":"woo-vneck-tee_91","variants":[{"product_field":"color","label":"Color","options":["Red","Green","Blue"]},{"product_field":"size","label":"Size","options":["Small","Medium","Large"]}]}', $parsed_args['body'] );
			return [
				'body'     => '{"id":"5427299404026432"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->create_product_group( $facebook_product_catalog_id, $data );

		$this->assertEquals( '5427299404026432', $response->id );
	}

	/**
	 * Tests update product group prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_update_product_group_preforms_update_product_group_request() {
		$facebook_product_group_id = '5427299404026432';
		$data                      = [
			'variants' => [
				[
					'product_field' => 'color',
					'label'         => 'Color',
					'options'       => [ 'Red', 'Green', 'Blue' ],
				],
				[
					'product_field' => 'size',
					'label'         => 'Size',
					'options'       => [ 'Small', 'Medium', 'Large' ],
				],
			],
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_group_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_group_id}", $url );
			$this->assertEquals( '{"variants":[{"product_field":"color","label":"Color","options":["Red","Green","Blue"]},{"product_field":"size","label":"Size","options":["Small","Medium","Large"]}]}', $parsed_args['body'] );
			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->update_product_group( $facebook_product_group_id, $data );

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests get product group products prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_get_product_group_products_returns_group_products_request() {
		$facebook_product_group_id = '5427299404026432';
		$limit                     = 999;

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_group_id, $limit ) {
			$expected_query = http_build_query(
				[
					'fields' => 'id,retailer_id',
					'limit'  => $limit,
				],
				'',
				'&'
			);

			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_group_id}/products?{$expected_query}", $url );
			return [
				'body'     => '{"data":[{"id":"5678482592202667","retailer_id":"woo-vneck-tee-blue_107"},{"id":"5575513012507086","retailer_id":"woo-vneck-tee-green_106"},{"id":"5454083851373820","retailer_id":"woo-vneck-tee-red_105"}],"paging":{"cursors":{"before":"QVFIUmt2N3lKdWUycEM1c1ZA3eXF5YnJoeWxJZAFFMZAW1OVDZAieG4ycU5mMmV6NV9NY2syaWVxRnZAtRnpwOTVETVZANR21VTzUtZAXBLb25TcmNaaVpYTEYyMVJ3","after":"QVFIUmxJVmYwQUdmay1YUnRPdXROMjN5a3EyZAGhIVm1NX2VpVjBiMFRBMncxSjZAYWXowOVhYTjQ0VzY4X2tlQ2VIZAFNyT3ZARbk5LeWRPM242d2JFazZA4QVZA3"},"next":"https:\/\/graph.facebook.com\/' . $this->version . '\/5427299404026432\/products?fields=id\u00252Cretailer_id&limit=1000&after=QVFIUmxJVmYwQUdmay1YUnRPdXROMjN5a3EyZAGhIVm1NX2VpVjBiMFRBMncxSjZAYWXowOVhYTjQ0VzY4X2tlQ2VIZAFNyT3ZARbk5LeWRPM242d2JFazZA4QVZA3","previous":"https:\/\/graph.facebook.com\/' . $this->version . '\/5427299404026432\/products?fields=id\u00252Cretailer_id&limit=1000&before=QVFIUmt2N3lKdWUycEM1c1ZA3eXF5YnJoeWxJZAFFMZAW1OVDZAieG4ycU5mMmV6NV9NY2syaWVxRnZAtRnpwOTVETVZANR21VTzUtZAXBLb25TcmNaaVpYTEYyMVJ3"}}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_product_group_products( $facebook_product_group_id, $limit );

		$this->assertEquals(
			[
				[
					'id'          => '5678482592202667',
					'retailer_id' => 'woo-vneck-tee-blue_107',
				],
				[
					'id'          => '5575513012507086',
					'retailer_id' => 'woo-vneck-tee-green_106',
				],
				[
					'id'          => '5454083851373820',
					'retailer_id' => 'woo-vneck-tee-red_105',
				],
			],
			$response->data
		);
	}

	/**
	 * Tests create product prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_product_item_creates_product_item_request() {
		$facebook_product_group_id = '8672727046074523';
		$data                      = [
			'name'                  => 'Cap',
			'description'           => 'Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Vestibulum tortor quam, feugiat vitae, ultricies eget, tempor sit amet, ante. Donec eu libero sit amet quam egestas semper. Aenean ultricies mi vitae est. Mauris placerat eleifend leo',
			'image_url'             => 'https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/cap-2.jpg',
			'additional_image_urls' => [
				'https://wordpress-facebook.ddev.site/wp-content/uploads/2022/04/cap-2.jpg',
			],
			'url'                   => 'https://wordpress-facebook.ddev.site/product/long-sleeve-tee-2/',
			'category'              => 'Accessories',
			'brand'                 => 'WordPress-Facebook',
			'retailer_id'           => 'woo-cap_97',
			'price'                 => 1600,
			'currency'              => 'USD',
			'availability'          => 'in stock',
			'visibility'            => 'published',
			'sale_price_start_date' => '1970-01-29T00:00+00:00',
			'sale_price_end_date'   => '2038-01-17T23:59+00:00',
			'sale_price'            => 0,
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_group_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_group_id}/products", $url );
			$this->assertEquals( '{"name":"Cap","description":"Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Vestibulum tortor quam, feugiat vitae, ultricies eget, tempor sit amet, ante. Donec eu libero sit amet quam egestas semper. Aenean ultricies mi vitae est. Mauris placerat eleifend leo","image_url":"https:\/\/woocommercecore.mystagingwebsite.com\/wp-content\/uploads\/2017\/12\/cap-2.jpg","additional_image_urls":["https:\/\/wordpress-facebook.ddev.site\/wp-content\/uploads\/2022\/04\/cap-2.jpg"],"url":"https:\/\/wordpress-facebook.ddev.site\/product\/long-sleeve-tee-2\/","category":"Accessories","brand":"WordPress-Facebook","retailer_id":"woo-cap_97","price":1600,"currency":"USD","availability":"in stock","visibility":"published","sale_price_start_date":"1970-01-29T00:00+00:00","sale_price_end_date":"2038-01-17T23:59+00:00","sale_price":0}', $parsed_args['body'] );
			return [
				'body'     => '{"id":"8672727132741181"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->create_product_item( $facebook_product_group_id, $data );

		$this->assertEquals( '8672727132741181', $response->id );
	}

	/**
	 * Tests update product prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_update_product_item_updated_product_item_request() {
		$facebook_product_id = '8672727132741181';
		$data                = [
			'name'                  => 'Cap',
			'description'           => 'Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Vestibulum tortor quam, feugiat vitae, ultricies eget, tempor sit amet, ante. Donec eu libero sit amet quam egestas semper. Aenean ultricies mi vitae est. Mauris placerat eleifend leo',
			'image_url'             => 'https://woocommercecore.mystagingwebsite.com/wp-content/uploads/2017/12/cap-2.jpg',
			'additional_image_urls' => [
				'https://wordpress-facebook.ddev.site/wp-content/uploads/2022/04/cap-2.jpg',
			],
			'url'                   => 'https://wordpress-facebook.ddev.site/product/long-sleeve-tee-2/',
			'category'              => 'Accessories',
			'brand'                 => 'WordPress-Facebook',
			'retailer_id'           => 'woo-cap_97',
			'price'                 => 1600,
			'currency'              => 'USD',
			'availability'          => 'in stock',
			'visibility'            => 'published',
			'sale_price_start_date' => '1970-01-29T00:00+00:00',
			'sale_price_end_date'   => '2038-01-17T23:59+00:00',
			'sale_price'            => 0,
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_id}", $url );
			$this->assertEquals( '{"name":"Cap","description":"Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Vestibulum tortor quam, feugiat vitae, ultricies eget, tempor sit amet, ante. Donec eu libero sit amet quam egestas semper. Aenean ultricies mi vitae est. Mauris placerat eleifend leo","image_url":"https:\/\/woocommercecore.mystagingwebsite.com\/wp-content\/uploads\/2017\/12\/cap-2.jpg","additional_image_urls":["https:\/\/wordpress-facebook.ddev.site\/wp-content\/uploads\/2022\/04\/cap-2.jpg"],"url":"https:\/\/wordpress-facebook.ddev.site\/product\/long-sleeve-tee-2\/","category":"Accessories","brand":"WordPress-Facebook","retailer_id":"woo-cap_97","price":1600,"currency":"USD","availability":"in stock","visibility":"published","sale_price_start_date":"1970-01-29T00:00+00:00","sale_price_end_date":"2038-01-17T23:59+00:00","sale_price":0}', $parsed_args['body'] );
			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->update_product_item( $facebook_product_id, $data );

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests get product ids prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_get_product_facebook_ids_creates_get_ids_request() {
		$facebook_product_catalog_id  = '726635365295186';
		$facebook_product_retailer_id = 'woo-cap_97';

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id, $facebook_product_retailer_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );

			$filter = urlencode('{"retailer_id":{"eq":"' . $facebook_product_retailer_id . '"}}');
			$fields = urlencode('id,product_group{id}');
			$path = "/{$facebook_product_catalog_id}/products?filter={$filter}&fields={$fields}";

			$this->assertEquals( "{$this->endpoint}{$this->version}{$path}", $url );
			return [
				'body'     => '{"id":"8672727132741181","product_group":{"id":"8672727046074523"}}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->get_product_facebook_ids( $facebook_product_catalog_id, $facebook_product_retailer_id );

		$this->assertEquals( '8672727132741181', $response->id );
		$this->assertEquals( '8672727046074523', $response->get_facebook_product_group_id() );
	}

	/**
	 * Tests get product ids prepares a request to Facebook.
	 * This test is for the filter endpoint.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_get_product_facebook_ids_creates_get_ids_request_with_filter_endpoint() {
		$facebook_product_catalog_id  = '726635365295186';
		$facebook_product_retailer_id = 'woo-cap_97';

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id, $facebook_product_retailer_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );

			$filter = urlencode('{"retailer_id":{"eq":"' . $facebook_product_retailer_id . '"}}');
			$fields = urlencode('id,product_group{id}');
			$path = "/{$facebook_product_catalog_id}/products?filter={$filter}&fields={$fields}";

			$this->assertEquals( "{$this->endpoint}{$this->version}{$path}", $url );
			return [
				'body'     => '{"data":[{"id":"8672727132741181","product_group":{"id":"8672727046074523"}}]}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		add_filter( 'pre_http_request', $response, 10, 3 );

		$is_call_before_sync = true;
		$product = WC_Helper_Product::create_simple_product();
		$woo_product = new WC_Facebook_Product( $product );
		$response = $this->api->get_product_facebook_ids( $facebook_product_catalog_id, $facebook_product_retailer_id, $woo_product, $is_call_before_sync );

		$this->assertEquals(
			[
				[
					'id'            => '8672727132741181',
					'product_group'	=> [
						'id' => '8672727046074523',
					],
				],
			],
			$response->data
		);
	}

	/**
	 * Tests get product ids prepares a request to Facebook.
	 * This test is for the filter endpoint with a product retailer id that doesn't exist.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_get_product_facebook_ids_creates_get_ids_request_with_filter_endpoint_nonexisting_product() {
		$facebook_product_catalog_id  = '726635365295186';
		$facebook_product_retailer_id = 'nonexisting_product_retailer_id';

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id, $facebook_product_retailer_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );

			$filter = urlencode('{"retailer_id":{"eq":"' . $facebook_product_retailer_id . '"}}');
			$fields = urlencode('id,product_group{id}');
			$path = "/{$facebook_product_catalog_id}/products?filter={$filter}&fields={$fields}";

			$this->assertEquals( "{$this->endpoint}{$this->version}{$path}", $url );
			return [
				'body'     => '{"data":[]}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		add_filter( 'pre_http_request', $response, 10, 3 );

		$is_call_before_sync = true;
		$product = WC_Helper_Product::create_simple_product();
		$woo_product = new \WC_Facebook_Product( $product );
		$response = $this->api->get_product_facebook_ids( $facebook_product_catalog_id, $facebook_product_retailer_id, $woo_product, $is_call_before_sync );

		$this->assertEquals(
			[],
			$response->data
		);
	}

	/**
	 * Tests create product set prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_product_set_item_creates_set_request() {
		$facebook_product_catalog_id = '726635365295186';
		$data                        = [
			'name'     => 'Fb excluded set',
			'filter'   => '{"or":[{"retailer_id":{"eq":"bundle-one_134"}},{"retailer_id":{"eq":"woo-sunglasses_98"}},{"retailer_id":{"eq":"Woo-beanie-logo_112"}},{"retailer_id":{"eq":"woo-album_103"}},{"retailer_id":{"eq":"woo-single_104"}},{"retailer_id":{"eq":"woo-belt_96"}},{"retailer_id":{"eq":"woo-cap_97"}},{"retailer_id":{"eq":"woo-beanie_95"}}]}',
			'metadata' => '{"description":""}',
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_catalog_id}/product_sets", $url );
			$this->assertEquals( '{"name":"Fb excluded set","filter":"{\"or\":[{\"retailer_id\":{\"eq\":\"bundle-one_134\"}},{\"retailer_id\":{\"eq\":\"woo-sunglasses_98\"}},{\"retailer_id\":{\"eq\":\"Woo-beanie-logo_112\"}},{\"retailer_id\":{\"eq\":\"woo-album_103\"}},{\"retailer_id\":{\"eq\":\"woo-single_104\"}},{\"retailer_id\":{\"eq\":\"woo-belt_96\"}},{\"retailer_id\":{\"eq\":\"woo-cap_97\"}},{\"retailer_id\":{\"eq\":\"woo-beanie_95\"}}]}","metadata":"{\"description\":\"\"}"}', $parsed_args['body'] );
			return [
				'body'     => '{"id":"848141989502356"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->create_product_set_item( $facebook_product_catalog_id, $data );

		$this->assertEquals( '848141989502356', $response->id );
	}

	/**
	 * Tests update product set prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_update_product_set_item_updates_set_request() {
		$facebook_product_set_id = '609903163910641';
		$data                    = [
			'name'     => 'Fb excluded set',
			'filter'   => '{"or":[{"retailer_id":{"eq":"bundle-one_134"}},{"retailer_id":{"eq":"woo-sunglasses_98"}},{"retailer_id":{"eq":"Woo-beanie-logo_112"}},{"retailer_id":{"eq":"woo-album_103"}},{"retailer_id":{"eq":"woo-single_104"}},{"retailer_id":{"eq":"woo-belt_96"}},{"retailer_id":{"eq":"woo-cap_97"}},{"retailer_id":{"eq":"woo-beanie_95"}}]}',
			'metadata' => '{"description":""}',
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_set_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_set_id}", $url );
			$this->assertEquals( '{"name":"Fb excluded set","filter":"{\"or\":[{\"retailer_id\":{\"eq\":\"bundle-one_134\"}},{\"retailer_id\":{\"eq\":\"woo-sunglasses_98\"}},{\"retailer_id\":{\"eq\":\"Woo-beanie-logo_112\"}},{\"retailer_id\":{\"eq\":\"woo-album_103\"}},{\"retailer_id\":{\"eq\":\"woo-single_104\"}},{\"retailer_id\":{\"eq\":\"woo-belt_96\"}},{\"retailer_id\":{\"eq\":\"woo-cap_97\"}},{\"retailer_id\":{\"eq\":\"woo-beanie_95\"}}]}","metadata":"{\"description\":\"\"}"}', $parsed_args['body'] );
			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->update_product_set_item( $facebook_product_set_id, $data );

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests delete product set prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_delete_product_set_item_deletes_set_request() {
		$facebook_product_set_id = '609903163910641';

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_set_id ) {
			$this->assertEquals( 'DELETE', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_set_id}?allow_live_product_set_deletion=true", $url );
			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->delete_product_set_item( $facebook_product_set_id, true );

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests read feeds prepares a request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_read_feeds_creates_read_feeds_request() {
		$facebook_product_catalog_id = '726635365295186';

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_catalog_id}/product_feeds", $url );
			return [
				'body'     => '{"data":[{"id":"1068839467367301","file_name":"WooCommerce Catalog - Feed","name":"WooCommerce Catalog - Feed"}],"paging":{"cursors":{"before":"QVFIUmJybjEwNU81U29oZAXdmcXl2MEhBdWthLVhSUlhUcV9PLWtSR1RQVkJqTnlWVTRtQzRvTExRdjZAheDlsZA0JKYUkxaHJLOVZAqYmU2eVZAYQXJRNG5pRXp3","after":"QVFIUmJybjEwNU81U29oZAXdmcXl2MEhBdWthLVhSUlhUcV9PLWtSR1RQVkJqTnlWVTRtQzRvTExRdjZAheDlsZA0JKYUkxaHJLOVZAqYmU2eVZAYQXJRNG5pRXp3"}}}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->read_feeds( $facebook_product_catalog_id );

		$this->assertEquals(
			[
				[
					'id'        => '1068839467367301',
					'file_name' => 'WooCommerce Catalog - Feed',
					'name'      => 'WooCommerce Catalog - Feed',
				],
			],
			$response->data
		);
	}

	/**
	 * Tests create feed request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_feed_request() {
		$facebook_product_catalog_id = '726635365295186';

		$data = [
			'name' => "Test feed name.",
		];

		$response = function( $result, $parsed_args, $url ) use ( $facebook_product_catalog_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$facebook_product_catalog_id}/product_feeds", $url );
			return [
				'body'     => '{"id":"1068839467367301"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response_feed = $this->api->create_feed( $facebook_product_catalog_id, $data );

		$this->assertEquals( '1068839467367301', $response_feed['id'] );
	}

	/**
	 * Tests create feed upload request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_upload_request() {
		$product_feed_id = '1068839467367301';

		$data = [
			'url' => 'http://example.com/?wc-api=wc_facebook_get_feed_data&secret=c4b8c3c46145aac6519e3f8a28bc86f2',
		];

		$response = function( $result, $parsed_args, $url ) use ( $product_feed_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$product_feed_id}/uploads", $url );
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->create_product_feed_upload( $product_feed_id, $data );
		$this->assertFalse( $response->has_api_error() );
	}

	/**
	 * Tests create common upload feed request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_common_upload_request() {
		$cpi = '24316596247984028';

		$data = array(
			'url'         => 'http://example.com/?wc-api=wc_facebook_get_feed_data_example&secret=c4b8c3c46145aac6519e3f8a28bc86f2',
			'feed_type'   => 'PRODUCT_RATINGS_AND_REVIEWS',
			'update_type' => 'CREATE',
		);

		$response = function( $result, $parsed_args, $url ) use ( $cpi ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$cpi}/file_update", $url );
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->create_common_data_feed_upload( $cpi, $data );
		$this->assertFalse( $response->has_api_error() );
	}

	/**
	 * Tests repair commerce integration request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_repair_commerce_integration_request() {
		$fbe_external_business_id = 'test-business-123';
		$shop_domain = 'example.com';
		$admin_url = 'https://example.com/wp-admin';
		$extension_version = '3.0.0';

		$response = function( $result, $parsed_args, $url ) use ( $fbe_external_business_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/commerce_partner_integrations_repair", $url );

			// Parse the actual request body to compare properties regardless of order
			$actual_body = json_decode($parsed_args['body'], true);
			$expected_body = [
				'fbe_external_business_id' => $fbe_external_business_id,
				'shop_domain' => 'example.com',
				'admin_url' => 'https://example.com/wp-admin',
				'extension_version' => '3.0.0',
				'commerce_partner_seller_platform_type' => 'SELF_SERVE_PLATFORM'
			];

			$this->assertEquals($expected_body, $actual_body);

			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->repair_commerce_integration(
			$fbe_external_business_id,
			$shop_domain,
			$admin_url,
			$extension_version
		);

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests update commerce integration request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_update_commerce_integration_request() {
		$commerce_integration_id = 'test-integration-123';
		$extension_version = '3.0.0';
		$admin_url = 'https://example.com/wp-admin';
		$country_code = 'US';
		$currency = 'USD';
		$platform_store_id = 'store-123';
		$commerce_partner_seller_platform_type = 'SELF_SERVE';
		$installation_status = 'ACCESS_TOKEN_DEPOSITED';

		$response = function( $result, $parsed_args, $url ) use ( $commerce_integration_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$commerce_integration_id}", $url );

			// Parse the actual request body to compare properties regardless of order
			$actual_body = json_decode($parsed_args['body'], true);
			$expected_body = [
				'commerce_partner_seller_platform_type' => 'SELF_SERVE',
				'installation_status' => 'ACCESS_TOKEN_DEPOSITED',
				'extension_version' => '3.0.0',
				'admin_url' => 'https://example.com/wp-admin',
				'country_code' => 'US',
				'currency' => 'USD',
				'platform_store_id' => 'store-123'
			];

			$this->assertEquals($expected_body, $actual_body);

			return [
				'body'     => '{"success":true}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->update_commerce_integration(
			$commerce_integration_id,
			$extension_version,
			$admin_url,
			$country_code,
			$currency,
			$platform_store_id,
			$commerce_partner_seller_platform_type,
			$installation_status
		);

		$this->assertTrue( $response->success );
	}

	/**
	 * Tests that send_pixel_events sends a blocking request by default.
	 *
	 * @return void
	 * @throws ApiException In case of a general API error or rate limit error.
	 */
	public function test_send_pixel_events_is_blocking_by_default() {
		$pixel_id = '1964583793745557';

		$response = function ( $result, $parsed_args, $url ) use ( $pixel_id ) {
			$this->assertStringContainsString( "/{$pixel_id}/events", $url );
			$this->assertTrue( $parsed_args['blocking'] );
			return [
				'body'     => '{"events_received":1}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$event = $this->createMock( \WooCommerce\Facebook\Events\Event::class );
		$event->method( 'get_data' )->willReturn(
			[
				'event_name' => 'ViewContent',
				'event_time' => time(),
			]
		);

		$result = $this->api->send_pixel_events( $pixel_id, [ $event ] );

		$this->assertNotNull( $result );
	}

	/**
	 * Tests that send_pixel_events sends a non-blocking request when filter is set.
	 *
	 * @return void
	 */
	public function test_send_pixel_events_is_non_blocking_when_filter_enabled() {
		$pixel_id = '1964583793745557';

		$this->add_filter_with_safe_teardown(
			'wc_facebook_pixel_events_non_blocking',
			function () {
				return true;
			}
		);

		$request_fired = false;
		$response      = function ( $result, $parsed_args, $url ) use ( $pixel_id, &$request_fired ) {
			$request_fired = true;
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertStringContainsString( "/{$pixel_id}/events", $url );
			$this->assertFalse( $parsed_args['blocking'] );
			$this->assertStringContainsString( 'Bearer test-api-key-9678djyad552', $parsed_args['headers']['Authorization'] );
			$this->assertEquals( 'application/json', $parsed_args['headers']['Content-Type'] );
			return [
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$event = $this->createMock( \WooCommerce\Facebook\Events\Event::class );
		$event->method( 'get_data' )->willReturn(
			[
				'event_name' => 'ViewContent',
				'event_time' => time(),
			]
		);

		$result = $this->api->send_pixel_events( $pixel_id, [ $event ] );

		$this->assertTrue( $request_fired, 'The non-blocking HTTP request was not triggered.' );
		$this->assertNull( $result );
	}

	/**
	 * Tests read product set request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_read_product_set_item() {
		$product_catalog_id = '726635365295186';
		$retailer_id = '29';

		$response = function( $result, $parsed_args, $url ) use ( $product_catalog_id, $retailer_id ) {
			$this->assertEquals( 'GET', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$product_catalog_id}/product_sets?retailer_id={$retailer_id}", $url );
			return [
				'body'     => '{"id":"325235346346546"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->read_product_set_item( $product_catalog_id, $retailer_id );
		$this->assertFalse( $response->has_api_error() );
	}

	/**
	 * Tests create language override feed upload request to Facebook.
	 *
	 * @return void
	 * @throws ApiException In case of network request error.
	 */
	public function test_create_language_override_upload_request() {
		$product_feed_id = '1068839467367301';
		$language_code = 'es_XX';

		$data = [
			'url' => 'http://example.com/?wc-api=wc_facebook_get_language_feed_data&language=' . $language_code . '&secret=c4b8c3c46145aac6519e3f8a28bc86f2',
		];

		$response = function( $result, $parsed_args, $url ) use ( $product_feed_id ) {
			$this->assertEquals( 'POST', $parsed_args['method'] );
			$this->assertEquals( "{$this->endpoint}{$this->version}/{$product_feed_id}/uploads", $url );

			// Verify the request body contains the language override URL
			$body_data = json_decode( $parsed_args['body'], true );
			$this->assertStringContainsString( 'wc_facebook_get_language_feed_data', $body_data['url'] );
			$this->assertStringContainsString( 'language=es_XX', $body_data['url'] );

			return [
				'body'     => '{"id":"lang_upload_12345","validation_status":["success"]}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$response = $this->api->create_product_feed_upload( $product_feed_id, $data );
		$this->assertFalse( $response->has_api_error() );
	}

	/**
	 * The API object is a singleton reused within a request, so `get_api( $token )` can swap
	 * the token between calls. The Authorization actually sent must follow the current token,
	 * not the one that constructed the instance.
	 */
	public function test_authorization_header_follows_the_current_access_token() {
		$sent_authorizations = array();

		$response = function ( $result, $parsed_args, $url ) use ( &$sent_authorizations ) {
			$sent_authorizations[] = $parsed_args['headers']['Authorization'];
			return [
				'body'     => '{"name":"WooCommerce Catalog","id":"726635365295186"}',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
			];
		};
		$this->add_filter_with_safe_teardown( 'pre_http_request', $response, 10, 3 );

		$this->api->get_catalog( '726635365295186' );

		$this->api->set_access_token( 'rotated-token-9911' );
		$this->api->get_catalog( '726635365295186' );

		$this->assertSame(
			array( 'Bearer test-api-key-9678djyad552', 'Bearer rotated-token-9911' ),
			$sent_authorizations,
			'The Authorization sent must track the current access token across a token swap.'
		);
		$this->assertSame( 'rotated-token-9911', $this->api->get_access_token() );
	}
}
