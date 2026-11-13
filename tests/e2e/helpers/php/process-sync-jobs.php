<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * E2E Helper — Process pending Facebook sync background jobs directly.
 *
 * The background job handler dispatches via a loopback HTTP request to
 * admin-ajax.php, which fails on single-threaded PHP servers (like the
 * built-in dev server used in CI). This script bypasses the loopback by
 * invoking the job handler directly.
 *
 * Usage: php process-sync-jobs.php
 */

// Simulate cron context so is_queue_empty() doesn't bail early.
define( 'DOING_CRON', true );

$wp_path = getenv( 'WORDPRESS_PATH' ) . '/wp-load.php';

if ( ! file_exists( $wp_path ) ) {
	echo json_encode( [ 'success' => false, 'error' => 'WordPress not found at: ' . $wp_path ] );
	exit( 1 );
}

require_once $wp_path;

/**
 * Waits until Meta has finished processing all submitted Catalog Batch API handles.
 *
 * @param string[] $handles Batch handles returned by /items_batch.
 * @return array<int, array<string, mixed>> Sanitized final batch statuses.
 * @throws Exception When a batch fails, contains invalid requests, or times out.
 */
function wait_for_meta_batch_completion( array $handles ) {
	$handles = array_values( array_unique( array_filter( $handles ) ) );
	if ( empty( $handles ) ) {
		return [];
	}

	$integration = facebook_for_woocommerce()->get_integration();
	$catalog_id  = $integration ? $integration->get_product_catalog_id() : '';
	$api          = facebook_for_woocommerce()->get_api();
	$access_token = $api ? $api->get_access_token() : '';

	if ( empty( $catalog_id ) || empty( $access_token ) ) {
		throw new Exception( 'Facebook catalog connection is not configured for batch status checks' );
	}

	$pending_handles = array_fill_keys( $handles, true );
	$final_statuses  = [];
	$deadline        = microtime( true ) + 300;

	while ( ! empty( $pending_handles ) ) {
		foreach ( array_keys( $pending_handles ) as $handle ) {
			$url = add_query_arg(
				[
					'handle'                       => $handle,
					'fields'                       => 'status,errors_total_count,warnings_total_count,ids_of_invalid_requests',
					'load_ids_of_invalid_requests' => 'true',
				],
				\WooCommerce\Facebook\API::GRAPH_API_URL . \WooCommerce\Facebook\API::API_VERSION . "/{$catalog_id}/check_batch_request_status"
			);

			$response = wp_remote_get(
				$url,
				[
					'headers' => [ 'Authorization' => "Bearer {$access_token}" ],
					'timeout' => 30,
				]
			);

			if ( is_wp_error( $response ) ) {
				throw new Exception( 'Meta batch status request failed: ' . $response->get_error_message() );
			}

			$http_code = wp_remote_retrieve_response_code( $response );
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $http_code ) {
				$message = $body['error']['message'] ?? "HTTP {$http_code}";
				throw new Exception( 'Meta batch status request failed: ' . $message );
			}

			$data          = $body['data'][0] ?? $body;
			$status        = strtolower( (string) ( $data['status'] ?? '' ) );
			$error_count   = (int) ( $data['errors_total_count'] ?? 0 );
			$warning_count = (int) ( $data['warnings_total_count'] ?? 0 );
			$invalid_count = count( (array) ( $data['ids_of_invalid_requests'] ?? [] ) );

			if ( in_array( $status, [ 'failed', 'error' ], true ) ) {
				throw new Exception( "Meta catalog batch ended with status {$status}" );
			}

			if ( 'finished' === $status ) {
				if ( $error_count > 0 || $invalid_count > 0 ) {
					throw new Exception( "Meta catalog batch finished with {$error_count} error(s) and {$invalid_count} invalid request(s)" );
				}

				$final_statuses[] = [
					'status'                => $status,
					'errors_total_count'    => $error_count,
					'warnings_total_count'  => $warning_count,
					'invalid_request_count' => $invalid_count,
				];
				unset( $pending_handles[ $handle ] );
			}
		}

		if ( empty( $pending_handles ) ) {
			break;
		}

		if ( microtime( true ) >= $deadline ) {
			throw new Exception( 'Timed out waiting for Meta to finish catalog batches' );
		}

		sleep( 5 );
	}

	return $final_statuses;
}

