<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/fbutils.php';

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskLists;
use WooCommerce\Facebook\Admin\Tasks\Setup;
use WooCommerce\Facebook\Admin\Notes\SettingsMoved;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Framework\Plugin\Compatibility;
use WooCommerce\Facebook\Integrations\Bookings as BookingsIntegration;
use WooCommerce\Facebook\Lifecycle;
use WooCommerce\Facebook\ProductSync\ProductValidator as ProductSyncValidator;
use WooCommerce\Facebook\Utilities\Background_Handle_Virtual_Products_Variations;
use WooCommerce\Facebook\Utilities\Background_Remove_Duplicate_Visibility_Meta;
use WooCommerce\Facebook\Utilities\DebugTools;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed;

/**
 * Class WC_Facebookcommerce
 *
 * This class is the main entry point for the Meta for WooCommerce plugin.
 */
class WC_Facebookcommerce extends WooCommerce\Facebook\Framework\Plugin {
	/** @var string the plugin version */
	const VERSION = WC_Facebook_Loader::PLUGIN_VERSION;

	/** @var string for backwards compatibility TODO: remove this in v2.0.0 {CW 2020-02-06} */
	const PLUGIN_VERSION = self::VERSION;

	/** @var string the plugin ID */
	const PLUGIN_ID = 'facebook_for_woocommerce';

	/** @var string the integration ID */
	const INTEGRATION_ID = 'facebookcommerce';

	/** @var string the plugin user agent name to use for HTTP calls within User-Agent header */
	const PLUGIN_USER_AGENT_NAME = 'Facebook-for-WooCommerce';

	/** @var WC_Facebookcommerce singleton instance */
	protected static $instance;

	/** @var WooCommerce\Facebook\API instance */
	private $api;

	/** @var \WC_Facebookcommerce_Integration instance */
	private $integration;

	/** @var WooCommerce\Facebook\Admin admin handler instance */
	private $admin;

	/** @var WooCommerce\Facebook\Admin\Enhanced_Settings */
	private $admin_settings;

	/** @var WooCommerce\Facebook\Admin\WhatsApp_Integration_Settings */
	private $wa_admin_settings;

	/** @var WooCommerce\Facebook\AJAX Ajax handler instance */
	private $ajax;

	/** @var WooCommerce\Facebook\Products\Feed product feed handler */
	private $product_feed;

	/** @var WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed language override feed handler */
	private $language_override_feed;

	/** @var WooCommerce\Facebook\Feed\FeedManager Entrypoint and creates all other feeds */
	public $feed_manager;

	/** @var Background_Handle_Virtual_Products_Variations instance */
	protected $background_handle_virtual_products_variations;

	/** @var Background_Remove_Duplicate_Visibility_Meta job handler instance */
	protected $background_remove_duplicate_visibility_meta;

	/** @var WooCommerce\Facebook\Products\Stock products stock handler */
	private $products_stock_handler;

	/** @var WooCommerce\Facebook\Products\Sync products sync handler */
	private $products_sync_handler;

	/** @var WooCommerce\Facebook\Products\Sync\Background background sync handler */
	private $sync_background_handler;

	/** @var WooCommerce\Facebook\ProductSets\ProductSetSync product sets sync handler */
	private $product_sets_sync_handler;

	/** @var WooCommerce\Facebook\Handlers\Connection connection handler */
	private $connection_handler;

	/** @var WooCommerce\Facebook\Handlers\WhatsAppConnection connection handler */
	private $whatsapp_connection_handler;

	/** @var WooCommerce\Facebook\Handlers\PluginRender plugin update handler */
	private $plugin_render_handler;

	/** @var WooCommerce\Facebook\Commerce_Page_Handler class, which handles the fbcollection endpoint */
	private $fbcollection_handler;

	/** @var WooCommerce\Facebook\Commerce commerce handler */
	private $commerce_handler;

	/** @var WooCommerce\Facebook\Utilities\Tracker */
	private $tracker;

	/** @var WooCommerce\Facebook\Jobs\JobManager */
	public $job_manager;

