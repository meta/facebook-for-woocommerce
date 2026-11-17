<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Handlers;

use WooCommerce\Facebook\API\CommerceIntegration\Client as CommerceIntegrationClient;
use WooCommerce\Facebook\API\CommerceIntegration\Exception as FinalizeException;
use WooCommerce\Facebook\API\CommerceIntegration\Read\Response as IntegrationReadResponse;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Utilities\Heartbeat;

defined( 'ABSPATH' ) || exit;

/**
 * The connection handler.
 *
 * @since 2.0.0
 */
class Connection {


	/** @var string Facebook client identifier */
	const CLIENT_ID = '474166926521348';

	/** @var string WooCommerce connection for APP Store login URL */
	const APP_STORE_LOGIN_URL = 'https://api.woocommerce.com/integrations/app-store-login/facebook/';

	/** @var string the action callback for the disconnection */
	const ACTION_DISCONNECT = 'wc_facebook_disconnect';

	/** @var string the action callback for FBE redirection */
	const ACTION_FBE_REDIRECT = 'wc_fbe_redirect';

	/** @var string the WordPress option name where the external business ID is stored */
	const OPTION_EXTERNAL_BUSINESS_ID = 'wc_facebook_external_business_id';

	/** @var string the business manager ID option name */
	const OPTION_BUSINESS_MANAGER_ID = 'wc_facebook_business_manager_id';

	/** @var string the ad account ID option name */
	const OPTION_AD_ACCOUNT_ID = 'wc_facebook_ad_account_id';

	/**
	 * @var string the system user ID option name
	 * @deprecated Legacy FBE 2 leftover. Internal connection flows no longer write this option,
	 *             and nothing reads the stored ID.
	 */
	const OPTION_SYSTEM_USER_ID = 'wc_facebook_system_user_id';

	/** @var string the system user access token option name */
	const OPTION_ACCESS_TOKEN = 'wc_facebook_access_token';

	/**
	 * @var string the merchant access token option name
	 * @deprecated Legacy FBE 2 leftover. Internal connection flows no longer write this option,
	 *             and nothing reads it to authenticate a request. Use self::OPTION_ACCESS_TOKEN
	 *             instead.
	 */
	const OPTION_MERCHANT_ACCESS_TOKEN = 'wc_facebook_merchant_access_token';

	/** @var string Instagram Business ID option name */
	const OPTION_INSTAGRAM_BUSINESS_ID = 'wc_facebook_instagram_business_id';

	/** @var string the Commerce merchant settings ID option name */
	const OPTION_COMMERCE_MERCHANT_SETTINGS_ID = 'wc_facebook_commerce_merchant_settings_id';

	/** @var string the Commerce Partner Integration ID option name */
	const OPTION_COMMERCE_PARTNER_INTEGRATION_ID = 'wc_facebook_commerce_partner_integration_id';

	/** @var string|null the generated external merchant settings ID */
	private $external_business_id;

	/** @var \WC_Facebookcommerce */
	private $plugin;

