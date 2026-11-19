/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Tests for the browser side of signal hold/release: the inline FacebookSignals
 * runtime rendered by WC_Facebookcommerce_Pixel::get_facebook_signals_js() and
 * the fbwcsignal helper (assets/js/facebook-for-woocommerce-signals.js).
 *
 * Both scripts are executed exactly as shipped so the tests cover the real
 * code paths, in particular a visitor withdrawing consent while a release is
 * still in flight.
 *
 * @package FacebookCommerce
 */

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const STATE_COOKIE = 'wc_facebook_signals_state';

function loadInlineRuntimeSource() {
    const php = fs.readFileSync(path.join(ROOT, 'facebook-commerce-pixel-event.php'), 'utf8');
    const match = php.match(/return <<<'JS'\n([\s\S]*?)\nJS;/);
    if (!match) {
        throw new Error('FacebookSignals heredoc not found in facebook-commerce-pixel-event.php');
    }
    return match[1];
}

const INLINE_RUNTIME = loadInlineRuntimeSource();
const HELPER = fs.readFileSync(
    path.join(ROOT, 'assets', 'js', 'facebook-for-woocommerce-signals.js'),
    'utf8'
);

/**
 * Minimal XMLHttpRequest stand-in: records every request and lets a test
 * answer it whenever it wants, which is what makes the race reproducible.
 */
class FakeXHR {
    constructor() {
        this.headers = {};
        this.status = 0;
        this.responseText = '';
        FakeXHR.instances.push(this);
    }
    open(method, url) {
        this.method = method;
        this.url = url;
    }
    setRequestHeader(name, value) {
        this.headers[name] = value;
    }
    send(body) {
        this.body = body || '';
    }
    /**
     * Answers the request. `setCookie` mimics a Set-Cookie header: the browser
     * applies it before the load event reaches page code.
     */
    respond(status, body, options) {
        this.status = status;
        this.responseText = typeof body === 'string' ? body : JSON.stringify(body);
        if (options && options.setCookie) {
            document.cookie = options.setCookie;
        }
        if (this.onload) {
            this.onload();
        }
    }
}
FakeXHR.instances = [];

function setStateCookie(value) {
    document.cookie = `${STATE_COOKIE}=${value};path=/`;
}

function clearStateCookie() {
    document.cookie = `${STATE_COOKIE}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`;
}

