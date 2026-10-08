<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Event object.
 *
 * @since 2.0.0
 */
class Event {


	/**
	 * @var array data specific to this event instance with the same structure as the event's payload
	 *
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/payload-helper
	 */
	protected $data = array();


	/**
	 * Gets version information for pixel events.
	 *
	 * @return array {
	 *     @type string source 'woocommerce'
	 *     @type string version WooCommerce's version
	 *     @type string pluginVersion Meta for WooCommerce's version
	 * }
	 */
	public static function get_version_info() {
		$source  = 'woocommerce';
		$source .= self::get_agent_flags();
		return array(
			'source'        => $source,
			'version'       => WC()->version,
			'pluginVersion' => facebook_for_woocommerce()->get_version(),
		);
	}


	/**
	 * Gets the agent string for pixel events.
	 *
	 * @return string
	 */
	public static function get_platform_identifier() {
		$info = self::get_version_info();
		return "{$info['source']}-{$info['version']}-{$info['pluginVersion']}";
	}


	/**
	 * Constructor.
	 *
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/parameters
	 *
	 * @since 2.0.0
	 *
	 * @param array $data event data
	 */
	public function __construct( $data = array() ) {
		$this->prepare_data( $data );
	}


	/**
	 * Provides defaults for properties if not already defined.
	 *
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/parameters/server-event
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/parameters/custom-data
	 *
	 * @since 2.0.0
	 *
	 * @param array $data event data
	 */
	protected function prepare_data( $data ) {
		$defaults = array(
			'action_source'    => 'website',
			'event_time'       => time(),
			'event_id'         => $this->generate_event_id(),
			'event_source_url' => $this->get_current_url(),
			'custom_data'      => array(),
			'user_data'        => array(),
		);

		// A physical store sale did not happen on a page, so there is no source URL to report.
		if ( $this->is_physical_store_event( $data ) ) {
			unset( $defaults['event_source_url'] );
		}

		$this->data = wp_parse_args( $data, $defaults );

		if ( isset( $_SERVER['HTTP_REFERER'] ) && ! $this->is_physical_store_event( $this->data ) ) {
			$this->data['referrer_url'] = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}

		$this->prepare_user_data( $this->data['user_data'] );
	}


	/**
	 * Determines whether the given event data describes an in-store (offline) event.
	 *
	 * @since 3.7.7
	 *
	 * @param array $data event data
	 * @return bool
	 */
	protected function is_physical_store_event( $data ) {
		return isset( $data['action_source'] ) && 'physical_store' === $data['action_source'];
	}


	/**
	 * Determines whether this event describes an in-store (offline) sale.
	 *
	 * @since 3.7.7
	 *
	 * @return bool
	 */
	public function is_physical_store(): bool {
		return $this->is_physical_store_event( $this->data );
	}


	/**
	 * Provides defaults for user properties if not already defined.
	 *
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/parameters/user-data
	 *
	 * @since 2.0.0
	 *
	 * @param array $data user data
	 */
	protected function prepare_user_data( $data ) {
		// For a physical store sale these browser signals describe the till the order
		// was rung up on, not the customer, so reporting them would be actively wrong.
		$defaults = $this->is_physical_store_event( $this->data )
			? array()
			: array(
				'client_ip_address' => $this->get_client_ip(),
				'client_user_agent' => $this->get_client_user_agent(),
				'click_id'          => $this->get_click_id(),
				'browser_id'        => $this->get_browser_id(),
			);

		$this->data['user_data'] = wp_parse_args( $data, $defaults );
		// Country key is not the same in pixel and CAPI events, see:
		// https://developers.facebook.com/docs/facebook-pixel/advanced/advanced-matching
		// https://developers.facebook.com/docs/marketing-api/conversions-api/parameters
		if ( array_key_exists( 'cn', $this->data['user_data'] ) ) {
			$country                            = $this->data['user_data']['cn'];
			$this->data['user_data']['country'] = $country;
			unset( $this->data['user_data']['cn'] );
		}
		$this->data['user_data'] = Normalizer::normalize_array( $this->data['user_data'], false );
		$this->data['user_data'] = $this->hash_pii_data( $this->data['user_data'] );
	}

