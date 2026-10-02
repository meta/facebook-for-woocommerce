<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Events\FacebookSignalsState;

/**
 * Class WC_Facebookcommerce_Pixel
 *
 * This class initializes the Facebook Pixel and provides methods to track events.
 */
class WC_Facebookcommerce_Pixel {


		const SETTINGS_KEY     = 'facebook_config';
		const PIXEL_ID_KEY     = 'pixel_id';
		const USE_PII_KEY      = 'use_pii';
		const USE_S2S_KEY      = 'use_s2s';
		const ACCESS_TOKEN_KEY = 'access_token';

		/**
		 * Cache key for pixel script block output.
		 *
		 * @var string cache key.
		 * */
		const PIXEL_RENDER = 'pixel_render';

		/**
		 * Cache key for pixel noscript block output.
		 *
		 * @var string cache key.
		 * */
		const NO_SCRIPT_RENDER = 'no_script_render';

		/**
		 * Script render memoization helper.
		 *
		 * @var array Cache array.
		 */
	public static $render_cache = [];

	/**
	 * Queued pixel events for isolated script execution.
	 *
	 * Events are collected here and output via wp_localize_script() to an external
	 * JS file, preventing errors from other plugins breaking pixel tracking.
	 *
	 * @var array Queued events array.
	 */
	private static $event_queue = [];

	/**
	 * Whether external script has been enqueued.
	 *
	 * @var bool
	 */
	private static $script_enqueued = false;

	/**
	 * Whether hooks have been initialized.
	 *
	 * @var bool
	 */
	private static $hooks_initialized = false;

		/**
		 * User information.
		 *
		 * @var array Information array.
		 */
		private $user_info;

		/**
		 * The name of the last event.
		 *
		 * @var string Event name.
		 */
		private $last_event;

		/**
		 * Class constructor.
		 *
		 * @param array $user_info User information array.
		 */
	public function __construct( $user_info = [] ) {
		$this->user_info  = $user_info;
		$this->last_event = '';
	}

	/**
	 * Initialize hooks for external JavaScript event handling.
	 * Uses wp_localize_script() + external JS file to prevent JavaScript errors
	 * from other plugins breaking our pixel tracking.
	 */
	public static function init_external_js_hooks() {
		if ( self::$hooks_initialized ) {
			return;
		}

		self::$hooks_initialized = true;

		// Deferred events from previous page are loaded via WC_Facebookcommerce_Utils::print_deferred_events()
		// which is hooked to wp_head in facebook-commerce-events-tracker.php.

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_pixel_events_script' ) );

		// Pass event data to JavaScript before footer scripts.
		add_action( 'wp_footer', array( __CLASS__, 'localize_pixel_events_data' ), 5 );
	}

	/**
	 * Enqueues the external pixel events script.
	 * External script runs in isolated context - not affected by other plugin errors.
	 */
	public static function enqueue_pixel_events_script() {
		if ( self::$script_enqueued ) {
			return;
		}

		$pixel_id = self::get_pixel_id();
		if ( empty( $pixel_id ) ) {
			return;
		}

		self::$script_enqueued = true;

		wp_enqueue_script(
			'wc-facebook-pixel-events',
			plugins_url( 'assets/js/frontend/pixel-events.js', __FILE__ ),
			array(),
			WC_Facebookcommerce_Utils::PLUGIN_VERSION,
			true  // Load in footer, after fbq is initialized.
		);
	}

	/**
	 * Passes queued event data to the frontend JavaScript.
	 * Uses wp_localize_script() to pass data (not code) to the external script.
	 */
	public static function localize_pixel_events_data() {
		if ( ! self::$script_enqueued || empty( self::$event_queue ) ) {
			return;
		}

		$pixel_id = self::get_pixel_id();

		wp_localize_script(
			'wc-facebook-pixel-events',
			'wc_facebook_pixel_data',
			array(
				'pixelId'     => esc_js( $pixel_id ),
				'eventQueue'  => self::$event_queue,
				'agentString' => Event::get_platform_identifier(),
			)
		);
	}

	/**
	 * Enqueue an event for isolated script execution.
	 * Events are stored as DATA, not executable code.
	 *
	 * @param string $event_name The name of the event to track.
	 * @param array  $params     Event parameters.
	 * @param string $method     The fbq method to use (track, trackCustom, etc.).
	 * @param string $event_id   Optional event ID for deduplication.
	 */
	public static function enqueue_event( $event_name, $params, $method = 'track', $event_id = '' ) {
		// Initialize hooks if not already done.
		self::init_external_js_hooks();

		$event_data = array(
			'name'   => $event_name,
			'params' => $params,
			'method' => $method,
		);

		if ( ! empty( $event_id ) ) {
			$event_data['eventId'] = $event_id;
		}

		self::$event_queue[] = $event_data;
	}

	/**
	 * Enqueue an event for deferred execution on next page load.
	 * Used when events need to be deferred (e.g., AddToCart with redirect).
	 *
	 * @param string $event_name The name of the event to track.
	 * @param array  $params     Event parameters.
	 * @param string $method     The fbq method to use (track, trackCustom, etc.).
	 * @param string $event_id   Optional event ID for deduplication.
	 */
	public static function enqueue_deferred_event( $event_name, $params, $method = 'track', $event_id = '' ) {
		$event_data = array(
			'name'   => $event_name,
			'params' => $params,
			'method' => $method,
		);

		if ( ! empty( $event_id ) ) {
			$event_data['eventId'] = $event_id;
		}

		WC_Facebookcommerce_Utils::add_deferred_event( $event_data );
	}

