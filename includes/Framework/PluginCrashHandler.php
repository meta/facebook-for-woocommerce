<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Framework;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin fatal crashes on PHP shutdown.
 *
 * Known limitation: PHP memory-exhaustion fatals may leave too little memory for
 * the shutdown handler to run. In that case disable-flag writes and crash queueing
 * may be skipped for that request.
 *
 * @since 3.6.4
 */
class PluginCrashHandler {

	/**
	 * Cache group for crash report deduplication locks.
	 */
	const CRASH_REPORT_CACHE_GROUP = 'wc_facebook_crash_reports';

	/**
	 * Transient key for crash report rate limiting.
	 */
	const CRASH_RATE_LIMIT_KEY = 'wc_facebook_crash_rate_limit';

	/**
	 * Transient key prefix for per-fingerprint crash aggregates.
	 */
	const CRASH_AGGREGATE_KEY_PREFIX = 'wc_facebook_crash_agg_';

	/**
	 * Transient key for aggregate fingerprint index.
	 */
	const CRASH_AGGREGATE_INDEX_KEY = 'wc_facebook_crash_agg_index';

	/**
	 * Transient key prefix for per-fingerprint queue locks.
	 */
	const CRASH_QUEUE_LOCK_PREFIX = 'wc_facebook_crash_queue_lock_';

	/**
	 * Max distinct crash fingerprints to retain in local aggregates.
	 */
	const CRASH_MAX_DISTINCT_FINGERPRINTS = 100;

	/**
	 * Soft cap for queued crash reports in Action Scheduler.
	 */
	const CRASH_MAX_QUEUED_REPORTS = 50;

	/**
	 * Max payload size for queued crash report data.
	 */
	const CRASH_MAX_PAYLOAD_BYTES = 16000;

	/**
	 * Max length of aggregate last sample message.
	 */
	const CRASH_MAX_SAMPLE_MESSAGE_LENGTH = 200;

	/**
	 * Max random delay before queued crash reports are sent.
	 */
	const CRASH_REPORT_MAX_JITTER_SECONDS = 3600;

	/**
	 * Max number of crash reports allowed in the rate-limit window.
	 */
	const CRASH_RATE_LIMIT_MAX = 10;

	/**
	 * Rate-limit window length in seconds.
	 */
	const CRASH_RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

	/**
	 * Previously registered exception handler.
	 *
	 * @var callable|null
	 */
	private $previous_exception_handler;

	/**
	 * Last uncaught throwable normalized into an error-like payload.
	 *
	 * @var array|null
	 */
	private $captured_throwable_error;

	/**
	 * Whether crash hooks were already registered for this request.
	 *
	 * @var bool
	 */
	private static $is_registered = false;

	/**
	 * Transient holding a sanitized summary of the last plugin crash, shown in the admin notice.
	 *
	 * @since 3.7.9
	 */
	const LAST_CRASH_TRANSIENT = 'wc_facebook_crash_guard_last_crash';

	/**
	 * Query argument that marks the crash guard's own self-check request.
	 *
	 * @since 3.7.9
	 */
	const SELF_CHECK_QUERY_ARG = 'wc_facebook_crash_probe';

	/**
	 * Transient prefix for a self-check token the guard is waiting on.
	 *
	 * @since 3.7.9
	 */
	const SELF_CHECK_PENDING_PREFIX = 'wc_facebook_crash_probe_pending_';

	/**
	 * Transient prefix for the outcome a self-check request recorded.
	 *
	 * @since 3.7.9
	 */
	const SELF_CHECK_OUTCOME_PREFIX = 'wc_facebook_crash_probe_result_';

	/**
	 * Seconds the self-check request may take.
	 *
	 * @since 3.7.9
	 */
	const SELF_CHECK_TIMEOUT = 8;

	const SELF_CHECK_CLEAN        = 'clean';
	const SELF_CHECK_CRASHED      = 'crashed';
	const SELF_CHECK_INCONCLUSIVE = 'inconclusive';
	const SELF_CHECK_SKIPPED      = 'skipped';

	/**
	 * Token carried by this request when it is the crash guard's own self-check; empty otherwise.
	 *
	 * @var string
	 */
	private static $self_check_token = '';

	/**
	 * Whether the plugin completed its bootstrap in this request (as opposed to disabled mode).
	 *
	 * Only such requests count as healthy evidence for the crash guard streak.
	 *
	 * @var bool
	 */
	private static $plugin_initialized = false;

	/**
	 * Registers crash handling hooks.
	 *
	 * @since 3.6.4
	 */
	public function register() {
		if ( self::$is_registered ) {
			return;
		}

		self::detect_self_check_request();

		$this->previous_exception_handler = set_exception_handler( [ $this, 'handle_uncaught_exception' ] );
		register_shutdown_function( [ $this, 'handle_shutdown' ] );
		self::$is_registered = true;
	}

	/**
	 * Captures uncaught throwables so shutdown handling can process them.
	 *
	 * @since 3.6.4
	 *
	 * @param Throwable $throwable uncaught throwable.
	 * @throws Throwable The same throwable, when no previous handler exists, so PHP reports it as uncaught.
	 */
	public function handle_uncaught_exception( Throwable $throwable ) {
		$this->captured_throwable_error = $this->normalize_throwable_to_error( $throwable );

		if ( is_callable( $this->previous_exception_handler ) ) {
			call_user_func( $this->previous_exception_handler, $throwable );
			return;
		}

		// Rethrowing from the exception handler lets PHP finish the request the standard way:
		// it logs the uncaught exception and populates error_get_last(), so WordPress's own
		// fatal error handler renders its critical-error page with a 500 status. Returning
		// silently here produced a blank HTTP 200 response and no log line at all.
		throw $throwable;
	}

