<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * Fallback crash handling for fatals raised before the main crash handler registers.
 * Loaded by the main plugin file before Composer's autoloader; depends on nothing but
 * CrashGuardState, which is loaded the same way, and degrades without it.
 *
 * @package FacebookCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'wc_facebook_handle_bootstrap_crash' ) ) {
	return;
}

/**
 * Fallback crash handling for fatals raised before PluginCrashHandler registers.
 *
 * Applies the same trust policy as the main handler. A trusted request (WP-CLI, cron, or a
 * user who can manage WooCommerce or the site) may pause the plugin on its streak. An
 * untrusted or unresolved request is only ever counted as unverified, because no self-check
 * can run this early, so an outsider cannot switch the integration off through a bootstrap
 * fatal either.
 *
 * @since 3.7.9
 *
 * @param array|null $error      error_get_last() payload.
 * @param string     $plugin_dir plugin directory used to attribute the fatal.
 * @return string|null 'recorded', 'paused' or 'skipped' when the fatal was the plugin's; null otherwise.
 */
function wc_facebook_handle_bootstrap_crash( $error, $plugin_dir ) {
	if ( ! is_array( $error ) || empty( $error['type'] ) ) {
		return null;
	}

	if ( ! in_array( (int) $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
		return null;
	}

	$error_file = isset( $error['file'] ) ? (string) $error['file'] : '';
	if ( '' === $error_file ) {
		return null;
	}

	$normalized_error_file = str_replace( '\\', '/', $error_file );
	$normalized_plugin_dir = rtrim( str_replace( '\\', '/', (string) $plugin_dir ), '/' );
	if ( 0 !== strpos( $normalized_error_file, $normalized_plugin_dir . '/' ) ) {
		return null;
	}

	$message = isset( $error['message'] ) ? (string) $error['message'] : '';
	$line    = isset( $error['line'] ) ? (int) $error['line'] : 0;

	if ( class_exists( '\\WooCommerce\\Facebook\\Framework\\CrashGuardState' ) ) {
		$request_class = \WooCommerce\Facebook\Framework\CrashGuardState::get_request_class();
		$may_pause     = \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_TRUSTED === $request_class;
		$result        = \WooCommerce\Facebook\Framework\CrashGuardState::record_crash(
			$request_class,
			null,
			$may_pause,
			$may_pause ? '' : 'bootstrap crash: no self-check can run before the crash handler is registered'
		);

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'Meta for WooCommerce crash guard (bootstrap): "%s" in %s:%d. %s',
				$message,
				$error_file,
				$line,
				\WooCommerce\Facebook\Framework\CrashGuardState::describe_decision( $result )
			)
		);

		return 'recorded';
	}

	// Last resort: even the state class could not be loaded, so there is no streak and no
	// unverified record to keep. Only a trusted request may pause, for the shortest window.
	$trusted = ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON );
	if ( ! $trusted && function_exists( 'current_user_can' ) && function_exists( 'wp_get_current_user' ) ) {
		try {
			$trusted = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	if ( ! $trusted ) {
		error_log( sprintf( 'Meta for WooCommerce crash guard (bootstrap): "%s" in %s:%d. Crash guard state unavailable; untrusted request, not paused.', $message, $error_file, $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return 'skipped';
	}

	$wp_content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( $normalized_plugin_dir, 2 );
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
		return 'skipped';
	}

	if ( ! is_dir( $flag_dir ) ) {
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $flag_dir );
		} else {
			@mkdir( $flag_dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
	}

	@file_put_contents( $flag_file, $payload_json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	error_log( sprintf( 'Meta for WooCommerce crash guard (bootstrap): "%s" in %s:%d. Crash guard state unavailable; trusted request, paused for 10 minutes.', $message, $error_file, $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

	return 'paused';
}
