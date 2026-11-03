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
 * Abstract class AbstractFeedFileWriter
 *
 * Provides the base functionality for handling Metadata feed file writing for Facebook integration feed uploads.
 *
 * @package WooCommerce\Facebook\Feed
 * @since 3.5.0
 */
abstract class AbstractFeedFileWriter {

	/** Feed file directory inside the uploads folder  @var string */
	const UPLOADS_DIRECTORY = 'facebook_for_woocommerce/%s';

	/**
	 * Use the feed name to distinguish which folder to write to.
	 *
	 * @var string
	 * @since 3.5.0
	 */
	protected string $feed_name;

	/**
	 * Header row for the feed file.
	 *
	 * @var string
	 * @since 3.5.0
	 */
	protected string $header_row;

	/**
	 * CSV delimiter.
	 *
	 * @var string
	 * @since 3.5.0
	 */
	protected string $delimiter;

	/**
	 * CSV enclosure.
	 *
	 * @var string
	 * @since 3.5.0
	 */
	protected string $enclosure;

	/**
	 * CSV escape character.
	 *
	 * @var string
	 * @since 3.5.0
	 */
	protected string $escape_char;

	/**
	 * Constructor.
	 *
	 * @param string $feed_name The name of the feed.
	 * @param string $header_row The headers for the feed csv.
	 * @param string $delimiter Optional. The field delimiter. Default: comma.
	 * @param string $enclosure Optional. The field enclosure. Default: double quotes.
	 * @param string $escape_char Optional. The escape character. Default: backslash.
	 *
	 * @since 3.5.0
	 */
	public function __construct( string $feed_name, string $header_row, string $delimiter = ',', string $enclosure = '"', string $escape_char = '\\' ) {
		$this->feed_name   = $feed_name;
		$this->header_row  = $header_row;
		$this->delimiter   = $delimiter;
		$this->enclosure   = $enclosure;
		$this->escape_char = $escape_char;
	}