	/**
	 * Recognizes the crash guard's own self-check request.
	 *
	 * A request qualifies only when it carries a token that a crashing request registered moments
	 * ago, so a visitor cannot mark their own requests as self-checks. Runs at plugin load so the
	 * events tracker can see it before any hook fires.
	 *
	 * @since 3.7.9
	 *
	 * @return bool whether this request is a self-check.
	 */
	public static function detect_self_check_request() {
		self::$self_check_token = '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The token itself is the credential.
		if ( ! isset( $_GET[ self::SELF_CHECK_QUERY_ARG ] ) || ! function_exists( 'get_transient' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = preg_replace( '/[^a-f0-9]/', '', sanitize_key( wp_unslash( $_GET[ self::SELF_CHECK_QUERY_ARG ] ) ) );
		if ( '' === $token || strlen( $token ) > 64 ) {
			return false;
		}

		try {
			$pending = get_transient( self::SELF_CHECK_PENDING_PREFIX . $token );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}

		if ( false === $pending ) {
			return false;
		}

		self::$self_check_token = $token;

		return true;
	}

	/**
	 * Whether this request is the crash guard's own self-check.
	 *
	 * The events tracker uses this to send nothing to Meta for it: a self-check is not a visitor.
	 *
	 * @since 3.7.9
	 *
	 * @return bool
	 */
	public static function is_self_check_request() {
		return '' !== self::$self_check_token;
	}

	/**
	 * Marks the plugin as fully bootstrapped for this request.
	 *
	 * Called by the loader once the main plugin class has been constructed. Requests that
	 * never reach this point (disabled mode, incompatible environment) are not healthy
	 * evidence for the crash guard, so they must not reset its streaks.
	 *
	 * @since 3.7.9
	 */
	public static function mark_plugin_initialized() {
		self::$plugin_initialized = true;
	}

	/**
	 * Whether mark_plugin_initialized() was called in this request.
	 *
	 * @since 3.7.9
	 *
	 * @return bool
	 */
	public static function is_plugin_initialized() {
		return self::$plugin_initialized;
	}

	/**
	 * Captures fatal plugin crashes on shutdown.
	 *
	 * @since 3.6.4
	 */
	public function handle_shutdown() {
		$error    = $this->normalize_captured_error( error_get_last(), 'fatal_error' );
		$captured = $this->normalize_captured_error( $this->captured_throwable_error, 'uncaught_exception' );

		// Prefer the throwable captured by handle_uncaught_exception(): once it is rethrown, PHP
		// also reports it through error_get_last() with a message that embeds the stack trace
		// and no exception class, which would change crash fingerprints and reports.
		if ( ! $this->is_supported_fatal_error( $error ) || $this->is_rethrown_captured_throwable( $error, $captured ) ) {
			$error = $captured;
		}

		if ( ! $this->is_supported_fatal_error( $error ) ) {
			if ( self::is_self_check_request() ) {
				// A clean finish proves nothing unless the plugin actually bootstrapped: auth
				// middleware or an early exit can end the request before plugins_loaded, exactly
				// as the healthy-request path below refuses such requests as evidence.
				$this->record_self_check_outcome( self::$plugin_initialized ? self::SELF_CHECK_CLEAN : self::SELF_CHECK_INCONCLUSIVE );
				return;
			}

			$this->record_healthy_request();
			return;
		}

		if ( ! $this->is_plugin_error( $error ) ) {
			if ( self::is_self_check_request() ) {
				// Something else broke the self-check, so it says nothing about the plugin.
				$this->record_self_check_outcome( self::SELF_CHECK_INCONCLUSIVE );
			}
			return;
		}

		if ( self::is_self_check_request() ) {
			// The plugin crashed on a plain request. The request that asked for this check will
			// pause the plugin; it already reported the crash, so this one is not counted again.
			$this->record_self_check_outcome( self::SELF_CHECK_CRASHED );
			return;
		}

		$this->record_plugin_crash( $error );

		try {
			$normalized_report = $this->normalize_crash_report_payload( $error );
			$fingerprint       = $this->generate_crash_fingerprint( $normalized_report );
			$aggregate         = $this->increment_crash_aggregate( $fingerprint, $normalized_report );
			$report_to_queue   = $this->attach_aggregate_to_report( $normalized_report, $aggregate, $fingerprint );
			$report_to_queue   = $this->apply_payload_size_guard( $report_to_queue );
		} catch ( Throwable $e ) {
			unset( $e );
			return;
		}

		if ( ErrorLogHandler::is_crash_reporting_paused() ) {
			return;
		}

		$dedup_lock_storage = null;
		if ( ! $this->should_queue_crash_report( $normalized_report, $dedup_lock_storage ) ) {
			$this->increment_duplicate_crash_counter( $normalized_report );
			return;
		}

		if ( $this->should_rate_limit_crash_report() ) {
			$this->increment_rate_limited_counter();
			$this->log_crash_observability( '[FBW_DEDUP_DEBUG] report skipped before enqueue: reason=rate_limited fingerprint=' . $fingerprint );
			$this->release_crash_dedup_lock( $normalized_report, 'rate_limited', $dedup_lock_storage );
			return;
		}

		$queue_size = 0;
		if ( ! $this->can_queue_more_crash_reports( $queue_size ) ) {
			if ( ! $this->maybe_trim_queue_for_prioritized_report( $report_to_queue, $queue_size ) ) {
				$this->log_crash_observability( '[FBW_CRASH_OBS] crash report dropped: reason=queue_cap fingerprint=' . $fingerprint . ' aggregate_count=' . (int) ( $report_to_queue['extra_data']['aggregate_count'] ?? 0 ) . ' queue_size=' . (int) $queue_size . ' queue_max=' . (int) self::CRASH_MAX_QUEUED_REPORTS );
				$this->log_crash_observability( '[FBW_DEDUP_DEBUG] report dropped before enqueue: reason=queue_cap fingerprint=' . $fingerprint );
				$this->release_crash_dedup_lock( $normalized_report, 'queue_cap', $dedup_lock_storage );
				return;
			}
		}

		if ( $this->has_pending_crash_queue_lock( $fingerprint ) ) {
			$this->log_crash_observability( '[FBW_DEDUP_DEBUG] report skipped before enqueue: reason=pending_queue_lock fingerprint=' . $fingerprint );
			$this->release_crash_dedup_lock( $normalized_report, 'pending_queue_lock', $dedup_lock_storage );
			return;
		}

		if ( ! $this->queue_crash_report( $report_to_queue ) ) {
			$this->log_crash_observability( '[FBW_DEDUP_DEBUG] report dropped before enqueue: reason=enqueue_failed fingerprint=' . $fingerprint );
			$this->release_crash_dedup_lock( $normalized_report, 'enqueue_failed', $dedup_lock_storage );
			$this->log_fallback( $report_to_queue );
			return;
		}

		$this->set_pending_crash_queue_lock( $fingerprint );
	}

	/**
	 * Checks if a crash report is already queued for this fingerprint.
	 *
	 * @since 3.6.4
	 *
	 * @param string $fingerprint crash fingerprint.
	 * @return bool
	 */
	private function has_pending_crash_queue_lock( $fingerprint ) {
		if ( '' === $fingerprint ) {
			return false;
		}

		return false !== $this->safe_get_transient( self::CRASH_QUEUE_LOCK_PREFIX . $fingerprint );
	}

	/**
	 * Sets a per-fingerprint queue lock after successful enqueue.
	 *
	 * @since 3.6.4
	 *
	 * @param string $fingerprint crash fingerprint.
	 */
	private function set_pending_crash_queue_lock( $fingerprint ) {
		if ( '' === $fingerprint ) {
			return;
		}

		$this->safe_set_transient( self::CRASH_QUEUE_LOCK_PREFIX . $fingerprint, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Increments local aggregate for a crash fingerprint.
	 *
	 * @since 3.6.4
	 *
	 * @param string $fingerprint crash fingerprint.
	 * @param array  $report normalized crash report payload.
	 * @return array
	 */
	private function increment_crash_aggregate( $fingerprint, array $report ) {
		$key            = $this->get_crash_aggregate_key( $fingerprint );
		$current        = $this->safe_get_transient( $key );
		$now            = time();
		$count          = is_array( $current ) && isset( $current['count'] ) ? (int) $current['count'] : 0;
		$first          = is_array( $current ) && isset( $current['first_seen'] ) ? (int) $current['first_seen'] : $now;
		$sample_message = isset( $report['exception_message'] ) ? (string) $report['exception_message'] : '';
		if ( function_exists( 'mb_substr' ) ) {
			$sample_message = mb_substr( $sample_message, 0, self::CRASH_MAX_SAMPLE_MESSAGE_LENGTH );
		} else {
			$sample_message = substr( $sample_message, 0, self::CRASH_MAX_SAMPLE_MESSAGE_LENGTH );
		}

		$sample = [
			'event_type' => isset( $report['event_type'] ) ? (string) $report['event_type'] : '',
			'message'    => $sample_message,
			'file'       => isset( $report['extra_data']['file'] ) ? (string) $report['extra_data']['file'] : '',
			'line'       => isset( $report['extra_data']['line'] ) ? (int) $report['extra_data']['line'] : 0,
		];

		$aggregate = [
			'count'       => $count + 1,
			'first_seen'  => $first,
			'last_seen'   => $now,
			'last_sample' => $sample,
		];

		$this->safe_set_transient( $key, $aggregate, DAY_IN_SECONDS );
		$this->update_crash_aggregate_index( $fingerprint, (int) $aggregate['count'], (int) $aggregate['last_seen'] );

		return $aggregate;
	}

	/**
	 * Updates aggregate index and trims less frequent fingerprints when needed.
	 *
	 * @since 3.6.4
	 *
	 * @param string $fingerprint fingerprint key.
	 * @param int    $count aggregate count.
	 * @param int    $last_seen unix timestamp.
	 */
	private function update_crash_aggregate_index( $fingerprint, $count, $last_seen ) {
		$index = $this->safe_get_transient( self::CRASH_AGGREGATE_INDEX_KEY );
		if ( ! is_array( $index ) ) {
			$index = [];
		}

		$index[ $fingerprint ] = [
			'count'     => (int) $count,
			'last_seen' => (int) $last_seen,
		];

		if ( count( $index ) > self::CRASH_MAX_DISTINCT_FINGERPRINTS ) {
			uasort(
				$index,
				function ( $a, $b ) {
					$count_cmp = ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 );
					if ( 0 !== $count_cmp ) {
						return $count_cmp;
					}
					return ( $b['last_seen'] ?? 0 ) <=> ( $a['last_seen'] ?? 0 );
				}
			);

			$index = array_slice( $index, 0, self::CRASH_MAX_DISTINCT_FINGERPRINTS, true );

			$kept = array_fill_keys( array_keys( $index ), true );
			foreach ( $this->get_all_known_aggregate_fingerprints() as $known_fingerprint ) {
				if ( isset( $kept[ $known_fingerprint ] ) ) {
					continue;
				}
				$this->safe_delete_transient( $this->get_crash_aggregate_key( $known_fingerprint ) );
			}
		}

		$this->safe_set_transient( self::CRASH_AGGREGATE_INDEX_KEY, $index, DAY_IN_SECONDS );
	}

	/**
	 * Gets all fingerprints tracked in the aggregate index.
	 *
	 * @since 3.6.4
	 *
	 * @return array
	 */
	private function get_all_known_aggregate_fingerprints() {
		$index = $this->safe_get_transient( self::CRASH_AGGREGATE_INDEX_KEY );
		return is_array( $index ) ? array_keys( $index ) : [];
	}

	/**
	 * Attaches aggregate metadata to a report payload.
	 *
	 * @since 3.6.4
	 *
	 * @param array  $report normalized report.
	 * @param array  $aggregate aggregate payload.
	 * @param string $fingerprint crash fingerprint.
	 * @return array
	 */
	private function attach_aggregate_to_report( array $report, array $aggregate, $fingerprint ) {
		if ( ! isset( $report['extra_data'] ) || ! is_array( $report['extra_data'] ) ) {
			$report['extra_data'] = [];
		}

		$report['extra_data']['aggregate_count']       = isset( $aggregate['count'] ) ? (int) $aggregate['count'] : 1;
		$report['extra_data']['aggregate_first_seen']  = isset( $aggregate['first_seen'] ) ? (int) $aggregate['first_seen'] : time();
		$report['extra_data']['aggregate_last_seen']   = isset( $aggregate['last_seen'] ) ? (int) $aggregate['last_seen'] : time();
		$report['extra_data']['aggregate_last_sample'] = isset( $aggregate['last_sample'] ) && is_array( $aggregate['last_sample'] ) ? $aggregate['last_sample'] : [];
		$report['extra_data']['fingerprint']           = (string) $fingerprint;

		return $report;
	}

	/**
	 * Clears local aggregate for a crash fingerprint after successful queue.
	 *
	 * @since 3.6.4
	 *
	 * @param string $fingerprint crash fingerprint.
	 */
	private function clear_crash_aggregate( $fingerprint ) {
		$this->safe_delete_transient( $this->get_crash_aggregate_key( $fingerprint ) );

		$index = $this->safe_get_transient( self::CRASH_AGGREGATE_INDEX_KEY );
		if ( is_array( $index ) && isset( $index[ $fingerprint ] ) ) {
			unset( $index[ $fingerprint ] );
			$this->safe_set_transient( self::CRASH_AGGREGATE_INDEX_KEY, $index, DAY_IN_SECONDS );
		}
	}

	/**
	 * Builds aggregate transient key for a fingerprint.
	 *
	 * @since 3.6.4
	 *
	 * @param string $fingerprint crash fingerprint.
	 * @return string
	 */
	private function get_crash_aggregate_key( $fingerprint ) {
		return self::CRASH_AGGREGATE_KEY_PREFIX . ( '' !== $fingerprint ? $fingerprint : 'default' );
	}

	/**
	 * Applies a lightweight payload-size guard before queueing.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report normalized crash report payload.
	 * @return array
	 */
	private function apply_payload_size_guard( array $report ) {
		$json = wp_json_encode( $report );
		if ( is_string( $json ) && strlen( $json ) <= self::CRASH_MAX_PAYLOAD_BYTES ) {
			return $report;
		}

		$bytes_before   = is_string( $json ) ? strlen( $json ) : 0;
		$trimmed_fields = [];
		$fingerprint    = isset( $report['extra_data']['fingerprint'] ) ? (string) $report['extra_data']['fingerprint'] : '';
		$agg_count      = isset( $report['extra_data']['aggregate_count'] ) ? (int) $report['extra_data']['aggregate_count'] : 0;

		if ( isset( $report['extra_data']['plugin_stack'] ) && is_array( $report['extra_data']['plugin_stack'] ) ) {
			$report['extra_data']['plugin_stack'] = array_slice( $report['extra_data']['plugin_stack'], 0, 2 );
			$trimmed_fields[]                     = 'plugin_stack';
		}

		if ( isset( $report['extra_data']['aggregate_last_sample'] ) ) {
			unset( $report['extra_data']['aggregate_last_sample'] );
			$trimmed_fields[] = 'aggregate_last_sample';
		}

		if ( isset( $report['exception_message'] ) && is_string( $report['exception_message'] ) ) {
			if ( function_exists( 'mb_substr' ) ) {
				$report['exception_message'] = mb_substr( $report['exception_message'], 0, 250 );
			} else {
				$report['exception_message'] = substr( $report['exception_message'], 0, 250 );
			}
			$trimmed_fields[] = 'exception_message';
		}

		$json_after  = wp_json_encode( $report );
		$bytes_after = is_string( $json_after ) ? strlen( $json_after ) : 0;
		error_log( '[FBW_CRASH_OBS] crash payload trimmed: reason=payload_size fingerprint=' . $fingerprint . ' aggregate_count=' . $agg_count . ' bytes_before=' . $bytes_before . ' bytes_after=' . $bytes_after . ' trimmed_fields=' . implode( ',', $trimmed_fields ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return $report;
	}

	/**
	 * Soft limit guard for queued crash reports.
	 *
	 * @since 3.6.4
	 *
	 * @param int $queue_size receives current queue size snapshot.
	 * @return bool
	 */
	private function can_queue_more_crash_reports( &$queue_size = 0 ) {
		$queue_size = 0;
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return true;
		}

		$actions = as_get_scheduled_actions(
			[
				'hook'     => ErrorLogHandler::META_LOG_API,
				'group'    => ErrorLogHandler::META_LOG_API_GROUP,
				'status'   => [ 'pending', 'in-progress' ],
				'per_page' => self::CRASH_MAX_QUEUED_REPORTS + 1,
			],
			'ids'
		);

		if ( ! is_array( $actions ) ) {
			return true;
		}

		$queue_size = count( $actions );
		return $queue_size <= self::CRASH_MAX_QUEUED_REPORTS;
	}

	/**
	 * Trims one low-value pending queued crash report when queue is full and incoming report is higher value.
	 *
	 * Strategy: prefer keeping reports with higher aggregate_count; when tied, keep existing and drop incoming.
	 *
	 * @since 3.6.4
	 *
	 * @param array $incoming_report incoming normalized crash payload.
	 * @param int   $queue_size current queue size snapshot.
	 * @return bool true when a lower-value pending report was removed and incoming report should proceed.
	 */
	protected function maybe_trim_queue_for_prioritized_report( array $incoming_report, $queue_size ) {
		$incoming_count = $this->get_report_aggregate_count( $incoming_report );
		$incoming_fp    = isset( $incoming_report['extra_data']['fingerprint'] ) ? (string) $incoming_report['extra_data']['fingerprint'] : '';
		$actions        = $this->get_pending_crash_queue_actions( self::CRASH_MAX_QUEUED_REPORTS + 10 );

		if ( ! is_array( $actions ) || empty( $actions ) ) {
			return false;
		}

		$best_candidate_report = null;
		$best_candidate_id     = 0;
		$best_count            = null;

		foreach ( $actions as $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}

			$args = $action->get_args();
			if ( ! is_array( $args ) || ! isset( $args[0] ) || ! is_array( $args[0] ) ) {
				continue;
			}

			$candidate_report = $args[0];
			$candidate_count  = $this->get_report_aggregate_count( $candidate_report );
			$candidate_id     = method_exists( $action, 'get_id' ) ? (int) $action->get_id() : 0;

			if ( null === $best_count || $candidate_count < $best_count ) {
				$best_count            = $candidate_count;
				$best_candidate_report = $candidate_report;
				$best_candidate_id     = $candidate_id;
			}
		}

		if ( ! is_array( $best_candidate_report ) || null === $best_count ) {
			return false;
		}

		if ( $incoming_count <= $best_count ) {
			$this->log_crash_observability( '[FBW_CRASH_OBS] crash report dropped: reason=queue_priority fingerprint=' . $incoming_fp . ' aggregate_count=' . (int) $incoming_count . ' queue_size=' . (int) $queue_size . ' kept_min_aggregate_count=' . (int) $best_count );
			return false;
		}

		$removed = $this->unschedule_pending_crash_action( $best_candidate_report, $best_candidate_id );
		if ( empty( $removed ) ) {
			return false;
		}

		$removed_fp = isset( $best_candidate_report['extra_data']['fingerprint'] ) ? (string) $best_candidate_report['extra_data']['fingerprint'] : '';
		if ( '' !== $removed_fp ) {
			$this->safe_delete_transient( self::CRASH_QUEUE_LOCK_PREFIX . $removed_fp );
		}
		$this->log_crash_observability( '[FBW_CRASH_OBS] crash queue trimmed: reason=queue_priority_replace removed_fingerprint=' . $removed_fp . ' removed_aggregate_count=' . (int) $best_count . ' incoming_fingerprint=' . $incoming_fp . ' incoming_aggregate_count=' . (int) $incoming_count . ' queue_size=' . (int) $queue_size );

		return true;
	}

	/**
	 * Extracts aggregate count from report payload.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report crash report.
	 * @return int
	 */
	protected function get_report_aggregate_count( array $report ) {
		$count = isset( $report['extra_data']['aggregate_count'] ) ? (int) $report['extra_data']['aggregate_count'] : 1;
		return max( 1, $count );
	}

	/**
	 * Returns pending crash actions from Action Scheduler.
	 *
	 * @since 3.6.4
	 *
	 * @param int $per_page max actions to fetch.
	 * @return array
	 */
	protected function get_pending_crash_queue_actions( $per_page ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return [];
		}

		return as_get_scheduled_actions(
			[
				'hook'     => ErrorLogHandler::META_LOG_API,
				'group'    => ErrorLogHandler::META_LOG_API_GROUP,
				'status'   => 'pending',
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => (int) $per_page,
			],
			OBJECT
		);
	}

	/**
	 * Unschedules one pending crash action, preferring Action Scheduler ID cancellation.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report queued crash report payload.
	 * @param int   $action_id queued Action Scheduler ID when available.
	 * @return int
	 */
	protected function unschedule_pending_crash_action( array $report, $action_id = 0 ) {
		$action_id = (int) $action_id;

		if ( $action_id > 0 && class_exists( 'ActionScheduler' ) && method_exists( 'ActionScheduler', 'store' ) ) {
			try {
				$store = \ActionScheduler::store();
				if ( is_object( $store ) && method_exists( $store, 'cancel_action' ) ) {
					$store->cancel_action( $action_id );
					return 1;
				}
			} catch ( Throwable $e ) {
				unset( $e );
				// Fall back to payload-based unschedule below.
			}
		}

		if ( ! function_exists( 'as_unschedule_action' ) ) {
			return 0;
		}

		return (int) as_unschedule_action( ErrorLogHandler::META_LOG_API, [ $report ], ErrorLogHandler::META_LOG_API_GROUP );
	}

	/**
	 * Emits crash observability log marker.
	 *
	 * @since 3.6.4
	 *
	 * @param string $message message to log.
	 */
	protected function log_crash_observability( $message ) {
		error_log( (string) $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}


	/**
	 * Checks if crash reporting is currently rate limited.
	 *
	 * Uses a simple rolling window counter in a dedicated transient.
	 *
	 * @since 3.6.4
	 *
	 * @return bool True when sending should be skipped.
	 */
	private function should_rate_limit_crash_report() {
		$now   = time();
		$state = $this->safe_get_transient( self::CRASH_RATE_LIMIT_KEY );

		if ( ! is_array( $state ) ) {
			$state = [
				'window_started_at' => $now,
				'count'             => 0,
				'limited_count'     => 0,
				'last_seen'         => 0,
			];
		}

		$window_started_at = isset( $state['window_started_at'] ) ? (int) $state['window_started_at'] : 0;
		$count             = isset( $state['count'] ) ? (int) $state['count'] : 0;
		$limited_count     = isset( $state['limited_count'] ) ? (int) $state['limited_count'] : 0;

		if ( $window_started_at <= 0 || ( $now - $window_started_at ) >= self::CRASH_RATE_LIMIT_WINDOW ) {
			$window_started_at = $now;
			$count             = 0;
			$limited_count     = 0;
		}

		if ( $count >= self::CRASH_RATE_LIMIT_MAX ) {
			$this->safe_set_transient(
				self::CRASH_RATE_LIMIT_KEY,
				[
					'window_started_at' => $window_started_at,
					'count'             => $count,
					'limited_count'     => $limited_count,
					'last_seen'         => $now,
				],
				self::CRASH_RATE_LIMIT_WINDOW
			);

			return true;
		}

		$this->safe_set_transient(
			self::CRASH_RATE_LIMIT_KEY,
			[
				'window_started_at' => $window_started_at,
				'count'             => $count + 1,
				'limited_count'     => $limited_count,
				'last_seen'         => $now,
			],
			self::CRASH_RATE_LIMIT_WINDOW
		);

		return false;
	}

	/**
	 * Increments local counters for rate-limited reports.
	 *
	 * @since 3.6.4
	 */
	private function increment_rate_limited_counter() {
		$now   = time();
		$state = $this->safe_get_transient( self::CRASH_RATE_LIMIT_KEY );

		if ( ! is_array( $state ) ) {
			$state = [
				'window_started_at' => $now,
				'count'             => 0,
				'limited_count'     => 0,
				'last_seen'         => 0,
			];
		}

		$state['limited_count'] = isset( $state['limited_count'] ) ? ( (int) $state['limited_count'] + 1 ) : 1;
		$state['last_seen']     = $now;

		$this->safe_set_transient( self::CRASH_RATE_LIMIT_KEY, $state, self::CRASH_RATE_LIMIT_WINDOW );
	}

	/**
	 * Determines whether a crash report should be queued based on dedup lock.
	 *
	 * @since 3.6.4
	 *
	 * @param array       $report normalized crash report payload.
	 * @param string|null $lock_storage receives lock backend used for acquisition.
	 * @return bool
	 */
	private function should_queue_crash_report( array $report, &$lock_storage = null ) {
		$fingerprint = $this->generate_crash_fingerprint( $report );

		if ( '' === $fingerprint ) {
			$lock_storage = null;
			return true;
		}

		$cache_key = 'crash_lock_' . $fingerprint;

		// Prefer object cache lock when an external cache backend is available.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$lock_storage = 'object_cache';
			$acquired     = wp_cache_add( $cache_key, 1, self::CRASH_REPORT_CACHE_GROUP, HOUR_IN_SECONDS );
			if ( $acquired ) {
				$this->log_crash_observability( '[FBW_DEDUP_DEBUG] dedup lock created: storage=object_cache fingerprint=' . $fingerprint );
			} else {
				$this->log_crash_observability( '[FBW_DEDUP_DEBUG] dedup lock blocked: storage=object_cache fingerprint=' . $fingerprint );
			}
			return $acquired;
		}

		// Fallback lock for non-persistent object cache environments.
		$lock_storage  = 'transient';
		$transient_key = 'wc_facebook_crash_lock_' . $fingerprint;
		if ( false !== $this->safe_get_transient( $transient_key ) ) {
			$this->log_crash_observability( '[FBW_DEDUP_DEBUG] dedup lock blocked: storage=transient fingerprint=' . $fingerprint );
			return false;
		}

		$this->safe_set_transient( $transient_key, 1, HOUR_IN_SECONDS );
		$this->log_crash_observability( '[FBW_DEDUP_DEBUG] dedup lock created: storage=transient fingerprint=' . $fingerprint );
		return true;
	}

	/**
	 * Releases dedup lock when report is skipped or dropped before enqueue.
	 *
	 * @since 3.6.4
	 *
	 * @param array       $report normalized crash report payload.
	 * @param string      $reason release reason for observability logs.
	 * @param string|null $lock_storage lock backend used for acquisition.
	 */
	private function release_crash_dedup_lock( array $report, $reason = 'unknown', $lock_storage = null ) {
		$fingerprint = $this->generate_crash_fingerprint( $report );
		if ( '' === $fingerprint ) {
			return;
		}

		$cache_key = 'crash_lock_' . $fingerprint;

		if ( 'object_cache' === $lock_storage ) {
			wp_cache_delete( $cache_key, self::CRASH_REPORT_CACHE_GROUP );
		} elseif ( 'transient' === $lock_storage ) {
			$this->safe_delete_transient( 'wc_facebook_crash_lock_' . $fingerprint );
		}

		$this->log_crash_observability( '[FBW_DEDUP_DEBUG] dedup lock released: reason=' . (string) $reason . ' storage=' . (string) $lock_storage . ' fingerprint=' . $fingerprint );
	}

	/**
	 * Increments local duplicate crash counters for suppressed reports.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report normalized crash report payload.
	 */
	private function increment_duplicate_crash_counter( array $report ) {
		$fingerprint = $this->generate_crash_fingerprint( $report );

		if ( '' === $fingerprint ) {
			return;
		}

		$transient_key = 'wc_facebook_crash_dup_' . $fingerprint;
		$current       = $this->safe_get_transient( $transient_key );
		$now           = time();

		$count      = is_array( $current ) && isset( $current['count'] ) ? (int) $current['count'] : 0;
		$first_seen = is_array( $current ) && isset( $current['first_seen'] ) ? (int) $current['first_seen'] : $now;

		$last_sample = [
			'event_type' => isset( $report['event_type'] ) ? (string) $report['event_type'] : '',
			'message'    => isset( $report['exception_message'] ) ? (string) $report['exception_message'] : '',
			'file'       => isset( $report['extra_data']['file'] ) ? (string) $report['extra_data']['file'] : '',
			'line'       => isset( $report['extra_data']['line'] ) ? (int) $report['extra_data']['line'] : 0,
		];

		$this->safe_set_transient(
			$transient_key,
			[
				'first_seen'  => $first_seen,
				'last_seen'   => $now,
				'count'       => $count + 1,
				'last_sample' => $last_sample,
			],
			DAY_IN_SECONDS
		);
	}

	/**
	 * Generates a crash fingerprint used for deduplication.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report normalized crash report payload.
	 * @return string
	 */
	private function generate_crash_fingerprint( array $report ) {
		$extra      = isset( $report['extra_data'] ) && is_array( $report['extra_data'] ) ? $report['extra_data'] : [];
		$stack      = isset( $extra['plugin_stack'] ) && is_array( $extra['plugin_stack'] ) ? $extra['plugin_stack'] : [];
		$top_frame  = ! empty( $stack ) && is_array( $stack[0] ) ? $stack[0] : [];
		$components = [
			isset( $report['event_type'] ) ? (string) $report['event_type'] : '',
			isset( $report['exception_message'] ) ? (string) $report['exception_message'] : '',
			isset( $extra['file'] ) ? (string) $extra['file'] : '',
			isset( $extra['line'] ) ? (string) $extra['line'] : '',
			isset( $top_frame['file'] ) ? (string) $top_frame['file'] : '',
			isset( $top_frame['line'] ) ? (string) $top_frame['line'] : '',
			isset( $extra['plugin_version'] ) ? (string) $extra['plugin_version'] : '',
		];

		$fingerprint_source = implode( '|', $components );
		if ( '' === trim( $fingerprint_source ) ) {
			return '';
		}

		return md5( $fingerprint_source );
	}

	/**
	 * Checks whether the captured error is one of the supported fatal types.
	 *
	 * @since 3.6.4
	 *
	 * @param array|null $error last PHP error.
	 * @return bool
	 */
	private function is_supported_fatal_error( $error ) {
		if ( ! is_array( $error ) || empty( $error['type'] ) ) {
			return false;
		}

		return in_array( (int) $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true );
	}

	/**
	 * Normalizes an uncaught throwable to an error-like payload.
	 *
	 * @since 3.6.4
	 *
	 * @param Throwable $throwable throwable instance.
	 * @return array
	 */
	private function normalize_throwable_to_error( Throwable $throwable ) {
		$type = $throwable instanceof \ParseError ? E_PARSE : E_ERROR;

		return $this->normalize_captured_error(
			[
				'type'            => $type,
				'message'         => $throwable->getMessage(),
				'file'            => $throwable->getFile(),
				'line'            => $throwable->getLine(),
				'exception_class' => get_class( $throwable ),
				'trace'           => $throwable->getTrace(),
			],
			'uncaught_exception'
		);
	}

	/**
	 * Normalizes captured error data into a stable internal shape.
	 *
	 * @since 3.6.4
	 *
	 * @param array|null $error captured error payload.
	 * @param string     $source source type (fatal_error or uncaught_exception).
	 * @return array|null
	 */
	private function normalize_captured_error( $error, $source ) {
		if ( ! is_array( $error ) ) {
			return null;
		}

		return [
			'type'            => isset( $error['type'] ) ? (int) $error['type'] : 0,
			'message'         => isset( $error['message'] ) ? (string) $error['message'] : '',
			'file'            => isset( $error['file'] ) ? (string) $error['file'] : '',
			'line'            => isset( $error['line'] ) ? (int) $error['line'] : 0,
			'exception_class' => isset( $error['exception_class'] ) ? (string) $error['exception_class'] : '',
			'trace'           => isset( $error['trace'] ) && is_array( $error['trace'] ) ? $error['trace'] : [],
			'source'          => 'uncaught_exception' === $source ? 'uncaught_exception' : 'fatal_error',
		];
	}

	/**
	 * Whether a fatal error reported by PHP is the uncaught throwable this handler already captured.
	 *
	 * @since 3.7.9
	 *
	 * @param array|null $error    normalized error_get_last() payload.
	 * @param array|null $captured normalized captured throwable payload.
	 * @return bool
	 */
	private function is_rethrown_captured_throwable( $error, $captured ) {
		if ( ! is_array( $error ) || ! is_array( $captured ) ) {
			return false;
		}

		return 0 === strpos( $error['message'], 'Uncaught ' )
			&& $error['file'] === $captured['file']
			&& $error['line'] === $captured['line'];
	}

	/**
	 * Reduces an error message to its first line, capped in length, for log lines and notices.
	 *
	 * @since 3.7.9
	 *
	 * @param string $message sanitized message.
	 * @return string
	 */
	private function summarize_message( $message ) {
		$first_line = trim( (string) strtok( (string) $message, "\r\n" ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $first_line, 0, 300 );
		}

		return substr( $first_line, 0, 300 );
	}

	/**
	 * Checks whether a fatal error originated from this plugin path.
	 *
	 * @since 3.6.4
	 *
	 * @param array $error last PHP error.
	 * @return bool
	 */
	private function is_plugin_error( array $error ) {
		if ( empty( $error['file'] ) || ! is_string( $error['file'] ) ) {
			return false;
		}

		if ( ! defined( 'WC_FACEBOOK_PLUGIN_PATH' ) || ! is_string( WC_FACEBOOK_PLUGIN_PATH ) || '' === WC_FACEBOOK_PLUGIN_PATH ) {
			return false;
		}

		$error_file  = wp_normalize_path( $error['file'] );
		$plugin_path = trailingslashit( wp_normalize_path( WC_FACEBOOK_PLUGIN_PATH ) );

		return 0 === strpos( $error_file, $plugin_path );
	}

	/**
	 * Tells the crash guard that this request ran the plugin and finished without a plugin fatal.
	 *
	 * @since 3.7.9
	 */
	private function record_healthy_request() {
		if ( ! self::$plugin_initialized ) {
			return;
		}

		try {
			CrashGuardState::record_healthy_request( CrashGuardState::get_request_class(), $this->get_current_time() );
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Records a plugin crash with the crash guard, remembers a sanitized summary for the admin
	 * notice and writes one log line with the decision taken.
	 *
	 * @since 3.7.9
	 *
	 * @param array $error normalized error payload.
	 * @return array|null crash guard result, or null when the state could not be updated.
	 */
	private function record_plugin_crash( array $error ) {
		$result        = null;
		$self_check    = null;
		$request_class = CrashGuardState::get_request_class();
		$now           = $this->get_current_time();

		try {
			if ( CrashGuardState::would_pause( $request_class, $now ) ) {
				$self_check = $this->run_self_check();

				if ( self::SELF_CHECK_CLEAN === $self_check['outcome'] ) {
					// A plain request ran the plugin cleanly, so this crash depends on something the
					// client sent. The self-check is the healthy request the store did not have.
					CrashGuardState::record_healthy_request( $request_class, $now );
				}
			}

			$result = CrashGuardState::record_crash( $request_class, $now );
		} catch ( Throwable $e ) {
			unset( $e );
		}

		$this->remember_last_crash( $error );
		$this->log_crash_decision( $error, $result, $self_check );

		return $result;
	}

	/**
	 * Runs the self-check: a plain loopback request that carries nothing from the client.
	 *
	 * Only storefront crashes are checked. A storefront request cannot vouch for wp-admin, cron or
	 * REST, so a backend crash is not checked at all and pauses on its streak alone; a clean
	 * storefront result must never reset a backend streak.
	 *
	 * The target is the path of the crashing request without its query string (the home page
	 * for a storefront request that was not a GET). The request carries a one-time token; the
	 * request it spawns recognizes the token, runs the plugin as any visitor would, and records
	 * clean, crashed or inconclusive at its own shutdown. The verdict comes from that record,
	 * never from the HTTP status, so a page cache answering 200 counts as inconclusive.
	 *
	 * @since 3.7.9
	 *
	 * @return array {
	 *     @type string $outcome one of the SELF_CHECK_* constants.
	 *     @type string $target  path that was requested.
	 *     @type string $detail  transport detail for the log line.
	 * }
	 */
	private function run_self_check() {
		if ( CrashGuardState::REQUEST_CLASS_FRONTEND !== CrashGuardState::get_request_class() ) {
			return $this->self_check_result( self::SELF_CHECK_SKIPPED, '', 'backend request; a storefront check would not establish backend health' );
		}

		if ( ! function_exists( 'wp_safe_remote_get' ) || ! function_exists( 'home_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return $this->self_check_result( self::SELF_CHECK_SKIPPED, '', 'HTTP API unavailable' );
		}

		$token  = $this->generate_self_check_token();
		$target = $this->get_self_check_target();

		if ( ! $this->safe_set_transient( self::SELF_CHECK_PENDING_PREFIX . $token, 1, MINUTE_IN_SECONDS ) ) {
			return $this->self_check_result( self::SELF_CHECK_SKIPPED, $target, 'could not register the token' );
		}

		$response = $this->perform_self_check_request( add_query_arg( self::SELF_CHECK_QUERY_ARG, $token, home_url( $target ) ) );
		$outcome  = $this->read_self_check_outcome( $token );

		$this->safe_delete_transient( self::SELF_CHECK_PENDING_PREFIX . $token );
		$this->safe_delete_transient( self::SELF_CHECK_OUTCOME_PREFIX . $token );

		$detail = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $outcome, [ self::SELF_CHECK_CLEAN, self::SELF_CHECK_CRASHED, self::SELF_CHECK_INCONCLUSIVE ], true ) ) {
			return $this->self_check_result( $outcome, $target, $detail );
		}

		return $this->self_check_result( self::SELF_CHECK_INCONCLUSIVE, $target, 'no verdict recorded, ' . $detail );
	}

	/**
	 * Performs the self-check HTTP request. Overridable so tests can stand in for the loopback.
	 *
	 * @since 3.7.9
	 *
	 * @param string $url self-check URL.
	 * @return array|\WP_Error
	 */
	protected function perform_self_check_request( $url ) {
		return wp_safe_remote_get(
			$url,
			[
				'timeout'     => self::SELF_CHECK_TIMEOUT,
				'redirection' => 2,
				'cookies'     => [],
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'user-agent'  => 'Meta for WooCommerce crash guard self-check',
				'headers'     => [
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				],
			]
		);
	}

	/**
	 * Reads the verdict the self-check request stored, retrying briefly for hosts that end the
	 * HTTP response before shutdown functions run.
	 *
	 * @since 3.7.9
	 *
	 * @param string $token self-check token.
	 * @return string|null
	 */
	private function read_self_check_outcome( $token ) {
		$key = self::SELF_CHECK_OUTCOME_PREFIX . $token;

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			if ( $attempt > 0 ) {
				usleep( 150000 );
			}

			$outcome = 0 === $attempt ? $this->safe_get_transient( $key ) : $this->reread_transient( $key );
			if ( is_string( $outcome ) && '' !== $outcome ) {
				return $outcome;
			}
		}

		return null;
	}

	/**
	 * Reads a transient again, getting past this request's memory of an earlier miss without
	 * deleting anything another request may have stored in the meantime.
	 *
	 * With a persistent object cache the cache entry is the transient itself, so it is read with
	 * a forced refresh and never deleted. Without one, transients live in the options table and
	 * only this request's in-memory memo of the miss is forgotten before reading again.
	 *
	 * @since 3.7.9
	 *
	 * @param string $key transient key.
	 * @return mixed transient value, or false.
	 */
	private function reread_transient( $key ) {
		try {
			if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				return wp_cache_get( $key, 'transient', true );
			}

			if ( function_exists( 'wp_cache_get' ) ) {
				$option_keys = [ '_transient_' . $key, '_transient_timeout_' . $key ];
				$notoptions  = wp_cache_get( 'notoptions', 'options' );

				if ( is_array( $notoptions ) ) {
					foreach ( $option_keys as $option_key ) {
						unset( $notoptions[ $option_key ] );
					}
					wp_cache_set( 'notoptions', $notoptions, 'options' );
				}

				foreach ( $option_keys as $option_key ) {
					wp_cache_delete( $option_key, 'options' );
				}
			}

			return get_transient( $key );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Records the verdict of this self-check request for the request that spawned it.
	 *
	 * @since 3.7.9
	 *
	 * @param string $outcome one of the SELF_CHECK_* constants.
	 */
	private function record_self_check_outcome( $outcome ) {
		$this->safe_set_transient( self::SELF_CHECK_OUTCOME_PREFIX . self::$self_check_token, $outcome, MINUTE_IN_SECONDS );
	}

	/**
	 * Chooses what the self-check requests: the crashing request's path, stripped of everything
	 * the client sent, or the home page for requests that were not a GET.
	 *
	 * @since 3.7.9
	 *
	 * @return string path relative to the home URL, starting with a slash.
	 */
	private function get_self_check_target() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
			return '/';
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '..' ) || strlen( $path ) > 2000 ) {
			return '/';
		}

		// home_url() prepends the site's own path, so strip it when the site lives in a subdirectory.
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		return '' === $path ? '/' : $path;
	}

