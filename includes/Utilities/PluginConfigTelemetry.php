<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Utilities;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Framework\LogHandlerBase;
use WooCommerce\Facebook\Handlers\PluginRender;
use WooCommerce\Facebook\Integrations\IntegrationRegistry;

/**
 * Plugin configuration telemetry.
 *
 * Periodically logs the plugin's configuration to Meta as the `plugin_updates` flow of
 * commerce_seller_logs. This is logging only; no plugin behavior depends on the result.
 * The installed plugin version is reported through the Commerce Partner Integration
 * (see Connection::refresh_installation_data()).
 *
 * @since 3.0.10
 */
class PluginConfigTelemetry {

	/** @var string Transient key for caching language feed statistics */
	const TRANSIENT_LANGUAGE_FEED_STATS = 'facebook_for_woocommerce_language_feed_stats';

	/** @var int Transient lifetime for language feed stats cache (2 weeks) */
	const TRANSIENT_LANGUAGE_FEED_STATS_LIFETIME = 2 * WEEK_IN_SECONDS;

	/** @var string Option name for caching the collection page compatibility check */
	const COLLECTIONPAGE_COMPAT_OPTION = 'wc_facebook_collectionpage_compat';

	/**
	 * Registers the telemetry on the hourly heartbeat.
	 *
	 * @since 3.0.10
	 */
	public function __construct() {
		add_action( Heartbeat::HOURLY, array( $this, 'send_plugin_config_to_facebook_server' ) );
	}

	/**
	 * Sends the plugin configs to the Meta server.
	 *
	 * @since 3.5.3
	 */
	public function send_plugin_config_to_facebook_server() {
		$flag_name = '_wc_facebook_for_woocommerce_send_plugin_config_flag';
		if ( 'yes' === get_transient( $flag_name ) ) {
			return;
		}
		set_transient( $flag_name, 'yes', 3 * HOUR_IN_SECONDS );

		try {
			$excluded_product_categories = (array) apply_filters( 'wc_facebook_excluded_product_category_ids', get_option( 'wc_facebook_excluded_product_category_ids', [] ), $this );
			if ( ! empty( $excluded_product_categories ) ) {
				$term_query                  = new \WP_Term_Query(
					array(
						'taxonomy'   => 'product_cat',
						'include'    => $excluded_product_categories,
						'hide_empty' => true,
						'fields'     => 'id=>name',
					)
				);
				$excluded_product_categories = $term_query->get_terms();
			}

			$excluded_product_tags = (array) apply_filters( 'wc_facebook_excluded_product_tag_ids', get_option( 'wc_facebook_excluded_product_tag_ids', [] ), $this );
			if ( ! empty( $excluded_product_tags ) ) {
				$term_query            = new \WP_Term_Query(
					array(
						'taxonomy'     => 'product_tag',
						'include'      => $excluded_product_tags,
						'hide_empty'   => true,
						'hierarchical' => false,
						'fields'       => 'id=>name',
					)
				);
				$excluded_product_tags = $term_query->get_terms();
			}

			$language_feed_stats = get_transient( self::TRANSIENT_LANGUAGE_FEED_STATS );

			$context  = array(
				'flow_name'  => 'plugin_updates',
				'flow_step'  => 'send_plugin_updates',
				'extra_data' => [
					'is_multisite'                         => is_multisite(),
					'is_product_sync_enabled'              => facebook_for_woocommerce()->get_integration()->is_product_sync_enabled(),
					'excluded_product_categories'          => wp_json_encode( $excluded_product_categories ),
					'excluded_product_tags'                => wp_json_encode( $excluded_product_tags ),
					'published_product_count'              => facebook_for_woocommerce()->get_integration()->get_product_count(),
					'opted_out_woo_all_products'           => get_option( PluginRender::MASTER_SYNC_OPT_OUT_TIME ),
					'active_plugins'                       => wp_json_encode( IntegrationRegistry::get_all_active_plugin_data() ),
					'language_override_enabled'            => get_option( \WC_Facebookcommerce_Integration::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED, 'no' ),
					'language_feed_stats'                  => wp_json_encode( is_array( $language_feed_stats ) ? $language_feed_stats : [] ),
					'is_collectionpage_reliably_available' => $this->is_collectionpage_reliably_available(),
				],
			);
			$context  = [ LogHandlerBase::set_core_log_context( $context ) ];
			$context  = [
				'event'      => 'persist_meta_logs',
				'extra_data' => [ 'meta_logs' => wp_json_encode( $context ) ],
			];
			$response = facebook_for_woocommerce()->get_api()->log_to_meta( $context );
			if ( ! $response->success ) {
				Logger::log(
					'Bad response from log_to_meta request',
					[],
					array(
						'should_send_log_to_meta'        => false,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
					)
				);
			}
		} catch ( \Exception $e ) {
			Logger::log(
				'Error persisting error logs: ' . $e->getMessage(),
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}
	}

	/**
	 * Returns whether the /fbcollection/ page will reliably render on this site.
	 * Result is computed once per plugin version and cached in a WP option.
	 *
	 * @return bool
	 */
	private function is_collectionpage_reliably_available(): bool {
		$current_version = defined( '\WooCommerce\Facebook\PLUGIN_VERSION' )
			? \WooCommerce\Facebook\PLUGIN_VERSION
			: facebook_for_woocommerce()->get_version();

		$cached = get_option( self::COLLECTIONPAGE_COMPAT_OPTION, [] );

		if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === $current_version && isset( $cached['result'] ) ) {
			return (bool) $cached['result'];
		}

		$result = $this->check_collectionpage_compatibility();

		update_option(
			self::COLLECTIONPAGE_COMPAT_OPTION,
			[
				'version' => $current_version,
				'result'  => $result,
			],
			false
		);

		return $result;
	}

	/**
	 * Runs the actual compatibility checks for the collection page.
	 *
	 * @return bool True if the collection page is expected to work reliably.
	 */
	private function check_collectionpage_compatibility(): bool {
		// Plain permalinks — rewrite rules won't work at all.
		if ( '' === get_option( 'permalink_structure' ) ) {
			return false;
		}

		// Block/FSE themes bypass the PHP archive template and woocommerce_product_query hook.
		// wp_is_block_theme() was introduced in WordPress 5.9; older versions cannot be block themes.
		// Called indirectly so a site declaring a lower "Requires at least" never fatals, and so
		// static compatibility scanners don't flag a WP 5.9 function against the declared minimum.
		$wp_is_block_theme = 'wp_is_block_theme';
		if ( function_exists( $wp_is_block_theme ) && $wp_is_block_theme() ) {
			return false;
		}

		// Headless — no server-side frontend rendering.
		if ( class_exists( 'WPGraphQL' ) || defined( 'FAUSTWP_FILE' ) ) {
			return false;
		}

		// Elementor Pro has a custom template for the product archive.
		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			$conditions = get_option( 'elementor_pro_theme_builder_conditions', [] );
			foreach ( $conditions as $template_conditions ) {
				foreach ( (array) $template_conditions as $condition ) {
					if ( is_string( $condition )
						&& false !== strpos( $condition, 'product' )
						&& false !== strpos( $condition, 'archive' ) ) {
						return false;
					}
				}
			}
		}

		return true;
	}
}