	/**
	 * Constructs a new Connection.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Facebookcommerce $plugin
	 */
	public function __construct( \WC_Facebookcommerce $plugin ) {

		$this->plugin = $plugin;

		add_action( Heartbeat::DAILY, array( $this, 'refresh_installation_data' ) );

		add_action( 'admin_action_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );

		add_action( 'woocommerce_api_' . self::ACTION_FBE_REDIRECT, array( $this, 'handle_fbe_redirect' ) );

		add_action( 'rest_api_init', array( $this, 'init_extras_endpoint' ) );
	}


	/**
	 * Refreshes the connected installation data.
	 *
	 * @since 2.0.0
	 */
	public function refresh_installation_data() {

		// bail if not connected
		if ( ! $this->is_connected() ) {
			return;
		}

		$flag_name = '_wc_facebook_for_woocommerce_refresh_installation_data';
		if ( 'yes' === get_transient( $flag_name ) ) {
			return;
		}
		set_transient( $flag_name, 'yes', DAY_IN_SECONDS );

		// Each phase logs and absorbs its own failures: the Commerce Partner
		// Integration path must run even when legacy FBE endpoints fail, so a
		// failing FBE endpoint can never disable the CPI path.
		$mapping = $this->ensure_commerce_partner_integration_id();
		$this->push_commerce_integration_config();
		$this->refresh_asset_mapping( $mapping );
	}

	/**
	 * Forces a refresh of installation data and config sync with Meta, bypassing transient checks.
	 * Used during version upgrades to ensure config is properly synchronized.
	 *
	 * @since 3.5.4
	 */
	public function force_config_sync_on_update() {

		// bail if not connected
		if ( ! $this->is_connected() ) {
			$this->get_plugin()->log( 'Skipping config sync on update - not connected to Facebook' );
			return;
		}

		$this->get_plugin()->log( 'Starting forced config sync on version update' );

		// Each phase logs and absorbs its own failures: the Commerce Partner
		// Integration path must run even when legacy FBE endpoints fail, so a
		// failing FBE endpoint can never disable the CPI path.
		$mapping        = $this->ensure_commerce_partner_integration_id();
		$config_pushed  = $this->push_commerce_integration_config();
		$mapping_synced = $this->refresh_asset_mapping( $mapping );

		if ( $config_pushed && $mapping_synced ) {
			$this->get_plugin()->log( 'Successfully completed forced config sync on version update' );
		}
	}

	/**
	 * Refreshes the client side info and configuration.
	 *
	 * @since 3.4.8
	 * @deprecated The repair and update steps are decoupled; see
	 *             ensure_commerce_partner_integration_id() and
	 *             push_commerce_integration_config().
	 */
	public function repair_or_update_commerce_integration_data() {
		// bail if not connected
		if ( ! $this->is_connected() ) {
			return;
		}

		$this->ensure_commerce_partner_integration_id();
		$this->push_commerce_integration_config();
	}

	/**
	 * Ensures a Commerce Partner Integration ID is stored.
	 *
	 * Looks the integration up by external business ID first, which works for
	 * both FBE and FBL onboarded instances. The FBE-dependent repair runs only
	 * when the lookup fails; it can only recapture integrations for stores
	 * onboarded through the legacy FBE flow.
	 *
	 * @return IntegrationReadResponse|null The lookup response when it supplied the ID, null otherwise.
	 */
	private function ensure_commerce_partner_integration_id() {
		$commerce_integration_id = $this->get_commerce_partner_integration_id();
		if ( ! empty( $commerce_integration_id ) ) {
			return null;
		}

		try {
			$client   = new CommerceIntegrationClient();
			$response = $client->get_integration_by_external_business_id(
				$this->get_access_token(),
				$this->get_external_business_id()
			);

			$this->update_commerce_partner_integration_id( $response->get_commerce_partner_integration_id() );
			$this->get_plugin()->log( 'Successfully resolved commerce integration by external business ID.' );

			return $response;
		} catch ( FinalizeException $exception ) {
			$this->get_plugin()->log( 'Could not resolve commerce integration by external business ID. ' . $exception->getMessage(), null, 'error' );

			// Only a 404 establishes that this store has no Commerce Partner Integration.
			// Every other failure -- unauthorized, rate limited, server error, transport --
			// leaves the remote state unknown, and repair can mint an integration. Falling
			// through on those would let a transient failure replace a healthy stored ID.
			if ( 'not_found' !== $exception->get_failure_reason() ) {
				return null;
			}
		}

		try {
			$response = $this->get_plugin()->get_api()->repair_commerce_integration(
				$this->get_external_business_id(),
				$this->get_shop_domain(),
				admin_url(),
				$this->get_plugin()->get_version()
			);

			if ( ! $response->is_successful() ) {
				$this->get_plugin()->log( 'Failed to repair commerce integration.', null, 'error' );
				return null;
			}

			// Store the new commerce integration ID
			$new_commerce_integration_id = $response->get_commerce_partner_integration_id();
			if ( empty( $new_commerce_integration_id ) ) {
				$this->get_plugin()->log( 'Failed to get commerce partner integration ID from repair response.', null, 'error' );
				return null;
			}

			$this->update_commerce_partner_integration_id( $new_commerce_integration_id );
			$this->get_plugin()->log( 'Successfully repaired commerce integration. New ID: ' . $new_commerce_integration_id );
		} catch ( ApiException $exception ) {
			$this->get_plugin()->log( 'Could not repair commerce integration. ' . $exception->getMessage(), null, 'error' );
		}

		return null;
	}

	/**
	 * Pushes client-side store metadata to the Commerce Partner Integration.
	 *
	 * @return bool Whether the configuration was updated.
	 */
	private function push_commerce_integration_config() {
		$commerce_integration_id = $this->get_commerce_partner_integration_id();
		if ( empty( $commerce_integration_id ) ) {
			$this->get_plugin()->log( 'Skipping commerce integration configuration update: no integration ID.', null, 'error' );
			return false;
		}

		try {
			$update_response = $this->get_plugin()->get_api()->update_commerce_integration(
				$commerce_integration_id,
				$this->get_plugin()->get_version(),  // extension_version
				admin_url(),                         // admin_url
				$this->get_country_code(),           // country_code
				$this->get_currency(),               // currency
				$this->get_platform_store_id(),      // platform_store_id
			);

			if ( ! $update_response->is_successful() ) {
				$this->get_plugin()->log( 'Failed to update commerce integration configuration.', null, 'error' );
				return false;
			}

			$this->get_plugin()->log( 'Successfully updated commerce integration configuration.' );
			return true;
		} catch ( ApiException $exception ) {
			$this->get_plugin()->log( 'Could not update commerce integration configuration. ' . $exception->getMessage(), null, 'error' );
			return false;
		}
	}

	/**
	 * Refreshes the Meta-managed asset mapping with CPI preferred.
	 *
	 * Tries the Commerce Integration API read first, then the legacy FBE install read.
	 * Each rung logs which source supplied the mapping so fallback usage stays
	 * measurable while FBE is retired.
	 *
	 * @param IntegrationReadResponse|null $resolved_mapping Mapping already resolved while ensuring the ID.
	 * @return bool Whether the mapping was refreshed from any source.
	 */
	private function refresh_asset_mapping( $resolved_mapping = null ) {
		if ( $resolved_mapping instanceof IntegrationReadResponse ) {
			$this->apply_asset_mapping(
				$resolved_mapping->get_pixel_id(),
				$resolved_mapping->get_catalog_id(),
				$resolved_mapping->get_commerce_merchant_settings_id()
			);
			$this->get_plugin()->log( 'Refreshed asset mapping from the integration lookup.' );
			return true;
		}

		$commerce_integration_id = $this->get_commerce_partner_integration_id();
		if ( ! empty( $commerce_integration_id ) ) {
			try {
				$client   = new CommerceIntegrationClient();
				$response = $client->get_integration_by_id(
					$this->get_access_token(),
					$commerce_integration_id
				);

				$this->apply_asset_mapping(
					$response->get_pixel_id(),
					$response->get_catalog_id(),
					$response->get_commerce_merchant_settings_id()
				);
				$this->get_plugin()->log( 'Refreshed asset mapping from the integration read.' );
				return true;
			} catch ( FinalizeException $exception ) {
				$this->get_plugin()->log( 'Could not read commerce integration. ' . $exception->getMessage(), null, 'error' );
			}
		}

		try {
			$this->update_installation_data();
			$this->get_plugin()->log( 'Refreshed asset mapping from the legacy FBE install read.' );
			return true;
		} catch ( ApiException $exception ) {
			$this->get_plugin()->log( 'Could not refresh installation data. ' . $exception->getMessage(), null, 'error' );
			return false;
		}
	}

	/**
	 * Persists asset IDs, never blanking a stored value.
	 *
	 * @param string $pixel_id Pixel ID, possibly empty.
	 * @param string $catalog_id Catalog ID, possibly empty.
	 * @param string $commerce_merchant_settings_id Commerce Merchant Settings ID, possibly empty.
	 */
	private function apply_asset_mapping( $pixel_id, $catalog_id, $commerce_merchant_settings_id ) {
		if ( ! empty( $pixel_id ) ) {
			update_option( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, sanitize_text_field( $pixel_id ) );
		}

		if ( ! empty( $catalog_id ) ) {
			update_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, sanitize_text_field( $catalog_id ) );
		}

		if ( ! empty( $commerce_merchant_settings_id ) ) {
			$this->update_commerce_merchant_settings_id( sanitize_text_field( $commerce_merchant_settings_id ) );
		}
	}

