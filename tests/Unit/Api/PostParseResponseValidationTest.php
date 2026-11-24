<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Api;

use WooCommerce\Facebook\API;
use WooCommerce\Facebook\API\Request;
use WooCommerce\Facebook\API\Response;
use WooCommerce\Facebook\API\Exceptions\Request_Limit_Reached;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

defined( 'ABSPATH' ) || exit;

/**
 * A testable API subclass that exposes the protected do_post_parse_response_validation() method.
 */
class TestableAPI extends API {

	public function __construct() {
		// Skip parent constructor to avoid requiring access token / connection handler.
	}

	/**
	 * Expose the protected response property for test setup.
	 *
	 * @param Response $response
	 */
	public function set_test_response( Response $response ): void {
		$this->response = $response;
	}

	/**
	 * Expose the protected request property for test setup.
	 *
	 * @param Request $request
	 */
	public function set_test_request( Request $request ): void {
		$this->request = $request;
	}

	/**
	 * Public wrapper to call the protected do_post_parse_response_validation().
	 *
	 * @throws Request_Limit_Reached
	 */
	public function call_do_post_parse_response_validation(): void {
		$this->do_post_parse_response_validation();
	}
}

/**
 * Tests for API::do_post_parse_response_validation().
 *
 * Verifies that:
 * - Token errors (code 190) set the wc_facebook_connection_invalid transient
 * - Known auth subcodes (452, 458, 460, 464, 465) are detected
 * - Successful responses clear the transient
 * - Non-auth errors clear the transient
 * - Rate limit errors throw Request_Limit_Reached
 * - Token errors do NOT throw (callers handle via response object)
 */
class PostParseResponseValidationTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Helper to create a Response with a Graph API error.
	 *
	 * @param int         $code     Error code.
	 * @param int|null    $subcode  Error subcode.
	 * @param string      $message  Error message.
	 * @param string      $type     Error type.
	 * @return Response
	 */
	private function make_error_response( int $code, ?int $subcode = null, string $message = 'Test error', string $type = 'OAuthException' ): Response {
		$error = [
			'error' => [
				'message' => $message,
				'type'    => $type,
				'code'    => $code,
			],
		];
		if ( null !== $subcode ) {
			$error['error']['error_subcode'] = $subcode;
		}
		return new Response( wp_json_encode( $error ) );
	}

	/**
	 * Helper to create a successful (no error) Response.
	 *
	 * @return Response
	 */
	private function make_success_response(): Response {
		return new Response( wp_json_encode( [ 'id' => '12345', 'success' => true ] ) );
	}

	/**
	 * Helper to set up a TestableAPI with a given response.
	 *
	 * @param Response $response
	 * @return TestableAPI
	 */
	private function make_api_with_response( Response $response ): TestableAPI {
		$api     = new TestableAPI();
		$request = new Request( '/test', 'GET' );
		$api->set_test_request( $request );
		$api->set_test_response( $response );
		return $api;
	}

	/**
	 * Test that error code 190 with subcode 464 sets the connection invalid transient.
	 */
	public function test_token_error_464_sets_connection_invalid_transient(): void {
		$api = $this->make_api_with_response(
			$this->make_error_response( 190, 464, 'User is not a confirmed user' )
		);

		$api->call_do_post_parse_response_validation();

		$this->assertNotFalse( get_transient( 'wc_facebook_connection_invalid' ) );
	}

	/**
	 * Test that error code 190 with subcode 460 sets the connection invalid transient.
	 */
	public function test_token_error_460_sets_connection_invalid_transient(): void {
		$api = $this->make_api_with_response(
			$this->make_error_response( 190, 460, 'Password has been changed' )
		);

		$api->call_do_post_parse_response_validation();

		$this->assertNotFalse( get_transient( 'wc_facebook_connection_invalid' ) );
	}

	/**
	 * Test that error code 190 with subcode 452 sets the connection invalid transient.
	 * Session mismatch (452) leaves the stored token unusable, so we flag it and
	 * block CAPI until the merchant reconnects.
	 */
	public function test_token_error_452_sets_connection_invalid_transient(): void {
		$api = $this->make_api_with_response(
			$this->make_error_response( 190, 452, 'Session does not match' )
		);

		$api->call_do_post_parse_response_validation();

		$this->assertNotFalse( get_transient( 'wc_facebook_connection_invalid' ) );
	}

	/**
	 * Test that a token error (code 190) does NOT throw an ApiException
	 * but still sets the connection invalid transient.
	 */
	public function test_token_error_does_not_throw_api_exception(): void {
		$api = $this->make_api_with_response(
			$this->make_error_response( 190, 464, 'User is not a confirmed user' )
		);

		// Should NOT throw — returns normally so callers can handle the response.
		$api->call_do_post_parse_response_validation();

		$this->assertNotFalse( get_transient( 'wc_facebook_connection_invalid' ) );
	}

	/**
	 * Test that a successful response clears the connection invalid transient.
	 */
	public function test_successful_response_clears_connection_invalid_transient(): void {
		// Pre-set the transient as if a previous call failed.
		set_transient( 'wc_facebook_connection_invalid', time(), DAY_IN_SECONDS );
		$this->assertNotFalse( get_transient( 'wc_facebook_connection_invalid' ) );

		$api = $this->make_api_with_response( $this->make_success_response() );
		$api->call_do_post_parse_response_validation();

		$this->assertFalse( get_transient( 'wc_facebook_connection_invalid' ) );
	}

	/**
	 * Test that a non-auth error (code 100) clears the connection invalid transient.
	 */
	public function test_non_auth_error_clears_connection_invalid_transient(): void {
		// Pre-set the transient.
		set_transient( 'wc_facebook_connection_invalid', time(), DAY_IN_SECONDS );

		$api = $this->make_api_with_response(
			$this->make_error_response( 100, 2804019, 'Invalid parameter' )
		);

		$api->call_do_post_parse_response_validation();

		$this->assertFalse( get_transient( 'wc_facebook_connection_invalid' ) );
	}

	/**
	 * Test that a rate limit error (code 4) throws Request_Limit_Reached.
	 */
	public function test_rate_limit_error_throws_request_limit_reached(): void {
		$api = $this->make_api_with_response(
			$this->make_error_response( 4, null, 'Too many calls', 'OAuthException' )
		);

		$this->expectException( Request_Limit_Reached::class );

		$api->call_do_post_parse_response_validation();
	}

	/**
	 * Test that the Catalog account rate limit (code 80009) throws Request_Limit_Reached.
	 */
	public function test_catalog_rate_limit_error_throws_request_limit_reached(): void {
		$api = $this->make_api_with_response(
			$this->make_error_response( 80009, 2446079, 'There have been too many calls to this Catalog account. Wait a bit and try again.', 'OAuthException' )
		);

		$this->expectException( Request_Limit_Reached::class );

		$api->call_do_post_parse_response_validation();
	}
}
