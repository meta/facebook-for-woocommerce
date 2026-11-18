<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\GoogleListingsAndAds\Tests;

use WC_Install;

define( 'FB_TESTS_DIR', __DIR__ );
define( 'FB_TESTS_DATA_DIR', FB_TESTS_DIR . '/data' );

global $fb_dir;
global $wp_plugins_dir;
global $wc_dir;
global $polylang_dir;
global $wcpos_dir;

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: path_join( sys_get_temp_dir(), '/wordpress-tests-lib' );
validate_file_exits( "{$wp_tests_dir}/includes/functions.php" );

$wp_core_dir    = getenv( 'WP_CORE_DIR' ) ?: path_join( sys_get_temp_dir(), '/wordpress' );
$wp_plugins_dir = path_join( $wp_core_dir, '/wp-content/plugins' );

$fb_dir = dirname( __FILE__, 2 ); // ../../

$wc_dir = getenv( 'WC_DIR' );
if ( ! $wc_dir ) {
	// Check if WooCommerce exists in the core plugin folder. The `bin/install-wp-tests.sh` script clones a copy there.
	$wc_dir = path_join( $wp_plugins_dir, '/woocommerce' );
	if ( ! file_exists( "{$wc_dir}/woocommerce.php" ) ) {
		// Check if WooCommerce exists in parent directory of the plugin (in case the plugin is located in a WordPress installation's `wp-content/plugins` folder)
		$wc_dir = path_join( dirname( $fb_dir ), '/woocommerce' );
	}
}
validate_file_exits( "{$wc_dir}/woocommerce.php" );

// Setup Polylang directory (optional - only if Polylang was installed)
$polylang_dir = path_join( $wp_plugins_dir, '/polylang' );
if ( ! file_exists( "{$polylang_dir}/polylang.php" ) ) {
	// Check if Polylang exists in parent directory of the plugin
	$polylang_dir = path_join( dirname( $fb_dir ), '/polylang' );
}
// Don't validate Polylang existence - it's optional

// WCPOS directory (optional - only installed for the point-of-sale contract tests).
$wcpos_dir = path_join( $wp_plugins_dir, '/woocommerce-pos' );

// Require the composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once "{$wp_tests_dir}/includes/functions.php";

tests_add_filter( 'muplugins_loaded', function () {
	load_plugins();
} );

tests_add_filter( 'init', function () {
	install_woocommerce();
} );

tests_add_filter( 'plugins_loaded', function () {
	// Only setup Polylang if explicitly requested
	if ( fb_test_plugin_requested( 'polylang' ) ) {
		activate_polylang();
		setup_basic_polylang_language();
	}
} );

// Start up the WP testing environment.
require "{$wp_tests_dir}/includes/bootstrap.php";

// Include WooCommerce test helpers
$wc_tests_dir = $wc_dir . '/tests';
if ( file_exists( $wc_dir . '/tests/legacy/bootstrap.php' ) ) {
	$wc_tests_dir .= '/legacy';
}

require_once $wc_tests_dir . '/framework/helpers/class-wc-helper-product.php';
require_once $wc_tests_dir . '/framework/helpers/class-wc-helper-shipping.php';
require_once $wc_tests_dir . '/framework/helpers/class-wc-helper-customer.php';
require_once $wc_tests_dir . '/framework/vendor/class-wp-test-spy-rest-server.php';

/**
 * Load WooCommerce for testing
 *
 * @global $wc_dir
 */
function install_woocommerce() {
	global $wc_dir;

	define( 'WP_UNINSTALL_PLUGIN', true );
	define( 'WC_REMOVE_ALL_DATA', true );

	include $wc_dir . '/uninstall.php';

	WC_Install::install();

	// Initialize the WC Admin extension.
	if ( class_exists( '\Automattic\WooCommerce\Internal\Admin\Install' ) ) {
		\Automattic\WooCommerce\Internal\Admin\Install::create_tables();
		\Automattic\WooCommerce\Internal\Admin\Install::create_events();
	} elseif ( class_exists( '\Automattic\WooCommerce\Admin\Install' ) ) {
		\Automattic\WooCommerce\Admin\Install::create_tables();
		\Automattic\WooCommerce\Admin\Install::create_events();
	}

	// Reload capabilities after install, see https://core.trac.wordpress.org/ticket/28374.
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_roles();

	echo "Installing WooCommerce..." . PHP_EOL;
}

