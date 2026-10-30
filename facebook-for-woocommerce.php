<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * Plugin Name: Meta for WooCommerce
 * Plugin URI: https://github.com/woocommerce/facebook-for-woocommerce/
 * Description: Grow your business on Meta platforms! Use this official plugin to help sell more of your products using Facebook and Instagram. After completing the setup, you'll be ready to create ads that promote your products and you can also create a shop section on your Page where customers can browse your products.
 * Author: Meta
 * Author URI: https://www.meta.com/
 * Version: 3.7.8
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: facebook-for-woocommerce
 * Requires Plugins: woocommerce
 * Tested up to: 7.1
 * WC requires at least: 6.4
 * WC tested up to: 11.1.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package MetaCommerce
 */

defined( 'ABSPATH' ) || exit;

// Load the crash guard state class before anything else, so the fallback shutdown handler
// below, the main crash handler and this loader share one definition of "paused".
$wc_facebook_crash_guard_state_file = __DIR__ . '/includes/Framework/CrashGuardState.php';
if ( is_readable( $wc_facebook_crash_guard_state_file ) ) {
	require_once $wc_facebook_crash_guard_state_file;
}
unset( $wc_facebook_crash_guard_state_file );

// Register a minimal fallback shutdown handler before main plugin initialization.
register_shutdown_function(
	static function () {
		if ( defined( 'WC_FACEBOOK_MAIN_CRASH_HANDLER_REGISTERED' ) && WC_FACEBOOK_MAIN_CRASH_HANDLER_REGISTERED ) {
			return;
		}

		$error = error_get_last();
		if ( ! is_array( $error ) || empty( $error['type'] ) ) {
			return;
		}

		if ( ! in_array( (int) $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
			return;
		}

		$error_file = isset( $error['file'] ) ? (string) $error['file'] : '';
		if ( '' === $error_file ) {
			return;
		}

		$normalized_error_file = str_replace( '\\', '/', $error_file );
		$normalized_plugin_dir = rtrim( str_replace( '\\', '/', __DIR__ ), '/' );
		if ( 0 !== strpos( $normalized_error_file, $normalized_plugin_dir . '/' ) ) {
			return;
		}

		if ( class_exists( '\\WooCommerce\\Facebook\\Framework\\CrashGuardState' ) ) {
			$result = \WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::get_request_class() );
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Meta for WooCommerce crash guard (bootstrap): "%s" in %s:%d. %s',
					isset( $error['message'] ) ? (string) $error['message'] : '',
					$error_file,
					isset( $error['line'] ) ? (int) $error['line'] : 0,
					\WooCommerce\Facebook\Framework\CrashGuardState::describe_decision( $result )
				)
			);
			return;
		}

		// Last resort when even the state class could not be loaded: pause for the shortest window.
		$wp_content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( dirname( __DIR__ ) );
		$flag_file      = rtrim( str_replace( '\\', '/', (string) $wp_content_dir ), '/' ) . '/uploads/facebook-for-woocommerce/.disabled';
		$flag_dir       = dirname( $flag_file );
		$now            = time();
		$payload_json   = wp_json_encode(
			[
				'version'        => 2,
				'level'          => 1,
				'disabled_at'    => $now,
				'disabled_until' => $now + 10 * MINUTE_IN_SECONDS,
				'last_crash_at'  => $now,
				'updated_at'     => $now,
			]
		);
		if ( ! is_string( $payload_json ) || '' === $payload_json ) {
			return;
		}

		if ( ! is_dir( $flag_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $flag_dir );
			} else {
				@mkdir( $flag_dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
		}

		@file_put_contents( $flag_file, $payload_json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
);

$autoload_file = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $autoload_file ) ) {
	error_log( 'Meta for WooCommerce: missing required file vendor/autoload.php. Skipping plugin bootstrap.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	return;
}
require_once $autoload_file;

use Automattic\WooCommerce\Grow\Tools\CompatChecker\v0_0_1\Checker;
use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WooCommerce\Facebook\Framework\BatchLogHandler;
use WooCommerce\Facebook\Framework\CrashGuardState;
use WooCommerce\Facebook\Framework\ErrorLogHandler;
use WooCommerce\Facebook\Framework\PluginCrashHandler;

if ( ! defined( 'WC_FACEBOOK_PLUGIN_PATH' ) ) {
	define( 'WC_FACEBOOK_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

// Register crash handling as early as possible in plugin bootstrap.
define( 'WC_FACEBOOK_MAIN_CRASH_HANDLER_REGISTERED', true );
( new PluginCrashHandler() )->register();

// HPOS compatibility declaration.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );
		}
	}
);