	/**
	 * Prepares event parameters for pixel tracking.
	 *
	 * Extracts event_id, unwraps custom_data, and applies build_params().
	 *
	 * @param array  $params     Raw event parameters.
	 * @param string $event_name The name of the event.
	 * @return array ['params' => array, 'event_id' => string]
	 */
	private static function prepare_event_params( $params, $event_name ) {
		$event_id = '';

		// Do not send the event name in the params.
		if ( isset( $params['event_name'] ) ) {
			unset( $params['event_name'] );
		}

		/**
		 * If possible, send the event ID to avoid duplication.
		 *
		 * @see https://developers.facebook.com/docs/marketing-api/server-side-api/deduplicate-pixel-and-server-side-events#deduplication-best-practices
		 */
		if ( isset( $params['event_id'] ) ) {
			$event_id = $params['event_id'];
			unset( $params['event_id'] );
		}

		// If custom_data is set, extract it (send only the inner data).
		if ( isset( $params['custom_data'] ) ) {
			$params = $params['custom_data'];
		}

		// user_data is for CAPI only and must not appear in cacheable pixel output.
		unset( $params['user_data'] );

		// Apply build_params() to add version info and apply filters.
		$params = self::build_params( $params, $event_name );

		return array(
			'params'   => $params,
			'event_id' => $event_id,
		);
	}

		/**
		 * Initialize pixelID.
		 */
	public static function initialize() {
		if ( ! is_admin() ) {
			return;
		}

		// Initialize PixelID in storage - this will only need to happen when the user is an admin.
		$pixel_id = self::get_pixel_id();
		if ( ! WC_Facebookcommerce_Utils::is_valid_id( $pixel_id ) &&
		class_exists( 'WC_Facebookcommerce_WarmConfig' ) ) {
			$fb_warm_pixel_id = WC_Facebookcommerce_WarmConfig::$fb_warm_pixel_id;

			// phpcs:disable Universal.Operators.StrictComparisons.LooseEqual
			if ( WC_Facebookcommerce_Utils::is_valid_id( $fb_warm_pixel_id ) &&
			(int) $fb_warm_pixel_id == $fb_warm_pixel_id ) {
				$fb_warm_pixel_id = (string) $fb_warm_pixel_id;
				self::set_pixel_id( $fb_warm_pixel_id );
			}
		}

		$is_advanced_matching_enabled = self::get_use_pii_key();
		//phpcs:disable Universal.Operators.StrictComparisons.LooseEqual
		if ( null == $is_advanced_matching_enabled &&
		class_exists( 'WC_Facebookcommerce_WarmConfig' ) ) {
			$fb_warm_is_advanced_matching_enabled =
			WC_Facebookcommerce_WarmConfig::$fb_warm_is_advanced_matching_enabled;
			if ( is_bool( $fb_warm_is_advanced_matching_enabled ) ) {
				self::set_use_pii_key( $fb_warm_is_advanced_matching_enabled ? 1 : 0 );
			}
		}
	}


		/**
		 * Gets Facebook Pixel init code.
		 *
		 * Init code might contain additional information to help matching website users with facebook users.
		 * Information is hashed in JS side using SHA256 before sending to Facebook.
		 *
		 * @return string
		 */
	private function get_pixel_init_code() {

		$agent_base      = Event::get_platform_identifier();
		$cache_threshold = 1800;

		/**
		 * Filters Facebook Pixel init code.
		 *
		 * @param string $js_code
		 */
		return apply_filters(
			'facebook_woocommerce_pixel_init',
			sprintf(
				"fbq('init', '%s', (function(){if(window.__wc_fb_page_generated&&(Math.floor(Date.now()/1000)-window.__wc_fb_page_generated)>%d){return {};}return %s;})(), {agent:(function(){var a='%s';if(window.__wc_fb_page_generated&&(Math.floor(Date.now()/1000)-window.__wc_fb_page_generated)>%d){a=a.replace(/(\\d+\\.\\d+\\.\\d+)/,'$1_c');}return a;})()});\n",
				esc_js( self::get_pixel_id() ),
				$cache_threshold,
				wp_json_encode( $this->user_info, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT ),
				esc_js( $agent_base ),
				$cache_threshold
			)
		);
	}


		/**
		 * Gets the Facebook Pixel code scripts.
		 *
		 * @return string HTML scripts
		 *
		 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		 */
	public function pixel_base_code() {

		$pixel_id = self::get_pixel_id();

		// Bail if no ID or already rendered.
		if ( empty( $pixel_id ) || ! empty( self::$render_cache[ self::PIXEL_RENDER ] ) ) {
			return '';
		}

		self::$render_cache[ self::PIXEL_RENDER ] = true;

		ob_start();

		?>
			<script <?php echo self::get_script_attributes(); ?>>
				window.__wc_fb_page_generated = <?php echo (int) time(); ?>;
				!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
					n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
					n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
					t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
					document,'script','https://connect.facebook.net/en_US/fbevents.js');
			</script>
			<!-- WooCommerce Facebook Integration Begin -->
			<script <?php echo self::get_script_attributes(); ?>>

				<?php echo self::get_facebook_signals_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				FacebookSignals.init({
					held: false,
					ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
					action: 'facebook_release_signals',
					pixelId: <?php echo wp_json_encode( self::get_pixel_id() ); ?>,
					attribution: {},
					capig: <?php echo wp_json_encode( self::is_capig_enabled() ); ?>
				});
				FacebookSignals.initPixel(
					<?php echo wp_json_encode( self::get_pixel_id() ); ?>,
					<?php echo wp_json_encode( $this->user_info, JSON_FORCE_OBJECT ); ?>,
					<?php echo wp_json_encode( array( 'agent' => Event::get_platform_identifier() ), JSON_FORCE_OBJECT ); ?>
				);

