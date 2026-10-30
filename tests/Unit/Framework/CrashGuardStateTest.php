<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Framework;

use WooCommerce\Facebook\Framework\CrashGuardState;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;

/**
 * Unit tests for the crash guard state machine.
 *
 * Every test drives the clock explicitly, so timing rules (spacing, probe grace, decay) are
 * exercised without sleeping. The tests use real options rather than the option-isolation
 * base class, because they verify how state is stored in and deleted from the transient.
 *
 * @since 3.7.9
 */
class CrashGuardStateTest extends AbstractWPUnitTestWithSafeFiltering {

	/** Fixed "now" so the tests never depend on wall-clock time. */
	private const T0 = 1700000000;

	/** @var string */
	private $flag_file_path;

	public function setUp(): void {
		parent::setUp();
		$this->flag_file_path = CrashGuardState::get_flag_file_path();
		CrashGuardState::clear();
	}

	public function tearDown(): void {
		CrashGuardState::$fail_file_writes_for_testing = false;
		CrashGuardState::clear();
		parent::tearDown();
	}

	public function test_window_ladder_escalates_and_caps_at_24_hours(): void {
		$this->assertSame( 0, CrashGuardState::get_window_seconds( 0 ) );
		$this->assertSame( 10 * MINUTE_IN_SECONDS, CrashGuardState::get_window_seconds( 1 ) );
		$this->assertSame( HOUR_IN_SECONDS, CrashGuardState::get_window_seconds( 2 ) );
		$this->assertSame( 6 * HOUR_IN_SECONDS, CrashGuardState::get_window_seconds( 3 ) );
		$this->assertSame( DAY_IN_SECONDS, CrashGuardState::get_window_seconds( 4 ) );
		$this->assertSame( DAY_IN_SECONDS, CrashGuardState::get_window_seconds( 9 ), 'There is no permanent tier: the ladder caps at 24 hours.' );
	}

