<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\API\FBE\Configuration;

use WooCommerce\Facebook\API\FBE\Configuration\Request;
use WooCommerce\Facebook\API\Request as ApiRequest;
use WooCommerce\Facebook\Framework\Api\JSONRequest;
use WP_UnitTestCase;

/**
 * Unit tests for FBE Configuration Request class.
 *
 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request
 */
class RequestTest extends WP_UnitTestCase {

	/**
	 * Test that the Request class exists and extends proper parent classes.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_request_class_inheritance() {
		$this->assertTrue( class_exists( Request::class ) );
		
		$request = new Request( 'test-business-123', 'GET' );
		$this->assertInstanceOf( ApiRequest::class, $request );
		$this->assertInstanceOf( JSONRequest::class, $request );
	}

	/**
	 * Test constructor with standard GET parameters.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_get_method() {
		$external_business_id = 'business-123';
		$method               = 'GET';
		$request              = new Request( $external_business_id, $method );
		
		$expected_path = '/fbe_business?fbe_external_business_id=' . $external_business_id;
		$this->assertEquals( $expected_path, $request->get_path() );
		$this->assertEquals( $method, $request->get_method() );
	}

	/**
	 * Test constructor with POST method.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_post_method() {
		$external_business_id = 'business-456';
		$method               = 'POST';
		$request              = new Request( $external_business_id, $method );
		
		$expected_path = '/fbe_business?fbe_external_business_id=' . $external_business_id;
		$this->assertEquals( $expected_path, $request->get_path() );
		$this->assertEquals( 'POST', $request->get_method() );
	}

	/**
	 * Test constructor with PUT method.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_put_method() {
		$external_business_id = 'business-789';
		$method               = 'PUT';
		$request              = new Request( $external_business_id, $method );
		
		$expected_path = '/fbe_business?fbe_external_business_id=' . $external_business_id;
		$this->assertEquals( $expected_path, $request->get_path() );
		$this->assertEquals( 'PUT', $request->get_method() );
	}

	/**
	 * Test constructor with DELETE method.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_delete_method() {
		$external_business_id = 'business-delete';
		$method               = 'DELETE';
		$request              = new Request( $external_business_id, $method );
		
		$expected_path = '/fbe_business?fbe_external_business_id=' . $external_business_id;
		$this->assertEquals( $expected_path, $request->get_path() );
		$this->assertEquals( 'DELETE', $request->get_method() );
	}

	/**
	 * Test constructor with numeric external business ID.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_numeric_business_id() {
		$external_business_id = '123456789';
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=123456789';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test constructor with empty external business ID.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_empty_business_id() {
		$request = new Request( '', 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test constructor with special characters in business ID.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_special_characters_in_business_id() {
		$external_business_id = 'business-id_123-test';
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=business-id_123-test';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test constructor with very long business ID.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_long_business_id() {
		$external_business_id = str_repeat( 'a', 200 );
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=' . $external_business_id;
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test constructor with business ID containing spaces.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_spaces_in_business_id() {
		$external_business_id = 'business id with spaces';
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=business id with spaces';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test constructor with business ID containing URL-encoded characters.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_url_encoded_characters() {
		$external_business_id = 'business%20id%2Ftest';
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=business%20id%2Ftest';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test constructor with business ID containing dots.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_dots_in_business_id() {
		$external_business_id = 'business.id.test';
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=business.id.test';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test that path format is consistent across different business IDs.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_path_format_consistency() {
		$business_ids = array( 'test1', 'test2', 'business-123', 'my_business', '999' );
		
		foreach ( $business_ids as $business_id ) {
			$request = new Request( $business_id, 'GET' );
			$path    = $request->get_path();
			
			// Path should always start with /fbe_business?fbe_external_business_id=
			$this->assertStringStartsWith( '/fbe_business?fbe_external_business_id=', $path );
			
			// Path should end with the business ID
			$this->assertStringEndsWith( $business_id, $path );
			
			// Path should be in correct format
			$expected = '/fbe_business?fbe_external_business_id=' . $business_id;
			$this->assertEquals( $expected, $path );
		}
	}

	/**
	 * Test that path contains query parameter format.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_path_contains_query_parameter() {
		$request = new Request( 'business-123', 'GET' );
		$path    = $request->get_path();
		
		$this->assertStringContainsString( '?', $path );
		$this->assertStringContainsString( 'fbe_external_business_id=', $path );
		$this->assertStringStartsWith( '/fbe_business?', $path );
	}

	/**
	 * Test request inherits parent methods.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_request_inherits_parent_methods() {
		$request = new Request( 'business-123', 'GET' );
		
		// Test that parent methods exist
		$this->assertTrue( method_exists( $request, 'set_params' ) );
		$this->assertTrue( method_exists( $request, 'set_data' ) );
		$this->assertTrue( method_exists( $request, 'get_params' ) );
		$this->assertTrue( method_exists( $request, 'get_data' ) );
		$this->assertTrue( method_exists( $request, 'get_retry_count' ) );
		$this->assertTrue( method_exists( $request, 'get_retry_limit' ) );
		$this->assertTrue( method_exists( $request, 'mark_retry' ) );
		$this->assertTrue( method_exists( $request, 'get_path' ) );
		$this->assertTrue( method_exists( $request, 'get_method' ) );
	}

	/**
	 * Test set_params functionality.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_set_params() {
		$request = new Request( 'business-123', 'GET' );
		
		$params = array(
			'access_token' => 'test_token_123',
			'limit'        => 50,
			'fields'       => 'id,name',
		);
		
		$request->set_params( $params );
		$this->assertEquals( $params, $request->get_params() );
		
		// Test with empty params
		$request->set_params( array() );
		$this->assertEquals( array(), $request->get_params() );
	}

	/**
	 * Test set_data functionality.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_set_data() {
		$request = new Request( 'business-123', 'POST' );
		
		$data = array(
			'configuration' => array(
				'pixel_id'   => '123456',
				'catalog_id' => '789012',
			),
		);
		
		$request->set_data( $data );
		$this->assertEquals( $data, $request->get_data() );
		
		// Test with empty data
		$request->set_data( array() );
		$this->assertEquals( array(), $request->get_data() );
	}

	/**
	 * Test retry functionality.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_retry_functionality() {
		$request = new Request( 'business-123', 'GET' );
		
		// Test initial retry count
		$this->assertEquals( 0, $request->get_retry_count() );
		
		// Default retry limit should be 5
		$this->assertEquals( 5, $request->get_retry_limit() );
		
		// Mark as retried
		$request->mark_retry();
		$this->assertEquals( 1, $request->get_retry_count() );
		
		// Mark as retried again
		$request->mark_retry();
		$this->assertEquals( 2, $request->get_retry_count() );
		
		// Test multiple retries
		for ( $i = 2; $i < 5; $i++ ) {
			$request->mark_retry();
		}
		$this->assertEquals( 5, $request->get_retry_count() );
	}

	/**
	 * Test retry codes.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_retry_codes() {
		$request = new Request( 'business-123', 'GET' );
		
		// Default retry codes should be empty array
		$this->assertIsArray( $request->get_retry_codes() );
		$this->assertEmpty( $request->get_retry_codes() );
	}

	/**
	 * Test get_base_path_override returns null.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_get_base_path_override() {
		$request = new Request( 'business-123', 'GET' );
		
		$this->assertNull( $request->get_base_path_override() );
	}

	/**
	 * Test get_request_specific_headers returns empty array.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_get_request_specific_headers() {
		$request = new Request( 'business-123', 'GET' );
		
		$headers = $request->get_request_specific_headers();
		
		$this->assertIsArray( $headers );
		$this->assertEmpty( $headers );
		$this->assertEquals( array(), $headers );
	}

	/**
	 * Test to_string method with data.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_to_string_with_data() {
		$request = new Request( 'business-123', 'POST' );
		
		$data = array(
			'pixel_id'   => '123456',
			'catalog_id' => '789012',
		);
		
		$request->set_data( $data );
		
		$result = $request->to_string();
		
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
		$this->assertEquals( wp_json_encode( $data ), $result );
	}

	/**
	 * Test to_string method with empty data.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_to_string_with_empty_data() {
		$request = new Request( 'business-123', 'GET' );
		
		$result = $request->to_string();
		
		$this->assertIsString( $result );
		$this->assertEquals( '', $result );
	}

	/**
	 * Test to_string_safe method.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_to_string_safe() {
		$request = new Request( 'business-123', 'POST' );
		
		$data = array(
			'key'    => 'value',
			'number' => 123,
		);
		
		$request->set_data( $data );
		
		$this->assertIsString( $request->to_string_safe() );
		$this->assertEquals( $request->to_string(), $request->to_string_safe() );
	}

	/**
	 * Test creating multiple instances with different business IDs.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_multiple_instances_with_different_business_ids() {
		$request1 = new Request( 'business-one', 'GET' );
		$request2 = new Request( 'business-two', 'POST' );
		$request3 = new Request( 'business-three', 'PUT' );
		
		// Verify each has the correct path
		$this->assertEquals( '/fbe_business?fbe_external_business_id=business-one', $request1->get_path() );
		$this->assertEquals( '/fbe_business?fbe_external_business_id=business-two', $request2->get_path() );
		$this->assertEquals( '/fbe_business?fbe_external_business_id=business-three', $request3->get_path() );
		
		// Verify each has the correct method
		$this->assertEquals( 'GET', $request1->get_method() );
		$this->assertEquals( 'POST', $request2->get_method() );
		$this->assertEquals( 'PUT', $request3->get_method() );
		
		// Verify they don't interfere with each other
		$this->assertNotEquals( $request1->get_path(), $request2->get_path() );
		$this->assertNotEquals( $request2->get_path(), $request3->get_path() );
		$this->assertNotEquals( $request1->get_path(), $request3->get_path() );
	}

	/**
	 * Test that instances are isolated from each other.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_instances_are_isolated() {
		$request1 = new Request( 'business-alpha', 'GET' );
		$request2 = new Request( 'business-beta', 'POST' );
		
		// Set different params for each
		$request1->set_params( array( 'param1' => 'value1' ) );
		$request2->set_params( array( 'param2' => 'value2' ) );
		
		// Verify params are isolated
		$this->assertEquals( array( 'param1' => 'value1' ), $request1->get_params() );
		$this->assertEquals( array( 'param2' => 'value2' ), $request2->get_params() );
		
		// Set different data for each
		$request1->set_data( array( 'data1' => 'value1' ) );
		$request2->set_data( array( 'data2' => 'value2' ) );
		
		// Verify data is isolated
		$this->assertEquals( array( 'data1' => 'value1' ), $request1->get_data() );
		$this->assertEquals( array( 'data2' => 'value2' ), $request2->get_data() );
	}

	/**
	 * Test that method is correctly stored and retrieved.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_method_is_correctly_stored() {
		$methods = array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' );
		
		foreach ( $methods as $method ) {
			$request = new Request( 'business-123', $method );
			$this->assertEquals( $method, $request->get_method() );
			$this->assertIsString( $request->get_method() );
		}
	}

	/**
	 * Test get_params returns empty array by default.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_get_params_returns_empty_array_by_default() {
		$request = new Request( 'business-123', 'GET' );
		
		$this->assertEquals( array(), $request->get_params() );
		$this->assertIsArray( $request->get_params() );
		$this->assertEmpty( $request->get_params() );
	}

	/**
	 * Test get_data returns empty array by default.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_get_data_returns_empty_array_by_default() {
		$request = new Request( 'business-123', 'POST' );
		
		$this->assertEquals( array(), $request->get_data() );
		$this->assertIsArray( $request->get_data() );
		$this->assertEmpty( $request->get_data() );
	}

	/**
	 * Test that data preserves all keys and values.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_data_preserves_all_keys_and_values() {
		$data = array(
			'pixel_id'     => '123456',
			'catalog_id'   => '789012',
			'page_id'      => '345678',
			'business_id'  => '901234',
			'custom_data'  => array(
				'key1' => 'value1',
				'key2' => array(
					'nested_key' => 'nested_value',
				),
			),
		);
		
		$request      = new Request( 'business-123', 'POST' );
		$request->set_data( $data );
		$request_data = $request->get_data();
		
		foreach ( $data as $key => $value ) {
			$this->assertArrayHasKey( $key, $request_data );
			$this->assertEquals( $value, $request_data[ $key ] );
		}
	}

	/**
	 * Test that params preserves all keys and values.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_params_preserves_all_keys_and_values() {
		$params = array(
			'access_token' => 'token_123',
			'limit'        => 100,
			'offset'       => 50,
			'fields'       => 'id,name,email',
			'filter'       => array(
				'status' => 'active',
			),
		);
		
		$request       = new Request( 'business-123', 'GET' );
		$request->set_params( $params );
		$request_params = $request->get_params();
		
		foreach ( $params as $key => $value ) {
			$this->assertArrayHasKey( $key, $request_params );
			$this->assertEquals( $value, $request_params[ $key ] );
		}
	}

	/**
	 * Test constructor with unicode characters in business ID.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_constructor_with_unicode_characters() {
		$external_business_id = 'ビジネス-123';
		$request              = new Request( $external_business_id, 'GET' );
		
		$expected_path = '/fbe_business?fbe_external_business_id=ビジネス-123';
		$this->assertEquals( $expected_path, $request->get_path() );
	}

	/**
	 * Test that retry count increments correctly.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_mark_retry_increments_count() {
		$request = new Request( 'business-123', 'GET' );
		
		$this->assertEquals( 0, $request->get_retry_count() );
		
		$request->mark_retry();
		$this->assertEquals( 1, $request->get_retry_count() );
		
		$request->mark_retry();
		$this->assertEquals( 2, $request->get_retry_count() );
		
		$request->mark_retry();
		$this->assertEquals( 3, $request->get_retry_count() );
	}

	/**
	 * Test get_retry_limit returns integer.
	 *
	 * @covers \WooCommerce\Facebook\API\FBE\Configuration\Request::__construct
	 */
	public function test_get_retry_limit_returns_integer() {
		$request = new Request( 'business-123', 'GET' );
		
		$retry_limit = $request->get_retry_limit();
		
		$this->assertIsInt( $retry_limit );
		$this->assertGreaterThanOrEqual( 0, $retry_limit );
	}
}
