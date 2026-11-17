<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\API\Plugin\WhatsAppSettings;

use WooCommerce\Facebook\API\Plugin\AbstractRESTEndpoint;
use WooCommerce\Facebook\API\Plugin\WhatsAppSettings\Update\Request as UpdateRequest;
use WooCommerce\Facebook\API\Plugin\WhatsAppSettings\UpdateIntegrationConfig\Request as UpdateIntegrationConfigRequest;
use WooCommerce\Facebook\API\Plugin\WhatsAppSettings\Uninstall\Request as UninstallRequest;
use WooCommerce\Facebook\API\Plugin\WhatsAppSettings\OnboardingComplete\Request as OnboardingCompleteRequest;
use WooCommerce\Facebook\Handlers\WhatsAppConnection;

defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp Settings REST API endpoint handler.
 *
 * @since 3.5.0
 */
class Handler extends AbstractRESTEndpoint {

	/**
	 * Register routes for this endpoint.
	 *
	 * @since 3.5.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->get_namespace(),
			'whatsapp_settings/update',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_update' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);

		register_rest_route(
			$this->get_namespace(),
			'whatsapp_settings/update/integration_config',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_update_integration_config' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);

		register_rest_route(
			$this->get_namespace(),
			'whatsapp_settings/uninstall',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_uninstall' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);

		register_rest_route(
			$this->get_namespace(),
			'whatsapp_settings/onboarding_complete',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_onboarding_complete' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
	}

	/**
	 * Handle the onboarding-complete push signal (WA_CONNECT).
	 *
	 * Persists that WhatsApp onboarding finished so the customer_events gate
	 * stops treating this install as pre-onboarding. Reaching this endpoint is
	 * itself the signal, so it carries no payload.
	 *
	 * @since 3.7.5
	 * @http_method POST
	 * @description Mark WhatsApp onboarding as complete
	 *
	 * @param \WP_REST_Request $wp_request The WordPress request object.
	 * @return \WP_REST_Response
	 */
	public function handle_onboarding_complete( \WP_REST_Request $wp_request ): \WP_REST_Response {
		try {
			$request           = new OnboardingCompleteRequest( $wp_request );
			$validation_result = $request->validate();

			if ( is_wp_error( $validation_result ) ) {
				return $this->error_response(
					$validation_result->get_error_message(),
					400
				);
			}

			facebook_for_woocommerce()->get_whatsapp_connection_handler()->set_onboarding_complete( true );

			wc_get_logger()->info(
				__( 'WhatsApp onboarding marked complete via WA_CONNECT push signal.', 'facebook-for-woocommerce' ),
			);

			return $this->success_response(
				[
					'message' => __( 'WhatsApp onboarding marked complete', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error(
				sprintf(
					/* translators: %s $error_message */
					__( 'Failed to handle_onboarding_complete for WhatsApp Utility Messages Integration. Exception: %s', 'facebook-for-woocommerce' ),
					$e->getMessage(),
				)
			);
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Handle the update settings request.
	 *
	 * @since 3.5.0
	 * @http_method POST
	 * @description Update plugin settings
	 *
	 * @param \WP_REST_Request $wp_request The WordPress request object.
	 * @return \WP_REST_Response
	 */
	public function handle_update( \WP_REST_Request $wp_request ): \WP_REST_Response {
		try {
			$request           = new UpdateRequest( $wp_request );
			$request_data      = $request->get_data();
			$validation_result = $request->validate();

			if ( is_wp_error( $validation_result ) ) {
				return $this->error_response(
					$validation_result->get_error_message(),
					400
				);
			}

			// Map parameters to options and update settings
			$options = $this->map_params_to_options( $request_data );
			$this->update_settings( $options );
			wc_get_logger()->info(
				sprintf(
					__( 'Update Settings for WhatsApp Utility Messages Integration Successful.', 'facebook-for-woocommerce' ),
				)
			);

			return $this->success_response(
				[
					'message' => __( 'WhatsApp settings updated successfully', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error(
				sprintf(
					/* translators: %s $error_message */
					__( 'Failed to handle_update for WhatsApp Utility Messages Integration. Exception: %1$s', 'facebook-for-woocommerce' ),
					$e->getMessage(),
				)
			);
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Handle the update integration config settings request.
	 *
	 * @since 3.5.10
	 * @http_method POST
	 * @description Update integration config in plugin settings
	 *
	 * @param \WP_REST_Request $wp_request The WordPress request object.
	 * @return \WP_REST_Response
	 */
	public function handle_update_integration_config( \WP_REST_Request $wp_request ): \WP_REST_Response {
		try {
			$request           = new UpdateIntegrationConfigRequest( $wp_request );
			$request_data      = $request->get_data();
			$validation_result = $request->validate();

			if ( is_wp_error( $validation_result ) ) {
				return $this->error_response(
					$validation_result->get_error_message(),
					400
				);
			}

			// Map parameters to options and update settings
			$options = $this->map_params_to_options( $request_data );
			$this->update_settings( $options );
			wc_get_logger()->info(
				__( 'Update Integration Config in Settings for WhatsApp Utility Messages Integration Successful.', 'facebook-for-woocommerce' ),
			);

			return $this->success_response(
				[
					'message' => __( 'Integration Config in WhatsApp settings updated successfully', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error(
				sprintf(
					/* translators: %s $error_message */
					__( 'Failed to handle_update_integration_config for WhatsApp Utility Messages Integration. Exception: %s', 'facebook-for-woocommerce' ),
					$e->getMessage(),
				)
			);
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Maps request parameters to WooCommerce settings options.
	 *
	 * @since 3.5.0
	 *
	 * @param array $params Request parameters.
	 * @return array Mapped options array
	 */
	private function map_params_to_options( array $params ): array {
		$options = [];
		// Map options name to option value
		if ( ! empty( $params['access_token'] ) ) {
			$options[ WhatsAppConnection::OPTION_WA_UTILITY_ACCESS_TOKEN ] = $params['access_token'];
		}

		if ( ! empty( $params['wa_installation_id'] ) ) {
			$options[ WhatsAppConnection::OPTION_WA_INSTALLATION_ID ] = $params['wa_installation_id'];
		}

		if ( ! empty( $params['business_id'] ) ) {
			$options[ WhatsAppConnection::OPTION_WA_BUSINESS_ID ] = $params['business_id'];
		}

		if ( ! empty( $params['waba_id'] ) ) {
			$options[ WhatsAppConnection::OPTION_WA_WABA_ID ] = $params['waba_id'];
		}

		if ( ! empty( $params['phone_number_id'] ) ) {
			$options[ WhatsAppConnection::OPTION_WA_PHONE_NUMBER_ID ] = $params['phone_number_id'];
		}

		if ( ! empty( $params['integration_config_id'] ) ) {
			$options[ WhatsAppConnection::OPTION_WA_INTEGRATION_CONFIG_ID ] = $params['integration_config_id'];
		}

		return $options;
	}

	/**
	 * Updates plugin settings options.
	 *
	 * @since 3.5.0
	 *
	 * @param array $settings Array of settings to update.
	 * @return void
	 */
	private function update_settings( array $settings ) {
		foreach ( $settings as $key => $value ) {
			if ( ! empty( $key ) ) {
				update_option( $key, $value );
			}
		}
	}

	/**
	 * Clears all integration options.
	 *
	 * @since 3.5.0
	 *
	 * @return void
	 */
	private function clear_integration_options() {
		$options = [
			WhatsAppConnection::OPTION_WA_UTILITY_ACCESS_TOKEN,
			WhatsAppConnection::OPTION_WA_INSTALLATION_ID,
			WhatsAppConnection::OPTION_WA_BUSINESS_ID,
			WhatsAppConnection::OPTION_WA_WABA_ID,
			WhatsAppConnection::OPTION_WA_PHONE_NUMBER_ID,
			WhatsAppConnection::OPTION_WA_INTEGRATION_CONFIG_ID,
			WhatsAppConnection::OPTION_WA_ONBOARDING_COMPLETE,
		];

		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}


	/**
	 * Handle the whatsapp integration uninstall request.
	 *
	 * @since 3.5.0
	 * @http_method POST
	 * @description Uninstall WhatsApp integration
	 *
	 * @param \WP_REST_Request $wp_request The WordPress request object.
	 * @return \WP_REST_Response
	 */
	public function handle_uninstall( \WP_REST_Request $wp_request ): \WP_REST_Response {
		try {
			$request           = new UninstallRequest( $wp_request );
			$validation_result = $request->validate();

			if ( is_wp_error( $validation_result ) ) {
				wc_get_logger()->error(
					sprintf(
						/* translators: %s $error_message */
						__( 'Failed to handle_uninstall for WhatsApp Utility Messages Integration. Exception: %1$s', 'facebook-for-woocommerce' ),
						$validation_result->get_error_message(),
					)
				);
				return $this->error_response(
					$validation_result->get_error_message(),
					400
				);
			}

			// Clear integration options
			$this->clear_integration_options();

			wc_get_logger()->info(
				sprintf(
					__( 'Uninstall successful for WhatsApp Utility Messages Integration.', 'facebook-for-woocommerce' ),
				)
			);

			return $this->success_response(
				[
					'message' => __( 'WhatsApp integration successfully uninstalled', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			wc_get_logger()->error(
				sprintf(
					/* translators: %s $error_message */
					__( 'Failed to handle_uninstall for WhatsApp Utility Messages Integration. Exception: %1$s', 'facebook-for-woocommerce' ),
					$e->getMessage(),
				)
			);
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}
}
