<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\API\Plugin\WhatsAppSettings\UpdateIntegrationConfig;

use WooCommerce\Facebook\API\Plugin\Request as RESTRequest;
use WooCommerce\Facebook\API\Plugin\Traits\JS_Exposable;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp Settings Update Integration Config REST API Request.
 *
 * @since 3.5.0
 */
class Request extends RESTRequest {

	use JS_Exposable;

	/**
	 * Gets the API endpoint for this request.
	 *
	 * @since 3.5.0
	 *
	 * @return string
	 */
	public function get_endpoint() {
		return 'whatsapp_settings/update/integration_config';
	}

	/**
	 * Gets the HTTP method for this request.
	 *
	 * @since 3.5.0
	 *
	 * @return string
	 */
	public function get_method() {
		return 'POST';
	}

	/**
	 * Gets the parameter schema for this request.
	 *
	 * @since 3.5.0
	 *
	 * @return array Array of parameters with their types and whether they're required
	 */
	public function get_param_schema() {
		return [
			'integration_config_id' => [
				'type'     => 'string',
				'required' => true,
			],
		];
	}

	/**
	 * Gets the JavaScript function name for this request.
	 *
	 * @since 3.5.0
	 *
	 * @return string
	 */
	public function get_js_function_name() {
		return 'updateWhatsAppSettingsIntegrationConfig';
	}

	/**
	 * Validate the request.
	 *
	 * @since 3.5.0
	 *
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate() {

		if ( empty( $this->get_param( 'integration_config_id' ) ) ) {
			wc_get_logger()->warning(
				sprintf(
					__( 'Failed to updateWhatsAppSettingsIntegrationConfig since integration_config_id param is missing.', 'facebook-for-woocommerce' ),
				)
			);
			return new \WP_Error(
				'missing_integration_config_id',
				__( 'Missing integration config id', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
