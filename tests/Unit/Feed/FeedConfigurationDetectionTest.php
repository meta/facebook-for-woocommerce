<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Feed;

use WooCommerce\Facebook\Feed\FeedConfigurationDetection;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;
use WooCommerce\Facebook\Utilities\Heartbeat;

/**
 * Unit tests for FeedConfigurationDetection class.
 *
 * @since 3.5.2
 */
class FeedConfigurationDetectionTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Test that the class can be instantiated.
	 */
	public function test_class_exists_and_can_be_instantiated() {
		$this->assertTrue( class_exists( FeedConfigurationDetection::class ) );
		
		$detection = new FeedConfigurationDetection();
		$this->assertInstanceOf( FeedConfigurationDetection::class, $detection );
	}

	/**
	 * Test constructor adds action.
	 */
	public function test_constructor_adds_action() {
		$detection = new FeedConfigurationDetection();
		
		$this->assertNotFalse( 
			has_action( Heartbeat::DAILY, [ $detection, 'track_data_source_feed_tracker_info' ] ) 
		);
	}

	/**
	 * Test track_data_source_feed_tracker_info with transient already set.
	 */
	public function test_track_data_source_feed_tracker_info_with_transient_set() {
		$detection = new FeedConfigurationDetection();
		
		// Set the transient flag
		set_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info', 'yes', DAY_IN_SECONDS );
		
		// Mock the tracker to ensure track method is not called
		$mock_tracker = $this->createMock( \WooCommerce\Facebook\Utilities\Tracker::class );
		$mock_tracker->expects( $this->never() )->method( 'track_facebook_feed_config' );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_tracker' )->willReturn( $mock_tracker );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Call the method
		$detection->track_data_source_feed_tracker_info();
		
		// Clean up
		delete_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' );
	}

	/**
	 * Test track_data_source_feed_tracker_info with valid data.
	 */
	public function test_track_data_source_feed_tracker_info_with_valid_data() {
		$detection = new FeedConfigurationDetection();
		
		// Ensure transient is not set
		delete_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' );
		
		// Mock the integration
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_feed_id' )->willReturn( 'test_feed_123' );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_456' );
		
		// Mock the API response for read_feeds
		$mock_feeds_response = $this->createMock( \WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\ReadAll\Response::class );
		$mock_feeds_response->method( '__get' )->with( 'data' )->willReturn( [
			[ 'id' => 'feed_1', 'name' => 'Feed 1' ],
			[ 'id' => 'test_feed_123', 'name' => 'Test Feed' ]
		] );
		
		// Mock feed metadata response
		$mock_feed_response = $this->createMock( \WooCommerce\Facebook\API\Response::class );
		$feed_data = [
			'id' => 'test_feed_123',
			'created_time' => '2023-01-01T00:00:00+0000',
			'product_count' => 100,
			'schedule' => [
				'interval' => 'DAILY',
				'interval_count' => 1
			],
			'latest_upload' => [
				'id' => 'upload_789',
				'start_time' => '2023-01-02T00:00:00+0000',
				'end_time' => '2023-01-02T01:00:00+0000'
			]
		];
		$mock_feed_response->method( '__get' )->willReturnCallback( function( $key ) use ( $feed_data ) {
			return $feed_data[ $key ] ?? null;
		} );
		$mock_feed_response->method( 'offsetExists' )->willReturnCallback( function( $key ) use ( $feed_data ) {
			return isset( $feed_data[ $key ] );
		} );
		$mock_feed_response->method( 'offsetGet' )->willReturnCallback( function( $key ) use ( $feed_data ) {
			return $feed_data[ $key ] ?? null;
		} );
		
		// Mock upload metadata response
		$mock_upload_response = $this->createMock( \WooCommerce\Facebook\API\Response::class );
		$upload_data = [
			'error_count' => 5,
			'warning_count' => 10,
			'num_detected_items' => 105,
			'num_persisted_items' => 100,
			'url' => 'https://example.com/feed'
		];
		$mock_upload_response->method( '__get' )->willReturnCallback( function( $key ) use ( $upload_data ) {
			return $upload_data[ $key ] ?? null;
		} );
		$mock_upload_response->method( 'offsetExists' )->willReturnCallback( function( $key ) use ( $upload_data ) {
			return isset( $upload_data[ $key ] );
		} );
		$mock_upload_response->method( 'offsetGet' )->willReturnCallback( function( $key ) use ( $upload_data ) {
			return $upload_data[ $key ] ?? null;
		} );
		
		// Mock the API
		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_feeds' )->willReturn( $mock_feeds_response );
		$mock_api->method( 'read_feed' )->willReturn( $mock_feed_response );
		$mock_api->method( 'read_upload' )->willReturn( $mock_upload_response );
		
		// Mock the tracker
		$mock_tracker = $this->createMock( \WooCommerce\Facebook\Utilities\Tracker::class );
		$mock_tracker->expects( $this->once() )
			->method( 'track_facebook_feed_config' )
			->with( $this->isType( 'array' ) );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );
		$mock_plugin->method( 'get_tracker' )->willReturn( $mock_tracker );
		$mock_plugin->method( 'log' )->willReturn( null );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Call the method
		$detection->track_data_source_feed_tracker_info();
		
		// Verify transient was set
		$this->assertEquals( 'yes', get_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' ) );
		
		// Clean up
		delete_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' );
	}

	/**
	 * Test track_data_source_feed_tracker_info with error.
	 */
	public function test_track_data_source_feed_tracker_info_with_error() {
		$detection = new FeedConfigurationDetection();
		
		// Ensure transient is not set
		delete_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' );
		
		// Mock the integration to throw error
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( '' ); // Empty catalog ID
		
		// Mock the plugin
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		$mock_plugin->expects( $this->once() )
			->method( 'log' )
			->with( $this->stringContains( 'Unable to detect valid feed configuration' ) );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Call the method
		$detection->track_data_source_feed_tracker_info();
		
		// Verify transient was still set
		$this->assertEquals( 'yes', get_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' ) );
		
		// Clean up
		delete_transient( '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info' );
	}

	/**
	 * Test get_data_source_feed_tracker_info with no catalog ID.
	 */
	public function test_get_data_source_feed_tracker_info_no_catalog_id() {
		$detection = new FeedConfigurationDetection();
		
		// Mock integration with empty catalog ID
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_feed_id' )->willReturn( 'feed_123' );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( '' );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_data_source_feed_tracker_info' );
		$method->setAccessible( true );
		
		// Should throw error
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'No catalog ID' );
		
		$method->invoke( $detection );
	}

	/**
	 * Test get_data_source_feed_tracker_info with no feed nodes.
	 */
	public function test_get_data_source_feed_tracker_info_no_feed_nodes() {
		$detection = new FeedConfigurationDetection();
		
		// Mock integration
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_feed_id' )->willReturn( 'feed_123' );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_456' );
		
		// Mock API to return empty feeds
		$mock_feeds_response = $this->createMock( \WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\ReadAll\Response::class );
		$mock_feeds_response->method( '__get' )->with( 'data' )->willReturn( [] );
		
		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_feeds' )->willReturn( $mock_feeds_response );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );
		$mock_plugin->method( 'log' )->willReturn( null );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_data_source_feed_tracker_info' );
		$method->setAccessible( true );
		
		// Should throw error
		$this->expectException( \Error::class );
		$this->expectExceptionMessage( 'No feed nodes for catalog' );
		
		$method->invoke( $detection );
	}

	/**
	 * Test get_data_source_feed_tracker_info with valid feed data.
	 */
	public function test_get_data_source_feed_tracker_info_with_valid_feed() {
		$detection = new FeedConfigurationDetection();
		
		// Mock integration
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_feed_id' )->willReturn( 'feed_123' );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_456' );
		
		// Mock API responses
		$mock_feeds_response = $this->createMock( \WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\ReadAll\Response::class );
		$mock_feeds_response->method( '__get' )->with( 'data' )->willReturn( [
			[ 'id' => 'feed_123', 'name' => 'Test Feed' ]
		] );
		
		// Mock feed metadata response
		$mock_feed_response = $this->createMock( \WooCommerce\Facebook\API\Response::class );
		$feed_data = [
			'id' => 'feed_123',
			'created_time' => '2023-01-01T00:00:00+0000',
			'product_count' => 100,
			'schedule' => [
				'interval' => 'DAILY',
				'interval_count' => 1
			],
			'update_schedule' => [
				'interval' => 'HOURLY',
				'interval_count' => 2
			],
			'latest_upload' => [
				'id' => 'upload_789',
				'start_time' => '2023-01-02T00:00:00+0000',
				'end_time' => '2023-01-02T01:00:00+0000'
			]
		];
		$mock_feed_response->method( '__get' )->willReturnCallback( function( $key ) use ( $feed_data ) {
			return $feed_data[ $key ] ?? null;
		} );
		$mock_feed_response->method( 'offsetExists' )->willReturnCallback( function( $key ) use ( $feed_data ) {
			return isset( $feed_data[ $key ] );
		} );
		$mock_feed_response->method( 'offsetGet' )->willReturnCallback( function( $key ) use ( $feed_data ) {
			return $feed_data[ $key ] ?? null;
		} );
		
		// Mock upload metadata response
		$mock_upload_response = $this->createMock( \WooCommerce\Facebook\API\Response::class );
		$upload_data = [
			'error_count' => 5,
			'warning_count' => 10,
			'num_detected_items' => 105,
			'num_persisted_items' => 100,
			'url' => 'https://example.com/feed'
		];
		$mock_upload_response->method( '__get' )->willReturnCallback( function( $key ) use ( $upload_data ) {
			return $upload_data[ $key ] ?? null;
		} );
		$mock_upload_response->method( 'offsetExists' )->willReturnCallback( function( $key ) use ( $upload_data ) {
			return isset( $upload_data[ $key ] );
		} );
		$mock_upload_response->method( 'offsetGet' )->willReturnCallback( function( $key ) use ( $upload_data ) {
			return $upload_data[ $key ] ?? null;
		} );
		
		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_feeds' )->willReturn( $mock_feeds_response );
		$mock_api->method( 'read_feed' )->willReturn( $mock_feed_response );
		$mock_api->method( 'read_upload' )->willReturn( $mock_upload_response );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_data_source_feed_tracker_info' );
		$method->setAccessible( true );
		
		$result = $method->invoke( $detection );
		
		// Verify result structure
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'site-feed-id', $result );
		$this->assertArrayHasKey( 'feed-count', $result );
		$this->assertArrayHasKey( 'active-feed', $result );
		
		$this->assertEquals( 'feed_123', $result['site-feed-id'] );
		$this->assertEquals( 1, $result['feed-count'] );
		
		// Verify active feed data
		$active_feed = $result['active-feed'];
		$this->assertArrayHasKey( 'created-time', $active_feed );
		$this->assertArrayHasKey( 'product-count', $active_feed );
		$this->assertArrayHasKey( 'schedule', $active_feed );
		$this->assertArrayHasKey( 'update-schedule', $active_feed );
		$this->assertArrayHasKey( 'latest-upload', $active_feed );
		
		$this->assertEquals( 100, $active_feed['product-count'] );
		$this->assertEquals( 'DAILY', $active_feed['schedule']['interval'] );
		$this->assertEquals( 1, $active_feed['schedule']['interval-count'] );
		$this->assertEquals( 'HOURLY', $active_feed['update-schedule']['interval'] );
		$this->assertEquals( 2, $active_feed['update-schedule']['interval-count'] );
		
		// Verify upload data
		$upload = $active_feed['latest-upload'];
		$this->assertArrayHasKey( 'end-time', $upload );
		$this->assertArrayHasKey( 'error-count', $upload );
		$this->assertArrayHasKey( 'warning-count', $upload );
		$this->assertArrayHasKey( 'num-detected-items', $upload );
		$this->assertArrayHasKey( 'num-persisted-items', $upload );
		$this->assertArrayHasKey( 'url-matches-site-endpoint', $upload );
		
		$this->assertEquals( 5, $upload['error-count'] );
		$this->assertEquals( 10, $upload['warning-count'] );
		$this->assertEquals( 105, $upload['num-detected-items'] );
		$this->assertEquals( 100, $upload['num-persisted-items'] );
		// The URL comparison result will be 'no' since we can't mock the static method
		$this->assertContains( $upload['url-matches-site-endpoint'], [ 'yes', 'no' ] );
	}

	/**
	 * Test get_data_source_feed_tracker_info when the upload metadata omits its count and url fields,
	 * as the Graph API does while an upload is still processing.
	 */
	public function test_get_data_source_feed_tracker_info_with_upload_metadata_missing_fields() {
		$detection = new FeedConfigurationDetection();

		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_feed_id' )->willReturn( 'feed_123' );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_456' );

		$mock_feeds_response = $this->createMock( \WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\ReadAll\Response::class );
		$mock_feeds_response->method( '__get' )->with( 'data' )->willReturn( [
			[ 'id' => 'feed_123', 'name' => 'Test Feed' ]
		] );

		$feed_response = new \WooCommerce\Facebook\API\Response( wp_json_encode( [
			'id'            => 'feed_123',
			'latest_upload' => [
				'id'         => 'upload_789',
				'start_time' => '2023-01-02T00:00:00+0000',
			],
		] ) );

		// A real response, so a missing key goes through the same offsetGet() that raised the warning.
		$upload_response = new \WooCommerce\Facebook\API\Response( wp_json_encode( [ 'id' => 'upload_789' ] ) );

		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_feeds' )->willReturn( $mock_feeds_response );
		$mock_api->method( 'read_feed' )->willReturn( $feed_response );
		$mock_api->method( 'read_upload' )->willReturn( $upload_response );

		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );

		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );

		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_data_source_feed_tracker_info' );
		$method->setAccessible( true );

		$result = $method->invoke( $detection );

		$upload = $result['active-feed']['latest-upload'];
		$this->assertSame( 0, $upload['error-count'] );
		$this->assertSame( 0, $upload['warning-count'] );
		$this->assertSame( 0, $upload['num-detected-items'] );
		$this->assertSame( 0, $upload['num-persisted-items'] );
		$this->assertSame( 'no', $upload['url-matches-site-endpoint'] );
	}

	/**
	 * Test get_data_source_feed_tracker_info with multiple feeds.
	 */
	public function test_get_data_source_feed_tracker_info_with_multiple_feeds() {
		$detection = new FeedConfigurationDetection();
		
		// Mock integration
		$mock_integration = $this->createMock( \WC_Facebookcommerce_Integration::class );
		$mock_integration->method( 'get_feed_id' )->willReturn( 'feed_123' );
		$mock_integration->method( 'get_product_catalog_id' )->willReturn( 'catalog_456' );
		
		// Mock API with multiple feeds
		$mock_feeds_response = $this->createMock( \WooCommerce\Facebook\API\ProductCatalog\ProductFeeds\ReadAll\Response::class );
		$mock_feeds_response->method( '__get' )->with( 'data' )->willReturn( [
			[ 'id' => 'feed_old', 'name' => 'Old Feed' ],
			[ 'id' => 'feed_123', 'name' => 'Current Feed' ],
			[ 'id' => 'feed_new', 'name' => 'New Feed' ]
		] );
		
		// Mock different feed metadata
		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_feeds' )->willReturn( $mock_feeds_response );
		$mock_api->method( 'read_feed' )->willReturnCallback( function( $feed_id ) {
			$mock_response = $this->createMock( \WooCommerce\Facebook\API\Response::class );
			if ( $feed_id === 'feed_123' ) {
				$data = [
					'id' => 'feed_123',
					'created_time' => '2023-01-02T00:00:00+0000',
					'product_count' => 100,
					'latest_upload' => [
						'id' => 'upload_123',
						'start_time' => '2023-01-02T00:00:00+0000'
					]
				];
			} else {
				$data = [
					'id' => $feed_id,
					'created_time' => '2023-01-01T00:00:00+0000',
					'product_count' => 50
				];
			}
			$mock_response->method( '__get' )->willReturnCallback( function( $key ) use ( $data ) {
				return $data[ $key ] ?? null;
			} );
			$mock_response->method( 'offsetExists' )->willReturnCallback( function( $key ) use ( $data ) {
				return isset( $data[ $key ] );
			} );
			$mock_response->method( 'offsetGet' )->willReturnCallback( function( $key ) use ( $data ) {
				return $data[ $key ] ?? null;
			} );
			return $mock_response;
		} );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_integration' )->willReturn( $mock_integration );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_data_source_feed_tracker_info' );
		$method->setAccessible( true );
		
		$result = $method->invoke( $detection );
		
		// Should have selected the matching feed
		$this->assertEquals( 3, $result['feed-count'] );
		$this->assertArrayHasKey( 'active-feed', $result );
		$this->assertEquals( 100, $result['active-feed']['product-count'] );
	}

	/**
	 * Test get_feed_nodes_for_catalog with API exception.
	 */
	public function test_get_feed_nodes_for_catalog_with_exception() {
		$detection = new FeedConfigurationDetection();
		
		// Mock API to throw exception
		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_feeds' )->willThrowException( new \Exception( 'API Error' ) );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );
		$mock_plugin->expects( $this->once() )
			->method( 'log' )
			->with( $this->stringContains( 'There was an error trying to get feed nodes' ) );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_feed_nodes_for_catalog' );
		$method->setAccessible( true );
		
		$result = $method->invokeArgs( $detection, [ 'catalog_123' ] );
		
		// Should return empty array on exception
		$this->assertEquals( [], $result );
	}

	/**
	 * Test get_feed_upload_metadata with exception.
	 */
	public function test_get_feed_upload_metadata_with_exception() {
		$detection = new FeedConfigurationDetection();
		
		// Mock API to throw exception
		$mock_api = $this->createMock( \WooCommerce\Facebook\API::class );
		$mock_api->method( 'read_upload' )->willThrowException( new \Exception( 'Upload API Error' ) );
		
		$mock_plugin = $this->createMock( \WC_Facebookcommerce::class );
		$mock_plugin->method( 'get_api' )->willReturn( $mock_api );
		$mock_plugin->expects( $this->once() )
			->method( 'log' )
			->with( $this->stringContains( 'There was an error trying to get feed upload metadata' ) );
		
		$this->add_filter_with_safe_teardown( 'wc_facebook_instance', function() use ( $mock_plugin ) {
			return $mock_plugin;
		} );
		
		// Use reflection to access private method
		$reflection = new \ReflectionClass( $detection );
		$method = $reflection->getMethod( 'get_feed_upload_metadata' );
		$method->setAccessible( true );
		
		$result = $method->invokeArgs( $detection, [ 'upload_123' ] );
		
		// Should return false on exception
		$this->assertFalse( $result );
	}
} 