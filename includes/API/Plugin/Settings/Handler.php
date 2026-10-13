<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\API\Plugin\Settings;

use WooCommerce\Facebook\API\CommerceIntegration\Client as FinalizeClient;
use WooCommerce\Facebook\API\CommerceIntegration\Exception as FinalizeException;
use WooCommerce\Facebook\API\Plugin\AbstractRESTEndpoint;
use WooCommerce\Facebook\API\Plugin\Settings\FinalizeInstall\Request as FinalizeInstallRequest;
use WooCommerce\Facebook\API\Plugin\Settings\Update\Request as UpdateRequest;
use WooCommerce\Facebook\API\Plugin\Settings\Uninstall\Request as UninstallRequest;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Framework\Plugin\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Settings REST API endpoint handler.
 *
 * @since 3.5.0
 */
class Handler extends AbstractRESTEndpoint {

	/** @var FinalizeClient Commerce Partner Integration finalize-install client. */
	private $finalize_client;

	/**
	 * Constructor.
	 *
	 * @param FinalizeClient|null $finalize_client Optional finalize-install client.
	 */
	public function __construct( ?FinalizeClient $finalize_client = null ) {
		$this->finalize_client = $finalize_client ?? new FinalizeClient();
	}

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
			'/settings/update',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_update' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);