	/**
	 * Generates an unguessable self-check token.
	 *
	 * @since 3.7.9
	 *
	 * @return string
	 */
	private function generate_self_check_token() {
		try {
			return bin2hex( random_bytes( 12 ) );
		} catch ( Throwable $e ) {
			unset( $e );
			return md5( uniqid( 'wc_facebook_crash_probe', true ) );
		}
	}

	/**
	 * Builds a self-check result array.
	 *
	 * @since 3.7.9
	 *
	 * @param string $outcome one of the SELF_CHECK_* constants.
	 * @param string $target  requested path.
	 * @param string $detail  transport detail.
	 * @return array
	 */
	private function self_check_result( $outcome, $target, $detail ) {
		return [
			'outcome' => $outcome,
			'target'  => (string) $target,
			'detail'  => (string) $detail,
		];
	}

	/**
	 * Describes a self-check result for the log line.
	 *
	 * @since 3.7.9
	 *
	 * @param array|null $self_check result of run_self_check(), or null when none ran.
	 * @return string
	 */
	private function describe_self_check( $self_check ) {
		if ( ! is_array( $self_check ) ) {
			return '';
		}

		$target = '' !== $self_check['target'] ? $self_check['target'] : '/';

		switch ( $self_check['outcome'] ) {
			case self::SELF_CHECK_CLEAN:
				return sprintf( ' Self-check: a plain request to %s ran cleanly, so this crash needs input the client sent; streak reset.', $target );
			case self::SELF_CHECK_CRASHED:
				return sprintf( ' Self-check: a plain request to %s crashed too.', $target );
			case self::SELF_CHECK_SKIPPED:
				return sprintf( ' Self-check skipped (%s); deciding on the streak alone.', $self_check['detail'] );
			default:
				return sprintf( ' Self-check of %s inconclusive (%s); pausing as a precaution.', $target, $self_check['detail'] );
		}
	}

