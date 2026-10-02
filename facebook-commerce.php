<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

use WooCommerce\Facebook\Admin;
use WooCommerce\Facebook\Events\AAMSettings;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\Products\Feed;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\RolloutSwitches;

defined( 'ABSPATH' ) || exit;

require_once 'facebook-config-warmer.php';
require_once 'includes/fbproduct.php';
require_once 'facebook-commerce-pixel-event.php';
require_once 'facebook-commerce-admin-notice.php';

/**
 * Class WC_Facebookcommerce_Integration
 *
 * This class is the main integration class for Meta for WooCommerce.
 */
class WC_Facebookcommerce_Integration extends WC_Integration {

	/**
	 * The WordPress option name where the legacy page access token is stored.
	 *
	 * Retained for compatibility until the stored data is removed in T283387818.
	 *
	 * @deprecated 2.1.0
	 * @var string option name
	 */
	const OPTION_PAGE_ACCESS_TOKEN = 'wc_facebook_page_access_token';

	/** @var string the WordPress option name where the product catalog ID is stored */
	const OPTION_PRODUCT_CATALOG_ID = 'wc_facebook_product_catalog_id';

	/** @var string the WordPress option name where the external merchant settings ID is stored */
	const OPTION_EXTERNAL_MERCHANT_SETTINGS_ID = 'wc_facebook_external_merchant_settings_id';

	/** @var string Option name for disabling feed. */
	const OPTION_LEGACY_FEED_FILE_GENERATION_ENABLED = 'wc_facebook_legacy_feed_file_generation_enabled';

	/** @var string Option name for enabling language override feed generation. */
	const OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED = 'wc_facebook_language_override_feed_generation_enabled';

	/** @var string the WordPress option name where the feed ID is stored */
	const OPTION_FEED_ID = 'wc_facebook_feed_id';

	/** @var string the WordPress option name where the upload ID is stored */
	const OPTION_UPLOAD_ID = 'wc_facebook_upload_id';

	/** @var string the WordPress option name where the JS SDK version is stored */
	const OPTION_JS_SDK_VERSION = 'wc_facebook_js_sdk_version';

	/** @var string the WordPress option name where the latest pixel install time is stored */
	const OPTION_PIXEL_INSTALL_TIME = 'wc_facebook_pixel_install_time';

	/** @var string the facebook page ID setting ID */
	const SETTING_FACEBOOK_PAGE_ID = 'wc_facebook_page_id';

	/** @var string the facebook pixel ID setting ID */
	const SETTING_FACEBOOK_PIXEL_ID = 'wc_facebook_pixel_id';

	/** @var string the "enable advanced matching" setting ID */
	const SETTING_ENABLE_ADVANCED_MATCHING = 'enable_advanced_matching';

	/** @var string the "use s2s" setting ID */
	const SETTING_USE_S2S = 'use_s2s';

	/** @var string the "access token" setting ID */
	const SETTING_ACCESS_TOKEN = 'access_token';

	/** @var string the "enable product sync" setting ID */
	const SETTING_ENABLE_PRODUCT_SYNC = 'wc_facebook_enable_product_sync';

	/** @var string the excluded product category IDs setting ID */
	const SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS = 'wc_facebook_excluded_product_category_ids';

	/** @var string the excluded product tag IDs setting ID */
	const SETTING_EXCLUDED_PRODUCT_TAG_IDS = 'wc_facebook_excluded_product_tag_ids';

	/** @var string the product description mode setting ID */
	const SETTING_PRODUCT_DESCRIPTION_MODE = 'wc_facebook_product_description_mode';

	/** @var string the scheduled resync offset setting ID */
	const SETTING_SCHEDULED_RESYNC_OFFSET = 'scheduled_resync_offset';

	/** @var string the "meta diagnosis" setting ID */
	const SETTING_ENABLE_META_DIAGNOSIS = 'wc_facebook_enable_meta_diagnosis';

	/** @var string the "debug mode" setting ID */
	const SETTING_ENABLE_DEBUG_MODE = 'wc_facebook_enable_debug_mode';

	/** @var string the "debug mode" setting ID */
	const SETTING_ENABLE_NEW_STYLE_FEED_GENERATOR = 'wc_facebook_enable_new_style_feed_generator';

	/** @var string enable facebook managed coupons setting ID */
	const SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS = 'wc_facebook_enable_facebook_managed_coupons';

	/** @var string the "enable offline purchase events" setting ID */
	const SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS = 'wc_facebook_enable_offline_purchase_events';

	/**
	 * The "Meta-enabled Conversions API integration" setting ID.
	 *
	 * CAPIG (Conversions API Gateway) is the Meta-enabled integration; internal
	 * names use it to keep a clear distinction from CAPI, the plugin's own direct
	 * server-to-server integration.
	 *
	 * @var string
	 */
	const SETTING_ENABLE_CAPIG = 'wc_facebook_enable_capig';

	/** @var string the default for the Meta-enabled Conversions API integration: opted in */
	const SETTING_ENABLE_CAPIG_DEFAULT = 'yes';

	/** @var string when the merchant last opted in to offline purchase events, as a Unix timestamp */
	const OPTION_OFFLINE_PURCHASE_EVENTS_OPTED_IN_AT = 'wc_facebook_offline_purchase_events_opted_in_at';

	/** @var string whether the merchant has ever opted in to offline purchase events; kept after opting out */
	const OPTION_OFFLINE_PURCHASE_EVENTS_EVER_OPTED_IN = 'wc_facebook_offline_purchase_events_ever_opted_in';

	/** @var string request headers in the debug log */
	const SETTING_REQUEST_HEADERS_IN_DEBUG_MODE = 'wc_facebook_request_headers_in_debug_log';

	/** @var array Meta keys that affect Facebook sync and should trigger last change time update */
	const PRODUCT_ATTRIBUTE_SYNC_RELEVANT_META_KEYS = array(
		'_regular_price',                     // -> price
		'_sale_price',                        // -> sale_price
		'_stock',                             // -> availability
		'_stock_status',                      // -> availability
		'_thumbnail_id',                      // -> image_link
		'_price',                             // -> price (calculated field)
		'fb_visibility',                      // -> visibility
		'fb_product_description',             // -> description
		'fb_rich_text_description',           // -> rich_text_description
		'fb_brand',                           // -> brand
		'fb_mpn',                             // -> mpn
		'fb_size',                            // -> size
		'fb_color',                           // -> color
		'fb_material',                        // -> material
		'fb_pattern',                         // -> pattern
		'fb_age_group',                       // -> age_group
		'fb_gender',                          // -> gender
		'fb_product_condition',               // -> condition
		'_wc_facebook_sync_enabled',          // -> sync settings
		'_wc_facebook_product_image_source',  // -> sync settings
	);

	/** @var string the WordPress option name where the access token is stored */
	const OPTION_ACCESS_TOKEN = 'wc_facebook_access_token';

	/**
	 * @var string the WordPress option name where the merchant access token is stored
	 * @deprecated Legacy FBE 2 leftover. The Commerce Extension flow issues a single system user
	 *             access token, so internal connection flows no longer write this option and it
	 *             was never used to authenticate a request. Use self::OPTION_ACCESS_TOKEN instead.
	 */
	const OPTION_MERCHANT_ACCESS_TOKEN = 'wc_facebook_merchant_access_token';

	/** @var string the WordPress option name where the business manager ID is stored */
	const OPTION_BUSINESS_MANAGER_ID = 'wc_facebook_business_manager_id';

	/** @var string the WordPress option name where the ad account ID is stored */
	const OPTION_AD_ACCOUNT_ID = 'wc_facebook_ad_account_id';

	/**
	 * @var string the WordPress option name where the system user ID is stored
	 * @deprecated Legacy FBE 2 leftover. Internal connection flows no longer write this option,
	 *             and nothing reads the stored ID.
	 */
	const OPTION_SYSTEM_USER_ID = 'wc_facebook_system_user_id';

	/** @var string the WordPress option name where the commerce merchant settings ID is stored */
	const OPTION_COMMERCE_MERCHANT_SETTINGS_ID = 'wc_facebook_commerce_merchant_settings_id';

	/** @var string the WordPress option name where the commerce partner integration ID is stored */
	const OPTION_COMMERCE_PARTNER_INTEGRATION_ID = 'wc_facebook_commerce_partner_integration_id';

	/** @var string the WordPress option name where the profiles are stored */
	const OPTION_PROFILES = 'wc_facebook_profiles';

	/** @var string the WordPress option name where the installed features are stored */
	const OPTION_INSTALLED_FEATURES = 'wc_facebook_installed_features';

	/**
	 * @var string the WordPress option name where the FBE 2 connection status is stored
	 * @deprecated Legacy FBE 2 leftover, no longer written. It was the FBE 1 vs FBE 2 discriminator
	 *             set by the 2.0.0 upgrade; nothing ever read it. Connection state is decided by
	 *             Connection::is_connected(), which checks the access token.
	 */
	const OPTION_HAS_CONNECTED_FBE_2 = 'wc_facebook_has_connected_fbe_2';

	/**
	 * @var string the WordPress option name where the pages read engagement authorization status is stored
	 * @deprecated Legacy FBE 2 leftover, no longer written. Nothing reads it.
	 */
	const OPTION_HAS_AUTHORIZED_PAGES_READ_ENGAGEMENT = 'wc_facebook_has_authorized_pages_read_engagement';

	/**
	 * @var string the WordPress option name where the messenger chat status is stored
	 * @deprecated Leftover from the removed Messenger chat feature, no longer written. Nothing reads it.
	 */
	const OPTION_ENABLE_MESSENGER = 'wc_facebook_enable_messenger';

	/**
	 * Options retired from connection state. Their names are retained so legacy callers remain
	 * compatible and stored rows can be deleted. This is the canonical list: when one of these
	 * is finally removed, delete its constant and its entry here together.
	 *
	 * Disconnecting clears them via clear_integration_options(). Rows on still-connected sites
	 * are removed by a version-gated upgrade step in the next major release.
	 *
	 * @var string[]
	 */
	const DEPRECATED_OPTIONS = array(
		self::OPTION_MERCHANT_ACCESS_TOKEN,
		self::OPTION_SYSTEM_USER_ID,
		self::OPTION_HAS_CONNECTED_FBE_2,
		self::OPTION_HAS_AUTHORIZED_PAGES_READ_ENGAGEMENT,
		self::OPTION_ENABLE_MESSENGER,
		self::SETTING_FACEBOOK_PAGE_ID,
		self::OPTION_BUSINESS_MANAGER_ID,
		self::OPTION_AD_ACCOUNT_ID,
		self::OPTION_PROFILES,
		self::OPTION_INSTALLED_FEATURES,
		'wc_facebook_instagram_business_id',
		'wc_facebook_last_attribute_sync',
	);

	/** @var string default value for facebook_managed_coupons_setting */
	const SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS_DEFAULT_VALUE = 'yes';

	/** @var string|null the configured external merchant settings ID */
	public $external_merchant_settings_id;

	/** @var string|null the configured feed ID */
	public $feed_id;

	/** @var string|null the configured upload ID */
	private $upload_id;

	/** @var string|null the configured pixel install time */
	public $pixel_install_time;

	/** @var string|null the configured JS SDK version */
	private $js_sdk_version;

	/** @var bool|null whether the feed has been migrated from FBE 1 to FBE 1.5 */
	private $feed_migrated;

	/** @var array the page name and url */
	private $page;

	/** Legacy properties *********************************************************************************************/


	// TODO probably some of these meta keys need to be moved to Facebook\Products {FN 2020-01-13}.
	public const FB_PRODUCT_GROUP_ID      = 'fb_product_group_id';
	public const FB_PRODUCT_ITEM_ID       = 'fb_product_item_id';
	public const FB_PRODUCT_DESCRIPTION   = 'fb_product_description';
	public const FB_RICH_TEXT_DESCRIPTION = 'fb_rich_text_description';
	/** @var string the API flag to set a product as visible in the Facebook shop */
	public const FB_SHOP_PRODUCT_VISIBLE = 'published';

	/** @var string the API flag to set a product as not visible in the Facebook shop */
	public const FB_SHOP_PRODUCT_HIDDEN = 'hidden';

	/** @var string @deprecated */
	public const FB_CART_URL = 'fb_cart_url';

	public const FB_MESSAGE_DISPLAY_TIME = 180;

	// Number of days to query tip.
	public const FB_TIP_QUERY = 1;

	// TODO: this constant is no longer used and can probably be removed {WV 2020-01-21}.
	public const FB_VARIANT_IMAGE = 'fb_image';

	public const FB_ADMIN_MESSAGE_PREPEND = '<b>Meta for WooCommerce</b><br/>';

	public const FB_SYNC_IN_PROGRESS = 'fb_sync_in_progress';
	public const FB_SYNC_REMAINING   = 'fb_sync_remaining';
	public const FB_SYNC_TIMEOUT     = 30;
	public const FB_PRIORITY_MID     = 9;

	/**
	 * Static flag to prevent infinite loops when updating last change time.
	 *
	 * @var bool
	 */
	private static $is_updating_last_change_time = false;

	/**
	 * Facebook exception test mode switch.
	 *
	 * @var bool
	 */
	private $test_mode = false;

	/** @var WC_Facebookcommerce */
	private $facebook_for_woocommerce;

	/** @var WC_Facebookcommerce_EventsTracker instance. */
	private $events_tracker;

	/** @var WC_Facebookcommerce_Background_Process instance. */
	private $background_processor;

	/** @var WC_Facebook_Product_Feed instance. */
	private $fbproductfeed;

	/** @var WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event instance. */
	private $wa_iframe_utility_event_processor;

