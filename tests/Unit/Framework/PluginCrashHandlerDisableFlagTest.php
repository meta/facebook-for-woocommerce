<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Framework;

use ReflectionClass;
use WooCommerce\Facebook\Framework\CrashGuardState;
use WooCommerce\Facebook\Framework\PluginCrashHandler;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for how PluginCrashHandler feeds the crash guard at shutdown.
 *
 * @since 3.6.4
 */
class PluginCrashHandlerDisableFlagTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** Fixed "now" so the tests never depend on wall-clock time. */
	private const T0 = 1700000000;

	/** @var PluginCrashHandler handler whose clock the test controls through its public $now. */
	private $handler;

	/** @var ReflectionClass */
	private $reflection;

	/** @var bool */
	private $original_plugin_initialized;

	public function setUp(): void {
		parent::setUp();

		$this->handler = new class() extends PluginCrashHandler {
			/** @var int */
			public $now = 1700000000;

			/** @var callable|null stands in for the loopback: receives the URL, may record a verdict, returns a response. */
			public $self_check_behaviour = null;

			/** @var string[] URLs the self-check requested. */
			public $self_check_urls = [];

			protected function get_current_time() {
				return $this->now;
			}

			protected function perform_self_check_request( $url ) {
				$this->self_check_urls[] = $url;

				if ( is_callable( $this->self_check_behaviour ) ) {
					return call_user_func( $this->self_check_behaviour, $url );
				}

				return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
			}
		};

		$this->reflection                  = new ReflectionClass( PluginCrashHandler::class );
		$this->original_plugin_initialized = (bool) $this->get_static( 'plugin_initialized' );
		$this->set_static( 'plugin_initialized', true );

		CrashGuardState::clear();
		delete_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		unset( $_GET[ PluginCrashHandler::SELF_CHECK_QUERY_ARG ], $_SERVER['REQUEST_URI'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		PluginCrashHandler::detect_self_check_request();
		$this->set_static( 'plugin_initialized', $this->original_plugin_initialized );
		CrashGuardState::clear();
		delete_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
		parent::tearDown();
	}

	public function test_plugin_crash_is_counted_and_summarized_without_pausing(): void {
		$this->simulate_plugin_crash( self::T0 );

		$state = CrashGuardState::read( self::T0 );
		$this->assertSame( 1, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
		$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 ), 'One crash must not pause the plugin.' );

		$summary = get_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
		$this->assertIsArray( $summary );
		$this->assertSame( 42, $summary['line'] );
		$this->assertSame( 'TypeError', $summary['class'] );
		$this->assertNotSame( '', $summary['message'] );
	}

	public function test_three_spaced_plugin_crashes_pause_the_plugin(): void {
		// Anonymous crashes pause only once a plain request is shown to crash too.
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CRASHED );

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$state = CrashGuardState::read( self::T0 + 60 );
		$this->assertTrue( CrashGuardState::is_disabled( $state, self::T0 + 61 ) );
		$this->assertSame( 1, $state['level'] );
		$this->assertSame( self::T0 + 60 + 10 * MINUTE_IN_SECONDS, $state['disabled_until'] );
	}

	public function test_healthy_shutdown_resets_the_streak(): void {
		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );

		$this->simulate_healthy_shutdown( self::T0 + 40 );

		$this->assertSame( 0, CrashGuardState::read( self::T0 + 40 )['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
	}

	public function test_healthy_shutdown_in_disabled_mode_does_not_reset_the_streak(): void {
		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );

		$this->set_static( 'plugin_initialized', false );
		$this->simulate_healthy_shutdown( self::T0 + 40 );

		$this->assertSame(
			2,
			CrashGuardState::read( self::T0 + 40 )['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'],
			'A request that never bootstrapped the plugin is not evidence that it works.'
		);
	}

	public function test_crash_outside_the_plugin_is_ignored(): void {
		$this->simulate_crash( self::T0, '/srv/www/wp-content/plugins/some-other-plugin/other.php' );

		$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 ) ) );
		$this->assertFalse( get_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT ) );
	}

	public function test_uncaught_exception_is_forwarded_to_the_previous_handler(): void {
		$forwarded = null;
		$this->set_instance(
			'previous_exception_handler',
			static function ( \Throwable $throwable ) use ( &$forwarded ) {
				$forwarded = $throwable;
			}
		);

		$throwable = new \RuntimeException( 'forwarded' );
		$this->handler->handle_uncaught_exception( $throwable );

		$this->assertSame( $throwable, $forwarded );
	}

	public function test_uncaught_exception_is_rethrown_when_there_is_no_previous_handler(): void {
		$this->set_instance( 'previous_exception_handler', null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'rethrown' );

		$this->handler->handle_uncaught_exception( new \RuntimeException( 'rethrown' ) );
	}

	public function test_rethrown_throwable_is_recognized_in_error_get_last_payload(): void {
		$method = $this->reflection->getMethod( 'is_rethrown_captured_throwable' );
		$method->setAccessible( true );

		$captured = [
			'type'            => E_ERROR,
			'message'         => 'Boom',
			'file'            => '/plugin/includes/Foo.php',
			'line'            => 7,
			'exception_class' => 'TypeError',
			'trace'           => [],
			'source'          => 'uncaught_exception',
		];
		$fatal    = [
			'type'            => E_ERROR,
			'message'         => "Uncaught TypeError: Boom in /plugin/includes/Foo.php:7\nStack trace:\n#0 {main}",
			'file'            => '/plugin/includes/Foo.php',
			'line'            => 7,
			'exception_class' => '',
			'trace'           => [],
			'source'          => 'fatal_error',
		];

		$this->assertTrue( $method->invoke( $this->handler, $fatal, $captured ) );
		$this->assertFalse( $method->invoke( $this->handler, array_merge( $fatal, [ 'line' => 8 ] ), $captured ), 'A different line is a different error.' );
		$this->assertFalse( $method->invoke( $this->handler, array_merge( $fatal, [ 'message' => 'Allowed memory size exhausted' ] ), $captured ), 'A plain fatal is not a rethrown throwable.' );
		$this->assertFalse( $method->invoke( $this->handler, $fatal, null ) );
	}

	public function test_last_crash_summary_is_a_single_trimmed_line(): void {
		$this->handler->now = self::T0;
		$this->set_instance(
			'captured_throwable_error',
			[
				'type'            => E_ERROR,
				'message'         => "First line of the message\nStack trace:\n#0 should not appear",
				'file'            => WC_FACEBOOK_PLUGIN_PATH . 'includes/Framework/Fake.php',
				'line'            => 42,
				'exception_class' => 'TypeError',
				'trace'           => [],
				'source'          => 'uncaught_exception',
			]
		);
		$this->handler->handle_shutdown();

		$summary = get_transient( PluginCrashHandler::LAST_CRASH_TRANSIENT );
		$this->assertSame( 'First line of the message', $summary['message'] );
	}

	public function test_self_check_that_runs_cleanly_prevents_the_pause(): void {
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CLEAN );

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->assertCount( 0, $this->handler->self_check_urls, 'No self-check before a pause is imminent.' );

		$this->simulate_plugin_crash( self::T0 + 60 );

		$this->assertCount( 1, $this->handler->self_check_urls );
		$state = CrashGuardState::read( self::T0 + 60 );
		$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 61 ), 'A crash that a plain request cannot reproduce must not pause the plugin.' );
		$this->assertSame( 1, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'], 'The self-check counts as the healthy request; this crash starts a new streak.' );

		$token = $this->token_from_url( $this->handler->self_check_urls[0] );
		$this->assertFalse( get_transient( PluginCrashHandler::SELF_CHECK_PENDING_PREFIX . $token ), 'The one-time token is withdrawn afterwards.' );
		$this->assertFalse( get_transient( PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token ) );
	}

	public function test_self_check_that_crashes_confirms_the_pause(): void {
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CRASHED );

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$this->assertCount( 1, $this->handler->self_check_urls );
		$state = CrashGuardState::read( self::T0 + 60 );
		$this->assertTrue( CrashGuardState::is_disabled( $state, self::T0 + 61 ) );
		$this->assertSame( 1, $state['level'] );
	}

	/**
	 * @dataProvider inconclusive_self_check_provider
	 */
	public function test_self_check_without_a_verdict_counts_as_unverified_instead_of_pausing( callable $behaviour ): void {
		$this->handler->self_check_behaviour = $behaviour;

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$this->assertCount( 1, $this->handler->self_check_urls );
		$state = CrashGuardState::read( self::T0 + 60 );
		$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 61 ), 'Without a verdict an outsider must not be able to pause the plugin.' );
		$this->assertSame( 1, $state['unverified']['count'] );
		$this->assertStringStartsWith( 'inconclusive', $state['unverified']['reason'] );
		$this->assertSame( 3, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
	}

	public function test_unverified_crashes_are_resolved_by_a_later_verdict(): void {
		$this->handler->self_check_behaviour = static function () {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		};
		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 60 ), self::T0 + 61 ) );

		// Loopback works again and the plain request crashes too: now it pauses.
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CRASHED );
		$this->simulate_plugin_crash( self::T0 + 90 );

		$state = CrashGuardState::read( self::T0 + 90 );
		$this->assertTrue( CrashGuardState::is_disabled( $state, self::T0 + 91 ) );
		$this->assertSame( 0, $state['unverified']['count'] );
	}

	public function test_anonymous_ajax_crash_is_self_checked_against_the_home_page(): void {
		$this->add_filter_with_safe_teardown( 'wp_doing_ajax', '__return_true' );
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CLEAN );
		$_SERVER['REQUEST_METHOD']           = 'POST';
		$_SERVER['REQUEST_URI']              = '/wp-admin/admin-ajax.php';

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$this->assertCount( 1, $this->handler->self_check_urls, 'An anonymous admin-ajax crash is untrusted and gets the self-check.' );
		$this->assertStringStartsWith( home_url( '/' ) . '?', $this->handler->self_check_urls[0] );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 60 ), self::T0 + 61 ) );
	}

	public function test_logged_in_customer_is_untrusted(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CLEAN );

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$this->assertCount( 1, $this->handler->self_check_urls, 'A customer login proves nothing; the self-check still decides.' );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 60 ), self::T0 + 61 ) );
	}

	public function inconclusive_self_check_provider(): array {
		return [
			'cached 200 that never ran PHP' => [
				static function () {
					return [ 'response' => [ 'code' => 200 ], 'body' => '<html>cached</html>' ];
				},
			],
			'loopback blocked'              => [
				static function () {
					return new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
				},
			],
			'auth wall'                     => [
				static function () {
					return [ 'response' => [ 'code' => 401 ], 'body' => '' ];
				},
			],
		];
	}

	public function test_self_check_targets_the_crashing_path_without_anything_the_client_sent(): void {
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CLEAN );
		$_SERVER['REQUEST_METHOD']           = 'GET';
		$_SERVER['REQUEST_URI']              = '/product/beanie/?fbclid%5Bx%5D=1&utm_source=x';

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$url = $this->handler->self_check_urls[0];
		$this->assertStringStartsWith( home_url( '/product/beanie/' ), $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( [ PluginCrashHandler::SELF_CHECK_QUERY_ARG ], array_keys( $query ), 'Only the token travels; the crashing request\'s query string does not.' );

		CrashGuardState::clear();
		$this->handler->self_check_urls = [];
		$_SERVER['REQUEST_METHOD']      = 'POST';

		$this->simulate_plugin_crash( self::T0 + 1000 );
		$this->simulate_plugin_crash( self::T0 + 1030 );
		$this->simulate_plugin_crash( self::T0 + 1060 );

		$this->assertStringStartsWith( home_url( '/' ) . '?', $this->handler->self_check_urls[0], 'Non-GET requests are checked against the home page.' );
	}

	public function test_delayed_verdict_is_found_on_retry_with_a_persistent_object_cache(): void {
		// wp_using_ext_object_cache() returns null until WordPress decides, and passing null back is a
		// no-op, so the flag must be restored as a boolean or it would leak into later tests.
		$was_using_ext_cache = (bool) wp_using_ext_object_cache( true );
		$token               = '';

		// The response comes back before the self-check request has stored its verdict; the verdict
		// lands in the shared cache during the retry delay. Deleting the cache entry would destroy it.
		$this->handler->self_check_behaviour = function ( string $url ) use ( &$token ) {
			$token = $this->token_from_url( $url );
			$key   = PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token;
			$first = true;

			$this->add_filter_with_safe_teardown(
				'pre_transient_' . $key,
				static function ( $pre ) use ( &$first, $key ) {
					if ( $first ) {
						$first = false;
						wp_cache_set( $key, PluginCrashHandler::SELF_CHECK_CLEAN, 'transient', MINUTE_IN_SECONDS );
						return ''; // First read: nothing there yet.
					}
					return $pre;
				}
			);

			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		};

		try {
			$this->simulate_plugin_crash( self::T0 );
			$this->simulate_plugin_crash( self::T0 + 30 );
			$this->simulate_plugin_crash( self::T0 + 60 );

			$state = CrashGuardState::read( self::T0 + 60 );
			$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 61 ), 'A verdict that lands during the retry delay must be honored, not deleted.' );
			$this->assertSame( 1, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
		} finally {
			if ( '' !== $token ) {
				wp_cache_delete( PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token, 'transient' );
				wp_cache_delete( PluginCrashHandler::SELF_CHECK_PENDING_PREFIX . $token, 'transient' );
			}
			wp_using_ext_object_cache( $was_using_ext_cache );
		}
	}

	public function test_delayed_verdict_is_found_on_retry_with_the_options_store(): void {
		global $wpdb;
		$was_using_ext_cache = (bool) wp_using_ext_object_cache( false );
		$token               = '';

		// Here the first read is a real miss, which WordPress memoizes for the request. The verdict is
		// then written by "another process" straight into the options table, so only a retry that
		// forgets the memo can see it.
		$this->handler->self_check_behaviour = function ( string $url ) use ( &$token, $wpdb ) {
			$token = $this->token_from_url( $url );
			$key   = PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token;
			$first = true;

			$this->add_filter_with_safe_teardown(
				'transient_' . $key,
				static function ( $value ) use ( &$first, $key, $wpdb ) {
					if ( $first && false === $value ) {
						$first = false;
						$wpdb->insert( $wpdb->options, [ 'option_name' => '_transient_timeout_' . $key, 'option_value' => (string) ( time() + MINUTE_IN_SECONDS ), 'autoload' => 'no' ] );
						$wpdb->insert( $wpdb->options, [ 'option_name' => '_transient_' . $key, 'option_value' => PluginCrashHandler::SELF_CHECK_CLEAN, 'autoload' => 'no' ] );
					}
					return $value;
				}
			);

			return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
		};

		try {
			$this->simulate_plugin_crash( self::T0 );
			$this->simulate_plugin_crash( self::T0 + 30 );
			$this->simulate_plugin_crash( self::T0 + 60 );

			$state = CrashGuardState::read( self::T0 + 60 );
			$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 61 ), 'The retry must get past the memoized miss and find the stored verdict.' );
			$this->assertSame( 1, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
		} finally {
			wp_using_ext_object_cache( $was_using_ext_cache );
		}
	}

	public function test_trusted_crashes_pause_on_their_streak_without_a_self_check(): void {
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CLEAN );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->assertSame( CrashGuardState::REQUEST_CLASS_TRUSTED, CrashGuardState::get_request_class(), 'Precondition: a manager is logged in.' );

		$this->simulate_plugin_crash( self::T0 );
		$this->simulate_plugin_crash( self::T0 + 30 );
		$this->simulate_plugin_crash( self::T0 + 60 );

		$this->assertCount( 0, $this->handler->self_check_urls, 'Trusted input needs no self-check.' );

		$state = CrashGuardState::read( self::T0 + 60 );
		$this->assertTrue( CrashGuardState::is_disabled( $state, self::T0 + 61 ), 'A crash under a manager still reaches recovery.' );
		$this->assertSame( 1, $state['level'] );
		$this->assertSame( 0, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
	}

	public function test_a_stale_preview_cannot_pause_without_a_verdict_of_its_own(): void {
		// Request A sees a streak of one in its unlocked preview, so it runs no self-check...
		CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 );
		$this->handler->self_check_behaviour = $this->self_check_recording( PluginCrashHandler::SELF_CHECK_CRASHED );

		// ...while request B, holding the writer lock, lands an unverified third crash before A can write.
		$state_from_b                  = CrashGuardState::read( self::T0 + 60 );
		$state_from_b['revision']      = 9;
		$state_from_b['updated_at']    = self::T0 + 60;
		$state_from_b['last_crash_at'] = self::T0 + 60;
		$state_from_b['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ] = [
			'count'           => 3,
			'last_counted_at' => self::T0 + 30,
		];
		$state_from_b['unverified']    = [
			'count'   => 1,
			'last_at' => self::T0 + 60,
			'reason'  => 'inconclusive: loopback blocked',
		];
		$holder = $this->start_lock_holder( 700, sprintf( 'ftruncate($h, 0); rewind($h); fwrite($h, %s);', var_export( wp_json_encode( $state_from_b ), true ) ) );

		try {
			$this->simulate_plugin_crash( self::T0 + 90 );
		} finally {
			$this->finish_lock_holder( $holder );
		}

		$this->assertCount( 0, $this->handler->self_check_urls, 'Precondition: A previewed a streak of one and ran no self-check.' );

		$state = CrashGuardState::read( self::T0 + 90 );
		$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 91 ), 'A crash with no verdict of its own must never pause, however the state changed meanwhile.' );
		$this->assertSame( 2, $state['unverified']['count'] );
		$this->assertStringContainsString( 'concurrently', $state['unverified']['reason'] );
		$this->assertSame( 3, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'], 'The streak stays at the threshold so the next crash asks the self-check.' );
	}

	/**
	 * Starts a PHP subprocess that takes the writer lock on the flag file, signals that it holds
	 * it, keeps it for $hold_ms, runs $body_php (with $h as the locked handle), then releases it.
	 *
	 * @param int    $hold_ms  how long to hold the lock before running the body.
	 * @param string $body_php PHP statements to run while holding the lock.
	 * @return array{0: resource, 1: array, 2: string} process handle, pipes, script path.
	 */
	private function start_lock_holder( int $hold_ms, string $body_php ): array {
		$script_path = tempnam( sys_get_temp_dir(), 'fbwc-lock-holder-' );
		$marker_path = $script_path . '.locked';

		$script = sprintf(
			'<?php $h = fopen(%s, "c+"); flock($h, LOCK_EX); touch(%s); usleep(%d); %s fflush($h); flock($h, LOCK_UN); fclose($h);',
			var_export( CrashGuardState::get_flag_file_path(), true ),
			var_export( $marker_path, true ),
			$hold_ms * 1000,
			$body_php
		);
		file_put_contents( $script_path, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$pipes   = [];
		$process = proc_open( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script_path ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$this->assertIsResource( $process );

		$deadline = microtime( true ) + 5;
		while ( ! file_exists( $marker_path ) && microtime( true ) < $deadline ) {
			usleep( 20000 );
			clearstatcache( true, $marker_path );
		}
		$this->assertFileExists( $marker_path, 'Lock holder subprocess did not take the lock in time.' );

		return [ $process, $pipes, $script_path ];
	}

	private function finish_lock_holder( array $holder ): void {
		list( $process, $pipes, $script_path ) = $holder;

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		@unlink( $script_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $script_path . '.locked' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( 0, $exit_code, 'Lock holder subprocess failed: ' . $stderr );
	}

	public function test_self_check_request_records_a_verdict_instead_of_counting(): void {
		$token = 'abc123def456';
		set_transient( PluginCrashHandler::SELF_CHECK_PENDING_PREFIX . $token, 1, MINUTE_IN_SECONDS );
		$_GET[ PluginCrashHandler::SELF_CHECK_QUERY_ARG ] = $token;

		$this->assertTrue( PluginCrashHandler::detect_self_check_request() );
		$this->assertTrue( PluginCrashHandler::is_self_check_request() );

		// A streak from earlier must be left alone by the self-check's own shutdown.
		CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 );
		CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 30 );

		$this->simulate_healthy_shutdown( self::T0 + 40 );
		$this->assertSame( PluginCrashHandler::SELF_CHECK_CLEAN, get_transient( PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token ) );
		$this->assertSame( 2, CrashGuardState::read( self::T0 + 40 )['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'], 'A self-check is not a visitor and resets nothing itself.' );

		$this->simulate_plugin_crash( self::T0 + 70 );
		$this->assertSame( PluginCrashHandler::SELF_CHECK_CRASHED, get_transient( PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token ) );
		$this->assertSame( 2, CrashGuardState::read( self::T0 + 70 )['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'], 'A crash inside the self-check is reported through the verdict, not the streak.' );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 70 ), self::T0 + 71 ) );
	}

	public function test_self_check_request_that_never_initialized_the_plugin_is_inconclusive(): void {
		$token = 'abc123def456';
		set_transient( PluginCrashHandler::SELF_CHECK_PENDING_PREFIX . $token, 1, MINUTE_IN_SECONDS );
		$_GET[ PluginCrashHandler::SELF_CHECK_QUERY_ARG ] = $token;
		$this->assertTrue( PluginCrashHandler::detect_self_check_request() );

		// Auth middleware or an early exit ended the request before plugins_loaded ran the plugin.
		$this->set_static( 'plugin_initialized', false );
		$this->simulate_healthy_shutdown( self::T0 + 40 );

		$this->assertSame(
			PluginCrashHandler::SELF_CHECK_INCONCLUSIVE,
			get_transient( PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $token ),
			'A request that never ran the plugin cannot vouch for it.'
		);
	}

	public function test_self_check_request_requires_a_registered_token(): void {
		$_GET[ PluginCrashHandler::SELF_CHECK_QUERY_ARG ] = 'abc123def456';

		$this->assertFalse( PluginCrashHandler::detect_self_check_request(), 'A visitor cannot declare their own request a self-check.' );
		$this->assertFalse( PluginCrashHandler::is_self_check_request() );
	}

	/**
	 * Returns a self-check stand-in that records the given verdict for the token in the URL.
	 *
	 * @param string $verdict one of the SELF_CHECK_* constants.
	 * @return callable
	 */
	private function self_check_recording( string $verdict ): callable {
		return function ( string $url ) use ( $verdict ) {
			set_transient( PluginCrashHandler::SELF_CHECK_OUTCOME_PREFIX . $this->token_from_url( $url ), $verdict, MINUTE_IN_SECONDS );

			return [ 'response' => [ 'code' => PluginCrashHandler::SELF_CHECK_CRASHED === $verdict ? 500 : 200 ], 'body' => '' ];
		};
	}

	private function token_from_url( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) ( $query[ PluginCrashHandler::SELF_CHECK_QUERY_ARG ] ?? '' );
	}

	private function simulate_plugin_crash( int $at ): void {
		$this->simulate_crash( $at, WC_FACEBOOK_PLUGIN_PATH . 'includes/Framework/Fake.php' );
	}

	private function simulate_crash( int $at, string $file ): void {
		$this->handler->now = $at;
		$this->set_instance(
			'captured_throwable_error',
			[
				'type'            => E_ERROR,
				'message'         => 'Boom for test@example.com',
				'file'            => $file,
				'line'            => 42,
				'exception_class' => 'TypeError',
				'trace'           => [],
				'source'          => 'uncaught_exception',
			]
		);
		$this->handler->handle_shutdown();
	}

	private function simulate_healthy_shutdown( int $at ): void {
		$this->handler->now = $at;
		$this->set_instance( 'captured_throwable_error', null );
		$this->handler->handle_shutdown();
	}

	private function get_static( string $name ) {
		$property = $this->reflection->getProperty( $name );
		$property->setAccessible( true );
		return $property->getValue();
	}

	private function set_static( string $name, $value ): void {
		$property = $this->reflection->getProperty( $name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}

	private function set_instance( string $name, $value ): void {
		$property = $this->reflection->getProperty( $name );
		$property->setAccessible( true );
		$property->setValue( $this->handler, $value );
	}
}