function readStateCookie() {
    const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${STATE_COOKIE}=([^;]*)`));
    return match ? match[1] : null;
}

function flushPromises() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}

function fbqCalls(name) {
    return global.fbq.mock.calls.filter((call) => call[0] === name);
}

function consentCalls() {
    return fbqCalls('consent').map((call) => call[1]);
}

/**
 * Boots the page the way PHP renders it: inline runtime in <head>, then the
 * helper script in the footer.
 *
 * @param {string|null} cookie Initial state cookie, as the server would have set it.
 */
function bootPage(cookie) {
    delete window.FacebookSignals;
    delete window.fbwcsignal;
    clearStateCookie();
    if (cookie) {
        setStateCookie(cookie);
    }

    new Function(INLINE_RUNTIME)();
    window.FacebookSignals.init({
        held: false,
        ajaxUrl: '/wp-admin/admin-ajax.php',
        action: 'facebook_release_signals',
        pixelId: '123',
        attribution: {},
    });
    window.FacebookSignals.initPixel('123', {}, { agent: 'test-agent' });

    new Function(HELPER)();

    return window.FacebookSignals;
}

function releaseRequests() {
    return FakeXHR.instances.filter((xhr) => xhr.url.indexOf('action=facebook_release_signals') !== -1);
}

function stateRequests() {
    return FakeXHR.instances.filter((xhr) => xhr.url.indexOf('action=facebook_release_signals') === -1);
}

describe('Signals hold/release browser runtime', function () {
    beforeEach(function () {
        global.fbq = jest.fn();
        global.XMLHttpRequest = FakeXHR;
        FakeXHR.instances = [];
        global.wc_facebook_signals_params = {
            ajax_url: '/wp-admin/admin-ajax.php',
            nonce: 'nonce',
            action: 'wc_facebook_update_signals_state',
        };
        try {
            window.sessionStorage.clear();
        } catch (e) {
            // ignore
        }
    });

    afterEach(function () {
        delete global.fbq;
        delete global.wc_facebook_signals_params;
        delete window.FacebookSignals;
        delete window.fbwcsignal;
        clearStateCookie();
    });

    describe('FacebookSignals.release()', function () {
        it('grants consent and replays the queue after an uninterrupted round-trip', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });
            expect(signals._queue).toHaveLength(1);
            const queuedId = signals._queue[0].event_id;

            // The helper writes `active` before it asks the runtime to release.
            setStateCookie('active');
            const pending = signals.release();

            expect(releaseRequests()).toHaveLength(1);
            const request = releaseRequests()[0];
            expect(request.method).toBe('POST');
            expect(JSON.parse(request.body).events).toHaveLength(1);

            request.respond(200, { success: true, data: { sent_count: 1 } });
            await pending;

            expect(signals._held).toBe(false);
            expect(signals._releasing).toBe(false);
            expect(signals._pixelInitialized).toBe(true);
            expect(consentCalls()).toEqual(['revoke', 'grant']);
            expect(fbqCalls('init')).toHaveLength(1);
            expect(fbqCalls('track')).toEqual([
                ['track', 'ViewContent', { content_ids: ['1'] }, { eventID: queuedId }],
            ]);
            expect(signals._queue).toHaveLength(0);
        });

        it('stays held and replays nothing when a hold lands while the release is in flight', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });

            setStateCookie('active');
            const pending = signals.release();
            expect(releaseRequests()).toHaveLength(1);

            // Visitor withdraws before the server answers.
            signals.hold();

            const queuedId = JSON.parse(releaseRequests()[0].body).events[0].event_id;
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 1, sent_event_ids: [queuedId] } });
            const response = await pending;

            expect(response.data.sent_count).toBe(1);
            expect(signals._held).toBe(true);
            expect(signals._releasing).toBe(false);
            expect(signals._pixelInitialized).toBe(false);
            expect(consentCalls()).toEqual(['revoke', 'revoke']);
            expect(fbqCalls('init')).toHaveLength(0);
            expect(fbqCalls('track')).toHaveLength(0);
            // The server already sent these, so they must not be posted again.
            expect(signals._queue).toHaveLength(0);
        });

        it('keeps the queue when the interrupted release sent nothing', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });

            setStateCookie('active');
            const pending = signals.release();
            signals.hold();

            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 0, sent_event_ids: [] } });
            await pending;

            expect(signals._held).toBe(true);
            expect(signals._queue).toHaveLength(1);
            expect(fbqCalls('track')).toHaveLength(0);
        });

        it('drops only the events the server reports as sent', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });
            signals.trackEvent('AddToCart', { content_ids: ['1'] });
            signals.trackEvent('Purchase', { value: 10, currency: 'USD' });

            setStateCookie('active');
            const pending = signals.release();
            signals.hold();

            // The server capped or filtered the batch: it sent the first two only.
            const posted = JSON.parse(releaseRequests()[0].body).events;
            expect(posted).toHaveLength(3);
            releaseRequests()[0].respond(200, {
                success: true,
                data: { sent_count: 2, sent_event_ids: [posted[0].event_id, posted[1].event_id] },
            });
            await pending;

            expect(signals._held).toBe(true);
            expect(signals._queue.map((ev) => ev.event_name)).toEqual(['Purchase']);
            expect(signals._queue[0].event_id).toBe(posted[2].event_id);
        });

        it('keeps every event when the response does not list sent ids', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });

            setStateCookie('active');
            const pending = signals.release();
            signals.hold();

            // An older server build answers with a count only.
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 1 } });
            await pending;

            expect(signals._held).toBe(true);
            expect(signals._queue).toHaveLength(1);
        });

        it('puts the held cookie back when a stale release response carries an active cookie', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });

            setStateCookie('active');
            const pending = signals.release();
            signals.hold();
            expect(readStateCookie()).toBe('held');

            // A CMP bridge answered the release request with the state it saw then.
            const queuedId = JSON.parse(releaseRequests()[0].body).events[0].event_id;
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 1, sent_event_ids: [queuedId] } }, {
                setCookie: `${STATE_COOKIE}=active;path=/`,
            });
            await pending;

            expect(signals._held).toBe(true);
            expect(readStateCookie()).toBe('held');
            expect(window.fbwcsignal.getState()).toBe('held');
        });

        it('puts the held cookie back when a stale release response fails with an active cookie', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });

            setStateCookie('active');
            const pending = signals.release();
            signals.hold();

            releaseRequests()[0].respond(403, { success: false, data: { message: 'Signals are held.' } }, {
                setCookie: `${STATE_COOKIE}=active;path=/`,
            });
            await pending.catch(() => {});

            expect(signals._held).toBe(true);
            expect(readStateCookie()).toBe('held');
            expect(signals._queue).toHaveLength(1);
        });

        it('does not post the queue when the state cookie already says held', async function () {
            const signals = bootPage('held');
            signals.trackEvent('ViewContent', { content_ids: ['1'] });

            const response = await signals.release();

            expect(response.data.sent_count).toBe(0);
            expect(releaseRequests()).toHaveLength(0);
            expect(signals._held).toBe(true);
            expect(signals._queue).toHaveLength(1);
        });
    });

    describe('fbwcsignal.hold()', function () {
        it('revokes browser consent synchronously, before the state update returns', async function () {
            const signals = bootPage('active');
            expect(signals._held).toBe(false);
            expect(signals._pixelInitialized).toBe(true);

            const pending = window.fbwcsignal.hold();

            // Everything below is observable before the server has answered.
            expect(signals._held).toBe(true);
            expect(consentCalls()).toEqual(['grant', 'revoke']);
            expect(readStateCookie()).toBe('held');
            expect(stateRequests()).toHaveLength(1);
            expect(stateRequests()[0].body).toContain('state=held');

            stateRequests()[0].respond(200, { success: true, data: { state: 'held' } });
            await pending;

            expect(signals._held).toBe(true);
        });
    });

    describe('fbwcsignal.release()', function () {
        it('releases the runtime after the state update succeeds', async function () {
            const signals = bootPage('held');
            signals.trackEvent('AddToCart', { content_ids: ['2'] });

            const pending = window.fbwcsignal.release();
            expect(readStateCookie()).toBe('active');
            expect(stateRequests()).toHaveLength(1);

            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } });
            await flushPromises();

            expect(releaseRequests()).toHaveLength(1);
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 1 } });
            await pending;

            expect(signals._held).toBe(false);
            expect(fbqCalls('track')).toHaveLength(1);
            expect(signals._queue).toHaveLength(0);
        });

        it('skips the runtime release when a hold lands during the state update', async function () {
            const signals = bootPage('held');
            signals.trackEvent('AddToCart', { content_ids: ['2'] });

            const pending = window.fbwcsignal.release();
            expect(stateRequests()).toHaveLength(1);

            // Visitor withdraws while the `active` state update is still in flight.
            window.fbwcsignal.hold();
            expect(stateRequests()).toHaveLength(2);
            expect(readStateCookie()).toBe('held');

            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } });
            await flushPromises();
            await pending;

            expect(releaseRequests()).toHaveLength(0);
            expect(signals._held).toBe(true);
            expect(signals._queue).toHaveLength(1);
            expect(fbqCalls('track')).toHaveLength(0);

            stateRequests()[1].respond(200, { success: true, data: { state: 'held' } });
        });

        it('keeps the cookie on the latest requested state when a stale response lands last', async function () {
            const signals = bootPage('held');
            signals.trackEvent('AddToCart', { content_ids: ['2'] });

            const releasing = window.fbwcsignal.release();
            const holding = window.fbwcsignal.hold();
            expect(stateRequests()).toHaveLength(2);
            expect(readStateCookie()).toBe('held');

            // The newer hold is answered first; PHP sets the cookie it applied.
            stateRequests()[1].respond(200, { success: true, data: { state: 'held' } }, {
                setCookie: `${STATE_COOKIE}=held;path=/`,
            });
            await holding;
            expect(readStateCookie()).toBe('held');

            // Then the older release-state response arrives with its own Set-Cookie.
            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } }, {
                setCookie: `${STATE_COOKIE}=active;path=/`,
            });
            await flushPromises();
            await releasing;

            expect(readStateCookie()).toBe('held');
            expect(window.fbwcsignal.getState()).toBe('held');
            expect(signals._held).toBe(true);
            expect(releaseRequests()).toHaveLength(0);
            expect(signals._queue).toHaveLength(1);
        });

        it('honours a release requested after a withdrawal while the first release is in flight', async function () {
            const signals = bootPage('held');
            signals.trackEvent('AddToCart', { content_ids: ['2'] });

            // 1. Release: state update, then the release POST goes out.
            const first = window.fbwcsignal.release();
            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } });
            await flushPromises();
            expect(releaseRequests()).toHaveLength(1);

            // 2. Withdraw while that POST is pending.
            const holding = window.fbwcsignal.hold();
            stateRequests()[1].respond(200, { success: true, data: { state: 'held' } });
            await holding;
            expect(signals._held).toBe(true);

            // 3. Consent again, still before the first POST has answered.
            const second = window.fbwcsignal.release();
            stateRequests()[2].respond(200, { success: true, data: { state: 'active' } });
            await flushPromises();
            expect(releaseRequests()).toHaveLength(1);

            // 4. The first POST answers: its response is stale and discarded...
            const firstPosted = JSON.parse(releaseRequests()[0].body).events;
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 1, sent_event_ids: [firstPosted[0].event_id] } });
            await first;
            await flushPromises();

            // ...and the newer release now runs instead of being dropped.
            expect(releaseRequests()).toHaveLength(2);
            releaseRequests()[1].respond(200, { success: true, data: { sent_count: 0 } });
            await second;

            expect(signals._held).toBe(false);
            expect(signals._releasing).toBe(false);
            expect(signals._pixelInitialized).toBe(true);
            expect(consentCalls().slice(-1)).toEqual(['grant']);
            expect(window.fbwcsignal.getState()).toBe('active');
            expect(signals._queue).toHaveLength(0);
        });

        it('drops a queued release when another hold arrives before it runs', async function () {
            const signals = bootPage('held');
            signals.trackEvent('AddToCart', { content_ids: ['2'] });

            const first = window.fbwcsignal.release();
            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } });
            await flushPromises();
            expect(releaseRequests()).toHaveLength(1);

            window.fbwcsignal.hold();
            stateRequests()[1].respond(200, { success: true, data: { state: 'held' } });
            const second = window.fbwcsignal.release();
            stateRequests()[2].respond(200, { success: true, data: { state: 'active' } });
            await flushPromises();

            // A further withdrawal before the first POST answers.
            window.fbwcsignal.hold();
            stateRequests()[3].respond(200, { success: true, data: { state: 'held' } });

            const postedOnce = JSON.parse(releaseRequests()[0].body).events;
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 1, sent_event_ids: [postedOnce[0].event_id] } });
            await first;
            await second;
            await flushPromises();

            expect(releaseRequests()).toHaveLength(1);
            expect(signals._held).toBe(true);
            expect(signals._pixelInitialized).toBe(false);
            expect(window.fbwcsignal.getState()).toBe('held');
        });
    });

    describe('FacebookSignals.queueEvent() on a page from a full-page cache', function () {
        /**
         * The event a held render bakes into the HTML. A full-page cache serves the
         * same HTML, and so the same event_id, on every view of the page.
         */
        function cachedPageView() {
            return {
                event_name: 'PageView',
                custom_data: {},
                event_id: 'cached-page-a',
                event_time: Math.floor(Date.now() / 1000),
                method: 'track',
            };
        }

        function pageViews() {
            return fbqCalls('track').filter((call) => call[1] === 'PageView');
        }

        it('ignores the same event queued twice in one page load', function () {
            const signals = bootPage('held');
            signals.queueEvent(cachedPageView());
            signals.queueEvent(cachedPageView());

            expect(signals._queue).toHaveLength(1);
        });

        it('fires the PageView when the page is viewed again after signals were released on another page', async function () {
            // Page A, held: the PageView is queued.
            bootPage('held').queueEvent(cachedPageView());

            // Page B: the visitor releases signals there.
            bootPage('held');
            window.fbwcsignal.release();
            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } }, {
                setCookie: `${STATE_COOKIE}=active;path=/`,
            });
            await flushPromises();
            releaseRequests()[0].respond(200, { success: true, data: { sent_count: 0 } });
            await flushPromises();
            global.fbq.mockClear();

            // Page A again, same cached HTML.
            bootPage('active').queueEvent(cachedPageView());

            expect(pageViews()).toEqual([['track', 'PageView', {}, { eventID: expect.any(String) }]]);
            expect(pageViews()[0][3].eventID).not.toBe('cached-page-a');
        });

        it('queues the PageView again on a second held view and sends it on release', async function () {
            bootPage('held').queueEvent(cachedPageView());

            const signals = bootPage('held');
            signals.queueEvent(cachedPageView());
            expect(signals._queue).toHaveLength(1);

            window.fbwcsignal.release();
            stateRequests()[0].respond(200, { success: true, data: { state: 'active' } }, {
                setCookie: `${STATE_COOKIE}=active;path=/`,
            });
            await flushPromises();

            const posted = JSON.parse(releaseRequests()[0].body).events;
            expect(posted.map((event) => event.event_name)).toEqual(['PageView']);
        });

        it('does not keep queued event ids in sessionStorage', function () {
            bootPage('held').queueEvent(cachedPageView());

            expect(window.sessionStorage.getItem('wc_facebook_signals_seen_event_ids')).toBeNull();
        });

        afterEach(function () {
            delete window.__wc_fb_page_generated;
            jest.restoreAllMocks();
        });

        it.each([0, 60, 1795, 1800, 1801, 3600])('uses a new visit time and id for a cached copy aged %i seconds', function (age) {
            const now = 2000000000;
            jest.spyOn(Date, 'now').mockReturnValue(now * 1000);
            window.__wc_fb_page_generated = now - age;
            const event = Object.assign(cachedPageView(), { event_time: now - age });
            const original = { ...event };
            const signals = bootPage('held');
            signals.queueEvent(event);

            expect(signals._queue[0].event_time).toBe(now);
            expect(signals._queue[0].event_id).not.toBe(event.event_id);
            expect(event).toEqual(original);
        });

        it.each([60, 3600])('deduplicates active inline replays on a cached copy aged %i seconds', function (age) {
            window.__wc_fb_page_generated = Math.floor(Date.now() / 1000) - age;
            const signals = bootPage('active');
            const event = cachedPageView();
            signals.queueEvent(event);
            signals.queueEvent(event);

            expect(pageViews()).toHaveLength(1);
            expect(pageViews()[0][3].eventID).not.toBe(event.event_id);
        });

        it.each([0, 60, 3600])('uses distinct event ids across active views of a cached copy aged %i seconds', function (age) {
            window.__wc_fb_page_generated = Math.floor(Date.now() / 1000) - age;
            const event = cachedPageView();
            bootPage('active').queueEvent(event);
            bootPage('active').queueEvent(event);

            expect(pageViews()).toHaveLength(2);
            const ids = pageViews().map((call) => call[3].eventID);
            expect(ids[0]).not.toBe(event.event_id);
            expect(ids[1]).not.toBe(event.event_id);
            expect(ids[1]).not.toBe(ids[0]);
        });

        it('deduplicates active replays before pixel initialization', function () {
            const signals = bootPage('active');
            signals._pixelInitialized = false;
            const event = cachedPageView();
            signals.queueEvent(event);
            signals.queueEvent(event);
            expect(signals._pendingPixelEvents).toHaveLength(1);
            signals._runPixelInit();
            expect(pageViews()).toHaveLength(1);
        });

        it('releases a recent visit near the cache age boundary with matching browser and CAPI ids', async function () {
            const now = 2000000000;
            const clock = jest.spyOn(Date, 'now').mockReturnValue(now * 1000);
            window.__wc_fb_page_generated = now - 1795;
            const event = Object.assign(cachedPageView(), { event_time: now - 1795 });
            const signals = bootPage('held');
            signals.queueEvent(event);

            clock.mockReturnValue((now + 10) * 1000);
            setStateCookie('active');
            const released = signals.release();
            const request = releaseRequests()[0];
            const posted = JSON.parse(request.body).events;
            expect(posted).toHaveLength(1);
            expect(now + 10 - posted[0].event_time).toBe(10);
            request.respond(200, { success: true, data: { sent_count: 1, sent_event_ids: [posted[0].event_id] } });
            await released;

            expect(pageViews()).toEqual([['track', 'PageView', {}, { eventID: posted[0].event_id }]]);
            signals.queueEvent(event);
            expect(pageViews()).toHaveLength(1);
            expect(signals._queue).toHaveLength(0);
        });

        it('preserves the matching server id for a live active event outside the held-render queue', function () {
            bootPage('active').trackEvent('PageView', {}, null, 'track', 'live-server-event');
            expect(pageViews()).toEqual([['track', 'PageView', {}, { eventID: 'live-server-event' }]]);
        });

        it.each(['Purchase', 'Subscribe', 'AddToCart', 'InitiateCheckout', 'Lead', 'CustomAction'])('retains the server id for an active %s from a held render', function (name) {
            const signals = bootPage('active');
            const method = name === 'CustomAction' ? 'trackCustom' : 'track';
            const event = { event_name: name, event_id: 'server-action-id', event_time: Math.floor(Date.now() / 1000), custom_data: { value: 50 }, method };
            signals.queueEvent(event);
            signals.queueEvent(event);

            expect(fbqCalls(method)).toEqual([[method, name, { value: 50 }, { eventID: 'server-action-id' }]]);
            expect(signals._queue).toHaveLength(0);
        });

        it.each(['PageView', 'ViewContent', 'ViewCategory', 'Search'])('still gives each active cached %s visit its own id', function (name) {
            const event = { event_name: name, event_id: 'cached-visit-id', custom_data: {} };
            bootPage('active').queueEvent(event);
            bootPage('active').queueEvent(event);

            const calls = fbqCalls('track');
            expect(calls).toHaveLength(2);
            expect(calls[0][3].eventID).not.toBe(event.event_id);
            expect(calls[1][3].eventID).not.toBe(event.event_id);
            expect(calls[0][3].eventID).not.toBe(calls[1][3].eventID);
        });

        it('keeps the existing shared browser and CAPI id when a held Purchase is released', async function () {
            const signals = bootPage('held');
            signals.queueEvent({ event_name: 'Purchase', event_id: 'order-id', custom_data: { value: 50 } });
            setStateCookie('active');
            const released = signals.release();
            const request = releaseRequests()[0];
            const posted = JSON.parse(request.body).events;
            expect(posted).toHaveLength(1);
            request.respond(200, { success: true, data: { sent_count: 1, sent_event_ids: [posted[0].event_id] } });
            await released;

            expect(fbqCalls('track')).toEqual([['track', 'Purchase', { value: 50 }, { eventID: posted[0].event_id }]]);
        });
    });
});
