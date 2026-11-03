<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Feed;

use WC_Facebookcommerce_Utils;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Framework\Logger;

defined( 'ABSPATH' ) || exit;

/**
 *
 * CsvFeedFileWriter class
 * To be used by any feed handler whose feed requires a csv file.
 *
 * @since 3.5.0
 */
class CsvFeedFileWriter extends AbstractFeedFileWriter {
	/** Feed file name @var string */
	const FILE_NAME = '%s_feed_%s.csv';

	/**
	 * Write the feed data to the temporary feed file.
	 *
	 * @param array $data The data to write to the feed file.
	 *
	 * @return void
	 * @throws PluginException If the temporary file cannot be opened or row can't be written.
	 * @throws \Exception Caught exception is rethrown.
	 * @since 3.5.0
	 */
	public function write_temp_feed_file( array $data ): void {
		$temp_file_path = $this->get_temp_file_path();
		$temp_feed_file = false;
		try {
			// phpcs:ignore -- use php file i/o functions
			$temp_feed_file = fopen( $temp_file_path, 'a' );
			if ( false === $temp_feed_file ) {
				// phpcs:ignore -- Escaping function for translated string not available in this context
				throw new PluginException( "Unable to open temporary file {$temp_file_path} for appending.", 500 );
			}

			// Convert the header row (CSV string) to an array to use as field accessors.
			$accessors = $this->get_header_columns();

			// Process and write each data row.
			foreach ( $data as $obj ) {
				$row = [];
				foreach ( $accessors as $accessor ) {
					// Map each field in the row to ensure proper string conversion
					$value = $obj[ $accessor ] ?? '';
					$row[] = $this->format_field( $value );
				}
				if ( fputcsv( $temp_feed_file, $row, $this->delimiter, $this->enclosure, $this->escape_char ) === false ) {
					throw new PluginException( 'Failed to write a CSV data row.', 500 );
				}
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Error while writing temporary feed file.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'write_temp_feed_file',
					'extra_data' => [
						'feed_name'      => $this->feed_name,
						'temp_file_path' => $temp_file_path,
						'file_type'      => 'csv',
					],
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => false,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				),
				$exception,
			);
			throw $exception;
		} finally {
			if ( $temp_feed_file ) {
				// phpcs:ignore -- use php file i/o functions
				fclose( $temp_feed_file );
			}
		}
	}

	protected function format_field( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value );
		}
		return $value;
	}
}