	/**
	 * Hashes the user data
	 *
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/parameters/user-data
	 *
	 * @since 2.0.3
	 *
	 * @param array $user_data user data
	 *
	 * @return array
	 */
	protected function hash_pii_data( $user_data ) {
		$keys_to_hash = array( 'em', 'fn', 'ln', 'ph', 'ct', 'st', 'zp', 'country', 'external_id' );
		foreach ( $keys_to_hash as $key ) {
			if ( array_key_exists( $key, $user_data ) ) {
				$user_data[ $key ] = hash( 'sha256', $user_data[ $key ], false );
			}
		}
		return $user_data;
	}

	/**
	 * Generates a UUIDv4 unique ID for the event.
	 *
	 * @see https://stackoverflow.com/a/15875555
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
// phpcs:disable
	protected function generate_event_id() {
		try {
			$data = random_bytes( 16 );
			$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // set version to 0100
			$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // set bits 6-7 to 10
			return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
		} catch ( \Exception $e ) {
			// fall back to mt_rand if random_bytes is unavailable
			return sprintf(
				'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
				// 32 bits for "time_low"
				mt_rand( 0, 0xffff ),
				mt_rand( 0, 0xffff ),
				// 16 bits for "time_mid"
				mt_rand( 0, 0xffff ),
				// 16 bits for "time_hi_and_version",
				// four most significant bits holds version number 4
				mt_rand( 0, 0x0fff ) | 0x4000,
				// 16 bits, 8 bits for "clk_seq_hi_res",
				// 8 bits for "clk_seq_low",
				// two most significant bits holds zero and one for variant DCE1.1
				mt_rand( 0, 0x3fff ) | 0x8000,
				// 48 bits for "node"
				mt_rand( 0, 0xffff ),
				mt_rand( 0, 0xffff ),
				mt_rand( 0, 0xffff )
			);
		}
	}
// phpcs:enable


	/**
	 * Gets the current URL.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	protected function get_current_url() {
		if ( wp_doing_ajax() ) {
			return wp_get_raw_referer();
		}

		/**
		 * Only the scheme, host and port of home_url() are used, so that the host is the
		 * one configured in site options rather than the HTTP_HOST server var. This also
		 * uses the correct domain when using Forward with the WooCommerce Dev Helper plugin.
		 *
		 * Any path or query string in home_url() is left out on purpose. On a site installed
		 * in a subdirectory, or with WPML serving languages from directories (/fr/) or from a
		 * ?lang= parameter, home_url() already carries that prefix and so does REQUEST_URI,
		 * so appending home_url() as a whole repeats it (https://example.com/fr//fr/page/).
		 */
		$home_url = home_url();
		$home     = wp_parse_url( $home_url );

