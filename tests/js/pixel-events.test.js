/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Tests for Facebook Pixel Events Isolated Execution (pixel-events.js)
 *
 * These tests validate the isolated JavaScript execution context for pixel events,
 * ensuring events fire even when other plugins cause JS errors.
 *
 * @package FacebookCommerce
 */

const { loadSignals } = require('./helpers/signals-source');

describe('Pixel Events - Isolated Execution Context', function () {
    let mockFbq;
    let originalFbq;
    let consoleWarnSpy;

    beforeEach(function () {
        // Store original fbq if exists
        originalFbq = global.fbq;

        // Create mock fbq function
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        // Spy on console.warn
        consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

        // Reset document ready state
        Object.defineProperty(document, 'readyState', {
            value: 'complete',
            writable: true,
        });
    });

    afterEach(function () {
        // Restore original fbq
        if (originalFbq) {
            global.fbq = originalFbq;
        } else {
            delete global.fbq;
        }

        // Restore console.warn
        consoleWarnSpy.mockRestore();

        // Clean up global data
        delete global.wc_facebook_pixel_data;

        jest.resetAllMocks();
    });

    describe('buildEventData function', function () {
        it('should build event data with default method', function () {
            const event = {
                name: 'ViewContent',
                params: { content_ids: ['123'] },
            };

            // Simulate buildEventData logic
            const eventData = {
                method: event.method || 'track',
                name: event.name,
                params: event.params || {},
                eventId: event.eventId || null,
            };

            expect(eventData.method).toBe('track');
            expect(eventData.name).toBe('ViewContent');
            expect(eventData.params.content_ids).toEqual(['123']);
            expect(eventData.eventId).toBeNull();
        });

        it('should use provided method', function () {
            const event = {
                name: 'CustomEvent',
                method: 'trackCustom',
                params: {},
            };

            const eventData = {
                method: event.method || 'track',
                name: event.name,
                params: event.params || {},
                eventId: event.eventId || null,
            };

            expect(eventData.method).toBe('trackCustom');
        });

        it('should preserve eventId when provided', function () {
            const event = {
                name: 'AddToCart',
                params: {},
                eventId: 'abc123-unique-id',
            };

            const eventData = {
                method: event.method || 'track',
                name: event.name,
                params: event.params || {},
                eventId: event.eventId || null,
            };

            expect(eventData.eventId).toBe('abc123-unique-id');
        });
    });

    describe('Event Deduplication', function () {
        it('should skip event if already fired with same eventId', function () {
            const firedEvents = {};
            const eventId = 'unique-event-123';

            // First time - should not skip
            const shouldSkip1 = eventId && firedEvents[eventId];
            expect(shouldSkip1).toBeFalsy();

            // Mark as fired
            firedEvents[eventId] = true;

            // Second time - should skip
            const shouldSkip2 = eventId && firedEvents[eventId];
            expect(shouldSkip2).toBe(true);
        });

        it('should not skip event if eventId is null', function () {
            const firedEvents = {};
            const eventId = null;

            const shouldSkip = eventId && firedEvents[eventId];
            expect(shouldSkip).toBeFalsy();
        });

        it('should not skip event if eventId is empty string', function () {
            const firedEvents = {};
            const eventId = '';

            const shouldSkip = eventId && firedEvents[eventId];
            expect(shouldSkip).toBeFalsy();
        });

        it('should track multiple different eventIds independently', function () {
            const firedEvents = {};

            firedEvents['event-1'] = true;
            firedEvents['event-2'] = true;

            expect(firedEvents['event-1']).toBe(true);
            expect(firedEvents['event-2']).toBe(true);
            expect(firedEvents['event-3']).toBeUndefined();
        });
    });

    describe('fbq Availability and Object.defineProperty Trap', function () {
        it('should fire events immediately when fbq is already available', function () {
            global.fbq = jest.fn();

            var eventsFired = false;

            function fireQueuedEvents() {
                eventsFired = true;
            }

            function init() {
                if (typeof global.fbq === 'function') {
                    fireQueuedEvents();
                    return;
                }
            }

            init();
            expect(eventsFired).toBe(true);
        });

        it('should set Object.defineProperty trap when fbq is not available', function () {
            delete global.fbq;

            var trapSet = false;
            var originalDefineProperty = Object.defineProperty;

            var definePropertySpy = jest.spyOn(Object, 'defineProperty');

            function init() {
                if (typeof global.fbq === 'function') {
                    return;
                }

                var _fbq = global.fbq;
                Object.defineProperty(global, 'fbq', {
                    configurable: true,
                    enumerable: true,
                    get: function() { return _fbq; },
                    set: function(value) {
                        _fbq = value;
                    }
                });
                trapSet = true;
            }

            init();
            expect(trapSet).toBe(true);
            expect(definePropertySpy).toHaveBeenCalledWith(
                global,
                'fbq',
                expect.objectContaining({
                    configurable: true,
                    enumerable: true,
                })
            );

            // Clean up
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                writable: true,
                value: undefined
            });
            delete global.fbq;
            definePropertySpy.mockRestore();
        });

        it('should fire events when fbq is assigned after trap is set', function () {
            delete global.fbq;

            var eventsFired = false;

            function fireQueuedEvents() {
                eventsFired = true;
            }

            // Set up the trap (simulating init logic)
            var _fbq = global.fbq;
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                get: function() { return _fbq; },
                set: function(value) {
                    _fbq = value;
                    if (typeof value === 'function') {
                        Object.defineProperty(global, 'fbq', {
                            configurable: true,
                            enumerable: true,
                            writable: true,
                            value: value
                        });
                        fireQueuedEvents();
                    }
                }
            });

            expect(eventsFired).toBe(false);

            // Simulate consent manager / FB SDK assigning fbq
            global.fbq = jest.fn();

            expect(eventsFired).toBe(true);
        });

        it('should defer fireQueuedEvents via setTimeout so fbq init runs first', function () {
            jest.useFakeTimers();
            delete global.fbq;

            var callOrder = [];

            function fireQueuedEvents() {
                callOrder.push('fireQueuedEvents');
            }

            // Set up the trap with setTimeout (matching actual implementation)
            var _fbq = global.fbq;
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                get: function() { return _fbq; },
                set: function(value) {
                    _fbq = value;
                    if (typeof value === 'function') {
                        Object.defineProperty(global, 'fbq', {
                            configurable: true,
                            enumerable: true,
                            writable: true,
                            value: value
                        });
                        setTimeout(fireQueuedEvents, 0);
                    }
                }
            });

            // Simulate Script 1: SDK snippet assigns fbq (triggers our setter)
            global.fbq = jest.fn();

            // Simulate Script 2: fbq('init', ...) runs synchronously after Script 1
            global.fbq('init', '123456789', {});
            callOrder.push('fbq_init');

            // At this point, init has run but fireQueuedEvents has NOT (still in setTimeout queue)
            expect(callOrder).toEqual(['fbq_init']);

            // Now advance timers — setTimeout(fireQueuedEvents, 0) runs
            jest.advanceTimersByTime(0);

            // init ran BEFORE fireQueuedEvents
            expect(callOrder).toEqual(['fbq_init', 'fireQueuedEvents']);

            jest.useRealTimers();
        });

        it('should restore fbq to a normal property after trap fires', function () {
            delete global.fbq;

            var _fbq = global.fbq;
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                get: function() { return _fbq; },
                set: function(value) {
                    _fbq = value;
                    if (typeof value === 'function') {
                        Object.defineProperty(global, 'fbq', {
                            configurable: true,
                            enumerable: true,
                            writable: true,
                            value: value
                        });
                    }
                }
            });

            var mockFn = jest.fn();
            global.fbq = mockFn;

            // After trap fires, fbq should be a normal writable property
            var anotherFn = jest.fn();
            global.fbq = anotherFn;
            expect(global.fbq).toBe(anotherFn);
        });

        it('should not fire events if a non-function value is assigned to fbq', function () {
            delete global.fbq;

            var eventsFired = false;

            function fireQueuedEvents() {
                eventsFired = true;
            }

            var _fbq = global.fbq;
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                get: function() { return _fbq; },
                set: function(value) {
                    _fbq = value;
                    if (typeof value === 'function') {
                        Object.defineProperty(global, 'fbq', {
                            configurable: true,
                            enumerable: true,
                            writable: true,
                            value: value
                        });
                        fireQueuedEvents();
                    }
                }
            });

            // Assign a non-function value
            global.fbq = 'not a function';
            expect(eventsFired).toBe(false);

            // Now assign a function
            global.fbq = jest.fn();
            expect(eventsFired).toBe(true);
        });

        it('should handle consent manager delayed consent (simulated)', function () {
            delete global.fbq;

            jest.useFakeTimers();
            var eventsFired = false;

            function fireQueuedEvents() {
                eventsFired = true;
            }

            var _fbq = global.fbq;
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                get: function() { return _fbq; },
                set: function(value) {
                    _fbq = value;
                    if (typeof value === 'function') {
                        Object.defineProperty(global, 'fbq', {
                            configurable: true,
                            enumerable: true,
                            writable: true,
                            value: value
                        });
                        fireQueuedEvents();
                    }
                }
            });

            // Simulate 30 seconds passing (user hasn't consented yet)
            jest.advanceTimersByTime(30000);
            expect(eventsFired).toBe(false);

            // User clicks "Accept Cookies" — consent manager assigns fbq
            global.fbq = jest.fn();
            expect(eventsFired).toBe(true);

            jest.useRealTimers();
        });
    });

    describe('fireEvent function', function () {
        it('should call fbq with correct parameters', function () {
            const event = {
                name: 'ViewContent',
                method: 'track',
                params: {
                    content_ids: ['SKU123'],
                    content_type: 'product',
                    value: 29.99,
                    currency: 'USD',
                },
            };

            // Simulate fireEvent logic
            global.fbq(event.method, event.name, event.params);

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'ViewContent',
                expect.objectContaining({
                    content_ids: ['SKU123'],
                    value: 29.99,
                })
            );
        });

        it('should call fbq with eventID as 4th argument when eventId is provided', function () {
            const event = {
                name: 'AddToCart',
                method: 'track',
                params: { content_ids: ['456'] },
                eventId: 'dedup-id-789',
            };

            // Simulate fireEvent logic with eventID as 4th argument
            if (event.eventId) {
                global.fbq(event.method, event.name, event.params, {eventID: event.eventId});
            } else {
                global.fbq(event.method, event.name, event.params);
            }

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                { content_ids: ['456'] },
                { eventID: 'dedup-id-789' }
            );
        });

        it('should not pass 4th argument when eventId is not provided', function () {
            const event = {
                name: 'ViewContent',
                method: 'track',
                params: { content_ids: ['123'] },
            };

            if (event.eventId) {
                global.fbq(event.method, event.name, event.params, {eventID: event.eventId});
            } else {
                global.fbq(event.method, event.name, event.params);
            }

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'ViewContent',
                { content_ids: ['123'] }
            );
            // Should only have 3 arguments (no 4th argument)
            expect(mockFbq.mock.calls[0].length).toBe(3);
        });

        it('should handle fbq errors gracefully', function () {
            // Make fbq throw an error
            global.fbq = jest.fn(() => {
                throw new Error('fbq error');
            });

            // Simulate fireEvent with try-catch
            let errorCaught = false;
            try {
                global.fbq('track', 'ViewContent', {});
            } catch (e) {
                errorCaught = true;
                // Log warning instead of crashing
                console.warn('[FB Pixel] Event error:', e);
            }

            expect(errorCaught).toBe(true);
            expect(consoleWarnSpy).toHaveBeenCalled();
        });
    });

    describe('fireQueuedEvents function', function () {
        it('should fire all events in eventQueue array', function () {
            const eventQueue = [
                { name: 'ViewContent', params: { id: '1' }, method: 'track' },
                { name: 'AddToCart', params: { id: '2' }, method: 'track' },
                { name: 'Purchase', params: { id: '3' }, method: 'track' },
            ];

            // Simulate fireQueuedEvents
            eventQueue.forEach((event) => {
                global.fbq(event.method, event.name, event.params);
            });

            expect(mockFbq).toHaveBeenCalledTimes(3);
            expect(mockFbq).toHaveBeenNthCalledWith(1, 'track', 'ViewContent', { id: '1' });
            expect(mockFbq).toHaveBeenNthCalledWith(2, 'track', 'AddToCart', { id: '2' });
            expect(mockFbq).toHaveBeenNthCalledWith(3, 'track', 'Purchase', { id: '3' });
        });

        it('should handle empty eventQueue array', function () {
            const eventQueue = [];

            eventQueue.forEach((event) => {
                global.fbq(event.method, event.name, event.params);
            });

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should handle null eventQueue', function () {
            const eventQueue = null;

            // Simulate the check in fireQueuedEvents
            if (!eventQueue || !Array.isArray(eventQueue)) {
                // Early return
                return;
            }

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should continue firing events even if one fails', function () {
            const eventQueue = [
                { name: 'ViewContent', params: { id: '1' }, method: 'track' },
                { name: 'BrokenEvent', params: null, method: 'track' }, // This might cause issues
                { name: 'AddToCart', params: { id: '3' }, method: 'track' },
            ];

            let successCount = 0;

            // Simulate fireQueuedEvents with error handling per event
            eventQueue.forEach((event) => {
                try {
                    global.fbq(event.method, event.name, event.params);
                    successCount++;
                } catch (e) {
                    console.warn('[FB Pixel] Event error:', e);
                }
            });

            // All events should be attempted
            expect(mockFbq).toHaveBeenCalledTimes(3);
        });
    });

    describe('Data Validation', function () {
        it('should handle wc_facebook_pixel_data being undefined', function () {
            global.wc_facebook_pixel_data = undefined;

            // Simulate early exit check
            const shouldExit = typeof wc_facebook_pixel_data === 'undefined';
            expect(shouldExit).toBe(true);
        });

        it('should process valid wc_facebook_pixel_data', function () {
            global.wc_facebook_pixel_data = {
                pixelId: '123456789',
                eventQueue: [
                    { name: 'ViewContent', params: {}, method: 'track' },
                ],
                agentString: 'woocommerce-1.0.0',
            };

            expect(global.wc_facebook_pixel_data.pixelId).toBe('123456789');
            expect(global.wc_facebook_pixel_data.eventQueue.length).toBe(1);
        });

        it('should handle missing eventQueue in data', function () {
            global.wc_facebook_pixel_data = {
                pixelId: '123456789',
                agentString: 'woocommerce-1.0.0',
            };

            const events = global.wc_facebook_pixel_data.eventQueue;
            const shouldSkip = !events || !Array.isArray(events);

            expect(shouldSkip).toBe(true);
        });
    });

    describe('Event Parameter Handling', function () {
        it('should preserve all standard event parameters', function () {
            const params = {
                content_ids: ['SKU123', 'SKU456'],
                content_type: 'product',
                value: 149.99,
                currency: 'EUR',
                content_name: 'Test Product',
                num_items: 2,
            };

            global.fbq('track', 'Purchase', params);

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'Purchase',
                expect.objectContaining(params)
            );
        });

        it('should handle empty params object', function () {
            global.fbq('track', 'PageView', {});

            expect(mockFbq).toHaveBeenCalledWith('track', 'PageView', {});
        });

        it('should handle params with nested objects', function () {
            const params = {
                content_ids: ['123'],
                contents: [
                    { id: '123', quantity: 1, item_price: 29.99 },
                ],
            };

            global.fbq('track', 'AddToCart', params);

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                expect.objectContaining({
                    contents: expect.arrayContaining([
                        expect.objectContaining({ id: '123', quantity: 1 }),
                    ]),
                })
            );
        });
    });

    describe('Document Ready State Handling', function () {
        it('should fire events immediately when document is complete', function () {
            Object.defineProperty(document, 'readyState', {
                value: 'complete',
                writable: true,
            });

            const fireStaticEventsCalled = document.readyState === 'complete';
            expect(fireStaticEventsCalled).toBe(true);
        });

        it('should wait for load event when document is not complete', function () {
            Object.defineProperty(document, 'readyState', {
                value: 'loading',
                writable: true,
            });

            const shouldWait = document.readyState !== 'complete';
            expect(shouldWait).toBe(true);
        });

        it('should use window.addEventListener for load event', function () {
            const addEventListenerSpy = jest.spyOn(window, 'addEventListener');

            Object.defineProperty(document, 'readyState', {
                value: 'loading',
                writable: true,
            });

            // Simulate init logic
            if (document.readyState !== 'complete') {
                window.addEventListener('load', () => {});
            }

            expect(addEventListenerSpy).toHaveBeenCalledWith('load', expect.any(Function));
            addEventListenerSpy.mockRestore();
        });
    });

    describe('Console Warning', function () {
        it('should log warning when fbq throws an error during event fire', function () {
            global.fbq = jest.fn(() => {
                throw new Error('fbq error');
            });

            // Simulate logWarning from catch block in fireEvent
            try {
                global.fbq('track', 'ViewContent', {});
            } catch (e) {
                console.warn('[FB Pixel]', 'Event error: ViewContent', e);
            }

            expect(consoleWarnSpy).toHaveBeenCalledWith(
                '[FB Pixel]',
                'Event error: ViewContent',
                expect.any(Error)
            );
        });

        it('should handle console not being available', function () {
            const originalConsole = global.console;
            delete global.console;

            // Should not throw
            expect(() => {
                if (typeof console !== 'undefined' && console.warn) {
                    console.warn('test');
                }
            }).not.toThrow();

            global.console = originalConsole;
        });
    });

    describe('IIFE Isolation', function () {
        it('should not pollute global namespace', function () {
            // The script uses IIFE pattern: (function() { ... })();
            // Internal variables should not be accessible globally

            // Simulate IIFE behavior
            (function () {
                var internalVar = 'secret';
                var firedEvents = {};
            })();

            expect(global.internalVar).toBeUndefined();
            expect(global.firedEvents).toBeUndefined();
        });

        it('should use strict mode', function () {
            // The script starts with 'use strict'
            // This test verifies strict mode behavior

            expect(() => {
                'use strict';
                // In strict mode, using undeclared variable throws
                // undeclaredVar = 'test'; // Would throw ReferenceError
            }).not.toThrow();
        });
    });

    describe('Integration with PHP Data', function () {
        it('should correctly parse PHP-provided event structure', function () {
            // This simulates the structure passed by wp_localize_script
            global.wc_facebook_pixel_data = {
                pixelId: '123456789',
                eventQueue: [
                    {
                        name: 'ViewContent',
                        params: {
                            content_ids: ['WC-PROD-123'],
                            content_type: 'product',
                            value: 49.99,
                            currency: 'USD',
                        },
                        method: 'track',
                        eventId: 'php-generated-uuid-123',
                    },
                ],
                agentString: 'woocommerce-facebook-for-woocommerce-3.0.0',
            };

            const data = global.wc_facebook_pixel_data;
            const event = data.eventQueue[0];

            expect(event.name).toBe('ViewContent');
            expect(event.eventId).toBe('php-generated-uuid-123');
            expect(event.params.content_ids).toContain('WC-PROD-123');
        });

        it('should handle AddToCart deferred event structure', function () {
            global.wc_facebook_pixel_data = {
                pixelId: '123456789',
                eventQueue: [
                    {
                        name: 'AddToCart',
                        params: {
                            content_ids: ['WC-PROD-456'],
                            content_type: 'product',
                            value: 29.99,
                            currency: 'USD',
                        },
                        method: 'track',
                        eventId: 'deferred-atc-event-789',
                    },
                ],
                agentString: 'woocommerce-facebook-for-woocommerce-3.0.0',
            };

            const event = global.wc_facebook_pixel_data.eventQueue[0];

            // Fire the event with eventID as 4th argument (correct approach)
            if (event.eventId) {
                global.fbq(event.method, event.name, event.params, {eventID: event.eventId});
            } else {
                global.fbq(event.method, event.name, event.params);
            }

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                expect.objectContaining({
                    content_ids: ['WC-PROD-456'],
                }),
                { eventID: 'deferred-atc-event-789' }
            );
        });
    });
});