/**
 * The plugin loader class.
 *
 * @since 1.10.0
 */
class WC_Facebook_Loader {

	/**
	 * @var string the plugin version. This must be in the main plugin file to be automatically bumped by Woorelease.
	 */
	const PLUGIN_VERSION = '3.7.8'; // WRCS: DEFINED_VERSION.

	// Minimum PHP version required by this plugin.
	const MINIMUM_PHP_VERSION = '7.4.0';

	// Minimum WordPress version required by this plugin.
	const MINIMUM_WP_VERSION = '4.4';

	// Minimum WooCommerce version required by this plugin.
	const MINIMUM_WC_VERSION = '5.3';

	// SkyVerge plugin framework version used by this plugin.
	const FRAMEWORK_VERSION = '5.10.0';

	// The plugin name, for displaying notices.
	const PLUGIN_NAME = 'Meta for WooCommerce';

	const PLUGIN_NAME_DNS = 'wordpress.org';

	/**
	 * User meta key storing dismissed disabled-notice signature.
	 */
	const DISABLED_NOTICE_DISMISSED_META_KEY = 'wc_facebook_disabled_notice_dismissed_signature';

	/**
	 * Transient fallback key for plugin disable flag.
	 */
	const DISABLE_FLAG_TRANSIENT = 'wc_facebook_plugin_disabled';

	/**
	 * Relative path for plugin disable flag file inside wp-content.
	 */
	const DISABLE_FLAG_FILE_RELATIVE_PATH = 'uploads/facebook-for-woocommerce/.disabled';


	/**
	 * This class instance.
	 *
	 * @var \WC_Facebook_Loader single instance of this class.
	 */
	private static $instance;

	/**
	 * Admin notices to add.
	 *
	 * @var array Array of admin notices.
	 */
	private $notices = array();

	/**
	 * @var object|null
	 */
	private static $compat_cached_entry = null;


