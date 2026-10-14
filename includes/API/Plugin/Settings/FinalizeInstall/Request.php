<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\API\Plugin\Settings\FinalizeInstall;

use WooCommerce\Facebook\API\Plugin\Request as RESTRequest;
use WooCommerce\Facebook\API\Plugin\Traits\JS_Exposable;

defined( 'ABSPATH' ) || exit;

/**
 * Settings Finalize Install REST API Request.
 *
 * Completes a new installation: the freshly issued access token is deposited and Meta is told
 * about it once, which returns the connected assets. Routine settings changes must use
 * Settings\Update\Request instead — finalizing again would re-bind the Commerce Partner
 * Integration.
 *
 * @since 3.7.7
 */
class Request extends RESTRequest {

	use JS_Exposable;

	/**
	 * Gets the API endpoint for this request.
	 *
	 * @since 3.7.7
	 *
	 * @return string
	 */
	public function get_endpoint() {
		return 'settings/finalize-install';
	}

	/**
	 * Gets the HTTP method for this request.
	 *
	 * @since 3.7.7
	 *
	 * @return string
	 */
	public function get_method() {
		return 'POST';
	}

	/**
	 * Gets the parameter schema for this request.
	 *
	 * @since 3.7.7
	 *
	 * @return array Array of parameters with their types and whether they're required
	 */
	public function get_param_schema() {
		return [
			'access_token'                    => [
				'type'     => 'string',
				'required' => true,
			],
			'product_catalog_id'              => [
				'type'     => 'string',
				'required' => false,
			],
			'pixel_id'                        => [
				'type'     => 'string',
				'required' => false,
			],
			'commerce_merchant_settings_id'   => [
				'type'     => 'string',
				'required' => false,
			],
			'commerce_partner_integration_id' => [
				'type'     => 'string',
				'required' => false,
			],
			'installed_features'              => [
				'type'     => 'array',
				'required' => false,
			],
		];
	}

	/**
	 * Gets the JavaScript function name for this request.
	 *
	 * @since 3.7.7
	 *
	 * @return string
	 */
	public function get_js_function_name() {
		return 'finalizeInstall';
	}

	/**
	 * Validate the request.
	 *
	 * @since 3.7.7
	 *
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate() {
		if ( empty( $this->get_param( 'access_token' ) ) ) {
			return new \WP_Error(
				'missing_access_token',
				__( 'Missing access token', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
