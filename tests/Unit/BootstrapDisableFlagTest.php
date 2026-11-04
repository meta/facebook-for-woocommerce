<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests;

use ReflectionClass;
use WooCommerce\Facebook\Framework\ErrorLogHandler;

/**
 * Unit test for bootstrap disable-flag behavior.
 *
 * @since 3.6.4
 */
class BootstrapDisableFlagTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var \WC_Facebook_Loader */
	private $loader;

	/** @var ReflectionClass */
	private $reflection;

	/** @var string */
	private $flag_file_path;

	public function setUp(): void {
		parent::setUp();
		$this->loader         = \WC_Facebook_Loader::instance();
		$this->reflection     = new ReflectionClass( \WC_Facebook_Loader::class );
		$this->flag_file_path = trailingslashit( WP_CONTENT_DIR ) . \WC_Facebook_Loader::DISABLE_FLAG_FILE_RELATIVE_PATH;

		delete_transient( \WC_Facebook_Loader::DISABLE_FLAG_TRANSIENT );
		if ( file_exists( $this->flag_file_path ) ) {
			@unlink( $this->flag_file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	public function tearDown(): void {
		delete_transient( \WC_Facebook_Loader::DISABLE_FLAG_TRANSIENT );
		if ( file_exists( $this->flag_file_path ) ) {
			@unlink( $this->flag_file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		remove_action( ErrorLogHandler::META_LOG_API, [ $this->loader, 'handle_disabled_mode_crash_report' ], 10 );
		parent::tearDown();
	}

	public function test_active_disable_flag_detected_and_full_init_is_skipped(): void {
		set_transient(
			\WC_Facebook_Loader::DISABLE_FLAG_TRANSIENT,
			[
				'timestamp'   => time(),
				'crash_count' => 1,
			],
			HOUR_IN_SECONDS
		);

		$has_valid_disable_flag = $this->reflection->getMethod( 'has_valid_disable_flag' );
		$has_valid_disable_flag->setAccessible( true );
		$this->assertTrue( $has_valid_disable_flag->invoke( $this->loader ) );

		$this->assert_init_skipped_when_disabled();
	}

	public function test_active_file_disable_flag_detected_and_full_init_is_skipped(): void {
		$flag_dir = dirname( $this->flag_file_path );
		if ( ! is_dir( $flag_dir ) ) {
			wp_mkdir_p( $flag_dir );
		}

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->flag_file_path,
			wp_json_encode(
				[
					'timestamp'   => time(),
					'crash_count' => 1,
				]
			)
		);

		$this->assertTrue( $this->has_valid_disable_flag() );

		$this->assert_init_skipped_when_disabled();
	}

	public function test_current_format_flag_with_active_pause_is_detected(): void {
		$this->write_flag_file(
			[
				'version'        => 2,
				'level'          => 1,
				'disabled_at'    => time() - 60,
				'disabled_until' => time() + 540,
				'last_crash_at'  => time() - 60,
				'updated_at'     => time() - 60,
			]
		);

		$this->assertTrue( $this->has_valid_disable_flag() );

		$this->assert_init_skipped_when_disabled();
	}

	public function test_current_format_flag_with_expired_pause_is_not_detected(): void {
		$this->write_flag_file(
			[
				'version'        => 2,
				'level'          => 1,
				'disabled_at'    => time() - 700,
				'disabled_until' => time() - 100,
				'last_crash_at'  => time() - 700,
				'updated_at'     => time() - 700,
			]
		);

		$has_valid_disable_flag = $this->reflection->getMethod( 'has_valid_disable_flag' );
		$has_valid_disable_flag->setAccessible( true );
		$this->assertFalse( $has_valid_disable_flag->invoke( $this->loader ), 'An expired pause must let the plugin boot again.' );
	}

	public function test_newer_idle_transient_overrides_a_stale_paused_file_at_bootstrap(): void {
		$this->write_flag_file( $this->active_pause_payload() );

		$tombstone = \WooCommerce\Facebook\Framework\CrashGuardState::empty_state( time() );
		set_transient( \WooCommerce\Facebook\Framework\CrashGuardState::TRANSIENT_KEY, $tombstone, HOUR_IN_SECONDS );

		$this->assertFalse( $this->has_valid_disable_flag(), 'A newer idle transient must win over the stale paused file.' );
		$this->assert_init_not_skipped();
	}

	public function test_resume_now_takes_effect_even_when_the_flag_file_cannot_be_deleted(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'File permission checks do not apply to root.' );
		}

		$this->write_flag_file( $this->active_pause_payload() );
		$this->assertTrue( $this->has_valid_disable_flag(), 'Precondition: the plugin is paused.' );

		$dir = dirname( $this->flag_file_path );
		chmod( $dir, 0555 );
		try {
			$clear = $this->reflection->getMethod( 'clear_disable_flag_state' );
			$clear->setAccessible( true );
			$this->assertTrue( (bool) $clear->invoke( $this->loader ) );
			$this->assertFileExists( $this->flag_file_path, 'Precondition: the stale file really could not be removed.' );

			$this->assertFalse( $this->has_valid_disable_flag(), 'Resume now must re-enable the plugin although the stale file remains.' );
			$this->assert_init_not_skipped();

			// A healthy shutdown afterwards must not bring the pause back.
			\WooCommerce\Facebook\Framework\CrashGuardState::record_healthy_request( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_UNTRUSTED );
			$this->assertFalse( $this->has_valid_disable_flag(), 'The loader must stay enabled after healthy shutdowns while the stale file lingers.' );
		} finally {
			chmod( $dir, 0755 );
		}
	}

	public function test_unverified_crashes_show_a_notice_but_do_not_pause(): void {
		\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_UNTRUSTED, time() - 60 );
		\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_UNTRUSTED, time() - 30 );
		\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_UNTRUSTED, time(), false, 'inconclusive: loopback blocked' );

		$this->assertFalse( $this->has_valid_disable_flag(), 'Unverified crashes never pause.' );

		$has_unverified = $this->reflection->getMethod( 'has_unverified_crashes' );
		$has_unverified->setAccessible( true );
		$this->assertTrue( (bool) $has_unverified->invoke( $this->loader ), 'The admin is told instead.' );

		$this->assert_init_not_skipped();
	}

	public function test_bootstrap_fallback_counts_an_anonymous_crash_as_unverified(): void {
		\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_UNTRUSTED, time() - 60 );
		\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_UNTRUSTED, time() - 30 );

		$this->assertSame( 'recorded', wc_facebook_handle_bootstrap_crash( $this->bootstrap_fatal(), rtrim( WC_FACEBOOK_PLUGIN_PATH, '/' ) ) );

		$this->assertFalse( $this->has_valid_disable_flag(), 'An anonymous fatal during plugin load must not pause: no self-check can vouch for it.' );
		$state = \WooCommerce\Facebook\Framework\CrashGuardState::read();
		$this->assertSame( 1, $state['unverified']['count'] );
		$this->assertStringContainsString( 'bootstrap', $state['unverified']['reason'] );
	}

	public function test_bootstrap_fallback_pauses_a_trusted_crash(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		try {
			\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_TRUSTED, time() - 60 );
			\WooCommerce\Facebook\Framework\CrashGuardState::record_crash( \WooCommerce\Facebook\Framework\CrashGuardState::REQUEST_CLASS_TRUSTED, time() - 30 );

			$this->assertSame( 'recorded', wc_facebook_handle_bootstrap_crash( $this->bootstrap_fatal(), rtrim( WC_FACEBOOK_PLUGIN_PATH, '/' ) ) );

			$this->assertTrue( $this->has_valid_disable_flag(), 'A manager\'s third crash during plugin load pauses on the streak.' );
		} finally {
			wp_set_current_user( 0 );
		}
	}

	public function test_bootstrap_fallback_ignores_fatals_outside_the_plugin(): void {
		$fatal         = $this->bootstrap_fatal();
		$fatal['file'] = '/srv/www/wp-content/plugins/some-other-plugin/other.php';

		$this->assertNull( wc_facebook_handle_bootstrap_crash( $fatal, rtrim( WC_FACEBOOK_PLUGIN_PATH, '/' ) ) );
		$this->assertNull( wc_facebook_handle_bootstrap_crash( null, rtrim( WC_FACEBOOK_PLUGIN_PATH, '/' ) ) );
		$this->assertTrue( \WooCommerce\Facebook\Framework\CrashGuardState::is_idle( \WooCommerce\Facebook\Framework\CrashGuardState::read() ) );
	}

	/**
	 * Returns an error_get_last() style payload for a fatal in a plugin bootstrap file.
	 *
	 * @return array
	 */
	private function bootstrap_fatal(): array {
		return [
			'type'    => E_ERROR,
			'message' => 'Uncaught Error: Class "Missing" not found',
			'file'    => WC_FACEBOOK_PLUGIN_PATH . 'class-wc-facebookcommerce.php',
			'line'    => 12,
		];
	}

	/**
	 * Returns a current-format payload describing an active 10-minute pause.
	 *
	 * @return array
	 */
	private function active_pause_payload(): array {
		return [
			'version'        => 2,
			'level'          => 1,
			'disabled_at'    => time() - 60,
			'disabled_until' => time() + 540,
			'last_crash_at'  => time() - 60,
			'updated_at'     => time() - 60,
		];
	}

	/**
	 * Invokes the loader's private has_valid_disable_flag().
	 *
	 * @return bool
	 */
	private function has_valid_disable_flag(): bool {
		$method = $this->reflection->getMethod( 'has_valid_disable_flag' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $this->loader );
	}

	/**
	 * Asserts that init_plugin takes the normal path rather than disabled mode.
	 */
	private function assert_init_not_skipped(): void {
		remove_action( ErrorLogHandler::META_LOG_API, [ $this->loader, 'handle_disabled_mode_crash_report' ], 10 );

		$this->loader->init_plugin();

		$this->assertFalse(
			has_action( ErrorLogHandler::META_LOG_API, [ $this->loader, 'handle_disabled_mode_crash_report' ] ),
			'Disabled-mode services must not be registered when the reconciled state is idle.'
		);
	}

	/**
	 * Writes a raw payload to the flag file.
	 *
	 * @param array $payload payload to encode.
	 */
	private function write_flag_file( array $payload ): void {
		$flag_dir = dirname( $this->flag_file_path );
		if ( ! is_dir( $flag_dir ) ) {
			wp_mkdir_p( $flag_dir );
		}

		file_put_contents( $this->flag_file_path, wp_json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Asserts that init_plugin follows the disabled-mode path.
	 */
	private function assert_init_skipped_when_disabled(): void {
		$before_integration_hooks = $this->count_hook_callbacks_with_method( 'init', 'get_integration' );

		$this->loader->init_plugin();

		$this->assertNotFalse(
			has_action( ErrorLogHandler::META_LOG_API, [ $this->loader, 'handle_disabled_mode_crash_report' ] ),
			'Disabled-mode services should be registered when disable flag is active.'
		);

		$after_integration_hooks = $this->count_hook_callbacks_with_method( 'init', 'get_integration' );
		$this->assertSame(
			$before_integration_hooks,
			$after_integration_hooks,
			'Main WC_Facebookcommerce init hook should not be added during disabled bootstrap path.'
		);
	}

	/**
	 * Counts callbacks for a hook that point to a specific method name.
	 *
	 * @param string $hook hook name.
	 * @param string $method method name.
	 * @return int
	 */
	private function count_hook_callbacks_with_method( string $hook, string $method ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( is_array( $fn ) && isset( $fn[1] ) && $method === $fn[1] ) {
					$count++;
				}
			}
		}

		return $count;
	}
}