	public function test_fresh_state_is_idle_and_not_disabled(): void {
		$state = CrashGuardState::read( self::T0 );

		$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 ) );
		$this->assertTrue( CrashGuardState::is_idle( $state ) );
		$this->assertFileDoesNotExist( $this->flag_file_path );
	}

	public function test_single_crash_is_counted_but_does_not_pause(): void {
		$result = $this->crash( self::T0 );

		$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'] );
		$this->assertTrue( $result['stored'] );
		$this->assertSame( 1, $this->frontend_streak( self::T0 ) );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 ), self::T0 ) );
		$this->assertFileExists( $this->flag_file_path );
	}

	public function test_crashes_within_spacing_count_once(): void {
		$this->crash( self::T0 );
		$second = $this->crash( self::T0 + 5 );
		$third  = $this->crash( self::T0 + 29 );

		$this->assertSame( CrashGuardState::DECISION_IGNORED_SPACING, $second['decision'] );
		$this->assertSame( CrashGuardState::DECISION_IGNORED_SPACING, $third['decision'] );
		$this->assertSame( 1, $this->frontend_streak( self::T0 + 29 ), 'A burst of concurrent crashes must count as one.' );

		$fourth = $this->crash( self::T0 + 30 );
		$this->assertSame( CrashGuardState::DECISION_COUNTED, $fourth['decision'] );
		$this->assertSame( 2, $this->frontend_streak( self::T0 + 30 ) );
	}

	public function test_three_spaced_crashes_pause_for_ten_minutes(): void {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );
		$result = $this->crash( self::T0 + 60 );

		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'] );
		$this->assertSame( 1, $result['state']['level'] );
		$this->assertSame( self::T0 + 60, $result['state']['disabled_at'] );
		$this->assertSame( self::T0 + 60 + 10 * MINUTE_IN_SECONDS, $result['state']['disabled_until'] );
		$this->assertSame( 0, $this->frontend_streak( self::T0 + 60 ), 'Streaks reset when a pause begins.' );

		$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 61 ), self::T0 + 61 ) );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 661 ), self::T0 + 661 ), 'The pause ends on its own.' );
	}

	public function test_healthy_request_resets_streak(): void {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );
		$this->assertSame( 2, $this->frontend_streak( self::T0 + 30 ) );

		$reset = CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 40 );

		$this->assertTrue( $reset );
		$this->assertSame( 0, $this->frontend_streak( self::T0 + 40 ) );

		$result = $this->crash( self::T0 + 70 );
		$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'] );
		$this->assertSame( 1, $this->frontend_streak( self::T0 + 70 ), 'After a healthy request the count starts over.' );
		$this->assertFalse( CrashGuardState::is_disabled( $result['state'], self::T0 + 70 ) );
	}

	public function test_healthy_request_of_other_class_does_not_reset_streak(): void {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );

		$reset = CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_TRUSTED, self::T0 + 40 );

		$this->assertFalse( $reset );
		$this->assertSame( 2, $this->frontend_streak( self::T0 + 40 ), 'An administrator browsing wp-admin must not mask a crashing storefront.' );
	}

	public function test_streaks_are_tracked_per_request_class(): void {
		$this->crash( self::T0, CrashGuardState::REQUEST_CLASS_TRUSTED );
		$this->crash( self::T0 + 30, CrashGuardState::REQUEST_CLASS_TRUSTED );
		$this->crash( self::T0 + 60, CrashGuardState::REQUEST_CLASS_UNTRUSTED );

		$state = CrashGuardState::read( self::T0 + 60 );
		$this->assertSame( 2, $state['streaks'][ CrashGuardState::REQUEST_CLASS_TRUSTED ]['count'] );
		$this->assertSame( 1, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
		$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 60 ) );
	}

	public function test_healthy_request_is_cheap_without_state_and_removes_idle_file(): void {
		$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 ) );

		$this->write_raw( CrashGuardState::empty_state( self::T0 ) );
		$this->assertFileExists( $this->flag_file_path );

		$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 1 ) );
		$this->assertFileDoesNotExist( $this->flag_file_path, 'An idle state file is removed so later requests stop reading it.' );
	}

	public function test_crash_while_paused_is_not_counted(): void {
		$until = $this->pause_at_level_one();

		$result = $this->crash( self::T0 + 100 );

		$this->assertSame( CrashGuardState::DECISION_ALREADY_DISABLED, $result['decision'] );
		$this->assertSame( 1, $result['state']['level'] );
		$this->assertSame( $until, $result['state']['disabled_until'], 'In-flight requests must not extend or escalate the pause.' );
		$this->assertSame( 0, $this->frontend_streak( self::T0 + 100 ) );
	}

	public function test_crash_after_pause_ends_counts_normally_and_a_new_streak_escalates(): void {
		$until = $this->pause_at_level_one();

		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( $until + 60 ), $until + 60 ) );

		$result = $this->crash( $until + 60 );
		$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'], 'A single crash after a pause must never escalate on its own.' );
		$this->assertSame( 1, $result['state']['level'], 'The escalation level persists between episodes.' );
		$this->assertFalse( CrashGuardState::is_disabled( $result['state'], $until + 60 ) );

		$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, $until + 70 ), 'Healthy traffic resets the streak after a pause just as before it.' );
		$this->assertSame( 0, $this->frontend_streak( $until + 70 ) );

		$this->crash( $until + 100 );
		$this->crash( $until + 130 );
		$result = $this->crash( $until + 160 );

		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'] );
		$this->assertSame( 2, $result['state']['level'], 'A full streak within a day of the last pause climbs the ladder.' );
		$this->assertSame( $until + 160 + HOUR_IN_SECONDS, $result['state']['disabled_until'] );
	}

	public function test_level_decays_after_a_day_of_running_without_crashes(): void {
		$until = $this->pause_at_level_one();

		$this->assertSame( 1, CrashGuardState::read( $until + DAY_IN_SECONDS - 1 )['level'] );

		$state = CrashGuardState::read( $until + DAY_IN_SECONDS );
		$this->assertSame( 0, $state['level'] );
		$this->assertFalse( CrashGuardState::is_disabled( $state, $until + DAY_IN_SECONDS ) );
	}

	public function test_long_pause_does_not_decay_the_moment_it_ends(): void {
		$state                   = CrashGuardState::empty_state( self::T0 );
		$state['level']          = CrashGuardState::MAX_LEVEL;
		$state['disabled_at']    = self::T0;
		$state['disabled_until'] = self::T0 + DAY_IN_SECONDS;
		$state['last_crash_at']  = self::T0;
		$this->write_raw( $state );

		$after = self::T0 + DAY_IN_SECONDS + 1;
		$this->assertSame( CrashGuardState::MAX_LEVEL, CrashGuardState::read( $after )['level'], 'Decay is measured from the end of the pause, not from the crash.' );

		$this->crash( $after + 10 );
		$this->crash( $after + 40 );
		$result = $this->crash( $after + 70 );
		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'] );
		$this->assertSame( CrashGuardState::MAX_LEVEL, $result['state']['level'], 'Escalation never exceeds the maximum level.' );
		$this->assertSame( $after + 70 + DAY_IN_SECONDS, $result['state']['disabled_until'] );
	}

	public function test_streak_starts_over_after_ttl(): void {
		$this->crash( self::T0 );

		$result = $this->crash( self::T0 + CrashGuardState::STREAK_TTL_SECONDS + 1 );

		$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'] );
		$this->assertSame( 1, $this->frontend_streak( self::T0 + CrashGuardState::STREAK_TTL_SECONDS + 1 ), 'Isolated crashes far apart never add up to a pause.' );
	}

	/**
	 * @dataProvider legacy_payload_provider
	 */
	public function test_legacy_payload_is_migrated( int $crash_count, int $expected_level, int $expected_window ): void {
		$this->write_raw(
			[
				'timestamp'   => self::T0,
				'crash_count' => $crash_count,
			]
		);

		$state = CrashGuardState::read( self::T0 + 1 );

		$this->assertSame( $expected_level, $state['level'] );
		$this->assertSame( self::T0, $state['disabled_at'] );
		$this->assertSame( self::T0 + $expected_window, $state['disabled_until'] );
		$this->assertTrue( CrashGuardState::is_disabled( $state, self::T0 + 1 ) );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + $expected_window ), self::T0 + $expected_window ) );
	}

	public function legacy_payload_provider(): array {
		return [
			'one crash: 10 minutes'                    => [ 1, 1, 10 * MINUTE_IN_SECONDS ],
			'two crashes: 1 hour'                      => [ 2, 2, HOUR_IN_SECONDS ],
			'legacy permanent becomes a 24-hour pause' => [ 3, CrashGuardState::MAX_LEVEL, DAY_IN_SECONDS ],
			'higher legacy counts also cap at 24 hours' => [ 6, CrashGuardState::MAX_LEVEL, DAY_IN_SECONDS ],
		];
	}

	public function test_corrupt_and_unknown_payloads_read_as_idle(): void {
		wp_mkdir_p( dirname( $this->flag_file_path ) );
		file_put_contents( $this->flag_file_path, '{not json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 ) ) );

		$this->write_raw( [ 'timestamp' => 'soon', 'crash_count' => 'many' ] );
		$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 ) ) );
	}

	public function test_read_falls_back_to_transient_unless_told_not_to(): void {
		$state                   = CrashGuardState::empty_state( self::T0 );
		$state['level']          = 1;
		$state['disabled_at']    = self::T0;
		$state['disabled_until'] = self::T0 + 600;
		$state['last_crash_at']  = self::T0;
		set_transient( CrashGuardState::TRANSIENT_KEY, $state, HOUR_IN_SECONDS );

		$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 1 ), self::T0 + 1 ) );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 1, false ), self::T0 + 1 ), 'The file-only read used at bootstrap must not touch the transient.' );
	}

	public function test_newer_store_wins_regardless_of_which_one_it_is(): void {
		$stale_file               = CrashGuardState::empty_state( self::T0 );
		$stale_file['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] = 1;
		$stale_file['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['last_counted_at'] = self::T0;
		$this->write_raw( $stale_file );

		$newer_transient                   = CrashGuardState::empty_state( self::T0 + 100 );
		$newer_transient['level']          = 1;
		$newer_transient['disabled_at']    = self::T0 + 100;
		$newer_transient['disabled_until'] = self::T0 + 700;
		$newer_transient['last_crash_at']  = self::T0 + 100;
		set_transient( CrashGuardState::TRANSIENT_KEY, $newer_transient, HOUR_IN_SECONDS );

		$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 101 ), self::T0 + 101 ), 'A newer transient must beat a stale file.' );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 101, false ), self::T0 + 101 ), 'The file-only bootstrap read still reflects the file.' );

		$newer_file = CrashGuardState::empty_state( self::T0 + 200 );
		$this->write_raw( $newer_file );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 201 ), self::T0 + 201 ), 'A newer file must beat an older transient.' );

		$tie = $newer_transient;
		$this->write_raw( $tie );
		$tie_transient = CrashGuardState::empty_state( self::T0 + 100 );
		set_transient( CrashGuardState::TRANSIENT_KEY, $tie_transient, HOUR_IN_SECONDS );
		$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 101 ), self::T0 + 101 ), 'On a tie the file wins.' );
	}

	public function test_crash_stored_in_transient_after_file_turned_read_only_is_authoritative(): void {
		$this->skip_if_root();

		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );
		$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ), 'While the file is writable nothing goes to the transient.' );

		chmod( $this->flag_file_path, 0444 );
		try {
			$result = $this->crash( self::T0 + 60 );

			$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'] );
			$this->assertTrue( $result['stored'], 'The pause must be persisted through the transient fallback.' );
			$this->assertIsArray( get_transient( CrashGuardState::TRANSIENT_KEY ) );

			$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 61, false ), self::T0 + 61 ), 'The stale file alone still says enabled.' );
			$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 61 ), self::T0 + 61 ), 'The reconciled read must honor the newer transient.' );
		} finally {
			chmod( $this->flag_file_path, 0644 );
		}
	}

	public function test_healthy_request_resets_streak_held_in_transient(): void {
		$this->skip_if_root();

		$this->crash( self::T0 );
		chmod( $this->flag_file_path, 0444 );
		try {
			$this->crash( self::T0 + 30 );
			$this->assertSame( 2, $this->frontend_streak( self::T0 + 30 ) );

			$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 40 ) );
			$this->assertSame( 0, $this->frontend_streak( self::T0 + 41 ) );
		} finally {
			chmod( $this->flag_file_path, 0644 );
		}
	}

	public function test_locked_update_continues_from_newer_transient(): void {
		$stale_file = CrashGuardState::empty_state( self::T0 );
		$stale_file['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ] = [
			'count'           => 1,
			'last_counted_at' => self::T0,
		];
		$this->write_raw( $stale_file );

		$newer_transient = CrashGuardState::empty_state( self::T0 + 30 );
		$newer_transient['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ] = [
			'count'           => 2,
			'last_counted_at' => self::T0 + 30,
		];
		set_transient( CrashGuardState::TRANSIENT_KEY, $newer_transient, HOUR_IN_SECONDS );

		$result = $this->crash( self::T0 + 60 );

		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'], 'The third crash must build on the transient\'s count of two, not the file\'s one.' );
		$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 61, false ), self::T0 + 61 ), 'Once writable again, the file carries the newest state.' );
	}

	public function test_clear_supersedes_a_file_that_cannot_be_deleted(): void {
		$this->skip_if_root();

		$this->pause_at_level_one();
		$dir = dirname( $this->flag_file_path );

		chmod( $dir, 0555 );
		try {
			$this->assertTrue( CrashGuardState::clear( self::T0 + 70 ), 'Clearing must succeed even when the file cannot be unlinked.' );
			$this->assertFileExists( $this->flag_file_path, 'Precondition: the file really could not be removed.' );

			$state = CrashGuardState::read( self::T0 + 71 );
			$this->assertFalse( CrashGuardState::is_disabled( $state, self::T0 + 71 ), 'The stale paused file must no longer count.' );
			$this->assertTrue( CrashGuardState::is_idle( $state ) );
		} finally {
			chmod( $dir, 0755 );
		}
	}

	private function skip_if_root(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'File permission checks do not apply to root.' );
		}
	}

	public function test_idle_cleanup_keeps_a_crash_recorded_while_waiting_for_the_lock(): void {
		$this->write_raw( CrashGuardState::empty_state( self::T0 ) );

		$crash_state                  = CrashGuardState::empty_state( self::T0 + 5 );
		$crash_state['last_crash_at'] = self::T0 + 5;
		$crash_state['streaks'][ CrashGuardState::REQUEST_CLASS_TRUSTED ] = [
			'count'           => 1,
			'last_counted_at' => self::T0 + 5,
		];

		// Another request takes the writer lock first and records a backend crash while holding it.
		$holder = $this->start_lock_holder( 700, sprintf( 'ftruncate($h, 0); rewind($h); fwrite($h, %s);', var_export( wp_json_encode( $crash_state ), true ) ) );

		try {
			// This request peeks at an idle file and wants to remove it; it must wait for the lock and re-check.
			$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 10 ) );
		} finally {
			$this->finish_lock_holder( $holder );
		}

		$this->assertFileExists( $this->flag_file_path, 'A crash recorded while the cleanup waited for the lock must survive.' );
		$this->assertSame( 1, CrashGuardState::read( self::T0 + 11 )['streaks'][ CrashGuardState::REQUEST_CLASS_TRUSTED ]['count'] );
	}

	public function test_writer_starts_over_when_the_file_is_removed_while_waiting_for_the_lock(): void {
		$this->write_raw( CrashGuardState::empty_state( self::T0 ) );

		// Another request holds the lock and removes the file (idle cleanup) before releasing it.
		$holder = $this->start_lock_holder( 700, sprintf( 'unlink(%s);', var_export( $this->flag_file_path, true ) ) );

		try {
			$result = $this->crash( self::T0 + 10 );
		} finally {
			$this->finish_lock_holder( $holder );
		}

		$this->assertTrue( $result['stored'] );
		$this->assertFileExists( $this->flag_file_path, 'The crash must land in a fresh file, not in the unlinked one.' );
		$this->assertSame( 1, $this->frontend_streak( self::T0 + 11 ) );
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
			var_export( $this->flag_file_path, true ),
			var_export( $marker_path, true ),
			$hold_ms * 1000,
			$body_php
		);
		file_put_contents( $script_path, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$pipes   = [];
		$process = proc_open( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script_path ), [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$this->assertIsResource( $process );

		// Wait until the subprocess really holds the lock, so the ordering is deterministic.
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

	public function test_healthy_requests_reset_streaks_held_only_in_the_transient(): void {
		$this->skip_if_root();
		$dir = $this->make_flag_file_uncreatable();

		try {
			$this->crash( self::T0 );
			$this->assertFileDoesNotExist( $this->flag_file_path, 'Precondition: the flag file cannot be created.' );
			$this->assertSame( 1, $this->frontend_streak( self::T0 ) );

			$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 10 ) );
			$this->assertSame( 0, $this->frontend_streak( self::T0 + 10 ) );

			$this->crash( self::T0 + 40 );
			$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 50 ) );

			$result = $this->crash( self::T0 + 80 );

			$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'] );
			$this->assertSame( 1, $this->frontend_streak( self::T0 + 80 ), 'Isolated crashes separated by healthy requests must not add up.' );
			$this->assertFalse( CrashGuardState::is_disabled( $result['state'], self::T0 + 80 ) );
			$this->assertFileDoesNotExist( $this->flag_file_path );
		} finally {
			chmod( $dir, 0755 );
		}
	}

	public function test_idle_transient_only_state_is_removed_by_a_healthy_request(): void {
		$this->skip_if_root();
		$dir = $this->make_flag_file_uncreatable();

		try {
			$this->crash( self::T0 );
			$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 10 ) );
			$this->assertIsArray( get_transient( CrashGuardState::TRANSIENT_KEY ), 'The reset itself is stored.' );

			$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 20 ) );
			$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ), 'Idle state with no file behind it is dropped.' );
		} finally {
			chmod( $dir, 0755 );
		}
	}

	/**
	 * Makes the flag directory exist but refuse new files, so every write falls back to the transient.
	 *
	 * @return string the directory, which the caller must chmod back to 0755.
	 */
	private function make_flag_file_uncreatable(): string {
		$dir = dirname( $this->flag_file_path );
		wp_mkdir_p( $dir );
		$this->assertFileDoesNotExist( $this->flag_file_path );
		chmod( $dir, 0555 );

		return $dir;
	}

	public function test_recovered_file_writes_retire_the_stale_transient_so_cleanup_cannot_resurrect_it(): void {
		$this->skip_if_root();

		$this->crash( self::T0 );
		chmod( $this->flag_file_path, 0444 );
		try {
			$this->crash( self::T0 + 30 );
			$this->assertSame( 2, $this->frontend_streak( self::T0 + 30 ), 'Precondition: the second crash lives in the transient.' );
			$this->assertIsArray( get_transient( CrashGuardState::TRANSIENT_KEY ) );
		} finally {
			chmod( $this->flag_file_path, 0644 );
		}

		// File writes work again: the healthy reset lands in the file and must retire the stale transient.
		$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 40 ) );
		$this->assertSame( 0, $this->frontend_streak( self::T0 + 40 ) );
		$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ), 'A transient older than the file state is stale once the file is written.' );

		// Idle cleanup removes the file; nothing stale may take its place.
		$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 50 ) );
		$this->assertFileDoesNotExist( $this->flag_file_path );
		$this->assertSame( 0, $this->frontend_streak( self::T0 + 51 ), 'The streak must not jump back to two after the idle file is removed.' );

		$result = $this->crash( self::T0 + 80 );
		$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'] );
		$this->assertFalse( CrashGuardState::is_disabled( $result['state'], self::T0 + 80 ), 'An isolated crash after recovery must not pause the plugin.' );
	}

	public function test_idle_cleanup_retires_an_older_transient_it_would_otherwise_promote(): void {
		$stale_transient = CrashGuardState::empty_state( self::T0 + 30 );
		$stale_transient['last_crash_at'] = self::T0 + 30;
		$stale_transient['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ] = [
			'count'           => 2,
			'last_counted_at' => self::T0 + 30,
		];
		set_transient( CrashGuardState::TRANSIENT_KEY, $stale_transient, HOUR_IN_SECONDS );
		$this->write_raw( CrashGuardState::empty_state( self::T0 + 40 ) );

		$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 50 ) );

		$this->assertFileDoesNotExist( $this->flag_file_path );
		$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ) );
		$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 + 51 ) ) );
	}

	public function test_cleanup_keeps_a_transient_crash_recorded_in_the_same_second_as_the_file(): void {
		$concurrent_transient = CrashGuardState::empty_state( self::T0 + 40 );
		$concurrent_transient['last_crash_at'] = self::T0 + 40;
		$concurrent_transient['streaks'][ CrashGuardState::REQUEST_CLASS_TRUSTED ] = [
			'count'           => 1,
			'last_counted_at' => self::T0 + 40,
		];
		set_transient( CrashGuardState::TRANSIENT_KEY, $concurrent_transient, HOUR_IN_SECONDS );
		$this->write_raw( CrashGuardState::empty_state( self::T0 + 40 ) );

		$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 50 ) );

		$this->assertFileDoesNotExist( $this->flag_file_path );
		$this->assertSame( 1, CrashGuardState::read( self::T0 + 51 )['streaks'][ CrashGuardState::REQUEST_CLASS_TRUSTED ]['count'], 'A crash that is not older than the file state may be a concurrent fallback write and must survive.' );
	}

	public function test_fallback_write_in_the_same_second_outranks_the_file(): void {
		$this->skip_if_root();

		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );
		$this->assertSame( 2, $this->frontend_streak( self::T0 + 30 ) );

		// The file turns read-only and a healthy request arrives within the same second as the last crash.
		chmod( $this->flag_file_path, 0444 );
		try {
			$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 30 ) );

			$this->assertSame( 0, $this->frontend_streak( self::T0 + 30 ), 'A later write must win even when updated_at ties with the file.' );

			$result = $this->crash( self::T0 + 60 );
			$this->assertSame( CrashGuardState::DECISION_COUNTED, $result['decision'] );
			$this->assertSame( 1, $this->frontend_streak( self::T0 + 60 ) );
			$this->assertFalse( CrashGuardState::is_disabled( $result['state'], self::T0 + 60 ), 'The isolated crash after the reset must not pause the plugin.' );
		} finally {
			chmod( $this->flag_file_path, 0644 );
		}
	}

	public function test_every_write_increments_the_revision(): void {
		$this->assertSame( 0, CrashGuardState::read( self::T0 )['revision'] );

		$this->assertSame( 1, $this->crash( self::T0 )['state']['revision'] );
		$this->assertSame( 2, $this->crash( self::T0 + 30 )['state']['revision'] );

		CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 40 );
		$this->assertSame( 3, CrashGuardState::read( self::T0 + 40 )['revision'] );

		$this->assertSame( 4, $this->crash( self::T0 + 70 )['state']['revision'], 'Revisions continue from the reconciled state, never restart.' );
	}

	public function test_higher_revision_outranks_a_newer_timestamp(): void {
		$file                = CrashGuardState::empty_state( self::T0 + 100 );
		$file['revision']    = 2;
		$file['last_crash_at'] = self::T0 + 100;
		$file['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ] = [
			'count'           => 1,
			'last_counted_at' => self::T0 + 100,
		];
		$this->write_raw( $file );

		$transient                  = CrashGuardState::empty_state( self::T0 + 50 );
		$transient['revision']      = 3;
		$transient['last_crash_at'] = self::T0 + 50;
		$transient['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ] = [
			'count'           => 2,
			'last_counted_at' => self::T0 + 50,
		];
		set_transient( CrashGuardState::TRANSIENT_KEY, $transient, HOUR_IN_SECONDS );

		$this->assertSame( 2, $this->frontend_streak( self::T0 + 101 ), 'Revision decides before updated_at.' );

		$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 200 ) );
		$state = CrashGuardState::read( self::T0 + 200, false );
		$this->assertSame( 4, $state['revision'], 'The file write continues from the highest revision seen.' );
		$this->assertSame( 0, $state['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'] );
		$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ), 'The lower-revision transient is retired by the file write.' );
	}

	public function test_resume_holds_when_the_directory_is_read_only_but_the_flag_is_writable(): void {
		$this->skip_if_root();

		$this->pause_at_level_one();
		$dir = dirname( $this->flag_file_path );

		chmod( $dir, 0555 );
		try {
			$this->assertTrue( CrashGuardState::clear( self::T0 + 70 ) );
			$this->assertFileExists( $this->flag_file_path, 'Precondition: the directory refuses the unlink.' );
			$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 + 71, false ) ), 'The stale file is overwritten with the idle state instead.' );
			$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ), 'No tombstone is needed once the file itself is idle.' );

			// Healthy shutdowns keep finding the leftover file; they must not bring the pause back.
			$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 100 ) );
			$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 101 ), self::T0 + 101 ) );
			$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 200 ) );
			$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 + 201 ) ) );
		} finally {
			chmod( $dir, 0755 );
		}
	}

	public function test_resume_keeps_its_tombstone_when_the_flag_is_neither_removable_nor_writable(): void {
		$this->skip_if_root();

		$this->pause_at_level_one();
		$dir = dirname( $this->flag_file_path );

		chmod( $this->flag_file_path, 0444 );
		chmod( $dir, 0555 );
		try {
			$this->assertTrue( CrashGuardState::clear( self::T0 + 70 ) );
			$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 71, false ), self::T0 + 71 ), 'Precondition: the stale paused file could not be touched.' );
			$this->assertIsArray( get_transient( CrashGuardState::TRANSIENT_KEY ), 'The tombstone is the only thing superseding the file.' );
			$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 71 ), self::T0 + 71 ) );

			$this->assertFalse( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 100 ) );
			$this->assertIsArray( get_transient( CrashGuardState::TRANSIENT_KEY ), 'The tombstone must survive healthy shutdowns while the file cannot be removed.' );
			$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 101 ), self::T0 + 101 ) );
		} finally {
			chmod( $dir, 0755 );
			chmod( $this->flag_file_path, 0644 );
		}
	}

	public function test_failed_file_write_persists_the_computed_state_to_the_transient(): void {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );

		CrashGuardState::$fail_file_writes_for_testing = true;
		try {
			$result = $this->crash( self::T0 + 60 );
		} finally {
			CrashGuardState::$fail_file_writes_for_testing = false;
		}

		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'], 'The third crash must be evaluated against the two before it, not against an emptied file.' );
		$this->assertTrue( $result['stored'] );
		$this->assertSame( '', (string) file_get_contents( $this->flag_file_path ), 'Precondition: the truncate emptied the file before the write failed.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$state = CrashGuardState::read( self::T0 + 61 );
		$this->assertTrue( CrashGuardState::is_disabled( $state, self::T0 + 61 ), 'The pause computed before the failed write is what the transient must hold.' );
		$this->assertSame( 1, $state['level'] );
		$this->assertSame( 3, $state['revision'], 'The persisted state carries the revision computed from the real history.' );

		// With writes working again, the next file write continues from the transient and retires it.
		$this->assertSame( CrashGuardState::DECISION_ALREADY_DISABLED, $this->crash( self::T0 + 90 )['decision'] );
		$this->assertTrue( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 91, false ), self::T0 + 91 ) );
		$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ) );
	}

	public function test_clear_removes_file_and_transient(): void {
		$this->crash( self::T0 );
		set_transient( CrashGuardState::TRANSIENT_KEY, CrashGuardState::empty_state( self::T0 ), HOUR_IN_SECONDS );

		$this->assertTrue( CrashGuardState::clear() );
		$this->assertFileDoesNotExist( $this->flag_file_path );
		$this->assertFalse( get_transient( CrashGuardState::TRANSIENT_KEY ) );
		$this->assertTrue( CrashGuardState::is_idle( CrashGuardState::read( self::T0 ) ) );
	}

	public function test_describe_decision_explains_each_outcome(): void {
		$counted = $this->crash( self::T0 );
		$this->assertStringContainsString( 'Crash 1 of 3 for untrusted requests', CrashGuardState::describe_decision( $counted ) );

		$ignored = $this->crash( self::T0 + 1 );
		$this->assertStringContainsString( 'not counted', CrashGuardState::describe_decision( $ignored ) );

		$this->crash( self::T0 + 30 );
		$disabled    = $this->crash( self::T0 + 60 );
		$description = CrashGuardState::describe_decision( $disabled );
		$this->assertStringContainsString( 'paused for 10 minutes', $description );
		$this->assertStringContainsString( 'level 1 of 4', $description );

		$already = $this->crash( self::T0 + 90 );
		$this->assertStringContainsString( 'already paused', CrashGuardState::describe_decision( $already ) );
	}

	public function test_would_pause_previews_the_decision_without_writing(): void {
		$this->assertFalse( CrashGuardState::would_pause( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 ) );

		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );

		$this->assertFalse( CrashGuardState::would_pause( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 35 ), 'A crash inside the spacing window would not be counted, so it would not pause.' );
		$this->assertTrue( CrashGuardState::would_pause( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 60 ) );
		$this->assertFalse( CrashGuardState::would_pause( CrashGuardState::REQUEST_CLASS_TRUSTED, self::T0 + 60 ), 'Streaks are per request class.' );

		$this->assertSame( 2, $this->frontend_streak( self::T0 + 60 ), 'Previewing must not change the stored state.' );
		$this->assertFalse( CrashGuardState::is_disabled( CrashGuardState::read( self::T0 + 60 ), self::T0 + 60 ) );

		$this->crash( self::T0 + 60 );
		$this->assertFalse( CrashGuardState::would_pause( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 90 ), 'While paused nothing pauses again.' );
	}

	public function test_request_class_is_trusted_only_for_managers(): void {
		$this->assertSame( CrashGuardState::REQUEST_CLASS_UNTRUSTED, CrashGuardState::get_request_class(), 'Anonymous requests are untrusted.' );

		try {
			wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
			$this->assertSame( CrashGuardState::REQUEST_CLASS_UNTRUSTED, CrashGuardState::get_request_class(), 'A logged-in customer is still an outsider.' );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );
			$this->assertSame( CrashGuardState::REQUEST_CLASS_TRUSTED, CrashGuardState::get_request_class(), 'Someone who can manage WooCommerce is trusted.' );

			wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
			$this->assertSame( CrashGuardState::REQUEST_CLASS_TRUSTED, CrashGuardState::get_request_class() );
		} finally {
			wp_set_current_user( 0 );
		}
	}

	public function test_unverified_crash_counts_without_pausing_and_holds_the_streak(): void {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );

		$result = CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 60, false, 'inconclusive: cURL error 7' );

		$this->assertSame( CrashGuardState::DECISION_UNVERIFIED, $result['decision'] );
		$this->assertFalse( CrashGuardState::is_disabled( $result['state'], self::T0 + 61 ), 'A crash that could not be verified must not pause the plugin.' );
		$this->assertSame( 3, $this->frontend_streak( self::T0 + 61 ), 'The streak is held at the threshold so the next crash asks again.' );
		$this->assertSame( 1, $result['state']['unverified']['count'] );
		$this->assertSame( 'inconclusive: cURL error 7', $result['state']['unverified']['reason'] );
		$this->assertFalse( CrashGuardState::is_idle( $result['state'] ) );
		$this->assertStringContainsString( 'could not be verified', CrashGuardState::describe_decision( $result ) );

		$this->assertTrue( CrashGuardState::would_pause( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 90 ) );
		$again = CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 90, false, 'inconclusive: cURL error 7' );
		$this->assertSame( 2, $again['state']['unverified']['count'] );
		$this->assertSame( 3, $this->frontend_streak( self::T0 + 91 ) );

		$this->assertTrue( CrashGuardState::record_healthy_request( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 100 ) );
		$state = CrashGuardState::read( self::T0 + 101 );
		$this->assertSame( 0, $this->frontend_streak( self::T0 + 101 ), 'Healthy traffic still resets the streak.' );
		$this->assertSame( 2, $state['unverified']['count'], 'The unverified record stays for the admin notice.' );

		$this->assertSame( 0, CrashGuardState::read( self::T0 + 90 + DAY_IN_SECONDS )['unverified']['count'], 'The record decays after a day.' );
	}

	public function test_a_confirmed_crash_after_unverified_ones_pauses_and_clears_the_record(): void {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );
		CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 60, false, 'inconclusive' );

		$result = CrashGuardState::record_crash( CrashGuardState::REQUEST_CLASS_UNTRUSTED, self::T0 + 90, true );

		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'] );
		$this->assertTrue( CrashGuardState::is_disabled( $result['state'], self::T0 + 91 ) );
		$this->assertSame( 0, $result['state']['unverified']['count'], 'A pause supersedes the unverified record.' );
	}

	/**
	 * Records three spaced frontend crashes starting at T0 and returns when the pause ends.
	 *
	 * @return int disabled_until timestamp.
	 */
	private function pause_at_level_one(): int {
		$this->crash( self::T0 );
		$this->crash( self::T0 + 30 );
		$result = $this->crash( self::T0 + 60 );

		$this->assertSame( CrashGuardState::DECISION_DISABLED, $result['decision'] );

		return (int) $result['state']['disabled_until'];
	}

	private function crash( int $at, string $class = CrashGuardState::REQUEST_CLASS_UNTRUSTED ): array {
		return CrashGuardState::record_crash( $class, $at );
	}

	private function frontend_streak( int $at ): int {
		return (int) CrashGuardState::read( $at )['streaks'][ CrashGuardState::REQUEST_CLASS_UNTRUSTED ]['count'];
	}

	private function write_raw( array $payload ): void {
		wp_mkdir_p( dirname( $this->flag_file_path ) );
		file_put_contents( $this->flag_file_path, wp_json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
