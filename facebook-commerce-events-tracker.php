<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Enums\ProductType;
use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Events\FacebookSignalsState;
use WooCommerce\Facebook\Events\POS\POS_Integration_Interface;
use WooCommerce\Facebook\Events\POS\POS_Integration_Registry;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Integrations\CostOfGoods\CostOfGoods;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Facebookcommerce_EventsTracker' ) ) :

	if ( ! class_exists( 'WC_Facebookcommerce_Utils' ) ) {
		include_once 'includes/fbutils.php';
	}

	if ( ! class_exists( 'WC_Facebookcommerce_Pixel' ) ) {
		include_once 'facebook-commerce-pixel-event.php';
	}

	/**
	 * Class WC_Facebookcommerce_EventsTracker
	 *
	 * This class is responsible for tracking events and sending them to Facebook.
	 */
	class WC_Facebookcommerce_EventsTracker {
		/** @var string URL for the client-side CAPI param builder script */
		const CAPI_PARAM_BUILDER_JS_URL = 'https://cdn.jsdelivr.net/npm/meta-capi-param-builder-clientjs/dist/clientParamBuilder.bundle.js';

		/** @var string Fallback URL for the client-side CAPI param builder script */
		const CAPI_PARAM_BUILDER_FALLBACK_JS_URL = 'https://unpkg.com/meta-capi-param-builder-clientjs/dist/clientParamBuilder.bundle.js';

		/** @var \WC_Facebookcommerce_Pixel instance */
		private $pixel;

		/** @var string name of the session variable used to store search event data */
		private $search_event_data_session_variable = 'wc_facebook_search_event_data';

		/** @var Event search event instance */
		private $search_event;

		/** @var array with events tracked */
		private $tracked_events;

		/** @var array array with epnding events */
		private $pending_events = array();

		/** @var AAMSettings aam settings instance, used to filter advanced matching fields*/
		private $aam_settings;

		/** @var bool whether the pixel should be enabled */
		private $is_pixel_enabled;

		/** @var CostOfGoods CostOfGoods provider instance. Used to calculate the profit margin */
		private $cogs_provider;

		/** @var POS_Integration_Registry point-of-sale integrations, used to recognise offline orders */
		private $pos_registry;

		/** @var array|null Pending pixel event data for Store API response */
		private $pending_store_api_pixel_event = null;

		/** @var Event|null Server-side CF7 Lead event, stored so its browser pixel code can be injected into the CF7 REST response */
		private $cf7_lead_event = null;

		/** @var array|null Reportable cart items, memoized per cart state so the clamp is only evaluated (and logged) once */
		private $reportable_cart_items = null;

		/** @var string|null Cart hash the memoized reportable cart items were built from */
		private $reportable_cart_items_hash = null;

		/**
		 * @var \FacebookAds\ParamBuilder|null shared ParamBuilder instance
		 */
		private static $param_builder = null;

		/** @var string|null Cached fbc value from ParamBuilder */
		private static $cached_fbc = null;

		/** @var string|null Cached fbp value from ParamBuilder */
		private static $cached_fbp = null;

		/**
		 * Order meta keys used by the tracker.
		 */
		const META_PURCHASE_TRACKED_BROWSER = '_meta_purchase_tracked_browser';
		const META_PURCHASE_TRACKED_SERVER  = '_meta_purchase_tracked_server';
		const META_OFFLINE_PURCHASE_TRACKED = '_meta_offline_purchase_tracked';
		const META_EVENT_ID                 = '_meta_event_id';

		/**
		 * Events tracker constructor.
		 *
		 * @param array       $user_info
		 * @param AAMSettings $aam_settings
		 */
		public function __construct( $user_info, $aam_settings ) {

			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			$this->pixel          = new \WC_Facebookcommerce_Pixel( $user_info );
			$this->aam_settings   = $aam_settings;
			$this->tracked_events = array();

			// Initialize external JS hooks early so script is enqueued before wp_enqueue_scripts fires.
			\WC_Facebookcommerce_Pixel::init_external_js_hooks();

			$this->param_builder_server_setup();
			$this->add_hooks();
			$this->cogs_provider = new CostOfGoods();
			$this->pos_registry  = new POS_Integration_Registry();
		}

		public static function get_param_builder() {
			if ( null === self::$param_builder ) {
				try {
					$site_url            = get_site_url();
					self::$param_builder = new \FacebookAds\ParamBuilder( array( $site_url ) );

					self::$param_builder->processRequest(
						$site_url,
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Param builder intentionally inspects current request query parameters.
						self::get_scalar_request_values( $_GET ),
						self::get_scalar_request_values( $_COOKIE ),
						isset( $_SERVER['HTTP_REFERER'] ) ?
						sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) :
						null
					);
				} catch ( \Throwable $exception ) {
					Logger::log(
						'Error initializing CAPI Parameter Builder: ' . $exception->getMessage(),
						array(),
						array(
							'should_send_log_to_meta' => true,
							'should_save_log_in_woocommerce' => true,
							'woocommerce_log_level'   => \WC_Log_Levels::ERROR,
						)
					);
				}
			}

			return self::$param_builder;
		}

		/**
		 * Returns a copy of a request superglobal that only contains scalar values.
		 *
		 * PHP parses `name[key]=value` cookies and query parameters into arrays. The CAPI
		 * Parameter Builder expects `_fbc`, `_fbp`, `_fbi` and `fbclid` to be strings and
		 * throws a TypeError otherwise, which would take the whole request down and trip the
		 * plugin crash handler. Array-shaped values are therefore dropped and treated as absent.
		 *
		 * @since 3.7.9
		 *
		 * @param mixed $values superglobal contents, e.g. $_GET or $_COOKIE.
		 * @return array<string, string>
		 */
		public static function get_scalar_request_values( $values ) {
			if ( ! is_array( $values ) ) {
				return array();
			}

			$scalar_values = array();
			foreach ( $values as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$scalar_values[ $key ] = (string) $value;
				}
			}

			return $scalar_values;
		}

		public static function get_fbc() {
			if ( null === self::$cached_fbc ) {
				$param_builder = self::get_param_builder();
				if ( $param_builder ) {
					self::$cached_fbc = $param_builder->getFbc();
				}
			}
			return self::$cached_fbc;
		}

		public static function get_fbp() {
			if ( null === self::$cached_fbp ) {
				$param_builder = self::get_param_builder();
				if ( $param_builder ) {
					self::$cached_fbp = $param_builder->getFbp();
				}
			}
			return self::$cached_fbp;
		}

		/**
		 * Initializes the CAPI server side Parameter Builder and sets cookies as needed.
		 */
		public function param_builder_server_setup() {
			try {

				if ( ! (bool) apply_filters( 'facebook_for_woocommerce_integration_pixel_enabled', true ) ) {
					return;
				}

				$param_builder = self::get_param_builder();

				// When signals are held, still run ParamBuilder so it can generate
				// attribution IDs, but do not write cookies until signals are released.
				if ( FacebookSignalsState::is_held() ) {
					$fbc = self::get_fbc();
					$fbp = self::get_fbp();

					if ( ! empty( $fbc ) ) {
						FacebookSignalsState::set_attribution_data( 'fbc', $fbc );
					}
					if ( ! empty( $fbp ) ) {
						FacebookSignalsState::set_attribution_data( 'fbp', $fbp );
					}

					foreach ( $param_builder->getCookiesToSet() as $cookie ) {
						if ( '_fbp' === $cookie->name ) {
							FacebookSignalsState::set_attribution_data( 'fbp_domain', $cookie->domain );
						} elseif ( '_fbc' === $cookie->name ) {
							FacebookSignalsState::set_attribution_data( 'fbc_domain', $cookie->domain );
						}
					}
				} else {
					$cookie_to_set = $param_builder->getCookiesToSet();

					if ( ! headers_sent() ) {
						foreach ( $cookie_to_set as $cookie ) {
							setcookie(
								$cookie->name,
								$cookie->value,
								time() + $cookie->max_age,
								'/',
								$cookie->domain
							);
						}
					}
				}
			} catch ( \Throwable $exception ) {
				Logger::log(
					'Error setting up server side CAPI Parameter Builder: ' . $exception->getMessage(),
					array(),
					array(
						'should_send_log_to_meta'        => true,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
					)
				);
			}
		}


		/**
		 * Determines whether the Pixel should be enabled.
		 *
		 * @since 2.2.0
		 *
		 * @return bool
		 */
		private function is_pixel_enabled() {

			if ( null === $this->is_pixel_enabled ) {

				/**
				 * Filters whether the Pixel should be enabled.
				 *
				 * @param bool $enabled default true
				 */
				$this->is_pixel_enabled = (bool) apply_filters( 'facebook_for_woocommerce_integration_pixel_enabled', true );
			}

			return $this->is_pixel_enabled;
		}


		/**
		 * Add events tracker hooks.
		 *
		 * @since 2.2.0
		 */
		private function add_hooks() {

			// inject Pixel
			add_action( 'wp_head', array( $this, 'inject_base_pixel' ) );
			add_action( 'wp_footer', array( $this, 'inject_base_pixel_noscript' ) );

			// set up CAPI Param Builder libraries
			add_action( 'wp_enqueue_scripts', array( $this, 'param_builder_client_setup' ) );

			// ViewContent for individual products
			add_action( 'woocommerce_after_single_product', array( $this, 'inject_view_content_event' ) );
			add_action( 'woocommerce_after_single_product', array( $this, 'maybe_inject_search_event' ) );

			// ViewCategory events
			add_action( 'woocommerce_after_shop_loop', array( $this, 'inject_view_category_event' ) );

			// Search events
			add_action( 'pre_get_posts', array( $this, 'inject_search_event' ) );
			add_filter( 'woocommerce_redirect_single_search_result', array( $this, 'maybe_add_product_search_event_to_session' ) );

			// AddToCart events
			add_action( 'woocommerce_add_to_cart', array( $this, 'inject_add_to_cart_event' ), 40, 4 );
			// AddToCart while AJAX is enabled (Classic WooCommerce)
			add_action( 'woocommerce_ajax_added_to_cart', array( $this, 'add_filter_for_add_to_cart_fragments' ) );
			// AddToCart for WooCommerce Blocks (Store API)
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				$this->register_store_api_endpoint_data();
			} else {
				add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_endpoint_data' ) );
			}
			// AddToCart while using redirect to cart page
			if ( 'yes' === get_option( 'woocommerce_cart_redirect_after_add', 'no' ) ) {
				add_action( 'wp_head', array( WC_Facebookcommerce_Utils::class, 'print_deferred_events' ) );
				add_action( 'shutdown', array( WC_Facebookcommerce_Utils::class, 'save_deferred_events' ) );
			}

			// InitiateCheckout events
			add_action( 'woocommerce_after_checkout_form', array( $this, 'inject_initiate_checkout_event' ) );

			// InitiateCheckout events for checkout block.
			add_action( 'woocommerce_blocks_checkout_enqueue_data', array( $this, 'inject_initiate_checkout_event' ) );

			// Purchase and Subscribe events
			add_action( 'woocommerce_new_order', array( $this, 'inject_purchase_event' ), 10, 2 );
			add_action( 'woocommerce_process_shop_order_meta', array( $this, 'inject_purchase_event' ), 20 );
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'inject_purchase_event' ), 30 );
			add_action( 'woocommerce_thankyou', array( $this, 'inject_purchase_event' ), 40 );

			// Offline Purchase events, reported once a point-of-sale order is paid.
			add_action( 'woocommerce_order_status_changed', array( $this, 'inject_offline_purchase_event_on_status_change' ), 10 );

			// Lead events through Contact Form 7 / WPForms.
			add_action( 'wpcf7_mail_sent', array( $this, 'track_cf7_lead_event' ), 10, 1 );
			add_filter( 'wpcf7_feedback_response', array( $this, 'inject_cf7_lead_event_pixel_code' ), 10, 2 );
			add_action( 'wp_footer', array( $this, 'inject_cf7_lead_event_listener' ), 20 );
			add_action( 'wpforms_process_complete', array( $this, 'track_wpforms_lead_event' ), 20, 3 );
			add_filter( 'wpforms_ajax_submit_success_response', array( $this, 'inject_wpforms_lead_event_ajax' ), 20, 3 );
			add_filter( 'wpforms_ajax_submit_redirect', array( $this, 'inject_wpforms_lead_event_ajax' ), 20, 3 );
			add_action( 'wp_footer', array( $this, 'inject_wpforms_ajax_listener' ), 20 );

			// Prevent stale Purchase tracking flags from being copied from subscriptions
			// onto renewal orders (which would make inject_purchase_event() skip CAPI).
			add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'exclude_purchase_tracking_meta_from_renewal_orders' ), 10, 3 );

			// Flush pending events on shutdown
			add_action( 'shutdown', array( $this, 'send_pending_events' ) );
		}


		/**
		 * Prints the base JavaScript pixel code
		 *
		 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		 */
		public function inject_base_pixel() {
			if ( $this->is_pixel_enabled() && ! $this->is_pos_page_request() ) {
				echo $this->pixel->pixel_base_code();
				$this->inject_page_view_event();
			}
		}


		/**
		 * Prints the base <noscript> pixel code.
		 *
		 * This is necessary to avoid W3 validation errors.
		 *
		 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		 */
		public function inject_base_pixel_noscript() {
			if ( $this->is_pixel_enabled() && ! $this->is_pos_page_request() ) {
				echo $this->pixel->pixel_base_code_noscript();
			}
		}

		/**
		 * Determines whether the current request renders a point-of-sale page.
		 *
		 * Point of sale pages (the till, and checkout screens it opens) are used by
		 * staff, not shoppers, so no storefront events are sent from them: no pixel,
		 * no PageView, no attribution cookies, no website Purchase. Offline events
		 * are unaffected, including one reported while such a page takes payment.
		 *
		 * @return bool
		 */
		private function is_pos_page_request() {
			return null !== $this->pos_registry && $this->pos_registry->is_pos_page_request();
		}

		/**
		 * Enqueues the Facebook CAPI Param Builder script.
		 */
		public function param_builder_client_setup() {
			// Client js setup
			if ( ! facebook_for_woocommerce()->get_connection_handler()->is_connected() ) {
				return;
			}

			// It would set attribution cookies on the till's browser, not a shopper's.
			if ( $this->is_pos_page_request() ) {
				return;
			}

			wp_enqueue_script(
				'facebook-capi-param-builder',
				self::CAPI_PARAM_BUILDER_JS_URL,
				array(),
				\WC_Facebookcommerce::PLUGIN_VERSION,
				true
			);
			// Add inline script that executes after the external script has loaded
			wp_add_inline_script(
				'facebook-capi-param-builder',
				'(function() {
					var processParams = function() {
						if (typeof clientParamBuilder !== "undefined" && !/(?:^|;\s*)wc_facebook_signals_state=held(?:;|$)/.test(document.cookie)) {
							clientParamBuilder.processAndCollectAllParams(window.location.href);
						}
					};
					if (typeof clientParamBuilder !== "undefined") {
						processParams();
						return;
					}
					var fallbackScript = document.createElement("script");
					fallbackScript.src = ' . wp_json_encode( self::CAPI_PARAM_BUILDER_FALLBACK_JS_URL ) . ';
					fallbackScript.onload = processParams;
					document.head.appendChild(fallbackScript);
				}());'
			);
		}

		/**
		 * Triggers the PageView event
		 */
		public function inject_page_view_event() {
			if ( ! $this->is_pixel_enabled() || $this->is_pos_page_request() ) {
				return;
			}

			$event_name = 'PageView';
			$event_data = array(
				'event_name' => $event_name,
				'user_data'  => $this->pixel->get_user_info(),
			);

			$event = new Event( $event_data );

			$this->send_api_event( $event, false );

			$event_data['event_id'] = $event->get_id();

			$this->pixel->inject_event( $event_name, $event_data );
		}


		/**
		 * Triggers ViewCategory for product category listings
		 */
		public function inject_view_category_event() {
			global $wp_query;

			if ( ! $this->is_pixel_enabled() || ! is_product_category() ) {
				return;
			}

			$products = array_values(
				array_map(
					function ( $post ) {
						return wc_get_product( $post );
					},
					$wp_query->posts
				)
			);

			// if any product is a variant, fire the pixel with
			// content_type: product_group
			$content_type = 'product';
			$product_ids  = array();
			$contents     = array();

			foreach ( $products as $product ) {

				if ( ! $product ) {
					continue;
				}

				$contents[] = array(
					'id'       => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product ),
					'quantity' => 1, // consider category results a quantity of 1
				);

				$product_ids = array_merge(
					$product_ids,
					WC_Facebookcommerce_Utils::get_fb_content_ids( $product )
				);
				if ( WC_Facebookcommerce_Utils::is_variable_type( $product->get_type() ) ) {
					$content_type = 'product_group';
				}
			}

			$category = get_queried_object();

			$event_name = 'ViewCategory';
			$event_data = array(
				'event_name'  => $event_name,
				'custom_data' => array(
					'content_name'     => $category->name,
					'content_category' => $category->name,
					'content_ids'      => wp_json_encode( array_slice( $product_ids, 0, 10 ) ),
					'content_type'     => $content_type,
					'contents'         => $contents,
				),
				'user_data'   => $this->pixel->get_user_info(),
			);

			$event = new Event( $event_data );

			$this->send_api_event( $event );

			$event_data['event_id'] = $event->get_id();

			$this->pixel->inject_event( $event_name, $event_data, 'trackCustom' );
		}


		/**
		 * Attempts to add a session variable to indicate that a product search event occurred.
		 *
		 * The session variable is used to catch search events that have a single result.
		 * In those cases WooCommerce redirects customers to the product page instead of showing the search results.
		 *
		 * The plugin can't inject a Pixel event code on redirect responses, but it can check for the presence of the variable on the product page.
		 *
		 * This method is hooked to woocommerce_redirect_single_search_result which is triggered right before redirecting.
		 *
		 * @internal
		 *
		 * @since 2.1.2
		 *
		 * @param bool $redirect whether to redirect to the product page
		 * @return bool
		 */
		public function maybe_add_product_search_event_to_session( $redirect ) {

			if ( $redirect && $this->search_event && $this->is_single_search_result() ) {

				$this->add_product_search_event_to_session( $this->search_event );
			}

			return $redirect;
		}


		/**
		 * Determines whether the current request is a product search with a single result.
		 *
		 * @since 2.1.2
		 *
		 * @return bool
		 */
		private function is_single_search_result() {
			global $wp_query;

			return is_search() && 1 === absint( $wp_query->found_posts ) && is_post_type_archive( 'product' );
		}


		/**
		 * Adds search event data to the session.
		 *
		 * This does nothing if there is no session set.
		 *
		 * @since 2.1.2
		 *
		 * @param Event $event
		 *
		 * @return void
		 */
		private function add_product_search_event_to_session( Event $event ) {

			if ( isset( WC()->session ) && is_callable( array( WC()->session, 'has_session' ) ) && WC()->session->has_session() ) {
				WC()->session->set( $this->search_event_data_session_variable, $event->get_data() );
			}
		}


		/**
		 * Injects a frontend search event if the session has stored event data.
		 *
		 * @internal
		 *
		 * @since 2.1.2
		 */
		public function maybe_inject_search_event() {

			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			$this->search_event = $this->get_product_search_event_from_session();

			if ( ! $this->search_event ) {
				return;
			}

			$this->delete_session_data( $this->search_event_data_session_variable );
			$this->actually_inject_search_event();
		}


		/**
		 * Attempts to create an Event instance for a product search event using session data.
		 *
		 * @since 2.1.2
		 *
		 * @return Event|null
		 */
		private function get_product_search_event_from_session() {

			if ( ! isset( WC()->session ) || ! is_callable( array( WC()->session, 'get' ) ) ) {
				return null;
			}

			$data = WC()->session->get( $this->search_event_data_session_variable );

			if ( ! is_array( $data ) || empty( $data ) ) {
				return null;
			}

			return new Event( $data );
		}


		/**
		 * Deletes a session variable.
		 *
		 * @since 2.1.2
		 *
		 * @param string $key name of the variable to delete
		 */
		private function delete_session_data( $key ) {

			if ( isset( WC()->session->$key ) ) {
				unset( WC()->session->$key );
			}
		}


		/**
		 * Triggers Search for result pages (deduped)
		 *
		 * @internal
		 *
		 * @param WP_Query $query the query object
		 */
		public function inject_search_event( $query ) {

			if ( ! $this->is_pixel_enabled() || ! $query->is_main_query() ) {
				return;
			}

			if ( ! is_admin() && is_search() && '' !== get_search_query() && 'product' === get_query_var( 'post_type' ) ) {

				if ( $this->pixel->is_last_event( 'Search' ) ) {
					return;
				}

				// needs to run before wc_template_redirect, normally hooked with priority 10
				add_action( 'template_redirect', array( $this, 'send_search_event' ), 5 );
				add_action( 'woocommerce_before_shop_loop', array( $this, 'actually_inject_search_event' ) );
			}
		}


		/**
		 * Sends a server-side Search event.
		 *
		 * @internal
		 *
		 * @since 2.0.0
		 */
		public function send_search_event() {

			$event = $this->get_search_event();

			if ( null === $event ) {
				return;
			}

			$this->send_api_event( $event );
		}


		/**
		 * Creates an Event instance to track a search request.
		 *
		 * The event instance is stored in memory to return a single instance per request.
		 *
		 * @since 2.0.0
		 *
		 * @return Event
		 */
		private function get_search_event() {
			global $wp_query;

			if ( null === $this->search_event ) {

				// if any product is a variant, fire the pixel with content_type: product_group
				$content_type = 'product';
				$product_ids  = array();
				$contents     = array();
				$total_value  = 0.00;

				if ( empty( $wp_query->posts ) ) {
					return null;
				}

				foreach ( $wp_query->posts as $post ) {

					$product = wc_get_product( $post );

					if ( ! $product instanceof \WC_Product ) {
						continue;
					}

					$product_ids = array_merge( $product_ids, WC_Facebookcommerce_Utils::get_fb_content_ids( $product ) );

					$contents[] = array(
						'id'       => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product ),
						'quantity' => 1, // consider the search results a quantity of 1
					);

					$total_value += (float) $product->get_price();

					if ( WC_Facebookcommerce_Utils::is_variable_type( $product->get_type() ) ) {
						$content_type = 'product_group';
					}
				}

				$event_data = array(
					'event_name'  => 'Search',
					'custom_data' => array(
						'content_type'  => $content_type,
						'content_ids'   => wp_json_encode( array_slice( $product_ids, 0, 10 ) ),
						'contents'      => $contents,
						'search_string' => get_search_query(),
						'value'         => Helper::number_format( $total_value ),
						'currency'      => get_woocommerce_currency(),
					),
					'user_data'   => $this->pixel->get_user_info(),
				);

				$this->search_event = new Event( $event_data );
			}

			return $this->search_event;
		}


		/**
		 * Injects a Search event on result pages.
		 *
		 * @internal
		 */
		public function actually_inject_search_event() {

			$event = $this->get_search_event();

			if ( null === $event ) {
				return;
			}

			$this->pixel->inject_event(
				$event->get_name(),
				array(
					'event_id'    => $event->get_id(),
					'event_name'  => $event->get_name(),
					'custom_data' => $event->get_custom_data(),
				)
			);
		}


		/**
		 * Triggers ViewContent event on product pages
		 *
		 * @internal
		 */
		public function inject_view_content_event() {
			global $post;

			if ( ! $this->is_pixel_enabled() || ! isset( $post->ID ) ) {
				return;
			}

			$product = wc_get_product( $post->ID );

			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			// if product is variable or grouped, fire the pixel with content_type: product_group
			if ( $product->is_type( array( 'variable', 'grouped' ) ) ) {
				$content_type = 'product_group';
			} else {
				$content_type = 'product';
			}

			if ( WC_Facebookcommerce_Utils::is_variable_type( $product->get_type() ) ) {
							$product_price = $product->get_variation_price( 'min' );
			} else {
				$product_price = $product->get_price();
			}

			$categories = \WC_Facebookcommerce_Utils::get_product_categories( $product->get_id() );

			$event_data = array(
				'event_name'  => 'ViewContent',
				'custom_data' => array(
					'content_name'     => \WC_Facebookcommerce_Utils::clean_string( $product->get_title() ),
					'content_ids'      => wp_json_encode( \WC_Facebookcommerce_Utils::get_fb_content_ids( $product ) ),
					'content_type'     => $content_type,
					'contents'         => wp_json_encode(
						array(
							array(
								'id'       => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product ),
								'quantity' => 1,
							),
						)
					),
					'content_category' => $categories['name'],
					'value'            => $product_price,
					'currency'         => get_woocommerce_currency(),
				),
				'user_data'   => $this->pixel->get_user_info(),
			);

			$event = new Event( $event_data );

			$this->send_api_event( $event );

			$event_data['event_id'] = $event->get_id();

			$this->pixel->inject_event( 'ViewContent', $event_data );
		}


		/**
		 * Gets the highest quantity we are willing to report for a product.
		 *
		 * WooCommerce places no upper bound on cart quantities on the classic path: products
		 * that do not manage stock short-circuit \WC_Product::has_enough_stock() to true, so a
		 * request carrying an oversized quantity is accepted as-is. PHP compounds this by
		 * saturating overflowing numeric strings at PHP_INT_MAX on cast, which is how we end
		 * up reporting quantities of 9223372036854775807 and conversion values of ~1e21.
		 *
		 * This deliberately uses a fixed ceiling rather than the Store API's
		 * QuantityLimits::get_add_to_cart_limits(), even though that would match Blocks exactly.
		 * That limit resolves to the product's remaining stock, and remaining stock is further
		 * reduced by ReserveStock to account for other customers' pending orders. The clamped
		 * quantity is echoed back to the browser in the pixel script, the add-to-cart fragment
		 * and the Store API cart response, so a stock-derived ceiling would let anyone read off
		 * exact inventory - and in-flight order volume - for any product just by adding an
		 * oversized quantity to their cart and reading the response. A constant reveals nothing.
		 *
		 * @since 3.7.7
		 *
		 * @param \WC_Product $product the product being reported
		 * @return int the maximum reportable quantity
		 */
		private function get_max_reportable_quantity( \WC_Product $product ) {

			// Matches the Store API's default ceiling, without consulting stock levels.
			$max = 9999;

			/**
			 * Filters the maximum quantity reported to Meta for a single product.
			 *
			 * Whatever this returns may be sent to the browser, so it must not be derived from
			 * information the store does not already expose publicly - stock levels in
			 * particular.
			 *
			 * @since 3.7.7
			 *
			 * @param int         $max the maximum reportable quantity
			 * @param \WC_Product $product the product being reported
			 */
			$max = (int) apply_filters( 'wc_facebook_max_reportable_quantity', $max, $product );

			return $max > 0 ? $max : 9999;
		}


		/**
		 * Clamps a quantity to the maximum we are willing to report for a product.
		 *
		 * @since 3.7.7
		 *
		 * @param int|float|string $quantity the quantity to clamp
		 * @param \WC_Product      $product the product being reported
		 * @return int|float the clamped quantity
		 */
		private function clamp_quantity( $quantity, \WC_Product $product ) {

			$quantity = is_numeric( $quantity ) ? $quantity + 0 : 0;
			$max      = $this->get_max_reportable_quantity( $product );

			if ( $quantity <= $max ) {
				return $quantity;
			}

			Logger::log(
				sprintf(
					'Clamped an out-of-range quantity before reporting to Meta: product_id=%s, quantity=%s, max=%s',
					$product->get_id(),
					$quantity,
					$max
				),
				array(
					'flow_name'  => 'pixel_events',
					'flow_step'  => 'clamp_quantity',
					'extra_data' => array(
						'product_id' => $product->get_id(),
						'quantity'   => (string) $quantity,
						'max'        => $max,
					),
				),
				array(
					'should_send_log_to_meta'        => true,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::WARNING,
					// A single abusive session clamps on every page view, and a bot walking the
					// catalogue clamps across thousands of products, so both limits are needed.
					'throttle'                       => array(
						'key'         => 'quantity_clamp:' . $product->get_id(),
						'interval'    => (int) apply_filters( 'wc_facebook_quantity_clamp_log_interval', HOUR_IN_SECONDS ),
						'group'       => 'quantity_clamp',
						'max_per_day' => (int) apply_filters( 'wc_facebook_quantity_clamp_log_daily_max', 10 ),
					),
				)
			);

			return $max;
		}


		/**
		 * Builds the custom data shared by every AddToCart event we send.
		 *
		 * @since 3.7.7
		 *
		 * @param \WC_Product      $product the product added to the cart
		 * @param int|float|string $quantity the added quantity, clamped before use
		 * @return array
		 */
		private function build_add_to_cart_custom_data( \WC_Product $product, $quantity ) {

			$quantity = $this->clamp_quantity( $quantity, $product );

			return array(
				'content_ids'  => wp_json_encode( \WC_Facebookcommerce_Utils::get_fb_content_ids( $product ) ),
				'content_name' => \WC_Facebookcommerce_Utils::clean_string( $product->get_title() ),
				'content_type' => 'product',
				'contents'     => wp_json_encode(
					array(
						array(
							'id'       => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product ),
							'quantity' => $quantity,
						),
					)
				),
				'value'        => (float) $product->get_price() * $quantity,
				'currency'     => get_woocommerce_currency(),
			);
		}


		/**
		 * Gets the cart items we are willing to report, with quantities clamped.
		 *
		 * Shared by the cart-derived event data so that contents, item count and total are all
		 * built from the same clamped quantities rather than diverging.
		 *
		 * @since 3.7.7
		 *
		 * @return array[] list of arrays with 'product', 'quantity' and 'clamped' keys
		 */
		private function get_reportable_cart_items() {

			$items = array();
			$cart  = WC()->cart;

			if ( ! $cart ) {
				return $items;
			}

			// Memoize per cart state: a single event reads this several times, and without a
			// cache an out-of-range quantity would be logged once per read.
			$hash = $cart->get_cart_hash();

			if ( null !== $this->reportable_cart_items && $hash === $this->reportable_cart_items_hash ) {
				return $this->reportable_cart_items;
			}

			foreach ( $cart->get_cart() as $item ) {

				if ( ! isset( $item['data'], $item['quantity'] ) || ! $item['data'] instanceof \WC_Product ) {
					continue;
				}

				$raw      = is_numeric( $item['quantity'] ) ? $item['quantity'] + 0 : 0;
				$quantity = $this->clamp_quantity( $raw, $item['data'] );

				$items[] = array(
					'product'  => $item['data'],
					'quantity' => $quantity,
					'clamped'  => $raw !== $quantity,
				);
			}

			$this->reportable_cart_items      = $items;
			$this->reportable_cart_items_hash = $hash;

			return $items;
		}


		/**
		 * Triggers an AddToCart event when a product is added to cart.
		 *
		 * @internal
		 *
		 * @param string $cart_item_key the cart item key
		 * @param int    $product_id the product identifier
		 * @param int    $quantity the added product quantity
		 * @param int    $variation_id the product variation identifier
		 */
		public function inject_add_to_cart_event( $cart_item_key, $product_id, $quantity, $variation_id ) {

			// bail if pixel tracking disabled or invalid variables
			if ( ! $this->is_pixel_enabled() || ! $product_id || ! $quantity ) {
				return;
			}

			/**
			 * Make sure to proceed only if the cart item exists.
			 * Some other plugins may clone the WC_Cart object. Calling clone APIs may trigger the 'woocommerce_add_to_cart' action.
			 * We want to make sure to proceed only if the cart item exists inside the original WC_Cart object.
			 */
			$cart = WC()->cart;
			if ( ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
				return;
			}
			// phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			$product = wc_get_product( $variation_id ?: $product_id );

			// bail if invalid product or error
			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			// Build custom_data once for reuse
			$custom_data = $this->build_add_to_cart_custom_data( $product, $quantity );

			$event_data = array(
				'event_name'  => 'AddToCart',
				'custom_data' => $custom_data,
				'user_data'   => $this->pixel->get_user_info(),
			);

			$event = new WooCommerce\Facebook\Events\Event( $event_data );

			$this->send_api_event( $event );

			// send the event ID to prevent duplication
			$event_data['event_id'] = $event->get_id();

			// store the ID in the session to be sent in AJAX JS event tracking as well
			WC()->session->set( 'facebook_for_woocommerce_add_to_cart_event_id', $event->get_id() );

			// Store pending pixel event for WooCommerce Blocks Store API.
			// Reuse custom_data and add event_id for deduplication.
			// NOTE: single-slot — if a batch request adds multiple products this gets
			// overwritten for each one and get_store_api_pixel_event_data() will only
			// return the last product's event. Fix: convert to an array and flush all.
			$pending_pixel_params                = array_merge( $custom_data, array( 'event_id' => $event->get_id() ) );
			$this->pending_store_api_pixel_event = array(
				'event'  => 'AddToCart',
				'params' => $pending_pixel_params,
			);

			$this->pixel->inject_event( 'AddToCart', $event_data );
		}


		/**
		 * Setups a filter to add an add to cart fragment whenever a product is added to the cart through Ajax.
		 *
		 * @see \WC_Facebookcommerce_EventsTracker::add_add_to_cart_event_fragment
		 *
		 * @internal
		 *
		 * @since 1.10.2
		 */
		public function add_filter_for_add_to_cart_fragments() {

			if ( 'no' === get_option( 'woocommerce_cart_redirect_after_add', 'no' ) ) {
				add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_add_to_cart_event_fragment' ) );
			}
		}


		/**
		 * Adds an add to cart fragment to trigger an AddToCart event.
		 *
		 * @internal
		 *
		 * @since 1.10.2
		 *
		 * @param array $fragments add to cart fragments
		 * @return array
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 */
		public function add_add_to_cart_event_fragment( $fragments ) {

			if ( ! $this->is_pixel_enabled() ) {
				return $fragments;
			}

			$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : '';
			$quantity   = isset( $_POST['quantity'] ) ? (int) $_POST['quantity'] : '';
			$product    = wc_get_product( $product_id );

			if ( ! $product instanceof \WC_Product || empty( $quantity ) ) {
				return $fragments;
			}

			$params = $this->build_add_to_cart_custom_data( $product, $quantity );

			// send the event ID to prevent duplication
			$event_id = WC()->session->get( 'facebook_for_woocommerce_add_to_cart_event_id' );
			if ( ! empty( $event_id ) ) {
				$params['event_id'] = $event_id;
			}

			$script = $this->pixel->get_event_script( 'AddToCart', $params );

			$fragments['div.wc-facebook-pixel-event-placeholder'] = '<div class="wc-facebook-pixel-event-placeholder">' . $script . '</div>';

			return $fragments;
		}


		/**
		 * Setups a filter to add an add to cart fragment to trigger an AddToCart event on added_to_cart JS event.
		 *
		 * This method is used by code snippets and should not be removed.
		 *
		 * @see \WC_Facebookcommerce_EventsTracker::add_conditional_add_to_cart_event_fragment
		 *
		 * @internal
		 *
		 * @since 1.10.2
		 */
		public function add_filter_for_conditional_add_to_cart_fragment() {

			if ( 'no' === get_option( 'woocommerce_cart_redirect_after_add', 'no' ) ) {
				add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_conditional_add_to_cart_event_fragment' ) );
			}
		}


		/**
		 * Adds an add to cart fragment to trigger an AddToCart event on added_to_cart JS event.
		 *
		 * @internal
		 *
		 * @since 1.10.2
		 *
		 * @param array $fragments add to cart fragments
		 * @return array
		 */
		public function add_conditional_add_to_cart_event_fragment( $fragments ) {

			if ( $this->is_pixel_enabled() ) {
				$params = array(
					'content_ids'  => $this->get_cart_content_ids(),
					'content_name' => $this->get_cart_content_names(),
					'content_type' => 'product',
					'contents'     => $this->get_cart_contents(),
					'value'        => $this->get_cart_total(),
					'currency'     => get_woocommerce_currency(),
				);

				// send the event ID to prevent duplication
				$event_id = WC()->session->get( 'facebook_for_woocommerce_add_to_cart_event_id' );
				if ( ! empty( $event_id ) ) {
					$params['event_id'] = $event_id;
				}

				$script = $this->pixel->get_conditional_one_time_event_script( 'AddToCart', $params, 'added_to_cart' );

				$fragments['div.wc-facebook-pixel-event-placeholder'] = '<div class="wc-facebook-pixel-event-placeholder">' . $script . '</div>';
			}

			return $fragments;
		}


		/**
		 * Registers endpoint data for WooCommerce Blocks Store API.
		 *
		 * This allows us to inject pixel event data into the Store API response
		 * so that pixel-events.js can read and fire the event for WooCommerce Blocks.
		 *
		 * @internal
		 *
		 * @since 3.x.x
		 */
		public function register_store_api_endpoint_data() {
			if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}

			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
					'namespace'       => 'facebook-for-woocommerce',
					'data_callback'   => array( $this, 'get_store_api_pixel_event_data' ),
					'schema_callback' => array( $this, 'get_store_api_pixel_event_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}


		/**
		 * Returns pixel event data for Store API response.
		 *
		 * Called by WooCommerce Blocks Store API to include our data in the cart response.
		 * The data is read and consumed by pixel-events.js.
		 *
		 * @internal
		 *
		 * @since 3.x.x
		 *
		 * @return array Pixel event data or empty array if no pending event.
		 */
		public function get_store_api_pixel_event_data() {
			if ( ! empty( $this->pending_store_api_pixel_event ) ) {
				$pending_event                       = $this->pending_store_api_pixel_event;
				$this->pending_store_api_pixel_event = null;
				return $pending_event;
			}

			return array();
		}


		/**
		 * Returns schema for pixel event data in Store API.
		 *
		 * @internal
		 *
		 * @since 3.x.x
		 *
		 * @return array Schema definition.
		 */
		public function get_store_api_pixel_event_schema() {
			return array(
				'event'  => array(
					'description' => __( 'Facebook Pixel event name', 'facebook-for-woocommerce' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'params' => array(
					'description' => __( 'Facebook Pixel event parameters including event_id for deduplication', 'facebook-for-woocommerce' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			);
		}


		/**
		 * Triggers an InitiateCheckout event when customer reaches checkout page.
		 *
		 * @internal
		 */
		public function inject_initiate_checkout_event() {

			if ( ! $this->is_pixel_enabled() || null === WC()->cart || WC()->cart->get_cart_contents_count() === 0 || $this->pixel->is_last_event( 'InitiateCheckout' ) ) {
				return;
			}

			$event_name = 'InitiateCheckout';
			$event_data = array(
				'event_name'  => $event_name,
				'custom_data' => array(
					'num_items'    => $this->get_cart_num_items(),
					'content_ids'  => $this->get_cart_content_ids(),
					'content_name' => $this->get_cart_content_names(),
					'content_type' => 'product',
					'contents'     => $this->get_cart_contents(),
					'value'        => $this->get_cart_total(),
					'currency'     => get_woocommerce_currency(),
				),
				'user_data'   => $this->pixel->get_user_info(),
			);

			// if there is only one item in the cart, send its first category
			$cart = WC()->cart;
			if ( ( $cart ) && count( $cart->get_cart() ) === 1 ) {

				$item = current( $cart->get_cart() );

				if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {

					$categories = \WC_Facebookcommerce_Utils::get_product_categories( $item['data']->get_id() );

					if ( ! empty( $categories['name'] ) ) {
						$event_data['custom_data']['content_category'] = $categories['name'];
					}
				}
			}

			$event = new Event( $event_data );

			$this->send_api_event( $event );

			$event_data['event_id'] = $event->get_id();

			$this->pixel->inject_event( $event_name, $event_data );
		}


		/**
		 * Excludes Meta Purchase tracking keys when Woo Subscriptions copies subscription meta
		 * to renewal orders.
		 *
		 * Without this, renewal orders may inherit `_meta_purchase_tracked_server` and
		 * `_meta_event_id`, causing inject_purchase_event() to treat them as already sent.
		 *
		 * @param array    $data copied data keyed by meta key.
		 * @param WC_Order $to_object target order (renewal order).
		 * @param WC_Order $from_object source object (subscription).
		 * @return array
		 */
		public function exclude_purchase_tracking_meta_from_renewal_orders( $data, $to_object, $from_object ) {
			// Guard to only affect subscription -> renewal order copies.
			if ( ! is_a( $to_object, 'WC_Order' ) || 'shop_order' !== $to_object->get_type() ) {
				return $data;
			}

			if ( ! is_a( $from_object, 'WC_Order' ) || 'shop_subscription' !== $from_object->get_type() ) {
				return $data;
			}

			unset( $data[ self::META_PURCHASE_TRACKED_SERVER ] );
			unset( $data[ self::META_PURCHASE_TRACKED_BROWSER ] );
			unset( $data[ self::META_EVENT_ID ] );

			return $data;
		}

		/**
		 * Determines whether a given order object should be tracked as a Purchase.
		 *
		 * We only track Purchases for actual WooCommerce orders (`shop_order`).
		 * Subscription objects (`shop_subscription`) are handled via Subscribe events.
		 *
		 * @param WC_Order $order order object.
		 * @return bool
		 */
		private function is_purchase_trackable_order( $order ) {
			return is_a( $order, 'WC_Order' ) && 'shop_order' === $order->get_type();
		}

		/**
		 * Determines whether Value Optimization is enabled for this store.
		 *
		 * VO is gated on a rollout switch rather than shipped on, so net revenue is
		 * only attached to Purchase events once Meta enables it for the store.
		 *
		 * @since 3.7.7
		 *
		 * @return bool
		 */
		private function is_value_optimization_enabled(): bool {
			$plugin = facebook_for_woocommerce();

			if ( ! $plugin || ! $plugin->get_rollout_switches() ) {
				return false;
			}

			return (bool) $plugin->get_rollout_switches()->is_switch_enabled(
				\WooCommerce\Facebook\RolloutSwitches::SWITCH_VALUE_OPTIMIZATION_ENABLED
			);
		}

		/**
		 * Determines whether a given order should be reported as an offline (physical store) event.
		 *
		 * We check if any of the supported POS integrations can detect this order.
		 *
		 * @param WC_Order $order order object.
		 * @return bool
		 */
		private function is_offline_event( $order ) {
			return null !== $this->pos_registry->match( $order );
		}

		/**
		 * Builds the custom data reported with a Purchase event for a given order.
		 *
		 * @param WC_Order $order order object.
		 * @return array
		 */
		private function get_custom_data( $order ) {

			// Seeded with an empty array so the array_merge() spread below still has
			// an argument when the order resolves to no products.
			$product_ids   = array( array() );
			$product_names = array();
			$contents      = array();
			$content_type  = 'product';

			foreach ( $order->get_items() as $item ) {

				$product = $item->get_product();

				if ( $product ) {
					$product_ids[]   = \WC_Facebookcommerce_Utils::get_fb_content_ids( $product );
					$product_names[] = \WC_Facebookcommerce_Utils::clean_string( $product->get_title() );

					if ( 'product_group' !== $content_type && $product->is_type( 'variable' ) ) {
						$content_type = 'product_group';
					}

					$content           = new \stdClass();
					$content->id       = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product );
					$content->quantity = $item->get_quantity();

					$contents[] = $content;
				}
			}

			return array(
				'content_ids'  => wp_json_encode( array_merge( ...$product_ids ) ),
				'content_name' => wp_json_encode( $product_names ),
				'contents'     => wp_json_encode( $contents ),
				'content_type' => $content_type,
				'value'        => $order->get_total(),
				'currency'     => ( method_exists( $order, 'get_currency' ) ? $order->get_currency() : get_woocommerce_currency() ),
				'order_id'     => $order->get_id(),
			);
		}

		/**
		 * Gets the user data reported with an offline (physical store) event.
		 *
		 * Built from the order alone. Unlike get_user_data_from_billing_address(), it
		 * does not start from the current user's details: on a point-of-sale request
		 * the current user is the cashier, not the customer, so any billing field the
		 * order left empty would otherwise be filled in with the cashier's.
		 *
		 * Returns nothing when the person on the order is a member of staff, since
		 * reporting their details would attribute the sale to them.
		 *
		 * The pixel's automatic advanced matching settings are deliberately not
		 * applied: they govern browser-side matching on the storefront, which an
		 * in-store sale has no part in.
		 *
		 * @param WC_Order                  $order       order object.
		 * @param POS_Integration_Interface $integration the point-of-sale integration that claimed the order.
		 * @return array
		 */
		private function get_user_data_for_offline_event( $order, $integration ) {
			if ( $this->is_staff_customer( $order, $integration ) ) {
				return array();
			}

			$user_data = array();
			self::update_array_if_not_null( $order->get_billing_first_name(), $user_data, 'fn' );
			self::update_array_if_not_null( $order->get_billing_last_name(), $user_data, 'ln' );
			self::update_array_if_not_null( $order->get_billing_email(), $user_data, 'em' );
			self::update_array_if_not_null( $order->get_billing_postcode(), $user_data, 'zp' );
			self::update_array_if_not_null( $order->get_billing_state(), $user_data, 'st' );
			self::update_array_if_not_null( $order->get_billing_country(), $user_data, 'country' );
			self::update_array_if_not_null( $order->get_billing_city(), $user_data, 'ct' );
			self::update_array_if_not_null( $order->get_billing_phone(), $user_data, 'ph' );

			// Walk-in sales with no customer account can carry "Guest" as the first
			// name. It names nobody, so it would only add noise to matching.
			if ( 0 === (int) $order->get_customer_id() && isset( $user_data['fn'] ) && $this->is_guest_placeholder_name( $user_data['fn'] ) ) {
				unset( $user_data['fn'] );
			}

			// Only a real customer account is an external ID; a guest order has none.
			if ( $order->get_customer_id() > 0 ) {
				$user_data['external_id'] = strval( $order->get_customer_id() );
			}

			return $user_data;
		}

		/**
		 * Determines whether a name is the "Guest" placeholder for a walk-in customer.
		 *
		 * WCPOS labels guest customers with __( 'Guest', 'woocommerce-pos' ), so the
		 * site's translation is matched as well as the English word. Case and
		 * surrounding whitespace are ignored.
		 *
		 * @param string $name the name to check.
		 * @return bool
		 */
		private function is_guest_placeholder_name( $name ) {
			$placeholders = array(
				'guest',
				// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- matching WCPOS's own translated label.
				wc_strtolower( __( 'Guest', 'woocommerce-pos' ) ),
			);

			return in_array( wc_strtolower( trim( $name ) ), $placeholders, true );
		}

		/**
		 * Determines whether the customer on an offline order is actually a member of staff.
		 *
		 * Point of sale plugins commonly attach the operator to walk-in sales (WCPOS does
		 * when its "default customer is cashier" setting is on). The customer counts as
		 * staff when they are the cashier who rang up the order, or can manage the store.
		 *
		 * The customer is the order's customer account or, for a guest order, the
		 * account matching its billing email, so staff details typed into a guest
		 * order are caught too.
		 *
		 * @param WC_Order                  $order       order object.
		 * @param POS_Integration_Interface $integration the point-of-sale integration that claimed the order.
		 * @return bool
		 */
		private function is_staff_customer( $order, $integration ) {
			$customer_id = (int) $order->get_customer_id();

			if ( 0 === $customer_id && $order->get_billing_email() ) {
				$user        = get_user_by( 'email', $order->get_billing_email() );
				$customer_id = $user ? (int) $user->ID : 0;
			}

			if ( 0 === $customer_id ) {
				return false;
			}

			$cashier_id = (int) $integration->get_cashier_id( $order );

			return ( $cashier_id > 0 && $customer_id === $cashier_id ) || user_can( $customer_id, 'manage_woocommerce' );
		}

		/**
		 * Builds the offline (physical store) Purchase event for a given order.
		 *
		 * @param WC_Order                  $order       order object.
		 * @param POS_Integration_Interface $integration the point-of-sale integration that claimed the order.
		 * @param array|null                $user_data   user data already built for the order, or null to build it.
		 * @return Event
		 */
		private function get_offline_event( $order, $integration, $user_data = null ) {
			$custom_data = array_merge(
				$this->get_offline_custom_data( $order ),
				$integration->get_event_data( $order )
			);

			$event_data = array(
				'event_name'    => 'Purchase',
				// When the sale happened, not when it was reported. Those differ
				// whenever payment and reporting are not in the same request.
				'event_time'    => $this->get_offline_sale_time( $order ),
				// Offline events carry the order ID at the top level of the event.
				'order_id'      => (string) $order->get_id(),
				'user_data'     => $user_data ?? $this->get_user_data_for_offline_event( $order, $integration ),
				'custom_data'   => $custom_data,
				'action_source' => 'physical_store',
			);

			$store_data = $this->sanitize_store_data( $integration->get_store_data( $order ) );

			if ( ! empty( $store_data ) ) {
				$event_data['store_data'] = $store_data;
			}

			return new Event( $event_data );
		}

		/**
		 * Keeps only valid store_data fields, in the types Meta expects.
		 *
		 * The store_page_id and brand_page_id fields are Facebook Page IDs (integers);
		 * store_code is a string of up to 64 characters. Anything else, or a value
		 * of the wrong shape, is dropped rather than altered: a truncated or coerced
		 * store code would attribute the sale to the wrong store.
		 *
		 * @param array $store_data store data reported by the point-of-sale integration.
		 * @return array
		 */
		private function sanitize_store_data( array $store_data ) {
			$sanitized = array();

			foreach ( array( 'store_page_id', 'brand_page_id' ) as $key ) {
				if ( isset( $store_data[ $key ] ) && ctype_digit( (string) $store_data[ $key ] ) && (int) $store_data[ $key ] > 0 ) {
					$sanitized[ $key ] = (int) $store_data[ $key ];
				}
			}

			if ( isset( $store_data['store_code'] ) && is_scalar( $store_data['store_code'] ) ) {
				$store_code = trim( (string) $store_data['store_code'] );

				if ( '' !== $store_code && strlen( $store_code ) <= 64 ) {
					$sanitized['store_code'] = $store_code;
				}
			}

			return $sanitized;
		}

		/**
		 * Builds the custom data for an offline (physical store) Purchase event.
		 *
		 * Unlike get_custom_data(), which the web path and the browser pixel share, the
		 * content fields are real arrays and the value is a number, as the Conversions
		 * API expects for offline events. The order ID is not included here; it goes at
		 * the top level of the event.
		 *
		 * @param WC_Order $order order object.
		 * @return array
		 */
		private function get_offline_custom_data( $order ) {
			$content_ids  = array();
			$contents     = array();
			$content_type = 'product';

			foreach ( $order->get_items() as $item ) {
				$product = $item->get_product();

				if ( ! $product ) {
					continue;
				}

				$content_ids = array_merge( $content_ids, \WC_Facebookcommerce_Utils::get_fb_content_ids( $product ) );

				if ( 'product_group' !== $content_type && $product->is_type( 'variable' ) ) {
					$content_type = 'product_group';
				}

				$contents[] = array(
					'id'       => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product ),
					'quantity' => (int) $item->get_quantity(),
				);
			}

			return array(
				'content_ids'  => array_values( array_unique( $content_ids ) ),
				'contents'     => $contents,
				'content_type' => $content_type,
				'value'        => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
			);
		}

		/**
		 * Gets when an offline (physical store) sale happened.
		 *
		 * The payment date where WooCommerce recorded one, otherwise the order's
		 * creation date, otherwise now.
		 *
		 * @param WC_Order $order order object.
		 * @return int Unix timestamp.
		 */
		private function get_offline_sale_time( $order ) {
			$date = $order->get_date_paid() ?? $order->get_date_created();

			return $date ? $date->getTimestamp() : time();
		}

		/**
		 * Tracks a Purchase event for an order taken at a physical point of sale.
		 *
		 * Server-side only: a point-of-sale order has no browser session, so there is
		 * no pixel event to deduplicate against and no shared event_id to maintain.
		 *
		 * The gates below, when the order is marked as reported, and what is sent are
		 * described for merchants and integration authors in docs/offline-events.md
		 * ("How an order is detected", "Order-level gates", "What the event contains"
		 * and "When an event is not sent"). Keep those sections in step when changing
		 * this flow, get_offline_event() or the offline user data.
		 *
		 * @param WC_Order $order order object.
		 */
		private function track_offline_purchase_event( $order ) {

			$integration = $this->pos_registry->match( $order );

			if ( ! $integration ) {
				return;
			}

			$order_id = $order->get_id();

			// Only report once payment has been taken. By then the order carries its
			// final customer, billing details and totals, so the event goes out with
			// everything available. Open, partially paid, pending and on-hold orders
			// are picked up later, when they become paid (see
			// inject_offline_purchase_event_on_status_change()). is_paid() also honours
			// the woocommerce_order_is_paid_statuses filter.
			if ( ! $order->is_paid() ) {
				return;
			}

			// Never report sales paid before the merchant opted in. Status changes on
			// older orders (processing to completed, say) would otherwise report them.
			if ( $this->get_offline_sale_time( $order ) < facebook_for_woocommerce()->get_integration()->get_offline_purchase_events_opted_in_at() ) {
				return;
			}

			// Return if this offline Purchase event has already been tracked for this order.
			if ( $order->meta_exists( self::META_OFFLINE_PURCHASE_TRACKED ) ) {
				return;
			}

			// Use a session flag to ensure this Purchase event is not tracked multiple times along multiple processes.
			$purchase_tracked_flag = '_wc_' . facebook_for_woocommerce()->get_id() . '_purchase_tracked_' . $order_id . '_offline';

			if ( 'yes' === get_transient( $purchase_tracked_flag ) ) {
				return;
			}

			// Meta rejects an event it has no way to match to a person (error subcode
			// 2804050), so don't send one yet. This runs before the order is marked as
			// tracked, so a later status change reports it once a customer is attached.
			$user_data = $this->get_user_data_for_offline_event( $order, $integration );

			if ( ! $this->has_matchable_customer_identifier( $user_data ) ) {
				Logger::log(
					'Offline Purchase event not sent for order ' . $order_id . ': it has no email, phone or customer account to match (POS: ' . $integration->get_slug() . '). It will be retried on the order\'s next status change.',
					array(),
					array(
						'should_send_log_to_meta'        => false,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::INFO,
					)
				);
				return;
			}

			// Claim the order while sending, so a concurrent request does not send it too.
			set_transient( $purchase_tracked_flag, 'yes', 45 * MINUTE_IN_SECONDS );

			$sent = $this->send_api_event( $this->get_offline_event( $order, $integration, $user_data ) );

			// Only a delivered event marks the order. If the request failed or Meta
			// rejected it, release the claim and leave the order unmarked, so the next
			// status change tries again rather than the sale being lost for good.
			if ( ! $sent ) {
				delete_transient( $purchase_tracked_flag );

				Logger::log(
					'Offline Purchase event for order ' . $order_id . ' was not delivered: the request failed or Meta rejected it (POS: ' . $integration->get_slug() . '). It will be retried on the order\'s next status change.',
					array(),
					array(
						'should_send_log_to_meta'        => false,
						'should_save_log_in_woocommerce' => true,
						'woocommerce_log_level'          => \WC_Log_Levels::WARNING,
					)
				);
				return;
			}

			// Prevent double-tracking of the event. A paid point-of-sale order can be
			// reported from woocommerce_new_order, while WooCommerce is still creating it.
			$order->add_meta_data( self::META_OFFLINE_PURCHASE_TRACKED, true, true );
			$this->save_tracking_meta_data( $order );

			Logger::log(
				'Offline Purchase event sent for order ' . $order_id . ' by hook ' . current_action() . ' (POS: ' . $integration->get_slug() . ').',
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::INFO,
				)
			);
		}

		/**
		 * Determines whether offline event user data can be matched to a person.
		 *
		 * Location fields alone (country, state, city, postcode) are too broad for
		 * Meta to accept; an email, a phone number or a customer account is needed.
		 *
		 * @param array $user_data user data, before hashing.
		 * @return bool
		 */
		private function has_matchable_customer_identifier( array $user_data ) {
			foreach ( array( 'em', 'ph', 'external_id' ) as $key ) {
				if ( ! empty( $user_data[ $key ] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Saves the tracking metadata added to an order.
		 *
		 * WooCommerce fires woocommerce_new_order partway through saving an order: when
		 * creating it, after inserting it and before saving its items (or, for a
		 * checkout draft, from the update). Saving anything on the order there is a
		 * recursive save, and save_meta_data() is no exception: with HPOS, a metadata
		 * change can turn into a full save() to update the modified date.
		 *
		 * So during woocommerce_new_order the metadata stays on the order object and is
		 * saved once WooCommerce has finished, on woocommerce_after_order_object_save.
		 * Until then the in-flight transient guards against duplicates. If the hook was
		 * fired directly rather than from a save, as some plugins do, no save follows,
		 * so the metadata is saved at shutdown instead.
		 *
		 * Anywhere else the metadata is saved straight away.
		 *
		 * @param \WC_Order $order order object.
		 */
		private function save_tracking_meta_data( \WC_Order $order ) {
			if ( ! doing_action( 'woocommerce_new_order' ) ) {
				$order->save_meta_data();
				return;
			}

			$save = static function () use ( $order ) {
				$order->save_meta_data();
			};

			$save_once_order_saved = static function ( $saved_order ) use ( $order, $save, &$save_once_order_saved ) {
				if ( $saved_order === $order ) {
					remove_action( 'woocommerce_after_order_object_save', $save_once_order_saved, 1 );
					remove_action( 'shutdown', $save );
					$save();
				}
			};

			add_action( 'woocommerce_after_order_object_save', $save_once_order_saved, 1 );
			add_action( 'shutdown', $save );
		}

		private function track_web_purchase_event( \WC_Order $order ) {

			$event_name                  = 'Purchase';
			$valid_purchase_order_states = array( 'processing', 'completed', 'on-hold', 'pending' );
			$order_id                    = $order->get_id();

			$hook_name = current_action();

			// Determine if this is a browser or server event.
			$is_browser = 'woocommerce_thankyou' === $hook_name;

			// Capture whether CAPI has already been sent before we set any meta.
			$capi_already_sent = $order->meta_exists( self::META_PURCHASE_TRACKED_SERVER );

			// If the event is triggered by a hook that is not related to the browser, it is a server event.
			$meta_flag = $is_browser ? self::META_PURCHASE_TRACKED_BROWSER : self::META_PURCHASE_TRACKED_SERVER;

			// Get the status of the order to ensure we track the actual purchases and not the ones that have a failed payment.
			$order_state = $order->get_status();
			if ( ! in_array( $order_state, $valid_purchase_order_states, true ) ) {
				return;
			}

			// Return if this Purchase event has already been tracked for this context (browser or server).
			if (
				( $is_browser && $order->meta_exists( self::META_PURCHASE_TRACKED_BROWSER ) ) ||
				( ! $is_browser && $order->meta_exists( self::META_PURCHASE_TRACKED_SERVER ) )
			) {
				return;
			}

			// Use a session flag to ensure this Purchase event is not tracked multiple times along multiple processes.
			$purchase_tracked_flag = '_wc_' . facebook_for_woocommerce()->get_id() . '_purchase_tracked_' . $order_id . '_' . ( $is_browser ? 'browser' : 'server' );

			// Return if this Purchase event has already been tracked.
			if ( 'yes' === get_transient( $purchase_tracked_flag ) ) {
				return;
			}

			// Ensure a single event_id is shared across browser and server for deduplication.
			$event_id = $order->get_meta( self::META_EVENT_ID );

			if ( empty( $event_id ) ) {
				$temp_event = new Event( [] );
				$event_id   = $temp_event->get_id();
				$order->add_meta_data( self::META_EVENT_ID, $event_id, true );
			}

			// Mark the order as tracked for the session.
			set_transient( $purchase_tracked_flag, 'yes', 45 * MINUTE_IN_SECONDS );

			// Mark the order as tracked for the context (browser or server).
			$order->add_meta_data( $meta_flag, true, true );

			// Persist only tracking metadata; do not recursively save an order that
			// WooCommerce is still creating or updating in woocommerce_new_order.
			$this->save_tracking_meta_data( $order );

			Logger::log(
				'Purchase event fired for order ' . $order_id . ' by hook ' . $hook_name . ' (context: ' . ( $is_browser ? 'browser' : 'server' ) . ').',
				array(),
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::INFO,
				)
			);

			// Advanced matching information is extracted from the order
			$event_data = array(
				'event_name'  => $event_name,
				'custom_data' => $this->get_custom_data( $order ),
				'user_data'   => $this->get_user_data_from_billing_address( $order ),
				'event_id'    => $event_id,
			);

			if ( $this->is_value_optimization_enabled() ) {
				$products = array();
				foreach ( $order->get_items() as $item ) {
					$product = $item->get_product();

					if ( $product ) {
						$products[] = array(
							'product' => $product,
							'qty'     => $item->get_quantity(),
						);
					}
				}
				$cogs = $this->cogs_provider->calculate_cogs_for_products( $products );

				if ( false !== $cogs ) {
					// get_total_tax() is already cart tax + shipping tax, so shipping tax
					// must not be subtracted a second time.
					$order_value_excluding_tax_including_discounts = $order->get_total()
						- $order->get_total_tax()
						- $order->get_shipping_total();

					$net_profit = $order_value_excluding_tax_including_discounts - $cogs;
					if ( $net_profit > 0 ) {
						$event_data['custom_data']['net_revenue'] = \WC_Facebookcommerce_Utils::truncate_float_number( $net_profit, 2 );
					}
				}
			}

			$event = new Event( $event_data );

			// Send CAPI event if not already sent. Events with the same event_id get deduplicated on Meta's side.
			if ( ! $capi_already_sent ) {
				$this->send_api_event( $event );
			}

			$this->pixel->inject_event( $event_name, $event_data );

			$this->inject_subscribe_event( $order_id );
		}

		/**
		 * Triggers a Purchase event when checkout is completed.
		 *
		 * This may happen either when:
		 * - WooCommerce signals a payment transaction complete (most gateways)
		 * - The order status is changed through the Woo dashboard to Processing or Completed
		 * - The Payment Completed event is fired, which happens in case of some external payment gateways.
		 * - Customer reaches Thank You page skipping payment (for gateways that do not require payment, e.g. Cheque, BACS, Cash on delivery...)
		 *
		 * The method checks if the event was not triggered already avoiding a duplicate.
		 * Finally, if the order contains subscriptions, it will also track an associated Subscription event.
		 *
		 * @internal
		 *
		 * @param int           $order_id Order identifier.
		 * @param WC_Order|null $order    Original order supplied by woocommerce_new_order, if available.
		 */
		public function inject_purchase_event( $order_id, $order = null ) {

			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			// New-order callbacks run before save_items(). The original object already
			// contains the items, while reloading it here can return an empty order.
			if ( ! $order instanceof \WC_Order || $order->get_id() !== (int) $order_id ) {
				$order = wc_get_order( $order_id );
			}

			if ( ! $order ) {
				return;
			}

			// Track Purchase only for checkout/renewal orders, not subscription posts.
			if ( ! $this->is_purchase_trackable_order( $order ) ) {
				return;
			}

			// A point-of-sale sale is never a website event, whether or not the merchant
			// has opted in to offline events. With the opt-in it is reported as an
			// offline event; without it, it is not reported at all. Before, an opted-out
			// store reported till sales as website Purchases, sent from the till's
			// browser and IP.
			//
			// The admin-user gate only applies to the web path: it exists to keep staff
			// browsing the storefront out of the pixel data, which has no bearing on a
			// server-side sale rung up at a cash registry.
			if ( $this->is_offline_event( $order ) ) {
				if ( facebook_for_woocommerce()->get_integration()->is_offline_purchase_events_enabled() ) {
					$this->track_offline_purchase_event( $order );
				}
				return;
			}

			// A point-of-sale page (e.g. its order-received screen) is not a storefront
			// visit, so it never reports a website Purchase either.
			if ( ! \WC_Facebookcommerce_Utils::is_admin_user() && ! $this->is_pos_page_request() ) {
				$this->track_web_purchase_event( $order );
			}
		}

		/**
		 * Reports an offline (physical store) Purchase when a point-of-sale order changes status.
		 *
		 * Point-of-sale orders are usually created before payment is taken — WCPOS opens
		 * them as `pos-open` — so woocommerce_new_order fires while the order is still
		 * unpaid, and none of the other purchase hooks run once payment completes.
		 * Listening to status changes catches payment_complete(), gateways that set the
		 * status directly, and staff marking an order paid in wp-admin.
		 *
		 * track_offline_purchase_event() only reports paid orders, and only once, so this
		 * can fire on every status change without duplicating the event. The web path is
		 * deliberately not run from here.
		 *
		 * @internal
		 *
		 * @param int $order_id order identifier.
		 */
		public function inject_offline_purchase_event_on_status_change( $order_id ) {

			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			// is_offline_event() does not check the opt-in, so it has to be checked here.
			if ( ! facebook_for_woocommerce()->get_integration()->is_offline_purchase_events_enabled() ) {
				return;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order || ! $this->is_purchase_trackable_order( $order ) ) {
				return;
			}

			if ( $this->is_offline_event( $order ) ) {
				$this->track_offline_purchase_event( $order );
			}
		}


		/**
		 * Triggers a Subscribe event when a given order contains subscription products.
		 *
		 * @see \WC_Facebookcommerce_EventsTracker::inject_purchase_event()
		 *
		 * @internal
		 *
		 * @param int $order_id order identifier
		 */
		public function inject_subscribe_event( $order_id ) {

			if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) || ! $this->is_pixel_enabled() || $this->pixel->is_last_event( 'Subscribe' ) ) {
				return;
			}

			foreach ( wcs_get_subscriptions_for_order( $order_id ) as $subscription ) {

				// TODO consider 'StartTrial' event for free trial Subscriptions, which is the same as here (minus sign_up_fee) and tracks "when a person starts a free trial of a product or service" {FN 2020-03-20}
				$event_name = 'Subscribe';

					// TODO: consider adding predicted_ltv for subscription events.
					$event_data = array(
						'event_name'  => $event_name,
						'custom_data' => array(
							'sign_up_fee' => $subscription->get_sign_up_fee(),
							'value'       => $subscription->get_total(),
							'currency'    => ( method_exists( $subscription, 'get_currency' ) ? $subscription->get_currency() : get_woocommerce_currency() ),
						),
						'user_data'   => $this->pixel->get_user_info(),
					);

					$event = new Event( $event_data );

					$this->send_api_event( $event );

					$event_data['event_id'] = $event->get_id();

					$this->pixel->inject_event( $event_name, $event_data );
			}
		}


		/** Contact Form 7 Lead Support **/

		/**
		 * Tracks a CF7 Lead event server-side (CAPI) when a form mail is successfully sent.
		 *
		 * CF7 submits via REST API (/wp-json/contact-form-7/v1/contact-forms/{id}/feedback),
		 * so wpcf7_mail_sent fires during a separate REST request — not during the page-load
		 * where wp_footer runs. We store the event here so inject_cf7_lead_event_pixel_code()
		 * can attach the matching browser pixel code (built via the existing pixel methods)
		 * to the REST response, keeping browser and CAPI events on the same event_id.
		 *
		 * @param \WPCF7_ContactForm $contact_form The CF7 form instance.
		 */
		public function track_cf7_lead_event( $contact_form ) {
			if ( ! $this->is_pixel_enabled() ) {
				return;
			}

			$event_data = array(
				'event_name'  => 'Lead',
				'user_data'   => $this->get_cf7_user_data(),
				'custom_data' => array(
					'fb_integration_tracking' => 'cf7',
				),
			);

			$event = new Event( $event_data );
			$this->send_api_event( $event );

			// Store so inject_cf7_lead_event_pixel_code() can read it.
			$this->cf7_lead_event = $event;
		}

		/**
		 * Extracts Advanced Matching user_data for a CF7 submission.
		 *
		 * Mirrors the WPForms approach: PII (email) is resolved server-side from the
		 * submitted form data and merged into the base user info, so it is included in
		 * the CAPI payload without ever exposing it to client-side JavaScript.
		 *
		 * @return array
		 */
		private function get_cf7_user_data() {
			$user_data = $this->pixel->get_user_info();
			$email     = $this->get_cf7_email();
			if ( ! empty( $email ) ) {
				$user_data['em'] = $email;
			}

			return $user_data;
		}

		/**
		 * Resolves the submitted email address from the current CF7 submission.
		 *
		 * @return string|null
		 */
		private function get_cf7_email() {
			if ( ! class_exists( 'WPCF7_Submission' ) ) {
				return null;
			}

			$submission = \WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				return null;
			}

			$posted_data = $submission->get_posted_data();
			if ( empty( $posted_data ) || ! is_array( $posted_data ) ) {
				return null;
			}

			foreach ( $posted_data as $key => $value ) {
				if ( false === strpos( (string) $key, 'email' ) ) {
					continue;
				}

				$value = is_array( $value ) ? reset( $value ) : $value;
				$email = sanitize_email( (string) $value );
				if ( ! empty( $email ) && is_email( $email ) ) {
					return $email;
				}
			}

			// Fallback: scan values for the first valid email address.
			foreach ( $posted_data as $value ) {
				$value = is_array( $value ) ? reset( $value ) : $value;
				$email = sanitize_email( (string) $value );
				if ( ! empty( $email ) && is_email( $email ) ) {
					return $email;
				}
			}

			return null;
		}

		/**
		 * Injects the browser Lead pixel code into the CF7 REST feedback response.
		 *
		 * Mirrors the WPForms AJAX approach ({@see inject_wpforms_lead_event_ajax()}):
		 * CF7 submits over its REST API, so we generate the pixel code with the existing
		 * {@see \WC_Facebookcommerce_Pixel::get_event_code()} method and attach it to the
		 * response as fb_pxl_code. The footer listener executes it once CF7 dispatches the
		 * wpcf7submit DOM event. The code carries the CAPI event_id so browser and server
		 * events deduplicate on Meta's side.
		 *
		 * @param array $response The CF7 REST response array.
		 * @param array $result   The CF7 submission result.
		 * @return array
		 */
		public function inject_cf7_lead_event_pixel_code( $response, $result ) {
			if ( $this->cf7_lead_event instanceof Event ) {
				$response['fb_pxl_code'] = $this->pixel->get_event_code( 'Lead', $this->build_cf7_lead_pixel_params( $this->cf7_lead_event ) );
			}
			return $response;
		}

		/**
		 * Adds a front-end listener that executes fb_pxl_code returned in the CF7 REST response.
		 *
		 * Mirrors {@see inject_wpforms_ajax_listener()}: a tiny bootstrap that runs the
		 * server-generated pixel code (built via the existing pixel methods) when CF7
		 * reports a successful submission, rather than re-implementing pixel logic in JS.
		 */
		public function inject_cf7_lead_event_listener() {
			if ( is_admin() || ! wp_script_is( 'contact-form-7', 'enqueued' ) ) {
				return;
			}
			?>
			<script type='text/javascript'>
			document.addEventListener( 'wpcf7submit', function ( event ) {
				var detail = event.detail || {};
				if ( 'mail_sent' !== detail.status ) {
					return;
				}
				var apiResponse = detail.apiResponse || {};
				if ( ! apiResponse.fb_pxl_code ) {
					return;
				}
				try {
					var s = document.createElement( 'script' );
					s.textContent = apiResponse.fb_pxl_code;
					document.head.appendChild( s );
				} catch ( e ) {
					// no-op
				}
			}, false );
			</script>
			<?php
		}

		/**
		 * Builds browser pixel params for the CF7 Lead event.
		 *
		 * Mirrors {@see build_wpforms_lead_pixel_params()}: shares the CAPI event_id for
		 * deduplication and forwards the server-resolved email for Advanced Matching.
		 *
		 * @param Event $event Lead event.
		 * @return array
		 */
		private function build_cf7_lead_pixel_params( Event $event ) {
			$params = array(
				'event_id' => $event->get_id(),
			);

			$user_data = $event->get_user_data();
			if ( is_array( $user_data ) && ! empty( $user_data['em'] ) ) {
				$params['user_data'] = array( 'em' => $user_data['em'] );
			}

			return $params;
		}

		/**
		 * Tracks WPForms Lead event for server-side delivery.
		 *
		 * Fires only after WPForms completes a successful entry save (wpforms_process_complete),
		 * so CAPI Lead events are not sent for invalid/rejected form submissions.
		 *
		 * @param array $fields     Sanitised field values.
		 * @param array $entry      Raw entry values.
		 * @param array $form_data  WPForms form config.
		 */
		public function track_wpforms_lead_event( $fields, $entry, $form_data ) {
			if ( ( is_admin() && ! wp_doing_ajax() ) || ! $this->is_pixel_enabled() ) {
				return;
			}

			$event_data = array(
				'event_name'  => 'Lead',
				'user_data'   => $this->get_wpforms_user_data( $entry, $form_data ),
				'custom_data' => array(
					'fb_integration_tracking' => 'wpforms-lite',
				),
			);

			$event = new Event( $event_data );
			$this->send_api_event( $event );

			// Non-AJAX fallback: inject matching browser event in footer.
			add_action( 'wp_footer', array( $this, 'inject_wpforms_lead_event' ), 20 );
		}

		/**
		 * Injects browser Lead event in normal (non-AJAX) page flow.
		 */
		public function inject_wpforms_lead_event() {
			if ( is_admin() ) {
				return;
			}

			$event = $this->get_latest_tracked_wpforms_lead_event();
			if ( ! $event ) {
				return;
			}

			echo $this->pixel->get_event_script( 'Lead', $this->build_wpforms_lead_pixel_params( $event ) );
		}

		/**
		 * Injects WPForms Lead pixel code into AJAX success/redirect response payload.
		 *
		 * @param array $response Existing WPForms response.
		 * @return array
		 */
		public function inject_wpforms_lead_event_ajax( $response ) {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $response;
			}

			$event = $this->get_latest_tracked_wpforms_lead_event();
			if ( ! $event ) {
				return $response;
			}

			$response['fb_pxl_code'] = $this->pixel->get_event_code( 'Lead', $this->build_wpforms_lead_pixel_params( $event ) );
			return $response;
		}

		/**
		 * Adds a front-end listener to execute fb_pxl_code returned in WPForms AJAX responses.
		 */
		public function inject_wpforms_ajax_listener() {
			if ( is_admin() || ! wp_script_is( 'wpforms', 'enqueued' ) ) {
				return;
			}
			?>
			<script type='text/javascript'>
			(function ( $ ) {
				if ( ! $ || typeof document === 'undefined' ) {
					return;
				}
				$( document ).on( 'wpformsAjaxSubmitSuccess', function ( event, data ) {
					if ( data && data.data && data.data.fb_pxl_code ) {
						try {
							var s = document.createElement( 'script' );
							s.textContent = data.data.fb_pxl_code;
							document.head.appendChild( s );
						} catch ( e ) {
							// no-op
						}
					}
				} );
			})( window.jQuery );
			</script>
			<?php
		}

		/**
		 * Gets latest tracked WPForms Lead event from current request.
		 *
		 * @return Event|null
		 */
		private function get_latest_tracked_wpforms_lead_event() {
			if ( empty( $this->tracked_events ) ) {
				return null;
			}

			$lead_events = array_values(
				array_filter(
					$this->tracked_events,
					function ( $event ) {
						if ( ! $event instanceof Event ) {
							return false;
						}

						$event_name = $event->get_name();
						if ( 'Lead' !== $event_name ) {
							return false;
						}

						$custom_data = $event->get_custom_data();
						return is_array( $custom_data )
							&& isset( $custom_data['fb_integration_tracking'] )
							&& 'wpforms-lite' === $custom_data['fb_integration_tracking'];
					}
				)
			);

			if ( empty( $lead_events ) ) {
				return null;
			}

			return end( $lead_events );
		}

		/**
		 * Builds browser pixel params for WPForms Lead event.
		 *
		 * @param Event $event Lead event.
		 * @return array
		 */
		private function build_wpforms_lead_pixel_params( Event $event ) {
			$params = array(
				'event_id' => $event->get_id(),
			);

			$user_data = $event->get_user_data();
			if ( is_array( $user_data ) && ! empty( $user_data['em'] ) ) {
				$params['user_data'] = array( 'em' => $user_data['em'] );
			}

			return $params;
		}

		/**
		 * Extracts WPForms user_data payload for Lead tracking.
		 *
		 * @param array $entry WPForms entry values.
		 * @param array $form_data WPForms form config.
		 * @return array
		 */
		private function get_wpforms_user_data( $entry, $form_data ) {
			$user_data = $this->pixel->get_user_info();
			$email     = $this->get_wpforms_email( $entry, $form_data );
			if ( ! empty( $email ) ) {
				$user_data['em'] = $email;
			}

			return $user_data;
		}

		/**
		 * Tries to resolve submitted WPForms email from form schema and entry values.
		 *
		 * @param array $entry WPForms entry values.
		 * @param array $form_data WPForms form config.
		 * @return string|null
		 */
		private function get_wpforms_email( $entry, $form_data ) {
			if ( empty( $entry ) || empty( $form_data['fields'] ) ) {
				return null;
			}

			foreach ( $form_data['fields'] as $field ) {
				if ( isset( $field['type'] ) && 'email' === $field['type'] ) {
					$field_id = isset( $field['id'] ) ? (string) $field['id'] : null;
					if ( $field_id && isset( $entry[ $field_id ] ) ) {
						$value = sanitize_email( (string) $entry[ $field_id ] );
						if ( ! empty( $value ) ) {
							return $value;
						}
					}

					if ( $field_id && isset( $entry['fields'] ) && is_array( $entry['fields'] ) && isset( $entry['fields'][ $field_id ] ) ) {
						$value = sanitize_email( (string) $entry['fields'][ $field_id ] );
						if ( ! empty( $value ) ) {
							return $value;
						}
					}
				}
			}

			// Fallback for unexpected entry shapes.
			foreach ( (array) $entry as $value ) {
				if ( is_array( $value ) ) {
					continue;
				}
				$email = sanitize_email( (string) $value );
				if ( ! empty( $email ) && is_email( $email ) ) {
					return $email;
				}
			}

			return null;
		}


		/**
		 * Sends an API event.
		 *
		 * Returns whether the event can be considered delivered. That is false when it
		 * was not sent (a crawler, a known-invalid connection, signals held), when the
		 * request failed, or when Meta returned an error. It is true when Meta accepted
		 * it, and also when the outcome cannot be known: a deferred send, or
		 * non-blocking sending, which does not wait for the response.
		 *
		 * @since 2.0.0
		 *
		 * @param Event $event event object
		 * @param bool  $send_now optional, defaults to true
		 * @return bool
		 */
		protected function send_api_event( Event $event, bool $send_now = true ) {
			if ( $this->is_crawler_request( $event ) ) {
				facebook_for_woocommerce()->log( 'Blocked CAPI event for crawler: ' . $event->get_client_user_agent() );
				return false;
			}

			// Skip CAPI events when the connection is known-invalid (auth error detected).
			// Sending events with an invalid token generates Graph API errors with no chance
			// of success, so we suppress them until the merchant reconnects.
			if ( get_transient( 'wc_facebook_connection_invalid' ) ) {
				return false;
			}

			$this->tracked_events[] = $event;

			// Skip CAPI sends for frontend requests while signals are held.
			// Backend/cron events (e.g. admin order status changes) still fire, and so
			// do in-store sales: the hold tracks a web visitor's consent state, but a
			// sale rung up at a till has no browser session that consent could describe.
			// Holding one would also strand it, since the queue is released by a
			// storefront AJAX call a point-of-sale terminal never makes.
			if ( FacebookSignalsState::should_suppress_frontend_send() && ! $event->is_physical_store() ) {
				FacebookSignalsState::queue_event( $event );
				return false;
			}

			if ( ! $send_now ) {
				$this->pending_events[] = $event;
				return true;
			}

			try {
				$response = facebook_for_woocommerce()->get_api()->send_pixel_events( facebook_for_woocommerce()->get_integration()->get_facebook_pixel_id(), array( $event ) );
			} catch ( ApiException $exception ) {
				facebook_for_woocommerce()->log( 'Could not send Pixel event: ' . $exception->getMessage() );
				return false;
			}

			// No response means non-blocking sending is on: the request was fired without
			// waiting for the answer, so there is nothing to check.
			if ( null === $response ) {
				return true;
			}

			// Graph API errors other than rate limits come back as an error response
			// rather than an exception, so the response itself has to be checked.
			return ! $response->has_api_error();
		}


		/**
		 * Determines whether the current request is from a known crawler.
		 *
		 * Checks the User-Agent header against a list of known crawler identifiers
		 * to prevent server-side events from firing for non-human traffic.
		 * This helps close the gap between pixel and CAPI event counts,
		 * since crawlers trigger CAPI events but never execute client-side JavaScript.
		 *
		 * @since 3.3.0
		 *
		 * @param Event $event Event object to read the User-Agent from.
		 * @return bool
		 */
		private function is_crawler_request( Event $event ): bool {
			$user_agent = strtolower( $event->get_client_user_agent() );

			$crawler_patterns = array(
				// Meta crawlers.
				'meta-externalagent',
				'meta-externalads',
				'meta-webindexer',
				// Generic crawler identifier.
				'crawler',
			);

			/**
			 * Filters the list of crawler user-agent patterns.
			 *
			 * @since 3.3.0
			 *
			 * @param string[] $crawler_patterns Array of lowercase crawler identifier strings to match against the User-Agent.
			 */
			$crawler_patterns = apply_filters( 'wc_facebook_crawler_user_agent_patterns', $crawler_patterns );

			foreach ( $crawler_patterns as $pattern ) {
				if ( false !== strpos( $user_agent, $pattern ) ) {
					return true;
				}
			}

			/**
			 * Filters whether the current request should be treated as a crawler request.
			 *
			 * @since 3.3.0
			 *
			 * @param bool   $is_crawler Whether the request is from a crawler. Default false after pattern checks pass.
			 * @param string $user_agent The request User-Agent string.
			 */
			return (bool) apply_filters( 'wc_facebook_is_crawler_request', false, $user_agent );
		}

		/**
		 * Gets the cart content items count.
		 *
		 * @since 1.10.2
		 *
		 * @return int
		 */
		private function get_cart_num_items() {

			$count = 0;

			foreach ( $this->get_reportable_cart_items() as $item ) {
				$count += $item['quantity'];
			}

			return $count;
		}


		/**
		 * Gets all content IDs from cart.
		 *
		 * @since 1.10.2
		 *
		 * @return string JSON data
		 */
		private function get_cart_content_ids() {

			$product_ids = array( array() );

			foreach ( $this->get_reportable_cart_items() as $item ) {
				$product_ids[] = \WC_Facebookcommerce_Utils::get_fb_content_ids( $item['product'] );
			}

			return wp_json_encode( array_unique( array_merge( ...$product_ids ) ) );
		}


		/**
		 * Gets all content names from cart.
		 *
		 * @since 2.0.0
		 *
		 * @return string JSON data
		 */
		private function get_cart_content_names() {

			$product_names = array();

			foreach ( $this->get_reportable_cart_items() as $item ) {
				$product_names[] = \WC_Facebookcommerce_Utils::clean_string( $item['product']->get_title() );
			}

			return wp_json_encode( array_unique( $product_names ) );
		}


		/**
		 * Gets the cart content data.
		 *
		 * @since 1.10.2
		 *
		 * @return string JSON data
		 */
		private function get_cart_contents() {

			$cart_contents = array();

			foreach ( $this->get_reportable_cart_items() as $item ) {

				$content = new \stdClass();

				$content->id       = \WC_Facebookcommerce_Utils::get_fb_retailer_id( $item['product'] );
				$content->quantity = $item['quantity'];

				$cart_contents[] = $content;
			}

			return wp_json_encode( $cart_contents );
		}


		/**
		 * Gets the cart total.
		 *
		 * Normally this is WooCommerce's own total, which accounts for tax, shipping, fees and
		 * discounts. When a quantity has been clamped that total describes a cart we are no
		 * longer reporting, so it is recomputed from the clamped line items instead. That loses
		 * tax and shipping, but only for carts whose total was meaningless to begin with.
		 *
		 * @return float|int
		 */
		private function get_cart_total() {

			$cart = WC()->cart;

			if ( ! $cart ) {
				return 0;
			}

			$items   = $this->get_reportable_cart_items();
			$clamped = false;

			foreach ( $items as $item ) {
				if ( $item['clamped'] ) {
					$clamped = true;
					break;
				}
			}

			if ( ! $clamped ) {
				return $cart->total;
			}

			$total = 0;

			foreach ( $items as $item ) {
				$total += (float) $item['product']->get_price() * $item['quantity'];
			}

			return $total;
		}

		/**
		 * Gets advanced matching information from a given order
		 *
		 * @since 2.0.3
		 *
		 * @param \WC_Order $order
		 *
		 * @return array
		 */
		private function get_user_data_from_billing_address( $order ) {
			if ( null === $this->aam_settings || ! $this->aam_settings->get_enable_automatic_matching() ) {
				return array();
			}
			$user_data = $this->pixel->get_user_info();
			self::update_array_if_not_null( $order->get_billing_first_name(), $user_data, 'fn' );
			self::update_array_if_not_null( $order->get_billing_last_name(), $user_data, 'ln' );
			self::update_array_if_not_null( $order->get_billing_email(), $user_data, 'em' );
			self::update_array_if_not_null( $order->get_billing_postcode(), $user_data, 'zp' );
			self::update_array_if_not_null( $order->get_billing_state(), $user_data, 'st' );
			self::update_array_if_not_null( $order->get_billing_country(), $user_data, 'country' );
			self::update_array_if_not_null( $order->get_billing_city(), $user_data, 'ct' );
			self::update_array_if_not_null( $order->get_billing_phone(), $user_data, 'ph' );
			self::update_array_if_not_null( strval( $order->get_user_id() ), $user_data, 'external_id' );

			foreach ( $user_data as $field => $value ) {
				if ( null === $value || '' === $value ||
					! in_array( $field, $this->aam_settings->get_enabled_automatic_matching_fields(), true )
				) {
					unset( $user_data[ $field ] );
				}
			}

			return $user_data;
		}

		/**
		 * Checks the value, if it's not null, updates the target array entry.
		 *
		 * @param mixed  $value        Value to set when not empty.
		 * @param array  $target_array Target array to update.
		 * @param string $array_key    Target array key.
		 */
		private static function update_array_if_not_null( $value, &$target_array, $array_key ) {
			if ( ! empty( $value ) ) {
				$target_array[ $array_key ] = $value;
			}
		}

		/**
		 * Gets the events tracked by this object
		 *
		 * @return array
		 */
		public function get_tracked_events() {
			return $this->tracked_events;
		}

		/**
		 * Gets the pending events awaiting to be sent
		 *
		 * @return array
		 */
		public function get_pending_events() {
			return $this->pending_events;
		}

		/**
		 * Send pending events.
		 */
		public function send_pending_events() {

			// Don't batch-send pending events while signals are held.
			if ( FacebookSignalsState::should_suppress_frontend_send() ) {
				return;
			}

			$pending_events = $this->get_pending_events();

			if ( empty( $pending_events ) ) {
				return;
			}

			foreach ( $pending_events as $event ) {

				$this->send_api_event( $event );
			}
		}
	}

endif;