	/** @var WooCommerce\Facebook\Utilities\Heartbeat */
	public $heartbeat;

	/** @var WooCommerce\Facebook\Utilities\PluginConfigTelemetry */
	private $plugin_config_telemetry;

	/** @var WooCommerce\Facebook\Feed\FeedConfigurationDetection instance. */
	private $configuration_detection;

	/** @var WooCommerce\Facebook\Products\FBCategories instance. */
	private $fb_categories;

	/** @var WooCommerce\Facebook\RolloutSwitches instance. */
	private $rollout_switches;

	/**
	 * The Debug tools instance.
	 *
	 * @var WooCommerce\Facebook\Utilities\DebugTools
	 */
	private $debug_tools;

	/** @var WooCommerce\Facebook\Signals */
	private $signals;

	/** @var WooCommerce\Facebook\Events\ReleaseSignalsAjax */
	private $release_signals_ajax;

	/**
	 * Constructs the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			[ 'text_domain' => 'facebook-for-woocommerce' ]
		);
		$this->init();
		$this->init_admin();
	}

	/**
	 * __get method for backward compatibility.
	 *
	 * @param string $key property name
	 * @return mixed
	 * @since 3.0.32
	 */
	public function __get( $key ) {
		// Add warning for private properties.
		if ( in_array( $key, array( 'configuration_detection', 'fb_categories' ), true ) ) {
			/* translators: %s property name. */
			_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'The %s property is private and should not be accessed outside its class.', 'facebook-for-woocommerce' ), esc_html( $key ) ), '3.0.32' );
			return $this->$key;
		}

		return null;
	}

	/**
	 * Initializes the plugin.
	 *
	 * @internal
	 */
	public function init() {
		add_action( 'init', array( $this, 'get_integration' ) );

		// Hook the setup task. The hook admin_init is not triggered when the WC fetches the tasks using the endpoint: wp-json/wc-admin/onboarding/tasks and hence hooking into init.
		add_action( 'init', array( $this, 'add_setup_task' ), 20 );
		add_action( 'admin_notices', array( $this, 'add_inbox_notes' ) );

		add_filter(
			'wc_' . self::PLUGIN_ID . '_http_request_args',
			array( $this, 'force_user_agent_in_latin' )
		);

		if ( \WC_Facebookcommerce_Utils::is_woocommerce_integration() ) {
			include_once 'facebook-commerce.php';

			require_once __DIR__ . '/includes/fbproductfeed.php';

			$this->heartbeat = new Heartbeat( WC()->queue() );
			$this->heartbeat->init();
			$this->feed_manager              = new WooCommerce\Facebook\Feed\FeedManager();
			$this->product_feed              = new WooCommerce\Facebook\Products\Feed();
			$this->language_override_feed    = new WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed();
			$this->products_stock_handler    = new WooCommerce\Facebook\Products\Stock();
			$this->products_sync_handler     = new WooCommerce\Facebook\Products\Sync();
			$this->sync_background_handler   = new WooCommerce\Facebook\Products\Sync\Background();
			$this->configuration_detection   = new WooCommerce\Facebook\Feed\FeedConfigurationDetection();
			$this->product_sets_sync_handler = new WooCommerce\Facebook\ProductSets\ProductSetSync();
			$this->commerce_handler          = new WooCommerce\Facebook\Commerce();
			$this->fb_categories             = new WooCommerce\Facebook\Products\FBCategories();
			$this->plugin_config_telemetry   = new WooCommerce\Facebook\Utilities\PluginConfigTelemetry();
			$this->fbcollection_handler      = new WooCommerce\Facebook\CollectionPage();
			if ( wp_doing_ajax() ) {
				$this->ajax = new WooCommerce\Facebook\AJAX();
			}

			$this->signals              = new WooCommerce\Facebook\Signals();
			$this->release_signals_ajax = new WooCommerce\Facebook\Events\ReleaseSignalsAjax();

			// Load integrations.
			new WooCommerce\Facebook\WPMLInjector();
			new BookingsIntegration();

			if ( 'yes' !== get_option( 'wc_facebook_background_handle_virtual_products_variations_complete', 'no' ) ) {
				$this->background_handle_virtual_products_variations = new Background_Handle_Virtual_Products_Variations();
			}

			if ( 'yes' !== get_option( 'wc_facebook_background_remove_duplicate_visibility_meta_complete', 'no' ) ) {
				$this->background_remove_duplicate_visibility_meta = new Background_Remove_Duplicate_Visibility_Meta();
			}

			// Register REST API Endpoints
			new WooCommerce\Facebook\API\Plugin\InitializeRestAPI();
			WooCommerce\Facebook\OfferManagement\OfferManagementEndpointBase::register_endpoints();

			$this->connection_handler          = new WooCommerce\Facebook\Handlers\Connection( $this );
			$this->whatsapp_connection_handler = new WooCommerce\Facebook\Handlers\WhatsAppConnection( $this );
			new WooCommerce\Facebook\Handlers\WhatsAppExtension();
			new WooCommerce\Facebook\Handlers\MetaExtension();
			$this->tracker          = new WooCommerce\Facebook\Utilities\Tracker();
			$this->rollout_switches = new WooCommerce\Facebook\RolloutSwitches( $this );

			// Init jobs
			$this->job_manager = new WooCommerce\Facebook\Jobs\JobManager();
			add_action( 'init', [ $this->job_manager, 'init' ] );
			add_action( 'admin_init', array( $this->rollout_switches, 'init' ) );
			// Instantiate the debug tools.
			$this->debug_tools = new DebugTools();

			// load admin handlers, before admin_init
			if ( is_admin() ) {
				$this->admin_settings        = new WooCommerce\Facebook\Admin\Enhanced_Settings( $this );
				$this->wa_admin_settings     = new WooCommerce\Facebook\Admin\WhatsApp_Integration_Settings( $this );
				$this->plugin_render_handler = new \WooCommerce\Facebook\Handlers\PluginRender( $this );

				add_action( 'admin_notices', array( $this, 'add_connection_invalid_notice' ) );
			}
		}
	}

	/**
	 * Initializes the admin handling.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function init_admin() {
		add_action(
			'admin_init',
			function () {
				$this->admin = new WooCommerce\Facebook\Admin();

				// Initialize the global attributes banner
				if ( class_exists( 'WooCommerce\Facebook\Admin\Global_Attributes_Banner' ) ) {
					new WooCommerce\Facebook\Admin\Global_Attributes_Banner();
				}

				// Introduce offline events to stores with a supported POS that have never opted in.
				new WooCommerce\Facebook\Admin\Offline_Events_Banner();
			},
			0
		);
	}


	/**
	 * Add Inbox notes.
	 */
	public function add_inbox_notes() {
		if ( Compatibility::is_enhanced_admin_available() ) {
			if ( Compatibility::is_marketing_enabled() && class_exists( '\Automattic\WooCommerce\Admin\Notes\Note' ) ) { // Checking for Note class is for backward compatibility.
				SettingsMoved::possibly_add_or_delete_note();
			}
		}
	}

	/**
	 * Adds the setup task to the Tasklists.
	 *
	 * @since 2.6.29
	 */
	public function add_setup_task() {
		if ( class_exists( TaskLists::class ) ) { // This is added for backward compatibility.
			TaskLists::add_task(
				'extended',
				new Setup(
					TaskLists::get_list( 'extended' )
				)
			);
		}
	}

	/**
	 * Get the last event from the plugin lifecycle.
	 *
	 * @since 2.6.29
	 * @return array
	 */
	public function get_last_event_from_history() {
		$last_event     = array();
		$history_events = $this->lifecycle_handler->get_event_history();

		if ( isset( $history_events[0] ) ) {
			$last_event = $history_events[0];
		}
		return $last_event;
	}

	public function add_wordpress_integration() {
		new WP_Facebook_Integration();
	}

	/**
	 * Saves errors or messages to WooCommerce Log (woocommerce/logs/plugin-id-xxx.txt)
	 *
	 * @since 2.3.3
	 * @param string $message error or message to save to log
	 * @param string $log_id optional log id to segment the files by, defaults to plugin id
	 * @param string $level optional log level represents log's tag
	 */
	public function log( $message, $log_id = null, $level = null ) {
		// Bail if site is connected and user has disabled logging.
		// If site is disconnected, force-enable logging so merchant can diagnose connection issues.
		if ( ( ! $this->get_integration() || ! $this->get_integration()->is_debug_mode_enabled() ) && $this->get_connection_handler()->is_connected() ) {
			return;
		}

		parent::log( $message, $log_id, $level );
	}

	/**
	 * Logs an API request.
	 *
	 * @since 2.0.0
	 *
	 * @param array $request request data
	 * @param array $response response data
	 * @param null  $log_id log ID
	 */
	public function log_api_request( $request, $response, $log_id = null ) {
		// bail if logging isn't enabled
		if ( ! $this->get_integration() || ! $this->get_integration()->is_debug_mode_enabled() ) {
			return;
		}

		// Maybe remove headers from the debug log.
		if ( ! $this->get_integration()->are_headers_requested_for_debug() ) {
			unset( $request['headers'] );
			unset( $response['headers'] );
		}

		$this->log( $this->get_api_log_message( $request ), $log_id );

		if ( ! empty( $response ) ) {
			$this->log( $this->get_api_log_message( $response ), $log_id );
		}
	}

	/**
	 * Filter is responsible to always set latin user agent header value, because translated plugin names
	 * may contain characters which Facebook does not accept and return 400 response for requests with such
	 * header values.
	 * Applying either sanitize_title() nor remove_accents() on header value will not work for all the languages
	 * we support translations to e.g. Hebrew is going to convert into something %d7%90%d7%a8%d7%99%d7%92 which is
	 * not acceptable neither.
	 *
	 * @param array $http_request_headers - http request headers
	 * @return array
	 */
	public function force_user_agent_in_latin( array $http_request_headers ) {
		if ( isset( $http_request_headers['user-agent'] ) ) {
			$http_request_headers['user-agent'] = sprintf(
				'%s/%s (WooCommerce/%s; WordPress/%s)',
				self::PLUGIN_USER_AGENT_NAME,
				self::PLUGIN_VERSION,
				defined( 'WC_VERSION' ) ? WC_VERSION : WC_Facebook_Loader::MINIMUM_WC_VERSION,
				$GLOBALS['wp_version']
			);
		}
		return $http_request_headers;
	}

	/** Getter methods ********************************************************************************************/

	/**
	 * Gets the API instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $access_token access token to use for this API request
	 * @return WooCommerce\Facebook\API
	 * @throws ApiException If the access token is missing.
	 */
	public function get_api( string $access_token = '' ): WooCommerce\Facebook\API {
		// if none provided, use the general access token
		if ( ! $access_token ) {
			$access_token = $this->get_connection_handler()->get_access_token();
		}
		if ( ! is_object( $this->api ) ) {
			if ( ! $access_token ) {
				throw new ApiException( esc_html__( 'Cannot create the API instance because the access token is missing.', 'facebook-for-woocommerce' ) );
			}
			$this->api = new WooCommerce\Facebook\API( $access_token );
		} else {
			$this->api->set_access_token( $access_token );
		}
		return $this->api;
	}

	/**
	 * Gets the category handler.
	 *
	 * @since 1.11.0
	 *
	 * @return WooCommerce\Facebook\Products\FBCategories
	 */
	public function get_facebook_category_handler() {
		return $this->fb_categories;
	}

	/**
	 * Gets the background handle virtual products and variations handler instance.
	 *
	 * @since 2.0.0
	 *
	 * @return Background_Handle_Virtual_Products_Variations
	 */
	public function get_background_handle_virtual_products_variations_instance() {
		return $this->background_handle_virtual_products_variations;
	}

	/**
	 * Gets the background remove duplicate visibility meta data handler instance.
	 *
	 * @since 2.0.3
	 *
	 * @return Background_Remove_Duplicate_Visibility_Meta
	 */
	public function get_background_remove_duplicate_visibility_meta_instance() {
		return $this->background_remove_duplicate_visibility_meta;
	}

	/**
	 * Gets the products sync handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Products\Sync
	 */
	public function get_products_sync_handler() {
		return $this->products_sync_handler;
	}

	/**
	 * Gets the products sync handler.
	 *
	 * @since 3.4.9
	 *
	 * @return WooCommerce\Facebook\ProductSets\ProductSetSync
	 */
	public function get_product_sets_sync_handler() {
		return $this->product_sets_sync_handler;
	}

	/**
	 * Gets the products sync background handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Products\Sync\Background
	 */
	public function get_products_sync_background_handler() {
		return $this->sync_background_handler;
	}

	/**
	 * Gets the signals handler.
	 *
	 * @since 3.6.0
	 *
	 * @return WooCommerce\Facebook\Signals
	 */
	public function get_signals_handler() {
		return $this->signals;
	}

	/**
	 * Gets the connection handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Handlers\Connection
	 */
	public function get_connection_handler() {
		return $this->connection_handler;
	}

	/**
	 * Gets the whatsapp connection handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Handlers\WhatsAppConnection
	 */
	public function get_whatsapp_connection_handler() {
		return $this->whatsapp_connection_handler;
	}

	/**
	 * Gets the Plugin update handler.
	 *
	 * @since 2.0.0
	 *
	 * @return WooCommerce\Facebook\Handlers\PluginRender
	 */
	public function get_plugin_render_handler() {
		return $this->plugin_render_handler;
	}

	/**
	 * Gets the integration instance.
	 *
	 * @since 1.10.0
	 *
	 * @return WC_Facebookcommerce_Integration instance
	 */
	public function get_integration() {
		if ( null === $this->integration ) {
			$this->integration = new WC_Facebookcommerce_Integration( $this );
		}

		return $this->integration;
	}

	/**
	 * Gets the commerce handler instance.
	 *
	 * @since 2.1.0
	 *
	 * @return WooCommerce\Facebook\Commerce commerce handler instance
	 */
	public function get_commerce_handler() {
		return $this->commerce_handler;
	}

	/**
	 * Gets tracker instance.
	 *
	 * @since 2.6.0
	 *
	 * @return WooCommerce\Facebook\Utilities\Tracker
	 */
	public function get_tracker() {
		return $this->tracker;
	}

	/**
	 * Gets the debug profiling logger instance.
	 *
	 * @return WooCommerce\Facebook\Debug\ProfilingLogger
	 */
	public function get_profiling_logger() {
		static $instance = null;
		if ( null === $instance ) {
			$is_enabled = defined( 'FACEBOOK_FOR_WOOCOMMERCE_PROFILING_LOG_ENABLED' ) && FACEBOOK_FOR_WOOCOMMERCE_PROFILING_LOG_ENABLED;
			$instance   = new WooCommerce\Facebook\Debug\ProfilingLogger( $is_enabled );
		}

		return $instance;
	}

	/**
	 * Get the product sync validator class.
	 *
	 * @param WC_Product $product A product object to be validated.
	 *
	 * @return ProductSyncValidator
	 */
	public function get_product_sync_validator( WC_Product $product ) {
		return new ProductSyncValidator( $this->get_integration(), $product );
	}

	/**
	 * Gets the settings page URL.
	 *
	 * @since 1.10.0
	 *
	 * @param null $plugin_id unused
	 * @return string
	 */
	public function get_settings_url( $plugin_id = null ) {
		return admin_url( 'admin.php?page=wc-facebook' );
	}

	/**
	 * Gets the plugin's documentation URL.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_documentation_url() {
		return 'https://www.facebook.com/business/search/?q=woocommerce';
	}

	/**
	 * Gets the plugin's support URL.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_support_url() {
		return 'https://www.facebook.com/business-support-home';
	}

	/**
	 * Gets the plugin's sales page URL.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_sales_page_url() {
		return 'https://wordpress.org/plugins/facebook-for-woocommerce/';
	}

	/**
	 * Gets the plugin's reviews URL.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_reviews_url() {
		return 'https://wordpress.org/support/plugin/facebook-for-woocommerce/reviews/';
	}

	/**
	 * Gets the plugin name.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return __( 'Meta for WooCommerce', 'facebook-for-woocommerce' );
	}

	/**
	 * Gets the url for the assets build directory.
	 *
	 * @since 2.3.4
	 *
	 * @return string
	 */
	public function get_asset_build_dir_url() {
		return $this->get_plugin_url() . '/assets/build';
	}


	/**
	 * Gets the language override feed handler.
	 *
	 * @since 3.6.0
	 * @return WooCommerce\Facebook\Feed\Localization\LanguageOverrideFeed|null
	 */
	public function get_language_override_feed() {
		return $this->language_override_feed;
	}

	/**
	 * Gets the connection handler.
	 *
	 * @return WooCommerce\Facebook\RolloutSwitches
	 */
	public function get_rollout_switches() {
		return $this->rollout_switches;
	}

	/** Conditional methods ***************************************************************************************/

	/**
	 * Determines if viewing the plugin settings in the admin.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	public function is_plugin_settings() {
		$page_value = Helper::get_requested_value( 'page' );
		return is_admin() && in_array( $page_value, [ WooCommerce\Facebook\Admin\Enhanced_Settings::PAGE_ID, WooCommerce\Facebook\Admin\WhatsApp_Integration_Settings::PAGE_ID ], true );
	}

	/** Utility methods *******************************************************************************************/

	/**
	 * Initializes the lifecycle handler.
	 *
	 * @since 1.10.0
	 */
	protected function init_lifecycle_handler() {
		$this->lifecycle_handler = new Lifecycle( $this );
	}

	/**
	 * Gets the plugin singleton instance.
	 *
	 * @see \facebook_for_woocommerce()
	 *
	 * @since 1.10.0
	 *
	 * @return \WC_Facebookcommerce the plugin singleton instance
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Gets the plugin file.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	protected function get_file() {
		return __FILE__;
	}

	/**
	 * Return current page ID
	 *
	 * @since 2.3.0
	 *
	 * @return string
	 */
	protected function get_current_page_id() {
		$current_screen_id = '';
		$current_screen    = get_current_screen();
		if ( ! empty( $current_screen ) ) {
			$current_screen_id = $current_screen->id;
		}
		return $current_screen_id;
	}

	/**
	 * Displays an admin notice when the Facebook connection is invalid.
	 *
	 * Hooked on admin_notices so it fires regardless of whether the enhanced
	 * or non-enhanced settings path is active.
	 */
	public function add_connection_invalid_notice() {
		if ( ! get_transient( 'wc_facebook_connection_invalid' ) ) {
			return;
		}

		// Only show on the Plugins page and the Facebook settings page.
		$screen          = get_current_screen();
		$allowed_screens = array( 'plugins', 'marketing_page_wc-facebook', 'woocommerce_page_wc-facebook' );
		if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %1$s - <strong>, %2$s - </strong>, %3$s - <a> reconnect link, %4$s - </a>, %5$s - <a> support link, %6$s - </a> */
			__( '%1$sMeta for WooCommerce connection error.%2$s Your access token is no longer valid. This may happen if the system user was unconfirmed, the password was changed, or the app was deauthorized. Please %3$sreconnect your store%4$s to restore functionality, or %5$scontact support%6$s for help.', 'facebook-for-woocommerce' ),
			'<strong>',
			'</strong>',
			'<a href="' . esc_url( $this->get_settings_url() ) . '">',
			'</a>',
			'<a href="' . esc_url( $this->get_support_url() ) . '" target="_blank">',
			'</a>'
		);

		$this->get_admin_notice_handler()->add_admin_notice(
			$message,
			'wc_facebook_connection_invalid',
			array(
				'notice_class' => 'error',
				'dismissible'  => false,
			)
		);
	}
}

/**
 * Gets the Meta for WooCommerce plugin instance.
 *
 * @since 1.10.0
 *
 * @return \WC_Facebookcommerce instance of the plugin
 *
 * phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
 */
function facebook_for_woocommerce() {
	return apply_filters( 'wc_facebook_instance', \WC_Facebookcommerce::instance() );
}