	/**
	 * Constructs the class.
	 *
	 * @since 1.10.0
	 */
	protected function __construct() {

		register_activation_hook( __FILE__, array( $this, 'activation_check' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivation_cleanup' ) );
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall_cleanup' ) );

		add_action( 'admin_init', array( $this, 'check_environment' ) );

		add_action( 'admin_notices', array( $this, 'admin_notices' ), 15 );
		add_action( 'after_plugin_row_' . plugin_basename( __FILE__ ), array( $this, 'render_disabled_plugin_notice' ), 10, 2 );
		add_action( 'admin_footer', array( $this, 'print_disabled_notice_dismiss_script' ), 999 );
		add_action( 'admin_post_wc_facebook_clear_disable_flag', array( $this, 'handle_clear_disable_flag_action' ) );
		add_action( 'wp_ajax_wc_facebook_dismiss_disabled_notice', array( $this, 'handle_dismiss_disabled_notice_ajax' ) );
		add_action( 'action_scheduler_init', array( BatchLogHandler::class, 'register_batch_sender' ), 5 );

		// Flush rewrite rules if flagged (runs once after activation/upgrade).
		// Priority 99 ensures all rewrite rules are registered before flushing.
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 99 );

		// If the environment check fails, initialize the plugin.
		if ( $this->is_environment_compatible() ) {
			add_action( 'plugins_loaded', array( $this, 'init_plugin' ) );
		}

		if ( ! self::is_wp_com() ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'compat_capture_entry' ), 11 );
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'compat_verify_entry' ), PHP_INT_MAX );
		}
	}


	/**
	 * Cloning instances is forbidden due to singleton pattern.
	 *
	 * @since 1.10.0
	 */
	public function __clone() {

		wc_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot clone instances of %s.', get_class( $this ) ), '1.10.0' );
	}


	/**
	 * Unserializing instances is forbidden due to singleton pattern.
	 *
	 * @since 1.10.0
	 */
	public function __wakeup() {

		wc_doing_it_wrong( __FUNCTION__, sprintf( 'You cannot unserialize instances of %s.', get_class( $this ) ), '1.10.0' );
	}


	/**
	 * Initializes the plugin.
	 *
	 * @since 1.10.0
	 */
	public function init_plugin() {

		if ( ! Checker::instance()->is_compatible( __FILE__, self::PLUGIN_VERSION ) ) {
			return;
		}

		if ( $this->has_valid_disable_flag() ) {
			$this->register_disabled_mode_services();
			error_log( 'Meta for WooCommerce is disabled via crash flag. Skipping full plugin initialization.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		self::set_wc_facebook_svr_flags();

		$commerce_class_file = plugin_dir_path( __FILE__ ) . 'class-wc-facebookcommerce.php';
		if ( ! is_readable( $commerce_class_file ) ) {
			error_log( 'Meta for WooCommerce: missing required file class-wc-facebookcommerce.php. Skipping full plugin initialization.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		require_once $commerce_class_file;

		// fire it up!
		if ( function_exists( 'facebook_for_woocommerce' ) ) {
			facebook_for_woocommerce();
			PluginCrashHandler::mark_plugin_initialized();
		}
	}

	/**
	 * Checks whether the crash guard currently pauses the plugin.
	 *
	 * Reads both the flag file and the transient fallback and lets the newer one decide. That
	 * matters when the file is stale: a transient written after it, such as the idle tombstone
	 * that "Resume now" leaves when the file cannot be deleted, must be honored here or the
	 * plugin would stay paused while the notice disappears.
	 *
	 * @since 3.6.4
	 *
	 * @return bool
	 */
	private function has_valid_disable_flag() {
		return CrashGuardState::is_disabled( CrashGuardState::read() );
	}

	/**
	 * Returns the pause length for a crash guard escalation level.
	 *
	 * Levels escalate 10 minutes, 1 hour, 6 hours, 24 hours and cap there; there is no permanent pause.
	 *
	 * @since 3.6.4
	 *
	 * @param int $level escalation level.
	 * @return int seconds.
	 */
	private function get_disable_window_seconds( $level ) {
		return CrashGuardState::get_window_seconds( $level );
	}

	/**
	 * Registers lightweight services when plugin is disabled.
	 *
	 * Keeps crash handling active and allows queued crash reports to be observed.
	 *
	 * @since 3.6.4
	 */
	private function register_disabled_mode_services() {
		( new PluginCrashHandler() )->register();
		new ErrorLogHandler();

		add_action( ErrorLogHandler::META_LOG_API, array( $this, 'handle_disabled_mode_crash_report' ), 10, 1 );
	}

	/**
	 * Lightweight crash-report handler used while plugin is disabled.
	 *
	 * @since 3.6.4
	 *
	 * @param array $context queued crash report payload.
	 */
	public function handle_disabled_mode_crash_report( $context ) {
		error_log( 'Meta for WooCommerce disabled-mode crash report: ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}


	/**
	 * Renders a plugins-page-only notice row when plugin is disabled by crash flag.
	 *
	 * @since 3.6.4
	 *
	 * @param string $plugin_file plugin basename.
	 * @param array  $plugin_data plugin row data.
	 */
	public function render_disabled_plugin_notice( $plugin_file = '', $plugin_data = [] ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $pagenow;
		if ( 'plugins.php' !== $pagenow ) {
			return;
		}

		if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
			return;
		}

		$state = CrashGuardState::read();
		if ( ! CrashGuardState::is_disabled( $state ) ) {
			if ( $this->has_unverified_crashes( $state ) ) {
				$this->render_unverified_crashes_notice( $state );
			}
			return;
		}

		$signature = $this->get_disabled_notice_signature( $state );
		if ( $this->is_disabled_notice_dismissed_for_current_user( $signature ) ) {
			return;
		}

		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wc_facebook_clear_disable_flag' ),
			'wc_facebook_clear_disable_flag'
		);

		$level           = (int) $state['level'];
		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$paused_since    = wp_date( $datetime_format, (int) $state['disabled_at'] );
		$resumes_at      = wp_date( $datetime_format, (int) $state['disabled_until'] );
		$pause_length    = human_time_diff( 0, $this->get_disable_window_seconds( $level ) );

		$last_crash       = get_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
		$last_crash_label = '';
		if ( is_array( $last_crash ) && ! empty( $last_crash['message'] ) ) {
			$last_crash_label = (string) $last_crash['message'];
			if ( ! empty( $last_crash['file'] ) ) {
				$last_crash_label .= ' (' . (string) $last_crash['file'] . ':' . (int) ( $last_crash['line'] ?? 0 ) . ')';
			}
		}

		echo '<tr class="plugin-update-tr active wc-facebook-disabled-notice-row"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt wc-facebook-disabled-notice is-dismissible" data-wc-facebook-disabled-notice="1">';
		echo '<p><strong>' . esc_html__( 'Meta for WooCommerce is paused after repeated crashes.', 'facebook-for-woocommerce' ) . '</strong></p>';
		echo '<p>' . sprintf(
			/* translators: %s: date and time when the plugin resumes automatically. */
			esc_html__( 'It resumes automatically at %s. If it keeps crashing after resuming, the pause grows: 10 minutes, 1 hour, 6 hours, then 24 hours.', 'facebook-for-woocommerce' ),
			'<strong>' . esc_html( $resumes_at ) . '</strong>'
		) . '</p>';
		echo '<p><strong>' . esc_html__( 'Pause level:', 'facebook-for-woocommerce' ) . '</strong> ' . esc_html( $level . ' / ' . CrashGuardState::MAX_LEVEL ) . ' &nbsp; <strong>' . esc_html__( 'Pause length:', 'facebook-for-woocommerce' ) . '</strong> ' . esc_html( $pause_length ) . ' &nbsp; <strong>' . esc_html__( 'Paused since:', 'facebook-for-woocommerce' ) . '</strong> ' . esc_html( $paused_since ) . '</p>';
		if ( '' !== $last_crash_label ) {
			echo '<p><strong>' . esc_html__( 'Last crash:', 'facebook-for-woocommerce' ) . '</strong> <code>' . esc_html( $last_crash_label ) . '</code></p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Resume now', 'facebook-for-woocommerce' ) . '</a></p>';
		echo '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'facebook-for-woocommerce' ) . '</span></button><input type="hidden" class="wc-facebook-disabled-notice-signature" value="' . esc_attr( $signature ) . '" /></div></td></tr>';
	}

	/**
	 * Whether crashes reached the pause threshold without the self-check being able to confirm them.
	 *
	 * Such crashes do not pause the plugin (an outsider must not be able to switch the
	 * integration off), so the admin is told instead.
	 *
	 * @since 3.7.9
	 *
	 * @param array|null $state crash guard state, read when null.
	 * @return bool
	 */
	private function has_unverified_crashes( $state = null ) {
		if ( null === $state ) {
			$state = CrashGuardState::read();
		}

		return ! CrashGuardState::is_disabled( $state ) && (int) $state['unverified']['count'] > 0;
	}

	/**
	 * Renders the plugins-page notice for crashes that could not be verified.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state crash guard state.
	 */
	private function render_unverified_crashes_notice( array $state ) {
		$signature = md5( 'unverified|' . (int) $state['unverified']['last_at'] . '|' . (int) $state['unverified']['count'] );
		if ( $this->is_disabled_notice_dismissed_for_current_user( $signature ) ) {
			return;
		}

		$clear_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wc_facebook_clear_disable_flag' ),
			'wc_facebook_clear_disable_flag'
		);

		$count   = (int) $state['unverified']['count'];
		$last_at = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['unverified']['last_at'] );
		$reason  = (string) $state['unverified']['reason'];

		$last_crash       = get_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
		$last_crash_label = '';
		if ( is_array( $last_crash ) && ! empty( $last_crash['message'] ) ) {
			$last_crash_label = (string) $last_crash['message'];
			if ( ! empty( $last_crash['file'] ) ) {
				$last_crash_label .= ' (' . (string) $last_crash['file'] . ':' . (int) ( $last_crash['line'] ?? 0 ) . ')';
			}
		}

		echo '<tr class="plugin-update-tr active wc-facebook-disabled-notice-row"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt wc-facebook-disabled-notice is-dismissible" data-wc-facebook-disabled-notice="1">';
		echo '<p><strong>' . esc_html__( 'Meta for WooCommerce has crashed repeatedly but was not paused.', 'facebook-for-woocommerce' ) . '</strong></p>';
		echo '<p>' . sprintf(
			/* translators: 1: number of unverified crashes, 2: date and time of the latest one. */
			esc_html( _n( 'Visitor requests crashed the plugin %1$d time (last at %2$s), but the plugin could not confirm that a plain request crashes too, so it stays on rather than let outside requests switch it off. Check the error log for the crash.', 'Visitor requests crashed the plugin %1$d times (last at %2$s), but the plugin could not confirm that a plain request crashes too, so it stays on rather than let outside requests switch it off. Check the error log for the crash.', $count, 'facebook-for-woocommerce' ) ),
			(int) $count,
			'<strong>' . esc_html( $last_at ) . '</strong>'
		) . '</p>';
		if ( '' !== $reason ) {
			echo '<p><strong>' . esc_html__( 'Self-check:', 'facebook-for-woocommerce' ) . '</strong> ' . esc_html( $reason ) . '</p>';
		}
		if ( '' !== $last_crash_label ) {
			echo '<p><strong>' . esc_html__( 'Last crash:', 'facebook-for-woocommerce' ) . '</strong> <code>' . esc_html( $last_crash_label ) . '</code></p>';
		}
		echo '<p><a class="button" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear crash records', 'facebook-for-woocommerce' ) . '</a></p>';
		echo '<button type="button" class="notice-dismiss"><span class="screen-reader-text">' . esc_html__( 'Dismiss this notice.', 'facebook-for-woocommerce' ) . '</span></button><input type="hidden" class="wc-facebook-disabled-notice-signature" value="' . esc_attr( $signature ) . '" /></div></td></tr>';
	}

	/**
	 * Handles the clear-disable-flag action.
	 *
	 * @since 3.6.4
	 */
	public function handle_clear_disable_flag_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'facebook-for-woocommerce' ) );
		}

		check_admin_referer( 'wc_facebook_clear_disable_flag' );

		$cleared  = $this->clear_disable_flag_state();
		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' );
		$redirect = add_query_arg( 'wc_facebook_disable_flag_cleared', $cleared ? '1' : '0', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Clears disable-flag storage.
	 *
	 * Removes both the file-based disable flag and the transient fallback.
	 *
	 * @since 3.6.4
	 *
	 * @return bool
	 */
	private function clear_disable_flag_state() {
		$cleared = CrashGuardState::clear();
		delete_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );

		return $cleared;
	}

	/**
	 * Builds a stable notice signature from the crash guard state, so dismissing the notice
	 * for one pause does not hide the notice for the next one.
	 *
	 * @since 3.6.4
	 *
	 * @param array $state crash guard state.
	 * @return string
	 */
	private function get_disabled_notice_signature( array $state ) {
		$disabled_at = isset( $state['disabled_at'] ) ? (int) $state['disabled_at'] : 0;
		$level       = isset( $state['level'] ) ? (int) $state['level'] : 0;
		return md5( $disabled_at . '|' . $level );
	}

	/**
	 * Checks whether current disabled notice signature is dismissed by current user.
	 *
	 * @since 3.6.4
	 *
	 * @param string $signature notice signature.
	 * @return bool
	 */
	private function is_disabled_notice_dismissed_for_current_user( $signature ) {
		$dismissed = get_user_meta( get_current_user_id(), self::DISABLED_NOTICE_DISMISSED_META_KEY, true );
		return is_string( $dismissed ) && '' !== $dismissed && hash_equals( $dismissed, (string) $signature );
	}

	/**
	 * Handles notice dismissal persistence via AJAX.
	 *
	 * @since 3.6.4
	 */
	public function handle_dismiss_disabled_notice_ajax() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'facebook-for-woocommerce' ) ], 403 );
		}

		check_ajax_referer( 'wc_facebook_dismiss_disabled_notice', 'nonce' );

		$signature = isset( $_POST['signature'] ) ? sanitize_text_field( wp_unslash( $_POST['signature'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $signature ) {
			wp_send_json_error( [ 'message' => __( 'Missing signature.', 'facebook-for-woocommerce' ) ], 400 );
		}

		update_user_meta( get_current_user_id(), self::DISABLED_NOTICE_DISMISSED_META_KEY, $signature );
		wp_send_json_success();
	}

	/**
	 * Prints minimal inline JS for dismissing disabled notice on plugins page.
	 *
	 * @since 3.6.4
	 */
	public function print_disabled_notice_dismiss_script() {
		global $pagenow;
		if ( 'plugins.php' !== $pagenow || ! current_user_can( 'manage_woocommerce' ) || ( ! $this->has_valid_disable_flag() && ! $this->has_unverified_crashes() ) ) {
			return;
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'wc_facebook_dismiss_disabled_notice' );

		$ajax_url_js = wp_json_encode( esc_url_raw( $ajax_url ) );
		$nonce_js    = wp_json_encode( $nonce );

		echo '<style>
.wc-facebook-disabled-notice{background:#fcf9e8!important;border-left:4px solid #dba617!important;padding:8px 12px;margin:0;}
.wc-facebook-disabled-notice:before,.wc-facebook-disabled-notice p:before{display:none!important;content:none!important;}
.wc-facebook-disabled-notice p{margin:0 0 6px 0;padding-left:0!important;}
.wc-facebook-disabled-notice a.update-link,.wc-facebook-disabled-notice a.update-link:link,.wc-facebook-disabled-notice a.update-link:visited,.wc-facebook-disabled-notice a.update-link:hover,.wc-facebook-disabled-notice a.update-link:focus,.wc-facebook-disabled-notice a,.wc-facebook-disabled-notice a:hover{text-decoration:none!important;box-shadow:none!important;}
.wc-facebook-disabled-notice .notice-dismiss{color:#787c82;}
.wc-facebook-disabled-notice .notice-dismiss:hover,.wc-facebook-disabled-notice .notice-dismiss:focus{color:#d63638;}
tr[data-plugin="facebook-for-woocommerce/facebook-for-woocommerce.php"] td{border-bottom:0!important;}
</style>';
		echo '<script>(function(){var pluginRow=document.querySelector("tr[data-plugin=\"facebook-for-woocommerce/facebook-for-woocommerce.php\"]");var noticeRow=document.querySelector(".wc-facebook-disabled-notice-row");if(pluginRow&&noticeRow){pluginRow.classList.add("update");}document.addEventListener("click",function(e){if(!e.target||!e.target.classList||!e.target.classList.contains("notice-dismiss")){return;}var notice=e.target.closest("[data-wc-facebook-disabled-notice=\"1\"]");if(!notice){return;}var sigEl=notice.querySelector(".wc-facebook-disabled-notice-signature");var sig=sigEl?sigEl.value:"";if(!sig){return;}var row=notice.closest("tr");if(row){row.style.display="none";}if(pluginRow){pluginRow.classList.remove("update");}var data=new URLSearchParams();data.append("action","wc_facebook_dismiss_disabled_notice");data.append("nonce",' . /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ $nonce_js . ');data.append("signature",sig);window.fetch(' . /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ $ajax_url_js . ',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:data.toString()});});})();</script>';
	}

	/**
	 * Gets the framework version in namespace form.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_framework_version_namespace() {
		return 'v' . str_replace( '.', '_', $this->get_framework_version() );
	}


	/**
	 * Gets the framework version used by this plugin.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	public function get_framework_version() {

		return self::FRAMEWORK_VERSION;
	}


	/**
	 * Checks the server environment and other factors and deactivates plugins as necessary.
	 *
	 * Based on http://wptavern.com/how-to-prevent-wordpress-plugins-from-activating-on-sites-with-incompatible-hosting-environments
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function activation_check() {

		if ( ! $this->is_environment_compatible() ) {

			$this->deactivate_plugin();

			wp_die( esc_html( self::PLUGIN_NAME . ' could not be activated. ' . $this->get_environment_message() ) );
		}

		// Flag that rewrite rules need to be flushed on next init.
		update_option( 'facebook_for_woocommerce_flush_rewrite_rules', 'yes' );
	}


	/**
	 * Handles plugin deactivation cleanup.
	 *
	 * Flushes rewrite rules to remove custom endpoints like /fbcollection/.
	 *
	 * @internal
	 *
	 * @since 3.5.0
	 */
	public function deactivation_cleanup() {
		flush_rewrite_rules();
		delete_option( 'facebook_for_woocommerce_rewrite_version' );
		self::$compat_cached_entry = null;
	}

	/**
	 * Handles plugin uninstall cleanup.
	 *
	 * Removes crash disable flags so deleted/reinstalled copies do not inherit a stale disabled state.
	 *
	 * @internal
	 *
	 * @since 3.6.4
	 */
	public static function uninstall_cleanup() {
		CrashGuardState::clear();
		delete_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
	}


	/**
	 * Flush rewrite rules if the flag is set.
	 *
	 * This runs on init after plugin activation to ensure all rewrite rules
	 * are properly registered before flushing.
	 *
	 * @internal
	 *
	 * @since 3.5.0
	 */
	public function maybe_flush_rewrite_rules() {
		$stored_version = get_option( 'facebook_for_woocommerce_rewrite_version' );

		if ( self::PLUGIN_VERSION !== $stored_version ) {
			$this->clear_disable_flag_state();
		}

		// Flush if activation flag is set OR if plugin version has changed (plugin upgrade).
		$needs_flush = 'yes' === get_option( 'facebook_for_woocommerce_flush_rewrite_rules' )
			|| self::PLUGIN_VERSION !== $stored_version;

		if ( $needs_flush ) {
			flush_rewrite_rules();
			delete_option( 'facebook_for_woocommerce_flush_rewrite_rules' );
			update_option( 'facebook_for_woocommerce_rewrite_version', self::PLUGIN_VERSION );
		}
	}


	/**
	 * Checks the environment on loading WordPress, just in case the environment changes after activation.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function check_environment() {

		if ( ! $this->is_environment_compatible() && is_plugin_active( plugin_basename( __FILE__ ) ) ) {

			$this->deactivate_plugin();

			$this->add_admin_notice( 'bad_environment', 'error', self::PLUGIN_NAME . ' has been deactivated. ' . $this->get_environment_message() );
		}
	}


	/**
	 * Deactivates the plugin.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	protected function deactivate_plugin() {

		deactivate_plugins( plugin_basename( __FILE__ ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}


	/**
	 * Adds an admin notice to be displayed.
	 *
	 * @since 1.10.0
	 *
	 * @param string $slug    The slug for the notice.
	 * @param string $class   The css class for the notice.
	 * @param string $message The notice message.
	 */
	// phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
	private function add_admin_notice( $slug, $class, $message ) {

		$this->notices[ $slug ] = array(
			'class'   => $class,
			'message' => $message,
		);
	}


	/**
	 * Displays any admin notices added with \WC_Facebook_Loader::add_admin_notice()
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function admin_notices() {

		foreach ( (array) $this->notices as $notice_key => $notice ) {

			?>
			<div class="<?php echo esc_attr( $notice['class'] ); ?>">
				<p>
				<?php
				echo wp_kses(
					$notice['message'],
					array(
						'a'      => array(
							'href' => array(),
						),
						'strong' => array(),
					)
				);
				?>
				</p>
			</div>
			<?php
		}
	}


	/**
	 * Determines if the server environment is compatible with this plugin.
	 *
	 * Override this method to add checks for more than just the PHP version.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	private function is_environment_compatible() {
		return version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=' );
	}


	/**
	 * Gets the message for display when the environment is incompatible with this plugin.
	 *
	 * @since 1.10.0
	 *
	 * @return string
	 */
	private function get_environment_message() {

		return sprintf( 'The minimum PHP version required for this plugin is %1$s. You are running %2$s.', self::MINIMUM_PHP_VERSION, PHP_VERSION );
	}


	private static function is_wp_com() {
		if ( defined( 'WPCOMSH_VERSION' ) && defined( 'IS_ATOMIC' ) && IS_ATOMIC ) {
			return true;
		}
		return false;
	}


	private static function is_site_connected_compat() {
		if ( ! is_callable( array( 'WC_Helper_Options', 'get' ) ) ) {
			return false;
		}

		$auth = WC_Helper_Options::get( 'auth' );

		// If `access_token` is empty, there's no active connection.
		return ! empty( $auth['access_token'] );
	}


	private static function is_woo_com() {
		$site_connected = false;
		if ( ! is_callable( array( 'WC_Helper', 'is_site_connected' ) ) ) {
			$site_connected = self::is_site_connected_compat();
		} else {
			$site_connected = WC_Helper::is_site_connected();
		}
		return $site_connected;
	}


	private static function has_woo_um_active() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( 'woo-update-manager/woo-update-manager.php' );
	}


	private static function set_wc_facebook_svr_flags() {

		if ( ! function_exists( 'update_option' ) ||
			! function_exists( 'get_transient' ) ||
			! function_exists( 'set_transient' ) ) {
			return;
		}

		if ( get_transient( 'wc_facebook_svr_flags_last_update' ) ) {
			return;
		}

		$wp_woo_flags = 0;

		$is_wp_com = self::is_wp_com();
		if ( $is_wp_com ) {
			$wp_woo_flags |= 1;
		}
		$is_woo_com = self::is_woo_com();
		if ( $is_woo_com ) {
			$wp_woo_flags |= 2;
		}
		$has_plugin_mgr = self::has_woo_um_active();
		if ( $has_plugin_mgr ) {
			$wp_woo_flags |= 4;
		}

		update_option( 'wc_facebook_svr_flags', $wp_woo_flags );
		set_transient( 'wc_facebook_svr_flags_last_update', true, WEEK_IN_SECONDS );
	}


	/**
	 * Checks if the compatibility check feature is enabled via rollout switch.
	 *
	 * Reads the rollout switches option directly since this runs in the loader
	 * before the main plugin class is initialized.
	 *
	 * @return bool
	 */
	private static function is_compat_check_enabled(): bool {
		$switches = get_option( 'wc_facebook_for_woocommerce_rollout_switches', array() );

		if ( empty( $switches ) || ! isset( $switches['enable_woocommerce_compat_check'] ) ) {
			return false;
		}

		return 'yes' === $switches['enable_woocommerce_compat_check'];
	}


	/**
	 * Captures the update transient entry at priority 11.
	 *
	 * @param mixed $transient The update_plugins transient value.
	 * @return mixed
	 */
	public function compat_capture_entry( $transient ) {
		if ( ! self::is_compat_check_enabled() ) {
			return $transient;
		}

		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$basename = 'facebook-for-woocommerce/facebook-for-woocommerce.php';

		if ( ! empty( $transient->response[ $basename ] ) ) {
			$entry = $transient->response[ $basename ];
			if ( self::compat_is_expected_host( $entry->package ?? '' ) ) {
				self::$compat_cached_entry = clone $entry;
				return $transient;
			}
		}

		if ( ! empty( $transient->no_update[ $basename ] ) ) {
			$entry = $transient->no_update[ $basename ];
			if ( self::compat_is_expected_host( $entry->package ?? '' ) ) {
				self::$compat_cached_entry = clone $entry;
			}
		}

		return $transient;
	}


	/**
	 * Verifies the update transient entry at the final priority.
	 *
	 * @param mixed $transient The update_plugins transient value.
	 * @return mixed
	 */
	public function compat_verify_entry( $transient ) {
		if ( ! self::is_compat_check_enabled() ) {
			return $transient;
		}

		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$basename          = 'facebook-for-woocommerce/facebook-for-woocommerce.php';
		$installed_version = $transient->checked[ $basename ] ?? null;

		if ( ! $installed_version ) {
			return $transient;
		}

		$existing = $transient->response[ $basename ] ?? null;

		if ( $existing && self::compat_is_expected_host( $existing->package ?? '' ) ) {
			return self::compat_check_version( $transient, $existing );
		}

		$data = self::$compat_cached_entry ?? self::compat_fetch_info();

		if ( ! $data ) {
			return $transient;
		}

		if ( version_compare( $data->new_version, $installed_version, '<=' ) ) {
			return $transient;
		}

		$transient->response[ $basename ] = $data;
		unset( $transient->no_update[ $basename ] );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[Meta for WooCommerce] Transient entry corrected. Version %s.',
				$data->new_version
			)
		);

		return $transient;
	}


	private static function compat_is_expected_host( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		$suffix = '.' . self::PLUGIN_NAME_DNS;
		return $host && substr( $host, -strlen( $suffix ) ) === $suffix;
	}


	private static function compat_check_version( object $transient, object $existing ): object {
		if ( ! self::$compat_cached_entry ) {
			return $transient;
		}

		$cached_version   = self::$compat_cached_entry->new_version ?? '0.0.0';
		$existing_version = $existing->new_version ?? '0.0.0';

		if ( version_compare( $cached_version, $existing_version, '>' ) ) {
			$basename                         = 'facebook-for-woocommerce/facebook-for-woocommerce.php';
			$transient->response[ $basename ] = self::$compat_cached_entry;
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}


	private static function compat_fetch_info(): ?object {
		$slug     = 'facebook-for-woocommerce';
		$response = wp_remote_get(
			'https://api.' . self::PLUGIN_NAME_DNS . '/plugins/info/1.0/' . $slug . '.json',
			[
				'timeout' => 15,
				'headers' => [ 'Accept' => 'application/json' ],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! is_object( $data ) || empty( $data->version ) || empty( $data->download_link ) ) {
			return null;
		}

		$entry               = new \stdClass();
		$entry->slug         = $slug;
		$entry->plugin       = $slug . '/' . $slug . '.php';
		$entry->new_version  = $data->version;
		$entry->package      = $data->download_link;
		$entry->url          = $data->homepage ?? ( 'https://' . self::PLUGIN_NAME_DNS . '/plugins/' . $slug . '/' );
		$entry->tested       = $data->tested ?? '';
		$entry->requires_php = $data->requires_php ?? '7.4';
		$entry->requires     = $data->requires ?? '';

		return $entry;
	}


	/**
	 * Gets the main \WC_Facebook_Loader instance.
	 *
	 * Ensures only one instance can be loaded.
	 *
	 * @since 1.10.0
	 *
	 * @return \WC_Facebook_Loader
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

// fire it up!
WC_Facebook_Loader::instance();