	/**
	 * Init and hook in the integration.
	 *
	 * @param WC_Facebookcommerce $facebook_for_woocommerce
	 *
	 * @return void
	 */
	public function __construct( WC_Facebookcommerce $facebook_for_woocommerce ) {
		$this->facebook_for_woocommerce = $facebook_for_woocommerce;

		if ( ! class_exists( 'WC_Facebookcommerce_EventsTracker' ) ) {
			include_once 'facebook-commerce-events-tracker.php';
		}

		$this->id                 = WC_Facebookcommerce::INTEGRATION_ID;
		$this->method_title       = __(
			'Meta for WooCommerce',
			'facebook-for-woocommerce'
		);
		$this->method_description = __(
			'Meta Commerce and Dynamic Ads (Pixel) Extension',
			'facebook-for-woocommerce'
		);

		// Load the settings.
		$this->init_settings();

		$pixel_id = WC_Facebookcommerce_Pixel::get_pixel_id();

		// If there is a pixel option saved and no integration setting saved, inherit the pixel option.
		if ( $pixel_id && ! $this->get_facebook_pixel_id() ) {
			$this->settings[ self::SETTING_FACEBOOK_PIXEL_ID ] = $pixel_id;
		}

		$advanced_matching_enabled = WC_Facebookcommerce_Pixel::get_use_pii_key();

		// If Advanced Matching (use_pii) is enabled on the saved pixel option and not on the saved integration setting, inherit the pixel option.
		if ( $advanced_matching_enabled && ! $this->is_advanced_matching_enabled() ) {
			$this->settings[ self::SETTING_ENABLE_ADVANCED_MATCHING ] = $advanced_matching_enabled;
		}

		// For now, the values of use s2s and access token will be the ones returned from WC_Facebookcommerce_Pixel.
		$this->settings[ self::SETTING_USE_S2S ]      = WC_Facebookcommerce_Pixel::get_use_s2s();
		$this->settings[ self::SETTING_ACCESS_TOKEN ] = WC_Facebookcommerce_Pixel::get_access_token();

		WC_Facebookcommerce_Utils::$ems = $this->get_external_merchant_settings_id();

		// Set meta diagnosis to yes by default
		if ( ! get_option( self::SETTING_ENABLE_META_DIAGNOSIS ) ) {
			update_option( self::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );
		}

		// Register the WordPress cron hook for async processing
		add_action( 'wc_facebook_async_sync', array( $this, 'handle_async_product_save' ) );

		// Record when the merchant opts in to offline purchase events, whichever way the
		// setting is changed (settings screen, AJAX, WP-CLI).
		add_action( 'add_option_' . self::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS, array( $this, 'handle_offline_purchase_events_option_added' ), 10, 2 );
		add_action( 'update_option_' . self::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS, array( $this, 'handle_offline_purchase_events_option_updated' ), 10, 2 );

		if ( is_admin() ) {

			$this->init_pixel();

			if ( ! class_exists( 'WC_Facebookcommerce_EventsTracker' ) ) {
				include_once 'includes/fbutils.php';
			}

			if ( ! $this->get_pixel_install_time() && $this->get_facebook_pixel_id() ) {
				$this->update_pixel_install_time( time() );
			}

			add_action( 'admin_notices', array( $this, 'checks' ) );

			add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) );

			add_action(
				'wp_ajax_ajax_check_feed_upload_status',
				array( $this, 'ajax_check_feed_upload_status' ),
				self::FB_PRIORITY_MID
			);

			add_action(
				'wp_ajax_ajax_reset_all_fb_products',
				array( $this, 'ajax_reset_all_fb_products' ),
				self::FB_PRIORITY_MID
			);
			add_action(
				'wp_ajax_ajax_display_test_result',
				array( $this, 'ajax_display_test_result' )
			);

			// Don't duplicate product FBID meta.
			add_filter( 'woocommerce_duplicate_product_exclude_meta', array( $this, 'fb_duplicate_product_reset_meta' ) );

			// Add product processing hooks if the plugin is configured only.
			if ( $this->is_configured() && $this->get_product_catalog_id() ) {

				// On_product_save() must run with priority larger than 20 to make sure WooCommerce has a chance to save the submitted product information.
				add_action( 'woocommerce_process_product_meta', array( $this, 'schedule_product_sync' ), 40 );

				add_action(
					'woocommerce_product_quick_edit_save',
					array( $this, 'on_product_quick_edit_save' )
				);

				add_action(
					'woocommerce_product_bulk_edit_save',
					array( $this, 'on_product_bulk_edit_save' )
				);

				add_action(
					'wp_ajax_ajax_fb_toggle_visibility',
					array( $this, 'ajax_fb_toggle_visibility' )
				);

				add_action(
					'pmxi_after_xml_import',
					array( $this, 'wp_all_import_compat' )
				);

				add_action(
					'wp_ajax_wpmelon_adv_bulk_edit',
					array( $this, 'ajax_woo_adv_bulk_edit_compat' ),
					self::FB_PRIORITY_MID
				);

				// Used to remove the 'you need to resync' message.
				if ( isset( $_GET['remove_sticky'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$this->remove_sticky_message();
				}
			}
			$this->load_background_sync_process();
		}

		if ( $this->get_facebook_pixel_id() ) {
			// Apply the current signal state before EventsTracker initializes.
			if ( \WooCommerce\Facebook\Signals::should_hold_signals() ) {
				\WooCommerce\Facebook\Events\FacebookSignalsState::hold();
			}

			$aam_settings         = $this->load_aam_settings_of_pixel();
			$user_info            = WC_Facebookcommerce_Utils::get_user_info( $aam_settings );
			$this->events_tracker = new WC_Facebookcommerce_EventsTracker( $user_info, $aam_settings );
		}

		// Sync products created or updated outside the admin product editor, such as through the
		// WooCommerce REST API. Those requests do not fire the admin-only woocommerce_process_product_meta
		// hook used above, so without this the Meta catalog would miss REST API and programmatic changes.
		add_action( 'woocommerce_update_product', array( $this, 'on_product_save_via_api' ), 40 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_save_via_api' ), 40 );

		// Update products on change of status.
		add_action(
			'transition_post_status',
			array( $this, 'fb_change_product_published_status' ),
			10,
			3
		);

		add_action( 'before_delete_post', array( $this, 'on_product_delete' ) );

		// Ensure product is deleted from FB when moved to trash.
		add_action( 'wp_trash_post', array( $this, 'on_product_delete' ) );

		add_action( 'untrashed_post', array( $this, 'fb_restore_untrashed_variable_product' ) );

		// Ensure product is deleted from FB when status is changed to draft.
		add_action( 'publish_to_draft', array( $this, 'delete_draft_product' ) );

		// Track programmatic changes that don't update post_modified
		add_action( 'updated_post_meta', array( $this, 'update_product_last_change_time' ), 10, 4 );

		// Init Whatsapp Iframe Utility Event Processor
		$this->wa_iframe_utility_event_processor = $this->load_whatsapp_iframe_utility_event_processor();
	}

	/**
	 * __get method for backward compatibility.
	 *
	 * @param string $key property name
	 *
	 * @return mixed
	 * @since 3.0.32
	 */
	public function __get( $key ) {
		// Add warning for private properties.
		if ( in_array( $key, array( 'events_tracker', 'background_processor' ), true ) ) {
			/* translators: %s property name. */
			_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'The %s property is private and should not be accessed outside its class.', 'facebook-for-woocommerce' ), esc_html( $key ) ), '3.0.32' );

