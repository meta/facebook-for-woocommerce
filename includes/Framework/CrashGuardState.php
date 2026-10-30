<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook\Framework;

defined( 'ABSPATH' ) || exit;

/**
 * Crash guard state: decides when repeated plugin crashes should pause the plugin.
 *
 * This class is deliberately self-contained (no plugin or Composer dependencies) because the
 * main plugin file loads it with require_once before the autoloader, so the early fallback
 * shutdown handler, PluginCrashHandler and the loader all share one definition of "disabled".
 *
 * The model:
 *
 * - Every plugin crash increments a consecutive-crash streak for its request class: trusted
 *   (WP-CLI, cron, users who can manage WooCommerce or the site) or untrusted (everyone else,
 *   logged-in customers included). Crashes closer together than CRASH_SPACING_SECONDS count once, so a burst of
 *   concurrent requests cannot inflate the streak, and a streak older than STREAK_TTL_SECONDS
 *   starts over.
 * - Any request where the plugin initialized and finished without a plugin fatal resets the
 *   streak for its class. A plugin that is broken on every request reaches the threshold in
 *   about a minute; a crash that only one malformed request can trigger never does on a store
 *   with live traffic.
 * - Reaching STREAK_THRESHOLD pauses the plugin for an escalating window: 10 minutes, 1 hour,
 *   6 hours and 24 hours, capped there. There is no permanent tier.
 * - When a window ends the plugin runs again under the same rules. The escalation level is
 *   remembered, so a streak that completes again within DECAY_SECONDS of the pause ending
 *   pauses for the next longer window; after DECAY_SECONDS without a crash the level resets.
 *   A single crash after a pause never escalates on its own: that would let one request
 *   renew a pause indefinitely.
 * - Trusted crashes pause on the streak alone: their input is not an outsider's. Untrusted
 *   crashes pause only when PluginCrashHandler's self-check (a plain loopback request carrying
 *   nothing from the client) confirms that a plain request crashes too. would_pause() tells the
 *   handler when that decision is due; when the self-check cannot run, record_crash() is asked
 *   to count the crash as unverified instead of pausing, which the admin notice reports.
 *
 * State is stored as JSON in uploads/facebook-for-woocommerce/.disabled, updated under an
 * exclusive file lock. A transient is the fallback when the file cannot be written. Reads
 * consult both stores and the newer one wins. "Newer" is decided by a revision counter that
 * every write increments from the reconciled state it started from, so two writes within the
 * same second are still ordered; updated_at only breaks ties between legacy payloads, and the
 * file wins when nothing else does. A fallback write made after the file turned read-only
 * therefore stays authoritative over the stale file, and once the file is writable again the
 * next successful file write retires the stale transient.
 *
 * @since 3.7.9
 */
final class CrashGuardState {

	/** Current payload format version. */
	const VERSION = 2;

	/** Flag file path relative to WP_CONTENT_DIR. */
	const FLAG_FILE_RELATIVE_PATH = 'uploads/facebook-for-woocommerce/.disabled';

	/** Transient used when the flag file cannot be written. */
	const TRANSIENT_KEY = 'wc_facebook_plugin_disabled';

	/** Consecutive counted crashes that pause the plugin. */
	const STREAK_THRESHOLD = 3;

	/** Crashes closer together than this count once. */
	const CRASH_SPACING_SECONDS = 30;

	/** A streak with no counted crash for this long starts over. */
	const STREAK_TTL_SECONDS = 600;

	/** Crash-free running time after which the escalation level resets. */
	const DECAY_SECONDS = 86400;

	/** Highest escalation level; its window is the longest pause. */
	const MAX_LEVEL = 4;

	const REQUEST_CLASS_TRUSTED   = 'trusted';
	const REQUEST_CLASS_UNTRUSTED = 'untrusted';

	const DECISION_COUNTED          = 'counted';
	const DECISION_IGNORED_SPACING  = 'ignored_spacing';
	const DECISION_DISABLED         = 'disabled';
	const DECISION_ALREADY_DISABLED = 'already_disabled';
	const DECISION_UNVERIFIED       = 'unverified';

	/**
	 * Makes every flag file write fail after the truncate, to exercise the fallback in tests.
	 *
	 * @internal Testing seam only; never set in production code.
	 *
	 * @var bool
	 */
	public static $fail_file_writes_for_testing = false;

	/**
	 * Returns the pause length for an escalation level.
	 *
	 * @since 3.7.9
	 *
	 * @param int $level escalation level (1-based; 0 means not paused).
	 * @return int seconds, 0 for level 0.
	 */
	public static function get_window_seconds( $level ) {
		$level = (int) $level;
		if ( $level <= 0 ) {
			return 0;
		}

		$ladder = [
			1 => 600,
			2 => 3600,
			3 => 21600,
			4 => 86400,
		];

		return $ladder[ min( $level, self::MAX_LEVEL ) ];
	}

