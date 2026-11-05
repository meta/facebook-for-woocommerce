<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Feed\Localization;

defined( 'ABSPATH' ) || exit;

use Exception;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WooCommerce\Facebook\Feed\AbstractFeed;
use WooCommerce\Facebook\Integrations\IntegrationRegistry;

/**
 * Language Override Feed handler.
 *
 * Specialized functionality for language override feeds.
 *
 * @since 3.6.0
 */
class LanguageOverrideFeed {

	use LanguageFeedManagementTrait;

	/** @var string the feed name for creating a new feed by this plugin */
	const FEED_NAME_TEMPLATE = 'WooCommerce Language Override Feed (%s)';

	/** @var \WooCommerce\Facebook\Feed\Localization\LanguageFeedData|null Lazy-loaded data handler */
	private $language_feed_data = null;

	/** Action constants */
	const GENERATE_FEED_ACTION     = 'wc_facebook_regenerate_feed_';
	const REQUEST_FEED_ACTION      = 'wc_facebook_get_feed_data_language_override';
	const FEED_GEN_COMPLETE_ACTION = 'wc_facebook_feed_generation_completed_';
	const LEGACY_API_PREFIX        = 'woocommerce_api_';
	const OPTION_FEED_URL_SECRET   = 'wc_facebook_feed_url_secret_';

	/**
	 * Constructor
	 *
	 * Follows the same pattern as Products\Feed - only registers hooks in constructor.
	 * Data objects are instantiated on-demand when actually needed.
	 *
	 * @since 3.6.0
	 */
	public function __construct() {
		$this->add_hooks();
	}

	/**
	 * Get the language feed data handler (lazy-loaded).
	 *
	 * Only instantiates LanguageFeedData when it's actually needed,
	 * following the same pattern as Products\Feed.
	 *
	 * @return LanguageFeedData
	 * @since 3.6.0
	 */
	private function get_language_feed_data(): LanguageFeedData {
		if ( null === $this->language_feed_data ) {
			$this->language_feed_data = new LanguageFeedData();
		}
		return $this->language_feed_data;
	}



	/**
	 * Schedules the recurring feed generation.
	 *
	 * @since 3.6.0
	 */
	public function schedule_feed_generation(): void {
		$flag_name = '_wc_facebook_language_override_schedule_feed_generation';
		if ( 'yes' === get_transient( $flag_name ) ) {
			return;
		}
		set_transient( $flag_name, 'yes', HOUR_IN_SECONDS );

		$integration        = facebook_for_woocommerce()->get_integration();
		$connection_handler = facebook_for_woocommerce()->get_connection_handler();
		// Language feeds only require an active connection, not a Facebook Page ID
		$is_connected = $connection_handler && $connection_handler->is_connected();

		// Only schedule feed job if store has not opted out of product sync.
		$store_allows_sync = ( $is_connected && $integration->is_product_sync_enabled() ) || $integration->is_woo_all_products_enabled();

		// Only schedule if has not opted out of language override feed generation.
		$store_allows_language_feeds = $is_connected && $this->is_language_override_feed_generation_enabled();

		$schedule_action_hook_name = self::GENERATE_FEED_ACTION . static::get_data_stream_name();

		if ( ! $store_allows_sync || ! $store_allows_language_feeds || $this->should_skip_feed() ) {
			as_unschedule_all_actions( $schedule_action_hook_name );

			$message = '';
			if ( ! $is_connected ) {
				$message = 'Integration not configured.';
			} elseif ( ! $store_allows_language_feeds ) {
				$message = 'Store does not allow language override feeds.';
			} elseif ( ! $store_allows_sync ) {
				$message = 'Store does not allow sync.';
			} elseif ( $this->should_skip_feed() ) {
				$message = 'Feed should be skipped.';
			}

			Logger::log(
				sprintf( 'Language override feed scheduling failed: %s', $message ),
				array(
					'flow_name' => 'language_override_feed',
					'flow_step' => 'schedule_feed_generation',
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::WARNING,
				)
			);
			return;
		}

		// Prevent double registration by checking for existing scheduled actions
		if ( ! as_next_scheduled_action( $schedule_action_hook_name ) ) {
			as_schedule_recurring_action(
				time(),
				static::get_feed_gen_interval(),
				$schedule_action_hook_name,
				array(),
				facebook_for_woocommerce()->get_id_dasherized()
			);
		}
	}


