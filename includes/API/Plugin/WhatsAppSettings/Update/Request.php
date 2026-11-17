<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\API\Plugin\WhatsAppSettings\Update;

use WooCommerce\Facebook\API\Plugin\Request as RESTRequest;
use WooCommerce\Facebook\API\Plugin\Traits\JS_Exposable;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp Settings Update REST API Request.
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
		return 'whatsapp_settings/update';
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
			'access_token'       => [
				'type'     => 'string',
				'required' => true,
			],
			'business_id'        => [
				'type'     => 'string',
				'required' => false,
			],
			'phone_number_id'    => [
				'type'     => 'string',
				'required' => false,
			],
			'waba_id'            => [
				'type'     => 'string',
				'required' => false,
			],
			'wa_installation_id' => [
				'type'     => 'string',
				'required' => false,
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
		return 'updateWhatsAppSettings';
	}

	/**
	 * Validate the request.
	 *
	 * @since 3.5.0
	 *
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate() {

		if ( empty( $this->get_param( 'access_token' ) ) ) {
			wc_get_logger()->warning(
				sprintf(
					__( 'Failed to updateWhatsAppSettings since access_token param is missing.', 'facebook-for-woocommerce' ),
				)
			);
			return new \WP_Error(
				'missing_access_token',
				__( 'Missing access token', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $this->get_param( 'business_id' ) ) ) {
			wc_get_logger()->warning(
				sprintf(
					__( 'Failed to updateWhatsAppSettings since business_id param is missing.', 'facebook-for-woocommerce' ),
				)
			);
			return new \WP_Error(
				'missing_business_id',
				__( 'Missing business id', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $this->get_param( 'phone_number_id' ) ) ) {
			wc_get_logger()->warning(
				sprintf(
					__( 'Failed to updateWhatsAppSettings since phone_number_id param is missing.', 'facebook-for-woocommerce' ),
				)
			);
			return new \WP_Error(
				'missing_phone_number_id',
				__( 'Missing phone number id', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $this->get_param( 'waba_id' ) ) ) {
			wc_get_logger()->warning(
				sprintf(
					__( 'Failed to updateWhatsAppSettings since waba_id param is missing.', 'facebook-for-woocommerce' ),
				)
			);
			return new \WP_Error(
				'missing_waba_id',
				__( 'Missing waba id', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		if ( empty( $this->get_param( 'wa_installation_id' ) ) ) {
			wc_get_logger()->warning(
				sprintf(
					__( 'Failed to updateWhatsAppSettings since wa_installation_id param is missing.', 'facebook-for-woocommerce' ),
				)
			);
			return new \WP_Error(
				'missing_wa_installation_id',
				__( 'Missing wa installation id', 'facebook-for-woocommerce' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