		if ( empty( $home['host'] ) ) {
			// A home URL without a host has no origin to keep, so it is used as it is.
			$url = $home_url;
		} else {
			$scheme = isset( $home['scheme'] ) ? $home['scheme'] : ( is_ssl() ? 'https' : 'http' );
			$port   = isset( $home['port'] ) ? ':' . $home['port'] : '';
			$url    = $scheme . '://' . $home['host'] . $port;
		}

		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$url .= esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		return $url;
	}


	/**
	 * Gets the client IP address.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	protected function get_client_ip() {
		return \WC_Geolocation::get_ip_address();
	}


	/**
	 * Gets the client user agent.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_client_user_agent() {
		return ! empty( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}


	/**
	 * Gets the click ID from the cookie or the query parameter.
	 * In order, rely on:
	 * 1. If an _fbc cookie is set
	 * 2. If we have stored fbc in the session
	 * 3. The FBC result from param builder
	 * 4. Construct our own FBC
	 *
	 * The resulting value is stored in the session for future requests.
	 *
	 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/parameters/fbp-and-fbc#fbp-and-fbc-parameters
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	protected function get_click_id() {
		$fbc = \WC_Facebookcommerce_EventsTracker::get_fbc();

		if ( empty( $fbc ) ) {
			$cookie_fbc = ! empty( $_COOKIE['_fbc'] )
				? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) )
				: null;

			$request_fbclid = isset( $_GET['fbclid'] ) // phpcs:ignore WordPress.Security.NonceVerification
				? sanitize_text_field( wp_unslash( $_GET['fbclid'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
				: null;

			if ( $request_fbclid && ( empty( $cookie_fbc ) || self::has_fbclid_changed( $cookie_fbc, $request_fbclid ) ) ) {
				$creation_time = time();
				$fbc           = "fb.1.{$creation_time}.{$request_fbclid}";
			}

			if ( empty( $fbc ) && ! empty( $cookie_fbc ) ) {
				$fbc = $cookie_fbc;
			}

			if ( empty( $fbc ) && isset( $_SESSION['_fbc'] ) ) {
				$fbc = sanitize_text_field( $_SESSION['_fbc'] );
			}
		}

		if ( ! empty( $fbc ) && ! FacebookSignalsState::is_held() ) {
			$_SESSION['_fbc'] = $fbc;
		}

		// Return null instead of empty string
		return ! empty( $fbc ) ? $fbc : null;
	}


	/**
	 * Gets the browser ID from the cookie.
	 * In order, rely on:
	 * 1. If an _fbp cookie is set
	 * 2. If we have stored fbp in the session
	 * 3. The FBP result from param builder
	 *
	 * The resulting value is stored in the session for future requests.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	protected function get_browser_id() {
		$fbp = \WC_Facebookcommerce_EventsTracker::get_fbp();
		if ( empty( $fbp ) ) {
			if ( ! empty( $_COOKIE['_fbp'] ) ) {
				$fbp = sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) );
			} elseif ( ! empty( $_SESSION['_fbp'] ) ) {
				$fbp = $_SESSION['_fbp']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}
		if ( ! empty( $fbp ) && ! FacebookSignalsState::is_held() ) {
			$_SESSION['_fbp'] = $fbp;
		}
		// Return null instead of empty string
		return ! empty( $fbp ) ? $fbp : null;
	}


	/**
	 * Gets the data.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_data() {
		return $this->data;
	}


	/**
	 * Gets the event ID.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_id() {
		return ! empty( $this->data['event_id'] ) ? $this->data['event_id'] : '';
	}


	/**
	 * Gets the event name.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_name() {
		return ! empty( $this->data['event_name'] ) ? $this->data['event_name'] : '';
	}


	/**
	 * Gets the user data.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_user_data() {
		return ! empty( $this->data['user_data'] ) ? $this->data['user_data'] : array();
	}


	/**
	 * Gets the event custom data.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_custom_data() {
		return ! empty( $this->data['custom_data'] ) ? $this->data['custom_data'] : array();
	}


	/**
	 * Gets the postfix flags for the agent string.
	 *
	 * @since 3.5.5
	 *
	 * @return string
	 */
	private static function get_agent_flags() {
		$postfix = '';
		if ( function_exists( 'get_option' ) && false !== get_option( 'wc_facebook_svr_flags' ) ) {
			$flags   = get_option( 'wc_facebook_svr_flags' );
			$postfix = "_{$flags}";
		}
		return $postfix;
	}

	/**
	 * Checks whether the fbclid in the current request differs from
	 * the one stored in the existing _fbc cookie.
	 *
	 * @param string      $cookie_fbc     The current _fbc cookie value
	 *                                    (format: fb.{subdomain}.{timestamp}.{fbclid}).
	 * @param string|null $request_fbclid The fbclid from the current request,
	 *                                    or null if not present.
	 *
	 * @return bool True if fbclid has changed, false otherwise.
	 */
	private static function has_fbclid_changed( $cookie_fbc, $request_fbclid ) {
		if ( null === $request_fbclid ) {
			return false;
		}

		$parts = explode( '.', $cookie_fbc );
		if ( count( $parts ) < 4 ) {
			return true;
		}

		$cookie_fbclid = implode( '.', array_slice( $parts, 3 ) );
		return $cookie_fbclid !== $request_fbclid;
	}
}
