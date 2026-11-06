<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\ProductSets\LegacyProductSetMigration;
use WooCommerce\Facebook\Handlers\WhatsAppExtension;

/**
 * The Meta for WooCommerce plugin lifecycle handler.
 *
 * @since 1.10.0
 *
 * @method \WC_Facebookcommerce get_plugin()
 */
class Lifecycle extends Framework\Lifecycle {

	/**
	 * @var string the "enable messenger" setting ID
	 * @deprecated Leftover from the removed Messenger chat feature, no longer written. Nothing reads it.
	 */
	const SETTING_ENABLE_MESSENGER = 'wc_facebook_enable_messenger';

	/** @var string the messenger locale setting ID */
	const SETTING_MESSENGER_LOCALE = 'wc_facebook_messenger_locale';

	/** @var string the messenger greeting setting ID */
	const SETTING_MESSENGER_GREETING = 'wc_facebook_messenger_greeting';

	/** @var string the messenger color HEX setting ID */
	const SETTING_MESSENGER_COLOR_HEX = 'wc_facebook_messenger_color_hex';


	/**
	 * Lifecycle constructor.
	 *
	 * @since 1.10.0
	 *
	 * @param Framework\Plugin $plugin
	 */
	public function __construct( Framework\Plugin $plugin ) {
		parent::__construct( $plugin );
		$this->upgrade_versions = array(
			'1.10.0',
			'1.10.1',
			'1.11.0',
			'2.0.0',
			'2.0.3',
			'2.0.4',
			'2.4.0',
			'2.5.0',
			'3.2.0',
			'3.4.9',
			'3.5.4',
			'3.5.6',
			'3.7.5',
		);
	}


	/**
	 * Migrates options from previous versions of the plugin, which did not use the Framework.
	 *
	 * @since 1.10.0
	 */
	protected function install() {
		/**
		 * Versions prior to 1.10.0 did not set a version option, so the upgrade method needs to be called manually.
		 * We do this by checking first if an old option exists, but a new one doesn't.
		 */
		if ( get_option( 'woocommerce_facebookcommerce_settings' ) && ! get_option( \WC_Facebookcommerce_Integration::OPTION_PAGE_ACCESS_TOKEN ) ) {
			$this->upgrade( '1.9.15' );
		}
	}


	/**
	 * Upgrades to version 1.10.0.
	 *
	 * @since 1.10.0
	 */
	protected function upgrade_to_1_10_0() {
		$this->migrate_1_9_settings();
	}


	/**
	 * Migrates Meta for WooCommerce options used in version 1.9.x to the options and settings used in 1.10.x.
	 *
	 * Some users who upgraded from 1.9.x to 1.10.0 ended up with an incomplete upgrade and could have configured the plugin from scratch after that.
	 * This routine will update the options and settings only if a previous value does not exists.
	 *
	 * @since 1.10.1
	 */
	private function migrate_1_9_settings() {
		$values = get_option( 'woocommerce_facebookcommerce_settings', array() );
		// preserve legacy values
		if ( false === get_option( 'woocommerce_facebookcommerce_legacy_settings' ) ) {
			update_option( 'woocommerce_facebookcommerce_legacy_settings', $values );
		}
		// migrate options from woocommerce_facebookcommerce_settings
		$options = array(
			'fb_product_catalog_id'            => \WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID,
			'fb_external_merchant_settings_id' => \WC_Facebookcommerce_Integration::OPTION_EXTERNAL_MERCHANT_SETTINGS_ID,
			'fb_feed_id'                       => \WC_Facebookcommerce_Integration::OPTION_FEED_ID,
			'facebook_jssdk_version'           => \WC_Facebookcommerce_Integration::OPTION_JS_SDK_VERSION,
			'pixel_install_time'               => \WC_Facebookcommerce_Integration::OPTION_PIXEL_INSTALL_TIME,
		);

		foreach ( $options as $old_index => $new_option_name ) {
			if ( isset( $values[ $old_index ] ) && false === get_option( $new_option_name ) ) {
				$new_value = $values[ $old_index ];
				if ( 'pixel_install_time' === $old_index ) {
					// convert to UTC timestamp
					try {
						$pixel_install_time = \DateTime::createFromFormat( 'Y-m-d G:i:s', $new_value, new \DateTimeZone( wc_timezone_string() ) );
					} catch ( \Exception $e ) {
						$pixel_install_time = false;
					}
					$new_value = $pixel_install_time instanceof \DateTime ? $pixel_install_time->getTimestamp() : null;
				}
				update_option( $new_option_name, $new_value );
			}
		}

		$new_settings = get_option( 'woocommerce_' . \WC_Facebookcommerce::INTEGRATION_ID . '_settings', array() );

		// migrate settings from woocommerce_facebookcommerce_settings
		$settings = array(
			'fb_page_id'                                  => \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID,
			'fb_pixel_id'                                 => \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID,
			'fb_pixel_use_pii'                            => \WC_Facebookcommerce_Integration::SETTING_ENABLE_ADVANCED_MATCHING,
			'is_messenger_chat_plugin_enabled'            => self::SETTING_ENABLE_MESSENGER,
			'msger_chat_customization_locale'             => self::SETTING_MESSENGER_LOCALE,
			'msger_chat_customization_greeting_text_code' => self::SETTING_MESSENGER_GREETING,
			'msger_chat_customization_theme_color_code'   => self::SETTING_MESSENGER_COLOR_HEX,
		);

		foreach ( $settings as $old_index => $new_index ) {
			if ( isset( $values[ $old_index ] ) && ! isset( $new_settings[ $new_index ] ) ) {
				$new_settings[ $new_index ] = $values[ $old_index ];
			}
		}

		// migrate settings from standalone options
		if ( ! isset( $new_settings[ \WC_Facebookcommerce_Integration::SETTING_SCHEDULED_RESYNC_OFFSET ] ) ) {
			$autosync_time = get_option( 'woocommerce_fb_autosync_time' );
			$parsed_time   = ! empty( $autosync_time ) ? strtotime( $autosync_time ) : false;
			$resync_offset = null;
			if ( false !== $parsed_time ) {
				$midnight      = ( new \DateTime() )->setTimestamp( $parsed_time )->setTime( 0, 0, 0 );
				$resync_offset = $parsed_time - $midnight->getTimestamp();
			}
			$new_settings[ \WC_Facebookcommerce_Integration::SETTING_SCHEDULED_RESYNC_OFFSET ] = $resync_offset;
		}

		// maybe remove old settings entries
		$old_indexes = array_merge( array_keys( $options ), array_keys( $settings ), array( 'fb_settings_heading', 'fb_upload_id', 'upload_end_time' ) );

		foreach ( $old_indexes as $old_index ) {
			unset( $new_settings[ $old_index ] );
		}

		update_option( 'woocommerce_' . \WC_Facebookcommerce::INTEGRATION_ID . '_settings', $new_settings );
	}