	/**
	 * Gets the shop domain.
	 *
	 * @return string
	 */
	private function get_shop_domain() {
		return site_url( '/' );
	}

	/**
	 * Gets the country code.
	 *
	 * @return string|null
	 */
	private function get_country_code() {
		return WC()->countries->get_base_country();
	}

	/**
	 * Gets the currency.
	 *
	 * @return string|null
	 */
	private function get_currency() {
		return get_woocommerce_currency();
	}

	/**
	 * Gets the platform store ID.
	 *
	 * @return int
	 */
	private function get_platform_store_id() {
		return get_current_blog_id();
	}

	/**
	 * Retrieves and stores the connected installation data.
	 *
	 * Last-resort rung of the asset-mapping ladder; the Commerce Integration API read
	 * and the legacy Graph node are tried first.
	 *
	 * @since 2.0.0
	 *
	 * @throws ApiException If the installation data could not be retrieved.
	 */
	private function update_installation_data() {

		$response = $this->get_plugin()->get_api()->get_installation_ids( $this->get_external_business_id() );

		// Graph errors come back as a response rather than an exception, so surface them
		// here; otherwise the caller would report a refresh that never happened.
		if ( $response->has_api_error() ) {
			throw new ApiException( esc_html( $response->get_api_error_message() ), (int) $response->get_api_error_code() );
		}

		if ( empty( $response->get_data() ) ) {
			throw new ApiException( 'FBE install read returned no installation.' );
		}

		if ( $response->get_pixel_id() ) {
			update_option( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, sanitize_text_field( $response->get_pixel_id() ) );
		}

		if ( $response->get_catalog_id() ) {
			update_option( \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, sanitize_text_field( $response->get_catalog_id() ) );
		}

		if ( $response->get_commerce_merchant_settings_id() ) {
			$this->update_commerce_merchant_settings_id( sanitize_text_field( $response->get_commerce_merchant_settings_id() ) );
		}

		// The CPI ID is owned by ensure_commerce_partner_integration_id() and is
		// deliberately never written here, so a legacy read can neither mint nor
		// clear it.
	}


