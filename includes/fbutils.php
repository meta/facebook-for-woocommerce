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

use WooCommerce\Facebook\Events\AAMSettings;
use WooCommerce\Facebook\Events\Normalizer;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\ErrorLogHandler;
use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Framework\Logger;

require_once __DIR__ . '/Logger/Logger.php';

if ( ! class_exists( 'WC_Facebookcommerce_Utils' ) ) :
	/**
	 * FB Graph API helper functions
	 */
	class WC_Facebookcommerce_Utils {

		const FB_RETAILER_ID_PREFIX = 'wc_post_id_';
		// TODO: remove this in v2.0.0 {CW 2020-02-06}
		const PLUGIN_VERSION              = \WC_Facebookcommerce::VERSION;
		const FB_VARIANT_SIZE             = 'size';
		const FB_VARIANT_COLOR            = 'color';
		const FB_VARIANT_COLOUR           = 'colour';
		const FB_VARIANT_PATTERN          = 'pattern';
		const FB_VARIANT_GENDER           = 'gender';
		const WC_EXCERPT_LENGTH_THRESHOLD = 10;

		// TODO: this constant is no longer used and can probably be removed {WV 2020-01-21}
		const FB_VARIANT_IMAGE = 'fb_image';
		/** @var string */
		public static $ems = null;

		/** @var string */
		public static $store_name = null;

		/** @var array */
		public static $valid_gender_array = array(
			'male'   => 1,
			'female' => 1,
			'unisex' => 1,
		);

		/**
		 * A deferred events storage.
		 *
		 * @var array
		 */
		private static $deferred_events = [];

		/**
		 * Prints deferred events into page header.
		 *
		 * Supports both legacy (JS code string) and isolated execution (event data array) formats.
		 * - Legacy format (switch OFF): Outputs inline <script> tag with JS code
		 * - Isolated format (switch ON): Uses WC_Facebookcommerce_Pixel::enqueue_event()
		 *
		 * @since 3.1.6
		 */
		public static function print_deferred_events() {
			$deferred_events = static::load_deferred_events();

			if ( empty( $deferred_events ) ) {
				return;
			}

			// Check if isolated pixel execution is enabled
			$is_isolated_execution_enabled = facebook_for_woocommerce()->get_rollout_switches()->is_switch_enabled(
				\WooCommerce\Facebook\RolloutSwitches::SWITCH_ISOLATED_PIXEL_EXECUTION_ENABLED
			);

			// Separate events by type
			$legacy_events   = array();
			$isolated_events = array();

			foreach ( $deferred_events as $event ) {
				if ( is_array( $event ) ) {
					$isolated_events[] = $event;
				} else {
					$legacy_events[] = $event;
				}
			}

			// Handle isolated execution events (event data arrays) - only if switch is enabled
			if ( $is_isolated_execution_enabled ) {
				foreach ( $isolated_events as $event ) {
					WC_Facebookcommerce_Pixel::enqueue_event(
						$event['name'],
						$event['params'],
						$event['method'] ?? 'track',
						$event['eventId'] ?? ''
					);
				}
			}

			// Handle legacy events (JS code strings) - combine into single script tag
			if ( ! empty( $legacy_events ) ) {
				echo '<script>' . implode( PHP_EOL, $legacy_events ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped --- Printing hardcoded JS tracking code.
			}
		}

		/**
		 * Loads deferred events from the storage and cleans the storage immediately after.
		 *
		 * @since 3.1.6
		 *
		 * @return array
		 */
		private static function load_deferred_events(): array {
			$transient_key = static::get_deferred_events_transient_key();
			if ( ! $transient_key ) {
				return array();
			}

			$deferred_events = get_transient( $transient_key );
			if ( ! $deferred_events ) {
				return array();
			}

			delete_transient( $transient_key );
			return $deferred_events;
		}

		/**
		 * Adds event into the list of events to be saved/rendered.
		 *
		 * Supports both legacy (JS code string) and isolated execution (event data array) formats:
		 * - Legacy format (switch OFF): Pass JS code string from get_event_code()
		 * - Isolated format (switch ON): Pass event data array with keys: name, params, method, eventId
		 *
		 * @since 3.1.6
		 *
		 * @param string|array $event Event data - either JS code string (legacy) or event data array (isolated).
		 */
		public static function add_deferred_event( $event ): void {
			static::$deferred_events[] = $event;
		}

		/**
		 * Saves deferred events into the storage.
		 *
		 * @since 3.1.6
		 */
		public static function save_deferred_events() {
			$transient_key = static::get_deferred_events_transient_key();
			if ( ! $transient_key ) {
				return;
			}

			$existing_events         = static::load_deferred_events();
			static::$deferred_events = array_merge( $existing_events, static::$deferred_events );

			if ( ! empty( static::$deferred_events ) ) {
				set_transient( $transient_key, static::$deferred_events, DAY_IN_SECONDS );
			}
		}

		/**
		 * Returns the transient key for deferred events based on user session.
		 *
		 * @since 3.1.6
		 *
		 * @return string
		 */
		private static function get_deferred_events_transient_key(): string {
			if ( is_object( WC()->session ) ) {
				return 'facebook_for_woocommerce_async_events_' . md5( WC()->session->get_customer_id() );
			}

			return '';
		}

		/**
		 * Enqueue inline js directly.
		 *
		 * @since 1.2.1
		 *
		 * @param string $code
		 */
		public static function enqueue_inline_js( $code ) {
			global $wc_queued_js;

			$handle = 'facebook-for-woocommerce-inline';

			if ( ! function_exists( 'wp_add_inline_script' ) ) {
				$wc_queued_js = $code . "\n" . $wc_queued_js;
			} else {
				static $registered = false;
				if ( ! $registered ) {
					if ( ! wp_script_is( $handle, 'registered' ) ) {
						$version = defined( 'WC_VERSION' ) ? WC_VERSION : false;
						wp_register_script( $handle, '', array(), $version, true );
					}
					wp_enqueue_script( $handle );
					$registered = true;
				}

				if ( ! is_string( $code ) || '' === trim( $code ) ) {
					return;
				}

				wp_add_inline_script( $handle, $code );
			}
		}

		/**
		 * Validate URLs, make relative URLs absolute.
		 *
		 * @param string $url
		 * @return string
		 */
		public static function make_url( $url ) {
			if (
			// The first check incorrectly fails for URLs with special chars.
			! filter_var( $url, FILTER_VALIDATE_URL ) &&
			substr( $url, 0, 4 ) !== 'http'
			) {
				return get_site_url() . $url;
			} else {
				return $url;
			}
		}

		/**
		 * Product ID for Dynamic Ads on Facebook can be SKU or wc_post_id_123.
		 *
		 * This function should be used to get retailer_id based on a WC_Product
		 * from WooCommerce.
		 *
		 * @param WC_Product|WC_Facebook_Product $woo_product
		 * @return string
		 */
		public static function get_fb_retailer_id( $woo_product ) {
			$woo_id = (string) $woo_product->get_id();

			/**
			 * Filter facebook retailer id value.
			 *
			 * This can be used to match retailer id generated by other Facebook plugins.
			 *
			 * @since 2.6.12
			 *
			 * @param string     Facebook Retailer ID.
			 * @param WC_Product WooCommerce product.
			 */
			return apply_filters( 'wc_facebook_fb_retailer_id', $woo_id, $woo_product );
		}

		/**
		 * Returns the categories for products/pixel.
		 *
		 * @param int $wpid
		 * @return Array
		 */
		public static function get_product_categories( $wpid ) {
			$category_path = wp_get_post_terms(
				$wpid,
				'product_cat',
				array( 'fields' => 'all' )
			);

			if ( is_wp_error( $category_path ) ) {
				return array(
					'name' => '',
					'categories' => '""'
				);
			}

			$content_category = array_values(
				array_map(
					function ( $item ) {
						return html_entity_decode( $item->name, ENT_QUOTES | ENT_HTML401, 'UTF-8' );
					},
					$category_path
				)
			);

			$content_category_slice = array_slice( $content_category, -1 );
			$categories             = empty( $content_category ) ? '""' : implode( ', ', $content_category );

			return array(
				'name'       => array_pop( $content_category_slice ),
				'categories' => $categories,
			);
		}

		/**
		 * Returns the category ids for products/pixel.
		 *
		 * @param int $wpid
		 * @return Array
		 */
		public static function get_product_category_ids( $wpid ) {
			$product = wc_get_product( $wpid );

			if ( ! $product ) {
				return [];
			}

			return $product->get_category_ids();
		}

		/**
		 * Returns the category ids for products/pixel.
		 *
		 * @param int $wpid
		 * @return Array
		 */
		public static function get_excluded_product_tags_ids( $wpid ) {
			$product = wc_get_product( $wpid );

			if ( ! $product ) {
				return [];
			}

			return $product->get_tag_ids();
		}

		/**
		 * Returns the content ID to match on for Pixel fires.
		 *
		 * @param WC_Product $woo_product
		 * @return array
		 */
		public static function get_fb_content_ids( $woo_product ) {
			return array( self::get_fb_retailer_id( $woo_product ) );
		}

		/**
		 * Cleans up strings for FB Graph POSTing.
		 *
		 * This function should will:
		 * 1. Replace newlines chars/nbsp with a real space
		 * 2. strip_tags() if not explicitly stated to not
		 * 3. trim()
		 *
		 * @param string $str
		 * @param bool   $strip_html_tags
		 * @return string
		 */
		public static function clean_string( $str, $strip_html_tags = true ) {
			if ( empty( $str ) ) {
				return '';
			}

			/**
			 * Filters whether the shortcodes should be applied for a string when syncing a product or be stripped out.
			 *
			 * @since 2.6.19
			 *
			 * @param bool   $apply_shortcodes Shortcodes are applied if set to `true` and stripped out if set to `false`.
			 * @param string $str           String to clean up.
			 */
			$apply_shortcodes = apply_filters( 'wc_facebook_string_apply_shortcodes', false, $str );
			if ( $apply_shortcodes ) {
				// Apply active shortcodes
				$str = do_shortcode( $str );
			} else {
				// Strip out active shortcodes
				$str = strip_shortcodes( $str );
			}

			$str = str_replace( array( '&amp%3B', '&amp;' ), '&', $str );
			$str = str_replace( array( "\r", '&nbsp;', "\t" ), ' ', $str );
			if ( $strip_html_tags ) {
				$str = wp_strip_all_tags( $str, false ); // true == remove line breaks
			}

			return $str;
		}

		/**
		 * Returns a flat array of woo IDs for variable products, or
		 * an array with a single woo ID for simple products.
		 *
		 * @param WC_Product|WC_Facebook_Product $woo_product
		 * @return array
		 */
		public static function get_product_array( $woo_product ) {
			$result = [];

			if ( self::is_variable_type( $woo_product->get_type() ) ) {
				foreach ( $woo_product->get_children() as $item_id ) {
					array_push( $result, $item_id );
				}
				return $result;
			} else {
				return array( $woo_product->get_id() );
			}
		}

		/**
		 * Returns true if WooCommerce plugin found.
		 *
		 * @return bool
		 */
		public static function is_woocommerce_integration() {
			return class_exists( 'WooCommerce' );
		}

		/**
		 * Returns integration dependent name.
		 *
		 * @return string
		 */
		public static function get_integration_name() {
			if ( self::is_woocommerce_integration() ) {
				return 'WooCommerce';
			} else {
				return 'WordPress';
			}
		}

		/**
		 * Returns user info for the current WP user.
		 *
		 * @param AAMSettings $aam_settings
		 * @return array
		 */
		public static function get_user_info( $aam_settings ) {
			$current_user = wp_get_current_user();

			if ( null === $aam_settings || ! $aam_settings->get_enable_automatic_matching() ) {
				// User not logged in or pixel not configured with automatic advance matching
				return [];
			} else {
				$user_data = array();
				if ( 0 !== $current_user->ID ) {
					// Keys documented in https://developers.facebook.com/docs/facebook-pixel/advanced/advanced-matching
					$user_data            = array(
						'em'          => $current_user->user_email,
						'fn'          => $current_user->user_firstname,
						'ln'          => $current_user->user_lastname,
						'external_id' => strval( get_current_user_id() ),
					);
					$user_id              = $current_user->ID;
					$user_data['ct']      = get_user_meta( $user_id, 'billing_city', true );
					$user_data['zp']      = get_user_meta( $user_id, 'billing_postcode', true );
					$user_data['country'] = get_user_meta( $user_id, 'billing_country', true );
					$user_data['st']      = get_user_meta( $user_id, 'billing_state', true );
					$user_data['ph']      = get_user_meta( $user_id, 'billing_phone', true );
				}

				// Each field that is not present in AAM settings or is empty is deleted from user data
				foreach ( $user_data as $field => $value ) {
					if ( null === $value || '' === $value
						|| ! in_array( $field, $aam_settings->get_enabled_automatic_matching_fields(), true )
					) {
						unset( $user_data[ $field ] );
					}
				}

				// Country is a special case, it is returned as country in AAM settings
				// But used as cn in pixel
				if ( array_key_exists( 'country', $user_data ) ) {
					$country         = $user_data['country'];
					$user_data['cn'] = $country;
					unset( $user_data['country'] );
				}

				$user_data = Normalizer::normalize_array( $user_data, true );
				return $user_data;
			}
		}

		/**
		 * Returns whether the variation type is 'variation' or 'subscription_variation'.
		 *
		 * @param string $type
		 */
		public static function is_variation_type( $type ) {
			return 'variation' === $type || 'subscription_variation' === $type;
		}

		/**
		 * Returns whether the variation type is 'variable' or 'variable-subscription'.
		 *
		 * @param string $type
		 */
		public static function is_variable_type( $type ) {
			return 'variable' === $type || 'variable-subscription' === $type;
		}

		/**
		 * Returns whether the current user is an admin user who can manage (create) orders.
		 *
		 * @return bool
		 */
		public static function is_admin_user() {
			return current_user_can( 'manage_woocommerce' );
		}

		/**
		 * Checks if the ajax caller is admin and the call is stemming from an active admin session.
		 *
		 * @param string $action
		 * @param string $nonce
		 * @return bool
		 */
		public static function is_legit_ajax_call( $action, $nonce = 'nonce' ) {
			return self::is_admin_user() && check_ajax_referer( $action, $nonce );
		}

		/**
		 * Returns whether AJAX permissions are valid.
		 *
		 * @param string $action_text
		 * @param bool   $should_die
		 */
		public static function check_woo_ajax_permissions( $action_text, $should_die ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {

				Logger::log(
					'Non manage_woocommerce user attempting to' . $action_text . '!',
					[],
					array(
						'should_send_log_to_meta'        => false,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::CRITICAL,
					)
				);

				if ( $should_die ) {
					wp_die();
				}
				return false;
			}

			return true;
		}

		/**
		 * Truncates a float value to the number of points.
		 *
		 * Scaling a float is lossy, so a value that is exactly 5.99 in decimal can scale to
		 * 598.9999999999999 and floor down to 5.98. The scaled value is rounded off well below
		 * the point of interest first, which removes that artifact while still truncating
		 * digits the caller genuinely asked to drop.
		 *
		 * @param float $value input value
		 * @param int   $points number of floating points
		 * @return float
		 */
		public static function truncate_float_number( float $value, int $points = 2 ) {
			$zeros = pow( 10, $points );
			return floor( round( $value * $zeros, 6 ) ) / $zeros;
		}

		/**
		 * Returns true if id is a positive non-zero integer.
		 *
		 * @param string $pixel_id
		 * @return bool
		 */
		public static function is_valid_id( $pixel_id ) {
			return isset( $pixel_id ) && is_numeric( $pixel_id ) && (int) $pixel_id > 0;
		}

		/**
		 * Helper function to query posts.
		 *
		 * @param int    $product_group_id
		 * @param string $compare_condition
		 * @param string $post_type
		 */
		public static function get_wp_posts(
			$product_group_id = null,
			$compare_condition = null,
			$post_type = 'product'
		) {
			$args = array(
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_query'     => array(
					( ( $product_group_id ) ?
					array(
						'key'     => $product_group_id,
						'compare' => $compare_condition,
					) : []
					),
				),
				'post_status'    => 'publish',
				'post_type'      => $post_type,
				'posts_per_page' => -1,
			);

			return get_posts( $args );
		}

		/**
		 * Returns store name with sanitized apostrophe.
		 *
		 * @return string
		 */
		public static function get_store_name() {
			if ( self::$store_name ) {
				return self::$store_name;
			}

			$apos = "\u{2019}";
			$name = trim(
				str_replace(
					"'",
					$apos,
					html_entity_decode(
						get_bloginfo( 'name' ),
						ENT_QUOTES,
						'UTF-8'
					)
				)
			);
			if ( $name ) {
				self::$store_name = $name;
				return $name;
			}

			// Fallback to site url
			$url = get_site_url();
			if ( $url ) {
				self::$store_name = wp_parse_url( $url, PHP_URL_HOST );
				return self::$store_name;
			}

			// If site url doesn't exist, fall back to http host.
			if ( isset( $_SERVER['HTTP_HOST'] ) ) {
				self::$store_name = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
				return self::$store_name;
			}

			// If http host doesn't exist, fall back to local host name.
			$url              = gethostname();
			self::$store_name = $url;
			return ( self::$store_name ) ? ( self::$store_name ) : 'A Store Has No Name';
		}

		/**
		 * Returns the default brand name
		 *
		 * @return string
		 */
		public static function get_default_fb_brand() {
			return wp_strip_all_tags( self::get_store_name() );
		}


		/**
		 * Get visible name for variant attribute rather than the slug
		 *
		 * @param int    $wp_id         Post ID.
		 * @param string $label         Attribute label.
		 * @param string $default_value Default value to use if the term has no name.
		 * @return string Term name or the default value.
		 */
		public static function get_variant_option_name( $wp_id, $label, $default_value ) {
			$meta           = get_post_meta( $wp_id, $label, true );
			$attribute_name = str_replace( 'attribute_', '', $label );
			$term           = get_term_by( 'slug', $meta, $attribute_name );

			return $term && $term->name ? $term->name : $default_value;
		}

		/**
		 * Get all products for synchronization tasks.
		 *
		 * Warning: While changing this code please make sure that it scales properly.
		 * Sites with big product catalogs should not experience memory problems.
		 *
		 * @return array IDs of all product for synchronization.
		 */
		public static function get_all_product_ids_for_sync() {
			// Get all published products ids. This includes parent products of variations.
			$product_args       = array(
				'fields'         => 'ids',
				'post_status'    => 'publish',
				'post_type'      => 'product',
				'posts_per_page' => -1,
			);
			$product_ids        = get_posts( $product_args );
			$variation_args     = array(
				'fields'         => 'id=>parent',
				'post_status'    => 'publish',
				'post_type'      => 'product_variation',
				'posts_per_page' => -1,
			);
			$variation_products = get_posts( $variation_args );

			/*
			* Collect all parent products.
			* Exclude variations which parents are not 'publish'.
			*/
			$parent_product_ids = [];
			foreach ( $variation_products as $post_id => $parent_id ) {
				/*
				* Keep track of all parents to remove them from the list of products to sync.
				* Use key to automatically remove duplicated items.
				*/
				$parent_product_ids[ $parent_id ] = true;

				// Include variations with published parents only.
				if ( in_array( $parent_id, $product_ids, true ) ) {
					$product_ids[] = $post_id;
				}
			}

			// Remove parent products because those can't be represented as Product Items.
			return array_diff( $product_ids, array_keys( $parent_product_ids ) );
		}


		/**
		 * Change variant product field name from Woo taxonomy to FB name.
		 *
		 * @param string $name
		 * @param bool   $use_custom_data
		 * @return string
		 */
		public static function sanitize_variant_name( $name, $use_custom_data = true ) {
			$name = str_replace( array( 'attribute_', 'pa_' ), '', strtolower( $name ) );

			// British spelling
			if ( self::FB_VARIANT_COLOUR === $name ) {
				$name = self::FB_VARIANT_COLOR;
			}

			if ( $use_custom_data ) {
				switch ( $name ) {
					case self::FB_VARIANT_SIZE:
					case self::FB_VARIANT_COLOR:
					case self::FB_VARIANT_GENDER:
					case self::FB_VARIANT_PATTERN:
						break;
					default:
						$name = 'custom_data:' . strtolower( $name );
						break;
				}
			}

			return $name;
		}

		/**
		 * Sanitize attribute names inline with FB name.
		 *
		 * @param string $name
		 * @return string
		 */
		public static function sanitize_attribute_name( $name ) {
			return str_replace( array( '-', ' ' ), '_', $name );
		}

		/**
		 * Validates the gender.
		 *
		 * @param string $gender
		 * @return string
		 */
		public static function validate_gender( $gender ) {
			if ( $gender && ! isset( self::$valid_gender_array[ $gender ] ) ) {
				$first_char = strtolower( substr( $gender, 0, 1 ) );

				// Men, Man, Boys
				if ( 'm' === $first_char || 'b' === $first_char ) {
					return 'male';
				}

				// Women, Woman, Female, Ladies
				if ( 'w' === $first_char || 'f' === $first_char || 'l' === $first_char ) {
					return 'female';
				}

				if ( 'u' === $first_char ) {
					return 'unisex';
				}

				if ( 3 <= strlen( $gender ) ) {
					$gender = strtolower( substr( $gender, 0, 3 ) );
					if ( 'gir' === $gender || 'her' === $gender ) {
						return 'female';
					}

					if ( 'him' === $gender || 'his' === $gender || 'guy' === $gender ) {
						return 'male';
					}
				}

				return null;
			}

			return $gender;
		}

		/**
		 * Gets the FBID based on wp_id and fbid_type.
		 *
		 * @param int    $wp_id
		 * @param string $fbid_type
		 * @return int
		 */
		public static function get_fbid_post_meta( $wp_id, $fbid_type ) {
			return get_post_meta( $wp_id, $fbid_type, true );
		}

		/**
		 * Returns whether or no the value is all caps.
		 *
		 * @param string $value
		 * @return bool
		 */
		public static function is_all_caps( $value ) {
			if ( null === $value || '' === $value ) {
				return true;
			}

			if ( preg_match( '/[^\\p{Common}\\p{Latin}]/u', $value ) ) {
				// Contains non-western characters
				// So, it can't be all uppercase
				return false;
			}

			$latin_string = preg_replace( '/[^\\p{Latin}]/u', '', $value );
			if ( '' === $latin_string ) {
				// Symbols only
				return true;
			}

			return strtoupper( $latin_string ) === $latin_string;
		}

		/**
		 * Decodes JSON string.
		 *
		 * @param string $json_string
		 * @param bool   $assoc
		 * @return mixed
		 */
		public static function decode_json( $json_string, $assoc = false ) {
			// Plugin requires 5.6.0 but for some user use 5.5.9 JSON_BIGINT_AS_STRING
			// will cause 502 issue when redirect.
			return version_compare( phpversion(), '5.6.0' ) >= 0
			? json_decode( $json_string, $assoc, 512, JSON_BIGINT_AS_STRING )
			: json_decode( $json_string, $assoc, 512 );
		}

		/**
		 * Sets the test fail reason.
		 *
		 * @param string $msg
		 * @param string $trace
		 */
		public static function set_test_fail_reason( $msg, $trace ) {
			$reason_msg = get_transient( 'facebook_plugin_test_fail' );
			if ( $reason_msg ) {
				$msg = $reason_msg . PHP_EOL . $msg;
			}
			set_transient( 'facebook_plugin_test_fail', $msg );
			set_transient( 'facebook_plugin_test_stack_trace', $trace );
		}

		public static function generate_guid() {
			if ( function_exists( 'com_create_guid' ) === true ) {
				return trim( com_create_guid(), '{}' );
			}

			return sprintf(
				'%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
				wp_rand( 0, 65535 ),
				wp_rand( 0, 65535 ),
				wp_rand( 0, 65535 ),
				wp_rand( 16384, 20479 ),
				wp_rand( 32768, 49151 ),
				wp_rand( 0, 65535 ),
				wp_rand( 0, 65535 ),
				wp_rand( 0, 65535 )
			);
		}

		/**
		 * Normalizes product data to be included in a sync request. /items_batch
		 * rather than /batch this time.
		 *
		 * @since 3.1.7
		 *
		 * @param array $data product data.
		 * @return array
		 */
		public static function normalize_product_data_for_items_batch( $data ) {
			/*
			 * To avoid overriding the condition value, we check if the value is set or is not one of
			 * the allowed values before setting it to 'new'. Allowed values are 'refurbished', 'used', and 'new'.
			 */
			if ( ! isset( $data['condition'] ) || ! in_array( $data['condition'], array( 'refurbished', 'used', 'new' ), true ) ) {
				$data['condition'] = 'new';
			}

			// Attributes other than size, color, pattern, or gender need to be included in the additional_variant_attributes field.
			if ( isset( $data['custom_data'] ) && is_array( $data['custom_data'] ) ) {
				$attributes = [];
				foreach ( $data['custom_data'] as $key => $val ) {

					/**
					 * Filter: facebook_for_woocommerce_variant_attribute_comma_replacement
					 *
					 * The Facebook API expects a comma-separated list of attributes in `additional_variant_attribute` field.
					 * https://developers.facebook.com/docs/marketing-api/catalog/reference/
					 * This means that WooCommerce product attributes included in this field should avoid the comma (`,`) character.
					 * Meta for WooCommerce replaces any `,` with a space by default.
					 * This filter allows a site to provide a different replacement string.
					 *
					 * @since 2.5.0
					 *
					 * @param string $replacement The default replacement string (`,`).
					 * @param string $value Attribute value.
					 * @return string Return the desired replacement string.
					 */
					$attribute_value = str_replace(
						',',
						apply_filters( 'facebook_for_woocommerce_variant_attribute_comma_replacement', ' ', $val ),
						$val
					);
					/** Force replacing , and : characters if those were not cleaned up by filters */
					$attributes[] = str_replace( [ ',', ':' ], ' ', $key ) . ':' . str_replace( [ ',', ':' ], ' ', $attribute_value );
				}

				$data['additional_variant_attribute'] = implode( ',', $attributes );
				unset( $data['custom_data'] );
			}

			return $data;
		}

		/**
		 * Prepares the product data to be included in a sync request.
		 *
		 * @since 3.1.7
		 *
		 * @param \WC_Product $product product object
		 * @return array
		 */
		public static function prepare_product_data_items_batch( $product ) {
			$fb_product = new \WC_Facebook_Product( $product->get_id() );
			$data       = $fb_product->prepare_product( null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
			// Products that are not variations use their retailer retailer ID as the retailer product group ID
			$data['item_group_id'] = $data['retailer_id'];

			return self::normalize_product_data_for_items_batch( $data );
		}

		/**
		 * Prepares the requests array to be included in a batch api request.
		 *
		 * @since 3.1.7
		 *
		 * @param array $product Array
		 * @return array
		 */
		public static function prepare_product_requests_items_batch( $product ) {
			$product['item_group_id'] = $product['retailer_id'];
			$product_data             = self::normalize_product_data_for_items_batch( $product );

			// Extract the retailer_id
			$retailer_id = $product_data['retailer_id'];

			// NB: Changing this to get items_batch to work
			// retailer_id cannot be included in the data object
			unset( $product_data['retailer_id'] );
			$product_data['id'] = $retailer_id;

			$requests = array(
				[
					'method' => Sync::ACTION_UPDATE,
					'data'   => $product_data,
				],
			);

			return $requests;
		}

		/**
		 * Prepares the data for a product variation to be included in a sync request.
		 *
		 * @since 3.1.7
		 *
		 * @param \WC_Product $product product object
		 * @return array
		 * @throws PluginException In case no product found.
		 */
		public static function prepare_product_variation_data_items_batch( $product ) {
			$parent_product = wc_get_product( $product->get_parent_id() );

			if ( ! $parent_product instanceof \WC_Product ) {
				throw new PluginException( esc_html( "No parent product found with ID equal to {$product->get_parent_id()}." ) );
			}

			$fb_parent_product = new \WC_Facebook_Product( $parent_product->get_id() );
			$fb_product        = new \WC_Facebook_Product( $product->get_id(), $fb_parent_product );

			$data = $fb_product->prepare_product( null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );

			// Product variations use the parent product's retailer ID as the retailer product group ID
			// $data['retailer_product_group_id'] = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $parent_product );
			$data['item_group_id'] = self::get_fb_retailer_id( $parent_product );

			return self::normalize_product_data_for_items_batch( $data );
		}

		/**
		 * Utility function for sending exception logs to Meta.
		 *
		 * @since 3.5.0
		 *
		 * @param Throwable $error error object
		 * @param array     $context optional error message attributes
		 */
		public static function log_exception_immediately_to_meta( Throwable $error, array $context = [] ) {
			ErrorLogHandler::log_exception_to_meta( $error, $context );
		}

		/**
		 * Checks whether fpassthru has been disabled in PHP.
		 *
		 * @since 3.5.0
		 *
		 * @return bool
		 */
		public static function is_fpassthru_disabled(): bool {
			$disabled = false;
			if ( function_exists( 'ini_get' ) ) {
				// phpcs:ignore
				$disabled_functions = @ini_get( 'disable_functions' );

				$disabled =
					is_string( $disabled_functions ) &&
					//phpcs:ignore
					in_array( 'fpassthru', explode( ',', $disabled_functions ), false );
			}

			return $disabled;
		}

		/**
		 * Gets a value from the context array, or a default if the key is not set.
		 *
		 * @param array  $context
		 * @param string $key
		 * @param mixed  $default_value
		 * @return mixed
		 */
		public static function get_context_data( array $context, string $key, $default_value = null ) {
			return $context[ $key ] ?? $default_value;
		}


		/**
		 * Check if a post excerpt is a WooCommerce-generated attribute summary.
		 *
		 * WooCommerce automatically generates attribute summaries for variations in the format:
		 * "attribute1: value1, attribute2: value2"
		 *
		 * @param string $excerpt The post excerpt to check.
		 * @return bool True if this appears to be a WooCommerce attribute summary.
		 */
		public static function is_woocommerce_attribute_summary( $excerpt ) {
			if ( empty( $excerpt ) ) {
				return false;
			}

			// Check for attribute: value pattern
			// Common patterns: "Size: Large", "1: kids", "Color: Red, Size: Large"
			$patterns = array(
				// Numeric attribute names: "1: kids", "123: test" (short numeric followed by short word)
				'/^\d+:\s*\w+(\s*,\s*\d+:\s*\w+)*$/',
				// WooCommerce attribute prefixes: "pa_color: red"
				'/^pa_[a-zA-Z0-9_]+:\s*[a-zA-Z0-9_\-\s]+(\s*,\s*pa_[a-zA-Z0-9_]+:\s*[a-zA-Z0-9_\-\s]+)*$/',
				// Common attribute names (must be short and at start, followed by short values)
				'/^(size|color|colour|brand|material|style|type|gender|age_group|pattern|condition|mpn|gtin):\s*[a-zA-Z0-9_\-\s]{1,50}(\s*,\s*(size|color|colour|brand|material|style|type|gender|age_group|pattern|condition|mpn|gtin):\s*[a-zA-Z0-9_\-\s]{1,50})*$/i',
				// Single short attribute pattern (1-20 chars): "Material: Cotton" but NOT "This product has: great features"
				'/^[a-zA-Z0-9_]{1,20}:\s*[a-zA-Z0-9_\-\s]{1,30}(\s*,\s*[a-zA-Z0-9_]{1,20}:\s*[a-zA-Z0-9_\-\s]{1,30})*$/',
			);

			$trimmed_excerpt = trim( $excerpt );

			// Additional checks to exclude common sentence patterns (but only for longer text)
			if ( strlen( $trimmed_excerpt ) > self::WC_EXCERPT_LENGTH_THRESHOLD ) {
				$exclusion_patterns = array(
					'/\b(this|that|the|and|or|but|in|on|at|to|for|of|with|by|from|about|into|through|during|before|after|above|below|up|down|out|off|over|under|again|further|then|once|here|there|when|where|why|how|all|any|both|each|few|more|most|other|some|such|no|nor|not|only|own|same|so|than|too|very|can|will|just|don|should|now|has|have)\b/i',
				);

				// First check if it matches any exclusion patterns (common sentence words)
				foreach ( $exclusion_patterns as $exclusion_pattern ) {
					if ( preg_match( $exclusion_pattern, $trimmed_excerpt ) ) {
						return false;
					}
				}
			}

			// Then check if it matches attribute patterns
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $trimmed_excerpt ) ) {
					return true;
				}
			}

			return false;
		}
	}
endif;