	/**
	 * Write the feed file.
	 *
	 * @param array $data The data to write to the feed file.
	 *
	 * @return void
	 * @since 3.5.0
	 */
	public function write_feed_file( array $data ): void {
		try {
			$this->create_feed_directory();
			$this->create_files_to_protect_feed_directory();

			// Step 1: Prepare the temporary empty feed file with header row.
			$temp_feed_file = $this->prepare_temporary_feed_file();

			// Step 2: Write feed into the temporary feed file.
			$this->write_temp_feed_file( $data );

			// Step 3: Rename temporary feed file to final feed file.
			$this->promote_temp_file();
		} catch ( \Exception $exception ) {
			Logger::log(
				'Error while writing feed file.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'write_feed_file',
					'extra_data' => [
						'feed_name' => $this->feed_name,
					],
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => false,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				),
				$exception,
			);
			// Close the temporary file if it is still open.
			if ( ! empty( $temp_feed_file ) && is_resource( $temp_feed_file ) ) {
				fclose( $temp_feed_file ); // phpcs:ignore
			}

			// Delete the temporary file if it exists.
			if ( ! empty( $temp_file_path ) && file_exists( $temp_file_path ) ) {
				unlink( $temp_file_path ); // phpcs:ignore
			}
		}
	}

	/**
	 * Generates the feed file.
	 *
	 * @throws PluginException If the directory could not be created.
	 * @throws \Exception Caught exception is rethrown.
	 * @since 3.5.0
	 */
	public function create_feed_directory(): void {
		$file_directory = $this->get_file_directory();
		try {
			$directory_created = wp_mkdir_p( $file_directory );
			if ( ! $directory_created ) {
				throw new PluginException( "Could not create feed directory at {$file_directory}", 500 );
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Error while creating feed directory.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'create_feed_directory',
					'extra_data' => [
						'feed_name'      => $this->feed_name,
						'file_directory' => $file_directory,
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
		}
	}

	/**
	 * Creates files in the feed directory to prevent directory listing and hotlinking.
	 *
	 * @throws \Exception Caught exception is rethrown.
	 * @since 3.5.0
	 */
	public function create_files_to_protect_feed_directory(): void {
		$feed_directory = trailingslashit( $this->get_file_directory() );
		try {
			$files = array(
				array(
					'base'    => $feed_directory,
					'file'    => 'index.html',
					'content' => '',
				),
				array(
					'base'    => $feed_directory,
					'file'    => '.htaccess',
					'content' => 'deny from all',
				),
			);

			foreach ( $files as $file ) {
				$file_path = trailingslashit( $file['base'] ) . $file['file'];
				if ( wp_mkdir_p( $file['base'] ) && ! file_exists( $file_path ) ) {
					// phpcs:ignore -- use php file i/o functions
					$file_handle = fopen( $file_path, 'w' );
					if ( $file_handle ) {
						try {
							fwrite( $file_handle, $file['content'] ); //phpcs:ignore
						} finally {
							fclose( $file_handle ); //phpcs:ignore
						}
					}
				}
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Error while creating files to protect feed directory.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'create_files_to_protect_feed_directory',
					'extra_data' => [
						'feed_name'      => $this->feed_name,
						'feed_directory' => $feed_directory,
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
		}
	}

	/**
	 * Gets the header row's column names.
	 *
	 * The header row is always a comma-separated string, whatever delimiter the feed file uses
	 * (ShippingProfilesFeed writes tab-separated files from a comma-separated header).
	 *
	 * @return array
	 */
	protected function get_header_columns(): array {
		return str_getcsv( $this->header_row, ',', $this->enclosure, $this->escape_char );
	}

	/**
	 * Gets the feed file path of given feed.
	 *
	 * @return string
	 * @since 3.5.0
	 */
	public function get_file_path(): string {
		return "{$this->get_file_directory()}/{$this->get_file_name()}";
	}


	/**
	 * Gets the temporary feed file path.
	 *
	 * @return string
	 * @since 3.5.0
	 */
	public function get_temp_file_path(): string {
		return "{$this->get_file_directory()}/{$this->get_temp_file_name()}";
	}

	/**
	 * Gets the feed file directory.
	 *
	 * @return string
	 * @since 3.5.0
	 */
	public function get_file_directory(): string {
		$uploads_directory = wp_upload_dir( null, false );

		return trailingslashit( $uploads_directory['basedir'] ) . sprintf( self::UPLOADS_DIRECTORY, $this->feed_name );
	}


	/**
	 * Gets the feed file name.
	 *
	 * @return string
	 * @since 3.5.0
	 */
	public function get_file_name(): string {
		$feed_secret = facebook_for_woocommerce()->feed_manager->get_feed_secret( $this->feed_name );

		return sprintf( static::FILE_NAME, $this->feed_name, $feed_secret );
	}

	/**
	 * Gets the temporary feed file name.
	 *
	 * @return string
	 * @since 3.5.0
	 */
	public function get_temp_file_name(): string {
		$feed_secret = facebook_for_woocommerce()->feed_manager->get_feed_secret( $this->feed_name );

		return sprintf( static::FILE_NAME, $this->feed_name, 'temp_' . wp_hash( $feed_secret ) );
	}

	/**
	 * Prepare a fresh empty temporary feed file with the header row.
	 *
	 * @throws PluginException We can't open the file or the file is not writable.
	 * @throws \Exception Caught exception is rethrown.
	 * @return resource A file pointer resource.
	 * @since 3.5.0
	 */
	public function prepare_temporary_feed_file() {
		$temp_file_path = $this->get_temp_file_path();
		$temp_feed_file = false;
		$file_path      = $this->get_file_path();

		try {
			// phpcs:ignore -- use php file i/o functions
			$temp_feed_file = fopen( $temp_file_path, 'w' );

			// Check if we can open the temporary feed file.
			// phpcs:ignore
			if ( false === $temp_feed_file || ! is_writable( $temp_file_path ) ) {
				throw new PluginException( "Could not open file {$temp_file_path} for writing.", 500 );
			}

			// Check if we will be able to write to the final feed file.
			// phpcs:ignore -- use php file i/o functions
			if ( file_exists( $file_path ) && ! is_writable( $file_path ) ) {
				throw new PluginException( "Could not open file {$file_path} for writing.", 500 );
			}

			if ( ! empty( $this->header_row ) ) {
				if ( fputcsv( $temp_feed_file, $this->get_header_columns(), $this->delimiter, $this->enclosure, $this->escape_char ) === false ) {
					throw new PluginException( "Failed to write header row to {$temp_file_path}.", 500 );
				}
			}

			return $temp_feed_file;
		} catch ( \Exception $exception ) {
			Logger::log(
				'Error while creating temporary feed file.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'prepare_temporary_feed_file',
					'extra_data' => [
						'feed_name'      => $this->feed_name,
						'temp_file_path' => $temp_file_path,
						'file_path'      => $file_path,
					],
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => false,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				),
				$exception,
			);
			if ( $temp_feed_file ) {
				// phpcs:ignore -- use php file i/o functions
				fclose( $temp_feed_file );
			}
			throw $exception;
		}
	}

	/**
	 * Rename temporary feed file into the final feed file.
	 * This is the last step fo the feed generation procedure.
	 *
	 * @throws PluginException If the temporary feed file could not be renamed.
	 * @throws \Exception Caught exception is rethrown.
	 * @since 3.5.0
	 */
	public function promote_temp_file(): void {
		$temp_file_path = $this->get_temp_file_path();
		$file_path      = $this->get_file_path();

		try {
			if ( ! empty( $temp_file_path ) && ! empty( $file_path ) ) {
				// phpcs:ignore -- use php file i/o functions
				$renamed = rename( $temp_file_path, $file_path );
				if ( empty( $renamed ) ) {
					throw new PluginException( "Could not promote temp file: {$temp_file_path}", 500 );
				}
			}
		} catch ( \Exception $exception ) {
			Logger::log(
				'Error while promoting temporary file.',
				array(
					'event'      => 'feed_upload',
					'event_type' => 'promote_temp_file',
					'extra_data' => [
						'feed_name'      => $this->feed_name,
						'temp_file_path' => $temp_file_path,
						'file_path'      => $file_path,
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
		}
	}

	/**
	 * Write to the temp feed file.
	 *
	 * @param array $data The data to write to the feed file.
	 * @since 3.5.0
	 */
	abstract public function write_temp_feed_file( array $data );
}