	/**
	 * Disconnects the integration using the Graph API.
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 *
	 * @throws ApiException If the disconnection failed.
	 */
	public function handle_disconnect() {
		check_admin_referer( self::ACTION_DISCONNECT );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to uninstall Facebook Business Extension.', 'facebook-for-woocommerce' ) );
		}
		try {
			$external_business_id = $this->get_external_business_id();

			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
			if ( null != $external_business_id ) {
				$response = facebook_for_woocommerce()->get_api()->delete_mbe_connection( (string) $external_business_id );
				facebook_for_woocommerce()->get_message_handler()->add_message( __( 'Disconnection successful.', 'facebook-for-woocommerce' ) );

				$body = wp_remote_retrieve_body( $response );
				$body = json_decode( $body, true );
				if ( ! is_array( $body ) || empty( $body['data'] ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
					facebook_for_woocommerce()->log( 'Failed to disconnect' );
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
					facebook_for_woocommerce()->log( print_r( $body, true ) );
					throw new ApiException(
						sprintf( wp_remote_retrieve_response_message( $response ) )
					);
				}
			} else {
				facebook_for_woocommerce()->log( 'External business id not found for the disconnection procedure, connection will be reset.' );
			}
		} catch ( ApiException $exception ) {
			facebook_for_woocommerce()->log( sprintf( 'An error occurred during disconnection: %s.', $exception->getMessage() ) );
		} catch ( \Exception $exception ) {
			facebook_for_woocommerce()->log( sprintf( 'Internal error occurred during disconnection: %s.', $exception->getMessage() ) );
		} finally {
			$this->disconnect();
			facebook_for_woocommerce()->log( sprintf( 'Your Facebook connection settings have been reset.' ) );
			wp_safe_redirect( facebook_for_woocommerce()->get_settings_url() );
			exit;
		}
	}