/**
 * Integration Tests - Loading the actual pixel-events.js IIFE
 *
 * These tests load the real pixel-events.js file to ensure the actual
 * implementation matches our unit test expectations.
 */
describe('Integration: pixel-events.js IIFE', function () {
    let mockFbq;
    let consoleWarnSpy;
    let addEventListenerSpy;

    beforeEach(function () {
        jest.resetModules();
        jest.useFakeTimers();

        // Clean up globals before each test
        delete global.fbq;
        delete global.wc_facebook_pixel_data;

        // Reset window.fbq to a clean state (remove any property descriptors)
        try {
            Object.defineProperty(global, 'fbq', {
                configurable: true,
                enumerable: true,
                writable: true,
                value: undefined
            });
            delete global.fbq;
        } catch (e) {
            // Ignore if property doesn't exist
        }

        // Spy on console.warn
        consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

        // Capture the load event handler so we can trigger it manually
        // (document.readyState is not configurable in jsdom)
        addEventListenerSpy = jest.spyOn(window, 'addEventListener').mockImplementation((event, handler) => {
            if (event === 'load') {
                // Immediately call the handler to simulate document ready
                handler();
            }
        });
    });

    afterEach(function () {
        // Stop a wrapper's load listener or poll from touching the next test's fbq.
        if (window.FacebookSignals && window.FacebookSignals._fbqWatch) {
            window.FacebookSignals._fbqWatch.cancel();
        }
        jest.clearAllTimers();
        jest.useRealTimers();
        jest.resetModules();

        // Clean up globals
        delete global.fbq;
        delete global.wc_facebook_pixel_data;
        delete window.wcFacebookPixelFiredEvents;
        delete window.FacebookSignals;
        delete window.wcFacebookPixelQueuedEvents;

        consoleWarnSpy.mockRestore();
        addEventListenerSpy.mockRestore();
    });

    it('should fire queued events when fbq is assigned after IIFE loads', function () {
        // Set up test data before loading the script
        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'PageView', params: {}, method: 'track' },
                { name: 'ViewContent', params: { content_ids: ['123'] }, method: 'track' }
            ]
        };

        // Load the actual IIFE - this sets up the Object.defineProperty trap
        require('../../assets/js/frontend/pixel-events.js');

        // At this point, fbq doesn't exist, so trap should be set
        // Simulate consent manager / FB SDK assigning fbq
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        // fireQueuedEvents is deferred via setTimeout(0)
        jest.runAllTimers();

        // Verify events were fired
        expect(mockFbq).toHaveBeenCalledTimes(2);
        expect(mockFbq).toHaveBeenCalledWith('track', 'PageView', {});
        expect(mockFbq).toHaveBeenCalledWith('track', 'ViewContent', { content_ids: ['123'] });
    });

    it('should hand queued events to FacebookSignals before fbq exists instead of waiting for it', function () {
        var trackEvent = jest.fn();
        window.FacebookSignals = { trackEvent: trackEvent };
        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'PageView', params: {}, method: 'track', eventId: 'event-1' }
            ]
        };

        require('../../assets/js/frontend/pixel-events.js');

        // The wrapper queues the event until the Pixel initialises, so no trap is needed.
        expect(trackEvent).toHaveBeenCalledWith('PageView', {}, null, 'track', 'event-1');
        expect(Object.getOwnPropertyDescriptor(global, 'fbq')).toBeUndefined();
    });

    it('should chain onto an fbq accessor another script installed', function () {
        var stored;
        var previousSet = jest.fn(function (value) { stored = value; });
        Object.defineProperty(global, 'fbq', {
            configurable: true,
            enumerable: true,
            get: function () { return stored; },
            set: previousSet,
        });
        global.wc_facebook_pixel_data = {
            eventQueue: [ { name: 'PageView', params: {}, method: 'track' } ]
        };

        require('../../assets/js/frontend/pixel-events.js');

        mockFbq = jest.fn();
        global.fbq = mockFbq;
        expect(previousSet).toHaveBeenCalledWith(mockFbq);
        jest.runAllTimers();

        expect(mockFbq).toHaveBeenCalledWith('track', 'PageView', {});
    });

    it('should poll for fbq when window.fbq cannot be redefined', function () {
        global.wc_facebook_pixel_data = {
            eventQueue: [ { name: 'PageView', params: {}, method: 'track' } ]
        };
        var defineProperty = Object.defineProperty;
        var spy = jest.spyOn(Object, 'defineProperty').mockImplementation(function (target, name, descriptor) {
            if (target === global && name === 'fbq') {
                throw new TypeError('Cannot redefine property: fbq');
            }
            return defineProperty.call(Object, target, name, descriptor);
        });

        expect(function () {
            require('../../assets/js/frontend/pixel-events.js');
        }).not.toThrow();
        spy.mockRestore();

        mockFbq = jest.fn();
        global.fbq = mockFbq;
        expect(mockFbq).not.toHaveBeenCalled();

        jest.advanceTimersByTime(500);

        expect(mockFbq).toHaveBeenCalledWith('track', 'PageView', {});
    });

    it('should send events the inline code queued before fbq existed', function () {
        window.wcFacebookPixelQueuedEvents = [
            { name: 'ViewContent', params: { content_ids: '["1"]' }, method: 'track', eventId: 'event-1', agent: 'woocommerce_2-1.0.0-2.0.0', pixelId: '123' }
        ];
        global.wc_facebook_pixel_data = { eventQueue: [] };

        require('../../assets/js/frontend/pixel-events.js');

        mockFbq = jest.fn();
        global.fbq = mockFbq;
        jest.runAllTimers();

        expect(mockFbq.mock.calls).toEqual([
            ['set', 'agent', 'woocommerce_2-1.0.0-2.0.0', '123'],
            ['track', 'ViewContent', { content_ids: '["1"]' }, { eventID: 'event-1' }],
        ]);
        expect(window.wcFacebookPixelQueuedEvents).toEqual([]);
    });

    /**
     * Mimics the Meta Pixel snippet's stub: calls are recorded and, as the real
     * stub does, pushed onto fbq.queue until the library loads, so an init call
     * shows up there.
     *
     * @param {Array} calls Receives every fbq() call as an argument array.
     * @return {Function} The stub.
     */
    function installSnippetStub(calls) {
        var stub = function () {
            var args = Array.prototype.slice.call(arguments);
            calls.push(args);
            stub.queue.push(args);
        };
        stub.queue = [];
        stub.loaded = true;
        global.fbq = stub;
        return stub;
    }

    it('should keep events queued while fbq has no initialised pixel and let a later wrapper send them', function () {
        var calls = [];
        installSnippetStub(calls);
        global.wc_facebook_pixel_data = {
            eventQueue: [ { name: 'ViewContent', params: { content_ids: ['1'] }, method: 'track', eventId: 'event-1' } ]
        };

        require('../../assets/js/frontend/pixel-events.js');

        // Only the wrapper initialises the Pixel; firing now would reach no pixel.
        expect(calls).toEqual([]);
        expect(window.wcFacebookPixelQueuedEvents).toHaveLength(1);

        // The consent manager activates the wrapper block.
        var signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});

        expect(window.wcFacebookPixelQueuedEvents).toEqual([]);
        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
            ['track', 'ViewContent', { content_ids: ['1'] }, { eventID: 'event-1' }],
        ]);

        // The poll notices the wrapper and stops without sending anything itself.
        jest.runAllTimers();
        expect(calls).toHaveLength(3);
    });

    it('should send queued events itself once something else initialises the pixel', function () {
        var calls = [];
        var stub = installSnippetStub(calls);
        global.wc_facebook_pixel_data = {
            eventQueue: [ { name: 'PageView', params: {}, method: 'track' } ]
        };

        require('../../assets/js/frontend/pixel-events.js');
        expect(calls).toEqual([]);

        stub('init', '999');
        jest.advanceTimersByTime(500);

        expect(calls).toEqual([
            ['init', '999'],
            ['track', 'PageView', {}],
        ]);
        expect(window.wcFacebookPixelQueuedEvents).toEqual([]);
    });

    it('should stop waiting for a sender after a minute and keep the events queued', function () {
        var calls = [];
        installSnippetStub(calls);
        global.wc_facebook_pixel_data = {
            eventQueue: [ { name: 'PageView', params: {}, method: 'track' } ]
        };

        require('../../assets/js/frontend/pixel-events.js');
        jest.advanceTimersByTime(60 * 1000);

        expect(calls).toEqual([]);
        expect(window.wcFacebookPixelQueuedEvents).toHaveLength(1);
        expect(consoleWarnSpy).toHaveBeenCalledWith('[FB Pixel]', 'No Meta Pixel initialised, events kept queued:', 1);
        expect(jest.getTimerCount()).toBe(0);
    });

    it('should let a wrapper that chains onto its fbq watcher initialise the Pixel and apply consent before the events fire', function () {
        global.wc_facebook_pixel_data = {
            eventQueue: [ { name: 'PageView', params: {}, method: 'track', eventId: 'event-1' } ]
        };

        // Both base-code blocks are blocked: the event is queued and window.fbq watched.
        require('../../assets/js/frontend/pixel-events.js');
        expect(window.wcFacebookPixelQueuedEvents).toHaveLength(1);
        expect(typeof Object.getOwnPropertyDescriptor(global, 'fbq').set).toBe('function');

        // The wrapper block is activated first, while fbq is still missing.
        var signals = loadSignals();
        signals.init({});
        signals.initPixel('123', {}, {});
        expect(signals._fbqWatcherInstalled).toBe(true);
        expect(window.wcFacebookPixelQueuedEvents).toEqual([]);
        expect(signals._pendingPixelEvents).toHaveLength(1);

        // Then the Pixel snippet assigns fbq, running both chained setters.
        var calls = [];
        installSnippetStub(calls);
        expect(calls).toEqual([]);
        jest.runAllTimers();

        expect(calls).toEqual([
            ['consent', 'grant'],
            ['init', '123', {}, {}],
            ['track', 'PageView', {}, { eventID: 'event-1' }],
        ]);
    });

    it('should fire events immediately when fbq already exists before IIFE loads', function () {
        // Set up fbq BEFORE loading the script
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        // Set up test data
        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'Purchase', params: { value: 99.99, currency: 'USD' }, method: 'track' }
            ]
        };

        // Load the actual IIFE - should fire immediately since fbq exists
        require('../../assets/js/frontend/pixel-events.js');

        // Events should fire immediately (no setTimeout needed when fbq already exists)
        expect(mockFbq).toHaveBeenCalledTimes(1);
        expect(mockFbq).toHaveBeenCalledWith('track', 'Purchase', { value: 99.99, currency: 'USD' });
    });

    it('should wait for FB SDK init before firing queued events', function () {
        var callOrder = [];

        // Set up test data
        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'AddToCart', params: {}, method: 'track' }
            ]
        };

        // Load the actual IIFE - sets up the trap
        require('../../assets/js/frontend/pixel-events.js');

        // Simulate Script 1: FB SDK assigns fbq (triggers our setter)
        mockFbq = jest.fn(function() {
            callOrder.push('fbq_call:' + arguments[1]);
        });
        global.fbq = mockFbq;

        // Simulate Script 2: FB SDK immediately calls fbq('init', ...)
        global.fbq('init', '123456789', {});
        callOrder.push('fbq_init_complete');

        // At this point, our fireQueuedEvents is still in setTimeout queue
        expect(callOrder).toEqual(['fbq_call:123456789', 'fbq_init_complete']);

        // Now advance timers - our deferred fireQueuedEvents runs
        jest.runAllTimers();

        // Verify order: FB init completed BEFORE our queued events fired
        expect(callOrder).toEqual([
            'fbq_call:123456789',
            'fbq_init_complete',
            'fbq_call:AddToCart'
        ]);
    });

    it('should restore fbq to a normal writable property after trap fires', function () {
        // Set up minimal data
        global.wc_facebook_pixel_data = {
            eventQueue: []
        };

        // Load the IIFE - sets up the trap
        require('../../assets/js/frontend/pixel-events.js');

        // Assign fbq - triggers trap and should restore to normal property
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        jest.runAllTimers();

        // fbq should now be a normal writable property
        var anotherFbq = jest.fn();
        global.fbq = anotherFbq;

        expect(global.fbq).toBe(anotherFbq);

        // And it should work normally
        global.fbq('track', 'TestEvent', {});
        expect(anotherFbq).toHaveBeenCalledWith('track', 'TestEvent', {});
    });

    it('should pass eventID as 4th argument for deduplication', function () {
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'AddToCart', params: { content_ids: ['123'] }, method: 'track', eventId: 'unique-event-id' }
            ]
        };

        require('../../assets/js/frontend/pixel-events.js');
        jest.runAllTimers();

        expect(mockFbq).toHaveBeenCalledWith(
            'track',
            'AddToCart',
            { content_ids: ['123'] },
            { eventID: 'unique-event-id' }
        );
    });

    it('should skip queued events already marked in shared browser dedup map', function () {
        mockFbq = jest.fn();
        global.fbq = mockFbq;
        window.wcFacebookPixelFiredEvents = {
            'shared-event-id': true,
        };

        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'AddToCart', params: { content_ids: ['123'] }, method: 'track', eventId: 'shared-event-id' },
                { name: 'ViewContent', params: { content_ids: ['456'] }, method: 'track', eventId: 'new-event-id' },
            ]
        };

        require('../../assets/js/frontend/pixel-events.js');
        jest.runAllTimers();

        expect(mockFbq).toHaveBeenCalledTimes(1);
        expect(mockFbq).toHaveBeenCalledWith(
            'track',
            'ViewContent',
            { content_ids: ['456'] },
            { eventID: 'new-event-id' }
        );
        expect(window.wcFacebookPixelFiredEvents['shared-event-id']).toBe(true);
        expect(window.wcFacebookPixelFiredEvents['new-event-id']).toBe(true);
    });

    it('should handle empty eventQueue without errors', function () {
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        global.wc_facebook_pixel_data = {
            eventQueue: []
        };

        expect(() => {
            require('../../assets/js/frontend/pixel-events.js');
        }).not.toThrow();

        expect(mockFbq).not.toHaveBeenCalled();
    });

    it('should early exit when wc_facebook_pixel_data is undefined', function () {
        // Don't set wc_facebook_pixel_data
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        expect(() => {
            require('../../assets/js/frontend/pixel-events.js');
        }).not.toThrow();

        expect(mockFbq).not.toHaveBeenCalled();
    });

    it('should not fire events when non-function is assigned to fbq', function () {
        global.wc_facebook_pixel_data = {
            eventQueue: [
                { name: 'ViewContent', params: {}, method: 'track' }
            ]
        };

        // Load the IIFE - sets up trap
        require('../../assets/js/frontend/pixel-events.js');

        // Assign non-function values - should NOT trigger fireQueuedEvents
        global.fbq = null;
        global.fbq = 'string';
        global.fbq = 123;
        global.fbq = {};

        jest.runAllTimers();

        // Now assign a real function
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        jest.runAllTimers();

        // Events should only fire once the function was assigned
        expect(mockFbq).toHaveBeenCalledTimes(1);
        expect(mockFbq).toHaveBeenCalledWith('track', 'ViewContent', {});
    });
});

