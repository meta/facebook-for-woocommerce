/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

/**
 * Facebook for WooCommerce - Signals helper.
 *
 * Exposes `window.fbwcsignal` so a CMP or theme integration can hold or
 * release signal delivery while keeping the plugin loaded.
 *
 * @package FacebookCommerce
 */
( function () {
	'use strict';

	var COOKIE_NAME = 'wc_facebook_signals_state';
	var STATE_ACTIVE = 'active';
	var STATE_HELD = 'held';

	/**
	 * Minimal cookie setter.
	 *
	 * @param {string} name
	 * @param {string} value
	 * @param {number} days
	 */
	function setCookie( name, value, days ) {
		var d = new Date();
		d.setTime( d.getTime() + days * 86400000 );
		document.cookie =
			name +
			'=' +
			encodeURIComponent( value ) +
			';expires=' +
			d.toUTCString() +
			';path=/;SameSite=Lax';
	}

	/**
	 * Read a cookie value.
	 *
	 * @param {string} name
	 * @return {string|null}
	 */
	function getCookie( name ) {
		var match = document.cookie.match(
			new RegExp( '(?:^|;\\s*)' + name + '=([^;]*)' )
		);
		return match ? decodeURIComponent( match[ 1 ] ) : null;
	}

	/**
	 * Sequence number of the most recent state request, and the state it asked
	 * for. The server answers each request with a Set-Cookie for the state it
	 * applied, so when responses arrive out of order the older one would leave
	 * the cookie on a state the visitor has since changed.
	 */
	var latestRequest = 0;
	var latestState = null;

	/**
	 * Update the stored signal state and sync it to PHP.
	 *
	 * @param {string} state `active` or `held`.
	 * @return {Promise} Resolves with the backend response.
	 */
	function updateState( state ) {
		var sequence = ++latestRequest;
		latestState = state;
		setCookie( COOKIE_NAME, state, 365 );

		var params = wc_facebook_signals_params; // localized by PHP.

		return new Promise( function ( resolve, reject ) {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', params.ajax_url, true );
			xhr.setRequestHeader(
				'Content-Type',
				'application/x-www-form-urlencoded; charset=UTF-8'
			);
			xhr.onload = function () {
				// A newer state was requested while this request was in flight.
				// The browser has just applied this response's Set-Cookie, so put
				// the newer state back before anything reads the cookie.
				if ( sequence !== latestRequest && latestState ) {
					setCookie( COOKIE_NAME, latestState, 365 );
				}

				if ( xhr.status >= 200 && xhr.status < 300 ) {
					try {
						var data = JSON.parse( xhr.responseText );
						resolve( data );
					} catch ( e ) {
						reject( e );
					}
				} else {
					reject( new Error( 'AJAX failed: ' + xhr.status ) );
				}
			};
			xhr.onerror = function () {
				reject( new Error( 'Network error' ) );
			};
			xhr.send(
				'action=' +
					encodeURIComponent( params.action ) +
					'&security=' +
					encodeURIComponent( params.nonce ) +
					'&state=' +
					encodeURIComponent( state )
			);
		} );
	}

	/**
	 * Hold browser/server signals for subsequent requests.
	 *
	 * @return {Promise}
	 */
	function hold() {
		// Revoke browser consent right away, before the state update
		// round-trip, so nothing fires in the meantime and a release that
		// is still in flight is discarded when it returns.
		if (
			window.FacebookSignals &&
			typeof window.FacebookSignals.hold === 'function'
		) {
			window.FacebookSignals.hold();
		}

		return updateState( STATE_HELD );
	}

	/**
	 * Release held signals and flush any queued browser events.
	 *
	 * @return {Promise}
	 */
	function release() {
		// Remember which hold generation this release belongs to. A hold that
		// lands while the state update is in flight bumps it, and the queue
		// must then stay where it is.
		var generation = window.FacebookSignals
			? window.FacebookSignals._holdGeneration
			: null;

		return updateState( STATE_ACTIVE ).then( function ( data ) {
			if (
				window.FacebookSignals &&
				window.FacebookSignals._held
			) {
				if (
					null !== generation &&
					window.FacebookSignals._holdGeneration !== generation
				) {
					return data;
				}

				return window.FacebookSignals.release().then(
					function () {
						return data;
					},
					function () {
						return data;
					}
				);
			}

			return data;
		} );
	}

	/**
	 * Read the current signal state from the cookie.
	 *
	 * @return {string|null} `active`, `held`, or `null`.
	 */
	function getState() {
		var val = getCookie( COOKIE_NAME );
		if ( val === null ) {
			return null;
		}

		return val === STATE_ACTIVE ? STATE_ACTIVE : STATE_HELD;
	}

	window.fbwcsignal = window.fbwcsignal || {};
	window.fbwcsignal.hold = hold;
	window.fbwcsignal.release = release;
	window.fbwcsignal.getState = getState;
} )();