	/**
	 * Unschedules the recurring feed generation.
	 *
	 * @since 3.6.0
	 */
	public function unschedule_feed_generation(): void {
		$schedule_action_hook_name = self::GENERATE_FEED_ACTION . static::get_data_stream_name();

		// Unschedule all actions for this feed type
		as_unschedule_all_actions( $schedule_action_hook_name );
	}


	/**
	 * Regenerates language override feeds (required for AJAX and scheduled actions).
	 * This method is called by the WordPress action scheduler and AJAX handlers.
	 * It regenerates all language feeds and triggers the upload hook.
	 *
	 * @since 3.6.0
	 */
	public function regenerate_feed(): void {
		// Call the main regeneration method
		$this->regenerate_all_language_feeds();

		/**
		 * Fires after language override feed generation is completed.
		 *
		 * @since 3.6.0
		 */
		do_action( self::FEED_GEN_COMPLETE_ACTION . static::get_data_stream_name() );
	}

	/**
	 * Regenerates language override feeds for all available languages.
	 * Uses the feed handler directly instead of the feed generator to create
	 * multiple language files in a single action.
	 *
	 * @since 3.6.0
	 */
	public function regenerate_all_language_feeds(): void {
		if ( $this->should_skip_feed() ) {
			return;
		}

		// Get all available languages
		$languages = $this->get_language_feed_data()->get_available_languages();

		if ( empty( $languages ) ) {
			return;
		}

		$successful_languages = [];
		$failed_languages     = [];
		$language_stats       = [];

		// Generate feed file for each language using the feed handler directly
		foreach ( $languages as $language_code ) {
			try {
				// Generate the feed file for this language
				$language_feed_writer = new LanguageOverrideFeedWriter( $language_code );
				$result               = $language_feed_writer->write_language_feed_file( $this->get_language_feed_data(), $language_code );

				if ( $result['success'] ) {
					$successful_languages[]           = $language_code;
					$language_stats[ $language_code ] = [
						'translated_products' => $result['count'],
						'last_generated'      => time(),
					];
				} else {
					$failed_languages[] = $language_code;
					Logger::log(
						sprintf( 'Failed to generate language override feed for: %s', $language_code ),
						[ 'language_code' => $language_code ],
						array(
							'should_send_log_to_meta' => true,
							'should_save_log_in_woocommerce' => true,
							'woocommerce_log_level'   => \WC_Log_Levels::ERROR,
						)
					);
				}
			} catch ( \Exception $e ) {
				$failed_languages[] = $language_code;
				Logger::log(
					sprintf( 'Exception while generating language override feed for %s: %s', $language_code, $e->getMessage() ),
					[
						'language_code'     => $language_code,
						'exception_message' => $e->getMessage(),
					],
					array(
						'should_send_log_to_meta'        => true,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
					),
					$e
				);
			}
		}

		// Log completion
		if ( ! empty( $failed_languages ) ) {
			Logger::log(
				sprintf(
					'Language override feeds generated with failures. Success: %d, Failed: %d (%s)',
					count( $successful_languages ),
					count( $failed_languages ),
					implode( ', ', $failed_languages )
				),
				[
					'failed_languages' => $failed_languages,
					'total_languages'  => count( $languages ),
				],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::WARNING,
				)
			);
		}

		// Send language feed statistics directly to Meta via commerce_3p_platform_event
		if ( ! empty( $language_stats ) ) {
			// Cache the stats in a transient for the hourly telemetry to use
			set_transient(
				\WooCommerce\Facebook\Utilities\PluginConfigTelemetry::TRANSIENT_LANGUAGE_FEED_STATS,
				$language_stats,
				\WooCommerce\Facebook\Utilities\PluginConfigTelemetry::TRANSIENT_LANGUAGE_FEED_STATS_LIFETIME
			);
		}