/**
 * Manually load plugins
 *
 * @global $fb_dir
 * @global $wc_dir
 * @global $polylang_dir
 */
function load_plugins() {
	global $fb_dir;
	global $wc_dir;
	global $polylang_dir;
	global $wcpos_dir;

	require_once( $wc_dir . '/woocommerce.php' );
	update_option( 'woocommerce_db_version', WC()->version );

	// Load Polylang if it exists (for localization integration tests)
	if ( file_exists( "{$polylang_dir}/polylang.php" ) ) {
		require_once( $polylang_dir . '/polylang.php' );
		echo "Loading Polylang for localization integration tests..." . PHP_EOL;

		// Setup basic Polylang configuration for testing
		setup_polylang_for_tests();
	}

	// Load WCPOS only when requested. It hooks into orders, REST routes and rewrites,
	// so loading it for every run would change the environment for unrelated tests.
	if ( fb_test_plugin_requested( 'wcpos' ) && file_exists( "{$wcpos_dir}/woocommerce-pos.php" ) ) {
		require_once $wcpos_dir . '/woocommerce-pos.php';
		echo 'Loading WCPOS for point-of-sale contract tests...' . PHP_EOL;
	}

	require $fb_dir . '/facebook-for-woocommerce.php';
}

/**
 * Determines whether a third-party plugin was requested for this test run.
 *
 * FB_TEST_PLUGIN holds a comma-separated list, e.g. "polylang,wcpos".
 *
 * @param string $slug the plugin identifier.
 * @return bool
 */
function fb_test_plugin_requested( string $slug ): bool {
	$requested = array_map( 'trim', explode( ',', (string) getenv( 'FB_TEST_PLUGIN' ) ) );

	return in_array( $slug, $requested, true );
}

/**
 * Setup Polylang configuration for integration tests
 */
function setup_polylang_for_tests() {
	// Only setup if we're running localization tests. Polylang may be one of several requested plugins.
	if ( ! fb_test_plugin_requested( 'polylang' ) ) {
		return;  // Skip Polylang setup if not explicitly requested
	}

	// Define Polylang constants for proper initialization
	if ( ! defined( 'PLL_ADMIN' ) ) {
		define( 'PLL_ADMIN', true );
	}

	// Prevent textdomain loading issues
	if ( ! defined( 'PLL_SETTINGS_MODULES' ) ) {
		define( 'PLL_SETTINGS_MODULES', false );
	}

	// Note: Polylang activation and setup is now handled by tests_add_filter('plugins_loaded')
}

/**
 * Properly activate Polylang after WordPress is fully loaded
 */
function activate_polylang() {
	global $polylang_dir;

	$polylang_file = $polylang_dir . '/polylang.php';
	if ( file_exists( $polylang_file ) ) {
		$polylang_basename = plugin_basename( $polylang_file );

		// Ensure plugin.php functions are available
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Activate the plugin properly
		$result = activate_plugin( $polylang_basename );

		if ( is_wp_error( $result ) ) {
			echo "Failed to activate Polylang: " . $result->get_error_message() . PHP_EOL;
		}
	}
}

/**
 * Set up English, Spanish, French, and German languages for Polylang testing
 */
