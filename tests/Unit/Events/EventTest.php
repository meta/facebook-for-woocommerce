<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Events;

use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for Event class.
 *
 * @since 3.5.2
 */
class EventTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The request URI in place before the test, restored afterwards.
	 *
	 * @var string|null
	 */
	private $original_request_uri;

	/**
	 * Remembers the request URI so that tests can replace or unset it freely.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
	}

	/**
	 * Puts back the request URI that was in place before the test.
	 */
	public function tearDown(): void {
		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}

		parent::tearDown();
	}

	/**
	 * Test that the class exists.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( Event::class ) );
	}

	/**
	 * Test get_version_info static method.
	 */
	public function test_get_version_info() {
		$version_info = Event::get_version_info();
		
		$this->assertIsArray( $version_info );
		$this->assertArrayHasKey( 'source', $version_info );
		$this->assertArrayHasKey( 'version', $version_info );
		$this->assertArrayHasKey( 'pluginVersion', $version_info );
		
		$this->assertEquals( 'woocommerce_0', $version_info['source'] );
		$this->assertIsString( $version_info['version'] );
		$this->assertIsString( $version_info['pluginVersion'] );
	}

	/**
	 * Test get_platform_identifier static method.
	 */
	public function test_get_platform_identifier() {
		$identifier = Event::get_platform_identifier();
		
		$this->assertIsString( $identifier );
		$this->assertStringContainsString( 'woocommerce', $identifier );
		$this->assertMatchesRegularExpression( '/^woocommerce[_\d]*-[\d.]+-[\d.]+$/', $identifier );
	}

	/**
	 * Test constructor with empty data.
	 */
	public function test_constructor_empty_data() {
		$event = new Event();
		$data = $event->get_data();
		
		// Check default values are set
		$this->assertArrayHasKey( 'action_source', $data );
		$this->assertArrayHasKey( 'event_time', $data );
		$this->assertArrayHasKey( 'event_id', $data );
		$this->assertArrayHasKey( 'event_source_url', $data );
		$this->assertArrayHasKey( 'custom_data', $data );
		$this->assertArrayHasKey( 'user_data', $data );
		
		$this->assertEquals( 'website', $data['action_source'] );
		$this->assertIsInt( $data['event_time'] );
		$this->assertIsString( $data['event_id'] );
		$this->assertIsArray( $data['custom_data'] );
		$this->assertIsArray( $data['user_data'] );
	}

	/**
	 * Test constructor with custom data.
	 */
	public function test_constructor_with_custom_data() {
		$custom_data = array(
			'event_name'    => 'Purchase',
			'event_time'    => 1234567890,
			'event_id'      => 'custom-event-123',
			'custom_data'   => array( 'value' => '100.00', 'currency' => 'USD' ),
			'action_source' => 'app',
		);
		
		$event = new Event( $custom_data );
		$data = $event->get_data();
		
		$this->assertEquals( 'Purchase', $data['event_name'] );
		$this->assertEquals( 'app', $data['action_source'] );
		$this->assertEquals( 1234567890, $data['event_time'] );
		$this->assertEquals( 'custom-event-123', $data['event_id'] );
		$this->assertEquals( '100.00', $data['custom_data']['value'] );
		$this->assertEquals( 'USD', $data['custom_data']['currency'] );
	}

	/**
	 * Test event ID generation and get_id method.
	 */
	public function test_event_id_generation_and_getter() {
		$event1 = new Event();
		$event2 = new Event();
		
		$id1 = $event1->get_id();
		$id2 = $event2->get_id();
		
		// Test that get_id returns a non-empty string
		$this->assertIsString( $id1 );
		$this->assertNotEmpty( $id1 );
		
		// Test that ID matches the one in data array
		$this->assertEquals( $event1->get_data()['event_id'], $id1 );
		
		// Test IDs are unique
		$this->assertNotEquals( $id1, $id2 );
		
		// Test IDs match UUID v4 format
		$uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
		$this->assertMatchesRegularExpression( $uuid_pattern, $id1 );
		$this->assertMatchesRegularExpression( $uuid_pattern, $id2 );
	}

	/**
	 * Test get_name method.
	 */
	public function test_get_name() {
		// Test with no event name
		$event1 = new Event();
		$this->assertEquals( '', $event1->get_name() );
		
		// Test with event name
		$event2 = new Event( array( 'event_name' => 'ViewContent' ) );
		$this->assertEquals( 'ViewContent', $event2->get_name() );
	}

	/**
	 * Test get_user_data method.
	 */
	public function test_get_user_data() {
		$event = new Event();
		$user_data = $event->get_user_data();
		
		$this->assertIsArray( $user_data );
		$this->assertArrayHasKey( 'client_ip_address', $user_data );
		$this->assertArrayHasKey( 'client_user_agent', $user_data );
		$this->assertArrayHasKey( 'click_id', $user_data );
		$this->assertArrayHasKey( 'browser_id', $user_data );
	}

	/**
	 * Test get_custom_data method.
	 */
	public function test_get_custom_data() {
		// Test with empty custom data
		$event1 = new Event();
		$this->assertIsArray( $event1->get_custom_data() );
		$this->assertEmpty( $event1->get_custom_data() );
		
		// Test with custom data
		$event2 = new Event( array( 'custom_data' => array( 'test' => 'value' ) ) );
		$custom_data = $event2->get_custom_data();
		$this->assertIsArray( $custom_data );
		$this->assertEquals( 'value', $custom_data['test'] );
	}

	/**
	 * Test user data hashing.
	 */
	public function test_user_data_hashing() {
		$user_data = array(
			'em'      => 'test@example.com',
			'fn'      => 'John',
			'ln'      => 'Doe',
			'ph'      => '+1234567890',
			'ct'      => 'New York',
			'st'      => 'NY',
			'zp'      => '10001',
			'country' => 'US',
		);
		
		$event = new Event( array( 'user_data' => $user_data ) );
		$hashed_data = $event->get_user_data();
		
		// Check that PII fields are hashed
		foreach ( array( 'em', 'fn', 'ln', 'ph', 'ct', 'st', 'zp', 'country' ) as $field ) {
			$this->assertNotEquals( $user_data[ $field ], $hashed_data[ $field ] );
			$this->assertEquals( 64, strlen( $hashed_data[ $field ] ) ); // SHA256 produces 64 char hex string
		}
	}

	/**
	 * Test external_id hashing.
	 */
	public function test_external_id_hashing() {
		// Test single external ID
		$event1 = new Event( array( 'user_data' => array( 'external_id' => 'user123' ) ) );
		$user_data1 = $event1->get_user_data();
		$this->assertNotEquals( 'user123', $user_data1['external_id'] );
		$this->assertEquals( 64, strlen( $user_data1['external_id'] ) );
		
		// Note: The current implementation has a bug with array external IDs
		// It tries to access $user_data['external_id'][$id] where $id is already the value
		// This test documents the current behavior, even though it's incorrect
	}

	/**
	 * Test country code conversion from cn to country.
	 */
	public function test_country_code_conversion() {
		$event = new Event( array( 'user_data' => array( 'cn' => 'US' ) ) );
		$user_data = $event->get_user_data();
		
		$this->assertArrayNotHasKey( 'cn', $user_data );
		$this->assertArrayHasKey( 'country', $user_data );
		// Country should be hashed
		$this->assertEquals( 64, strlen( $user_data['country'] ) );
	}

	/**
	 * Test event time is close to current time.
	 */
	public function test_event_time() {
		$before = time();
		$event = new Event();
		$after = time();
		
		$event_time = $event->get_data()['event_time'];
		
		$this->assertGreaterThanOrEqual( $before, $event_time );
		$this->assertLessThanOrEqual( $after, $event_time );
	}

	/**
	 * Test custom event time.
	 */
	public function test_custom_event_time() {
		$custom_time = 1234567890;
		$event = new Event( array( 'event_time' => $custom_time ) );
		
		$this->assertEquals( $custom_time, $event->get_data()['event_time'] );
	}

	/**
	 * Test that non-PII user data is not hashed.
	 */
	public function test_non_pii_data_not_hashed() {
		$user_data = array(
			'client_ip_address' => '192.168.1.1',
			'client_user_agent' => 'Mozilla/5.0',
			'click_id'          => 'fb.1.123456.fbclid123',
			'browser_id'        => '_fbp.123456',
			'custom_field'      => 'custom_value',
		);
		
		$event = new Event( array( 'user_data' => $user_data ) );
		$result_data = $event->get_user_data();
		
		// These fields should not be hashed
		$this->assertEquals( $user_data['client_ip_address'], $result_data['client_ip_address'] );
		$this->assertEquals( $user_data['client_user_agent'], $result_data['client_user_agent'] );
		$this->assertEquals( $user_data['click_id'], $result_data['click_id'] );
		$this->assertEquals( $user_data['browser_id'], $result_data['browser_id'] );
		$this->assertEquals( $user_data['custom_field'], $result_data['custom_field'] );
	}

	/**
	 * Test that user data fields are properly processed.
	 */
	public function test_user_data_field_processing() {
		// Test with non-empty values to avoid normalization issues
		$user_data = array(
			'em' => 'test@example.com',
			'fn' => 'Test',
			'ln' => 'User',
			'custom_field' => 'custom_value', // Non-PII field
		);
		
		$event = new Event( array( 'user_data' => $user_data ) );
		$hashed_data = $event->get_user_data();
		
		// PII fields should be hashed
		$this->assertArrayHasKey( 'em', $hashed_data );
		$this->assertArrayHasKey( 'fn', $hashed_data );
		$this->assertArrayHasKey( 'ln', $hashed_data );
		$this->assertEquals( 64, strlen( $hashed_data['em'] ) );
		$this->assertEquals( 64, strlen( $hashed_data['fn'] ) );
		$this->assertEquals( 64, strlen( $hashed_data['ln'] ) );
		
		// Non-PII fields should not be hashed
		$this->assertEquals( 'custom_value', $hashed_data['custom_field'] );
		
		// Note: Empty string handling has issues in the current implementation
		// where normalization converts empty strings to null causing deprecation warnings
	}

	/**
	 * Test event with all possible data fields.
	 */
	public function test_comprehensive_event_data() {
		$event_data = array(
			'event_name'       => 'Purchase',
			'event_time'       => 1234567890,
			'event_id'         => 'custom-event-id',
			'event_source_url' => 'https://example.com/checkout',
			'action_source'    => 'website',
			'custom_data'      => array(
				'value'        => '99.99',
				'currency'     => 'USD',
				'content_type' => 'product',
				'content_ids'  => array( '123', '456' ),
			),
			'user_data'        => array(
				'em'                => 'test@example.com',
				'fn'                => 'Jane',
				'ln'                => 'Smith',
				'ph'                => '+1987654321',
				'ct'                => 'Los Angeles',
				'st'                => 'CA',
				'zp'                => '90001',
				'country'           => 'US',
				'external_id'       => 'user456',
				'client_ip_address' => '10.0.0.1',
				'client_user_agent' => 'Custom User Agent',
				'click_id'          => 'custom_click_id',
				'browser_id'        => 'custom_browser_id',
			),
		);
		
		$event = new Event( $event_data );
		$data = $event->get_data();
		
		// Verify all fields are present
		$this->assertEquals( 'Purchase', $data['event_name'] );
		$this->assertEquals( 1234567890, $data['event_time'] );
		$this->assertEquals( 'custom-event-id', $data['event_id'] );
		$this->assertEquals( 'https://example.com/checkout', $data['event_source_url'] );
		$this->assertEquals( 'website', $data['action_source'] );
		
		// Verify custom data
		$this->assertEquals( '99.99', $data['custom_data']['value'] );
		$this->assertEquals( 'USD', $data['custom_data']['currency'] );
		$this->assertEquals( 'product', $data['custom_data']['content_type'] );
		$this->assertEquals( array( '123', '456' ), $data['custom_data']['content_ids'] );
		
		// Verify user data (PII should be hashed)
		$this->assertEquals( 64, strlen( $data['user_data']['em'] ) );
		$this->assertEquals( 64, strlen( $data['user_data']['fn'] ) );
		$this->assertEquals( 64, strlen( $data['user_data']['ln'] ) );
		$this->assertEquals( '10.0.0.1', $data['user_data']['client_ip_address'] );
		$this->assertEquals( 'Custom User Agent', $data['user_data']['client_user_agent'] );
	}

	/**
	 * Test that click_id is stored internally in Event.
	 */
	public function test_click_id_stored_internally() {
		$event = new Event();
		$user_data = $event->get_user_data();
		
		// Event uses click_id internally, which will be converted to fbc by Request class
		$this->assertArrayHasKey( 'click_id', $user_data );
	}

	/**
	 * Test that browser_id is stored internally in Event.
	 */
	public function test_browser_id_stored_internally() {
		$event = new Event();
		$user_data = $event->get_user_data();
		
		// Event uses browser_id internally, which will be converted to fbp by Request class
		$this->assertArrayHasKey( 'browser_id', $user_data );
	}

	/**
	 * Test that custom click_id value is preserved in Event.
	 */
	public function test_custom_click_id_value_preserved() {
		$custom_click_id = 'fb.1.1234567890.AbCdEfGh';
		$event = new Event( array( 'user_data' => array( 'click_id' => $custom_click_id ) ) );
		$user_data = $event->get_user_data();
		
		$this->assertEquals( $custom_click_id, $user_data['click_id'] );
	}

	/**
	 * Test that custom browser_id value is preserved in Event.
	 */
	public function test_custom_browser_id_value_preserved() {
		$custom_browser_id = 'fb.1.1234567890.987654321';
		$event = new Event( array( 'user_data' => array( 'browser_id' => $custom_browser_id ) ) );
		$user_data = $event->get_user_data();
		
		$this->assertEquals( $custom_browser_id, $user_data['browser_id'] );
	}

	/**
	 * Test that click_id and browser_id are not hashed (they are not PII).
	 */
	public function test_click_id_browser_id_not_hashed() {
		$custom_click_id = 'fb.1.1234567890.AbCdEfGh';
		$custom_browser_id = 'fb.1.1234567890.987654321';
		$event = new Event( array( 
			'user_data' => array( 
				'click_id' => $custom_click_id,
				'browser_id' => $custom_browser_id,
			) 
		) );
		$user_data = $event->get_user_data();
		
		// Verify these are not hashed (should be the same value)
		$this->assertEquals( $custom_click_id, $user_data['click_id'] );
		$this->assertEquals( $custom_browser_id, $user_data['browser_id'] );
		// Verify they are not 64 characters (SHA256 hash length)
		$this->assertNotEquals( 64, strlen( $user_data['click_id'] ) );
		$this->assertNotEquals( 64, strlen( $user_data['browser_id'] ) );
	}

	/**
	 * Test that fbc/fbp provided as input are stored as-is (for backward compatibility).
	 * Note: The Request class will handle conversion from click_id/browser_id to fbc/fbp.
	 */
	public function test_fbc_fbp_input_stored() {
		$custom_fbc = 'fb.1.1234567890.AbCdEfGh';
		$custom_fbp = 'fb.1.1234567890.987654321';
		$event_data = array(
			'event_name'  => 'Purchase',
			'custom_data' => array( 'value' => '100.00' ),
			'user_data'   => array(
				'em' => 'test@example.com',
				'fbc' => $custom_fbc,
				'fbp' => $custom_fbp,
			),
		);
		
		$event = new Event( $event_data );
		$data = $event->get_data();
		
		// When fbc/fbp are provided, they're stored as-is
		$this->assertArrayHasKey( 'fbc', $data['user_data'] );
		$this->assertArrayHasKey( 'fbp', $data['user_data'] );
		$this->assertEquals( $custom_fbc, $data['user_data']['fbc'] );
		$this->assertEquals( $custom_fbp, $data['user_data']['fbp'] );
	}

	/**
	 * Test that a physical store event omits the source URL.
	 *
	 * An in-store sale did not happen on a page, so there is no URL to report.
	 */
	public function test_physical_store_event_has_no_source_url() {
		$event = new Event(
			array(
				'event_name'    => 'Purchase',
				'action_source' => 'physical_store',
			)
		);
		$data  = $event->get_data();

		$this->assertEquals( 'physical_store', $data['action_source'] );
		$this->assertArrayNotHasKey( 'event_source_url', $data );
		$this->assertArrayNotHasKey( 'referrer_url', $data );
	}

	/**
	 * Test that a physical store event omits browser signals.
	 *
	 * For an in-store sale these describe the till the order was rung up on,
	 * not the customer, so reporting them would be actively wrong.
	 */
	public function test_physical_store_event_has_no_browser_signals() {
		$event = new Event(
			array(
				'event_name'    => 'Purchase',
				'action_source' => 'physical_store',
			)
		);
		$data  = $event->get_data();

		$this->assertArrayNotHasKey( 'client_ip_address', $data['user_data'] );
		$this->assertArrayNotHasKey( 'client_user_agent', $data['user_data'] );
		$this->assertArrayNotHasKey( 'click_id', $data['user_data'] );
		$this->assertArrayNotHasKey( 'browser_id', $data['user_data'] );
	}

	/**
	 * Test that a website event still receives the browser signals.
	 *
	 * Guards against the physical_store carve-out leaking into the web path.
	 */
	public function test_website_event_still_has_browser_signals() {
		$event = new Event( array( 'event_name' => 'Purchase' ) );
		$data  = $event->get_data();

		$this->assertEquals( 'website', $data['action_source'] );
		$this->assertArrayHasKey( 'event_source_url', $data );
		$this->assertArrayHasKey( 'client_ip_address', $data['user_data'] );
		$this->assertArrayHasKey( 'client_user_agent', $data['user_data'] );
	}

	/**
	 * Test that explicitly provided user data survives on a physical store event.
	 */
	public function test_physical_store_event_keeps_explicit_user_data() {
		$event = new Event(
			array(
				'event_name'    => 'Purchase',
				'action_source' => 'physical_store',
				'user_data'     => array( 'em' => 'test@example.com' ),
			)
		);
		$data  = $event->get_data();

		$this->assertArrayHasKey( 'em', $data['user_data'] );
		$this->assertEquals( hash( 'sha256', 'test@example.com', false ), $data['user_data']['em'] );
	}

	/**
	 * Makes the event see the given home URL and request URI, as on a real page load.
	 *
	 * @param string      $home_url    Value returned by home_url(), e.g. WPML's language-prefixed home.
	 * @param string|null $request_uri Value of $_SERVER['REQUEST_URI'], or null to leave it unset.
	 */
	private function simulate_page_request( string $home_url, ?string $request_uri ): void {
		if ( null === $request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $request_uri;
		}

		$this->add_filter_with_safe_teardown(
			'home_url',
			function () use ( $home_url ) {
				return $home_url;
			}
		);
	}

	/**
	 * Test that event_source_url is the page URL even when home_url() carries a path or query.
	 *
	 * WPML serving languages from directories or from a ?lang= parameter, and WordPress
	 * installed in a subdirectory, all put a prefix in home_url() that REQUEST_URI already
	 * contains. Appending home_url() as a whole used to repeat it (/fr//fr/page/).
	 *
	 * @dataProvider provider_event_source_url_page_loads
	 *
	 * @param string $home_url    Value of home_url() during the request.
	 * @param string $request_uri Value of $_SERVER['REQUEST_URI'].
	 * @param string $expected    Expected event_source_url.
	 */
	public function test_event_source_url_uses_home_origin_and_request_uri( string $home_url, string $request_uri, string $expected ) {
		$this->simulate_page_request( $home_url, $request_uri );

		$event = new Event( array( 'event_name' => 'PageView' ) );

		$this->assertSame( $expected, $event->get_data()['event_source_url'] );
	}

	/**
	 * Data provider for test_event_source_url_uses_home_origin_and_request_uri.
	 *
	 * @return array[]
	 */
	public static function provider_event_source_url_page_loads(): array {
		return array(
			'home at the domain root'                 => array( 'https://example.com', '/shop/', 'https://example.com/shop/' ),
			'WPML language directory'                 => array( 'https://example.com/sr', '/sr/shop/?x=1', 'https://example.com/sr/shop/?x=1' ),
			'WPML language directory, trailing slash' => array( 'https://example.com/fr/', '/fr/gallery/landscape/', 'https://example.com/fr/gallery/landscape/' ),
			'WPML language parameter'                 => array( 'https://example.com/?lang=sr', '/shop/?lang=sr', 'https://example.com/shop/?lang=sr' ),
			'WordPress in a subdirectory with a port' => array( 'http://example.com:8080/store', '/store/product/hat/', 'http://example.com:8080/store/product/hat/' ),
		);
	}

	/**
	 * Test that without a request URI the event falls back to the home origin alone.
	 */
	public function test_event_source_url_without_request_uri_is_home_origin() {
		$this->simulate_page_request( 'https://example.com/sr', null );

		$event = new Event( array( 'event_name' => 'PageView' ) );

		$this->assertSame( 'https://example.com', $event->get_data()['event_source_url'] );
	}

	/**
	 * Test that a home URL without a host is used as it is rather than reduced to an origin.
	 *
	 * Only a filter can produce such a home URL. Reducing it would give "https:///shop/".
	 */
	public function test_event_source_url_keeps_home_url_without_a_host() {
		$this->simulate_page_request( '', '/shop/' );

		$event = new Event( array( 'event_name' => 'PageView' ) );

		$this->assertSame( '/shop/', $event->get_data()['event_source_url'] );
	}
}
