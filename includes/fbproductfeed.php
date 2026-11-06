<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Products\Feed;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Logger;


/**
 * Initial Sync by Facebook feed class
 */
class WC_Facebook_Product_Feed {


	/** @var string product catalog feed file directory inside the uploads folder */
	const UPLOADS_DIRECTORY              = 'facebook_for_woocommerce';
	const FILE_NAME                      = 'product_catalog_%s.csv';
	const FACEBOOK_CATALOG_FEED_FILENAME = 'fae_product_catalog.csv';
	const FB_ADDITIONAL_IMAGES_FOR_FEED  = 5;
	const FB_PRODUCT_GROUP_ID            = 'fb_product_group_id';
	const FB_VISIBILITY                  = 'fb_visibility';

	/** @var int has default product count */
	private $has_default_product_count = 0;
	/** @var int no default product count */
	private $no_default_product_count = 0;

	/**
	 * Generates the product catalog feed.
	 *
	 * This replaces any previously generated feed file.
	 *
	 * @since 1.11.0
	 */
	public function generate_feed() {
		$profiling_logger = facebook_for_woocommerce()->get_profiling_logger();
		$profiling_logger->start( 'generate_feed' );

		Logger::log(
			'Generating a fresh product feed file',
			[],
			array(
				'should_send_log_to_meta'        => false,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
			)
		);

		try {

			$start_time = microtime( true );

			$this->generate_productfeed_file();

			$generation_time = microtime( true ) - $start_time;
			facebook_for_woocommerce()->get_tracker()->track_feed_file_generation_time( $generation_time );

			Logger::log(
				'Product feed file generated',
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
				)
			);

			do_action( 'wc_facebook_feed_generation_completed' );

		} catch ( \Exception $exception ) {
			Logger::log(
				$exception->getMessage(),
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
			// Feed generation failed - clear the generation time to track that there's an issue.
			facebook_for_woocommerce()->get_tracker()->track_feed_file_generation_time( -1 );

		}

		$profiling_logger->stop( 'generate_feed' );
	}

	/**
	 * Gets the product catalog feed file path.
	 *
	 * @since 1.11.0
	 *
	 * @return string
	 */
	public function get_file_path() {

		/**
		 * Filters the product catalog feed file path.
		 *
		 * @since 1.11.0
		 *
		 * @param string $file_path the file path
		 */
		return apply_filters( 'wc_facebook_product_catalog_feed_file_path', "{$this->get_file_directory()}/{$this->get_file_name()}" );
	}


	/**
	 * Gets the product catalog temporary feed file path.
	 *
	 * @since 1.11.3
	 *
	 * @return string
	 */
	public function get_temp_file_path() {

		/**
		 * Filters the product catalog temporary feed file path.
		 *
		 * @since 1.11.3
		 *
		 * @param string $file_path the temporary file path
		 */
		return apply_filters( 'wc_facebook_product_catalog_temp_feed_file_path', "{$this->get_file_directory()}/{$this->get_temp_file_name()}" );
	}


	/**
	 * Gets the product catalog feed file directory.
	 *
	 * @since 1.11.0
	 *
	 * @return string
	 */
	public function get_file_directory() {

		$uploads_directory = wp_upload_dir( null, false );

		return trailingslashit( $uploads_directory['basedir'] ) . self::UPLOADS_DIRECTORY;
	}


	/**
	 * Gets the product catalog feed file name.
	 *
	 * @since 1.11.0
	 *
	 * @return string
	 */
	public function get_file_name() {

		$file_name = sprintf( self::FILE_NAME, wp_hash( Feed::get_feed_secret() ) );

		/**
		 * Filters the product catalog feed file name.
		 *
		 * @since 1.11.0
		 *
		 * @param string $file_name the file name
		 */
		return apply_filters( 'wc_facebook_product_catalog_feed_file_name', $file_name );
	}


	/**
	 * Gets the product catalog temporary feed file name.
	 *
	 * @since 1.11.3
	 *
	 * @return string
	 */
	public function get_temp_file_name() {

		$file_name = sprintf( self::FILE_NAME, 'temp_' . wp_hash( Feed::get_feed_secret() ) );

		/**
		 * Filters the product catalog temporary feed file name.
		 *
		 * @since 1.11.3
		 *
		 * @param string $file_name the temporary file name
		 */
		return apply_filters( 'wc_facebook_product_catalog_temp_feed_file_name', $file_name );
	}


