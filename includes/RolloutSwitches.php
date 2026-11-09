<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved

 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook;

use WooCommerce\Facebook\Framework\Api\Exception;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WooCommerce\Facebook\Framework\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The rollout switches is used to control available
 * features in the Meta for WooCommerce plugin.
 */
class RolloutSwitches {
	/** @var \WC_Facebookcommerce commerce handler */
	private \WC_Facebookcommerce $plugin;

	public const SWITCH_ROLLOUT_FEATURES                 = 'rollout_enabled';
	public const SWITCH_PRODUCT_SETS_SYNC_ENABLED        = 'product_sets_sync_enabled';
	public const SWITCH_OFFER_MANAGEMENT_ENABLED         = 'offer_management_enabled';
	public const SWITCH_MULTIPLE_IMAGES_ENABLED          = 'woo_variant_multiple_images_enabled';
	public const SWITCH_CONTENT_ID_MIGRATION_ENABLED     = 'enable_woocommerce_content_id_migration';
	public const SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED   = 'wooc_language_override_feed';
	public const SWITCH_ISOLATED_PIXEL_EXECUTION_ENABLED = 'enable_woocommerce_isolated_pixel_execution';
	public const SWITCH_COMPAT_CHECK_ENABLED             = 'enable_woocommerce_compat_check';
	private const SETTINGS_KEY                           = 'wc_facebook_for_woocommerce_rollout_switches';
	public const CAPI_EVENT_LOGGING_ENABLED              = 'enable_woocommerce_capi_event_logging';
	public const SWITCH_WA_CUSTOMER_EVENTS_GATING_ENABLED = 'enable_woocommerce_wa_customer_events_gating';
	public const SWITCH_VALUE_OPTIMIZATION_ENABLED        = 'woocommerce_vo_switch';

	private const ACTIVE_SWITCHES = array(
		self::SWITCH_ROLLOUT_FEATURES,
		self::SWITCH_OFFER_MANAGEMENT_ENABLED,
		self::SWITCH_MULTIPLE_IMAGES_ENABLED,
		self::CAPI_EVENT_LOGGING_ENABLED,
		self::SWITCH_CONTENT_ID_MIGRATION_ENABLED,
		self::SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED,
		self::SWITCH_ISOLATED_PIXEL_EXECUTION_ENABLED,
		self::SWITCH_COMPAT_CHECK_ENABLED,
		self::SWITCH_WA_CUSTOMER_EVENTS_GATING_ENABLED,
		self::SWITCH_VALUE_OPTIMIZATION_ENABLED,
	);

	public function __construct( \WC_Facebookcommerce $plugin ) {
		$this->plugin = $plugin;
	}

	public function init() {
		$is_connected = $this->plugin->get_connection_handler()->is_connected();
		if ( ! $is_connected ) {
			return;
		}

		// Include plugin version in transient key to reset on version upgrades
		$plugin_version = $this->plugin->get_version();
		$flag_name      = '_wc_facebook_for_woocommerce_rollout_switch_flag_' . $plugin_version;
		if ( 'yes' === get_transient( $flag_name ) ) {
			return;
		}
		set_transient( $flag_name, 'yes', 60 * MINUTE_IN_SECONDS );

		try {
			$external_business_id = $this->plugin->get_connection_handler()->get_external_business_id();
			$catalog_id           = $this->plugin->get_integration()->get_product_catalog_id();
			$pixel_id             = $this->plugin->get_integration()->get_facebook_pixel_id();
			$switches             = $this->plugin->get_api()->get_rollout_switches( $external_business_id, $catalog_id, $pixel_id );
			$data                 = $switches->get_data();
			if ( empty( $data ) ) {
				throw new Exception( 'Empty data' );
			}
			$fb_options = array();
			foreach ( $data as $switch ) {
				if ( ! isset( $switch['switch'] ) || ! $this->is_switch_active( $switch['switch'] ) ) {
					continue;
				}
				$fb_options[ $switch['switch'] ] = (bool) $switch['enabled'] ? 'yes' : 'no';
			}
			update_option( self::SETTINGS_KEY, $fb_options );
		} catch ( Exception $e ) {
			$fb_options = get_option( self::SETTINGS_KEY );
			if ( empty( $fb_options ) ) {
				$fb_options = array();
			}
			foreach ( $this->get_active_switches() as $switch_name ) {
				// if the switch is not in the response and we have a failure
				// we fallback to the old value first and false otherwise
				if ( ! isset( $fb_options[ $switch_name ] ) ) {
					$fb_options[ $switch_name ] = 'no';
				}
			}
			update_option( self::SETTINGS_KEY, $fb_options );
			Logger::log(
				$e->getMessage(),
				array(
					'flow_name' => 'rollout_switches',
					'flow_step' => 'init',
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}
	}

	/**
	 * Get if the switch is enabled or not.
	 * If the switch is not active ->
	 *   FALSE
	 *
	 * If the switch is active but not in the response ->
	 *    TRUE: we assume this is an old version of the plugin
	 *    and the backend since has changed and the switch was released
	 *    in the backend we will otherwise always return false for unreleased
	 *    features
	 *
	 * If the feature is active and in the response ->
	 *   we will return the value of the switch from the response
	 *
	 * @param string $switch_name The name of the switch.
	 */
	public function is_switch_enabled( string $switch_name ) {
		if ( ! $this->is_switch_active( $switch_name ) ) {
			return false;
		}
		$features = get_option( self::SETTINGS_KEY );
		if ( empty( $features ) ) {
			return false;
		}

		if ( ! isset( $features[ $switch_name ] ) ) {
			return true;
		}

		return 'yes' === $features[ $switch_name ] ? true : false;
	}

	public function is_switch_active( string $switch_name ): bool {
		return in_array( $switch_name, self::ACTIVE_SWITCHES, true );
	}

	public function get_active_switches(): array {
		return self::ACTIVE_SWITCHES;
	}
}