/**
 * Tests for WooCommerce Blocks Store API integration
 */
describe('Pixel Events - WooCommerce Blocks Store API', function () {
    let mockFbq;
    let originalFetch;
    let consoleWarnSpy;

    beforeEach(function () {
        // Reset modules
        jest.resetModules();

        // Create mock fbq function
        mockFbq = jest.fn();
        global.fbq = mockFbq;

        // Store original fetch
        originalFetch = global.fetch;

        // Spy on console.warn
        consoleWarnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});

        // Set up basic pixel data
        global.wc_facebook_pixel_data = {
            eventQueue: []
        };

        // Reset document ready state
        Object.defineProperty(document, 'readyState', {
            value: 'complete',
            writable: true,
        });
    });

    afterEach(function () {
        // Restore original fetch
        global.fetch = originalFetch;

        // Restore console.warn
        consoleWarnSpy.mockRestore();

        // Clean up globals
        delete global.wc_facebook_pixel_data;
        delete global.fbq;

        jest.resetAllMocks();
    });

    describe('setupFetchInterceptor', function () {
        it('should wrap window.fetch when available', function () {
            const mockOriginalFetch = jest.fn().mockResolvedValue({
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({ extensions: {} })
                })
            });
            global.fetch = mockOriginalFetch;

            // Load the module (sets up interceptor)
            require('../../assets/js/frontend/pixel-events.js');

            // fetch should now be wrapped
            expect(global.fetch).not.toBe(mockOriginalFetch);
        });

        it('should not throw if fetch is not available', function () {
            delete global.fetch;

            expect(() => {
                require('../../assets/js/frontend/pixel-events.js');
            }).not.toThrow();
        });

        it('should only intercept add-item requests', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { value: 29.99, event_id: 'test-123' }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch with add-item URL
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should fire pixel event
            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                { value: 29.99, event_id: 'test-123' },
                { eventID: 'test-123' }
            );
        });

        it('should not intercept general cart requests', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { value: 29.99 }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch with general cart URL (not add-item)
            await global.fetch('/wc/store/v1/cart', { method: 'GET' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should NOT fire pixel event
            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should not fire pixel if response is not ok', async function () {
            const mockResponse = {
                ok: false,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: {}
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch with add-item URL but response.ok = false
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should NOT fire pixel event
            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should return original response to caller', async function () {
            const mockResponse = {
                ok: true,
                status: 200,
                data: 'test-data',
                clone: () => ({
                    json: () => Promise.resolve({ extensions: {} })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch
            const response = await global.fetch('/wc/store/v1/cart/add-item');

            // Should return the original response
            expect(response.status).toBe(200);
            expect(response.data).toBe('test-data');
        });
    });

    describe('processStoreApiEvent', function () {
        it('should fire pixel with correct params from Store API response', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: {
                                    content_ids: '["product_123"]',
                                    content_name: 'Test Product',
                                    content_type: 'product',
                                    value: 49.99,
                                    currency: 'USD',
                                    event_id: 'fb.1.abc123'
                                }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch with add-item URL
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Verify fbq was called with correct params
            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                {
                    content_ids: '["product_123"]',
                    content_name: 'Test Product',
                    content_type: 'product',
                    value: 49.99,
                    currency: 'USD',
                    event_id: 'fb.1.abc123'
                },
                { eventID: 'fb.1.abc123' }
            );
        });

        it('should not fire pixel if no facebook-for-woocommerce extension', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'other-plugin': { data: 'test' }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should NOT fire pixel event
            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should not fire pixel if extension data is empty', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {}
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should NOT fire pixel event (no event property)
            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should not fire pixel if event property is missing', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                params: { value: 29.99 }
                                // Missing 'event' property
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should NOT fire pixel event
            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should handle JSON parse errors gracefully', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.reject(new Error('Invalid JSON'))
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Should not throw
            await expect(
                global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' })
            ).resolves.toBeDefined();

            // Should NOT fire pixel event
            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should fire pixel without eventID if event_id is missing', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: {
                                    value: 29.99,
                                    currency: 'USD'
                                    // No event_id
                                }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });

            // Wait for async operations
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should fire pixel without eventID option
            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                { value: 29.99, currency: 'USD' }
            );
        });
    });

    describe('deduplication', function () {
        it('should not fire same event_id twice', async function () {
            const eventId = 'test-dedup-123';
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { value: 29.99, event_id: eventId }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch twice with same event_id
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should only fire once
            expect(mockFbq).toHaveBeenCalledTimes(1);
        });

        it('should fire different event_ids separately', async function () {
            let callCount = 0;
            const mockOriginalFetch = jest.fn().mockImplementation(() => {
                callCount++;
                return Promise.resolve({
                    ok: true,
                    clone: () => ({
                        json: () => Promise.resolve({
                            extensions: {
                                'facebook-for-woocommerce': {
                                    event: 'AddToCart',
                                    params: { value: 29.99, event_id: `event-${callCount}` }
                                }
                            }
                        })
                    })
                });
            });
            global.fetch = mockOriginalFetch;

            // Load the module
            require('../../assets/js/frontend/pixel-events.js');

            // Call fetch twice with different event_ids
            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            await global.fetch('/wc/store/v1/cart/add-item', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            // Should fire twice (different event_ids)
            expect(mockFbq).toHaveBeenCalledTimes(2);
        });
    });

    describe('Batch endpoint support', function () {
        it('should process AddToCart event from /wc/store/v1/batch response', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        responses: [
                            {
                                status: 200,
                                body: {
                                    extensions: {
                                        'facebook-for-woocommerce': {
                                            event: 'AddToCart',
                                            params: { value: 29.99, currency: 'USD', event_id: 'batch-1' }
                                        }
                                    }
                                }
                            }
                        ]
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).toHaveBeenCalledWith(
                'track',
                'AddToCart',
                { value: 29.99, currency: 'USD', event_id: 'batch-1' },
                { eventID: 'batch-1' }
            );
        });

        it('should not fire for /wc/store/v1/batch when no fb extension exists', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        responses: [
                            {
                                status: 200,
                                body: {
                                    extensions: {
                                        'other-plugin': { test: true }
                                    }
                                }
                            }
                        ]
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should not fire when batch response item status is not 2xx', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        responses: [
                            {
                                status: 422,
                                body: {
                                    extensions: {
                                        'facebook-for-woocommerce': {
                                            event: 'AddToCart',
                                            params: { event_id: 'failed-1' }
                                        }
                                    }
                                }
                            }
                        ]
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should not fire for empty responses array', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({ responses: [] })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should fire once per valid item in multi-item batch', async function () {
            const mkItem = (id) => ({
                status: 200,
                body: {
                    extensions: {
                        'facebook-for-woocommerce': {
                            event: 'AddToCart',
                            params: { event_id: id }
                        }
                    }
                }
            });
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        responses: [
                            mkItem('id-1'),
                            { status: 200, body: { extensions: {} } },
                            mkItem('id-2')
                        ]
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).toHaveBeenCalledTimes(2);
        });

        it('should skip batch items with null body', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        responses: [null, { status: 200, body: null }]
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should fire only once for direct extension, not again for batch responses', async function () {
            // A response with both top-level extensions AND responses[] should fire
            // only for the top-level extension (else-if prevents double-fire).
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { event_id: 'direct-1' }
                            }
                        },
                        responses: [
                            {
                                status: 200,
                                body: {
                                    extensions: {
                                        'facebook-for-woocommerce': {
                                            event: 'AddToCart',
                                            params: { event_id: 'batch-1' }
                                        }
                                    }
                                }
                            }
                        ]
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/batch', { method: 'POST' });
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).toHaveBeenCalledTimes(1);
        });
    });

    describe('URL matching', function () {
        it('should match /wc/store/v1/cart/add-item', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { event_id: 'test-1' }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/cart/add-item');
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).toHaveBeenCalled();
        });

        it('should match /wc/store/cart/add-item (older API version)', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { event_id: 'test-2' }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/cart/add-item');
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).toHaveBeenCalled();
        });

        it('should match full URL with domain', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: { event_id: 'test-3' }
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('https://example.com/wc/store/v1/cart/add-item');
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).toHaveBeenCalled();
        });

        it('should NOT match /wc/store/v1/cart (without add-item)', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: {}
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/cart');
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).not.toHaveBeenCalled();
        });

        it('should NOT match /wc/store/v1/cart/remove-item', async function () {
            const mockResponse = {
                ok: true,
                clone: () => ({
                    json: () => Promise.resolve({
                        extensions: {
                            'facebook-for-woocommerce': {
                                event: 'AddToCart',
                                params: {}
                            }
                        }
                    })
                })
            };

            const mockOriginalFetch = jest.fn().mockResolvedValue(mockResponse);
            global.fetch = mockOriginalFetch;

            require('../../assets/js/frontend/pixel-events.js');

            await global.fetch('/wc/store/v1/cart/remove-item');
            await new Promise(resolve => setTimeout(resolve, 0));

            expect(mockFbq).not.toHaveBeenCalled();
        });
    });
});