/**
 * Gets the latest Catalog Batch API handle for every requested sync item.
 *
 * The async request can finish before this CLI helper starts, so get_job() no
 * longer returns it. Completed jobs remain persisted and retain their handles.
 *
 * @param \WooCommerce\Facebook\Products\Sync\Background $handler Product sync job handler.
 * @param string[] $request_ids Product IDs for UPDATE or retailer IDs for DELETE.
 * @param string[] $actions Sync actions to match.
 * @return array{handles: string[], missing_request_ids: string[]}
 */
function get_completed_request_batch_handles( $handler, array $request_ids, array $actions ) {
	if ( empty( $request_ids ) ) {
		return [
			'handles'             => [],
			'missing_request_ids' => [],
		];
	}

	$pending_request_keys = [];
	foreach ( $request_ids as $request_id ) {
		$pending_request_keys[ \WooCommerce\Facebook\Products\Sync::PRODUCT_INDEX_PREFIX . $request_id ] = $request_id;
	}

	$handles             = [];
	$missing_request_ids = [];
	$jobs                = $handler->get_jobs(
		[
			'status' => 'completed',
			'order'  => 'DESC',
		]
	) ?: [];

	foreach ( $jobs as $job ) {
		$requests = isset( $job->requests ) && is_array( $job->requests ) ? $job->requests : [];
		$matches  = [];

		foreach ( $pending_request_keys as $request_key => $request_id ) {
			if ( in_array( $requests[ $request_key ] ?? null, $actions, true ) ) {
				$matches[ $request_key ] = $request_id;
			}
		}

		if ( empty( $matches ) ) {
			continue;
		}

		if ( ! empty( $job->handles ) && is_array( $job->handles ) ) {
			$handles = array_merge( $handles, $job->handles );
		} else {
			$missing_request_ids = array_merge( $missing_request_ids, array_values( $matches ) );
		}

		foreach ( array_keys( $matches ) as $matched_request_key ) {
			unset( $pending_request_keys[ $matched_request_key ] );
		}

		if ( empty( $pending_request_keys ) ) {
			break;
		}
	}

	return [
		'handles'             => array_values( array_unique( array_filter( $handles ) ) ),
		'missing_request_ids' => array_values(
			array_unique(
				array_merge( $missing_request_ids, array_values( $pending_request_keys ) )
			)
		),
	];
}

/**
 * Runs due async product-save events before draining the catalog sync queue.
 *
 * Product edits first schedule wc_facebook_async_sync, which in turn creates
 * the catalog background job. WP-Cron is not guaranteed to run between E2E
 * browser actions and this CLI helper, so process the due events directly.
 *
 * @return int Number of async product-save events processed.
 */
function process_due_product_sync_events() {
	$hook             = 'wc_facebook_async_sync';
	$events_processed = 0;
	$deadline         = microtime( true ) + 5;

	do {
		$cron             = _get_cron_array();
		$due_events       = [];
		$next_run         = null;
		$current_timestamp = time();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( empty( $hooks[ $hook ] ) ) {
				continue;
			}

			if ( (int) $timestamp > $current_timestamp ) {
				$next_run = null === $next_run ? (int) $timestamp : min( $next_run, (int) $timestamp );
				continue;
			}

			foreach ( $hooks[ $hook ] as $event ) {
				$due_events[] = [
					'timestamp' => (int) $timestamp,
					'args'      => (array) ( $event['args'] ?? [] ),
				];
			}
		}

		foreach ( $due_events as $event ) {
			wp_unschedule_event( $event['timestamp'], $hook, $event['args'] );
			do_action_ref_array( $hook, $event['args'] );
			++$events_processed;
		}

		if ( ! empty( $due_events ) ) {
			continue;
		}

		if ( null === $next_run || $next_run > time() + 5 || microtime( true ) >= $deadline ) {
			break;
		}

		usleep( 250000 );
	} while ( microtime( true ) < $deadline );

	if ( $events_processed > 0 ) {
		$sync_handler = facebook_for_woocommerce()->get_products_sync_handler();
		remove_action( 'shutdown', [ $sync_handler, 'schedule_sync' ] );
		$sync_handler->schedule_sync();
	}

	return $events_processed;
}

/**
 * Processes every sync job currently visible to the CLI request.
 *
 * @param \WooCommerce\Facebook\Products\Sync\Background $handler Product sync job handler.
 * @return array{jobs_processed: int, handles: string[]}
 */
