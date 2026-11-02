/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Tests for the inline FacebookSignals wrapper rendered by
 * WC_Facebookcommerce_Pixel::get_facebook_signals_js().
 *
 * The wrapper lives in facebook-commerce-pixel-event.php as a nowdoc, so the
 * tests extract it from there and evaluate it in jsdom. They cover the case
 * where a consent manager or script optimiser runs the Pixel base code only
 * after the wrapper initialised, so window.fbq does not exist yet.
 *
 * @package FacebookCommerce
 */

const fs = require('fs');
const path = require('path');

function loadSignalsSource() {
    const php = fs.readFileSync(path.join(__dirname, '..', '..', 'facebook-commerce-pixel-event.php'), 'utf8');
    const marker = 'window.FacebookSignals = window.FacebookSignals ||';
    const markerIndex = php.indexOf(marker);
    const opener = "<<<'JS'";
    const start = php.lastIndexOf(opener, markerIndex) + opener.length;
    const end = php.indexOf('\nJS;', markerIndex);
    return php.slice(start, end);
}

const signalsSource = loadSignalsSource();

function resetFbq() {
    try {
        Object.defineProperty(window, 'fbq', {
            configurable: true,
            enumerable: true,
            writable: true,
            value: undefined,
        });
    } catch (e) {
        // Ignore if the property does not exist.
    }
    delete window.fbq;
}

function loadSignals() {
    delete window.FacebookSignals;
    new Function(signalsSource)(); // eslint-disable-line no-new-func
    return window.FacebookSignals;
}

/**
 * Mimics the Meta Pixel snippet: window.fbq is assigned first and the call
 * queue is attached afterwards, so anything run synchronously from a setter
 * would hit a stub that cannot queue calls yet.
 *
 * @param {Array} calls Receives every fbq() call as an argument array.
 * @return {Function} The stub.
 */
function installPixelStub(calls) {
    const stub = function () {
        calls.push(Array.prototype.slice.call(arguments));
    };
    window.fbq = stub;
    stub.loaded = true;
    stub.queue = [];
    return stub;
}

