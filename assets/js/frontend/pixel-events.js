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
     * Whether the Pixel library has an initialised pixel, or an init call
     * already queued for it.
     *
     * The wrapper is what initialises the Pixel; without it, firing before
     * fbq('init') gives the library nothing to send the event to. Returns true
     * when fbq offers no way to tell, so a plain stub behaves as before.
     *
     * @return {boolean}
     */
    function pixelInitialised() {
        if (typeof fbq !== 'function') {
            return false;
        }
        try {
            if (typeof fbq.getState === 'function') {
                var state = fbq.getState();
                return !!(state && state.pixels && state.pixels.length);
            }
            if (fbq.queue && typeof fbq.queue.length === 'number') {
                for (var i = 0; i < fbq.queue.length; i++) {
                    if (fbq.queue[i] && fbq.queue[i][0] === 'init') {
                        return true;
                    }
                }
                return false;
            }
        } catch (e) {}
        return true;
    }

    /**
     * Whether an event can go straight to fbq(): the library is there and a
     * pixel is, or is about to be, initialised.
     *
     * @return {boolean}
     */
    function canSendDirectly() {
        return typeof fbq === 'function' && pixelInitialised();
    }

    /**
     * Sends one event straight through fbq().
     *
     * @param {Object} ev Event with name, params, method, eventId and, for
     *                    events the inline code queued, agent and pixelId.
     */
    function sendDirectly(ev) {
        if (ev.agent && ev.pixelId) {
            fbq('set', 'agent', ev.agent, ev.pixelId);
        }
        if (ev.eventId) {
            fbq(ev.method || 'track', ev.name, ev.params || {}, { eventID: ev.eventId });
        } else {
            fbq(ev.method || 'track', ev.name, ev.params || {});
        }
    }

    /**
     * Keeps an event in the page-level queue the inline event code also uses,
     * for the wrapper to drain when it initialises or for drainInlineQueue()
     * once a pixel is initialised, and starts waiting for either.
     *
     * @param {Object} eventData Prepared event data.
     */
    function queueForLater(eventData) {
        window.wcFacebookPixelQueuedEvents = window.wcFacebookPixelQueuedEvents || [];
        window.wcFacebookPixelQueuedEvents.push({
            name: eventData.name,
            params: eventData.params,
            method: eventData.method,
            eventId: eventData.eventId
        });
        waitForSender();
    }

    /**
     * Fire a single event.
     *
     * Hands it to the FacebookSignals wrapper when present, sends it through
     * fbq() when only an initialised Pixel is present, and otherwise keeps it
     * queued until one of them arrives (e.g. a consent manager activating the
     * base code after this script ran).
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
            if (hasSignalsWrapper()) {
                window.FacebookSignals.trackEvent(eventData.name, eventData.params, null, eventData.method, eventData.eventId);
            } else if (canSendDirectly()) {
                sendDirectly(eventData);
            } else {
                queueForLater(eventData);
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
     * Sends the events waiting in the page-level queue straight through fbq().
     * Only used without the wrapper, which drains the queue itself, and only
     * once a pixel is initialised.
     */
    function drainInlineQueue() {
        var queue = window.wcFacebookPixelQueuedEvents;
        if (!queue || !queue.length || !canSendDirectly()) {
            return;
        }
        window.wcFacebookPixelQueuedEvents = [];

        for (var i = 0; i < queue.length; i++) {
            var ev = queue[i];
            if (!ev || !ev.name) {
                continue;
            }
            try {
                sendDirectly(ev);
            } catch (e) {
                logWarning('Queued event error: ' + ev.name, e);
            }
        }
    }

    var waitingForSender = false;

    /**
     * Waits, once at a time, for something that can send the queued events:
     * the wrapper, which drains the queue itself when it initialises, or an
     * initialised pixel. Watches for fbq while it is missing, then polls while
     * fbq exists without an initialised pixel, for at most a minute.
     */
    function waitForSender() {
        if (waitingForSender) {
            return;
        }
        waitingForSender = true;

        function attempt() {
            if (hasSignalsWrapper()) {
                waitingForSender = false;
                return true;
            }
            if (canSendDirectly()) {
                waitingForSender = false;
                drainInlineQueue();
                return true;
            }
            return false;
        }

        function pollForSender() {
            var attempts = 0;
            var poll = setInterval(function() {
                attempts++;
                if (attempt()) {
                    clearInterval(poll);
                } else if (attempts >= 120) {
                    clearInterval(poll);
                    waitingForSender = false;
                    logWarning('No Meta Pixel initialised, events kept queued:', (window.wcFacebookPixelQueuedEvents || []).length);
                }
            }, 500);
        }

        if (typeof fbq === 'function') {
            pollForSender();
        } else {
            watchForFbq(function() {
                if (!attempt()) {
                    pollForSender();
                }
            });
        }
    }

    /**
     * Runs the callback once window.fbq is a function, at most once.
     *
     * Prefers an accessor on window.fbq, chained onto any accessor another
     * script already installed, so the callback runs the moment the Pixel
     * snippet assigns it (e.g. when a consent manager activates it) with no
     * timers in between. Falls back to a bounded poll when the property cannot
     * be redefined, and checks again on load in case another script replaced
     * the accessor meanwhile.
     *
     * Mirrored in facebook-commerce-pixel-event.php (FacebookSignals._startFbqWatch):
     * keep both copies identical, tests/js/fbq-watcher-sync.test.js compares them.
     *
     * @param {Function} callback Called once, after fbq exists.
     * @return {{installed: boolean, cancel: Function}} Whether the accessor was
     *     installed, and a way to stop waiting.
     */
    function watchForFbq(callback) {
        var handled = false;
        var poll = null;

        function handle() {
            if (handled || typeof fbq !== 'function') {
                return;
            }
            handled = true;
            if (poll) {
                clearInterval(poll);
                poll = null;
            }
            callback();
        }

        // Another script may replace window.fbq without going through the
        // accessor below; check again once the page has loaded.
        try {
            window.addEventListener('load', handle);
        } catch (e) {}

        var descriptor = null;
        try {
            descriptor = Object.getOwnPropertyDescriptor(window, 'fbq') || null;
        } catch (e) {
            descriptor = { configurable: false };
        }

        var installed = false;
        if (!descriptor || descriptor.configurable) {
            var previousGet = descriptor ? descriptor.get : undefined;
            var previousSet = descriptor ? descriptor.set : undefined;
            var current = descriptor && Object.prototype.hasOwnProperty.call(descriptor, 'value') ? descriptor.value : undefined;
            try {
                Object.defineProperty(window, 'fbq', {
                    configurable: true,
                    enumerable: true,
                    get: function() {
                        return previousGet ? previousGet.call(window) : current;
                    },
                    set: function(value) {
                        current = value;
                        if (previousSet) {
                            previousSet.call(window, value);
                        } else if (typeof value === 'function') {
                            // Restore a normal property so the Pixel library works as usual.
                            Object.defineProperty(window, 'fbq', {
                                configurable: true,
                                enumerable: true,
                                writable: true,
                                value: value
                            });
                        }
                        if (typeof value === 'function' && !handled) {
                            // The snippet assigns window.fbq before it attaches the call
                            // queue, so let it finish first.
                            setTimeout(handle, 0);
                        }
                    }
                });
                installed = true;
            } catch (e) {}
        }

        if (!installed) {
            // Last resort, e.g. a script declared fbq with var: check twice a
            // second for a minute.
            var attempts = 0;
            poll = setInterval(function() {
                attempts++;
                if (typeof fbq === 'function' || attempts >= 120) {
                    clearInterval(poll);
                    poll = null;
                    handle();
                }
            }, 500);
        }

        return {
            installed: installed,
            cancel: function() {
                handled = true;
                if (poll) {
                    clearInterval(poll);
                    poll = null;
                }
            }
        };
    }

    /**
     * Initialize pixel event handling.
     *
     * When the FacebookSignals wrapper is present, queued events are handed to
     * it right away: it queues them until the Pixel can be initialised and
     * flushes them itself. Without it, events go straight to fbq() only once a
     * pixel is initialised; until then (e.g. a consent manager still blocking
     * the base code) they wait in the page-level queue, see waitForSender().
     *
     * Also sets up Store API interceptor for WooCommerce Blocks AJAX AddToCart.
     */
    function init() {
        // Set up fetch interceptor for WooCommerce Blocks Store API
        setupFetchInterceptor();

        // Each event goes to the wrapper, straight to an initialised Pixel, or
        // into the page-level queue, which also starts waiting for a sender.
        fireQueuedEvents();

        if (hasSignalsWrapper()) {
            // The wrapper drained the page-level queue when it initialised.
            return;
        }

        if (canSendDirectly()) {
            drainInlineQueue();
        } else if (window.wcFacebookPixelQueuedEvents && window.wcFacebookPixelQueuedEvents.length) {
            waitForSender();
        }
    }

    // Start
    if (document.readyState === 'complete') {
        init();
    } else {
        window.addEventListener('load', init);
    }

})();