		register_rest_route(
			$this->get_namespace(),
			'/settings/finalize-install',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_finalize_install' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);

		register_rest_route(
			$this->get_namespace(),
			'/settings/uninstall',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle_uninstall' ],
				'permission_callback' => [ $this, 'permission_callback' ],
			]
		);
	}

	/**
	 * Handle the update settings request.
	 *
	 * Routine settings changes only. Completing a new installation goes through
	 * handle_finalize_install(), which is the one place that calls finalize-install.
	 *
	 * @since 3.5.0
	 * @http_method POST
	 * @description Update Facebook settings
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

			$this->apply_installation_settings( $request_data );

			return $this->success_response(
				[
					'message' => __( 'Facebook settings updated successfully', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Handle the finalize install request.
	 *
	 * Called once, when the Commerce Extension reports a completed install. The freshly issued
	 * access token is deposited first because finalize-install authenticates with it, then Meta
	 * is told about the install once and returns the connected assets. Routine settings changes
	 * must use handle_update() — finalizing again would re-bind the Commerce Partner Integration.
	 *
	 * @since 3.7.7
	 * @http_method POST
	 * @description Finalize a new Facebook installation
	 *
	 * @param \WP_REST_Request $wp_request The WordPress request object.
	 * @return \WP_REST_Response
	 */
	public function handle_finalize_install( \WP_REST_Request $wp_request ): \WP_REST_Response {
		try {
			$request           = new FinalizeInstallRequest( $wp_request );
			$request_data      = $request->get_data();
			$validation_result = $request->validate();

			if ( is_wp_error( $validation_result ) ) {
				return $this->error_response(
					$validation_result->get_error_message(),
					400
				);
			}

			// finalize-install authenticates with the token, so deposit it first and confirm it
			// reads back before telling Meta it is there.
			if ( ! $this->persist_access_token( $request_data ) ) {
				$this->clear_access_token();
				return $this->error_response(
					__( 'Unable to save the Facebook access token. Please try again.', 'facebook-for-woocommerce' ),
					500
				);
			}

			$resolved_assets = $this->resolve_installation_assets( $request_data );
			if ( is_wp_error( $resolved_assets ) ) {
				// Meta rejected the token, so drop it and leave the store disconnected. Keeping it
				// would make is_connected() true on a dead token; the merchant re-onboards instead.
				$this->clear_access_token();
				$error_data = $resolved_assets->get_error_data();
				return $this->error_response(
					$resolved_assets->get_error_message(),
					is_array( $error_data ) ? (int) ( $error_data['status'] ?? 500 ) : 500
				);
			}

			// The token was already persisted above, so keep it out of the settings mapping.
			$settings_data = array_replace( $request_data, $resolved_assets );
			unset( $settings_data['access_token'] );

			$this->apply_installation_settings( $settings_data, ! empty( $resolved_assets['pixel_id'] ) );

			// No resolved assets means finalize-install failed and the legacy install message was used.
			if ( ! empty( $resolved_assets ) ) {
				$this->set_up_checkout( (string) ( $settings_data['commerce_merchant_settings_id'] ?? '' ), $resolved_assets['country_code'] ?? '' );
			}

			return $this->success_response(
				[
					'message' => __( 'Facebook settings updated successfully', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Maps request parameters to options, stores them and triggers any follow-up syncs.
	 *
	 * @since 3.7.7
	 *
	 * @param array $request_data Request parameters, with finalized assets merged in when present.
	 *                           Callers that have already stored the access token themselves
	 *                           should unset it first so it is not mapped twice.
	 * @param bool  $has_finalized_pixel Whether a finalized top-level pixel should win over installed features.
	 * @return void
	 */
	private function apply_installation_settings( array $request_data, bool $has_finalized_pixel = false ) {
		// Check if we should trigger product sync and/or metadata feed uploads for this update
		// Only trigger products and sets sync if catalog id is being updated
		$should_trigger_products_and_sets_sync = ! empty( $request_data['product_catalog_id'] ) && facebook_for_woocommerce()->get_integration()->get_product_catalog_id() !== $request_data['product_catalog_id'];
		// Only trigger metadata feed uploads if CPI id is being updated
		$should_trigger_metadata_feed_uploads = ! empty( $request_data['commerce_partner_integration_id'] ) && facebook_for_woocommerce()->get_connection_handler()->get_commerce_partner_integration_id() !== $request_data['commerce_partner_integration_id'];

		// Map parameters to options and update settings
		$options = $this->map_params_to_options( $request_data, $has_finalized_pixel );
		$this->update_settings( $options );

		// Update connection status flags
		$this->update_connection_status();

		// Maybe trigger products sync and/or metadata feed uploads
		$this->maybe_trigger_feed_uploads( $should_trigger_products_and_sets_sync, $should_trigger_metadata_feed_uploads, $request_data );
	}

	/**
	 * Deposits the durable access token before finalizing the installation.
	 *
	 * Reads the value back rather than trusting the write: if the token were to go missing
	 * locally after Meta had recorded it as deposited, the two sides would disagree with no
	 * way to notice.
	 *
	 * @param array $params Request parameters.
	 * @return bool Whether the token can be read back after persistence.
	 */
	private function persist_access_token( array $params ): bool {
		$this->update_settings(
			array(
				\WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN => $params['access_token'],
			)
		);

		return get_option( \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN, '' ) === $params['access_token'];
	}

	/**
	 * Clears the access token so a failed installation leaves the store disconnected.
	 *
	 * @return void
	 */
	private function clear_access_token() {
		$this->update_settings(
			array(
				\WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN => '',
			)
		);
	}

	/**
	 * Gets authoritative installation assets, with the current FBE install
	 * message retained as a temporary fallback during rollout.
	 *
	 * TODO: Remove the fallback after finalize-install release monitoring is complete.
	 *
	 * @param array $fallback_assets Assets returned by the current FBE install flow.
	 * @return array|\WP_Error
	 */
	private function resolve_installation_assets( array $fallback_assets ) {
		$external_business_id = facebook_for_woocommerce()->get_connection_handler()->get_external_business_id();

		try {
			// Call Meta to inform it the client has the token deposited, and retrieve the
			// connected asset IDs it returns in response.
			$response = $this->finalize_client->finalize_install(
				$fallback_assets['access_token'],
				$external_business_id,
				facebook_for_woocommerce()->get_version()
			);

			$assets                       = $response->get_installation_assets();
			$legacy_asset_fallback_fields = array();
			foreach ( array( 'commerce_merchant_settings_id', 'product_catalog_id', 'pixel_id' ) as $asset_key ) {
				if ( empty( $assets[ $asset_key ] ) && ! empty( $fallback_assets[ $asset_key ] ) ) {
					$legacy_asset_fallback_fields[] = $asset_key;
				}
			}

			$this->log_finalize_install_outcome(
				empty( $legacy_asset_fallback_fields ) ? 'success' : 'success_with_legacy_asset_fallback',
				$external_business_id,
				$assets['commerce_partner_integration_id'],
				'',
				200,
				$legacy_asset_fallback_fields
			);

			return $assets;
		} catch ( FinalizeException $exception ) {
			$status_code      = (int) $exception->getCode();
			$failure_reason   = $exception->get_failure_reason();
			$is_auth_failure  = 401 === $status_code;
			$is_ebid_mismatch = 'external_business_id_mismatch' === $failure_reason;
			$outcome          = $is_auth_failure
				? 'failed_authentication'
				: ( $is_ebid_mismatch ? 'failed_identity_validation' : 'fallback' );

			$this->log_finalize_install_outcome(
				$outcome,
				$external_business_id,
				$fallback_assets['commerce_partner_integration_id'] ?? '',
				$failure_reason,
				$status_code
			);

			if ( $is_auth_failure || $is_ebid_mismatch ) {
				set_transient( 'wc_facebook_connection_invalid', time(), DAY_IN_SECONDS );

				return new \WP_Error(
					$is_auth_failure ? 'finalize_install_unauthorized' : 'finalize_install_identity_mismatch',
					__( 'Unable to finalize the Facebook connection. Please reconnect and try again.', 'facebook-for-woocommerce' ),
					array( 'status' => $is_auth_failure ? 401 : 409 )
				);
			}

			// During the monitored rollout, non-401 endpoint failures retain the
			// existing FBE install behavior. The outcome log records the exact status.
			return array();
		}
	}

	/**
	 * Logs finalize-install rollout outcomes without access tokens or raw payloads.
	 *
	 * @param string $outcome Finalize-install outcome.
	 * @param string $external_business_id Stable external business ID.
	 * @param string $commerce_partner_integration_id Commerce Partner Integration ID, if available.
	 * @param string $failure_reason Machine-readable failure reason, if any.
	 * @param int    $http_status HTTP status, or zero when unavailable.
	 * @param array  $legacy_asset_fallback_fields Finalize fields supplied by the legacy payload.
	 * @return void
	 */
	private function log_finalize_install_outcome(
		string $outcome,
		string $external_business_id,
		string $commerce_partner_integration_id,
		string $failure_reason = '',
		int $http_status = 0,
		array $legacy_asset_fallback_fields = array()
	) {
		Logger::log(
			'Commerce Partner Integration finalize-install outcome.',
			array(
				'event'      => 'commerce_partner_integration_finalize_install',
				'event_type' => $outcome,
				'extra_data' => array(
					'external_business_id'            => $external_business_id,
					'commerce_partner_integration_id' => $commerce_partner_integration_id,
					'failure_reason'                  => $failure_reason,
					'http_status'                     => $http_status,
					'legacy_asset_fallback_fields'    => implode( ',', $legacy_asset_fallback_fields ),
				),
			),
			array(
				'should_send_log_to_meta'        => true,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
			)
		);
	}

	/**
	 * Sets the store's checkout URL on its Commerce Merchant Settings and publishes the shop.
	 *
	 * Meta's checkout sends buyers to WooCommerce's native /checkout-link/ endpoint with the
	 * cart in its query string. A failure is logged and never fails the installation.
	 *
	 * @since 3.7.8
	 *
	 * @param string $commerce_merchant_settings_id Commerce Merchant Settings ID.
	 * @param string $country_code Integration country; the store's base country when empty.
	 * @return void
	 */
	private function set_up_checkout( string $commerce_merchant_settings_id, string $country_code ) {
		$country_code = '' !== $country_code ? $country_code : (string) WC()->countries->get_base_country();
		$outcome      = 'success';
		$error        = '';

		// WooCommerce serves /checkout-link/ from 10.0, so older stores have no checkout URL to give Meta.
		if ( '' === $commerce_merchant_settings_id || '' === $country_code || ! Compatibility::is_wc_version_gte( '10.0' ) ) {
			$outcome = 'skipped';
		} else {
			try {
				$response = facebook_for_woocommerce()->get_api()->set_up_checkout(
					$commerce_merchant_settings_id,
					site_url( '/checkout-link/' ),
					$country_code
				);

				// Graph errors come back as a response rather than an exception.
				if ( $response->has_api_error() ) {
					$outcome = 'failed';
					$error   = (string) $response->get_api_error_message();
				}
			} catch ( \Exception $exception ) {
				$outcome = 'failed';
				$error   = $exception->getMessage();
			}
		}

		Logger::log(
			'Commerce Merchant Settings checkout setup outcome.',
			array(
				'event'      => 'commerce_merchant_settings_checkout_setup',
				'event_type' => $outcome,
				'extra_data' => array(
					'commerce_merchant_settings_id' => $commerce_merchant_settings_id,
					'country_code'                  => $country_code,
					'wc_version'                    => (string) Compatibility::get_wc_version(),
					'error'                         => $error,
				),
			),
			array(
				'should_send_log_to_meta'        => true,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => 'failed' === $outcome ? \WC_Log_Levels::ERROR : \WC_Log_Levels::DEBUG,
			)
		);
	}

	/**
	 * Handle the uninstall request.
	 *
	 * @since 3.5.0
	 * @http_method POST
	 * @description Uninstall Facebook integration
	 *
	 * @param \WP_REST_Request $wp_request The WordPress request object.
	 * @return \WP_REST_Response
	 */
	public function handle_uninstall( \WP_REST_Request $wp_request ): \WP_REST_Response {
		try {
			$request           = new UninstallRequest( $wp_request );
			$validation_result = $request->validate();

			if ( is_wp_error( $validation_result ) ) {
				return $this->error_response(
					$validation_result->get_error_message(),
					400
				);
			}

			// Clear integration options
			$this->clear_integration_options();

			return $this->success_response(
				[
					'message' => __( 'Facebook integration successfully uninstalled', 'facebook-for-woocommerce' ),
				]
			);
		} catch ( \Exception $e ) {
			return $this->error_response(
				$e->getMessage(),
				500
			);
		}
	}

	/**
	 * Maps request parameters to WooCommerce options.
	 *
	 * @since 3.5.0
	 *
	 * @param array $params Request parameters.
	 * @param bool  $prefer_explicit_pixel Whether a finalized top-level pixel should override installed features.
	 * @return array Mapped options.
	 */
	private function map_params_to_options( array $params, bool $prefer_explicit_pixel = false ): array {
		$options = [];

		// Map access token
		if ( ! empty( $params['access_token'] ) ) {
			$options[ \WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN ] = $params['access_token'];
		}

		if ( ! empty( $params['commerce_merchant_settings_id'] ) ) {
			$options[ \WC_Facebookcommerce_Integration::OPTION_COMMERCE_MERCHANT_SETTINGS_ID ] = $params['commerce_merchant_settings_id'];
		}

		if ( ! empty( $params['commerce_partner_integration_id'] ) ) {
			$options[ \WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID ] = $params['commerce_partner_integration_id'];
		}

		// Prefer pixel from installed_features if available
		$pixel_from_features = '';

		if ( ! empty( $params['installed_features'] ) && is_array( $params['installed_features'] ) ) {
			foreach ( $params['installed_features'] as $feature ) {

				$feature_type = $feature['feature_type'] ?? '';
				$pixel_id     = $feature['connected_assets']['pixel_id'] ?? '';

				if ( 'pixel' === $feature_type && ! empty( $pixel_id ) ) {
					$pixel_from_features = $pixel_id;
					break;
				}
			}
		}

		$pixel_to_use = $prefer_explicit_pixel && ! empty( $params['pixel_id'] )
			? $params['pixel_id']
			: ( ! empty( $pixel_from_features ) ? $pixel_from_features : ( $params['pixel_id'] ?? '' ) );

		if ( ! empty( $pixel_to_use ) ) {
			$options[ \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID ] = $pixel_to_use;
			update_option( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, $pixel_to_use );
		}

		if ( ! empty( $params['product_catalog_id'] ) ) {
			$options[ \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ] = $params['product_catalog_id'];
		}

		return $options;
	}

	/**
	 * Updates Facebook settings options.
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
	 * Updates connection status flags.
	 *
	 * @since 3.5.0
	 *
	 * @return void
	 */
	private function update_connection_status() {
		// Clear any invalid connection flags since we just received fresh tokens
		delete_transient( 'wc_facebook_connection_invalid' );
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
			\WC_Facebookcommerce_Integration::OPTION_ACCESS_TOKEN,
			\WC_Facebookcommerce_Integration::OPTION_FEED_ID,
			\WC_Facebookcommerce_Integration::OPTION_COMMERCE_MERCHANT_SETTINGS_ID,
			\WC_Facebookcommerce_Integration::OPTION_COMMERCE_PARTNER_INTEGRATION_ID,
			\WC_Facebookcommerce_Integration::OPTION_PAGE_ACCESS_TOKEN,
			\WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID,
			\WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID,
		];

		// Also drop the rows for options the plugin no longer writes or reads.
		$options = array_merge( $options, \WC_Facebookcommerce_Integration::DEPRECATED_OPTIONS );

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		// Clear facebook_config option to stop pixel tracking and prevent stale data
		if ( class_exists( 'WC_Facebookcommerce_Pixel' ) ) {
			delete_option( \WC_Facebookcommerce_Pixel::SETTINGS_KEY );
		}
	}

	/**
	 * Triggers products sync if catalog id is being set to a different value.
	 * Triggers metadata feed uploads if CPI id is being set to a different value.
	 *
	 * @since 3.5.0
	 *
	 * @param bool  $should_trigger_products_and_sets_sync
	 * @param bool  $should_trigger_metadata_feed_uploads
	 * @param array $params
	 * @return void
	 */
	private function maybe_trigger_feed_uploads( bool $should_trigger_products_and_sets_sync, bool $should_trigger_metadata_feed_uploads, array $params ) {
		try {
			if ( $should_trigger_products_and_sets_sync ) {
				// Allow opt-out of full batch-API sync, for example if store has a large number of products.
				if ( facebook_for_woocommerce()->get_integration()->allow_full_batch_api_sync() ) {
					facebook_for_woocommerce()->get_products_sync_handler()->create_or_update_all_products();
				} else {
					Logger::log(
						'Initial full product sync disabled by filter hook `facebook_for_woocommerce_allow_full_batch_api_sync`',
						[
							'flow_name' => 'product_sync',
							'flow_step' => 'initial_sync',
						],
						array(
							'should_send_log_to_meta' => true,
							'should_save_log_in_woocommerce' => true,
							'woocommerce_log_level'   => \WC_Log_Levels::DEBUG,
						)
					);
				}
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Product feed upload failed.',
				array(
					'event'      => 'product_sync',
					'event_type' => 'sync_products_after_settings_update',
					'extra_data' => [
						'params' => wp_json_encode( $params ),
					],
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => false,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				),
				$exception,
			);
		}

		try {
			if ( $should_trigger_products_and_sets_sync ) {
				// Queued rather than run here: the sync makes Graph calls for every product
				// category, and this request has already committed the install on Meta's side.
				facebook_for_woocommerce()->get_product_sets_sync_handler()->schedule_sync_all_product_sets();
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Product sets sync scheduling failed.',
				array(
					'event'      => 'product_sets_sync',
					'event_type' => 'sync_product_sets_after_settings_update',
					'extra_data' => [
						'params' => wp_json_encode( $params ),
					],
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => false,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				),
				$exception,
			);
		}

		try {
			if ( $should_trigger_metadata_feed_uploads ) {
				facebook_for_woocommerce()->feed_manager->run_all_feed_uploads();
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Products metadata feed upload failed.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'trigger_feed_uploads_after_settings_update',
					'extra_data' => [
						'params' => wp_json_encode( $params ),
					],
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => false,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				),
				$exception,
			);
		}
	}
}