	/**
	 * Gets the product IDs that will be included in the feed file.
	 *
	 * @since 1.11.0
	 *
	 * @return int[]
	 */
	private function get_product_ids() {
		return \WC_Facebookcommerce_Utils::get_all_product_ids_for_sync();
	}


	/**
	 * Generates the product catalog feed file.
	 *
	 * @return bool
	 * @throws PluginException If the feed file directory can't be created or the feed file can't be written.
	 */
	public function generate_productfeed_file() {

		if ( ! wp_mkdir_p( $this->get_file_directory() ) ) {
			throw new PluginException( esc_html__( 'Could not create product catalog feed directory', 'facebook-for-woocommerce' ), 500 );
		}

		$this->create_files_to_protect_product_feed_directory();

		return $this->write_product_feed_file( $this->get_product_ids() );
	}


	/**
	 * Creates files in the catalog feed directory to prevent directory listing and hotlinking.
	 * Will create directory if not already available
	 *
	 * @since 1.11.0
	 */
	public function create_files_to_protect_product_feed_directory() {

		$catalog_feed_directory = trailingslashit( $this->get_file_directory() );

		$files = array(
			array(
				'base'    => $catalog_feed_directory,
				'file'    => 'index.html',
				'content' => '',
			),
			array(
				'base'    => $catalog_feed_directory,
				'file'    => '.htaccess',
				'content' => 'deny from all',
			),
		);

		foreach ( $files as $file ) {
			if ( wp_mkdir_p( $file['base'] ) && ! file_exists( trailingslashit( $file['base'] ) . $file['file'] ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$file_handle = @fopen( trailingslashit( $file['base'] ) . $file['file'], 'w' );
				if ( $file_handle ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fwrite( $file_handle, $file['content'] );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $file_handle );
				}
			}
		}
	}


	/**
	 * Writes the product catalog feed file with data for the given product IDs.
	 *
	 * @since 1.11.0
	 *
	 * @param int[] $wp_ids product IDs
	 * @return bool
	 */
	public function write_product_feed_file( $wp_ids ) {

		try {

			// Step 1: Prepare the temporary empty feed file with header row.
			$temp_feed_file = $this->prepare_temporary_feed_file();

			// Step 2: Write products feed into the temporary feed file.
			$this->write_products_feed_to_temp_file( $wp_ids, $temp_feed_file );

			// Step 3: Rename temporary feed file to final feed file.
			$this->rename_temporary_feed_file_to_final_feed_file();

			$written = true;

		} catch ( Exception $e ) {

			Logger::log(
				wp_json_encode( $e->getMessage() ),
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);

			$written = false;

			// close the temporary file
			if ( ! empty( $temp_feed_file ) && is_resource( $temp_feed_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $temp_feed_file );
			}

			// delete the temporary file
			if ( ! empty( $temp_file_path ) && file_exists( $temp_file_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $temp_file_path );
			}
		}

		return $written;
	}

