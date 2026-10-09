<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Framework\Utilities;

use WooCommerce\Facebook\Framework\Utilities\AsyncRequest;
use WP_UnitTestCase;

/**
 * Unit tests for the AsyncRequest class.
 */
class AsyncRequestTest extends WP_UnitTestCase {

    /** @var array Tracks HTTP requests */
    private $http_requests = [];

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        parent::setUp();
        
        // Reset HTTP request tracking
        $this->http_requests = [];
        
        // Add filter to intercept HTTP requests
        add_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10, 3);
    }
    
    /**
     * Clean up after tests
     */
    protected function tearDown(): void {
        // Remove our filter
        remove_filter('pre_http_request', [$this, 'interceptHttpRequest'], 10);

        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        
        parent::tearDown();
    }
    
    /**
     * Intercept HTTP requests and record them
     */
    public function interceptHttpRequest($_preempt, $args, $url) {
        $this->http_requests[] = [
            'url' => $url,
            'args' => $args
        ];
        
        // Return a fake response
        return [
            'headers' => [],
            'body' => '',
            'response' => [
                'code' => 200,
                'message' => 'OK'
            ],
            'cookies' => [],
            'filename' => ''
        ];
    }

    /**
     * Tests that child classes can override query args, query URL, and request args.
     */
    public function test_child_class_can_override_properties() {
        // Create a mock child class that overrides the properties
        $child = new class extends AsyncRequest {
            protected $prefix = 'test';
            protected $action = 'test_action';
            
            // Define the properties with the same visibility as parent
            protected $query_args = ['custom' => 'query_arg'];
            protected $query_url = 'https://example.com/custom-endpoint';
            protected $request_args = ['custom' => 'request_arg'];
            
            // Implementation of abstract method
            protected function handle() {
                // No-op for testing
            }
        };
        
        // Test that the child class properties are correctly accessed
        $this->assertEquals(
            ['custom' => 'query_arg'],
            $this->invokeMethod($child, 'get_query_args'),
            'Custom query args should be used'
        );
        
        $this->assertEquals(
            'https://example.com/custom-endpoint',
            $this->invokeMethod($child, 'get_query_url'),
            'Custom query URL should be used'
        );
        
        $this->assertEquals(
            ['custom' => 'request_arg'],
            $this->invokeMethod($child, 'get_request_args'),
            'Custom request args should be used'
        );
    }
    
    /**
     * Test that the default methods work correctly when properties are null.
     */
    public function test_default_methods_when_properties_are_null() {
        // Create a child class without setting the properties
        $child = new class extends AsyncRequest {
            protected $prefix = 'test';
            protected $action = 'test_action';
            
            // Implementation of abstract method
            protected function handle() {
                // No-op for testing
            }
        };
        
        // Test that the default implementations are used
        $query_args = $this->invokeMethod($child, 'get_query_args');
        $this->assertIsArray($query_args, 'Query args should be an array');
        $this->assertArrayHasKey('action', $query_args, 'Query args should have action key');
        $this->assertArrayHasKey('nonce', $query_args, 'Query args should have nonce key');
        $this->assertEquals('test_test_action', $query_args['action'], 'Action should be prefixed correctly');
        
        $query_url = $this->invokeMethod($child, 'get_query_url');
        $this->assertStringContainsString('admin-ajax.php', $query_url, 'Query URL should contain admin-ajax.php');
        
        $request_args = $this->invokeMethod($child, 'get_request_args');
        $this->assertIsArray($request_args, 'Request args should be an array');
        $this->assertArrayHasKey('timeout', $request_args, 'Request args should have timeout key');
        $this->assertArrayHasKey('blocking', $request_args, 'Request args should have blocking key');
        $this->assertArrayHasKey('body', $request_args, 'Request args should have body key');
    }
    
    /**
     * Test that the request is actually executed.
     */
    public function test_request_execution() {
        // Create a testable async request class
        $request = new class extends AsyncRequest {
            protected $prefix = 'test';
            protected $action = 'test_action';
            public $handled = false;
            
            protected function handle() {
                $this->handled = true;
            }
        };
        
        // Dispatch the request
        $request->dispatch();
        
        // Verify the HTTP request was made
        $this->assertCount(1, $this->http_requests, 'One HTTP request should be made');
        
        if (!empty($this->http_requests)) {
            $http_request = $this->http_requests[0];
            
            // Verify the URL contains the expected action
            $this->assertStringContainsString('test_test_action', $http_request['url'], 'URL should contain the action');
            
            // Verify request args - note that we're checking for existence, not exact values
            $this->assertIsArray($http_request['args'], 'Request args should be an array');
            $this->assertArrayHasKey('timeout', $http_request['args'], 'Request args should contain timeout');
        }
    }
    
    /**
     * Test that the request is actually executed with custom properties.
     */
    public function test_request_execution_with_custom_properties() {
        // Create a testable async request class with custom properties
        $request = new class extends AsyncRequest {
            protected $prefix = 'test';
            protected $action = 'test_action';
            public $handled = false;
            
            // Define custom properties with the same visibility as parent
            protected $query_args = ['custom' => 'query_arg'];
            protected $query_url = 'https://example.com/custom-endpoint';
            protected $request_args = [
                'timeout' => 0.5,
                'blocking' => true,
                'body' => ['test' => 'data'],
                'cookies' => [],
                'sslverify' => false,
            ];
            
            protected function handle() {
                $this->handled = true;
            }
        };
        
        // Dispatch the request
        $request->dispatch();
        
        // Verify the HTTP request was made
        $this->assertCount(1, $this->http_requests, 'One HTTP request should be made');
        
        if (!empty($this->http_requests)) {
            $http_request = $this->http_requests[0];
            
            // Verify custom URL was used (base URL without checking query params)
            $this->assertStringStartsWith('https://example.com/custom-endpoint', $http_request['url'], 'Custom URL should be used');
            
            // Verify query args are appended
            $this->assertStringContainsString('custom=query_arg', $http_request['url'], 'Query args should be appended to URL');
            
            // Verify custom request args - check for existence and key values
            $this->assertArrayHasKey('timeout', $http_request['args'], 'Request args should contain timeout');
            $this->assertEquals(0.5, $http_request['args']['timeout'], 'Custom timeout should be used');
            $this->assertArrayHasKey('body', $http_request['args'], 'Request args should contain body');
            $this->assertEquals(['test' => 'data'], $http_request['args']['body'], 'Custom body should be used');
        }
    }
    
    /**
     * Tests that the nonce is created for a logged-out user when there is no logged-in cookie to forward, even if
     * another plugin left a different user set as the current user, as can happen during WP-Cron.
     */
    public function test_nonce_is_created_for_logged_out_user_when_no_cookie_is_forwarded() {
        $this->assert_nonce_is_created_for_logged_out_user( null );
    }

    /**
     * Tests that the nonce is created for a logged-out user when the forwarded logged-in cookie is malformed.
     */
    public function test_nonce_is_created_for_logged_out_user_when_cookie_is_malformed() {
        $this->assert_nonce_is_created_for_logged_out_user( 'not-a-valid-cookie' );
    }

    /**
     * Tests that the nonce is created for a logged-out user when the forwarded logged-in cookie has expired.
     */
    public function test_nonce_is_created_for_logged_out_user_when_cookie_is_expired() {
        $user_id = self::factory()->user->create();

        // expired beyond the one-hour grace period that Ajax and POST requests get
        $this->assert_nonce_is_created_for_logged_out_user( wp_generate_auth_cookie( $user_id, time() - 2 * HOUR_IN_SECONDS, 'logged_in' ) );
    }

    /**
     * Asserts that, with the given logged-in cookie (or none) and a different user set as the current user, the nonce
     * verifies for a logged-out user and the current user is restored afterwards.
     *
     * @param string|null $cookie logged-in cookie to set, or null for none
     */
    private function assert_nonce_is_created_for_logged_out_user( ?string $cookie ) {
        $request = $this->get_request();
        $user_id = self::factory()->user->create();

        if ( null === $cookie ) {
            unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        } else {
            $_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
        }

        wp_set_current_user( $user_id );

        $nonce = $this->invokeMethod( $request, 'get_query_args' )['nonce'];

        $this->assertSame( $user_id, get_current_user_id(), 'The current user should be restored after creating the nonce' );

        // the async request is handled as a logged-out user when the forwarded cookies authenticate nobody
        wp_set_current_user( 0 );

        $this->assertNotFalse( wp_verify_nonce( $nonce, 'test_test_action' ), 'The nonce should verify for a logged-out user' );
    }

    /**
     * Tests that the nonce is created for the user authenticated by the forwarded logged-in cookie.
     */
    public function test_nonce_is_created_for_cookie_user_when_cookie_is_forwarded() {
        $request = $this->get_request();
        $user_id = self::factory()->user->create();

        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + DAY_IN_SECONDS, 'logged_in' );
        wp_set_current_user( $user_id );

        $nonce = $this->invokeMethod( $request, 'get_query_args' )['nonce'];

        $this->assertNotFalse( wp_verify_nonce( $nonce, 'test_test_action' ), 'The nonce should verify for the cookie user' );

        wp_set_current_user( 0 );

        $this->assertFalse( wp_verify_nonce( $nonce, 'test_test_action' ), 'The nonce should not verify for a logged-out user' );
    }

    /**
     * Tests that the nonce is created for the cookie user even when the current user was switched to someone else.
     */
    public function test_nonce_is_created_for_cookie_user_when_current_user_was_switched() {
        $request       = $this->get_request();
        $cookie_user   = self::factory()->user->create();
        $switched_user = self::factory()->user->create();

        $_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $cookie_user, time() + DAY_IN_SECONDS, 'logged_in' );
        wp_set_current_user( $switched_user );

        $nonce = $this->invokeMethod( $request, 'get_query_args' )['nonce'];

        $this->assertSame( $switched_user, get_current_user_id(), 'The current user should be restored after creating the nonce' );

        wp_set_current_user( $cookie_user );

        $this->assertNotFalse( wp_verify_nonce( $nonce, 'test_test_action' ), 'The nonce should verify for the cookie user' );
    }

    /**
     * Tests that the nonce is the one WordPress would create when the current user already is the request user.
     */
    public function test_nonce_is_unchanged_when_current_user_is_the_request_user() {
        $request = $this->get_request();

        unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
        wp_set_current_user( 0 );

        $this->assertSame( wp_create_nonce( 'test_test_action' ), $this->invokeMethod( $request, 'get_query_args' )['nonce'] );
    }

    /**
     * Gets a minimal async request for the tests above.
     */
    private function get_request(): AsyncRequest {
        return new class extends AsyncRequest {
            protected $prefix = 'test';
            protected $action = 'test_action';

            protected function handle() {
                // No-op for testing
            }
        };
    }

    /**
     * Helper method to invoke protected/private methods
     */
    private function invokeMethod($object, $methodName, array $parameters = []) {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        
        return $method->invokeArgs($object, $parameters);
    }
}