describe('FacebookSignals inline wrapper', function () {
    beforeEach(function () {
        jest.useFakeTimers();
        resetFbq();
        document.cookie = 'wc_facebook_signals_state=; max-age=0';
        window.sessionStorage.clear();
    });

    afterEach(function () {
        // Stop stale load listeners and polls of this test's wrapper from
        // touching the next test's fbq.
        if (window.FacebookSignals) {
            window.FacebookSignals._fbqHandled = true;
        }
        jest.clearAllTimers();
        jest.useRealTimers();
        resetFbq();
        delete window.FacebookSignals;
        delete window.wcFacebookPixelQueuedEvents;
    });

    it('fires events straight away when fbq already exists at initPixel() time', function () {
        const calls = [];
        installPixelStub(calls);

        const signals = loadSignals();
        signals.init({ held: false });
        signals.initPixel('123', {}, { agent: 'woocommerce_2-1.0.0-2.0.0' });
        signals.setAgent('woocommerce_2-1.0.0-2.0.0');
        signals.trackEvent('PageView', {}, null, 'track', 'event-1');

        expect(signals._fbqWatcherInstalled).toBe(false);
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, { agent: 'woocommerce_2-1.0.0-2.0.0' }],
            ['set', 'agent', 'woocommerce_2-1.0.0-2.0.0', '123'],
            ['track', 'PageView', {}, { eventID: 'event-1' }],
        ]);
    });

    it('initialises the Pixel and flushes pending events once fbq is assigned after initPixel()', function () {
        const signals = loadSignals();
        signals.init({ held: false, ajaxUrl: '/admin-ajax.php', action: 'facebook_release_signals' });
        signals.initPixel('123', { em: 'hash' }, { agent: 'woocommerce_2-1.0.0-2.0.0' });
        signals.trackEvent('PageView', { source: 'woocommerce_2' }, null, 'track', 'event-1');
        signals.trackEvent('ViewContent', { content_ids: '["1"]' }, null, 'track', 'event-2');

        expect(signals._pixelInitialized).toBe(false);
        expect(signals._pendingPixelEvents.map(function (e) { return e.name; })).toEqual(['PageView', 'ViewContent']);

        const calls = [];
        installPixelStub(calls);
        // Nothing may run synchronously inside the property setter.
        expect(calls).toEqual([]);

        jest.runAllTimers();

        expect(signals._pixelInitialized).toBe(true);
        expect(signals._pendingPixelEvents).toEqual([]);
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', { em: 'hash' }, { agent: 'woocommerce_2-1.0.0-2.0.0' }],
            ['track', 'PageView', { source: 'woocommerce_2' }, { eventID: 'event-1' }],
            ['track', 'ViewContent', { content_ids: '["1"]' }, { eventID: 'event-2' }],
        ]);
    });

    it('leaves window.fbq as a plain writable property once the library arrived', function () {
        const signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});

        const stub = installPixelStub([]);
        jest.runAllTimers();

        const descriptor = Object.getOwnPropertyDescriptor(window, 'fbq');
        expect(descriptor.value).toBe(stub);
        expect(descriptor.writable).toBe(true);
        expect(descriptor.get).toBeUndefined();
    });

    it('applies the agent reported by event code once the Pixel initialises', function () {
        const signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});
        signals.setAgent('woocommerce_2-1.0.0-2.0.0_c');

        const calls = [];
        installPixelStub(calls);
        jest.runAllTimers();

        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
            ['set', 'agent', 'woocommerce_2-1.0.0-2.0.0_c', '123'],
        ]);

        signals.setAgent('woocommerce_2-1.0.0-2.0.0');
        expect(calls[calls.length - 1]).toEqual(['set', 'agent', 'woocommerce_2-1.0.0-2.0.0', '123']);
    });

    function preventFbqWatcher(signals) {
        const defineProperty = Object.defineProperty;
        const spy = jest.spyOn(Object, 'defineProperty').mockImplementation(function (target, name, descriptor) {
            if (target === window && name === 'fbq') {
                throw new TypeError('Cannot redefine property: fbq');
            }
            return defineProperty.call(Object, target, name, descriptor);
        });
        signals.initPixel('123', {}, {});
        spy.mockRestore();
        expect(signals._fbqWatcherInstalled).toBe(false);
    }

    it('polls for fbq when window.fbq cannot be watched', function () {
        const signals = loadSignals();
        signals.init({});
        preventFbqWatcher(signals);
        signals.trackEvent('PageView', {}, null, 'track', 'event-1');
        expect(signals._pendingPixelEvents).toHaveLength(1);

        const calls = [];
        installPixelStub(calls);
        expect(signals._pixelInitialized).toBe(false);

        jest.advanceTimersByTime(500);

        expect(signals._pixelInitialized).toBe(true);
        expect(signals._fbqPoll).toBeNull();
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
            ['track', 'PageView', {}, { eventID: 'event-1' }],
        ]);
    });

    it('initialises the Pixel on the next event after the poll has given up', function () {
        const signals = loadSignals();
        signals.init({});
        preventFbqWatcher(signals);
        signals.trackEvent('PageView', {}, null, 'track', 'event-1');

        jest.advanceTimersByTime(60 * 1000);
        expect(signals._fbqPoll).toBeNull();

        const calls = [];
        installPixelStub(calls);
        jest.runAllTimers();
        expect(signals._pixelInitialized).toBe(false);

        signals.trackEvent('AddToCart', { value: 1 }, null, 'track', 'event-2');

        expect(signals._pixelInitialized).toBe(true);
        expect(signals._pendingPixelEvents).toEqual([]);
        expect(calls).toEqual([
            ['init', '123', {}, {}],
            ['track', 'PageView', {}, { eventID: 'event-1' }],
            ['track', 'AddToCart', { value: 1 }, { eventID: 'event-2' }],
        ]);
    });

    it('checks for fbq again on load when another script replaced the watcher', function () {
        const signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});
        signals.trackEvent('PageView', {}, null, 'track', 'event-1');
        expect(signals._fbqWatcherInstalled).toBe(true);

        // Another script redefines window.fbq without chaining our accessor.
        Object.defineProperty(window, 'fbq', { configurable: true, enumerable: true, writable: true, value: undefined });
        const calls = [];
        installPixelStub(calls);
        jest.runAllTimers();
        expect(signals._pixelInitialized).toBe(false);

        window.dispatchEvent(new Event('load'));

        expect(signals._pixelInitialized).toBe(true);
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
            ['track', 'PageView', {}, { eventID: 'event-1' }],
        ]);
    });

    it('chains onto an existing fbq accessor and applies consent only once', function () {
        // Another script already watches window.fbq and keeps its accessor.
        let stored;
        const previousSet = jest.fn(function (value) { stored = value; });
        Object.defineProperty(window, 'fbq', {
            configurable: true,
            enumerable: true,
            get: function () { return stored; },
            set: previousSet,
        });

        const signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});
        expect(signals._fbqWatcherInstalled).toBe(true);

        const calls = [];
        const stub = installPixelStub(calls);
        expect(previousSet).toHaveBeenCalledWith(stub);
        jest.runAllTimers();
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
        ]);

        // A later reassignment must not re-send consent or re-initialise.
        const laterCalls = [];
        installPixelStub(laterCalls);
        jest.runAllTimers();
        expect(laterCalls).toEqual([]);
        expect(signals._fbqHandled).toBe(true);
    });

    it('drains events the inline code queued before the wrapper existed', function () {
        window.wcFacebookPixelQueuedEvents = [
            { name: 'PageView', params: { source: 'woocommerce_2' }, method: 'track', eventId: 'event-1', agent: 'woocommerce_2-1.0.0-2.0.0', pixelId: '123' },
        ];
        const calls = [];
        installPixelStub(calls);

        const signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});

        expect(window.wcFacebookPixelQueuedEvents).toEqual([]);
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
            ['set', 'agent', 'woocommerce_2-1.0.0-2.0.0', '123'],
            ['track', 'PageView', { source: 'woocommerce_2' }, { eventID: 'event-1' }],
        ]);
    });

    it('keeps the Pixel uninitialised while held and revokes consent when fbq arrives', function () {
        const signals = loadSignals();
        signals.init({ held: true });
        signals.initPixel('123', {}, {});
        signals.trackEvent('PageView', {}, null, 'track', 'event-1');

        expect(signals._queue).toHaveLength(1);
        expect(signals._pendingPixelEvents).toHaveLength(0);

        const calls = [];
        installPixelStub(calls);
        jest.runAllTimers();

        expect(signals._pixelInitialized).toBe(false);
        expect(calls).toEqual([['consent', 'revoke']]);
    });

    it('releases held events without fbq and sends them once the library arrives', function () {
        const signals = loadSignals();
        signals.init({ held: true });
        signals.initPixel('123', {}, {});
        signals.trackEvent('PageView', {}, null, 'track', 'event-1');

        expect(function () { signals._handleReleaseResponse({}, {}); }).not.toThrow();
        expect(signals._held).toBe(false);
        expect(signals._pendingPixelEvents.map(function (e) { return e.name; })).toEqual(['PageView']);

        const calls = [];
        installPixelStub(calls);
        jest.runAllTimers();

        expect(calls).toHaveLength(3);
        expect(calls[0]).toEqual(['consent', 'grant']);
        expect(calls[1]).toEqual(['init', '123', {}, {}]);
        expect(calls[2].slice(0, 3)).toEqual(['track', 'PageView', {}]);
        expect(typeof calls[2][3].eventID).toBe('string');
    });
});