			return $this->$key;
		}

		return null;
	}

	/**
	 * Initialises Facebook Pixel and its settings.
	 *
	 * @return bool
	 */
	public function init_pixel() {
		/* Not sure this one is needed. Config warmer is never written. */
		WC_Facebookcommerce_Pixel::initialize();

		/**
		 * Migrate WC customer pixel_id from WC settings to WP options.
		 * This is part of a larger effort to consolidate all the FB-specific
		 * settings for all plugin integrations.
		 */
		if ( is_admin() ) {
			$pixel_id          = WC_Facebookcommerce_Pixel::get_pixel_id();
			$settings_pixel_id = $this->get_facebook_pixel_id();
			if (
				WC_Facebookcommerce_Utils::is_valid_id( $settings_pixel_id ) &&
				( ! WC_Facebookcommerce_Utils::is_valid_id( $pixel_id ) ||
					$pixel_id !== $settings_pixel_id
				)
			) {
				WC_Facebookcommerce_Pixel::set_pixel_id( $settings_pixel_id );
			}
			/**
			 * Migrate Advanced Matching enabled (use_pii) from the integration setting to the pixel option,
			 * so that it works the same way the pixel ID does
			 */
			$settings_advanced_matching_enabled = $this->is_advanced_matching_enabled();
			WC_Facebookcommerce_Pixel::set_use_pii_key( $settings_advanced_matching_enabled );

			$settings_use_s2s = WC_Facebookcommerce_Pixel::get_use_s2s();
			WC_Facebookcommerce_Pixel::set_use_s2s( $settings_use_s2s );

			$settings_access_token = WC_Facebookcommerce_Pixel::get_access_token();
			WC_Facebookcommerce_Pixel::set_access_token( $settings_access_token );

			return true;
		}

		return false;
	}

	/**
	 * Returns user_info for the currently installed pixel using AAM settings.
	 *
	 * Public wrapper for external callers (e.g. AJAX handlers) that should not
	 * access internal AAM loading methods directly.
	 *
	 * @return array
	 * @since 3.7.0
	 */
	public function get_pixel_user_info_for_release() {
		$aam_settings = $this->load_aam_settings_of_pixel();

		return WC_Facebookcommerce_Utils::get_user_info( $aam_settings );
	}

	/**
	 * Returns the Automatic advanced matching of this pixel
	 *
	 * @return AAMSettings
	 * @since 2.0.3
	 */
	private function load_aam_settings_of_pixel() {
		$installed_pixel = $this->get_facebook_pixel_id();
		// If no pixel is installed, reading the DB is not needed.
		if ( ! $installed_pixel ) {
			return null;
		}
		$config_key       = 'wc_facebook_aam_settings';
		$saved_value      = get_transient( $config_key );
		$refresh_interval = 10 * MINUTE_IN_SECONDS;
		$aam_settings     = null;
		// If wc_facebook_aam_settings is present in the DB it is converted into an AAMSettings object.
		if ( false !== $saved_value ) {
			$cached_aam_settings = new AAMSettings( json_decode( $saved_value, true ) );
			// This condition is added because
			// it is possible that the AAMSettings saved do not belong to the current
			// installed pixel
			// because the admin could have changed the connection to Facebook
			// during the refresh interval.
			if ( $cached_aam_settings->get_pixel_id() === $installed_pixel ) {
				$aam_settings = $cached_aam_settings;
			}
		}
		// If the settings are not present or invalid
		// they are fetched from Facebook domain
		// and cached in WP database if they are not null.
		if ( ! $aam_settings ) {
			$aam_settings = AAMSettings::build_from_pixel_id( $installed_pixel );
			if ( $aam_settings ) {
				set_transient( $config_key, strval( $aam_settings ), $refresh_interval );
			}
		}

		return $aam_settings;
	}

	/**
	 * Init background process.
	 *
	 * @return void
	 */
	public function load_background_sync_process() {
		// Attempt to load background processing (Woo 3.x.x only).
		include_once 'includes/fbbackground.php';
		if ( class_exists( 'WC_Facebookcommerce_Background_Process' ) ) {
			if ( ! isset( $this->background_processor ) ) {
				$this->background_processor = new WC_Facebookcommerce_Background_Process( $this );
			}
		}
		add_action(
			'wp_ajax_ajax_fb_background_check_queue',
			array( $this, 'ajax_fb_background_check_queue' )
		);
		add_action(
			'wp_ajax_fb_dismiss_unmapped_attributes_banner',
			array( $this, 'ajax_dismiss_unmapped_attributes_banner' )
		);
	}

	/**
	 * Ajax background check handler.
	 *
	 * @return void
	 */
	public function ajax_fb_background_check_queue() {
		WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'background check queue', true );
		check_ajax_referer( 'wc_facebook_settings_jsx' );
		$request_time = null;
		if ( isset( $_POST['request_time'] ) ) {
			$request_time = esc_js( sanitize_text_field( wp_unslash( $_POST['request_time'] ) ) );
		}
		if ( $this->facebook_for_woocommerce->get_connection_handler()->get_access_token() ) {
			if ( isset( $this->background_processor ) ) {
				$is_processing = $this->background_processor->handle_cron_healthcheck();
				$remaining     = $this->background_processor->get_item_count();
				$response      = array(
					'connected'    => true,
					'background'   => true,
					'processing'   => $is_processing,
					'remaining'    => $remaining,
					'request_time' => $request_time,
				);
			} else {
				$response = array(
					'connected'  => true,
					'background' => false,
				);
			}
		} else {
			$response = array(
				'connected'  => false,
				'background' => false,
			);
		}
		printf( wp_json_encode( $response ) );
		wp_die();
	}


	/**
	 * Gets a list of Product Item IDs indexed by the ID of the variation.
	 *
	 * @param WC_Facebook_Product|WC_Product $product product
	 * @param string                         $product_group_id product group ID
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public function get_variation_product_item_ids( $product, $product_group_id ) {
		$product_item_ids_by_variation_id = array();
		$missing_product_item_ids         = array();

		// get the product item IDs from meta data and build a list of variations that don't have a product item ID stored
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$product_item_id = $variation->get_meta( self::FB_PRODUCT_ITEM_ID );
				if ( $product_item_id ) {
					$product_item_ids_by_variation_id[ $variation_id ] = $product_item_id;
				} else {
					$retailer_id                                       = WC_Facebookcommerce_Utils::get_fb_retailer_id( $variation );
					$missing_product_item_ids[ $retailer_id ]          = $variation;
					$product_item_ids_by_variation_id[ $variation_id ] = null;
				}
			}
		}
		// use the Graph API to try to find and store the product item IDs for variations that don't have a value yet
		if ( $missing_product_item_ids ) {
			$product_item_ids = $this->find_variation_product_item_ids( $product_group_id );
			foreach ( $missing_product_item_ids as $retailer_id => $variation ) {
				if ( isset( $product_item_ids[ $retailer_id ] ) ) {
					$variation->update_meta_data( self::FB_PRODUCT_ITEM_ID, $product_item_ids[ $retailer_id ] );
					$variation->save_meta_data();
					$product_item_ids_by_variation_id[ $variation->get_id() ] = $product_item_ids[ $retailer_id ];
				}
			}
		}

		return $product_item_ids_by_variation_id;
	}


	/**
	 * Uses the Graph API to return a list of Product Item IDs indexed by the variation's retailer ID.
	 *  Returns a map of pairs
	 *  e.g.
	 *  (
	 *      `woo-vneck-tee-blue_28` -> `7344216055651160`,
	 *      `woo-vneck-tee-red_26`  -> `5102436146508829`
	 *  )
	 *
	 * @param string $product_group_id product group ID
	 *
	 * @return array a map of ( `retailer id` -> `id` ) pairs.
	 */
	private function find_variation_product_item_ids( string $product_group_id ): array {
		$product_item_ids = array();
		try {
			$response = $this->facebook_for_woocommerce->get_api()->get_product_group_products( $product_group_id );
			do {
				$product_item_ids += $response->get_ids();
				// get up to two additional pages of results
				$response = $this->facebook_for_woocommerce->get_api()->next( $response, 2 );
			} while ( $response );
		} catch ( ApiException $e ) {
			$message = sprintf( 'Meta APIs thrown APIException while fetching the Product Items in the Product Group %s: %s', $product_group_id, $e->getMessage() );
			Logger::log(
				$message,
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}

		return $product_item_ids;
	}

	/**
	 * Gets the total number of published products.
	 *
	 * @return int
	 */
	public function get_product_count() {
		$product_counts = wp_count_posts( 'product' );

		return $product_counts->publish;
	}

	/**
	 * Should full batch-API sync be allowed?
	 *
	 * May be used to disable various full sync UI/APIs to avoid performance impact.
	 *
	 * @return boolean True if full batch sync is safe.
	 * @since 2.6.1
	 */
	public function allow_full_batch_api_sync() {
		/**
		 * Block the full batch API sync.
		 *
		 * @param bool $block_sync Should the full batch API sync be blocked?
		 *
		 * @return boolean True if full batch sync should be blocked.
		 * @since 2.6.10
		 */
		$block_sync = apply_filters(
			'facebook_for_woocommerce_block_full_batch_api_sync',
			false
		);

		if ( $block_sync ) {
			return false;
		}

		$default_allow_sync = true;
		// If 'facebook_for_woocommerce_allow_full_batch_api_sync' is not used, prevent get_product_count from firing.
		if ( ! has_filter( 'facebook_for_woocommerce_allow_full_batch_api_sync' ) ) {
			return $default_allow_sync;
		}

		/**
		 * Allow full batch api sync to be enabled or disabled.
		 *
		 * @param bool $allow Default value - is full batch sync allowed?
		 * @param int $product_count Number of products in store.
		 *
		 * @return boolean True if full batch sync is safe.
		 *
		 * @since 2.6.1
		 * @deprecated deprecated since version 2.6.10
		 */
		return apply_filters_deprecated(
			'facebook_for_woocommerce_allow_full_batch_api_sync',
			array(
				$default_allow_sync,
				$this->get_product_count(),
			),
			'2.6.10',
			'facebook_for_woocommerce_block_full_batch_api_sync'
		);
	}

	/**
	 * Load DIA specific JS Data
	 */
	public function load_assets() {
		$ajax_data = array(
			'nonce' => wp_create_nonce( 'wc_facebook_infobanner_jsx' ),
		);
		// load banner assets
		wp_enqueue_script(
			'wc_facebook_infobanner_jsx',
			$this->facebook_for_woocommerce->get_asset_build_dir_url() . '/admin/infobanner.js',
			array(),
			\WC_Facebookcommerce::PLUGIN_VERSION,
			true
		);
		wp_localize_script( 'wc_facebook_infobanner_jsx', 'wc_facebook_infobanner_jsx', $ajax_data );

		if ( ! $this->facebook_for_woocommerce->is_plugin_settings() ) {
			return;
		}

		?>
		<script>
			window.facebookAdsToolboxConfig = {
				hasGzipSupport: '<?php echo extension_loaded( 'zlib' ) ? 'true' : 'false'; ?>',
				enabledPlugins: ['INSTAGRAM_SHOP', 'PAGE_SHOP'],
				enableSubscription: '<?php echo class_exists( 'WC_Subscriptions' ) ? 'true' : 'false'; ?>',
				popupOrigin: '<?php echo isset( $_GET['url'] ) ? esc_js( sanitize_text_field( wp_unslash( $_GET['url'] ) ) ) : 'https://www.facebook.com/'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>',
				feedWasDisabled: 'true',
				platform: 'WooCommerce',
				pixel: {
					pixelId: '<?php echo $this->get_facebook_pixel_id() ? esc_js( $this->get_facebook_pixel_id() ) : ''; ?>',
					advanced_matching_supported: true
				},
				diaSettingId: '<?php echo $this->get_external_merchant_settings_id() ? esc_js( $this->get_external_merchant_settings_id() ) : ''; ?>',
				store: {
					baseUrl: window.location.protocol + '//' + window.location.host,
					baseCurrency: '<?php echo esc_js( WC_Admin_Settings::get_option( 'woocommerce_currency' ) ); ?>',
					timezoneId: '<?php echo esc_js( date( 'Z' ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date ?>',
					storeName: '<?php echo esc_js( WC_Facebookcommerce_Utils::get_store_name() ); ?>',
					version: '<?php echo esc_js( WC()->version ); ?>',
					php_version: '<?php echo PHP_VERSION; ?>',
					plugin_version: '<?php echo esc_js( WC_Facebookcommerce_Utils::PLUGIN_VERSION ); ?>'
				},
				feed: {
					totalVisibleProducts: '<?php echo esc_js( $this->get_product_count() ); ?>',
					hasClientSideFeedUpload: '<?php echo esc_js( (bool) $this->get_feed_id() ); ?>',
					enabled: true,
					format: 'csv'
				},
				feedPrepared: {
					feedUrl: '<?php echo esc_url_raw( Feed::get_feed_data_url() ); ?>',
					feedPingUrl: '',
					feedMigrated: <?php echo $this->is_feed_migrated() ? 'true' : 'false'; ?>,
					samples: <?php echo $this->get_sample_product_feed(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				},
			};
		</script>
		<?php
		$ajax_data = array(
			'nonce' => wp_create_nonce( 'wc_facebook_settings_jsx' ),
		);
		wp_localize_script(
			'wc_facebook_settings_jsx',
			'wc_facebook_settings_jsx',
			$ajax_data
		);
		wp_enqueue_style(
			'wc_facebook_css',
			plugins_url(
				'/assets/css/facebook.css',
				__FILE__
			),
			array(),
			\WC_Facebookcommerce::PLUGIN_VERSION
		);
	}

	/**
	 * Gets the IDs of products marked for deletion from Facebook when removed from Sync.
	 *
	 * @return array
	 * @since 2.3.0
	 *
	 * @internal
	 */
	private function get_removed_from_sync_products_to_delete() {
		$posted_products = Helper::get_posted_value( WC_Facebook_Product::FB_REMOVE_FROM_SYNC );
		if ( empty( $posted_products ) ) {
			return array();
		}

		return array_map( 'absint', explode( ',', $posted_products ) );
	}


	/**
	 * Stores sync data in a transient for async processing.
	 *
	 * @param int   $wp_id The product ID
	 * @param array $sync_data  The sync data to store
	 * @return string The transient key used for storage
	 * @since 3.5.10
	 */
	private function get_store_sync_transient( int $wp_id, array $sync_data ): string {
		$sync_key = 'fb_sync_data_' . $wp_id . '_' . time();
		set_transient( $sync_key, $sync_data, 300 );
		return $sync_key;
	}

	/**
	 * Processes async sync data from transient and restores context.
	 *
	 * @param string $transient_key The transient key
	 * @return array|null Array containing product_id and success flag, or null if failed
	 * @since 3.5.10
	 */
	private function process_sync_transient( string $transient_key ): ?array {
		// Validate transient key format
		if ( strpos( $transient_key, 'fb_sync_data_' ) !== 0 ) {
			return null;
		}

		// Retrieve sync data
		$sync_data = get_transient( $transient_key );
		if ( ! $sync_data ) {
			return null; // Data expired or doesn't exist
		}

		// Clean up transient
		delete_transient( $transient_key );

		// Restore context
		$product_id = $sync_data['product_id'];
		$_POST      = $sync_data['post_data']; // Restore $_POST data

		return array(
			'product_id' => $product_id,
			'success'    => true,
		);
	}

	/**
	 * Handles async product save from WordPress cron by processing transient data.
	 *
	 * @param string $transient_key The transient key containing sync data
	 * @since 3.5.10
	 * @internal
	 */
	public function handle_async_product_save( string $transient_key ) {
		$transient_result = $this->process_sync_transient( $transient_key );
		if ( ! $transient_result ) {
			return; // Invalid transient or data expired
		}

		// Call the regular product save method with clean product ID
		$this->on_product_save( $transient_result['product_id'] );
	}

	/**
	 * Schedule product sync for async processing using WordPress cron.
	 *
	 * @param int $wp_id post ID
	 *
	 * @since 3.5.10
	 *
	 * @internal
	 */
	public function schedule_product_sync( int $wp_id ) {
		// Store sync data in transient for async processing
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$sync_data = array(
			'product_id' => $wp_id,
			'post_data'  => $_POST,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$sync_key = $this->get_store_sync_transient( $wp_id, $sync_data );

		// Schedule WordPress cron event
		$scheduled = wp_schedule_single_event(
			time() + 1,
			'wc_facebook_async_sync',
			array( $sync_key )
		);

		// Fallback to synchronous processing if scheduling fails
		if ( false === $scheduled ) {
			delete_transient( $sync_key );
			$this->on_product_save( $wp_id );
		}
	}

	/**
	 * Checks the product type and calls the corresponding on publish method.
	 *
	 * @param int $wp_id post ID
	 *
	 * @since 1.10.0
	 *
	 * @internal
	 */
	public function on_product_save( int $wp_id ) {
		$product = wc_get_product( $wp_id );
		if ( ! $product ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$sync_mode = isset( $_POST['wc_facebook_sync_mode'] )
			? sanitize_text_field( wp_unslash( $_POST['wc_facebook_sync_mode'] ) )
			: Admin::SYNC_MODE_SYNC_DISABLED;

		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$sync_enabled = Admin::SYNC_MODE_SYNC_DISABLED !== $sync_mode;

		if ( Admin::SYNC_MODE_SYNC_AND_SHOW === $sync_mode && $product->is_virtual() && 'bundle' !== $product->get_type() ) {
			// force to Sync and hide
			$sync_mode = Admin::SYNC_MODE_SYNC_AND_HIDE;
		}

		$products_to_delete_from_facebook = $this->get_removed_from_sync_products_to_delete();
		if ( $product->is_type( 'variable' ) ) {
			$this->save_variable_product_settings( $product );
			// check variations for deletion
			foreach ( $products_to_delete_from_facebook as $delete_product_id ) {
				$delete_product = wc_get_product( $delete_product_id );
				if ( empty( $delete_product ) ) {
					continue;
				}
				if ( Products::is_sync_enabled_for_product( $delete_product ) ) {
					continue;
				}
				$this->delete_fb_product( $delete_product );
			}
		}

		if ( $sync_enabled ) {
				Products::enable_sync_for_products( array( $product ) );
				Products::set_product_visibility( $product, Admin::SYNC_MODE_SYNC_AND_HIDE !== $sync_mode );
				$this->save_product_settings( $product );
		} else {
			// if previously enabled, add a notice on the next page load
			Products::disable_sync_for_products( array( $product ) );
			if ( in_array( $wp_id, $products_to_delete_from_facebook, true ) ) {
				$this->delete_fb_product( $product );
			}
		}
		if ( $sync_enabled ) {
			Admin\Products::save_commerce_fields( $product );
			switch ( $product->get_type() ) {
				case 'simple':
				case 'booking':
				case 'external':
				case 'composite':
					$this->on_simple_product_publish( $wp_id );
					break;
				case 'variable':
					$this->on_variable_product_publish( $wp_id );
					break;
				case 'subscription':
				case 'variable-subscription':
				case 'bundle':
					$this->on_product_publish( $wp_id );
					break;
			}
		}
	}

	/**
	 * Syncs a product to Facebook when it is created or updated through the WooCommerce REST API.
	 *
	 * The admin product editor schedules a sync via the woocommerce_process_product_meta hook, which
	 * WooCommerce only fires for the admin product form. REST API (and other programmatic) saves do
	 * not trigger that hook, so those changes never reached the Meta catalog. This handler runs on the
	 * woocommerce_update_product / woocommerce_new_product actions, which do fire for REST API saves,
	 * and queues the product for the background sync, honoring its existing sync settings.
	 *
	 * The woocommerce_update_product / woocommerce_new_product actions are low-level data-store hooks
	 * fired by WC_Product::save() for *any* product save, including ones made in unprivileged contexts
	 * such as a stock decrement during a Store API checkout. Two guards keep this handler scoped to a
	 * genuine product edit made over the REST API:
	 *
	 * - is_rest_api_request() excludes the admin editor and other request types, which are already
	 *   handled through their own hooks (and avoids double-processing admin saves).
	 * - current_user_can( 'edit_product' ) mirrors the capability the WooCommerce REST products
	 *   controller checks before a write, so guest/customer contexts (e.g. checkout stock changes over
	 *   the Store API) do not trigger a sync.
	 *
	 * @since 3.7.7
	 *
	 * @internal
	 *
	 * @param int $product_id the product ID
	 */
	public function on_product_save_via_api( $product_id ) {
		// The admin product editor and other request types are handled through their own hooks.
		if ( ! Helper::is_rest_api_request() ) {
			return;
		}

		// Only sync for actors allowed to edit the product; skip unprivileged contexts such as
		// stock changes made during a Store API checkout.
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		// bail if the plugin is not configured properly
		if ( ! $this->is_configured() || ! $this->get_product_catalog_id() ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$product_ids = array();

		if ( $product->is_type( 'variable' ) ) {
			// Variations carry the catalog items for a variable product; the parent is represented by
			// a product group, which the items batch derives from each variation's item_group_id.
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof WC_Product && $this->product_should_be_synced( $variation ) ) {
					$product_ids[] = $variation_id;
				}
			}
		} elseif ( $this->product_should_be_synced( $product ) ) {
			$product_ids[] = $product->get_id();
		}

		if ( empty( $product_ids ) ) {
			return;
		}

		// Queue rather than sync inline. The sync handler collects everything queued during the request
		// and dispatches a single background job on shutdown, so a batch REST update costs one job
		// instead of a Graph call per product inside the request — matching the admin, quick edit and
		// stock paths.
		$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_products( $product_ids );
	}

	/**
	 * Saves Facebook product attributes from POST data.
	 *
	 * @param WC_Facebook_Product $woo_product The Facebook product object
	 */
	private function save_facebook_product_attributes( $woo_product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ WC_Facebook_Product::FB_BRAND ] ) ) {
			$woo_product->set_fb_brand( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_BRAND ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_MPN ] ) ) {
			$woo_product->set_fb_mpn( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_MPN ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_SIZE ] ) ) {
			$woo_product->set_fb_size( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_SIZE ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_COLOR ] ) ) {
			$woo_product->set_fb_color( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_COLOR ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_MATERIAL ] ) ) {
			$woo_product->set_fb_material( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_MATERIAL ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_PATTERN ] ) ) {
			$woo_product->set_fb_pattern( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_PATTERN ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_AGE_GROUP ] ) ) {
			$woo_product->set_fb_age_group( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_AGE_GROUP ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_GENDER ] ) ) {
			$woo_product->set_fb_gender( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_GENDER ] ) ) );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_PRODUCT_CONDITION ] ) ) {
			$woo_product->set_fb_condition( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_PRODUCT_CONDITION ] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Saves the submitted Facebook settings for a variable product.
	 *
	 * @param \WC_Product $product The variable product object.
	 */
	private function save_variable_product_settings( $product ) {
		$woo_product = new WC_Facebook_Product( $product->get_id() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ WC_Facebook_Product::FB_PRODUCT_VIDEO ] ) ) {
			$attachment_ids = sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_PRODUCT_VIDEO ] ) );
			$woo_product->set_product_video_urls( $attachment_ids );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$this->save_facebook_product_attributes( $woo_product );
	}

	/**
	 * Saves the submitted Facebook settings for a product.
	 *
	 * @param \WC_Product $product the product object
	 *
	 * @since 1.10.0
	 */
	private function save_product_settings( WC_Product $product ) {
		$woo_product = new WC_Facebook_Product( $product->get_id() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ self::FB_PRODUCT_DESCRIPTION ] ) ) {
			$woo_product->set_description( sanitize_text_field( wp_unslash( $_POST[ self::FB_PRODUCT_DESCRIPTION ] ) ) );
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$woo_product->set_rich_text_description( $_POST[ self::FB_PRODUCT_DESCRIPTION ] );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ] ) ) {
			$woo_product->set_price( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ] ) ) );
		}

		if ( isset( $_POST['fb_product_image_source'] ) ) {
			$product->update_meta_data( Products::PRODUCT_IMAGE_SOURCE_META_KEY, sanitize_key( wp_unslash( $_POST['fb_product_image_source'] ) ) );
			$product->save_meta_data();
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_PRODUCT_IMAGE ] ) ) {
			$woo_product->set_product_image( sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_PRODUCT_IMAGE ] ) ) );
		}

		if ( isset( $_POST['fb_product_video_source'] ) ) {
			$product->update_meta_data( Products::PRODUCT_VIDEO_SOURCE_META_KEY, sanitize_key( wp_unslash( $_POST['fb_product_video_source'] ) ) );
			$product->save_meta_data();
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_PRODUCT_VIDEO ] ) ) {
			$attachment_ids = sanitize_text_field( wp_unslash( $_POST[ WC_Facebook_Product::FB_PRODUCT_VIDEO ] ) );
			$woo_product->set_product_video_urls( $attachment_ids );
		}

		if ( isset( $_POST[ WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url' ] ) ) {
			$product->update_meta_data( WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url', esc_url_raw( wp_unslash( $_POST[ WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url' ] ) ) );
			$product->save_meta_data();
		}

		$this->save_facebook_product_attributes( $woo_product );
	}

	/**
	 * Deletes a product from Facebook.
	 *
	 * @param int $product_id product ID
	 */
	public function on_product_delete( int $product_id ) {
		$product = wc_get_product( $product_id );

		// bail if product does not exist
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		/**
		 * Bail if not enabled for sync, except if explicitly deleting from the metabox or when deleting the
		 * parent product ( Products::published_product_should_be_synced( $product ) will fail for the parent product
		 * when deleting a variable product. This causes the fb_group_id to remain on the DB. )
		 *
		 * @see ajax_delete_fb_product()
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ( ! wp_doing_ajax() || ! isset( $_POST['action'] ) || 'ajax_delete_fb_product' !== $_POST['action'] )
			&& ! Products::published_product_should_be_synced( $product ) && ! $product->is_type( 'variable' ) ) {
			return;
		}

		$this->delete_fb_product( $product );
	}

	/**
	 * Deletes Facebook product.
	 *
	 * @param \WC_Product $product WooCommerce product object
	 *
	 * @since 2.3.0
	 *
	 * @internal
	 */
	public function delete_fb_product( $product ) {

		$product_id = $product->get_id();

		if ( $product->is_type( 'variation' ) || $product->is_type( 'simple' ) ) {
			$retailer_id = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product );
			// enqueue variation to be deleted in the background
			$this->facebook_for_woocommerce->get_products_sync_handler()->delete_products( array( $retailer_id ) );
		} elseif ( $product->is_type( 'variable' ) ) {
			$retailer_ids = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation instanceof \WC_Product ) {
					$retailer_ids[] = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $variation );
				}
				delete_post_meta( $variation_id, self::FB_PRODUCT_ITEM_ID );
			}
			// enqueue variations to be deleted in the background
			$this->facebook_for_woocommerce->get_products_sync_handler()->delete_products( $retailer_ids );
		}

		// clear out both item and group IDs
		delete_post_meta( $product_id, self::FB_PRODUCT_ITEM_ID );
		delete_post_meta( $product_id, self::FB_PRODUCT_GROUP_ID );
	}

	/**
	 * Updates Facebook Visibility upon trashing and restore.
	 *
	 * @param string   $new_status
	 * @param string   $old_status
	 * @param \WP_post $post
	 *
	 * @internal
	 */
	public function fb_change_product_published_status( $new_status, $old_status, $post ) {
		if ( ! $post ) {
			return;
		}

		if ( ! $this->should_update_visibility_for_product_status_change( $new_status, $old_status ) ) {
			return;
		}

		$product = wc_get_product( $post->ID );

		// Bail if we couldn't retrieve a valid product object or the product isn't enabled for sync
		//
		// Note that while moving a variable product to the trash, this method is called for each one of the
		// variations before it gets called with the variable product. As a result, Products::product_should_be_synced()
		// always returns false for the variable product (since all children are in the trash at that point).
		// This causes update_fb_visibility() to be called on simple products and product variations only.
		if ( ! $product instanceof \WC_Product || ( ! Products::published_product_should_be_synced( $product ) ) ) {
			return;
		}

		// Exclude variants. Product variables visibility is handled separately.
		// @See fb_restore_untrashed_variable_product.
		if ( $product->is_type( 'variant' ) ) {
			return;
		}

		$visibility = $product->is_visible() ? self::FB_SHOP_PRODUCT_VISIBLE : self::FB_SHOP_PRODUCT_HIDDEN;

		if ( self::FB_SHOP_PRODUCT_VISIBLE === $visibility ) {
			// - new status is 'publish' regardless of old status, sync to Facebook
			$this->on_product_publish( $product->get_id() );
		} else {
			$this->update_fb_visibility( $product, $visibility );
		}
	}

	/**
	 * Re-publish restored variable product.
	 *
	 * @param int $post_id
	 *
	 * @internal
	 */
	public function fb_restore_untrashed_variable_product( $post_id ) {
		$product = wc_get_product( $post_id );

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return;
		}

		$visibility = $product->is_visible() ? self::FB_SHOP_PRODUCT_VISIBLE : self::FB_SHOP_PRODUCT_HIDDEN;

		if ( self::FB_SHOP_PRODUCT_VISIBLE === $visibility ) {
			// - new status is 'publish' regardless of old status, sync to Facebook
			$this->on_product_publish( $product->get_id() );
		}
	}


	/**
	 * Determines whether the product visibility needs to be updated for the given status change.
	 *
	 * Change from publish status -> unpublish status (e.g. trash, draft, etc.)
	 * Change from trash status -> publish status
	 * No need to update for change from trash <-> unpublish status
	 *
	 * @param string $new_status
	 * @param string $old_status
	 *
	 * @return bool
	 * @since 2.0.2
	 */
	private function should_update_visibility_for_product_status_change( $new_status, $old_status ) {
		return ( 'publish' === $old_status && 'publish' !== $new_status ) || ( 'trash' === $old_status && 'publish' === $new_status ) || ( 'future' === $old_status && 'publish' === $new_status );
	}

	/**
	 * Deletes a product from Facebook when status is changed to draft.
	 *
	 * @param \WP_post $post
	 *
	 * @since 3.0.27
	 */
	public function delete_draft_product( $post ) {

		if ( ! $post ) {
			return;
		}

		$this->on_product_delete( $post->ID );
	}


	/**
	 * Generic function for use with any product publishing.
	 *
	 * Will determine product type (simple or variable) and delegate to
	 * appropriate handler.
	 *
	 * @param int $product_id product ID
	 */
	public function on_product_publish( $product_id ) {
		// bail if the plugin is not configured properly
		if ( ! $this->is_configured() || ! $this->get_product_catalog_id() ) {
			return;
		}

		$product = wc_get_product( $product_id );

		if ( $product->is_type( 'variable' ) ) {
			$this->on_variable_product_publish( $product_id );
		} else {
			$this->on_simple_product_publish( $product_id );
		}
	}

	/**
	 * Syncs product to Facebook when saving a variable product.
	 *
	 * @param int                      $wp_id product post ID
	 * @param WC_Facebook_Product|null $woo_product product object
	 */
	public function on_variable_product_publish( $wp_id, $woo_product = null ) {
		if ( ! $woo_product instanceof \WC_Facebook_Product ) {
			$woo_product = new \WC_Facebook_Product( $wp_id );
		}

		if ( ! $this->product_should_be_synced( $woo_product->woo_product ) ) {
			return;
		}

		$retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id( $woo_product->woo_product );
		$this->create_product_group( $woo_product, $retailer_id );

		$variation_ids = array();

		// scheduled update for each variation that should be synced
		foreach ( $woo_product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product && $this->product_should_be_synced( $variation ) ) {
				$variation_ids[] = $variation_id;
			}
		}

		$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_products( $variation_ids );
	}

	/**
	 * Syncs product to Facebook when saving a simple product.
	 *
	 * @param int                      $wp_id product post ID
	 * @param WC_Facebook_Product|null $woo_product product object
	 * @param WC_Facebook_Product|null $parent_product parent object
	 *
	 * @return int|mixed|void|null
	 */
	public function on_simple_product_publish( $wp_id, $woo_product = null, &$parent_product = null ) {
		if ( ! $woo_product instanceof \WC_Facebook_Product ) {
			$woo_product = new \WC_Facebook_Product( $wp_id, $parent_product );
		}

		if ( ! $this->product_should_be_synced( $woo_product->woo_product ) ) {
			return;
		}
		return $this->create_product_simple( $woo_product );  // new product
	}

	/**
	 * Determines whether the product with the given ID should be synced.
	 *
	 * @param WC_Product $product product object
	 *
	 * @since 2.0.0
	 */
	public function product_should_be_synced( WC_Product $product ): bool {
		try {
			$this->facebook_for_woocommerce->get_product_sync_validator( $product )->validate();

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Create product group and product, store fb-specific info.
	 *
	 * @param WC_Facebook_Product $woo_product
	 *
	 * @return string
	 */
	public function create_product_simple( WC_Facebook_Product $woo_product ): string {
		$retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id( $woo_product );
		return $this->create_product_item_batch_api( $woo_product, $retailer_id );
	}

	/**
	 * @param WC_Facebook_Product $woo_product
	 * @param string              $retailer_id
	 *
	 * @return ?string
	 */
	public function create_product_group( WC_Facebook_Product $woo_product, string $retailer_id ): ?string {
		$product_group_data             = array(
			'retailer_id' => $retailer_id,
		);
		$product_group_data['variants'] = $woo_product->prepare_variants_for_group();

		try {
			$create_product_group_result = $this->facebook_for_woocommerce->get_api()->create_product_group(
				$this->get_product_catalog_id(),
				$product_group_data
			);

			// New variant added
			if ( $create_product_group_result->id ) {
				$fb_product_group_id = $create_product_group_result->id;
				update_post_meta(
					$woo_product->get_id(),
					self::FB_PRODUCT_GROUP_ID,
					(string) $fb_product_group_id
				);

				return $fb_product_group_id;
			}
		} catch ( ApiException $e ) {
			$message = sprintf( 'There was an error trying to create the product group: %s', $e->getMessage() );
			Logger::log(
				$message,
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}

		return null;
	}

	/**
	 * Creates a product item using the facebook Catalog Batch API. This replaces existing functionality,
	 * which is currently using facebook Product Item API implemented by `WC_Facebookcommerce_Integration::create_product_item`
	 *
	 * @param WC_Facebook_Product $woo_product
	 * @param string              $retailer_id
	 * *@since 3.1.7
	 */
	public function create_product_item_batch_api( $woo_product, $retailer_id ): string {
		try {
			$product_data        = $woo_product->prepare_product( $retailer_id, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
			$requests            = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch( $product_data );
			$facebook_catalog_id = $this->get_product_catalog_id();
			$response            = $this->facebook_for_woocommerce->get_api()->send_item_updates( $facebook_catalog_id, $requests );

			if ( $response->handles ) {
				return '';
			} else {
				$this->display_error_message(
					'Updated product on Facebook has failed.'
				);
			}
		} catch ( ApiException $e ) {
			$message = sprintf( 'There was an error trying to create a product item: %s', $e->getMessage() );
			Logger::log(
				$message,
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}

		return '';
	}

	public function create_product_item( $woo_product, $retailer_id, $product_group_id ): string {
		try {
			$product_data   = $woo_product->prepare_product( $retailer_id );
			$product_result = $this->facebook_for_woocommerce->get_api()->create_product_item( $product_group_id, $product_data );

			if ( $product_result->id ) {
				$fb_product_item_id = $product_result->id;

				update_post_meta(
					$woo_product->get_id(),
					self::FB_PRODUCT_ITEM_ID,
					(string) $fb_product_item_id
				);

				$this->display_success_message(
					'Created product item <a href="https://facebook.com/' .
					$fb_product_item_id . '" target="_blank">' .
					$fb_product_item_id . '</a> on Facebook.'
				);

				return $fb_product_item_id;
			}
		} catch ( ApiException $e ) {
			$message = sprintf( 'There was an error trying to create a product item: %s', $e->getMessage() );
			Logger::log(
				$message,
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}

		return '';
	}

	/**
	 * Determines if there is a matching variation for the default attributes.
	 * Select closest matching if best can't be found.
	 *
	 * @param WC_Facebook_Product $woo_product
	 * @param string              $fb_product_group_id
	 *
	 * @return integer|null Facebook Catalog variation id.
	 * @since 2.1.2
	 *
	 * @since 2.6.6
	 * The algorithm only considers the variations that already have been synchronized to the catalog successfully.
	 */
	private function get_product_group_default_variation( WC_Facebook_Product $woo_product, string $fb_product_group_id ) {
		$default_attributes = $woo_product->woo_product->get_default_attributes( 'edit' );
		$default_variation  = null;

		// Fetch variations that exist in the catalog.
		$existing_catalog_variations              = $this->find_variation_product_item_ids( $fb_product_group_id );
		$existing_catalog_variations_retailer_ids = array_keys( $existing_catalog_variations );

		// All woocommerce variations for the product.
		$product_variations = $woo_product->woo_product->get_available_variations();

		if ( ! empty( $default_attributes ) ) {

			$best_match_count = 0;
			foreach ( $product_variations as $variation ) {

				$fb_retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id(
					wc_get_product(
						$variation['variation_id']
					)
				);

				// Check if currently processed variation exist in the catalog.
				if ( ! in_array( $fb_retailer_id, $existing_catalog_variations_retailer_ids, true ) ) {
					continue;
				}

				$variation_attributes       = $this->get_product_variation_attributes( $variation );
				$variation_attributes_count = count( $variation_attributes );
				$matching_attributes_count  = count( array_intersect_assoc( $default_attributes, $variation_attributes ) );

				// Check how much current variation matches the selected default attributes.
				if ( $matching_attributes_count === $variation_attributes_count ) {
					// We found a perfect match;
					$default_variation = $existing_catalog_variations[ $fb_retailer_id ];
					break;
				}
				if ( $matching_attributes_count > $best_match_count ) {
					// We found a better match.
					$default_variation = $existing_catalog_variations[ $fb_retailer_id ];
				}
			}
		}

		/**
		 * Filter product group default variation.
		 * This can be used to customize the choice of a default variation (e.g. choose one with the lowest price).
		 *
		 * @param integer|null Facebook Catalog variation id.
		 * @param \WC_Facebook_Product WooCommerce product.
		 * @param string product group ID.
		 * @param array List of available WC_Product variations.
		 * @param array List of Product Item IDs indexed by the variation's retailer ID.
		 *
		 * @since 2.6.25
		 */
		return apply_filters(
			'wc_facebook_product_group_default_variation',
			$default_variation,
			$woo_product,
			$fb_product_group_id,
			$product_variations,
			$existing_catalog_variations
		);
	}

	/**
	 * Parses given product variation for it's attributes
	 *
	 * @param array $variation
	 *
	 * @return array
	 * @since 2.1.2
	 */
	private function get_product_variation_attributes( array $variation ): array {
		$final_attributes     = array();
		$variation_attributes = $variation['attributes'];

		foreach ( $variation_attributes as $attribute_name => $attribute_value ) {
			$final_attributes[ str_replace( 'attribute_', '', $attribute_name ) ] = $attribute_value;
		}

		return $final_attributes;
	}

	/**
	 * Update existing product using batch API.
	 *
	 * @param WC_Facebook_Product $woo_product
	 * @param string              $fb_product_item_id
	 *
	 * @return void
	 */
	public function update_product_item_batch_api( WC_Facebook_Product $woo_product, string $fb_product_item_id ): void {
		$product  = $woo_product->prepare_product( null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
		$requests = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch( $product );

		try {
			$facebook_catalog_id = $this->get_product_catalog_id();
			$response            = $this->facebook_for_woocommerce->get_api()->send_item_updates( $facebook_catalog_id, $requests );
			if ( $response->handles ) {
				$this->display_success_message(
					'Updated product  <a href="https://facebook.com/' . $fb_product_item_id .
					'" target="_blank">' . $fb_product_item_id . '</a> on Facebook.'
				);
			} else {
				$this->display_error_message(
					'Updated product  <a href="https://facebook.com/' . $fb_product_item_id .
					'" target="_blank">' . $fb_product_item_id . '</a> on Facebook has failed.'
				);
			}
		} catch ( ApiException $e ) {
			$message = sprintf( 'There was an error trying to update a product item: %s', $e->getMessage() );
			Logger::log(
				$message,
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}
	}

	/**
	 * Update existing product.
	 *
	 * @param WC_Facebook_Product $woo_product
	 * @param string              $fb_product_item_id
	 *
	 * @return void
	 */
	public function update_product_item( WC_Facebook_Product $woo_product, string $fb_product_item_id ): void {
		$product_data = $woo_product->prepare_product();

		// send an empty string to clear the additional_image_urls property if the product has no additional images
		if ( empty( $product_data['additional_image_urls'] ) ) {
			$product_data['additional_image_urls'] = '';
		}
		try {
			$result = $this->facebook_for_woocommerce->get_api()->update_product_item( $fb_product_item_id, $product_data );
			if ( $result->success ) {
				$this->display_success_message(
					'Updated product  <a href="https://facebook.com/' . $fb_product_item_id .
					'" target="_blank">' . $fb_product_item_id . '</a> on Facebook.'
				);
			} else {
				$this->display_error_message(
					'Updated product  <a href="https://facebook.com/' . $fb_product_item_id .
					'" target="_blank">' . $fb_product_item_id . '</a> on Facebook has failed.'
				);
			}
		} catch ( ApiException $e ) {
			$message = sprintf( 'There was an error trying to update a product item: %s', $e->getMessage() );
			Logger::log(
				$message,
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
		}
	}

	/**
	 * Displays a banner for unmapped attributes encouraging users to use the attribute mapper.
	 *
	 * @param \WC_Product $product The product object
	 * @return void
	 */
	public function display_unmapped_attributes_banner( \WC_Product $product ) {
		// Check if this feature should be shown
		if ( ! $this->should_show_unmapped_attributes_banner() ) {
			return;
		}

		// Get unmapped attributes using the ProductAttributeMapper
		if ( ! class_exists( '\WooCommerce\Facebook\ProductAttributeMapper' ) ) {
			return;
		}

		$unmapped_attributes = \WooCommerce\Facebook\ProductAttributeMapper::get_unmapped_attributes( $product );

		// Only show if there are unmapped attributes
		if ( empty( $unmapped_attributes ) ) {
			return;
		}

		$count = count( $unmapped_attributes );

		// Convert attribute names to user-friendly labels
		$attribute_labels = array();
		foreach ( $unmapped_attributes as $attribute ) {
			$attribute_name = $attribute['name'];
			// Get the user-friendly label for the attribute
			$label = wc_attribute_label( $attribute_name );
			// If no label found, clean up the name by removing pa_ prefix
			if ( $label === $attribute_name && strpos( $attribute_name, 'pa_' ) === 0 ) {
				$label = ucfirst( str_replace( array( 'pa_', '_', '-' ), array( '', ' ', ' ' ), $attribute_name ) );
			}
			$attribute_labels[] = $label;
		}

		$attribute_list = implode( ', ', array_slice( $attribute_labels, 0, 3 ) );
		if ( $count > 3 ) {
			/* translators: %d: number of additional unmapped attributes */
			$attribute_list .= sprintf( __( ' and %d more', 'facebook-for-woocommerce' ), $count - 3 );
		}

		// Build the mapper URL
		$mapper_url = add_query_arg(
			array(
				'page' => 'wc-facebook',
				'tab'  => 'product-attributes',
			),
			admin_url( 'admin.php' )
		);

		$message = sprintf(
			/* translators: %1$s - attribute list, %2$d - count, %3$s - link start, %4$s - link end */
			_n(
				'%3$s%2$d attribute "%1$s" is not mapped to Meta.%4$s Use the %3$sattribute mapper%4$s to map this attribute and improve your product visibility in Meta ads.',
				'%3$s%2$d attributes (%1$s) are not mapped to Meta.%4$s Use the %3$sattribute mapper%4$s to map these attributes and improve your product visibility in Meta ads.',
				$count,
				'facebook-for-woocommerce'
			),
			$attribute_list,
			$count,
			'<a href="' . esc_url( $mapper_url ) . '" target="_blank">',
			'</a>'
		);

		// Store the message with a specific prefix to identify it
		$banner_message = self::FB_ADMIN_MESSAGE_PREPEND . $message;
		set_transient(
			'facebook_plugin_unmapped_attributes_info',
			$banner_message,
			self::FB_MESSAGE_DISPLAY_TIME
		);
	}

	/**
	 * Determines if the unmapped attributes banner should be shown.
	 *
	 * @return bool
	 */
	private function should_show_unmapped_attributes_banner() {
		// Only show to users who can manage WooCommerce
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks the feed upload status (FBE v1.0).
	 *
	 * @internal
	 */
	public function ajax_check_feed_upload_status() {
		$response = array(
			'connected' => true,
			'status'    => 'complete',
		);
		printf( wp_json_encode( $response ) );
		wp_die();
	}

	/**
	 * Check Feed Upload Status (FBE v2.0)
	 * TODO: When migrating to FBE v2.0, remove above function and rename
	 * below function to ajax_check_feed_upload_status()
	 **/
	public function ajax_check_feed_upload_status_v2() {
		\WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'check feed upload status', true );
		check_ajax_referer( 'wc_facebook_settings_jsx' );
		if ( $this->is_configured() ) {
			$response = array(
				'connected' => true,
				'status'    => 'in progress',
			);

			if ( ! empty( $this->get_upload_id() ) ) {

				if ( ! isset( $this->fbproductfeed ) ) {

					if ( ! class_exists( 'WC_Facebook_Product_Feed' ) ) {
						include_once 'includes/fbproductfeed.php';
					}

					$this->fbproductfeed = new \WC_Facebook_Product_Feed();
				}

				$status = $this->fbproductfeed->is_upload_complete( $this->settings );

				$response['status'] = $status;
			} else {
				$response = array(
					'connected' => true,
					'status'    => 'error',
				);
			}

			if ( 'complete' === $response['status'] ) {
				update_option(
					$this->get_option_key(),
					apply_filters(
						'woocommerce_settings_api_sanitized_fields_' . $this->id,
						$this->settings
					)
				);
			}
		} else {
			$response = array( 'connected' => false );
		}
		printf( wp_json_encode( $response ) );
		wp_die();
	}

	/**
	 * Display custom success message (sugar).
	 *
	 * @param string $msg
	 *
	 * @return void
	 * @deprecated 2.1.0
	 */
	public function display_success_message( string $msg ): void {
		$msg = self::FB_ADMIN_MESSAGE_PREPEND . $msg;
		set_transient(
			'facebook_plugin_api_success',
			$msg,
			self::FB_MESSAGE_DISPLAY_TIME
		);
	}

	/**
	 * Display custom info message (sugar).
	 *
	 * @param string $msg
	 *
	 * @return void
	 */
	public function display_info_message( string $msg ): void {
		$msg = self::FB_ADMIN_MESSAGE_PREPEND . $msg;
		set_transient(
			'facebook_plugin_api_info',
			$msg,
			self::FB_MESSAGE_DISPLAY_TIME
		);
	}

	/**
	 * Display custom "sticky" info message.
	 * Call remove_sticky_message or wait for time out.
	 *
	 * @param string $msg
	 *
	 * @return void
	 */
	public function display_sticky_message( string $msg ): void {
		$msg = self::FB_ADMIN_MESSAGE_PREPEND . $msg;
		set_transient(
			'facebook_plugin_api_sticky',
			$msg,
			self::FB_MESSAGE_DISPLAY_TIME
		);
	}

	/**
	 * Remove custom "sticky" info message.
	 *
	 * @return void
	 */
	public function remove_sticky_message() {
		delete_transient( 'facebook_plugin_api_sticky' );
	}

	/**
	 * Remove 'resync' message.
	 *
	 * @return void
	 */
	public function remove_resync_message() {
		$msg = get_transient( 'facebook_plugin_api_sticky' );
		if ( $msg && strpos( $msg, 'Sync' ) !== false ) {
			delete_transient( 'facebook_plugin_resync_sticky' );
		}
	}

	/**
	 * Logs and stores custom error message (sugar).
	 *
	 * @param string $msg
	 *
	 * @return void
	 */
	public function display_error_message( string $msg ): void {
		Logger::log(
			$msg,
			array(),
			array(
				'should_send_log_to_meta'        => false,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
			)
		);
		set_transient( 'facebook_plugin_api_error', $msg, self::FB_MESSAGE_DISPLAY_TIME );
	}

	/**
	 * Displays out of sync message if products are edited using WooCommerce Advanced Bulk Edit.
	 *
	 * @param string $import_id
	 *
	 * @return void
	 */
	public function ajax_woo_adv_bulk_edit_compat( string $import_id ): void {
		if ( ! WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'adv bulk edit', false ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

		if ( strpos( $type, 'product' ) !== false && strpos( $type, 'load' ) === false ) {
			$this->display_out_of_sync_message( 'advanced bulk edit' );
		}
	}

	/**
	 * Display import message.
	 *
	 * @param string $import_id
	 *
	 * @return void
	 */
	public function wp_all_import_compat( string $import_id ): void {
		$import = new PMXI_Import_Record();
		$import->getById( $import_id );
		if ( ! $import->isEmpty() && in_array(
			$import->options['custom_type'],
			array(
				'product',
				'product_variation',
			),
			true
		) ) {
			$this->display_out_of_sync_message( 'import' );
		}
	}

	/**
	 * Displays out of sync message.
	 *
	 * @param string $action_name
	 *
	 * @return void
	 */
	public function display_out_of_sync_message( string $action_name ): void {
		$this->display_sticky_message(
			sprintf(
				'Products may be out of Sync with Facebook due to your recent ' . $action_name . '.' .
				' <a href="%s&fb_force_resync=true&remove_sticky=true">Re-Sync them with FB.</a>',
				$this->facebook_for_woocommerce->get_settings_url()
			)
		);
	}

	/**
	 * If we get a product group ID or product item ID back for a dupe retailer
	 * id error, update existing ID.
	 *
	 * @param stdClass $error_data
	 * @param int      $wpid
	 *
	 * @return null
	 **/
	public function get_existing_fbid( stdClass $error_data, int $wpid ) {
		if ( isset( $error_data->product_group_id ) ) {
			update_post_meta(
				$wpid,
				self::FB_PRODUCT_GROUP_ID,
				(string) $error_data->product_group_id
			);

			return $error_data->product_group_id;
		} elseif ( isset( $error_data->product_item_id ) ) {
			update_post_meta(
				$wpid,
				self::FB_PRODUCT_ITEM_ID,
				(string) $error_data->product_item_id
			);

			return $error_data->product_item_id;
		} else {
			return null;
		}
	}

	/**
	 * Checks for API key and other API errors.
	 */
	public function checks() {
		// TODO improve this by checking the settings page with Framework method and ensure error notices are displayed under the Integration sections {FN 2020-01-30}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && 'wc-facebook' === $_GET['page'] ) {
			$this->display_errors();
		}

		$this->maybe_display_facebook_api_messages();
	}

	/**
	 * Gets a sample feed with up to 12 published products.
	 *
	 * @return string
	 */
	public function get_sample_product_feed() {
		ob_start();
		// get up to 12 published posts that are products
		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'fields'         => 'ids',
		);

		$post_ids = get_posts( $args );
		$items    = array();

		foreach ( $post_ids as $post_id ) {
			$woo_product  = new WC_Facebook_Product( $post_id );
			$product_data = $woo_product->prepare_product();

				$feed_item = array(
					'title'        => wp_strip_all_tags( $product_data['name'] ),
					'availability' => $woo_product->is_in_stock() ? 'in stock' :
						'out of stock',
					'description'  => wp_strip_all_tags( $product_data['description'] ),
					'id'           => $product_data['retailer_id'],
					'image_link'   => $product_data['image_url'],
					'brand'        => Helper::str_truncate( wp_strip_all_tags( WC_Facebookcommerce_Utils::get_store_name() ), 100 ),
					'link'         => $product_data['url'],
					'price'        => $product_data['price'] . ' ' . get_woocommerce_currency(),
				);
				array_push( $items, $feed_item );
		}
		// https://codex.wordpress.org/Function_Reference/wp_reset_postdata
		wp_reset_postdata();
		ob_end_clean();

		return wp_json_encode( array( $items ) );
	}

	/**
	 * Checks if a meta key affects Facebook sync and should trigger last change time update.
	 *
	 * @param string $meta_key Meta key to check.
	 * @return bool True if the meta key is relevant to Facebook sync.
	 * @since 3.5.8
	 */
	private function is_product_attribute_sync_relevant( $meta_key ) {
		// Skip our own meta keys to prevent infinite loops
		if ( in_array( $meta_key, array( '_last_change_time', '_fb_sync_last_time' ), true ) ) {
			return false;
		}

		// Skip WordPress internal meta keys
		if ( strpos( $meta_key, '_wp_' ) === 0 || strpos( $meta_key, '_edit_' ) === 0 ) {
			return false;
		}

		return in_array( $meta_key, self::PRODUCT_ATTRIBUTE_SYNC_RELEVANT_META_KEYS, true );
	}

	/**
	 * Validates if the product and meta key should trigger a last change time update.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $meta_key   Meta key.
	 * @return bool True if update should proceed, false otherwise.
	 * @since 3.5.8
	 */
	private function should_update_product_change_time( $product_id, $meta_key ) {
		$product_id = absint( $product_id );
		$meta_key   = sanitize_key( $meta_key );

		// Check if this is a WooCommerce product
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// Check if meta key is relevant for Facebook sync
		if ( ! $this->is_product_attribute_sync_relevant( $meta_key ) ) {
			return false;
		}

		// Check rate limiting
		if ( $this->is_last_change_time_update_rate_limited( $product_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Performs the actual product last change time update with proper flag management.
	 *
	 * @param int $product_id Product ID.
	 * @since 3.5.8
	 */
	private function perform_product_last_change_time_update( $product_id ) {
		// Set flag to prevent infinite loops
		self::$is_updating_last_change_time = true;

		try {
			$current_time = time();

			// Update the database
			update_post_meta( $product_id, '_last_change_time', $current_time );

			// Update cache for rate limiting
			$this->set_last_change_time_cache( $product_id, $current_time );

		} finally {
			// Always reset flag, even if update fails
			self::$is_updating_last_change_time = false;
		}
	}

	/**
	 * Updates the _last_change_time meta field when wp_postmeta table is updated.
	 *
	 * @param int    $meta_id    ID of the metadata entry to update.
	 * @param int    $product_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @since 3.5.8
	 */
	public function update_product_last_change_time( $meta_id, $product_id, $meta_key, $meta_value ) {
		// Guard against infinite loops
		if ( self::$is_updating_last_change_time ) {
			return;
		}

		try {
			// Run all validation checks first
			if ( ! $this->should_update_product_change_time( $product_id, $meta_key ) ) {
				return;
			}

			// All checks passed - proceed with update
			$this->perform_product_last_change_time_update( $product_id );

		} catch ( \Exception $e ) {
			// Ensure flag is reset even on exception
			self::$is_updating_last_change_time = false;
		}
	}

	/**
	 * Checks if the last change time update is rate limited for a product.
	 *
	 * @param int $product_id Product ID.
	 * @return bool True if rate limited, false otherwise.
	 * @since 3.5.8
	 */
	private function is_last_change_time_update_rate_limited( $product_id ) {
		$cache_key   = "last_change_time_{$product_id}";
		$cached_time = wp_cache_get( $cache_key, 'facebook_for_woocommerce' );

		// If no cached time, allow update
		if ( false === $cached_time ) {
			return false;
		}

		// Rate limit to once every 60 seconds (1 minute)
		$rate_limit_window = 60;
		$current_time      = time();

		// If the last update was within the rate limit window, prevent update
		return ( $current_time - $cached_time ) < $rate_limit_window;
	}

	/**
	 * Sets the last change time in cache for rate limiting.
	 *
	 * @param int $product_id Product ID.
	 * @param int $timestamp Timestamp to cache.
	 * @since 3.5.8
	 */
	private function set_last_change_time_cache( $product_id, $timestamp ) {
		$cache_key = "last_change_time_{$product_id}";
		// Cache for 2 minutes (120 seconds) to ensure it persists longer than the rate limit window
		wp_cache_set( $cache_key, $timestamp, 'facebook_for_woocommerce', 120 );
	}

	/**
	 * Loop through array of WPIDs to remove metadata.
	 *
	 * @param array $products
	 */
	public function delete_post_meta_loop( array $products ) {
		foreach ( $products as $product_id ) {
			delete_post_meta( $product_id, self::FB_PRODUCT_GROUP_ID );
			delete_post_meta( $product_id, self::FB_PRODUCT_ITEM_ID );
			delete_post_meta( $product_id, Products::VISIBILITY_META_KEY );
		}
	}

	/**
	 * Remove FBIDs from all products when resetting store.
	 **/
	public function reset_all_products() {
		if ( ! is_admin() ) {
			Logger::log(
				'Not resetting any FBIDs from products, must call reset from admin context.',
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				)
			);
			return false;
		}

		// Include draft products (omit 'post_status' => 'publish')
		Logger::log(
			'Removing FBIDs from all products',
			array(),
			array(
				'should_send_log_to_meta'        => false,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
			)
		);

		$post_ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => - 1,
				'fields'         => 'ids',
			)
		);

		$children = array();
		foreach ( $post_ids as $post_id ) {
			$children = array_merge(
				get_posts(
					array(
						'post_type'      => 'product_variation',
						'posts_per_page' => - 1,
						'post_parent'    => $post_id,
						'fields'         => 'ids',
					)
				),
				$children
			);
		}
		$post_ids = array_merge( $post_ids, $children );
		$this->delete_post_meta_loop( $post_ids );

		Logger::log(
			'Product FBIDs deleted',
			array(),
			array(
				'should_send_log_to_meta'        => false,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
			)
		);

		return true;
	}

	/**
	 * Remove FBIDs from a single WC product
	 *
	 * @param int $wp_id
	 */
	public function reset_single_product( int $wp_id ) {
		$woo_product = new WC_Facebook_Product( $wp_id );
		$products    = array( $woo_product->get_id() );
		if ( WC_Facebookcommerce_Utils::is_variable_type( $woo_product->get_type() ) ) {
			$products = array_merge( $products, $woo_product->get_children() );
		}

		$this->delete_post_meta_loop( $products );

		Logger::log(
			'Deleted FB Metadata for product ' . $wp_id,
			array(),
			array(
				'should_send_log_to_meta'        => false,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
			)
		);
	}

	/**
	 * Ajax reset all Facebook products.
	 *
	 * @return void
	 */
	public function ajax_reset_all_fb_products() {
		WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'reset products', true );
		check_ajax_referer( 'wc_facebook_settings_jsx' );
		$this->reset_all_products();
		wp_reset_postdata();
		wp_die();
	}

	/**
	 * Ajax reset single Facebook product.
	 *
	 * @return void
	 */
	public function ajax_reset_single_fb_product() {
		WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'reset single product', true );
		check_ajax_referer( 'wc_facebook_metabox_jsx' );
		if ( ! isset( $_POST['wp_id'] ) ) {
			wp_die();
		}

		$wp_id       = sanitize_text_field( wp_unslash( $_POST['wp_id'] ) );
		$woo_product = new WC_Facebook_Product( $wp_id );
		if ( $woo_product ) {
			$this->reset_single_product( $wp_id );
		}

		wp_reset_postdata();
		wp_die();
	}

	/**
	 * Ajax delete Facebook product.
	 *
	 * @return void
	 */
	public function ajax_delete_fb_product() {
		WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'delete single product', true );
		check_ajax_referer( 'wc_facebook_metabox_jsx' );
		if ( ! isset( $_POST['wp_id'] ) ) {
			wp_die();
		}

		$wp_id = sanitize_text_field( wp_unslash( $_POST['wp_id'] ) );
		$this->on_product_delete( $wp_id );
		$this->reset_single_product( $wp_id );
		wp_reset_postdata();
		wp_die();
	}

	/**
	 * AJAX handler for dismissing the unmapped attributes banner.
	 *
	 * @return void
	 */
	public function ajax_dismiss_unmapped_attributes_banner() {
		// Check permissions
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1, 403 );
		}

		// Check nonce
		check_ajax_referer( 'fb_dismiss_unmapped_attributes_banner' );

		// Clear the transient (but don't set permanent user meta)
		// This way the banner will show again next time there are unmapped attributes
		delete_transient( 'facebook_plugin_unmapped_attributes_info' );

		wp_die();
	}

	/**
	 * Toggles product visibility via AJAX.
	 *
	 * @internal
	 * @deprecated since 1.10.0
	 **/
	public function ajax_fb_toggle_visibility() {
		wc_deprecated_function( __METHOD__, '1.10.0' );
	}

	/** Getter methods ************************************************************************************************/

	/**
	 * Gets the product catalog ID.
	 *
	 * @return string
	 * @since 1.10.0
	 */
	public function get_product_catalog_id() {
		$value = get_option( self::OPTION_PRODUCT_CATALOG_ID, '' );

		/**
		 * Filters the Facebook product catalog ID.
		 *
		 * @param string $product_catalog_id Facebook product catalog ID
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return apply_filters( 'wc_facebook_product_catalog_id', is_string( $value ) ? $value : '', $this );
	}

	/**
	 * Gets the external merchant settings ID.
	 *
	 * @return string
	 * @since 1.10.0
	 */
	public function get_external_merchant_settings_id() {
		if ( ! is_string( $this->external_merchant_settings_id ) ) {
			$value                               = get_option( self::OPTION_EXTERNAL_MERCHANT_SETTINGS_ID, '' );
			$this->external_merchant_settings_id = is_string( $value ) ? $value : '';
		}

		/**
		 * Filters the Facebook external merchant settings ID.
		 *
		 * @param string $external_merchant_settings_id Facebook external merchant settings ID
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (string) apply_filters( 'wc_facebook_external_merchant_settings_id', $this->external_merchant_settings_id, $this );
	}

	/**
	 * Gets the feed ID.
	 *
	 * @return string
	 * @since 1.10.0
	 */
	public function get_feed_id() {
		if ( ! is_string( $this->feed_id ) ) {
			$value         = get_option( self::OPTION_FEED_ID, '' );
			$this->feed_id = is_string( $value ) ? $value : '';
		}

		/**
		 * Filters the Facebook feed ID.
		 *
		 * @param string $feed_id Facebook feed ID
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (string) apply_filters( 'wc_facebook_feed_id', $this->feed_id, $this );
	}

	/***
	 * Gets the Facebook Upload ID.
	 *
	 * @return string
	 * @since 1.11.0
	 */
	public function get_upload_id() {
		if ( ! is_string( $this->upload_id ) ) {
			$value           = get_option( self::OPTION_UPLOAD_ID, '' );
			$this->upload_id = is_string( $value ) ? $value : '';
		}

		/**
		 * Filters the Facebook upload ID.
		 *
		 * @param string $upload_id Facebook upload ID
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.11.0
		 */
		return (string) apply_filters( 'wc_facebook_upload_id', $this->upload_id, $this );
	}

	/**
	 * Gets the Facebook pixel install time in UTC seconds.
	 *
	 * @return int
	 * @since 1.10.0
	 */
	public function get_pixel_install_time() {
		if ( ! (int) $this->pixel_install_time ) {
			$value = (int) get_option( self::OPTION_PIXEL_INSTALL_TIME, 0 );
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			$this->pixel_install_time = $value ?: null;
		}

		/**
		 * Filters the Facebook pixel install time.
		 *
		 * @param string $pixel_install_time Facebook pixel install time in UTC seconds, or null if none set
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (int) apply_filters( 'wc_facebook_pixel_install_time', $this->pixel_install_time, $this );
	}

	/**
	 * Gets the configured JS SDK version.
	 *
	 * @return string
	 * @since 1.10.0
	 */
	public function get_js_sdk_version() {
		if ( ! is_string( $this->js_sdk_version ) ) {
			$value                = get_option( self::OPTION_JS_SDK_VERSION, '' );
			$this->js_sdk_version = is_string( $value ) ? $value : '';
		}

		/**
		 * Filters the Facebook JS SDK version.
		 *
		 * @param string $js_sdk_version Facebook JS SDK version
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (string) apply_filters( 'wc_facebook_js_sdk_version', $this->js_sdk_version, $this );
	}

	/**
	 * Gets the configured Facebook page ID.
	 *
	 * @return string
	 * @since 1.10.0
	 * @deprecated The Page ID is no longer collected or used. Nothing in the plugin reads it,
	 *             and the Commerce Partner Integration that replaces the legacy installation
	 *             read does not supply one. The read remains temporarily for backwards
	 *             compatibility and returns whatever a previously connected store stored.
	 */
	public function get_facebook_page_id() {
		wc_deprecated_function( __METHOD__, '3.7.7' );
		/**
		 * Filters the configured Facebook page ID.
		 *
		 * @param string $page_id the configured Facebook page ID
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (string) apply_filters( 'wc_facebook_page_id', get_option( self::SETTING_FACEBOOK_PAGE_ID, '' ), $this );
	}

	/**
	 * Gets the configured Facebook pixel ID.
	 *
	 * @return string
	 * @since 1.10.0
	 */
	public function get_facebook_pixel_id() {
		/**
		 * Filters the configured Facebook pixel ID.
		 *
		 * @param string $pixel_id the configured Facebook pixel ID
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (string) apply_filters( 'wc_facebook_pixel_id', get_option( self::SETTING_FACEBOOK_PIXEL_ID, '' ), $this );
	}

	/**
	 * Gets the IDs of the categories to be excluded from sync.
	 *
	 * @return int[]
	 * @since 1.10.0
	 */
	public function get_excluded_product_category_ids() {

		if ( $this->is_woo_all_products_enabled() ) {
			return (array) array();
		}
		/**
		 * Filters the configured excluded product category IDs.
		 *
		 * @param int[] $category_ids the configured excluded product category IDs
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (array) apply_filters( 'wc_facebook_excluded_product_category_ids', get_option( self::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, array() ), $this );
	}

	/**
	 * Gets the IDs of the tags to be excluded from sync.
	 *
	 * @return int[]
	 * @since 1.10.0
	 */
	public function get_excluded_product_tag_ids() {
		if ( $this->is_woo_all_products_enabled() ) {
			return (array) array();
		}
		/**
		 * Filters the configured excluded product tag IDs.
		 *
		 * @param int[] $tag_ids the configured excluded product tag IDs
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (array) apply_filters( 'wc_facebook_excluded_product_tag_ids', get_option( self::SETTING_EXCLUDED_PRODUCT_TAG_IDS, array() ), $this );
	}

	/** Setter methods ************************************************************************************************/


	/**
	 * Updates the Facebook product catalog ID.
	 *
	 * @param string $value product catalog ID value
	 *
	 * @since 1.10.0
	 */
	public function update_product_catalog_id( $value ) {
		update_option( self::OPTION_PRODUCT_CATALOG_ID, $this->sanitize_facebook_credential( $value ) );
	}

	/**
	 * Updates the Facebook external merchant settings ID.
	 *
	 * @param string $value external merchant settings ID value
	 *
	 * @since 1.10.0
	 */
	public function update_external_merchant_settings_id( $value ) {
		$this->external_merchant_settings_id = $this->sanitize_facebook_credential( $value );

		update_option( self::OPTION_EXTERNAL_MERCHANT_SETTINGS_ID, $this->external_merchant_settings_id );
	}

	/**
	 * Updates the Facebook feed ID.
	 *
	 * @param string $value feed ID value
	 *
	 * @since 1.10.0
	 */
	public function update_feed_id( $value ) {
		$this->feed_id = $this->sanitize_facebook_credential( $value );

		update_option( self::OPTION_FEED_ID, $this->feed_id );
	}

	/**
	 * Updates the Facebook upload ID.
	 *
	 * @param string $value upload ID value
	 *
	 * @since 1.11.0
	 */
	public function update_upload_id( $value ) {
		$this->upload_id = $this->sanitize_facebook_credential( $value );

		update_option( self::OPTION_UPLOAD_ID, $this->upload_id );
	}

	/**
	 * Updates the Facebook pixel install time.
	 *
	 * @param int $value pixel install time, in UTC seconds
	 *
	 * @since 1.10.0
	 */
	public function update_pixel_install_time( $value ) {
		$value = (int) $value;
		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		$this->pixel_install_time = $value ?: null;

		// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		update_option( self::OPTION_PIXEL_INSTALL_TIME, $value ?: '' );
	}

	/**
	 * Updates the Facebook JS SDK version.
	 *
	 * @param string $value JS SDK version
	 *
	 * @since 1.10.0
	 */
	public function update_js_sdk_version( $value ) {
		$this->js_sdk_version = $this->sanitize_facebook_credential( $value );
		update_option( self::OPTION_JS_SDK_VERSION, $this->js_sdk_version );
	}


	/**
	 * Sanitizes a value that's a Facebook credential.
	 *
	 * @param string $value value to sanitize
	 *
	 * @return string
	 * @since 1.10.0
	 */
	private function sanitize_facebook_credential( $value ) {
		return wc_clean( is_string( $value ) ? $value : '' );
	}

	/**
	 * Determines whether Meta for WooCommerce is configured.
	 *
	 * This used to also require a Facebook Page ID. The Page is not used to reach any
	 * Meta endpoint and is optional during onboarding, so a shop connected through an
	 * Instagram-only asset selection had product sync silently disabled even with a
	 * healthy connection and catalog. Callers that need a catalog check for it
	 * alongside this method.
	 *
	 * @return bool
	 * @since 1.10.0
	 */
	public function is_configured() {
		return $this->facebook_for_woocommerce->get_connection_handler()->is_connected();
	}

	/**
	 * Determines if viewing the plugin settings in the admin.
	 *
	 * @since 3.5.3
	 *
	 * @return bool
	 */
	public function is_woo_all_products_enabled() {
		return $this->facebook_for_woocommerce->get_rollout_switches()->is_switch_enabled(
			RolloutSwitches::SWITCH_WOO_ALL_PRODUCTS_SYNC_ENABLED
		);
	}

	/**
	 * Determines whether advanced matching is enabled.
	 *
	 * @return bool
	 * @since 1.10.0
	 */
	public function is_advanced_matching_enabled() {
		/**
		 * Filters whether advanced matching is enabled.
		 *
		 * @param bool $is_enabled whether advanced matching is enabled
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (bool) apply_filters( 'wc_facebook_is_advanced_matching_enabled', true, $this );
	}

	/**
	 * Determines whether product sync is enabled.
	 *
	 * @return bool
	 * @since 1.10.0
	 */
	public function is_product_sync_enabled() {
		/**
		 * If all products switch is enabled
		 * There is no check for global sync
		 */

		if ( $this->is_woo_all_products_enabled() ) {
			return true;
		}

		/**
		 * Filters whether product sync is enabled.
		 *
		 * @param bool $is_enabled whether product sync is enabled
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.0
		 */
		return (bool) apply_filters( 'wc_facebook_is_product_sync_enabled', 'yes' === get_option( self::SETTING_ENABLE_PRODUCT_SYNC, 'yes' ), $this );
	}

	/**
	 * Return true if (legacy) feed generation is enabled.
	 *
	 * Feed generation for product sync is enabled by default, and generally recommended.
	 * Large stores, or stores running on shared hosting (low resources) may have issues
	 * with feed generation. This option allows those stores to disable generation to
	 * work around the issue.
	 *
	 * Note - this is temporary. In a future release, an improved feed system will be
	 * implemented, which should work well for all stores. This option will not disable
	 * the new improved implementation.
	 *
	 * @since 2.5.0
	 *
	 * @return bool
	 */
	public function is_legacy_feed_file_generation_enabled() {
		return 'yes' === get_option( self::OPTION_LEGACY_FEED_FILE_GENERATION_ENABLED, 'yes' );
	}

	/**
	 * Determines whether meta diagnosis is enabled.
	 *
	 * @return bool
	 * @since 3.4.4
	 */
	public function is_meta_diagnosis_enabled() {
		return (bool) ( 'yes' === get_option( self::SETTING_ENABLE_META_DIAGNOSIS ) );
	}

	/**
	 * Determines whether debug mode is enabled.
	 *
	 * @return bool
	 * @since 1.10.2
	 */
	public function is_debug_mode_enabled() {
		/**
		 * Filters whether debug mode is enabled.
		 *
		 * @param bool $is_enabled whether debug mode is enabled
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 *
		 * @since 1.10.2
		 */
		return (bool) apply_filters( 'wc_facebook_is_debug_mode_enabled', 'yes' === get_option( self::SETTING_ENABLE_DEBUG_MODE ), $this );
	}

	/**
	 * Determines whether facebook managed coupons is enabled.
	 *
	 * @return bool
	 */
	public function is_facebook_managed_coupons_enabled(): bool {
		return ( 'yes' === get_option( self::SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS, self::SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS_DEFAULT_VALUE ) );
	}

	/**
	 * Determines whether offline (physical store) purchase events are enabled.
	 *
	 * Purchase events for orders taken at a point of sale are opt-in: they are
	 * reported to Meta with an `action_source` of `physical_store`, so a merchant
	 * has to knowingly turn them on before anything is sent.
	 *
	 * @return bool
	 */
	public function is_offline_purchase_events_enabled(): bool {
		/**
		 * Filters whether offline (physical store) purchase events are enabled.
		 *
		 * @param bool                             $is_enabled  whether offline purchase events are enabled
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 */
		return (bool) apply_filters(
			'wc_facebook_is_offline_purchase_events_enabled',
			'yes' === get_option( self::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS, 'no' ),
			$this
		);
	}

	/**
	 * Determines whether the merchant has opted in to the Meta-enabled Conversions API integration.
	 *
	 * When opted in, the pixel emits fbq('optinMetaEnabledCapi', <pixel id>) before
	 * initialising, authorising Meta to set up the integration (Conversions API
	 * Gateway). It is opted in by default, so a missing option — a fresh install, or
	 * an upgrade from a version without the setting — counts as opted in.
	 *
	 * @return bool
	 */
	public function is_capig_enabled(): bool {
		/**
		 * Filters whether the Meta-enabled Conversions API integration is opted in to.
		 *
		 * @param bool                             $is_enabled  whether the integration is opted in to
		 * @param \WC_Facebookcommerce_Integration $integration the integration instance
		 */
		return (bool) apply_filters(
			'wc_facebook_is_capig_enabled',
			'yes' === get_option( self::SETTING_ENABLE_CAPIG, self::SETTING_ENABLE_CAPIG_DEFAULT ),
			$this
		);
	}

	/**
	 * Determines whether offline purchase events can be switched on right now.
	 *
	 * They need an active point-of-sale plugin to come from. This is the single rule
	 * both the settings screen and the AJAX actions apply, so the two ways of
	 * enabling the feature cannot disagree. It governs switching on only: an
	 * existing opt-in is kept while no POS plugin is active, and switching off is
	 * always allowed.
	 *
	 * @return bool
	 */
	public function can_enable_offline_purchase_events(): bool {
		return ! empty( ( new \WooCommerce\Facebook\Events\POS\POS_Integration_Registry() )->get_supported_integrations() );
	}

	/**
	 * Determines whether the merchant has ever opted in to offline purchase events.
	 *
	 * Stays true after opting out, so a merchant who tried the feature and switched it
	 * off is not pitched it again as new. A store that is enabled now counts too, in
	 * case it was enabled before this was recorded.
	 *
	 * @return bool
	 */
	public function has_ever_opted_in_to_offline_purchase_events(): bool {
		return 'yes' === get_option( self::OPTION_OFFLINE_PURCHASE_EVENTS_EVER_OPTED_IN, 'no' )
			|| 'yes' === get_option( self::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS, 'no' );
	}

	/**
	 * Gets when the merchant opted in to offline purchase events.
	 *
	 * Sales paid before this moment are never reported, so turning the feature on
	 * does not backfill history. The time is recorded whenever the setting is
	 * switched on. If it is missing while the feature is enabled — for example when
	 * the feature was enabled through the wc_facebook_is_offline_purchase_events_enabled
	 * filter — it is recorded now, which errs towards reporting nothing old.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_offline_purchase_events_opted_in_at(): int {
		$opted_in_at = (int) get_option( self::OPTION_OFFLINE_PURCHASE_EVENTS_OPTED_IN_AT, 0 );

		if ( 0 === $opted_in_at ) {
			$opted_in_at = time();
			update_option( self::OPTION_OFFLINE_PURCHASE_EVENTS_OPTED_IN_AT, $opted_in_at, false );
		}

		return $opted_in_at;
	}

	/**
	 * Records the opt-in time when the offline purchase events option is first created.
	 *
	 * @internal
	 *
	 * @param string $option the option name.
	 * @param mixed  $value  the new value.
	 */
	public function handle_offline_purchase_events_option_added( $option, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- signature fixed by the add_option_{$option} hook.
		$this->record_offline_purchase_events_opt_in( 'no', $value );
	}

	/**
	 * Records the opt-in time when the offline purchase events option changes.
	 *
	 * @internal
	 *
	 * @param mixed $old_value the previous value.
	 * @param mixed $value     the new value.
	 */
	public function handle_offline_purchase_events_option_updated( $old_value, $value ) {
		$this->record_offline_purchase_events_opt_in( $old_value, $value );
	}

	/**
	 * Stamps the opt-in time on a switch to enabled, and clears it on a switch to disabled.
	 *
	 * Clearing it means re-enabling later starts a fresh window, so sales paid while
	 * the feature was off are not reported after it is turned back on.
	 *
	 * @param mixed $old_value the previous value.
	 * @param mixed $value     the new value.
	 */
	private function record_offline_purchase_events_opt_in( $old_value, $value ) {
		$was_enabled = 'yes' === $old_value;
		$is_enabled  = 'yes' === $value;

		if ( $is_enabled && ! $was_enabled ) {
			update_option( self::OPTION_OFFLINE_PURCHASE_EVENTS_OPTED_IN_AT, time(), false );
			// Unlike the opt-in time, this survives opting out.
			update_option( self::OPTION_OFFLINE_PURCHASE_EVENTS_EVER_OPTED_IN, 'yes', false );
		} elseif ( ! $is_enabled && $was_enabled ) {
			delete_option( self::OPTION_OFFLINE_PURCHASE_EVENTS_OPTED_IN_AT );
		}
	}

	/**
	 * Determines whether debug mode is enabled.
	 *
	 * @return bool
	 * @since 2.6.6
	 */
	public function is_new_style_feed_generation_enabled() {
		return (bool) ( 'yes' === get_option( self::SETTING_ENABLE_NEW_STYLE_FEED_GENERATOR ) );
	}

	/**
	 * Determines whether language override feed generation is enabled.
	 *
	 * @return bool
	 * @since 3.6.0
	 */
	public function is_language_override_feed_generation_enabled() {

		if ( ! facebook_for_woocommerce()->get_rollout_switches()->is_switch_enabled( \WooCommerce\Facebook\RolloutSwitches::SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED ) ) {
			return false;
		}

		// Check if localization integration is available and eligible
		$integration = \WooCommerce\Facebook\Integrations\IntegrationRegistry::get_active_localization_integration();

		if ( $integration ) {
			// If integration exists, check if it's eligible for override feeds
			// WPML with legacy multi-language setup will return false here
			if ( ! $integration->is_eligible_for_language_override_feeds() ) {
				return false;
			}
		}

		return 'yes' === get_option( self::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED, 'yes' );
	}

	/**
	 * Check if logging headers is requested.
	 * For a typical troubleshooting session the request headers bring zero value except making the log unreadable.
	 * They will be disabled by default. Enabling them will require setting an option in the options table.
	 *
	 * @since 2.6.6
	 */
	public function are_headers_requested_for_debug() {
		return (bool) get_option( self::SETTING_REQUEST_HEADERS_IN_DEBUG_MODE, false );
	}

	/***
	 * Determines if the feed has been migrated from FBE 1 to FBE 1.5
	 *
	 * @return bool
	 * @since 1.11.0
	 */
	public function is_feed_migrated() {
		if ( ! is_bool( $this->feed_migrated ) ) {
			$value               = get_option( 'wc_facebook_feed_migrated', 'no' );
			$this->feed_migrated = wc_string_to_bool( $value );
		}

		return $this->feed_migrated;
	}

	/**
	 * Gets message HTML.
	 *
	 * @param string $message
	 * @param string $type
	 *
	 * @return string
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	 */
	private function get_message_html( string $message, string $type = 'error' ): string {
		ob_start();

		printf(
			'<div class="notice is-dismissible notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			$message
		);

		return ob_get_clean();
	}

	/**
	 * Displays relevant messages to user from transients, clear once displayed.
	 */
	public function maybe_display_facebook_api_messages() {
		$error_msg = get_transient( 'facebook_plugin_api_error' );
		if ( $error_msg ) {
			$message = '<strong>' . __( 'Meta for WooCommerce error:', 'facebook-for-woocommerce' ) . '</strong></br>' . $error_msg;
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_message_html( $message );
			delete_transient( 'facebook_plugin_api_error' );
			Logger::log(
				'Error message displayed to Admins',
				array(
					'flow_name'  => 'display_admin_message',
					'flow_step'  => 'display_admin_error_message',
					'extra_data' => array(
						'displayed_message' => $error_msg,
					),
				),
				array(
					'should_send_log_to_meta' => true,
				)
			);
		}
		$warning_msg = get_transient( 'facebook_plugin_api_warning' );
		if ( $warning_msg ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_message_html( $warning_msg, 'warning' );
			delete_transient( 'facebook_plugin_api_warning' );
		}
		$success_msg = get_transient( 'facebook_plugin_api_success' );
		if ( $success_msg ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_message_html( $success_msg, 'success' );
			delete_transient( 'facebook_plugin_api_success' );
		}
		$info_msg = get_transient( 'facebook_plugin_api_info' );
		if ( $info_msg ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_message_html( $info_msg, 'info' );
			delete_transient( 'facebook_plugin_api_info' );
		}
		$sticky_msg = get_transient( 'facebook_plugin_api_sticky' );
		if ( $sticky_msg ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_message_html( $sticky_msg, 'info' );
			// transient must be deleted elsewhere, or wait for timeout
		}

		// Display unmapped attributes banner
		$unmapped_attributes_msg = get_transient( 'facebook_plugin_unmapped_attributes_info' );
		if ( $unmapped_attributes_msg && $this->should_show_unmapped_attributes_banner() ) {
			// Add a dismiss button to the message
			$dismiss_message = $unmapped_attributes_msg . ' <button type="button" class="notice-dismiss" onclick="fbDismissUnmappedAttributesBanner(event)" title="' . esc_attr__( 'Dismiss this notice.', 'facebook-for-woocommerce' ) . '"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'facebook-for-woocommerce' ) . '</span></button>';

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $this->get_message_html( $dismiss_message, 'info' );

			// Include JavaScript for dismiss functionality
			?>
			<script type="text/javascript">
			function fbDismissUnmappedAttributesBanner(event) {
				// Make AJAX request to dismiss the banner
				var xhr = new XMLHttpRequest();
				xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', true);
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
				xhr.onload = function() {
					if (xhr.status === 200) {
						// Hide the notice immediately
						var notice = event.target.closest('.notice');
						if (notice) {
							notice.style.display = 'none';
						}
					}
				};
				xhr.send('action=fb_dismiss_unmapped_attributes_banner&_wpnonce=<?php echo esc_attr( wp_create_nonce( 'fb_dismiss_unmapped_attributes_banner' ) ); ?>');
			}
			</script>
			<?php
			delete_transient( 'facebook_plugin_unmapped_attributes_info' );
		}
	}

	/**
	 * Admin Panel Options
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	 */
	public function admin_options() {
		$this->facebook_for_woocommerce->get_message_handler()->show_messages();

		printf(
			'<div id="integration-settings" %s>%s</div>',
			! $this->is_configured() ? 'style="display: none"' : '',
			sprintf(
				'<table class="form-table">%s</table>',
				$this->generate_settings_html( $this->get_form_fields() )
			)
		);
	}

	/**
	 * Filter function for woocommerce_duplicate_product_exclude_meta filter.
	 *
	 * @param array $to_delete
	 *
	 * @return array
	 */
	public function fb_duplicate_product_reset_meta( array $to_delete ): array {
		$to_delete[] = self::FB_PRODUCT_ITEM_ID;
		$to_delete[] = self::FB_PRODUCT_GROUP_ID;

		return $to_delete;
	}

	/**
	 * Helper function to update FB visibility.
	 *
	 * @param int|WC_Product $product_id product ID or product object
	 * @param string         $visibility visibility
	 */
	public function update_fb_visibility( $product_id, $visibility ) {
		// bail if the plugin is not configured properly
		if ( ! $this->is_configured() || ! $this->get_product_catalog_id() ) {
			return;
		}

		$product = $product_id instanceof WC_Product ? $product_id : wc_get_product( $product_id );

		// bail if product isn't found
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$should_set_visible = self::FB_SHOP_PRODUCT_VISIBLE === $visibility;
		if ( $product->is_type( 'variation' ) || $product->is_type( 'simple' ) ) {
			Products::set_product_visibility( $product, $should_set_visible );
			$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_products( array( $product->get_id() ) );
		} elseif ( $product->is_type( 'variable' ) ) {
			// parent product
			Products::set_product_visibility( $product, $should_set_visible );
			// we should not add the parent product ID to the array of product IDs to be
			// updated because product groups, which are used to represent the parent product
			// for variable products, don't have the visibility property on Facebook
			$product_ids = array();
			// set visibility for all children
			foreach ( $product->get_children() as $index => $id ) {
				$product = wc_get_product( $id );
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				Products::set_product_visibility( $product, $should_set_visible );
				$product_ids[] = $product->get_id();
			}
			// sync product with all variations
			$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_products( $product_ids );
		}
	}

	/**
	 * Sync product upon quick edit save action.
	 *
	 * @param \WC_Product $product product object
	 *
	 * @internal
	 * @since 3.5.6
	 */
	public function on_product_quick_edit_save( $product ) {
		$wp_id = null;

		try {
			// bail if not a product or product is not enabled for sync
			if ( ! $product instanceof \WC_Product || ! Products::published_product_should_be_synced( $product ) ) {
				return;
			}

			$wp_id = $product->get_id();

			// check if visibility is published and sync the product
			if ( get_post_status( $wp_id ) === 'publish' ) {
				if ( $product->is_type( 'variable' ) ) {
					// For variable products, sync only the variations that should be synced
					$variation_ids = array();
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation instanceof WC_Product && $this->product_should_be_synced( $variation ) ) {
							$variation_ids[] = $variation_id;
						}
					}
					if ( ! empty( $variation_ids ) ) {
						$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_products( $variation_ids );
					}
				} else {
					// For simple products and variations, sync the product directly
					$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_products( array( $wp_id ) );
				}
			}
		} catch ( Exception $e ) {
			Logger::log(
				'Error in on_product_quick_edit_save',
				array(
					'event'      => 'product_quick_edit_save_error',
					'product_id' => $wp_id,
					'extra_data' => array(
						'product_status' => $wp_id ? get_post_status( $wp_id ) : null,
					),
				),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				),
				$e
			);
		}
	}

	/**
	 * Sync product upon bulk edit save action.
	 *
	 * @param \WC_Product $product product object
	 *
	 * @internal
	 */
	public function on_product_bulk_edit_save( $product ) {
		// bail if not a product or product is not enabled for sync
		static $bulk_product_edit_ids    = array();
		static $bulk_products_to_exclude = array();

		if ( ! $product instanceof \WC_Product || ! Products::published_product_should_be_synced( $product ) ) {
			return;
		}

		$wp_id      = $product->get_id();
		$visibility = get_post_status( $wp_id ) === 'publish' ? self::FB_SHOP_PRODUCT_VISIBLE : self::FB_SHOP_PRODUCT_HIDDEN;

		if ( self::FB_SHOP_PRODUCT_HIDDEN === $visibility ) {
			// - product never published to Facebook, new status is not publish
			// - product new status is not publish but may have been published before
			$this->update_fb_visibility( $product, $visibility );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce bulk edit verifies the request before this hook fires.
		if ( ! empty( $_REQUEST['post'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce bulk edit verifies the request before this hook fires.
			$bulk_product_edit_ids = $_REQUEST['post'];
		}

		/**
		 * Draft products are also included in this bulk edit
		 * As they will not be sent in requests since in backgroun jon they will be discarded
		 * when validations are checked
		 */
		$bulk_action_products_cumulative_count = did_action( 'woocommerce_product_bulk_edit_save' );

		if ( count( $bulk_product_edit_ids ) === $bulk_action_products_cumulative_count ) {
			$unique_in_bulk_prouduct_edit_ids   = array_diff( $bulk_product_edit_ids, $bulk_products_to_exclude );
			$unique_in_bulk_prouduct_to_exclude = array_diff( $bulk_products_to_exclude, $bulk_product_edit_ids );
			$final_products_to_updte            = array_merge( $unique_in_bulk_prouduct_edit_ids, $unique_in_bulk_prouduct_to_exclude );
			$this->facebook_for_woocommerce->get_products_sync_handler()->create_or_update_all_products_for_bulk_edit( $final_products_to_updte );
		}
	}

	/**
	 * Display test result.
	 **/
	public function ajax_display_test_result() {
		WC_Facebookcommerce_Utils::check_woo_ajax_permissions( 'test result', true );
		check_ajax_referer( 'wc_facebook_settings_jsx' );
		$response  = array(
			'pass' => 'true',
		);
		$test_pass = get_option( 'fb_test_pass', null );
		if ( ! isset( $test_pass ) ) {
			$response['pass'] = 'in progress';
		} elseif ( 0 === $test_pass ) {
			$response['pass']        = 'false';
			$response['debug_info']  = get_transient( 'facebook_plugin_test_fail' );
			$response['stack_trace'] =
				get_transient( 'facebook_plugin_test_stack_trace' );
			$response['stack_trace'] =
				preg_replace( "/\n/", '<br>', $response['stack_trace'] );
			delete_transient( 'facebook_plugin_test_fail' );
			delete_transient( 'facebook_plugin_test_stack_trace' );
		}
		delete_option( 'fb_test_pass' );
		printf( wp_json_encode( $response ) );
		wp_die();
	}

	/**
	 * Init WhatsApp Utility Event Processor.
	 *
	 * @return void
	 */
	public function load_whatsapp_iframe_utility_event_processor() {
		// Attempt to load Iframe WhatsApp Utility Event Processor
		include_once 'facebook-commerce-iframe-whatsapp-utility-event.php';
		if ( class_exists( 'WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event' ) ) {
			if ( ! isset( $this->wa_iframe_utility_event_processor ) ) {
				$this->wa_iframe_utility_event_processor = new WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event( $this->facebook_for_woocommerce );
			}
		}
	}
}