function setup_basic_polylang_language() {
	// Set up minimal Polylang options
	$polylang_options = get_option( 'polylang', [] );
	if ( empty( $polylang_options ) || empty( $polylang_options['default_lang'] ) ) {
		$default_options = [
			'version' => '3.7.3',
			'default_lang' => 'en',
			'rewrite' => 1,
			'hide_default' => 0,
			'force_lang' => 0,
			'redirect_lang' => 0,
			'media_support' => 1,
			'uninstall' => 0,
			'sync' => [],
			'post_types' => [ 'post', 'page', 'product' ],
			'taxonomies' => [ 'category', 'post_tag', 'product_cat', 'product_tag' ],
			'domains' => [],
			'language_taxonomies' => [ 'language', 'post_language', 'term_language' ],
		];

		update_option( 'polylang', $default_options );
		echo "Polylang options configured for testing" . PHP_EOL;
	}

	// Create language taxonomy terms directly since Polylang's API might not be ready
	$languages_to_add = [
		[
			'name'        => 'English',
			'slug'        => 'en',
			'locale'      => 'en_US',
			'rtl'         => false,
			'term_group'  => 1,
			'flag'        => 'us',
		],
		[
			'name'        => 'Español',
			'slug'        => 'es',
			'locale'      => 'es_ES',
			'rtl'         => false,
			'term_group'  => 2,
			'flag'        => 'es',
		],
		[
			'name'        => 'Français',
			'slug'        => 'fr',
			'locale'      => 'fr_FR',
			'rtl'         => false,
			'term_group'  => 3,
			'flag'        => 'fr',
		],
		[
			'name'        => 'Deutsch',
			'slug'        => 'de',
			'locale'      => 'de_DE',
			'rtl'         => false,
			'term_group'  => 4,
			'flag'        => 'de',
		]
	];

	foreach ( $languages_to_add as $lang_data ) {
		// Check if language term already exists
		$existing_term = get_term_by( 'slug', $lang_data['slug'], 'language' );
		if ( $existing_term ) {
			continue;
		}

		// Prepare language data as Polylang expects it (serialized in description field)
		$language_description = serialize([
			'locale' => $lang_data['locale'],
			'rtl' => $lang_data['rtl'] ? 1 : 0,
			'flag_code' => $lang_data['flag']
		]);

		// Create language term with proper description data
		$term_result = wp_insert_term( $lang_data['name'], 'language', [
			'slug' => $lang_data['slug'],
			'description' => $language_description
		]);

		if ( ! is_wp_error( $term_result ) ) {
			$term_id = $term_result['term_id'];

			// Set term group (used by Polylang internally)
			wp_update_term( $term_id, 'language', [ 'term_group' => $lang_data['term_group'] ] );

			// Create corresponding post_language and term_language taxonomy terms
			foreach ( [ 'post_language', 'term_language' ] as $taxonomy ) {
				wp_insert_term( 'pll_' . $lang_data['slug'], $taxonomy, [
					'slug' => 'pll_' . $lang_data['slug'],
					'description' => 'Language term for ' . $lang_data['name']
				]);
			}
		}
	}

	// Set default language in options - this is crucial for pll_default_language() to work
	$polylang_options = get_option( 'polylang', [] );
	$polylang_options['default_lang'] = 'en';
	update_option( 'polylang', $polylang_options );

	// Also ensure the Polylang global object has the right options if it's initialized
	if ( isset( $GLOBALS['polylang'] ) && is_object( $GLOBALS['polylang'] ) ) {
		if ( property_exists( $GLOBALS['polylang'], 'options' ) && is_object( $GLOBALS['polylang']->options ) ) {
			// Force the options to include our default language
			if ( method_exists( $GLOBALS['polylang']->options, 'set' ) ) {
				$GLOBALS['polylang']->options->set( 'default_lang', 'en' );
			}
		}
	}

	echo "Default language set to English (en)" . PHP_EOL;

	// Verify language setup silently
	if ( function_exists( 'pll_languages_list' ) ) {
		pll_languages_list( [ 'fields' => '' ] );
	}

	if ( function_exists( 'pll_default_language' ) ) {
		pll_default_language();
	}
}

/**
 * Checks whether a file exists and throws an error if it doesn't.
 *
 * @param string $file_name
 */
function validate_file_exits( string $file_name ) {
	if ( ! file_exists( $file_name ) ) {
		echo "Could not find {$file_name}, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( 1 );
	}
}

/**
 * @param string $base
 * @param string $path
 *
 * @return string
 */
function path_join( string $base, string $path ) {
	return rtrim( $base, '/\\' ) . '/' . ltrim( $path, '/\\' );
}

// Load WooCommerce meta box functions for tests
if ( ! function_exists( 'woocommerce_wp_hidden_input' ) ) {
	$wc_meta_functions_file = $wc_dir . '/includes/admin/wc-meta-box-functions.php';
	if ( file_exists( $wc_meta_functions_file ) ) {
		include_once $wc_meta_functions_file;
	}
}

// Mock WooCommerce function if still not available
if ( ! function_exists( 'woocommerce_wp_hidden_input' ) ) {
	function woocommerce_wp_hidden_input( $args ) {
		echo '<input type="hidden" id="' . esc_attr( $args['id'] ) . '" name="' . esc_attr( $args['name'] ) . '" value="' . esc_attr( $args['value'] ) . '">';
	}
}