	/**
	 * Stores a sanitized summary of the crash for the plugins-page notice.
	 *
	 * The summary lives in a transient rather than in the flag file because the uploads
	 * directory may be served publicly, and error messages can carry paths or PII.
	 *
	 * @since 3.7.9
	 *
	 * @param array $error normalized error payload.
	 */
	private function remember_last_crash( array $error ) {
		$this->safe_set_transient(
			self::LAST_CRASH_TRANSIENT,
			[
				'message' => $this->summarize_message( $this->sanitize_message( isset( $error['message'] ) ? (string) $error['message'] : '' ) ),
				'file'    => $this->sanitize_file_path( isset( $error['file'] ) ? (string) $error['file'] : '' ),
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
				'class'   => isset( $error['exception_class'] ) ? (string) $error['exception_class'] : '',
				'time'    => $this->get_current_time(),
			],
			WEEK_IN_SECONDS
		);
	}

	/**
	 * Writes one error_log line describing the crash and what the crash guard decided.
	 *
	 * The uncaught-exception path used to leave no trace in the PHP log at all, which made a
	 * "disabled via crash flag" message impossible to diagnose from logs alone.
	 *
	 * @since 3.7.9
	 *
	 * @param array      $error      normalized error payload.
	 * @param array|null $result     crash guard result.
	 * @param array|null $self_check self-check result, when one ran.
	 */
	private function log_crash_decision( array $error, $result, $self_check = null ) {
		$class    = isset( $error['exception_class'] ) && '' !== $error['exception_class'] ? $error['exception_class'] : $this->get_php_error_type_label( isset( $error['type'] ) ? (int) $error['type'] : 0 );
		$decision = is_array( $result ) ? CrashGuardState::describe_decision( $result ) : 'Crash guard state could not be updated.';

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'Meta for WooCommerce crash guard: %s "%s" in %s:%d during %s request.%s %s',
				$class,
				$this->summarize_message( $this->sanitize_message( isset( $error['message'] ) ? (string) $error['message'] : '' ) ),
				$this->sanitize_file_path( isset( $error['file'] ) ? (string) $error['file'] : '' ),
				isset( $error['line'] ) ? (int) $error['line'] : 0,
				$this->get_request_context(),
				$this->describe_self_check( $self_check ),
				$decision
			)
		);
	}

	/**
	 * Returns the current Unix timestamp. Overridable so tests can drive the crash guard clock.
	 *
	 * @since 3.7.9
	 *
	 * @return int
	 */
	protected function get_current_time() {
		return time();
	}

	/**
	 * Reads a transient during crash shutdown handling without letting storage errors escape.
	 *
	 * @since 3.6.4
	 *
	 * @param string $key transient key.
	 * @return mixed
	 */
	private function safe_get_transient( $key ) {
		try {
			return get_transient( $key );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Writes a transient during crash shutdown handling without letting storage errors escape.
	 *
	 * @since 3.6.4
	 *
	 * @param string $key transient key.
	 * @param mixed  $value transient value.
	 * @param int    $expiration expiration in seconds.
	 * @return bool
	 */
	private function safe_set_transient( $key, $value, $expiration ) {
		try {
			return (bool) set_transient( $key, $value, $expiration );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Deletes a transient during crash shutdown handling without letting storage errors escape.
	 *
	 * @since 3.6.4
	 *
	 * @param string $key transient key.
	 * @return bool
	 */
	private function safe_delete_transient( $key ) {
		try {
			return (bool) delete_transient( $key );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Queues a crash report for asynchronous processing.
	 *
	 * Uses Action Scheduler only when available.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report normalized crash report payload.
	 * @return bool
	 */
	private function queue_crash_report( array $report ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return ErrorLogHandler::enqueue_meta_log_request( $report, true );
		}

		$delay = function_exists( 'wp_rand' )
			? wp_rand( 60, self::CRASH_REPORT_MAX_JITTER_SECONDS )
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback for the early crash-handler path (shutdown function) where the pluggable wp_rand() may not be loaded yet.
			: mt_rand( 60, self::CRASH_REPORT_MAX_JITTER_SECONDS );

		try {
			$action_id = as_schedule_single_action( time() + $delay, ErrorLogHandler::META_LOG_API, [ $report ], ErrorLogHandler::META_LOG_API_GROUP, true );
			return ! empty( $action_id );
		} catch ( Throwable $e ) {
			unset( $e );
			return false;
		}
	}

	/**
	 * Normalizes and sanitizes crash payload for stable reporting.
	 *
	 * @since 3.6.4
	 *
	 * @param array $error captured PHP fatal error data.
	 * @return array
	 */
	private function normalize_crash_report_payload( array $error ) {
		$message     = isset( $error['message'] ) ? (string) $error['message'] : '';
		$event_type  = ( isset( $error['source'] ) && 'uncaught_exception' === $error['source'] ) ? 'uncaught_exception' : 'fatal_error';
		$error_class = isset( $error['exception_class'] ) ? (string) $error['exception_class'] : '';

		$payload = [
			'event'             => 'plugin_crash',
			'event_type'        => $event_type,
			'exception_message' => $this->sanitize_message( $message ),
			'extra_data'        => [
				'error_class'     => $error_class,
				'php_error_type'  => $this->get_php_error_type_label( isset( $error['type'] ) ? (int) $error['type'] : 0 ),
				'error_type'      => isset( $error['type'] ) ? (int) $error['type'] : 0,
				'file'            => $this->sanitize_file_path( isset( $error['file'] ) ? (string) $error['file'] : '' ),
				'line'            => isset( $error['line'] ) ? (int) $error['line'] : 0,
				'plugin_stack'    => $this->extract_plugin_stack_frames( isset( $error['trace'] ) && is_array( $error['trace'] ) ? $error['trace'] : [] ),
				'plugin_version'  => $this->get_plugin_version(),
				'php_version'     => PHP_VERSION,
				'wp_version'      => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
				'wc_version'      => defined( 'WC_VERSION' ) ? (string) WC_VERSION : '',
				'request_context' => $this->get_request_context(),
			],
		];

		return $payload;
	}

	/**
	 * Gets plugin version in a shutdown-safe way.
	 *
	 * @since 3.6.4
	 *
	 * @return string
	 */
	private function get_plugin_version() {
		if ( class_exists( '\\WC_Facebook_Loader' ) && defined( '\\WC_Facebook_Loader::PLUGIN_VERSION' ) ) {
			return (string) constant( '\\WC_Facebook_Loader::PLUGIN_VERSION' );
		}

		return '';
	}

	/**
	 * Gets the request context for crash reporting.
	 *
	 * @since 3.6.4
	 *
	 * @return string
	 */
	private function get_request_context() {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return 'cron';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'rest';
		}

		if ( is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}

	/**
	 * Extracts up to five plugin stack frames.
	 *
	 * @since 3.6.4
	 *
	 * @param array $trace throwable trace.
	 * @return array
	 */
	private function extract_plugin_stack_frames( array $trace ) {
		$frames = [];

		foreach ( $trace as $frame ) {
			if ( empty( $frame['file'] ) || ! is_string( $frame['file'] ) ) {
				continue;
			}

			$sanitized_file = $this->sanitize_file_path( $frame['file'] );
			if ( '' === $sanitized_file || 0 !== strpos( $sanitized_file, 'plugin:' ) ) {
				continue;
			}

			$frames[] = [
				'file' => $sanitized_file,
				'line' => isset( $frame['line'] ) ? (int) $frame['line'] : 0,
			];

			if ( count( $frames ) >= 5 ) {
				break;
			}
		}

		return $frames;
	}

	/**
	 * Sanitizes a message sample and truncates to 500 characters.
	 *
	 * @since 3.6.4
	 *
	 * @param string $message message text.
	 * @return string
	 */
	private function sanitize_message( $message ) {
		try {
			$sanitized = $this->sanitize_sensitive_values_with_parser( (string) $message );
			$sanitized = $this->sanitize_paths_with_parser( $sanitized );
		} catch ( Throwable $e ) {
			$sanitized = $this->sanitize_sensitive_values( (string) $message );
			$sanitized = preg_replace( '#(?:[A-Za-z]:)?(?:/|\\\\)[^\s"\']+#', '[path]', $sanitized );
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $sanitized, 0, 500 );
		}

		return substr( (string) $sanitized, 0, 500 );
	}

	/**
	 * Sanitizes file paths.
	 *
	 * @since 3.6.4
	 *
	 * @param string $path absolute file path.
	 * @return string
	 */
	private function sanitize_file_path( $path ) {
		if ( '' === $path ) {
			return '';
		}

		$normalized_path = wp_normalize_path( $path );
		$plugin_path     = defined( 'WC_FACEBOOK_PLUGIN_PATH' ) ? trailingslashit( wp_normalize_path( WC_FACEBOOK_PLUGIN_PATH ) ) : '';

		if ( '' !== $plugin_path && 0 === strpos( $normalized_path, $plugin_path ) ) {
			return 'plugin:' . ltrim( substr( $normalized_path, strlen( $plugin_path ) ), '/' );
		}

		return basename( $normalized_path );
	}

	/**
	 * Redacts token/key-like values from text.
	 *
	 * @since 3.6.4
	 *
	 * @param string $text input text.
	 * @return string
	 */
	private function sanitize_sensitive_values( $text ) {
		$patterns = [
			// Common secret-like key/value pairs.
			'/(token|access_token|auth|authorization|secret|api[_-]?key|password|cookie|set-cookie|request_body|body|headers?)\s*[:=]\s*("[^"]*"|\'[^\']*\'|\{[^\}]*\}|\[[^\]]*\]|[^\r\n]+)/i',
			// Explicit request-like header/body lines.
			'/(authorization|cookie|set-cookie|request_body|body|headers?)\s*:\s*[^\r\n]*/i',
			// Authorization bearer values.
			'/\bBearer\s+(?:Bearer\s+)?[A-Za-z0-9\-._~+\/]+=*/i',
			// JWT-like strings.
			'/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9._-]+\.[A-Za-z0-9._-]+\b/',
			// Redact long token-like strings (mixed letters+digits) and long hex strings.
			'/\b(?=[A-Za-z0-9_\-]{24,}\b)(?=[A-Za-z0-9_\-]*[A-Za-z])(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]+\b/',
			'/\b[a-f0-9]{32,}\b/i',
			// Basic PII redaction.
			'/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
			'/(?<!\w)\+?\d[\d\s().-]{7,}\d(?!\w)/',
		];

		$replacements = [
			'$1=[redacted]',
			'$1: [redacted]',
			'Bearer [redacted_token]',
			'[redacted_token]',
			'[redacted_token]',
			'[redacted_token]',
			'[redacted_email]',
			'[redacted_phone]',
		];

		return preg_replace( $patterns, $replacements, (string) $text );
	}

	/**
	 * Parser-first sanitizer for sensitive values in log messages.
	 *
	 * @since 3.6.4
	 *
	 * @param string $text input text.
	 * @return string
	 */
	private function sanitize_sensitive_values_with_parser( $text ) {
		$lines = preg_split( "/\\r\\n|\\n|\\r/", (string) $text );
		if ( ! is_array( $lines ) ) {
			return (string) $text;
		}

		$sensitive_keys = [
			'token',
			'access_token',
			'auth',
			'authorization',
			'secret',
			'apikey',
			'api_key',
			'password',
			'cookie',
			'setcookie',
			'set-cookie',
			'requestbody',
			'request_body',
			'body',
			'headers',
			'header',
			'phone',
			'telephone',
			'mobile',
		];

		foreach ( $lines as $index => $line ) {
			$line = (string) $line;
			$eq   = strpos( $line, '=' );
			$col  = strpos( $line, ':' );

			$pos = false;
			$sep = '';
			if ( false !== $eq && false !== $col ) {
				$pos = min( $eq, $col );
				$sep = $eq < $col ? '=' : ':';
			} elseif ( false !== $eq ) {
				$pos = $eq;
				$sep = '=';
			} elseif ( false !== $col ) {
				$pos = $col;
				$sep = ':';
			}

			if ( false !== $pos ) {
				$key      = trim( substr( $line, 0, $pos ) );
				$key_norm = strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $key ) );
				if ( in_array( $key_norm, $sensitive_keys, true ) ) {
					$lines[ $index ] = '=' === $sep ? $key . '=[redacted]' : $key . ': [redacted]';
					continue;
				}
			}

			$tokens = preg_split( '/(\s+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE );
			if ( ! is_array( $tokens ) ) {
				continue;
			}

			$tokens_count = count( $tokens );
			for ( $i = 0; $i < $tokens_count; $i++ ) {
				$token = (string) $tokens[ $i ];
				if ( '' === trim( $token ) ) {
					continue;
				}

				if ( 0 === strcasecmp( $token, 'Bearer' ) ) {
					$tokens[ $i ] = 'Bearer';
					for ( $j = $i + 1; $j < $tokens_count; $j++ ) {
						if ( '' === trim( (string) $tokens[ $j ] ) ) {
							continue;
						}
						$tokens[ $j ] = '[redacted_token]';
						break;
					}
					continue;
				}

				$tokens[ $i ] = $this->redact_sensitive_token( $token );
			}

			$lines[ $index ] = implode( '', $tokens );
		}

		$sanitized = implode( "\n", $lines );
		return preg_replace( '/(?<!\w)\+?\d[\d\s().-]{7,}\d(?!\w)/', '[redacted_phone]', $sanitized );
	}

	/**
	 * Parser-based absolute path masking for message text.
	 *
	 * @since 3.6.4
	 *
	 * @param string $text input text.
	 * @return string
	 */
	private function sanitize_paths_with_parser( $text ) {
		$tokens = preg_split( '/(\s+)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $tokens ) ) {
			return (string) $text;
		}

		foreach ( $tokens as $i => $token ) {
			$token = (string) $token;
			if ( '' === trim( $token ) ) {
				continue;
			}

			$parts = $this->split_token_wrappers( $token );
			$core  = $parts['core'];
			if ( '' === $core ) {
				continue;
			}

			if ( $this->looks_like_absolute_path( $core ) ) {
				$tokens[ $i ] = $parts['prefix'] . '[path]' . $parts['suffix'];
			}
		}

		return implode( '', $tokens );
	}

	/**
	 * Redacts sensitive token/PII-like samples while preserving simple wrappers.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return string
	 */
	private function redact_sensitive_token( $token ) {
		$parts = $this->split_token_wrappers( (string) $token );
		$core  = $parts['core'];
		if ( '' === $core ) {
			return (string) $token;
		}

		$replacement = '';
		if ( $this->looks_like_jwt( $core ) || $this->looks_like_long_hex( $core ) || $this->looks_like_long_token( $core ) ) {
			$replacement = '[redacted_token]';
		} elseif ( false !== strpos( $core, '@' ) && false !== strpos( substr( $core, strpos( $core, '@' ) ), '.' ) ) {
			$replacement = '[redacted_email]';
		} elseif ( $this->looks_like_phone( $core ) ) {
			$replacement = '[redacted_phone]';
		}

		if ( '' === $replacement ) {
			return (string) $token;
		}

		return $parts['prefix'] . $replacement . $parts['suffix'];
	}

	/**
	 * Splits a token into prefix/core/suffix wrappers.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return array
	 */
	private function split_token_wrappers( $token ) {
		$prefix_chars = "\"'([{<";
		$suffix_chars = "\"')]}>.,;:";

		$start = 0;
		$end   = strlen( (string) $token ) - 1;

		while ( $start <= $end && false !== strpos( $prefix_chars, $token[ $start ] ) ) {
			++$start;
		}

		while ( $end >= $start && false !== strpos( $suffix_chars, $token[ $end ] ) ) {
			--$end;
		}

		if ( $end < $start ) {
			return [
				'prefix' => (string) $token,
				'core'   => '',
				'suffix' => '',
			];
		}

		return [
			'prefix' => substr( $token, 0, $start ),
			'core'   => substr( $token, $start, $end - $start + 1 ),
			'suffix' => substr( $token, $end + 1 ),
		];
	}

	/**
	 * Checks whether token resembles an absolute Unix or Windows path.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return bool
	 */
	private function looks_like_absolute_path( $token ) {
		if ( '' === $token ) {
			return false;
		}

		if ( '/' === $token[0] ) {
			return true;
		}

		return strlen( $token ) > 2
			&& ctype_alpha( $token[0] )
			&& ':' === $token[1]
			&& ( '\\' === $token[2] || '/' === $token[2] );
	}

	/**
	 * Checks whether token resembles a JWT.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return bool
	 */
	private function looks_like_jwt( $token ) {
		if ( 0 !== strpos( $token, 'eyJ' ) ) {
			return false;
		}

		$parts = explode( '.', $token );
		return count( $parts ) >= 3;
	}

	/**
	 * Checks whether token resembles a long mixed token.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return bool
	 */
	private function looks_like_long_token( $token ) {
		$token_length = strlen( $token );
		if ( $token_length < 24 ) {
			return false;
		}

		$has_alpha = false;
		$has_digit = false;
		for ( $i = 0; $i < $token_length; $i++ ) {
			$char = $token[ $i ];
			if ( ctype_alpha( $char ) ) {
				$has_alpha = true;
			}
			if ( ctype_digit( $char ) ) {
				$has_digit = true;
			}
			if ( ! ctype_alnum( $char ) && '_' !== $char && '-' !== $char ) {
				return false;
			}
		}

		return $has_alpha && $has_digit;
	}

	/**
	 * Checks whether token resembles a long hex blob.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return bool
	 */
	private function looks_like_long_hex( $token ) {
		return strlen( $token ) >= 32 && ctype_xdigit( $token );
	}

	/**
	 * Checks whether token resembles a phone number.
	 *
	 * @since 3.6.4
	 *
	 * @param string $token token text.
	 * @return bool
	 */
	private function looks_like_phone( $token ) {
		$digits = preg_replace( '/\D/', '', $token );
		if ( ! is_string( $digits ) || strlen( $digits ) < 9 ) {
			return false;
		}

		$token_length = strlen( $token );
		for ( $i = 0; $i < $token_length; $i++ ) {
			$char = $token[ $i ];
			if ( ctype_digit( $char ) ) {
				continue;
			}
			if ( false === strpos( '+-(). ', $char ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Gets human-readable PHP error type label.
	 *
	 * @since 3.6.4
	 *
	 * @param int $type PHP error type.
	 * @return string
	 */
	private function get_php_error_type_label( $type ) {
		$map = [
			E_ERROR         => 'E_ERROR',
			E_PARSE         => 'E_PARSE',
			E_CORE_ERROR    => 'E_CORE_ERROR',
			E_COMPILE_ERROR => 'E_COMPILE_ERROR',
		];

		return isset( $map[ $type ] ) ? $map[ $type ] : 'UNKNOWN';
	}

	/**
	 * Logs crash report data when queueing fails.
	 *
	 * @since 3.6.4
	 *
	 * @param array $report normalized crash payload.
	 */
	private function log_fallback( array $report ) {
		error_log( 'Meta for WooCommerce crash capture fallback: ' . wp_json_encode( $report ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
