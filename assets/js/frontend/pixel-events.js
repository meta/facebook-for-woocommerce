/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Facebook Pixel Events - External JavaScript Handler
 *
 * This script fires pixel events in an isolated execution context,
 * ensuring events are sent even if other plugins cause JavaScript errors.
 *
 * Supports WooCommerce Blocks via Store API fetch interception.
 * The pixel event data is read from the Store API response extensions.
 *
 * @package FacebookCommerce
 */

(function() {
    'use strict';

    // Data from PHP (may be missing if no events were queued for initial render).
    // Keep Store API interception active even when eventQueue is empty/undefined.
    var data = (typeof wc_facebook_pixel_data !== 'undefined' && wc_facebook_pixel_data) ?
        wc_facebook_pixel_data :
        { eventQueue: [] };
    window.wcFacebookPixelFiredEvents = window.wcFacebookPixelFiredEvents || {};
    var firedEvents = window.wcFacebookPixelFiredEvents;

    /**
     * Build event data object for fbq()
     *
     * @param {Object} event Event object from PHP
     * @return {Object} Prepared event data
     */
    function isCachedPage() {
        return window.__wc_fb_page_generated &&
            (Math.floor(Date.now() / 1000) - window.__wc_fb_page_generated) > 1800;
    }

    function buildEventData(event) {
        return {
            method: event.method || 'track',
            name: event.name,
            params: event.params || {},
            eventId: isCachedPage() ? null : (event.eventId || null)
        };
    }

    /**
     * Check if event should be skipped (already fired)
     *
     * @param {string|null} eventId Event ID for deduplication
     * @return {boolean} True if should skip
     */
    function shouldSkipEvent(eventId) {
        return eventId && firedEvents[eventId];
    }

    /**
     * Mark event as fired for deduplication
     *
     * @param {string|null} eventId Event ID
     */
    function markEventFired(eventId) {
        if (eventId) {
            firedEvents[eventId] = true;
        }
    }

    /**
     * Log warning to console (with safety check)
     *
     * @param {string} message Warning message
     * @param {*} data Additional data to log
     */
    function logWarning(message, data) {
        if (typeof console !== 'undefined' && console.warn) {
            console.warn('[FB Pixel]', message, data);
        }
    }

    /**
     * Whether the FacebookSignals wrapper from the Pixel base code is present.
     *
     * The wrapper queues events until the Pixel can be initialised and flushes
     * them itself, including when a consent manager delays the Pixel library.
     *
     * @return {boolean}
     */
    function hasSignalsWrapper() {
        return !!(window.FacebookSignals && typeof window.FacebookSignals.trackEvent === 'function');
    }

    /**
     * Fire a single event using fbq()
     *
     * @param {Object} event Event object with name, params, method, eventId
     */
    function fireEvent(event) {
        var eventData = buildEventData(event);

        // Skip if already fired (deduplication)
        if (shouldSkipEvent(eventData.eventId)) {
            return;
        }

        try {
            var params = eventData.params;

            if (hasSignalsWrapper()) {
                window.FacebookSignals.trackEvent(eventData.name, params, null, eventData.method, eventData.eventId);
            } else {
                if (typeof fbq !== 'function') {
                    logWarning('fbq not available, skipping event:', eventData.name);
                    return;
                }

                if (eventData.eventId) {
                    fbq(eventData.method, eventData.name, params, {eventID: eventData.eventId});
                } else {
                    fbq(eventData.method, eventData.name, params);
                }
            }

            markEventFired(eventData.eventId);

        } catch (e) {
            logWarning('Event error: ' + eventData.name, e);
        }
    }

    /**
     * Fire all queued events from PHP
     */
    function fireQueuedEvents() {
        var events = data.eventQueue;

        if (!events || !Array.isArray(events)) {
            return;
        }

        for (var i = 0; i < events.length; i++) {
            try {
                fireEvent(events[i]);
            } catch (e) {
                logWarning('fireQueuedEvents loop error:', e);
            }
        }

        // Clear events after firing to prevent duplicate firing
        data.eventQueue = [];
    }

    // =========================================================================
    // WooCommerce Blocks: Store API approach
    // =========================================================================

    /**
     * Process pixel event data from Store API response.
     *
     * @param {Object} eventData Event data from Store API extensions
     */
    function processStoreApiEvent(eventData) {
        if (!eventData || !eventData.event) {
            return;
        }

        var params = eventData.params || {};

        var event = {
            method: 'track',
            name: eventData.event,
            params: params,
            eventId: params.event_id || null
        };

        fireEvent(event);
    }

    /**
     * Returns the request URL from a fetch() input.
     *
     * @param {*} input fetch() input argument
     * @return {string}
     */
    function getRequestUrl(input) {
        if (typeof input === 'string') {
            return input;
        }

        if (input && typeof input.url === 'string') {
            return input.url;
        }

        return '';
    }

    /**
     * Checks whether a URL targets the Store API add-item endpoint.
     *
     * @param {string} url Request URL
     * @return {boolean}
     */
    function isStoreApiAddItemRequest(url) {
        return typeof url === 'string' &&
            (url.indexOf('/wc/store/v1/cart/add-item') !== -1 ||
             url.indexOf('/wc/store/cart/add-item') !== -1);
    }

    /**
     * Checks whether a URL targets the Store API batch endpoint.
     *
     * @param {string} url Request URL
     * @return {boolean}
     */
    function isStoreApiBatchRequest(url) {
        return typeof url === 'string' &&
            (url.indexOf('/wc/store/v1/batch') !== -1 ||
             url.indexOf('/wc/store/batch') !== -1);
    }

    /**
     * Process Store API response payload and extract plugin extension data.
     * Handles both direct cart responses and batch responses.
     *
     * @param {Object} responseData Parsed Store API response payload
     */
    function processStoreApiResponseData(responseData) {
        if (!responseData || typeof responseData !== 'object') {
            return;
        }

        // Direct response shape: { extensions: { 'facebook-for-woocommerce': ... } }
        if (responseData.extensions && responseData.extensions['facebook-for-woocommerce']) {
            processStoreApiEvent(responseData.extensions['facebook-for-woocommerce']);
        } else if (Array.isArray(responseData.responses)) {
            // Batch response shape: { responses: [ { body: { extensions: ... } } ] }
            for (var i = 0; i < responseData.responses.length; i++) {
                var item = responseData.responses[i];
                if (item && item.body && item.body.extensions && item.body.extensions['facebook-for-woocommerce'] &&
                    item.status >= 200 && item.status < 300) {
                    processStoreApiEvent(item.body.extensions['facebook-for-woocommerce']);
                }
            }
        }
    }

    /**
     * Set up fetch interceptor to capture Store API responses.
     * Intercepts cart/add-item and batch requests to fire AddToCart pixel events.
     */
    function setupFetchInterceptor() {
        var originalFetch = window.fetch;
        if (!originalFetch) {
            return;
        }

        window.fetch = function() {
            var args = arguments;
            var url = getRequestUrl(args[0]);

            var isAddToCartRequest = isStoreApiAddItemRequest(url);
            var isBatchRequest = isStoreApiBatchRequest(url);

            return originalFetch.apply(this, args).then(function(response) {
                if ((isAddToCartRequest || isBatchRequest) && response.ok) {
                    // Clone response so we can read it without consuming
                    response.clone().json().then(function(responseData) {
                        processStoreApiResponseData(responseData);
                    }).catch(function(e) {
                        logWarning('Store API JSON parse error:', e);
                    });
                }
                return response;
            });
        };
    }

    /**
     * Initialize pixel event handling.
     *
     * When the FacebookSignals wrapper is present, queued events are handed to
     * it right away: it queues them until the Pixel can be initialised and
     * flushes them itself. Otherwise, if fbq() is already available, fires
     * queued events immediately. If not (e.g. consent manager blocking the
     * SDK), uses Object.defineProperty to set a trap on window.fbq — our
     * handler fires automatically the moment fbq is assigned, with zero
     * overhead in between. No polling, no timers.
     *
     * Also sets up Store API interceptor for WooCommerce Blocks AJAX AddToCart.
     */
    function init() {
        // Set up fetch interceptor for WooCommerce Blocks Store API
        setupFetchInterceptor();

        if (hasSignalsWrapper() || typeof fbq === 'function') {
            fireQueuedEvents();
            return;
        }

        // fbq doesn't exist yet — watch for it (zero overhead, no timers).
        // Consent managers block fbq until the
        // user accepts. This fires the moment they assign window.fbq.
        var _fbq = window.fbq;
        Object.defineProperty(window, 'fbq', {
            configurable: true,
            enumerable: true,
            get: function() { return _fbq; },
            set: function(value) {
                _fbq = value;
                if (typeof value === 'function') {
                    // Restore normal property so FB SDK works normally
                    Object.defineProperty(window, 'fbq', {
                        configurable: true,
                        enumerable: true,
                        writable: true,
                        value: value
                    });
                    setTimeout(fireQueuedEvents, 0);
                }
            }
        });
    }

    // Start
    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }

})();
