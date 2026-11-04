<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

namespace WooCommerce\Facebook\Feed;

defined( 'ABSPATH' ) || exit;

use Error;
use Exception;
use WC_Facebookcommerce_Utils;
use WooCommerce\Facebook\API\Exceptions\Request_Limit_Reached;
use WooCommerce\Facebook\API\Response;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Products\Feed;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WooCommerce\Facebook\Framework\Logger;

/**
 * A class responsible detecting feed configuration.
 */
class FeedConfigurationDetection {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( Heartbeat::DAILY, array( $this, 'track_data_source_feed_tracker_info' ) );
	}

	/**
	 * Store config settings for feed-based sync for WooCommerce Tracker.
	 *
	 * Gets various settings related to the feed, and data about recent uploads.
	 * This is formatted into an array of keys/values, and saved to a transient for inclusion in tracker snapshot.
	 * Note this does not send the data to tracker - this happens later (see Tracker class).
	 *
	 * @since 2.6.0
	 * @return void
	 */
	public function track_data_source_feed_tracker_info() {
		$flag_name = '_wc_facebook_for_woocommerce_track_data_source_feed_tracker_info';
		if ( 'yes' === get_transient( $flag_name ) ) {
			return;
		}
		set_transient( $flag_name, 'yes', DAY_IN_SECONDS );
		try {
			$info = $this->get_data_source_feed_tracker_info();
			facebook_for_woocommerce()->get_tracker()->track_facebook_feed_config( $info );
		} catch ( \Error $error ) {
			facebook_for_woocommerce()->log( 'Unable to detect valid feed configuration: ' . $error->getMessage() );
		}
	}

	/**
	 * Get config settings for feed-based sync for WooCommerce Tracker.
	 *
	 * @throws Error Catalog id missing.
	 * @return array Key-value array of various configuration settings.
	 */
	private function get_data_source_feed_tracker_info() {
		$integration         = facebook_for_woocommerce()->get_integration();
		$integration_feed_id = $integration->get_feed_id();
		$catalog_id          = $integration->get_product_catalog_id();

		$info                 = array();
		$info['site-feed-id'] = $integration_feed_id;

		// No catalog id. Most probably means that we don't have a valid connection.
		if ( '' === $catalog_id ) {
			throw new Error( 'No catalog ID' );
		}

		// Get all feeds configured for the catalog.
		$feed_nodes = $this->get_feed_nodes_for_catalog( $catalog_id );

		$info['feed-count'] = count( $feed_nodes );

		// Check if the catalog has any feed configured.
		if ( empty( $feed_nodes ) ) {
			throw new Error( 'No feed nodes for catalog' );
		}

		/*
		 * We will only track settings for one feed config (for now at least).
		 * So we need to determine which is the most relevant feed.
		 * If there is only one, we use that.
		 * If one has the same ID as $integration_feed_id, we use that.
		 * Otherwise we pick the one that was most recently updated.
		 */
		$active_feed_metadata = array();
		foreach ( $feed_nodes as $feed ) {
			try {
				$metadata = $this->get_feed_metadata( $feed['id'] );
			} catch ( Exception $e ) {
				$message = sprintf( 'There was an error trying to get feed metadata: %s', $e->getMessage() );
				Logger::log(
					$message,
					[],
					array(
						'should_send_log_to_meta'        => false,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
					)
				);
				continue;
			}

			if ( $feed['id'] === $integration_feed_id ) {
				$active_feed_metadata = clone $metadata;
				break;
			}

			if (
				! isset( $metadata['latest_upload'] ) ||
				! is_array( $metadata['latest_upload'] ) ||
				! array_key_exists( 'start_time', $metadata['latest_upload'] )
			) {
				continue;
			}

			$metadata['latest_upload_time'] = strtotime( $metadata['latest_upload']['start_time'] );
			if (
				! $active_feed_metadata ||
				$metadata['latest_upload_time'] > $active_feed_metadata['latest_upload_time']
			) {
				$active_feed_metadata = clone $metadata;
			}
		}

		if ( empty( $active_feed_metadata ) ) {
			// No active feed available, we don't have data to collect.
			$info['active-feed'] = null;
			return $info;
		}

		$active_feed = array();
		if ( isset( $active_feed_metadata['created_time'] ) ) {
			$active_feed['created-time'] = gmdate( 'Y-m-d H:i:s', strtotime( $active_feed_metadata['created_time'] ) );
		}

		if ( isset( $active_feed_metadata['product_count'] ) ) {
			$active_feed['product-count'] = $active_feed_metadata['product_count'];
		}

		/*
		 * Upload schedule settings can be in two keys:
		 * `schedule` => full replace of catalog with items in feed (including delete).
		 * `update_schedule` => append any new or updated products to catalog.
		 * These may both be configured; we will track settings for each individually (i.e. both).
		 * https://developers.facebook.com/docs/marketing-api/reference/product-feed/
		 */
		if ( isset( $active_feed_metadata['schedule'] ) ) {
			$active_feed['schedule']['interval']       = $active_feed_metadata['schedule']['interval'];
			$active_feed['schedule']['interval-count'] = $active_feed_metadata['schedule']['interval_count'];
		}
		if ( isset( $active_feed_metadata['update_schedule'] ) ) {
			$active_feed['update-schedule']['interval']       = $active_feed_metadata['update_schedule']['interval'];
			$active_feed['update-schedule']['interval-count'] = $active_feed_metadata['update_schedule']['interval_count'];
		}

		$info['active-feed'] = $active_feed;

		if ( isset( $active_feed_metadata['latest_upload'] ) ) {
			$latest_upload = $active_feed_metadata['latest_upload'];
			$upload        = array();

			if ( array_key_exists( 'end_time', $latest_upload ) ) {
				$upload['end-time'] = gmdate( 'Y-m-d H:i:s', strtotime( $latest_upload['end_time'] ) );
			}

			// Get more detailed metadata about the most recent feed upload.
			$upload_metadata = $this->get_feed_upload_metadata( $latest_upload['id'] );

			// If no metadata is available, we can't track any more details.
			if ( ! $upload_metadata ) {
				$info['active-feed']['latest-upload'] = $upload;
				return $info;
			}

			// The Graph API omits fields it has no value for yet, e.g. while an upload is still processing.
			$upload['error-count']         = $upload_metadata['error_count'] ?? 0;
			$upload['warning-count']       = $upload_metadata['warning_count'] ?? 0;
			$upload['num-detected-items']  = $upload_metadata['num_detected_items'] ?? 0;
			$upload['num-persisted-items'] = $upload_metadata['num_persisted_items'] ?? 0;

			// True if the feed upload url (Facebook side) matches the feed endpoint URL and secret.
			// If it doesn't match, it's likely it's unused.
			$upload['url-matches-site-endpoint'] = wc_bool_to_string(
				Feed::get_feed_data_url() === ( $upload_metadata['url'] ?? '' )
			);

			$info['active-feed']['latest-upload'] = $upload;
		}

		return $info;
	}

	/**
	 * Given catalog id this function fetches all feed configurations defined for this catalog.
	 *
	 * @param string $product_catalog_id Facebook Catalog ID.
	 * @return array Array of feed configurations.
	 * @throws Request_Limit_Reached When API request limit is reached.
	 * @throws ApiException When there is an error in the API request.
	 * @throws Error Feed configurations fetch was not successful.
	 */
	private function get_feed_nodes_for_catalog( string $product_catalog_id ) {
		try {
			$response = facebook_for_woocommerce()->get_api()->read_feeds( $product_catalog_id );
		} catch ( \Exception $e ) {
			$message = sprintf( 'There was an error trying to get feed nodes for catalog: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );
			return array();
		}
		return $response->data;
	}

	/**
	 * Given feed id fetch this feed configuration metadata.
	 *
	 * @param string $feed_id Facebook Product Feed ID.
	 *
	 * @return Response
	 * @throws Request_Limit_Reached When API request limit is reached.
	 * @throws ApiException When there is an error in the API request.
	 */
	private function get_feed_metadata( string $feed_id ) {
		return facebook_for_woocommerce()->get_api()->read_feed( $feed_id );
	}

	/**
	 * Given upload id fetch this upload execution metadata.
	 *
	 * @param string $upload_id Facebook Feed upload ID.
	 *
	 * @return Response
	 * @throws Error Upload metadata fetch was not successful.
	 */
	private function get_feed_upload_metadata( $upload_id ) {
		try {
			$response = facebook_for_woocommerce()->get_api()->read_upload( $upload_id );
		} catch ( \Exception $e ) {
			$message = sprintf( 'There was an error trying to get feed upload metadata: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );
			return false;
		}
		return $response;
	}
}