	/**
	 * Upgrades to version 1.10.1.
	 *
	 * @since 1.10.1
	 */
	protected function upgrade_to_1_10_1() {
		$this->migrate_1_9_settings();
	}


	/**
	 * Upgrades to version 1.11.0.
	 *
	 * @since 1.11.0
	 */
	protected function upgrade_to_1_11_0() {
		$settings = get_option( 'woocommerce_' . \WC_Facebookcommerce::INTEGRATION_ID . '_settings', array() );
		// moves the upload ID to a standalone option
		if ( ! empty( $settings['fb_upload_id'] ) ) {
			$this->get_plugin()->get_integration()->update_upload_id( $settings['fb_upload_id'] );
		}
	}


	/**
	 * Upgrades to version 2.0.0
	 *
	 * @since 2.0.0
	 */
	protected function upgrade_to_2_0_0() {
		// handle sync enabled and visible virtual products and variations
		$handler = $this->get_plugin()->get_background_handle_virtual_products_variations_instance();
		if ( $handler ) {
			// create_job() expects an non-empty array of attributes
			$handler->create_job( array( 'created_at' => current_time( 'mysql' ) ) );
			$handler->dispatch();
		}
		$settings = get_option( 'woocommerce_facebookcommerce_settings' );
		if ( is_array( $settings ) ) {
			$settings_map = array(
				'facebook_pixel_id'             => \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID,
				'facebook_page_id'              => \WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID,
				'enable_product_sync'           => \WC_Facebookcommerce_Integration::SETTING_ENABLE_PRODUCT_SYNC,
				'excluded_product_category_ids' => \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS,
				'excluded_product_tag_ids'      => \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_TAG_IDS,
				'enable_messenger'              => self::SETTING_ENABLE_MESSENGER,
				'messenger_locale'              => self::SETTING_MESSENGER_LOCALE,
				'messenger_greeting'            => self::SETTING_MESSENGER_GREETING,
				'messenger_color_hex'           => self::SETTING_MESSENGER_COLOR_HEX,
				'enable_debug_mode'             => \WC_Facebookcommerce_Integration::SETTING_ENABLE_DEBUG_MODE,
			);
			foreach ( $settings_map as $old_name => $new_name ) {
				if ( ! empty( $settings[ $old_name ] ) ) {
					update_option( $new_name, $settings[ $old_name ] );
				}
			}
		}
		// deletes an option that is not longer used to generate an admin notice
		delete_option( 'fb_cart_url' );
	}