	/**
	 * Prepare a fresh empty temporary feed file with the header row.
	 *
	 * @since 2.6.6
	 *
	 * @throws PluginException We can't open the file or the file is not writable.
	 * @return resource A file pointer resource.
	 */
	public function prepare_temporary_feed_file() {
		$temp_file_path = $this->get_temp_file_path();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$temp_feed_file = @fopen( $temp_file_path, 'w' );

		// check if we can open the temporary feed file
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( false === $temp_feed_file || ! is_writable( $temp_file_path ) ) {
			throw new PluginException( esc_html__( 'Could not open the product catalog temporary feed file for writing', 'facebook-for-woocommerce' ), 500 );
		}

		$file_path = $this->get_file_path();

		// check if we will be able to write to the final feed file
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( file_exists( $file_path ) && ! is_writable( $file_path ) ) {
			throw new PluginException( esc_html__( 'Could not open the product catalog feed file for writing', 'facebook-for-woocommerce' ), 500 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $temp_feed_file, $this->get_product_feed_header_row() );
		return $temp_feed_file;
	}

	/**
	 * Write products feed into a file.
	 *
	 * @since 2.6.6
	 *
	 * @param array    $wp_ids
	 * @param resource $temp_feed_file
	 * @return void
	 */
	public function write_products_feed_to_temp_file( $wp_ids, $temp_feed_file ) {
		$product_group_attribute_variants = array();

		foreach ( $wp_ids as $wp_id ) {

			$product           = wc_get_product( $wp_id );
			$fb_product_parent = null;
			if ( $product instanceof WC_Product && $product->get_parent_id() ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
				if ( $parent_product instanceof WC_Product ) {
					$fb_product_parent = new WC_Facebook_Product( $parent_product );
				}
			}

			$woo_product = new WC_Facebook_Product( $wp_id, $fb_product_parent );

			// Skip if we don't have a valid product object.
			if ( ! $woo_product->woo_product instanceof \WC_Product ) {
				continue;
			}

			// Skip if not enabled for sync.
			if ( ! facebook_for_woocommerce()->get_product_sync_validator( $woo_product->woo_product )->passes_all_checks() ) {
				continue;
			}

			$product_data_as_feed_row = $this->prepare_product_for_feed(
				$woo_product,
				$product_group_attribute_variants
			);

			if ( ! empty( $temp_feed_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fwrite( $temp_feed_file, $product_data_as_feed_row );
			}
		}

		wp_reset_postdata();

		if ( ! empty( $temp_feed_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $temp_feed_file );
		}
	}

	/**
	 * Rename temporary feed file into the final feed file.
	 * This is the last step fo the feed generation procedure.
	 *
	 * @since 2.6.6
	 *
	 * @return void
	 * @throws PluginException If we can't rename the temporary feed file.
	 */
	public function rename_temporary_feed_file_to_final_feed_file() {
		$file_path      = $this->get_file_path();
		$temp_file_path = $this->get_temp_file_path();
		if ( ! empty( $temp_file_path ) && ! empty( $file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$renamed = rename( $temp_file_path, $file_path );

			if ( empty( $renamed ) ) {
				throw new PluginException( esc_html__( 'Could not rename the product catalog feed file', 'facebook-for-woocommerce' ), 500 );
			}
		}
	}

	public function get_product_feed_header_row() {
		return 'id,title,description,image_link,link,product_type,' .
		'brand,price,availability,item_group_id,checkout_url,' .
		'additional_image_link,video,sale_price_effective_date,sale_price,condition,' .
		'visibility,gender,color,size,pattern,google_product_category,default_product,' .
		'variant,gtin,quantity_to_sell_on_facebook,custom_label_4,rich_text_description,internal_label,' .
		'external_variant_id, is_woo_all_products_sync' . PHP_EOL;
	}


	/**
	 * Assembles product payload in feed upload for initial sync.
	 *
	 * @param \WC_Facebook_Product $woo_product WooCommerce product object normalized by Facebook
	 * @param array                $attribute_variants passed by reference
	 * @return string product feed line data
	 */
	private function prepare_product_for_feed( $woo_product, &$attribute_variants ) {
		$product_data  = $woo_product->prepare_product( null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_FEED );
		$item_group_id = $product_data['retailer_id'];

		// prepare variant column for variable products
		$product_data['variant'] = '';

		if ( $woo_product->is_type( 'variation' ) ) {

			$parent_id = $woo_product->get_parent_id();

			if ( ! isset( $attribute_variants[ $parent_id ] ) ) {

				$parent_product          = new \WC_Facebook_Product( $parent_id );
				$gallery_urls            = array_filter( $parent_product->get_gallery_urls() );
				$variation_id            = $parent_product->find_matching_product_variation();
				$variants_for_group      = $parent_product->prepare_variants_for_group( true );
				$parent_attribute_values = array(
					'gallery_urls'       => $gallery_urls,
					'default_variant_id' => $variation_id,
					'item_group_id'      => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $parent_product ),
				);

				foreach ( $variants_for_group as $variant ) {
					if ( isset( $variant['product_field'], $variant['options'] ) ) {
						$parent_attribute_values[ $variant['product_field'] ] = $variant['options'];
					}
				}

				// cache product group variants
				$attribute_variants[ $parent_id ] = $parent_attribute_values;

			} else {

				$parent_attribute_values = $attribute_variants[ $parent_id ];
			}

			$variants_for_item   = $woo_product->prepare_variants_for_item( $product_data );
			$variant_feed_column = array();

			foreach ( $variants_for_item as $variant_array ) {

				static::format_variant_for_feed(
					$variant_array['product_field'],
					$variant_array['options'][0],
					$parent_attribute_values,
					$variant_feed_column
				);
			}

			if ( isset( $product_data['custom_data'] ) && is_array( $product_data['custom_data'] ) ) {

				foreach ( $product_data['custom_data'] as $product_field => $value ) {

					static::format_variant_for_feed(
						$product_field,
						$value,
						$parent_attribute_values,
						$variant_feed_column
					);
				}
			}

			if ( ! empty( $variant_feed_column ) ) {
				$product_data['variant'] = '"' . implode( ',', $variant_feed_column ) . '"';
			}

			if ( isset( $parent_attribute_values['gallery_urls'] ) ) {
				$product_data['additional_image_urls'] = array_merge( $product_data['additional_image_urls'], $parent_attribute_values['gallery_urls'] );
			}

			if ( isset( $parent_attribute_values['item_group_id'] ) ) {
				$item_group_id = $parent_attribute_values['item_group_id'];
			}

			// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			$product_data['default_product'] = $parent_attribute_values['default_variant_id'] == $woo_product->id ? 'default' : '';

			// If this group has default variant value, log this product item
			if ( isset( $parent_attribute_values['default_variant_id'] ) && ! empty( $parent_attribute_values['default_variant_id'] ) ) {
				++$this->has_default_product_count;
			} else {
				++$this->no_default_product_count;
			}
		}

		// log simple product
		if ( ! isset( $product_data['default_product'] ) ) {

			++$this->no_default_product_count;

			$product_data['default_product'] = '';
		}

		// Sale price, only format if we have a sale price set for the product, else leave as empty ('').
		$sale_price                = static::get_value_from_product_data( $product_data, 'sale_price', '' );
		$sale_price_effective_date = '';
		if ( is_numeric( $sale_price ) && $sale_price > 0 ) {
			$sale_price_effective_date = static::get_value_from_product_data( $product_data, 'sale_price_start_date' ) . '/' . $this->get_value_from_product_data( $product_data, 'sale_price_end_date' );
			$sale_price                = static::format_price_for_feed(
				$sale_price,
				static::get_value_from_product_data( $product_data, 'currency' )
			);
		}

		// Setting up Woo All Products sync flag
		if ( ! isset( $product_data['is_woo_all_products_sync'] ) ) {
			$is_woo_all_products_sync = 0;
		} else {
			$is_woo_all_products_sync = $product_data['is_woo_all_products_sync'];
		}

		return $product_data['retailer_id'] . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'name' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'description' ) ) . ',' .
		static::get_value_from_product_data( $product_data, 'image_url' ) . ',' .
		static::get_value_from_product_data( $product_data, 'url' ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'product_type' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'brand' ) ) . ',' .
		static::format_string_for_feed(
			static::format_price_for_feed(
				static::get_value_from_product_data( $product_data, 'price', 0 ),
				static::get_value_from_product_data( $product_data, 'currency' )
			)
		) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'availability' ) ) . ',' .
		$item_group_id . ',' .
		static::get_value_from_product_data( $product_data, 'checkout_url' ) . ',' .
		static::format_additional_image_url( static::get_value_from_product_data( $product_data, 'additional_image_urls' ) ) . ',' .
		static::format_video_urls_for_feed( static::get_value_from_product_data( $product_data, 'video' ) ) . ',' .
		static::format_string_for_feed( $sale_price_effective_date ) . ',' .
		static::format_string_for_feed( $sale_price ) . ',new,' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'visibility' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'gender' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'color' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'size' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'pattern' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'google_product_category' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'default_product' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'variant' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'gtin' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'quantity_to_sell_on_facebook' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'custom_label_4' ) ) . ',' .
		static::format_string_for_feed( static::get_value_from_product_data( $product_data, 'rich_text_description' ) ) . ',' .
		static::format_internal_labels_for_feed( static::get_value_from_product_data( $product_data, 'internal_label' ) ) . ',' .
		static::get_value_from_product_data( $product_data, 'external_variant_id' ) . ',' .
		static::format_string_for_feed( $is_woo_all_products_sync ) . PHP_EOL;
	}

	private static function format_additional_image_url( $product_image_urls ) {
		// returns the top 10 additional image urls separated by a comma
		// according to feed api rules
		$product_image_urls = array_slice(
			$product_image_urls,
			0,
			self::FB_ADDITIONAL_IMAGES_FOR_FEED
		);
		if ( $product_image_urls ) {
			return '"' . implode( ',', $product_image_urls ) . '"';
		} else {
			return '';
		}
	}

	/**
	 * Formats video URLs for the product feed.
	 *
	 * @param array $video_urls Array of video URL objects with 'url' key
	 * @return string Formatted video URLs string for feed
	 */
	private static function format_video_urls_for_feed( $video_urls ) {
		if ( ! empty( $video_urls ) && is_array( $video_urls ) ) {
			// Extract URL strings from the array of objects
			$urls = array_map(
				function ( $video ) {
					return isset( $video['url'] ) ? $video['url'] : '';
				},
				$video_urls
			);
			// Filter out empty URLs
			$urls = array_filter( $urls );
			if ( ! empty( $urls ) ) {
				// Return comma-separated URLs wrapped in quotes
				return '"' . implode( ',', $urls ) . '"';
			}
		}
		return '';
	}

	private static function format_string_for_feed( $text ) {
		if ( (bool) $text ) {
			return '"' . str_replace( '"', "'", $text ) . '"';
		} else {
			return '';
		}
	}

	private static function format_internal_labels_for_feed( $internal_labels ): string {
		$quoted_internal_labels = array_map(
			function ( string $label ) {
				return sprintf( "'%s'", $label );
			},
			$internal_labels
		);
		return sprintf( '[%s]', implode( ',', $quoted_internal_labels ) );
	}

	private static function format_price_for_feed( $value, $currency ) {
		return (string) ( round( $value / (float) 100, 2 ) ) . $currency;
	}

	private static function format_variant_for_feed(
		$product_field,
		$value,
		$parent_attribute_values,
		&$variant_feed_column
	) {
		if ( ! array_key_exists( $product_field, $parent_attribute_values ) ) {
			return;
		}
		array_push(
			$variant_feed_column,
			$product_field . ':' .
			implode( '/', $parent_attribute_values[ $product_field ] ) . ':' .
			$value
		);
	}


	/**
	 * Gets the value from the product data.
	 *
	 * This method is used to avoid PHP undefined index notices.
	 *
	 * @since 2.1.0
	 *
	 * @param array  $product_data the product data retrieved from a Woo product passed by reference
	 * @param string $index the data index
	 * @param mixed  $return_if_not_set the value to be returned if product data has no index (default to '')
	 * @return mixed|string the data value or an empty string
	 */
	private static function get_value_from_product_data( &$product_data, $index, $return_if_not_set = '' ) {

		return isset( $product_data[ $index ] ) ? $product_data[ $index ] : $return_if_not_set;
	}


	/**
	 * Gets the status of the configured feed upload.
	 *
	 * The status indicator is one of 'in progress', 'complete', or 'error'.
	 *
	 * @param array $settings
	 * @return string
	 */
	public function is_upload_complete( &$settings ) {
		try {
			$upload_status = 'error';
			$upload_id     = facebook_for_woocommerce()->get_integration()->get_upload_id();
			$result        = facebook_for_woocommerce()->get_api()->read_upload( $upload_id );

			if ( is_wp_error( $result ) || ! isset( $result['body'] ) ) {
				$this->log_feed_progress( wp_json_encode( $result ) );
				return $upload_status;
			}

			if ( isset( $result->end_time ) ) {
				$settings['upload_end_time'] = $result->end_time;
				$upload_status               = 'complete';
			} elseif ( 200 === (int) wp_remote_retrieve_response_code( $result ) ) {
				$upload_status = 'in progress';
			}
		} catch ( ApiException $e ) {
			$message = sprintf( 'There was an error trying to upload the configured feed: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );
		}

		return $upload_status;
	}


	/**
	 * Log progress in local log file and FB.
	 *
	 * @param string $msg
	 * @param array  $obj
	 */
	public function log_feed_progress( $msg, $obj = array() ) {
		$msg = empty( $obj ) ? $msg : $msg . wp_json_encode( $obj );
		Logger::log(
			$msg,
			$obj,
			array(
				'should_send_log_to_meta'        => true,
				'should_save_log_in_woocommerce' => true,
				'woocommerce_log_level'          => \WC_Log_Levels::DEBUG,
			)
		);
	}
}