				document.addEventListener( 'DOMContentLoaded', function() {
					document.body.insertAdjacentHTML( 'beforeend', '<div class=\"wc-facebook-pixel-event-placeholder\"></div>' );
				}, false );

			</script>
			<!-- WooCommerce Facebook Integration End -->
			<?php

			return ob_get_clean();
	}


		/**
		 * Gets Facebook Pixel code noscript part to avoid W3 validation errors.
		 *
		 * @return string
		 */
	public function pixel_base_code_noscript() {

		$pixel_id = self::get_pixel_id();

		if ( empty( $pixel_id ) || ! empty( self::$render_cache[ self::NO_SCRIPT_RENDER ] ) ) {
			return '';
		}

		self::$render_cache[ self::NO_SCRIPT_RENDER ] = true;

		ob_start();

		?>
			<!-- Facebook Pixel Code -->
			<noscript>
				<img
					height="1"
					width="1"
					style="display:none"
					alt="fbpx"
					src="https://www.facebook.com/tr?id=<?php echo esc_attr( $pixel_id ); ?>&ev=PageView&noscript=1"
				/>
			</noscript>
			<!-- End Facebook Pixel Code -->
			<?php

			return ob_get_clean();
	}


	/**
	 * Gets the inline FacebookSignals JS API definition.
	 *
	 * @since 3.6.0
	 *
	 * @return string JavaScript code defining window.FacebookSignals.
	 */
	private static function get_facebook_signals_js() {
		// phpcs:disable
		return <<<'JS'
window.FacebookSignals = window.FacebookSignals || {
	_held: false,
	_releasing: false,
	_pixelInitialized: false,
	_pixelId: null,
	_pixelUserInfo: {},
	_pixelOptions: {},
	_pendingPixelEvents: [],
	_queue: [],
	_config: {},
	_attribution: {},
	_seenEventIds: {},
	_fbclid: (function() {
		try {
			var m = window.location.search.match(/[?&]fbclid=([^&]*)/);
			return m ? decodeURIComponent(m[1]) : null;
		} catch(e) { return null; }
	})(),

	init: function(config) {
		config = config || {};
		this._config = config;
		this._attribution = config.attribution || {};

		var cookieState = this._getCookie('wc_facebook_signals_state');
		this._held = cookieState ? cookieState === 'held' : !!config.held;

		this._fbclid = this._fbclid || null;

		if (typeof fbq === 'function') {
			fbq('consent', this._held ? 'revoke' : 'grant');
		}

		try {
			var raw = window.sessionStorage.getItem('wc_facebook_signals_seen_event_ids');
			this._seenEventIds = raw ? JSON.parse(raw) : {};
		} catch (e) {
			this._seenEventIds = this._seenEventIds || {};
		}
	},

	initPixel: function(pixelId, userInfo, options) {
		this._pixelId = pixelId;
		this._pixelUserInfo = userInfo && typeof userInfo === 'object' && !Array.isArray(userInfo) ? userInfo : {};
		this._pixelOptions = options || {};
		if (!this._held) {
			this._runPixelInit();
		}
	},

	_runPixelInit: function() {
		if (this._pixelInitialized || !this._pixelId || typeof fbq !== 'function') return;
		// The merchant's opt-in to the Meta-enabled Conversions API integration (CAPIG)
		// has to reach Meta before the pixel initialises.
		if (this._config.capig === true) {
			fbq('optinMetaEnabledCapi', this._pixelId);
		}
		fbq('init', this._pixelId, this._pixelUserInfo, this._pixelOptions);
		this._pixelInitialized = true;
		this._flushPendingPixelEvents();
	},

	_flushPendingPixelEvents: function() {
		var pending = this._pendingPixelEvents;
		this._pendingPixelEvents = [];
		for (var i = 0; i < pending.length; i++) {
			var ev = pending[i];
			this._firePixelEvent(ev.name, ev.params, ev.method, ev.eventId);
		}
	},

	_fireOrQueuePixelEvent: function(name, params, method, eventId) {
		if (!this._pixelInitialized) {
			this._pendingPixelEvents.push({
				name: name, params: params || {}, method: method || 'track', eventId: eventId || null
			});
			return;
		}
		this._firePixelEvent(name, params || {}, method || 'track', eventId || null);
	},

	_firePixelEvent: function(name, params, method, eventId) {
		method = method || 'track';
		if (eventId) {
			fbq(method, name, params || {}, { eventID: eventId });
		} else {
			fbq(method, name, params || {});
		}
	},

	queueEvent: function(eventData) {
		if (!eventData || !eventData.event_name) return;

		var originalId = eventData.event_id || null;
		if (originalId && this._seenEventIds[originalId]) return;

		if (!this._held) {
			this._fireOrQueuePixelEvent(eventData.event_name, eventData.custom_data || {}, eventData.method || 'track', originalId);
			return;
		}

		eventData = this._cloneEventData(eventData);
		eventData.event_id = this._generateEventId();
		eventData.event_time = eventData.event_time || Math.floor(Date.now() / 1000);
		this._queue.push(eventData);

		var idToMark = originalId || eventData.event_id;
		if (idToMark) {
			this._seenEventIds[idToMark] = 1;
			try {
				window.sessionStorage.setItem(
					'wc_facebook_signals_seen_event_ids',
					JSON.stringify(this._seenEventIds)
				);
			} catch (e) {}
		}
	},

	trackEvent: function(name, params, userData, method, eventId) {
		method = method || 'track';
		eventId = eventId || (params && params.eventID) || null;

		if (this._held) {
			this.queueEvent({
				event_name: name,
				custom_data: params || {},
				event_id: eventId,
				event_time: Math.floor(Date.now() / 1000),
				method: method
			});
		} else {
			this._fireOrQueuePixelEvent(name, params || {}, method, eventId);
		}
	},

	release: function() {
		var self = this;
		if (!self._held || self._releasing || !self._config.ajaxUrl) {
			return Promise.resolve({ success: true, data: { sent_count: 0 } });
		}

		self._releasing = true;
		var attribution = self._collectAttribution();

		var payload = JSON.stringify({
			events: self._queue,
			attribution: {
				fbp: attribution.fbp || null,
				fbc: attribution.fbc || null
			},
			fbclid: self._fbclid || null
		});

		var url = self._config.ajaxUrl +
			(self._config.ajaxUrl.indexOf('?') === -1 ? '?' : '&') +
			'action=' + encodeURIComponent(self._config.action);

		return new Promise(function(resolve, reject) {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', url, true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.onload = function() {
				if (xhr.status >= 200 && xhr.status < 300) {
					try {
						var resp = JSON.parse(xhr.responseText);
						self._handleReleaseResponse(resp.data || {}, attribution);
						resolve(resp);
					} catch(e) { self._releasing = false; reject(e); }
				} else {
					self._releasing = false;
					reject(new Error('Signal release AJAX failed: ' + xhr.status));
				}
			};
			xhr.onerror = function() { self._releasing = false; reject(new Error('Network error')); };
			xhr.send(payload);
		});
	},

	_handleReleaseResponse: function(data, attribution) {
		this._syncAttributionCookies(data || {}, attribution);

		// Override cached user_info with fresh data from the release response.
		if (data.user_info && typeof data.user_info === 'object' && !Array.isArray(data.user_info)) {
			this._pixelUserInfo = data.user_info;
		}

		this._held = false;
		this._releasing = false;

		fbq('consent', 'grant');
		this._runPixelInit();

		var queue = this._queue;
		for (var i = 0; i < queue.length; i++) {
			var ev = queue[i];
			this._fireOrQueuePixelEvent(ev.event_name, ev.custom_data || {}, ev.method || 'track', ev.event_id || null);
		}

		this._queue = [];
	},

	_collectAttribution: function() {
		var clientParams = {};
		if (typeof clientParamBuilder !== 'undefined') {
			try {
				clientParams = clientParamBuilder.processAndCollectParams(this._getAttributionUrl()) || {};
			} catch (e) {}
		}

		var fbp = this._getCookie('_fbp') || clientParams._fbp || (typeof clientParamBuilder !== 'undefined' ? clientParamBuilder.getFbp() : null);
		var fbc = this._getCookie('_fbc') || clientParams._fbc || (typeof clientParamBuilder !== 'undefined' ? clientParamBuilder.getFbc() : null);

		if (!fbc && this._fbclid) {
			fbc = 'fb.1.' + Date.now() + '.' + this._fbclid;
		}

		return { fbp: fbp || null, fbc: fbc || null };
	},

	_syncAttributionCookies: function(data, attribution) {
		var fbp = data.fbp || (attribution && attribution.fbp) || null;
		var fbc = data.fbc || (attribution && attribution.fbc) || null;

		if (fbp) {
			this._setAttributionCookie('_fbp', fbp, data.fbp_domain || null);
		}
		if (fbc) {
			this._setAttributionCookie('_fbc', fbc, data.fbc_domain || null);
		}
	},

	_setAttributionCookie: function(name, value, domain) {
		if (!value) return;
		var domainAttr = domain ? ';domain=' + domain : '';
		document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;max-age=7776000' + domainAttr + ';SameSite=Lax';
	},

	_getCookie: function(name) {
		var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
		if (!match) return null;
		try { return decodeURIComponent(match[1]); } catch(e) { return null; }
	},

	_cloneEventData: function(eventData) {
		var clone = {};
		for (var key in eventData) {
			if (Object.prototype.hasOwnProperty.call(eventData, key)) {
				clone[key] = eventData[key];
			}
		}
		return clone;
	},

	_generateEventId: function() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
			var r = Math.random() * 16 | 0;
			return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	},

	_getAttributionUrl: function() {
		if (!this._fbclid) return window.location.href;
		try {
			var url = new URL(window.location.href);
			if (!url.searchParams.get('fbclid')) {
				url.searchParams.set('fbclid', this._fbclid);
			}
			return url.toString();
		} catch (e) {
			return window.location.href;
		}
	}
};
JS;
		// phpcs:enable
	}


	/**
	 * Gets JS code that queues an event via FacebookSignals while signals are held.
	 *
	 * Captures PII at event-generation time so it's included when events are replayed.
	 *
	 * @since 3.6.0
	 *
	 * @param string $event_name The event name.
	 * @param array  $params     Event parameters including custom_data, user_data, event_id.
	 * @param string $method     Signals method name (e.g. track, trackCustom).
	 * @return string JavaScript code.
	 */
	public function get_queued_event_code( $event_name, $params, $method = 'track' ) {
		$this->last_event = $event_name;

		$event_id   = isset( $params['event_id'] ) ? $params['event_id'] : null;
		$queue_data = ! empty( $event_id ) ? FacebookSignalsState::get_queued_event( $event_id ) : null;

		if ( ! is_array( $queue_data ) ) {
			$custom_data = isset( $params['custom_data'] ) ? $params['custom_data'] : $params;
			unset( $custom_data['user_data'], $custom_data['event_name'], $custom_data['event_id'] );

			$queue_data = array(
				'event_name'  => $event_name,
				'custom_data' => $custom_data,
				'event_id'    => $event_id,
				'event_time'  => time(),
			);
		}

		if ( empty( $queue_data['event_name'] ) ) {
			$queue_data['event_name'] = $event_name;
		}

		$queue_data['method'] = $method;

		// Do not render per-visitor user_data into cacheable HTML.
		// The release endpoint resolves attribution from the releasing request.
		unset( $queue_data['user_data'] );
		if ( isset( $queue_data['custom_data'] ) && is_array( $queue_data['custom_data'] ) ) {
			unset( $queue_data['custom_data']['user_data'] );
		}

		return sprintf(
			"/* %s Facebook Integration Event Queueing */\nFacebookSignals.queueEvent(%s);",
			\WC_Facebookcommerce_Utils::get_integration_name(),
			wp_json_encode( $queue_data )
		);
	}


		/**
		 * Determines if the last event in the current thread matches a given event.
		 *
		 * @since 1.11.0
		 *
		 * @param string $event_name
		 * @return bool
		 */
	public function is_last_event( $event_name ) {

		return $event_name === $this->last_event;
	}


		/**
		 * Gets the JavaScript code to track an event.
		 *
		 * Updates the last event property and returns the code.
		 *
		 * Use {@see \WC_Facebookcommerce_Pixel::inject_event()} to print or enqueue the code.
		 *
		 * @since 1.10.2
		 *
		 * @param string $event_name The name of the event to track.
		 * @param array  $params     Custom event parameters.
		 * @param string $method     Name of the pixel's fbq() function to call.
		 * @return string
		 */
	public function get_event_code( $event_name, $params, $method = 'track' ) {

		$this->last_event = $event_name;

		return self::build_event( $event_name, $params, $method );
	}


		/**
		 * Gets the JavaScript code to track an event wrapped in <script> tag.
		 *
		 * @see \WC_Facebookcommerce_Pixel::get_event_code()
		 *
		 * @since 1.10.2
		 *
		 * @param string $event_name The name of the event to track.
		 * @param array  $params     Custom event parameters.
		 * @param string $method     Name of the pixel's fbq() function to call.
		 * @return string
		 *
		 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		 */
	public function get_event_script( $event_name, $params, $method = 'track' ) {

		ob_start();

		?>
			<!-- Facebook Pixel Event Code -->
			<script <?php echo self::get_script_attributes(); ?>>
			<?php
			if ( FacebookSignalsState::is_held() ) {
				echo $this->get_queued_event_code( $event_name, $params, $method ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo $this->get_event_code( $event_name, $params, $method ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			</script>
			<!-- End Facebook Pixel Event Code -->
			<?php

			return ob_get_clean();
	}

	/**
	 * Prints or enqueues the JavaScript code to track an event.
	 * Preferred method to inject events in a page.
	 *
	 * Supports two execution modes controlled by rollout switch:
	 * - Isolated execution (switch ON): Uses external JS via wp_localize_script() to prevent
	 *   other plugins' JavaScript errors from breaking pixel tracking.
	 * - Legacy execution (switch OFF): Uses enqueue_inline_js() for inline script output.
	 *
	 * @see \WC_Facebookcommerce_Pixel::build_event()
	 *
	 * @param string $event_name The name of the event to track.
	 * @param array  $params     Custom event parameters.
	 * @param string $method     Name of the pixel's fbq() function to call.
	 *
	 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	 */
	public function inject_event( $event_name, $params, $method = 'track' ) {
		// When signals are held, queue events in the browser instead of firing them.
		if ( FacebookSignalsState::is_held() ) {
			$code = $this->get_queued_event_code( $event_name, $params, $method );
			if ( WC_Facebookcommerce_Utils::is_woocommerce_integration() ) {
				WC_Facebookcommerce_Utils::enqueue_inline_js( $code );
			} else {
				printf(
					'<!-- Facebook Pixel Event Code --><script %s>%s</script><!-- End Facebook Pixel Event Code -->',
					self::get_script_attributes(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$code // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			}
			return;
		}
		if ( WC_Facebookcommerce_Utils::is_woocommerce_integration() ) {
			// Check rollout switch for isolated pixel execution.
			// When enabled, pixel events are output via external JS file (wp_localize_script)
			// to prevent other plugins' JavaScript errors from breaking pixel tracking.
			$is_isolated_pixel_execution_enabled = facebook_for_woocommerce()->get_rollout_switches()->is_switch_enabled(
				\WooCommerce\Facebook\RolloutSwitches::SWITCH_ISOLATED_PIXEL_EXECUTION_ENABLED
			);

			// If we have add to cart redirect enabled, we must defer the AddToCart events to render them the next page load.
			$is_redirect    = 'yes' === get_option( 'woocommerce_cart_redirect_after_add', 'no' );
			$is_add_to_cart = 'AddToCart' === $event_name;
			$is_deferred    = $is_redirect && $is_add_to_cart;

			if ( $is_isolated_pixel_execution_enabled ) {
				// Isolated execution: Use external JS via wp_localize_script.
				// Set last_event here since we don't call get_event_code() in this path.
				$this->last_event                                      = $event_name;
				[ 'params' => $event_params, 'event_id' => $event_id ] = self::prepare_event_params( $params, $event_name );

				if ( $is_deferred ) {
					// Store event data for next page load.
					self::enqueue_deferred_event( $event_name, $event_params, $method, $event_id );
				} else {
					// Queue event for this page's external script.
					self::enqueue_event( $event_name, $event_params, $method, $event_id );
				}
			} else {
				// Legacy execution: Use enqueue_inline_js for inline script.
				$code = $this->get_event_code( $event_name, self::build_params( $params, $event_name ), $method );

				if ( $is_deferred ) {
					// Store JS code string for inline script at print time.
					WC_Facebookcommerce_Utils::add_deferred_event( $code );
				} else {
					WC_Facebookcommerce_Utils::enqueue_inline_js( $code );
				}
			}
		} else {
			printf( $this->get_event_script( $event_name, self::build_params( $params, $event_name ), $method ) ); // phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
		}
	}

		/**
		 * Gets the JavaScript code to track a conditional event wrapped in <script> tag.
		 *
		 * @see \WC_Facebookcommerce_Pixel::get_event_code()
		 *
		 * @since 1.10.2
		 *
		 * @param string $event_name    The name of the event to track.
		 * @param array  $params        Custom event parameters.
		 * @param string $listener      Name of the JavaScript event to listen for.
		 * @param string $jsonified_pii JavaScript code representing an object of data for Advanced Matching.
		 * @return string
		 *
		 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		 */
	public function get_conditional_event_script( $event_name, $params, $listener, $jsonified_pii ) {

		$this->last_event = $event_name;

		// When signals are held, use FacebookSignals.trackEvent() to queue the event.
		if ( FacebookSignalsState::is_held() ) {
			ob_start();
			?>
			<!-- Facebook Pixel Event Code -->
			<script <?php echo self::get_script_attributes(); ?>>
				document.addEventListener( '<?php echo esc_js( $listener ); ?>', function (event) {
					FacebookSignals.trackEvent(
						<?php echo wp_json_encode( $event_name ); ?>,
						<?php echo wp_json_encode( $params ); ?>
					);
				}, false );
			</script>
			<!-- End Facebook Pixel Event Code -->
			<?php
			return ob_get_clean();
		}

		$code = self::build_event( $event_name, $params, 'track' );

		/**
		 * TODO: use the settings stored by {@see \WC_Facebookcommerce_Integration}.
		 * The use_pii setting here is currently always disabled regardless of
		 * the value configured in the plugin settings page {WV-2020-01-02}.
		 */

		// Prepends fbq(...) with pii information to the injected code.
		if ( $jsonified_pii && get_option( self::SETTINGS_KEY )[ self::USE_PII_KEY ] ) {
			$this->user_info = '%s';
			$code            = sprintf( $this->get_pixel_init_code(), '" || ' . $jsonified_pii . ' || "' ) . $code;
		}

		ob_start();

		?>
			<!-- Facebook Pixel Event Code -->
			<script <?php echo self::get_script_attributes(); ?>>
				document.addEventListener( '<?php echo esc_js( $listener ); ?>', function (event) {
				<?php echo $code; ?>
				}, false );
			</script>
			<!-- End Facebook Pixel Event Code -->
			<?php

			return ob_get_clean();
	}


		/**
		 * Prints the JavaScript code to track a conditional event.
		 *
		 * The tracking code will be executed when the given JavaScript event is triggered.
		 *
		 * @param string $event_name    Name of the event.
		 * @param array  $params        Custom event parameters.
		 * @param string $listener      Name of the JavaScript event to listen for.
		 * @param string $jsonified_pii JavaScript code representing an object of data for Advanced Matching.
		 * @return string
		 */
	public function inject_conditional_event( $event_name, $params, $listener, $jsonified_pii = '' ) {

		// phpcs:ignore WordPress.XSS.EscapeOutput.OutputNotEscaped
		return $this->get_conditional_event_script( $event_name, self::build_params( $params, $event_name ), $listener, $jsonified_pii );
	}


		/**
		 * Gets the JavaScript code to track a conditional event that is only triggered one time wrapped in <script> tag.
		 *
		 * @internal
		 *
		 * @since 1.10.2
		 *
		 * @param string $event_name     The name of the event to track.
		 * @param array  $params         Custom event parameters.
		 * @param string $listened_event Name of the JavaScript event to listen for.
		 * @return string
		 *
		 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		 */
	public function get_conditional_one_time_event_script( $event_name, $params, $listened_event ) {

		// When signals are held, queue via FacebookSignals.trackEvent().
		if ( FacebookSignalsState::is_held() ) {
			$this->last_event = $event_name;
			ob_start();
			?>
			<!-- Facebook Pixel Event Code -->
			<script <?php echo self::get_script_attributes(); ?>>
				function handle<?php echo $event_name; ?>Event() {
					FacebookSignals.trackEvent(<?php echo wp_json_encode( $event_name ); ?>, <?php echo wp_json_encode( $params ); ?>);
					jQuery( document.body ).off( '<?php echo esc_js( $listened_event ); ?>', handle<?php echo $event_name; ?>Event );
				}
				jQuery( document.body ).one( '<?php echo esc_js( $listened_event ); ?>', handle<?php echo $event_name; ?>Event );
			</script>
			<!-- End Facebook Pixel Event Code -->
			<?php
			return ob_get_clean();
		}

		$code = $this->get_event_code( $event_name, $params );

		ob_start();

		?>
			<!-- Facebook Pixel Event Code -->
			<script <?php echo self::get_script_attributes(); ?>>
				function handle<?php echo $event_name; ?>Event() {
				<?php echo $code; ?>
					// Some weird themes (hi, Basel) are running this script twice, so two listeners are added and we need to remove them after running one.
					jQuery( document.body ).off( '<?php echo esc_js( $listened_event ); ?>', handle<?php echo $event_name; ?>Event );
				}

				jQuery( document.body ).one( '<?php echo esc_js( $listened_event ); ?>', handle<?php echo $event_name; ?>Event );
			</script>
			<!-- End Facebook Pixel Event Code -->
			<?php

			return ob_get_clean();
	}


		/**
		 * Builds an event.
		 *
		 * @see \WC_Facebookcommerce_Pixel::inject_event() for the preferred method to inject an event.
		 *
		 * @param string $event_name Event name.
		 * @param array  $params     Event params.
		 * @param string $method     Optional, defaults to 'track'.
		 * @return string
		 */
	public static function build_event( $event_name, $params, $method = 'track' ) {
		// Reuse shared param preparation logic.
		[ 'params' => $event_params, 'event_id' => $event_id ] = self::prepare_event_params( $params, $event_name );

		$agent_base      = Event::get_platform_identifier();
		$pixel_id        = self::get_pixel_id();
		$encoded_params  = wp_json_encode( $event_params, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT );
		$cache_threshold = 1800;

		$agent_js = sprintf(
			"(function(){var a='%s';if(window.__wc_fb_page_generated&&(Math.floor(Date.now()/1000)-window.__wc_fb_page_generated)>%d){a=a.replace(/(\\d+\\.\\d+\\.\\d+)/,'$1_c');}return a;})()",
			esc_js( $agent_base ),
			$cache_threshold
		);

		if ( ! empty( $event_id ) ) {
			// Guard against duplicate fires of the same event_id within a single page
			// load (e.g. AJAX fragment re-execution). Inside the guard, if the page is
			// being served stale from a full-page cache the baked-in event_id is shared
			// across visitors, so fire without the eventID to avoid corrupting
			// server-side deduplication.
			$encoded_event_id = wp_json_encode( $event_id );
			$event            = sprintf(
				"/* %s Facebook Integration Event Tracking */\n" .
				"fbq('set', 'agent', %s, '%s');\n" .
				"window.wcFacebookPixelFiredEvents = window.wcFacebookPixelFiredEvents || {};\n" .
				"if (!window.wcFacebookPixelFiredEvents[%s]) {\n" .
				"window.wcFacebookPixelFiredEvents[%s] = true;\n" .
				"var isStale = window.__wc_fb_page_generated && (Math.floor(Date.now()/1000) - window.__wc_fb_page_generated) > %d;\n" .
				"if (window.FacebookSignals && typeof window.FacebookSignals.trackEvent === 'function') {\n" .
				"\twindow.FacebookSignals.trackEvent('%s', %s, null, '%s', isStale ? null : %s);\n" .
				"} else if (isStale) {\n" .
				"\tfbq('%s', '%s', %s);\n" .
				"} else {\n" .
				"\tfbq('%s', '%s', %s, %s);\n" .
				"}\n" .
				'}',
				WC_Facebookcommerce_Utils::get_integration_name(),
				$agent_js,
				esc_js( $pixel_id ),
				$encoded_event_id,
				$encoded_event_id,
				$cache_threshold,
				esc_js( $event_name ),
				$encoded_params,
				esc_js( $method ),
				$encoded_event_id,
				esc_js( $method ),
				esc_js( $event_name ),
				$encoded_params,
				esc_js( $method ),
				esc_js( $event_name ),
				$encoded_params,
				wp_json_encode( array( 'eventID' => $event_id ), JSON_PRETTY_PRINT | JSON_FORCE_OBJECT )
			);
		} else {
			// No event_id to deduplicate on; route through the Signals Gateway when
			// available, otherwise fall back to a plain fbq fire. The dynamic agent
			// still flags the fire as cache-sourced when the page is served stale.
			$event = sprintf(
				"/* %s Facebook Integration Event Tracking */\n" .
				"fbq('set', 'agent', %s, '%s');\n" .
				"if (window.FacebookSignals && typeof window.FacebookSignals.trackEvent === 'function') {\n" .
				"\twindow.FacebookSignals.trackEvent('%s', %s, null, '%s', null);\n" .
				"} else {\n" .
				"\tfbq('%s', '%s', %s);\n" .
				'}',
				WC_Facebookcommerce_Utils::get_integration_name(),
				$agent_js,
				esc_js( $pixel_id ),
				esc_js( $event_name ),
				$encoded_params,
				esc_js( $method ),
				esc_js( $method ),
				esc_js( $event_name ),
				$encoded_params
			);
		}

		return $event;
	}


		/**
		 * Gets an array with version_info for pixel fires.
		 *
		 * Parameters provided by users should not be overwritten by this function.
		 *
		 * @since 1.10.2
		 *
		 * @param array  $params User defined parameters.
		 * @param string $event  The event name the params are for.
		 * @return array
		 */
	private static function build_params( $params = [], $event = '' ) {

		$params = array_replace( Event::get_version_info(), $params );

		/**
		 * Filters the parameters for the pixel code.
		 *
		 * @since 1.10.2
		 *
		 * @param array $params User defined parameters.
		 * @param string $event The event name.
		 */
		return (array) apply_filters( 'wc_facebook_pixel_params', $params, $event );
	}


		/**
		 * Determines whether the merchant opted in to the Meta-enabled Conversions API integration.
		 *
		 * Reads WC_Facebookcommerce_Integration::is_capig_enabled(), which applies the
		 * setting's opted-in default and its filter. Falls back to that default if the
		 * integration is not available yet.
		 *
		 * @return bool
		 */
	private static function is_capig_enabled() {
		if ( function_exists( 'facebook_for_woocommerce' ) && facebook_for_woocommerce()->get_integration() ) {
			return facebook_for_woocommerce()->get_integration()->is_capig_enabled();
		}

		return 'yes' === WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG_DEFAULT;
	}


		/**
		 * Gets script tag attributes.
		 *
		 * @since 1.10.2
		 *
		 * @return string
		 */
	private static function get_script_attributes() {

		$script_attributes = '';

		/**
		 * Filters Facebook Pixel script attributes.
		 *
		 * @since 1.10.2
		 *
		 * @param array $custom_attributes
		 */
		$custom_attributes = (array) apply_filters( 'wc_facebook_pixel_script_attributes', array( 'type' => 'text/javascript' ) );

		foreach ( $custom_attributes as $tag => $value ) {
			$script_attributes .= ' ' . $tag . '="' . esc_attr( $value ) . '"';
		}

		return $script_attributes;
	}

		/**
		 * Get the PixelId.
		 */
	public static function get_pixel_id() {
		$fb_options = self::get_options();
		if ( ! $fb_options ) {
			return '';
		}
		return isset( $fb_options[ self::PIXEL_ID_KEY ] ) ?
			$fb_options[ self::PIXEL_ID_KEY ] : '';
	}

		/**
		 * Set the PixelId.
		 *
		 * @param string $pixel_id PixelId.
		 */
	public static function set_pixel_id( $pixel_id ) {
		$fb_options = self::get_options();

		if ( isset( $fb_options[ self::PIXEL_ID_KEY ] )
			&& $fb_options[ self::PIXEL_ID_KEY ] === $pixel_id ) {
			return;
		}

		$fb_options[ self::PIXEL_ID_KEY ] = $pixel_id;
		update_option( self::SETTINGS_KEY, $fb_options );
	}

		/**
		 * Check if PII key use is enabled.
		 */
	public static function get_use_pii_key() {
		$fb_options = self::get_options();
		if ( ! $fb_options ) {
			return null;
		}
		return isset( $fb_options[ self::USE_PII_KEY ] ) ?
			$fb_options[ self::USE_PII_KEY ] : null;
	}

		/**
		 * Enable or disable use of PII key.
		 *
		 * @param string $use_pii PII key.
		 */
	public static function set_use_pii_key( $use_pii ) {
		$fb_options = self::get_options();

		if ( isset( $fb_options[ self::USE_PII_KEY ] )
			&& $fb_options[ self::USE_PII_KEY ] === $use_pii ) {
			return;
		}

		$fb_options[ self::USE_PII_KEY ] = $use_pii;
		update_option( self::SETTINGS_KEY, $fb_options );
	}

		/**
		 * Check if S2S is set.
		 */
	public static function get_use_s2s() {
		$fb_options = self::get_options();
		if ( ! $fb_options ) {
			return false;
		}
		return isset( $fb_options[ self::USE_S2S_KEY ] ) ?
			$fb_options[ self::USE_S2S_KEY ] : false;
	}

		/**
		 * Enable or disable use of S2S key.
		 *
		 * @param string $use_s2s S2S setting.
		 */
	public static function set_use_s2s( $use_s2s ) {
		$fb_options = self::get_options();

		if ( isset( $fb_options[ self::USE_S2S_KEY ] )
			&& $fb_options[ self::USE_S2S_KEY ] === $use_s2s ) {
			return;
		}

		$fb_options[ self::USE_S2S_KEY ] = $use_s2s;
		update_option( self::SETTINGS_KEY, $fb_options );
	}

		/**
		 * Get access token.
		 */
	public static function get_access_token() {
		$fb_options = self::get_options();
		if ( ! $fb_options ) {
			return '';
		}
		return isset( $fb_options[ self::ACCESS_TOKEN_KEY ] ) ?
			$fb_options[ self::ACCESS_TOKEN_KEY ] : '';
	}

		/**
		 * Set access token.
		 *
		 * @param string $access_token Access token.
		 */
	public static function set_access_token( $access_token ) {
		$fb_options = self::get_options();

		if ( isset( $fb_options[ self::ACCESS_TOKEN_KEY ] )
			&& $fb_options[ self::ACCESS_TOKEN_KEY ] === $access_token ) {
			return;
		}

		$fb_options[ self::ACCESS_TOKEN_KEY ] = $access_token;
		update_option( self::SETTINGS_KEY, $fb_options );
	}

		/**
		 * Get WooCommerce/Wordpress information.
		 */
	private static function get_version_info() {
		global $wp_version;

		if ( WC_Facebookcommerce_Utils::is_woocommerce_integration() ) {
			return array(
				'source'        => 'woocommerce',
				'version'       => WC()->version,
				'pluginVersion' => WC_Facebookcommerce_Utils::PLUGIN_VERSION,
			);
		}

		return array(
			'source'        => 'wordpress',
			'version'       => $wp_version,
			'pluginVersion' => WC_Facebookcommerce_Utils::PLUGIN_VERSION,
		);
	}

		/**
		 * Get PixelID related settings.
		 */
	public static function get_options() {

		$default_options = array(
			self::PIXEL_ID_KEY     => '0',
			self::USE_PII_KEY      => 0,
			self::USE_S2S_KEY      => false,
			self::ACCESS_TOKEN_KEY => '',
		);

		$fb_options = get_option( self::SETTINGS_KEY );

		if ( ! is_array( $fb_options ) ) {
			$fb_options = $default_options;
		} else {
			foreach ( $default_options as $key => $value ) {
				if ( ! isset( $fb_options[ $key ] ) ) {
					$fb_options[ $key ] = $value;
				}
			}
		}

		return $fb_options;
	}

		/**
		 * Gets the logged in user info
		 *
		 * @return string[]
		 */
	public function get_user_info() {
		return $this->user_info;
	}
}