	/**
	 * Disconnects the plugin.
	 *
	 * Deletes local asset data.
	 *
	 * @since 2.0.0
	 */
	public function disconnect() {
		$this->update_access_token( '' );
		$this->update_commerce_merchant_settings_id( '' );
		$this->update_external_business_id( '' );
		$this->update_commerce_partner_integration_id( '' );
		update_option( \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, '' );
		facebook_for_woocommerce()->get_integration()->update_product_catalog_id( '' );
		delete_option( \WC_Facebookcommerce_Integration::OPTION_PAGE_ACCESS_TOKEN );

		// The plugin no longer writes these, but stores connected before they were retired still
		// hold values — including a stale credential — so disconnecting must still clear them.
		foreach ( \WC_Facebookcommerce_Integration::DEPRECATED_OPTIONS as $deprecated_option ) {
			delete_option( $deprecated_option );
		}

		// Clear facebook_config option to stop pixel tracking and prevent stale data
		if ( class_exists( 'WC_Facebookcommerce_Pixel' ) ) {
			delete_option( \WC_Facebookcommerce_Pixel::SETTINGS_KEY );
		}
	}


	/**
	 * Gets the API access token.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_access_token() {
		$access_token = get_option( self::OPTION_ACCESS_TOKEN, '' );
		/**
		 * Filters the API access token.
		 *
		 * @since 2.0.0
		 *
		 * @param string $access_token access token
		 * @param Connection $connection connection handler instance
		 */
		return apply_filters( 'wc_facebook_connection_access_token', $access_token, $this );
	}


	/**
	 * Gets the stored external business ID.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_external_business_id() {
		if ( ! is_string( $this->external_business_id ) ) {
			$external_id = get_option( self::OPTION_EXTERNAL_BUSINESS_ID );
			if ( ! is_string( $external_id ) || empty( $external_id ) ) {
				/**
				 * Filters the shop's business external ID.
				 *
				 * This is passed to Facebook when connecting.
				 * Should be non-empty and without special characters, otherwise the ID will be obtained from the site URL as fallback.
				 *
				 * @since 2.0.0
				 *
				 * @param string $external_id the shop's business external ID
				 */
				$external_id = sanitize_key( (string) apply_filters( 'wc_facebook_connection_business_id', get_bloginfo( 'name' ) ) );
				if ( empty( $external_id ) ) {
					$external_id = sanitize_key( str_replace( array( 'http', 'https', 'www' ), '', get_bloginfo( 'url' ) ) );
				}
				$external_id = uniqid( sprintf( '%s-', $external_id ), false );
				$this->update_external_business_id( $external_id );
			}
			$this->external_business_id = $external_id;
		}

		/**
		 * Filters the external business ID.
		 *
		 * @since 2.0.0
		 *
		 * @param string $external_business_id stored external business ID
		 * @param Connection $connection connection handler instance
		 */
		return (string) apply_filters( 'wc_facebook_external_business_id', $this->external_business_id, $this );
	}


	/**
	 * Gets the site's business name.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_business_name() {
		$business_name = get_bloginfo( 'name' );
		/**
		 * Filters the shop's business name.
		 *
		 * This is passed to Facebook when connecting.
		 * Defaults to the site name. Should be non-empty, otherwise the site URL will be used as fallback.
		 *
		 * @since 2.0.0
		 *
		 * @param string $business_name the shop's business name
		 */
		$business_name = trim( (string) apply_filters( 'wc_facebook_connection_business_name', is_string( $business_name ) ? $business_name : '' ) );
		if ( empty( $business_name ) ) {
			$business_name = get_bloginfo( 'url' );
		}
		return html_entity_decode( $business_name, ENT_QUOTES, 'UTF-8' );
	}


	/**
	 * Gets the business manager ID value.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 *
	 * @deprecated Legacy FBE asset data. Internal flows no longer read or write this option,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply it. Retained for backwards compatibility.
	 */
	public function get_business_manager_id() {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		$business_manager_id = get_option( self::OPTION_BUSINESS_MANAGER_ID, '' );
		/**
		 * Filters the Business Manager ID.
		 *
		 * @since 3.0.31
		 *
		 * @param string     $business_manager_id Business Manager ID.
		 * @param Connection $connection          The Connection handler instance.
		 */
		return (string) apply_filters( 'wc_facebook_business_manager_id', $business_manager_id, $this );
	}


	/**
	 * Gets the ad account ID value.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 *
	 * @deprecated Legacy FBE asset data. Internal flows no longer read or write this option,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply it. Retained for backwards compatibility.
	 */
	public function get_ad_account_id() {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		return get_option( self::OPTION_AD_ACCOUNT_ID, '' );
	}


	/**
	 * Gets Instagram Business ID value.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 *
	 * @deprecated Legacy FBE asset data. Internal flows no longer read or write this option,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply it. Retained for backwards compatibility.
	 */
	public function get_instagram_business_id() {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		return get_option( self::OPTION_INSTAGRAM_BUSINESS_ID, '' );
	}


	/**
	 * Gets Commerce merchant settings ID value.
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	public function get_commerce_merchant_settings_id() {
		return get_option( self::OPTION_COMMERCE_MERCHANT_SETTINGS_ID, '' );
	}


	/**
	 * Gets Commerce Partner Integration ID value.
	 *
	 * @since 3.4.8
	 *
	 * @return string
	 */
	public function get_commerce_partner_integration_id() {
		return get_option( self::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, '' );
	}


	/**
	 * Gets APP Store Login URL.
	 *
	 * @since 2.3.0
	 *
	 * @return string URL
	 */
	public function get_app_store_login_url() {
		/**
		 * Filters App Store login URL.
		 *
		 * @since 2.3.0
		 *
		 * @param string $app_store_login_url the connection App Store login URL
		 */
		return (string) apply_filters( 'wc_facebook_connection_app_store_login_url', self::APP_STORE_LOGIN_URL );
	}

	/**
	 * Gets connection parameters extras.
	 *
	 * @since 2.0.0
	 *
	 * @return array associative array (to be converted to JSON encoded for connection purposes)
	 */
	private function get_connect_parameters_extras() {
		$parameters = array(
			'setup'           => array(
				'external_business_id' => $this->get_external_business_id(),
				'timezone'             => $this->get_timezone_string(),
				'currency'             => get_woocommerce_currency(),
				'business_vertical'    => 'ECOMMERCE',
				'domain'               => home_url(),
				'channel'              => 'DEFAULT',
			),
			'business_config' => array(
				'business' => array(
					'name' => $this->get_business_name(),
				),
			),
			'repeat'          => false,
		);

		$external_merchant_settings_id = facebook_for_woocommerce()->get_integration()->get_external_merchant_settings_id();
		if ( $external_merchant_settings_id ) {
			$parameters['setup']['merchant_settings_id'] = $external_merchant_settings_id;
		}
		return $parameters;
	}


	/**
	 * Gets the configured timezone string using values accepted by Facebook
	 *
	 * @since 2.5.0
	 *
	 * @return string
	 */
	public function get_timezone_string() {
		$timezone = wc_timezone_string();
		// convert +05:30 and +05:00 into Etc/GMT+5 - we ignore the minutes because Facebook does not allow minute offsets
		if ( preg_match( '/([+-])(\d{2}):\d{2}/', $timezone, $matches ) ) {
			$hours    = (int) $matches[2];
			$timezone = "Etc/GMT{$matches[1]}{$hours}";
		}
		return $timezone;
	}


	/**
	 * Stores the given ID value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value the business manager ID
	 *
	 * @deprecated Legacy FBE asset data. Internal flows no longer read or write this option,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply it. Retained for backwards compatibility.
	 */
	public function update_business_manager_id( $value ) {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		update_option( self::OPTION_BUSINESS_MANAGER_ID, $value );
	}


	/**
	 * Stores the given ID value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value the ad account ID
	 *
	 * @deprecated Legacy FBE asset data. Internal flows no longer read or write this option,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply it. Retained for backwards compatibility.
	 */
	public function update_ad_account_id( $value ) {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		update_option( self::OPTION_AD_ACCOUNT_ID, $value );
	}


	/**
	 * Stores the given system user ID.
	 *
	 * @since 2.0.0
	 * @deprecated Legacy FBE 2 leftover. Internal connection flows no longer call this method.
	 *             The write remains temporarily for backwards compatibility.
	 *
	 * @param string $value the ID
	 */
	public function update_system_user_id( $value ) {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		update_option( self::OPTION_SYSTEM_USER_ID, $value );
	}


	/**
	 * Stores the given Instagram Business ID.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id the ID
	 *
	 * @deprecated Legacy FBE asset data. Internal flows no longer read or write this option,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply it. Retained for backwards compatibility.
	 */
	public function update_instagram_business_id( $id ) {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		update_option( self::OPTION_INSTAGRAM_BUSINESS_ID, $id );
	}


	/**
	 * Stores the given Commerce merchant settings ID.
	 *
	 * @since 2.3.0
	 *
	 * @param string $id the ID
	 */
	public function update_commerce_merchant_settings_id( $id ) {
		update_option( self::OPTION_COMMERCE_MERCHANT_SETTINGS_ID, $id );
	}


	/**
	 * Stores the given token value.
	 *
	 * @since 2.0.0
	 *
	 * @param string $value the access token
	 */
	public function update_access_token( $value ) {
		update_option( self::OPTION_ACCESS_TOKEN, $value );
	}


	/**
	 * Stores the given merchant access token.
	 *
	 * @since 2.0.0
	 * @deprecated Legacy FBE 2 leftover. Internal connection flows no longer call this method.
	 *             The write remains temporarily for backwards compatibility; use
	 *             update_access_token() instead.
	 *
	 * @param string $value the access token
	 */
	public function update_merchant_access_token( $value ) {
		wc_deprecated_function( __METHOD__, '3.7.7', 'WooCommerce\Facebook\Handlers\Connection::update_access_token()' );
		update_option( self::OPTION_MERCHANT_ACCESS_TOKEN, $value );
	}


	/**
	 * Stores the given external business id.
	 *
	 * @since 2.6.13
	 *
	 * @param string $value external business id
	 */
	public function update_external_business_id( $value ) {
		update_option( self::OPTION_EXTERNAL_BUSINESS_ID, is_string( $value ) ? $value : '' );
	}


	/**
	 * Determines whether the site is connected.
	 *
	 * A site is connected if there is an access token stored.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function is_connected() {
		return (bool) $this->get_access_token();
	}


	/**
	 * Determines whether the site has previously connected to FBE 1.x.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function has_previously_connected_fbe_1() {
		$integration = $this->get_plugin()->get_integration();
		return $integration && $integration->get_external_merchant_settings_id();
	}


	/**
	 * Gets the client ID for connection.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_client_id() {
		/**
		 * Filters the client ID.
		 *
		 * @since 2.0.0
		 *
		 * @param string $client_id the client ID
		 */
		return apply_filters( 'wc_facebook_connection_client_id', self::CLIENT_ID );
	}


	/**
	 * Gets the plugin instance.
	 *
	 * @since 2.0.0
	 *
	 * @return \WC_Facebookcommerce
	 */
	public function get_plugin() {
		return $this->plugin;
	}


	/**
	 * Register Extras REST API endpoint
	 *
	 * @since 2.3.0
	 */
	public function init_extras_endpoint() {
		register_rest_route(
			'wc-facebook/v1',
			'extras',
			array(
				array(
					'methods'             => array( 'GET', 'POST' ),
					'callback'            => array( $this, 'extras_callback' ),
					'permission_callback' => array( $this, 'extras_permission_callback' ),
				),
			)
		);
	}


	/**
	 * FBE Extras endpoint permissions
	 *
	 * @since 2.3.0
	 *
	 * @return boolean
	 */
	public function extras_permission_callback() {
		return current_user_can( 'manage_woocommerce' );
	}


	/**
	 * Return FBE extras
	 *
	 * @since 2.3.0
	 *
	 * @return \WP_REST_Response
	 */
	public function extras_callback() {
		$extras = $this->get_connect_parameters_extras();
		if ( empty( $extras ) ) {
			return new \WP_REST_Response( null, 204 );
		}
		return new \WP_REST_Response( $extras, 200 );
	}


	/**
	 * Process FBE App Store login flow redirection
	 *
	 * @since 2.3.0
	 */
	public function handle_fbe_redirect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to finish App Store login.', 'facebook-for-woocommerce' ) );
		}

		$redirect_uri = isset( $_REQUEST['redirect_uri'] ) ? base64_decode( wc_clean( wp_unslash( $_REQUEST['redirect_uri'] ) ) ) : ''; //phpcs:ignore
		// To ensure that we are not sharing any user data with other parties, only redirect to the
		// redirect_uri if its parsed host matches a known Meta host used by this flow.
		$host         = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_HOST ) );
		$scheme       = strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_SCHEME ) );
		$host_allowed = 'https' === $scheme && 1 === preg_match(
			'/^(?:(?:www|m|l)\.)?(?:\d{5}\.od\.)?(?:facebook|instagram|whatsapp|commercepartnerhub)\.com$/',
			$host
		);

		if ( empty( $redirect_uri ) || ! $host_allowed ) {
			wp_safe_redirect( site_url() );
			exit;
		}

		if ( empty( $_REQUEST['success'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url_params   = array(
				'store_url'    => '',
				'redirect_uri' => rawurlencode( $redirect_uri ),
				'errors'       => array( 'You need to grant access to WooCommerce.' ),
			);
			$redirect_url = add_query_arg(
				$url_params,
				$this->get_app_store_login_url()
			);
		} else {
			$redirect_url = $redirect_uri . '&extras=' . rawurlencode_deep( wp_json_encode( $this->get_connect_parameters_extras() ) );
		}

		// $redirect_url now points either to a Meta domain (validated above) or to the trusted App
		// Store login URL. Register its host so the core safe-redirect guarantee is enforced for this
		// otherwise cross-domain redirect.
		$redirect_host  = strtolower( (string) wp_parse_url( $redirect_url, PHP_URL_HOST ) );
		$allow_redirect = static function ( $hosts ) use ( $redirect_host ) {
			$hosts[] = $redirect_host;
			return $hosts;
		};

		add_filter( 'allowed_redirect_hosts', $allow_redirect );
		wp_safe_redirect( $redirect_url );
		remove_filter( 'allowed_redirect_hosts', $allow_redirect );
		exit;
	}

	/**
	 * Stores the given Commerce Partner Integration ID.
	 *
	 * @since 3.4.
	 *
	 * @param string $id the ID
	 */
	public function update_commerce_partner_integration_id( $id ) {
		update_option( self::OPTION_COMMERCE_PARTNER_INTEGRATION_ID, $id );
	}
}
