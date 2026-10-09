<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Meta for WooCommerce.
 */

namespace WooCommerce\Facebook\Framework\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * SkyVerge WordPress Async Request class
 *
 * Based on the incredible work by deliciousbrains - most of the code is from
 * here: https://github.com/A5hleyRich/wp-background-processing
 *
 * Forked & namespaced to prevent dependency conflicts and to facilitate
 * further customizations.
 *
 * Use SV_WP_Async_Request::set_data() to set request data, instead of ::data().
 *
 * @since 4.4.0
 */
abstract class AsyncRequest {


	/** @var string request prefix */
	protected $prefix = 'wp';

	/** @var string request action name */
	protected $action = 'async_request';

	/** @var string request identifier */
	protected $identifier;

	/** @var array request data */
	protected $data = [];

	/**
	 * Initiate a new async request
	 *
	 * @since 4.4.0
	 */
	public function __construct() {
		$this->identifier = $this->prefix . '_' . $this->action;

		add_action( 'wp_ajax_' . $this->identifier, array( $this, 'maybe_handle' ) );
		add_action( 'wp_ajax_nopriv_' . $this->identifier, array( $this, 'maybe_handle' ) );
	}


	/**
	 * Set data used during the async request
	 *
	 * @since 4.4.0
	 * @param array $data
	 * @return AsyncRequest
	 */
	public function set_data( $data ) {
		$this->data = $data;

		return $this;
	}


	/**
	 * Dispatch the async request
	 *
	 * @since 4.4.0
	 * @return array|\WP_Error
	 */
	public function dispatch() {

		$url  = add_query_arg( $this->get_query_args(), $this->get_query_url() );
		$args = $this->get_request_args();

		return wp_safe_remote_get( esc_url( $url, null, 'db' ), $args );
	}


	/**
	 * Get query args
	 *
	 * @since 4.4.0
	 * @return array
	 */
	protected function get_query_args() {

		// Check if a child class has defined this property for custom query args
		if ( property_exists( $this, 'query_args' ) ) {
			// Dynamic property; not defined in the parent class, and not a true lint error
			// phpcs:ignore 
			return $this->query_args;
		}

		return array(
			'action' => $this->identifier,
			'nonce'  => $this->create_nonce(),
		);
	}


	/**
	 * Creates the nonce that authenticates the async request.
	 *
	 * The cookies of the current request are forwarded by get_request_args(), so the request is handled as the user
	 * those cookies authenticate, or as a logged-out user (ID 0) when they authenticate nobody, as is the case during
	 * WP-Cron and WP-CLI. WordPress nonces are bound to a user ID, so the nonce has to be created for that user rather
	 * than for whichever user is current when dispatching: another plugin may have switched the current user during
	 * the same cron run, and a nonce created for that user would make every dispatched request fail its nonce check.
	 *
	 * The current user is switched only when it differs from that user, and only while wp_create_nonce() runs.
	 *
	 * @return string
	 */
	protected function create_nonce() {
		$current_user_id = get_current_user_id();
		$request_user_id = $this->get_request_user_id();

		if ( $request_user_id === $current_user_id ) {
			return wp_create_nonce( $this->identifier );
		}

		try {
			wp_set_current_user( $request_user_id );

			return wp_create_nonce( $this->identifier );
		} finally {
			wp_set_current_user( $current_user_id );
		}
	}


	/**
	 * Gets the ID of the user the async request is expected to be handled as.
	 *
	 * That is the user authenticated by the logged-in cookie of the current request, or 0 when there is no valid one.
	 * This matches the handling side only while get_request_args() forwards the current request's cookies, since
	 * wp_create_nonce() also reads the nonce's session token from them: a subclass that forwards other cookies has
	 * to override create_nonce() as well.
	 *
	 * admin-ajax.php still accepts an expired cookie for an hour, which a GET request here does not, so a request
	 * dispatched from a frontend page in that hour fails its nonce check once and is dispatched again, without
	 * cookies, by the cron healthcheck.
	 *
	 * @return int
	 */
	protected function get_request_user_id() {
		$user_id = wp_validate_auth_cookie( '', 'logged_in' );

		return $user_id ? (int) $user_id : 0;
	}


	/**
	 * Get query URL
	 *
	 * @since 4.4.0
	 * @return string
	 */
	protected function get_query_url() {

		// Check if a child class has defined this property for a custom URL
		if ( property_exists( $this, 'query_url' ) ) {
			// Dynamic property; not defined in the parent class, and not a true lint error
			// phpcs:ignore 
			return $this->query_url;
		}

		return admin_url( 'admin-ajax.php' );
	}


	/**
	 * Get request args
	 *
	 * In 4.6.3 renamed from get_post_args to get_request_args
	 *
	 * @since 4.4.0
	 * @return array
	 */
	protected function get_request_args() {

		// Check if a child class has defined this property for custom request args
		if ( property_exists( $this, 'request_args' ) ) {
			// Dynamic property; not defined in the parent class, and not a true lint error
			// phpcs:ignore 
			return $this->request_args;
		}

		return array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'body'      => $this->data,
			'cookies'   => $_COOKIE,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		);
	}


	/**
	 * Maybe handle
	 *
	 * Check for correct nonce and pass to handler.
	 *
	 * @since 4.4.0
	 */
	public function maybe_handle() {
		check_ajax_referer( $this->identifier, 'nonce' );

		$this->handle();

		wp_die();
	}


	/**
	 * Handle
	 *
	 * Override this method to perform any actions required
	 * during the async request.
	 *
	 * @since 4.4.0
	 */
	abstract protected function handle();
}