		// Trigger the upload hook if any languages were successful
		if ( ! empty( $successful_languages ) ) {
			do_action( self::FEED_GEN_COMPLETE_ACTION . static::get_data_stream_name() );
		}
	}


	/**
	 * Get the Heartbeat interval to ensure that feed gen is scheduled. Must be shorter than the feed gen interval.
	 *
	 * @return string Heartbeat constant value
	 */
	protected static function get_feed_gen_scheduling_interval(): string {
		return Heartbeat::HOURLY;
	}

	/**
	 * Override add_hooks to use the correct REQUEST_FEED_ACTION constant.
	 * This ensures the WooCommerce API hook is registered with the proper action name.
	 *
	 * @since 3.6.0
	 */
	protected function add_hooks(): void {
		add_action( static::get_feed_gen_scheduling_interval(), array( $this, 'schedule_feed_generation' ) );
		add_action( self::GENERATE_FEED_ACTION . static::get_data_stream_name(), array( $this, 'regenerate_all_language_feeds' ) );
		add_action( self::FEED_GEN_COMPLETE_ACTION . static::get_data_stream_name(), array( $this, 'upload_language_override_feeds' ) );
		add_action(
			self::LEGACY_API_PREFIX . static::REQUEST_FEED_ACTION,
			array(
				$this,
				'handle_feed_data_request',
			)
		);
	}

	/**
	 * Gets the feed secret used for feed requests.
	 * Reuses the existing Feed class's secret for consistency.
	 *
	 * @return string
	 */
	protected function get_feed_secret(): string {
		return \WooCommerce\Facebook\Products\Feed::get_feed_secret();
	}

	/**
	 * Checks if language override feed generation is enabled in the admin settings.
	 *
	 * @return bool
	 * @since 3.6.0
	 */
	private function is_language_override_feed_generation_enabled(): bool {
		$integration = facebook_for_woocommerce()->get_integration();
		return $integration && $integration->is_language_override_feed_generation_enabled();
	}

	/**
	 * Get the data feed type for language override feeds.
	 *
	 * @return string
	 */
	protected static function get_feed_type(): string {
		return 'LANGUAGE_OVERRIDE';
	}

	/**
	 * Get the data stream name for language override feeds.
	 *
	 * @return string
	 */
	protected static function get_data_stream_name(): string {
		return 'language_override';
	}

	/**
	 * Override the feed generation interval to match product feeds frequency.
	 *
	 * @return int
	 */
	protected static function get_feed_gen_interval(): int {
		/**
		 * Filters the frequency with which the language override feed data is generated.
		 *
		 * @since 3.6.0
		 *
		 * @param int $interval the frequency with which the language override feed data is generated, in seconds.
		 */
		return apply_filters( 'wc_facebook_language_override_feed_generation_interval', DAY_IN_SECONDS );
	}

	/**
	 * Check if feed generation should be skipped.
	 *
	 * @return bool
	 */
	public function should_skip_feed(): bool {
		// Check if language override feed generation is enabled
		if ( ! $this->is_language_override_feed_generation_enabled() ) {
			return true;
		}

		$connection_handler = facebook_for_woocommerce()->get_connection_handler();

		// Check connection methods
		$has_valid_connection = ! empty( $connection_handler->get_commerce_partner_integration_id() ) ||
								! empty( $connection_handler->get_commerce_merchant_settings_id() ) ||
								! empty( $connection_handler->get_access_token() );

		if ( ! $has_valid_connection ) {
			return true;
		}

		// Check localization plugin
		if ( ! IntegrationRegistry::has_active_localization_plugin() ) {
			return true;
		}

		return false;
	}

	/**
	 * Override handle_feed_data_request to add language parameter handling.
	 * This mirrors Feed.php's handle_feed_data_request but adds language support.
	 *
	 * @throws PluginException If the feed secret is invalid, file is not readable, or other errors occur.
	 */
	public function handle_feed_data_request(): void {
		try {
			// Get the language code from the request
			$language_code = Helper::get_requested_value( 'language' );
			if ( empty( $language_code ) ) {
				throw new PluginException( 'Language code is required', 400 );
			}

			// Validate the feed secret
			if ( $this->get_feed_secret() !== Helper::get_requested_value( 'secret' ) ) {
				throw new PluginException( 'Invalid feed secret provided', 401 );
			}

			// Create language-specific feed writer to get file path
			$language_feed_writer = new LanguageOverrideFeedWriter( $language_code );
			$file_path            = $language_feed_writer->get_file_path();

			// Regenerate if the file doesn't exist or if explicitly requested
			$regenerate = Helper::get_requested_value( 'regenerate' );
			if ( ! empty( $regenerate ) || ! file_exists( $file_path ) ) {
				$success = $language_feed_writer->write_language_feed_file( $this->language_feed_data, $language_code );
				if ( ! $success ) {
					throw new PluginException( 'Failed to regenerate language feed file', 500 );
				}
			}

			// Check if the file can be read
			if ( ! is_readable( $file_path ) ) {
				throw new PluginException( 'Language feed file is not readable', 404 );
			}

			// Set the download headers
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Description: File Transfer' );
			header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
			header( 'Expires: 0' );
			header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
			header( 'Pragma: public' );
			header( 'Content-Length:' . filesize( $file_path ) );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$file = @fopen( $file_path, 'rb' );
			if ( ! $file ) {
				throw new PluginException( 'Could not open language feed file', 500 );
			}

			// fpassthru might be disabled in some hosts (like Flywheel)
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( \WC_Facebookcommerce_Utils::is_fpassthru_disabled() || ! @fpassthru( $file ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$contents = @stream_get_contents( $file );
				if ( ! $contents ) {
					throw new PluginException( 'Could not get language feed file contents', 500 );
				}
				echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@fclose( $file );

		} catch ( \Exception $exception ) {
			Logger::log(
				'Could not serve language override feed. ' . $exception->getMessage() . ' (' . $exception->getCode() . ')',
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
			status_header( $exception->getCode() ? $exception->getCode() : 500 );
		}

		exit;
	}


	/**
	 * Override get_feed_data_url to add language parameter.
	 * This mirrors Feed.php's get_feed_data_url but adds language support.
	 *
	 * @param string $language_code Language code
	 * @return string
	 */
	public function get_language_feed_url( string $language_code ): string {
		$query_args = array(
			'wc-api'   => static::REQUEST_FEED_ACTION,
			'language' => $language_code,
			'secret'   => $this->get_feed_secret(),
		);

		return add_query_arg( $query_args, home_url( '/' ) );
	}

	/**
	 * Upload language override feeds to Facebook for all available languages.
	 * This mirrors Feed.php's send_request_to_upload_feed but handles multiple languages.
	 *
	 * @since 3.6.0
	 */
	public function upload_language_override_feeds() {
		if ( ! IntegrationRegistry::has_active_localization_plugin() ) {
			return;
		}

		$languages = $this->get_language_feed_data()->get_available_languages();

		foreach ( $languages as $language_code ) {
			$this->upload_single_language_feed( $language_code );
		}
	}

	/**
	 * Upload a single language override feed to Facebook.
	 * This mirrors Feed.php's send_request_to_upload_feed but for a specific language.
	 * Only uploads if the feed file exists and has actual product data.
	 *
	 * @param string $language_code Language code (e.g., 'es_ES', 'fr_FR')
	 * @throws \Exception If feed creation/retrieval fails or API upload fails.
	 * @since 3.6.0
	 */
	private function upload_single_language_feed( string $language_code ) {
		try {
			// Check if feed file exists and has data before attempting upload
			$language_feed_writer = new LanguageOverrideFeedWriter( $language_code );
			$file_path            = $language_feed_writer->get_file_path();

			// Skip upload if file doesn't exist
			if ( ! file_exists( $file_path ) ) {
				return;
			}

			// Step 1: Create or get the language override feed configuration using trait method
			$feed_id = $this->retrieve_or_create_language_feed_id( $language_code );

			if ( empty( $feed_id ) ) {
				throw new \Exception( 'Could not create or retrieve language override feed ID' );
			}

			// Step 2: Tell Facebook to fetch the CSV data from our endpoint (feed files are already generated)
			$data = [
				'url' => $this->get_language_feed_url( $language_code ),
			];

			facebook_for_woocommerce()->get_api()->create_product_feed_upload( $feed_id, $data );

			// Successful upload - no log needed (matches main feed behavior)

		} catch ( \Exception $exception ) {
			Logger::log(
				'Language override feed upload failed: ' . $exception->getMessage(),
				array(
					'language_code' => $language_code,
				),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				),
				$exception
			);
		}
	}
}