	/**
	 * Returns the absolute flag file path.
	 *
	 * @since 3.7.9
	 *
	 * @return string
	 */
	public static function get_flag_file_path() {
		$content_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : dirname( __DIR__, 4 );

		return rtrim( str_replace( '\\', '/', (string) $content_dir ), '/' ) . '/' . self::FLAG_FILE_RELATIVE_PATH;
	}

	/**
	 * Classifies the current request by who could have shaped its input.
	 *
	 * Trusted: WP-CLI, cron, and logged-in users who can manage WooCommerce or the site. Their
	 * input is not an outsider's, so their crashes pause the plugin on the streak alone, and a
	 * plugin broken on every request is still caught through cron and wp-admin even where the
	 * self-check cannot run. Untrusted: everyone else, logged-in customers included, because open
	 * registration makes a login prove nothing. Streaks are tracked per class so that trusted
	 * traffic cannot mask a storefront that crashes for visitors, and vice versa.
	 *
	 * The user is resolved defensively: this runs at shutdown, possibly after a fatal, and
	 * anything that cannot be resolved counts as untrusted.
	 *
	 * @since 3.7.9
	 *
	 * @return string one of the REQUEST_CLASS_* constants.
	 */
	public static function get_request_class() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return self::REQUEST_CLASS_TRUSTED;
		}

		if ( function_exists( 'current_user_can' ) && function_exists( 'wp_get_current_user' ) ) {
			try {
				if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
					return self::REQUEST_CLASS_TRUSTED;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return self::REQUEST_CLASS_UNTRUSTED;
	}

	/**
	 * Returns a fresh, idle state.
	 *
	 * @since 3.7.9
	 *
	 * @param int $now current Unix timestamp.
	 * @return array
	 */
	public static function empty_state( $now ) {
		return [
			'version'        => self::VERSION,
			'revision'       => 0,
			'level'          => 0,
			'disabled_at'    => 0,
			'disabled_until' => 0,
			'last_crash_at'  => 0,
			'updated_at'     => (int) $now,
			'streaks'        => [
				self::REQUEST_CLASS_TRUSTED   => self::empty_streak(),
				self::REQUEST_CLASS_UNTRUSTED => self::empty_streak(),
			],
			'unverified'     => self::empty_unverified(),
		];
	}

	/**
	 * Reads the current state.
	 *
	 * @since 3.7.9
	 *
	 * @param int|null $now               current Unix timestamp, defaults to time().
	 * @param bool     $include_transient whether to fall back to the transient when the file is absent.
	 *                                    The loader passes false during bootstrap to avoid early DB access.
	 * @return array normalized state.
	 */
	public static function read( $now = null, $include_transient = true ) {
		$now  = self::resolve_now( $now );
		$file = self::decode( self::read_file_raw() );

		if ( ! $include_transient ) {
			return self::normalize( $file, $now );
		}

		return self::reconcile( $file, self::read_transient(), $now );
	}

	/**
	 * Whether the plugin is currently paused.
	 *
	 * @since 3.7.9
	 *
	 * @param array    $state normalized state.
	 * @param int|null $now   current Unix timestamp, defaults to time().
	 * @return bool
	 */
	public static function is_disabled( array $state, $now = null ) {
		return (int) $state['disabled_until'] > self::resolve_now( $now );
	}

	/**
	 * Whether the state carries nothing worth keeping.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state normalized state.
	 * @return bool
	 */
	public static function is_idle( array $state ) {
		if ( $state['level'] > 0 || $state['disabled_until'] > 0 ) {
			return false;
		}

		foreach ( $state['streaks'] as $streak ) {
			if ( $streak['count'] > 0 ) {
				return false;
			}
		}

		return 0 === (int) $state['unverified']['count'];
	}

	/**
	 * Records a plugin crash and decides whether to pause the plugin.
	 *
	 * @since 3.7.9
	 *
	 * @param string   $request_class one of the REQUEST_CLASS_* constants.
	 * @param int|null $now           current Unix timestamp, defaults to time().
	 * @param bool     $may_pause     false when the self-check could not confirm the crash: the crash
	 *                                is then counted as unverified and the plugin is not paused.
	 * @param string   $reason        why the crash could not be verified, for the admin notice.
	 * @return array {
	 *     @type string $decision      one of the DECISION_* constants.
	 *     @type string $request_class the class the crash was recorded against.
	 *     @type array  $state         the state after the update.
	 *     @type bool   $stored        whether the state could be persisted (file or transient).
	 * }
	 */
	public static function record_crash( $request_class, $now = null, $may_pause = true, $reason = '' ) {
		$now      = self::resolve_now( $now );
		$class    = self::normalize_request_class( $request_class );
		$decision = self::DECISION_COUNTED;

		$mutator = static function ( array $state ) use ( $class, $now, $may_pause, $reason, &$decision ) {
			list( $state, $decision ) = self::decide( $state, $class, $now, (bool) $may_pause, (string) $reason );
			return $state;
		};

		list( $state, $stored ) = self::update( $mutator, $now );

		return [
			'decision'      => $decision,
			'request_class' => $class,
			'state'         => $state,
			'stored'        => $stored,
		];
	}

	/**
	 * Whether recording a crash now would pause the plugin.
	 *
	 * Read-only preview of record_crash(), used by the crash handler to run its self-check
	 * before committing to a pause.
	 *
	 * @since 3.7.9
	 *
	 * @param string   $request_class one of the REQUEST_CLASS_* constants.
	 * @param int|null $now           current Unix timestamp, defaults to time().
	 * @return bool
	 */
	public static function would_pause( $request_class, $now = null ) {
		$now   = self::resolve_now( $now );
		$class = self::normalize_request_class( $request_class );

		list( , $decision ) = self::decide( self::read( $now ), $class, $now );

		return self::DECISION_DISABLED === $decision;
	}

	/**
	 * Applies a crash to a state and returns the new state with the decision taken.
	 *
	 * Pure: no storage, no clock. Shared by record_crash() and would_pause().
	 *
	 * @since 3.7.9
	 *
	 * @param array  $state         normalized state.
	 * @param string $request_class one of the REQUEST_CLASS_* constants.
	 * @param int    $now           current Unix timestamp.
	 * @param bool   $may_pause     whether reaching the threshold may pause the plugin.
	 * @param string $reason        why it may not, when it may not.
	 * @return array [ array $state, string $decision ]
	 */
	private static function decide( array $state, $request_class, $now, $may_pause = true, $reason = '' ) {
		if ( $state['disabled_until'] > $now ) {
			// A request that was already in flight when the pause began. Do not count it twice.
			$state['last_crash_at'] = $now;
			return [ $state, self::DECISION_ALREADY_DISABLED ];
		}

		$streak   = $state['streaks'][ $request_class ];
		$decision = self::DECISION_IGNORED_SPACING;

		if ( $now - $streak['last_counted_at'] >= self::CRASH_SPACING_SECONDS ) {
			++$streak['count'];
			$streak['last_counted_at'] = $now;
			$decision                  = self::DECISION_COUNTED;
		}

		$state['streaks'][ $request_class ] = $streak;

		if ( $streak['count'] >= self::STREAK_THRESHOLD ) {
			if ( $may_pause ) {
				$state    = self::disable( $state, $now );
				$decision = self::DECISION_DISABLED;
			} elseif ( self::DECISION_COUNTED === $decision ) {
				// Could not be verified: hold the streak at the threshold so every further counted
				// crash asks the self-check again, and remember it for the admin notice.
				$state['streaks'][ $request_class ]['count'] = self::STREAK_THRESHOLD;
				$state['unverified']                         = [
					'count'   => (int) $state['unverified']['count'] + 1,
					'last_at' => $now,
					'reason'  => $reason,
				];
				$decision                                    = self::DECISION_UNVERIFIED;
			}
		}

		$state['last_crash_at'] = $now;

		return [ $state, $decision ];
	}

	/**
	 * Records a request that ran the plugin and finished without a plugin fatal.
	 *
	 * Cheap on the hot path: it returns after a stat call and a transient read unless a crash was
	 * recorded recently. The transient read is free in practice, because the loader's bootstrap
	 * check already fetched the same transient earlier in the request and WordPress caches option
	 * lookups, including misses, for the rest of the request. Consulting it here is what keeps
	 * the consecutive-crash policy intact when the flag file cannot be created and all state
	 * lives in the transient.
	 *
	 * Resetting a streak and removing idle state both happen under the writer lock, with the
	 * state re-read inside it, so a crash another request records in the meantime is neither
	 * reset nor deleted.
	 *
	 * @since 3.7.9
	 *
	 * @param string|null $request_class one of the REQUEST_CLASS_* constants; null resolves it, after
	 *                                   the cheap checks, with get_request_class().
	 * @param int|null    $now           current Unix timestamp, defaults to time().
	 * @return bool whether a streak was reset and persisted.
	 */
	public static function record_healthy_request( $request_class = null, $now = null ) {
		$path = self::get_flag_file_path();
		if ( ! file_exists( $path ) && null === self::read_transient() ) {
			return false;
		}

		$now   = self::resolve_now( $now );
		$class = self::normalize_request_class( null === $request_class ? self::get_request_class() : $request_class );

		// Unlocked peek: most requests find nothing to do and stop here.
		$peek = self::read( $now );
		if ( ! self::is_idle( $peek ) && ( $peek['disabled_until'] > $now || 0 === $peek['streaks'][ $class ]['count'] ) ) {
			return false;
		}

		$reset   = false;
		$mutator = static function ( array $state ) use ( $class, $now, &$reset ) {
			if ( self::is_idle( $state ) ) {
				return null; // Nothing worth keeping: remove the file.
			}

			if ( $state['disabled_until'] > $now || 0 === $state['streaks'][ $class ]['count'] ) {
				return false; // Another request changed the state since the peek: leave it alone.
			}

			$state['streaks'][ $class ] = self::empty_streak();
			$reset                      = true;

			return $state;
		};

		list( , $stored ) = self::update( $mutator, $now );

		return $reset && $stored;
	}

	/**
	 * Removes all stored state (file and transient).
	 *
	 * The file is removed under the writer lock, or overwritten with the idle state when the
	 * directory is read-only. When it can be neither removed nor overwritten, an idle state with
	 * a higher revision is written to the transient instead, so reconciliation treats the stale
	 * file as superseded and the plugin runs again.
	 *
	 * @since 3.7.9
	 *
	 * @param int|null $now current Unix timestamp, defaults to time().
	 * @return bool whether the effective state is now idle.
	 */
	public static function clear( $now = null ) {
		$path = self::get_flag_file_path();
		$now  = self::resolve_now( $now );

		if ( file_exists( $path ) ) {
			self::update(
				static function () {
					return null;
				},
				$now
			);
			clearstatcache( true, $path );

			if ( file_exists( $path ) ) {
				$remaining = self::normalize( self::decode( self::read_file_raw() ), $now );

				if ( ! self::is_idle( $remaining ) ) {
					$tombstone               = self::empty_state( $now );
					$tombstone['revision']   = (int) $remaining['revision'] + 1;
					$tombstone['updated_at'] = max( $now, (int) $remaining['updated_at'] + 1 );

					return self::write_transient( $tombstone );
				}
			}
		}

		return self::delete_transient();
	}

	/**
	 * Describes a record_crash() result in one human-readable sentence for logs.
	 *
	 * @since 3.7.9
	 *
	 * @param array $result return value of record_crash().
	 * @return string
	 */
	public static function describe_decision( array $result ) {
		$state    = isset( $result['state'] ) && is_array( $result['state'] ) ? $result['state'] : null;
		$class    = isset( $result['request_class'] ) ? (string) $result['request_class'] : self::REQUEST_CLASS_UNTRUSTED;
		$decision = isset( $result['decision'] ) ? (string) $result['decision'] : '';
		$suffix   = empty( $result['stored'] ) ? ' (state could not be stored)' : '';

		if ( null === $state ) {
			return 'Crash guard state unavailable.' . $suffix;
		}

		$until = gmdate( 'Y-m-d H:i:s', (int) $state['disabled_until'] ) . ' UTC';
		$pause = self::format_seconds( self::get_window_seconds( $state['level'] ) );

		switch ( $decision ) {
			case self::DECISION_DISABLED:
				return sprintf( 'Crash %d of %d for %s requests: plugin paused for %s (level %d of %d), resumes at %s.', self::STREAK_THRESHOLD, self::STREAK_THRESHOLD, $class, $pause, $state['level'], self::MAX_LEVEL, $until ) . $suffix;
			case self::DECISION_ALREADY_DISABLED:
				return sprintf( 'Plugin is already paused until %s; crash not counted.', $until ) . $suffix;
			case self::DECISION_UNVERIFIED:
				return sprintf( 'Crash %d of %d for %s requests could not be verified (%s); plugin stays enabled, %d unverified crash(es) since %s.', self::STREAK_THRESHOLD, self::STREAK_THRESHOLD, $class, $state['unverified']['reason'], $state['unverified']['count'], gmdate( 'Y-m-d H:i:s', (int) $state['unverified']['last_at'] ) . ' UTC' ) . $suffix;
			case self::DECISION_IGNORED_SPACING:
				return sprintf( 'Within %d seconds of the previous counted crash; not counted (streak %d of %d for %s requests).', self::CRASH_SPACING_SECONDS, $state['streaks'][ $class ]['count'], self::STREAK_THRESHOLD, $class ) . $suffix;
			case self::DECISION_COUNTED:
			default:
				return sprintf( 'Crash %d of %d for %s requests; plugin stays enabled.', $state['streaks'][ $class ]['count'], self::STREAK_THRESHOLD, $class ) . $suffix;
		}
	}

	/**
	 * Normalizes a decoded payload into the current state shape, migrating legacy payloads
	 * and applying decay.
	 *
	 * @since 3.7.9
	 *
	 * @param mixed $raw decoded JSON or transient value.
	 * @param int   $now current Unix timestamp.
	 * @return array
	 */
	public static function normalize( $raw, $now ) {
		$now = (int) $now;

		if ( ! is_array( $raw ) ) {
			return self::empty_state( $now );
		}

		if ( ! isset( $raw['version'] ) ) {
			return self::apply_decay( self::migrate_legacy( $raw, $now ), $now );
		}

		$state                   = self::empty_state( $now );
		$state['revision']       = max( 0, (int) ( $raw['revision'] ?? 0 ) );
		$state['level']          = max( 0, min( self::MAX_LEVEL, (int) ( $raw['level'] ?? 0 ) ) );
		$state['disabled_at']    = max( 0, (int) ( $raw['disabled_at'] ?? 0 ) );
		$state['disabled_until'] = max( 0, (int) ( $raw['disabled_until'] ?? 0 ) );
		$state['last_crash_at']  = max( 0, (int) ( $raw['last_crash_at'] ?? 0 ) );
		$state['updated_at']     = max( 0, (int) ( $raw['updated_at'] ?? $now ) );

		foreach ( array_keys( $state['streaks'] ) as $class ) {
			$streak = isset( $raw['streaks'][ $class ] ) && is_array( $raw['streaks'][ $class ] ) ? $raw['streaks'][ $class ] : [];

			$state['streaks'][ $class ] = [
				'count'           => max( 0, (int) ( $streak['count'] ?? 0 ) ),
				'last_counted_at' => max( 0, (int) ( $streak['last_counted_at'] ?? 0 ) ),
			];
		}

		$unverified          = isset( $raw['unverified'] ) && is_array( $raw['unverified'] ) ? $raw['unverified'] : [];
		$state['unverified'] = [
			'count'   => max( 0, (int) ( $unverified['count'] ?? 0 ) ),
			'last_at' => max( 0, (int) ( $unverified['last_at'] ?? 0 ) ),
			'reason'  => isset( $unverified['reason'] ) ? (string) $unverified['reason'] : '',
		];

		return self::apply_decay( $state, $now );
	}

	/**
	 * Migrates the pre-3.7.9 payload ({"timestamp": …, "crash_count": …}).
	 *
	 * Legacy counts 1 and 2 map to levels 1 and 2 with their original 10-minute and 1-hour
	 * windows. The legacy permanent tier (3 or more) becomes the capped 24-hour window, so a
	 * site left permanently disabled by an older version recovers on its own.
	 *
	 * @since 3.7.9
	 *
	 * @param array $raw legacy payload.
	 * @param int   $now current Unix timestamp.
	 * @return array
	 */
	private static function migrate_legacy( array $raw, $now ) {
		$state = self::empty_state( $now );

		if ( ! isset( $raw['timestamp'], $raw['crash_count'] ) || ! is_numeric( $raw['timestamp'] ) || ! is_numeric( $raw['crash_count'] ) ) {
			return $state;
		}

		$count     = (int) $raw['crash_count'];
		$timestamp = (int) $raw['timestamp'];
		if ( $count <= 0 || $timestamp <= 0 ) {
			return $state;
		}

		$level = $count <= 1 ? 1 : ( 2 === $count ? 2 : self::MAX_LEVEL );

		$state['level']          = $level;
		$state['disabled_at']    = $timestamp;
		$state['disabled_until'] = $timestamp + self::get_window_seconds( $level );
		$state['last_crash_at']  = $timestamp;
		$state['updated_at']     = $timestamp;

		return $state;
	}

	/**
	 * Resets the escalation level after DECAY_SECONDS of crash-free running, and expires
	 * streaks that have gone quiet for STREAK_TTL_SECONDS.
	 *
	 * Decay is measured from the later of the last crash and the end of the last pause, so a
	 * 24-hour pause does not decay the moment it ends.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state normalized state.
	 * @param int   $now   current Unix timestamp.
	 * @return array
	 */
	private static function apply_decay( array $state, $now ) {
		if ( $state['level'] > 0 && $state['disabled_until'] <= $now ) {
			$reference = max( $state['last_crash_at'], $state['disabled_until'] );
			if ( $now - $reference >= self::DECAY_SECONDS ) {
				$state['level']          = 0;
				$state['disabled_at']    = 0;
				$state['disabled_until'] = 0;
			}
		}

		foreach ( $state['streaks'] as $class => $streak ) {
			if ( $streak['count'] > 0 && $now - $streak['last_counted_at'] > self::STREAK_TTL_SECONDS ) {
				$state['streaks'][ $class ] = self::empty_streak();
			}
		}

		if ( $state['unverified']['count'] > 0 && $now - $state['unverified']['last_at'] >= self::DECAY_SECONDS ) {
			$state['unverified'] = self::empty_unverified();
		}

		return $state;
	}

	/**
	 * Moves the state to the next escalation level and starts its pause.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state normalized state.
	 * @param int   $now   current Unix timestamp.
	 * @return array
	 */
	private static function disable( array $state, $now ) {
		$state['level']          = min( self::MAX_LEVEL, $state['level'] + 1 );
		$state['disabled_at']    = $now;
		$state['disabled_until'] = $now + self::get_window_seconds( $state['level'] );

		foreach ( array_keys( $state['streaks'] ) as $class ) {
			$state['streaks'][ $class ] = self::empty_streak();
		}

		$state['unverified'] = self::empty_unverified();

		return $state;
	}

	/**
	 * Applies a mutation to the stored state under an exclusive lock and persists it.
	 *
	 * The mutator receives the current (reconciled) state and returns one of:
	 * - an array: the new state, which is written;
	 * - false: leave the stored state untouched;
	 * - null: remove the stored state. The unlink happens while the lock is still held, so a
	 *   writer waiting on the lock finds the path gone when it gets the lock and starts over
	 *   (see open_locked()); a file that cannot be unlinked is overwritten with the idle state
	 *   instead (see remove_locked()).
	 *
	 * The flag file is the primary store. When it cannot be opened or locked, the mutation is
	 * applied to whatever can be read and the result goes to the transient fallback; removal of
	 * an existing file is skipped in that case because it cannot be done safely, while a
	 * transient-only state is simply deleted.
	 *
	 * @since 3.7.9
	 *
	 * @param callable $mutator receives the normalized state and returns the new state, false or null.
	 * @param int      $now     current Unix timestamp.
	 * @return array [ array $state, bool $stored ]
	 */
	private static function update( callable $mutator, $now ) {
		$path = self::get_flag_file_path();
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
		}

		$handle = is_dir( $dir ) ? self::open_locked( $path ) : false;

		if ( false !== $handle ) {
			$raw = stream_get_contents( $handle );

			// Continue from whichever store is newer: the file may be stale if earlier writes
			// had to fall back to the transient, or empty if it was just created.
			$current = self::reconcile( self::decode( is_string( $raw ) ? $raw : '' ), self::read_transient(), $now );
			$state   = $mutator( $current );

			if ( false === $state ) {
				self::release( $handle );
				return [ $current, true ];
			}

			if ( null === $state ) {
				return self::remove_locked( $handle, $path, $raw, $current, $now );
			}

			$state   = self::stamp( $state, $current, $now );
			$written = self::write_json( $handle, self::encode( $state ) );

			if ( $written ) {
				// The file is authoritative again; a transient left over from a fallback period is stale.
				self::retire_superseded_transient( $state, $now );
			}

			self::release( $handle );

			if ( $written ) {
				return [ $state, true ];
			}

			// The write failed, possibly after the truncate emptied the file. Persist the state that
			// was computed from the correct input instead of recomputing it from what is left; its
			// revision outranks whatever the file now holds.
			return [ $state, self::write_transient( $state ) ];
		}

		// No lock available (unwritable file or directory): the transient is the only store left.
		$current = self::read( $now );
		$state   = $mutator( $current );

		if ( false === $state ) {
			return [ $current, true ];
		}

		if ( null === $state ) {
			// Without the lock a file cannot be removed safely. When there is no file at all the
			// transient is the only store, so removing it cannot resurrect anything stale.
			if ( ! file_exists( $path ) ) {
				return [ self::empty_state( $now ), self::delete_transient() ];
			}

			return [ $current, false ];
		}

		$state = self::stamp( $state, $current, $now );

		return [ $state, self::write_transient( $state ) ];
	}

	/**
	 * Removes the stored state while holding the writer lock.
	 *
	 * Order matters here. The transient may be the very thing that supersedes a stale paused
	 * file (the tombstone "Resume now" leaves when the file cannot be deleted), so it is retired
	 * only once the file can no longer resurrect anything: after the unlink succeeded, or after
	 * the file was overwritten with the idle state through the handle we hold (a read-only
	 * directory blocks unlink but not writes to an existing file). If neither works, the transient
	 * is kept and the call reports failure.
	 *
	 * @since 3.7.9
	 *
	 * @param resource $handle  locked file handle.
	 * @param string   $path    flag file path.
	 * @param mixed    $raw     raw file contents read under the lock.
	 * @param array    $current reconciled state (idle) the mutation started from.
	 * @param int      $now     current Unix timestamp.
	 * @return array [ array $state, bool $stored ]
	 */
	private static function remove_locked( $handle, $path, $raw, array $current, $now ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		clearstatcache( true, $path );

		if ( ! file_exists( $path ) ) {
			self::retire_superseded_transient( $current, $now );
			self::release( $handle );
			return [ self::empty_state( $now ), true ];
		}

		$file_state = self::normalize( self::decode( is_string( $raw ) ? $raw : '' ), $now );

		if ( self::is_idle( $file_state ) ) {
			// The file that stays behind cannot resurrect anything, so the transient can go.
			self::retire_superseded_transient( $file_state, $now );
			self::release( $handle );
			return [ $file_state, true ];
		}

		$idle    = self::stamp( self::empty_state( $now ), $current, $now );
		$written = self::write_json( $handle, self::encode( $idle ) );

		if ( $written ) {
			self::retire_superseded_transient( $idle, $now );
		}

		self::release( $handle );

		return $written ? [ $idle, true ] : [ $current, false ];
	}

	/**
	 * Replaces the contents of a locked handle with the given JSON.
	 *
	 * Only a complete write counts: a short write would leave corrupt JSON behind, which later
	 * reads treat as an absent store.
	 *
	 * @since 3.7.9
	 *
	 * @param resource $handle locked file handle.
	 * @param string   $json   encoded state.
	 * @return bool
	 */
	private static function write_json( $handle, $json ) {
		if ( '' === $json || ! ftruncate( $handle, 0 ) || ! rewind( $handle ) ) {
			return false;
		}

		if ( self::$fail_file_writes_for_testing ) {
			return false;
		}

		$bytes = fwrite( $handle, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fflush( $handle );

		return strlen( $json ) === $bytes;
	}

	/**
	 * Stamps a new state with the next revision and the current time.
	 *
	 * The revision continues from the reconciled state the mutation started from, which is the
	 * highest revision in either store, so the result outranks both even within the same second.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state   new state returned by a mutator.
	 * @param array $current reconciled state the mutator received.
	 * @param int   $now     current Unix timestamp.
	 * @return array
	 */
	private static function stamp( array $state, array $current, $now ) {
		$state['revision']   = (int) $current['revision'] + 1;
		$state['updated_at'] = (int) $now;

		return $state;
	}

	/**
	 * Deletes the transient fallback once the flag file carries state that supersedes it.
	 *
	 * Transient state only exists because the file was unwritable for a while. Once a write to
	 * the file succeeds, the leftover transient is stale, and a later idle cleanup of the file
	 * would otherwise make it authoritative again. The transient is kept when it does not order
	 * before the file state: that can be a crash another request recorded in the same
	 * second without being able to take the lock, and must not be lost. An idle transient is
	 * always safe to drop.
	 *
	 * Called while holding the writer lock.
	 *
	 * @since 3.7.9
	 *
	 * @param array $file_state state now held (or last held) by the file.
	 * @param int   $now        current Unix timestamp.
	 */
	private static function retire_superseded_transient( array $file_state, $now ) {
		$transient = self::read_transient();
		if ( null === $transient ) {
			return;
		}

		$state = self::normalize( $transient, $now );

		if ( self::is_idle( $state ) || self::compare_order( $state, $file_state ) < 0 ) {
			self::delete_transient();
		}
	}

	/**
	 * Opens the flag file and takes an exclusive lock on it.
	 *
	 * After the lock is acquired, the handle is checked against the path: another request may
	 * have removed or replaced the file while this one was waiting, in which case writing
	 * through the handle would land in an orphaned inode. The open is then retried so the
	 * write ends up in the file that the path now names.
	 *
	 * @since 3.7.9
	 *
	 * @param string $path flag file path.
	 * @return resource|false locked handle positioned at the start, or false.
	 */
	private static function open_locked( $path ) {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$handle = @fopen( $path, 'c+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $handle ) {
				return false;
			}

			if ( ! @flock( $handle, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return false;
			}

			clearstatcache( true, $path );
			$path_stat   = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$handle_stat = fstat( $handle );

			if ( is_array( $path_stat ) && is_array( $handle_stat ) && $path_stat['dev'] === $handle_stat['dev'] && $path_stat['ino'] === $handle_stat['ino'] ) {
				return $handle;
			}

			self::release( $handle );
		}

		return false;
	}

	/**
	 * Releases a locked handle.
	 *
	 * @since 3.7.9
	 *
	 * @param resource $handle locked handle.
	 */
	private static function release( $handle ) {
		flock( $handle, LOCK_UN );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Picks the newer of the two stores.
	 *
	 * Ordered by compare_order(); ties go to the file. A missing or corrupt store never beats a
	 * valid one.
	 *
	 * @since 3.7.9
	 *
	 * @param mixed $file_raw      decoded flag file payload, or null.
	 * @param mixed $transient_raw transient payload, or null.
	 * @param int   $now           current Unix timestamp.
	 * @return array normalized state.
	 */
	private static function reconcile( $file_raw, $transient_raw, $now ) {
		$file_state      = is_array( $file_raw ) ? self::normalize( $file_raw, $now ) : null;
		$transient_state = is_array( $transient_raw ) ? self::normalize( $transient_raw, $now ) : null;

		if ( null === $file_state ) {
			return null === $transient_state ? self::empty_state( $now ) : $transient_state;
		}

		if ( null === $transient_state ) {
			return $file_state;
		}

		return self::compare_order( $transient_state, $file_state ) > 0 ? $transient_state : $file_state;
	}

	/**
	 * Orders two states: by revision first, then by updated_at.
	 *
	 * Revisions are monotonically increasing per write chain, so they order writes made within
	 * the same second; updated_at only matters for legacy payloads, which carry no revision.
	 *
	 * @since 3.7.9
	 *
	 * @param array $a normalized state.
	 * @param array $b normalized state.
	 * @return int negative when $a is older than $b, positive when newer, 0 when indistinguishable.
	 */
	private static function compare_order( array $a, array $b ) {
		if ( (int) $a['revision'] !== (int) $b['revision'] ) {
			return (int) $a['revision'] < (int) $b['revision'] ? -1 : 1;
		}

		if ( (int) $a['updated_at'] !== (int) $b['updated_at'] ) {
			return (int) $a['updated_at'] < (int) $b['updated_at'] ? -1 : 1;
		}

		return 0;
	}

	/**
	 * Deletes the transient fallback without letting storage errors escape.
	 *
	 * @since 3.7.9
	 *
	 * @return bool whether no transient remains.
	 */
	private static function delete_transient() {
		if ( ! function_exists( 'delete_transient' ) ) {
			return true;
		}

		try {
			return false === get_transient( self::TRANSIENT_KEY ) || delete_transient( self::TRANSIENT_KEY );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Reads the raw flag file contents.
	 *
	 * @since 3.7.9
	 *
	 * @return string|null null when the file is absent or unreadable.
	 */
	private static function read_file_raw() {
		$path = self::get_flag_file_path();
		if ( ! is_readable( $path ) ) {
			return null;
		}

		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_string( $raw ) ? $raw : null;
	}

	/**
	 * Reads the transient fallback without letting storage errors escape a dying request.
	 *
	 * @since 3.7.9
	 *
	 * @return array|null
	 */
	private static function read_transient() {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}

		try {
			$value = get_transient( self::TRANSIENT_KEY );
		} catch ( \Throwable $e ) {
			unset( $e );
			return null;
		}

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Writes the transient fallback without letting storage errors escape a dying request.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state normalized state.
	 * @return bool
	 */
	private static function write_transient( array $state ) {
		if ( ! function_exists( 'set_transient' ) ) {
			return false;
		}

		try {
			if ( set_transient( self::TRANSIENT_KEY, $state, 30 * self::DECAY_SECONDS ) ) {
				return true;
			}

			// set_transient() also reports false when the stored value was already identical,
			// so confirm by reading back before calling the write a failure.
			$stored = get_transient( self::TRANSIENT_KEY );

			return is_array( $stored ) && $stored == $state; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- key order may differ.
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Decodes a JSON payload.
	 *
	 * @since 3.7.9
	 *
	 * @param string|null $raw JSON string.
	 * @return array|null
	 */
	private static function decode( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Encodes state as JSON.
	 *
	 * @since 3.7.9
	 *
	 * @param array $state normalized state.
	 * @return string
	 */
	private static function encode( array $state ) {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $state ) : json_encode( $state ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Returns an empty streak record.
	 *
	 * @since 3.7.9
	 *
	 * @return array
	 */
	private static function empty_streak() {
		return [
			'count'           => 0,
			'last_counted_at' => 0,
		];
	}

	/**
	 * Returns an empty record of crashes that reached the threshold without a verdict.
	 *
	 * @since 3.7.9
	 *
	 * @return array
	 */
	private static function empty_unverified() {
		return [
			'count'   => 0,
			'last_at' => 0,
			'reason'  => '',
		];
	}

	/**
	 * Coerces an arbitrary request class value to a known one.
	 *
	 * @since 3.7.9
	 *
	 * @param mixed $request_class candidate value.
	 * @return string
	 */
	private static function normalize_request_class( $request_class ) {
		return self::REQUEST_CLASS_TRUSTED === $request_class ? self::REQUEST_CLASS_TRUSTED : self::REQUEST_CLASS_UNTRUSTED;
	}

	/**
	 * Resolves an optional timestamp argument.
	 *
	 * @since 3.7.9
	 *
	 * @param int|null $now candidate timestamp.
	 * @return int
	 */
	private static function resolve_now( $now ) {
		return null === $now ? time() : (int) $now;
	}

	/**
	 * Formats a duration for log lines.
	 *
	 * @since 3.7.9
	 *
	 * @param int $seconds duration.
	 * @return string
	 */
	private static function format_seconds( $seconds ) {
		$seconds = (int) $seconds;

		if ( $seconds >= 3600 ) {
			$hours = (int) round( $seconds / 3600 );
			return 1 === $hours ? '1 hour' : $hours . ' hours';
		}

		$minutes = max( 1, (int) round( $seconds / 60 ) );

		return 1 === $minutes ? '1 minute' : $minutes . ' minutes';
	}
}