	/**
	 * Upgrades to version 2.0.3
	 *
	 * @since 2.0.3
	 */
	protected function upgrade_to_2_0_3() {
		if ( ! $this->should_create_remove_duplicate_visibility_meta_background_job() ) {
			return;
		}

		// if an unfinished job is stuck, give the handler a chance to complete it
		$handler = $this->get_plugin()->get_background_handle_virtual_products_variations_instance();
		if ( $handler ) {
			$handler->dispatch();
		}

		// create a job to remove duplicate visibility meta data entries
		$handler = $this->get_plugin()->get_background_remove_duplicate_visibility_meta_instance();
		if ( $handler ) {
			// create_job() expects an non-empty array of attributes
			$handler->create_job( array( 'created_at' => current_time( 'mysql' ) ) );
			$handler->dispatch();
		}
	}


	/**
	 * Determines whether we need to run a background job to remove duplicate visibility meta.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	private function should_create_remove_duplicate_visibility_meta_background_job() {
		// we should try to remove duplicate meta if the virtual product variations job ran
		if ( 'yes' === get_option( 'wc_facebook_background_handle_virtual_products_variations_complete', 'no' ) ) {
			return true;
		}
		$handler = $this->get_plugin()->get_background_handle_virtual_products_variations_instance();
		// the virtual product variations job is not marked as complete but there is at least one job in the database
		if ( $handler && $handler->get_jobs() ) {
			return true;
		}
		return false;
	}


	/**
	 * Upgrades to version 2.0.4
	 *
	 * @since 2.0.4
	 */
	protected function upgrade_to_2_0_4() {
		// if unfinished jobs are stuck, give the handlers a chance to complete them
		$handler = $this->get_plugin()->get_background_handle_virtual_products_variations_instance();
		if ( $handler ) {
			$handler->dispatch();
		}

		$handler = $this->get_plugin()->get_background_remove_duplicate_visibility_meta_instance();
		if ( $handler ) {
			$handler->dispatch();
		}
	}

	/**
	 * Upgrades to version 2.4.0
	 *
	 * @since 2.4.0
	 */
	protected function upgrade_to_2_4_0() {
		delete_option( 'wc_facebook_google_product_categories' );
		delete_transient( 'wc_facebook_google_product_categories' );
	}

	/**
	 * Upgrades to version 2.5.0
	 *
	 * @since 2.5.0
	 */
	protected function upgrade_to_2_5_0() {
		/**
		 * Since 2.5.0 the feed generation interval is increased to 24h.
		 * Update procedure just needs to remove all current actions.
		 * The Feed class will reschedule new generation with proper cadence.
		 */
		as_unschedule_all_actions( Products\Feed::GENERATE_FEED_ACTION );
	}

	/**
	 * Removes the messenger settings and deprecation notice on upgrade to 3.2.0.
	 * Note: deprecation notice upgrade step removed in 3.2.1.
	 *
	 * @since 3.2.0
	 */
	protected function upgrade_to_3_2_0() {
		// Remove the Messenger deprecation notice.
		$notice_slug = 'facebook_messenger_deprecation_warning';
		if ( class_exists( 'WC_Admin_Notices' ) && \WC_Admin_Notices::has_notice( $notice_slug ) ) {
			\WC_Admin_Notices::remove_notice( $notice_slug );
		}

		// Delete all messenger options
		delete_option( self::SETTING_ENABLE_MESSENGER );
		delete_option( self::SETTING_MESSENGER_LOCALE );
		delete_option( self::SETTING_MESSENGER_GREETING );
		delete_option( self::SETTING_MESSENGER_COLOR_HEX );
	}

	/**
	 * Queue a sync of all WooCommerce categories.
	 *
	 * Queued rather than run here: the sync makes Graph calls for every product category, and
	 * upgrade routines run inside an ordinary admin request.
	 *
	 * @since 3.4.9
	 */
	protected function upgrade_to_3_4_9() {
		facebook_for_woocommerce()->get_product_sets_sync_handler()->schedule_sync_all_product_sets();
	}

	/**
	 * Forces config synchronization with Meta to ensure all configuration fields are up-to-date
	 *
	 * @since 3.5.4
	 */
	protected function upgrade_to_3_5_4() {
		// Force config sync with Meta to ensure all fields are properly synchronized
		$connection_handler = facebook_for_woocommerce()->get_connection_handler();
		if ( $connection_handler ) {
			$connection_handler->force_config_sync_on_update();
		}
	}

	/**
	 * Migrate manually created FB Sets from static to dynamic filter
	 *
	 * @since 3.5.6
	 */
	protected function upgrade_to_3_5_6() {
		LegacyProductSetMigration::migrate_legacy_fb_product_sets();
	}

	/**
	 * Backfill the WhatsApp onboarding-complete flag for installs that connected
	 * before the WA_CONNECT push signal existed (the pull path). One-time HEAD
	 * existence check; fails open on error.
	 *
	 * @since 3.7.5
	 */
	protected function upgrade_to_3_7_5() {
		WhatsAppExtension::maybe_backfill_onboarding_state( facebook_for_woocommerce() );
	}
}