function process_available_sync_jobs( $handler ) {
	$jobs_processed = 0;
	$batch_handles  = [];

	while ( true ) {
		$job = $handler->get_job();
		if ( ! $job ) {
			break;
		}

		$processed_job = $handler->process_job( $job );
		if ( ! empty( $processed_job->handles ) && is_array( $processed_job->handles ) ) {
			$batch_handles = array_merge( $batch_handles, $processed_job->handles );
		}
		++$jobs_processed;
	}

	return [
		'jobs_processed' => $jobs_processed,
		'handles'        => array_values( array_unique( $batch_handles ) ),
	];
}

try {
	if ( ! function_exists( 'facebook_for_woocommerce' ) ) {
		throw new Exception( 'Facebook plugin not loaded' );
	}

	$handler        = facebook_for_woocommerce()->get_products_sync_background_handler();
	$jobs_processed = 0;
	$batch_handles  = [];
	$sync_events_processed = process_due_product_sync_events();
	$request_ids    = array_values(
		array_unique(
			array_filter(
				array_map( 'trim', explode( ',', (string) getenv( 'FB_E2E_SYNC_REQUEST_IDS' ) ) ),
				function ( $request_id ) {
					return '' !== $request_id;
				}
			)
		)
	);
	$actions        = array_values(
		array_intersect(
			[
				\WooCommerce\Facebook\Products\Sync::ACTION_UPDATE,
				\WooCommerce\Facebook\Products\Sync::ACTION_DELETE,
			],
			array_filter( array_map( 'strtoupper', explode( ',', (string) getenv( 'FB_E2E_SYNC_ACTIONS' ) ) ) )
		)
	);
	if ( empty( $actions ) ) {
		$actions = [ \WooCommerce\Facebook\Products\Sync::ACTION_UPDATE ];
	}

	$processing_result = process_available_sync_jobs( $handler );
	$jobs_processed    += $processing_result['jobs_processed'];
	$batch_handles      = array_merge( $batch_handles, $processing_result['handles'] );

	$batch_lookup = ! empty( $request_ids )
		? get_completed_request_batch_handles( $handler, $request_ids, $actions )
		: [
			'handles'             => array_values( array_unique( $batch_handles ) ),
			'missing_request_ids' => [],
		];

	// The normal async handler can claim a job between the queue drain and the
	// completed-job lookup. Give it a short bounded window to persist its handle,
	// while continuing to process any jobs that become available to this request.
	if ( '1' === getenv( 'FB_E2E_WAIT_FOR_BATCH_COMPLETION' ) && ! empty( $request_ids ) ) {
		$job_lookup_deadline = microtime( true ) + 30;

		while ( ! empty( $batch_lookup['missing_request_ids'] ) && microtime( true ) < $job_lookup_deadline ) {
			usleep( 500000 );
			$processing_result = process_available_sync_jobs( $handler );
			$jobs_processed    += $processing_result['jobs_processed'];
			$batch_lookup       = get_completed_request_batch_handles( $handler, $request_ids, $actions );
		}
	}

	$batch_handles       = $batch_lookup['handles'];
	$missing_request_ids = $batch_lookup['missing_request_ids'];

	if ( '1' === getenv( 'FB_E2E_WAIT_FOR_BATCH_COMPLETION' ) && ! empty( $missing_request_ids ) ) {
		throw new Exception( 'No completed Catalog Batch API handles found for request IDs: ' . implode( ', ', $missing_request_ids ) );
	}

	$batch_statuses = '1' === getenv( 'FB_E2E_WAIT_FOR_BATCH_COMPLETION' )
		? wait_for_meta_batch_completion( $batch_handles )
		: [];

	echo json_encode( [
		'success'               => true,
		'sync_events_processed' => $sync_events_processed,
		'jobs_processed'        => $jobs_processed,
		'batch_count'           => count( $batch_handles ),
		'batch_statuses'        => $batch_statuses,
		'request_ids'           => $request_ids,
		'actions'               => $actions,
		'message'               => $jobs_processed > 0
			? "Processed {$jobs_processed} job(s)"
			: 'No pending jobs found',
	] );

} catch ( Exception $e ) {
	echo json_encode( [
		'success' => false,
		'error'   => $e->getMessage(),
	] );
	exit( 1 );
}
