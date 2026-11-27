/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Facebook Events Test - Validates Pixel + CAPI events
 */

const { test, expect } = require('@playwright/test');
const {
  TIMEOUTS,
  TestSetup,
  EventValidator,
  cleanupProduct,
  cleanupProducts,
  installJsErrorSimulatorMuPlugin,
  removeJsErrorSimulatorMuPlugin,
  installSingleSearchRedirectBlockerMuPlugin,
  removeSingleSearchRedirectBlockerMuPlugin,
  installBlockThemeHeaderSearchMuPlugin,
  removeBlockThemeHeaderSearchMuPlugin,
  getVisibleSearchInput,
  createVariableProductEventFixture,
  createGroupedProductEventFixture,
  selectVariationByLabel,
  setGroupedProductQuantity,
  loadCapturedEvents,
  getLatestEvent,
  asArray,
  assertEventContainsRetailerId,
  ignoreKnownPurchaseUserDataGap,
  getCartItemsViaStoreApi,
  clearCart,
  completeCheckoutFromCart,
  triggerAjaxAddToCartFromShop,
  isAjaxAddToCartAvailableOnShop,
  holdSignals,
  releaseSignals,
  getSignalState,
  getQueuedSignalEvents,
  submitSearch,
  getActiveThemeStatus,
  switchThemeBySlug,
  acquireThemeLock,
  releaseThemeLock
} = require('./helpers/js');

const STOREFRONT_THEME_SLUG = 'storefront';
// Classic project uses Twenty Thirteen because it ships a persistent, load-visible
// search form in its header (get_search_form() in header.php) — unlike Twenty
// Twenty-One, which has no header search. This lets the Search event run natively on
// the classic architecture. The block project keeps Twenty Twenty-Five (no FSE theme
// ships a header search) and gets a search block injected via the header-search
// mu-plugin (see PROJECTS_NEEDING_HEADER_SEARCH_INJECTION below).
const THEME_PROJECT_TO_SLUG = {
  'chromium-wp-customer-classic-theme': 'twentythirteen',
  'chromium-wp-customer-block-theme': 'twentytwentyfive',
};

// Block-theme projects that need a search block injected into the header so the
// Search event can be exercised (no stock FSE theme ships a header search).
const PROJECTS_NEEDING_HEADER_SEARCH_INJECTION = ['chromium-wp-customer-block-theme'];

// Projects where a search UI is guaranteed and its absence is a real failure, not a
// skippable fixture limitation: Storefront-based brave, the classic theme (native
// header search) and the block theme (injected header search). A skip here would be
// false coverage evidence for that configuration.
const PROJECTS_REQUIRING_SEARCH = [
  'brave-wp-customer',
  'chromium-wp-customer-classic-theme',
  'chromium-wp-customer-block-theme',
];

let themeLockToken = null;
let originalThemeSlug = null;
let activeThemeProjectSlug = null;

function resolveThemeForProject(projectName) {
  return THEME_PROJECT_TO_SLUG[projectName] || null;
}

function createPixelEventRequestRecorder(page, eventName) {
  const captured = [];
  const requestContext = page.context();

  const readBodyParam = (request, key) => {
    const body = request.postData() || '';
    if (!body) {
      return null;
    }

    if (!body.includes('name="')) {
      return new URLSearchParams(body).get(key);
    }

    // Firefox sends Pixel beacon payloads as multipart/form-data.
    const nameMarker = `name="${key}"`;
    const nameStart = body.indexOf(nameMarker);
    if (nameStart === -1) {
      return null;
    }

    const headerEndCRLF = body.indexOf('\r\n\r\n', nameStart);
    const headerEndLF = body.indexOf('\n\n', nameStart);
    let valueStart = -1;

    if (headerEndCRLF !== -1 && (headerEndLF === -1 || headerEndCRLF < headerEndLF)) {
      valueStart = headerEndCRLF + 4;
    } else if (headerEndLF !== -1) {
      valueStart = headerEndLF + 2;
    }

    if (valueStart === -1) {
      return null;
    }

    const boundaryCRLF = body.indexOf('\r\n--', valueStart);
    const boundaryLF = body.indexOf('\n--', valueStart);
    let valueEnd = -1;

    if (boundaryCRLF !== -1 && (boundaryLF === -1 || boundaryCRLF < boundaryLF)) {
      valueEnd = boundaryCRLF;
    } else if (boundaryLF !== -1) {
      valueEnd = boundaryLF;
    }

    return valueEnd === -1 ? null : body.slice(valueStart, valueEnd).trim() || null;
  };

  const onRequest = (request) => {
    const rawUrl = request.url();

    let parsed;
    try {
      parsed = new URL(rawUrl);
    } catch (_) {
      // Not a parseable URL; ignore.
      return;
    }

    // Only match the real Facebook Pixel endpoint. Validate the host and path
    // separately rather than with a substring check: rawUrl.includes(
    // 'facebook.com/tr') can be bypassed by hosts like "attackerfacebook.com/tr"
    // or URLs that merely contain the string (e.g. "evil.com/?x=facebook.com/tr").
    const host = parsed.hostname;
    const isFacebookHost = host === 'facebook.com' || host.endsWith('.facebook.com');
    const isPixelPath = parsed.pathname === '/tr' || parsed.pathname === '/tr/';
    if (!isFacebookHost || !isPixelPath) {
      return;
    }

    try {
      const queryEventName = parsed.searchParams.get('ev');
      const bodyEventName = readBodyParam(request, 'ev');
      const detectedEventName = queryEventName || bodyEventName;

      if (detectedEventName !== eventName) {
        return;
      }

      const queryEventId = parsed.searchParams.get('eid');
      const bodyEventId = readBodyParam(request, 'eid');

      captured.push({
        url: rawUrl,
        eventName: detectedEventName,
        eventId: queryEventId || bodyEventId || null,
      });
    } catch (_) {
      // Ignore non-URL-safe payloads.
    }
  };

  // Match PixelCapture's context-level request observation.
  requestContext.on('request', onRequest);

  return {
    getEvents: () => captured.slice(),
    stop: () => requestContext.off('request', onRequest),
  };
}

async function waitForMinimumPixelEvents(recorder, minCount, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const events = recorder.getEvents();
    if (events.length >= minCount) {
      return events;
    }

    await new Promise(resolve => setTimeout(resolve, 250));
  }

  return recorder.getEvents();
}

async function waitForMinimumCapiEvents(testId, eventName, minCount, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  let latestEvents = [];

  while (Date.now() < deadline) {
    const captured = await loadCapturedEvents(testId);
    latestEvents = captured.capi.filter(event => event.event_name === eventName);

    if (latestEvents.length >= minCount) {
      return latestEvents;
    }

    await new Promise(resolve => setTimeout(resolve, 300));
  }

  return latestEvents;
}

async function getSignalRuntimeSnapshot(page) {
  const state = await getSignalState(page).catch(() => ({ state: null, held: null }));

  const runtime = await page.evaluate(() => {
    const signal = window.FacebookSignals || null;
    const queue = signal && Array.isArray(signal._queue) ? signal._queue : [];
    const config = signal && signal._config ? signal._config : {};

    return {
      hasFbwcsignal: Boolean(window.fbwcsignal),
      hasFbwcsignalHold: Boolean(window.fbwcsignal && typeof window.fbwcsignal.hold === 'function'),
      hasFbwcsignalRelease: Boolean(window.fbwcsignal && typeof window.fbwcsignal.release === 'function'),
      hasFacebookSignals: Boolean(signal),
      facebookSignalsHeldFlag: signal ? Boolean(signal._held) : null,
      queueLength: queue.length,
      queueEventIds: queue.map(event => event?.event_id).filter(Boolean),
      queueEventNames: queue.map(event => event?.event_name).filter(Boolean),
      hasReleaseMethod: Boolean(signal && typeof signal.release === 'function'),
      configAction: config.action || null,
      configAjaxUrl: config.ajaxUrl || null,
      configNoncePresent: Boolean(config.nonce),
      signalCookie: document.cookie.split(';').map(v => v.trim()).find(v => v.startsWith('wc_facebook_signals_state=')) || null,
      locationHref: window.location.href,
    };
  }).catch(() => ({}));

  return { ...state, ...runtime };
}


test.beforeAll(async ({}, workerInfo) => {
  const targetThemeSlug = resolveThemeForProject(workerInfo.project.name);
  if (!targetThemeSlug) {
    return;
  }

  themeLockToken = await acquireThemeLock();

  const status = await getActiveThemeStatus();
  originalThemeSlug = status.activeStylesheet || STOREFRONT_THEME_SLUG;

  if (status.activeStylesheet !== targetThemeSlug) {
    const switchResult = await switchThemeBySlug(targetThemeSlug);
    if (!switchResult.success) {
      throw new Error(`Failed to activate theme '${targetThemeSlug}' for project '${workerInfo.project.name}': ${switchResult.error || 'unknown error'}`);
    }
  }

  if (PROJECTS_NEEDING_HEADER_SEARCH_INJECTION.includes(workerInfo.project.name)) {
    await installBlockThemeHeaderSearchMuPlugin();
  }

  activeThemeProjectSlug = targetThemeSlug;
});

test.afterAll(async ({}, workerInfo) => {
  if (!themeLockToken) {
    return;
  }

  try {
    if (PROJECTS_NEEDING_HEADER_SEARCH_INJECTION.includes(workerInfo.project.name)) {
      await removeBlockThemeHeaderSearchMuPlugin();
    }

    const restoreTarget = originalThemeSlug || STOREFRONT_THEME_SLUG;
    const restoreResult = await switchThemeBySlug(restoreTarget);
    if (!restoreResult.success) {
      throw new Error(`Failed to restore theme '${restoreTarget}' after '${activeThemeProjectSlug || 'unknown'}': ${restoreResult.error || 'unknown error'}`);
    }
  } finally {
    await releaseThemeLock(themeLockToken);
    themeLockToken = null;
    originalThemeSlug = null;
    activeThemeProjectSlug = null;
  }
});


test('PageView', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'PageView', testInfo);

    console.log(`   🌐 Navigating to homepage`);
    // Set up listener BEFORE triggering the action (prevents race condition)
    const eventPromise = pixelCapture.waitForEvent();
    await page.goto('/');
    await TestSetup.waitForPageReady(page);
    await eventPromise;

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const result = await validator.validate('PageView', page);

    TestSetup.logResult('PageView', result);
    expect(result.passed).toBe(true);
});

test('PageView with fbclid', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'PageView',  testInfo);

    const fbclid = process.env.TEST_FBCLID;
    const isBraveProject = testInfo?.project?.name?.includes('brave');

    // Keep original behavior for all browsers except Brave.
    // Brave can strip fbclid via WebRequest redirect, so seed _fbc only there.
    if (isBraveProject) {
      const seededFbc = `fb.1.${Date.now()}.${fbclid}`;
      await page.context().addCookies([
        {
          name: '_fbc',
          value: seededFbc,
          url: process.env.WORDPRESS_URL
        }
      ]);
    }

    console.log(`   🌐 Navigating to homepage with fbclid`);
    const eventPromise = pixelCapture.waitForEvent();
    await page.goto(`/?fbclid=${fbclid}`);
    await TestSetup.waitForPageReady(page);
    await eventPromise;

    const validator = new EventValidator(testId, true, false, { allowBraveFbcNormalization: isBraveProject }); // expects fbc
    await validator.checkDebugLog();
    const result = await validator.validate('PageView', page);

    TestSetup.logResult('PageView', result);
    expect(result.passed).toBe(true);
});

test('ViewContent', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'ViewContent',  testInfo);

    console.log(`   📦 Navigating to product page`);
    // Set up listener BEFORE triggering the action (prevents race condition)
    const eventPromise = pixelCapture.waitForEvent();
    await page.goto(process.env.TEST_PRODUCT_URL);
    await TestSetup.waitForPageReady(page);
    await eventPromise;

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const result = await validator.validate('ViewContent', page);

    TestSetup.logResult('ViewContent', result);
    expect(result.passed).toBe(true);
});

test('AddToCart', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'AddToCart',  testInfo);

    await page.goto(process.env.TEST_PRODUCT_URL);
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    console.log(`   🛒 Clicking Add to Cart`);
    // Set up listener BEFORE triggering the action (prevents race condition)
    const eventPromise = pixelCapture.waitForEvent();
    await page.click('.single_add_to_cart_button');

    // Wait for page to reload (form submission) and become ready
    await page.waitForLoadState('networkidle');
    await eventPromise;

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const result = await validator.validate('AddToCart', page);

    TestSetup.logResult('AddToCart', result);
    expect(result.passed).toBe(true);
});

test('AddToCart - AJAX (shop loop parity with PDP)', async ({ page }, testInfo) => {
    await clearCart(page);

    const baseline = await TestSetup.init(page, 'AddToCart', testInfo);
    await page.goto(process.env.TEST_PRODUCT_URL);
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    const pdpEventPromise = baseline.pixelCapture.waitForEvent();
    await page.click('.single_add_to_cart_button');
    await page.waitForLoadState('networkidle');
    await pdpEventPromise;

    const baselineValidator = new EventValidator(baseline.testId);
    await baselineValidator.checkDebugLog();
    const baselineResult = await baselineValidator.validate('AddToCart', page);
    expect(baselineResult.passed).toBe(true);

    const baselineCaptured = await loadCapturedEvents(baseline.testId);
    const baselinePixel = getLatestEvent(baselineCaptured.pixel, 'AddToCart');
    const baselineCapi = getLatestEvent(baselineCaptured.capi, 'AddToCart');

    expect(baselinePixel).toBeTruthy();
    expect(baselineCapi).toBeTruthy();

    const baselineProductId = String(
      baselinePixel?.custom_data?.contents?.[0]?.id ||
      baselinePixel?.custom_data?.content_ids?.[0] ||
      baselineCapi?.custom_data?.contents?.[0]?.id ||
      baselineCapi?.custom_data?.content_ids?.[0] ||
      ''
    );

    await clearCart(page);

    const ajaxRun = await TestSetup.init(page, 'AddToCart', testInfo);

    const ajaxEventPromise = ajaxRun.pixelCapture.waitForEvent();
    const ajaxTrace = await triggerAjaxAddToCartFromShop(page, {
      productUrl: process.env.TEST_PRODUCT_URL,
      expectedProductId: baselineProductId || undefined
    });
    await ajaxEventPromise;

    expect(ajaxTrace.usedAjax).toBe(true);
    expect(ajaxTrace.mainFrameNavigated).toBe(false);

    const ajaxValidator = new EventValidator(ajaxRun.testId);
    await ajaxValidator.checkDebugLog();
    const ajaxResult = await ajaxValidator.validate('AddToCart', page);
    expect(ajaxResult.passed).toBe(true);

    const ajaxCaptured = await loadCapturedEvents(ajaxRun.testId);
    const ajaxPixel = getLatestEvent(ajaxCaptured.pixel, 'AddToCart');
    const ajaxCapi = getLatestEvent(ajaxCaptured.capi, 'AddToCart');

    expect(ajaxPixel).toBeTruthy();
    expect(ajaxCapi).toBeTruthy();

    const pickComparable = (event) => ({
      content_ids: asArray(event?.custom_data?.content_ids),
      contents: asArray(event?.custom_data?.contents),
      content_name: event?.custom_data?.content_name,
      content_type: event?.custom_data?.content_type,
      value: Number(event?.custom_data?.value),
      currency: event?.custom_data?.currency
    });

    const normalizeContents = (items) => items
      .map(item => ({ id: String(item?.id), quantity: Number(item?.quantity) }))
      .sort((a, b) => `${a.id}:${a.quantity}`.localeCompare(`${b.id}:${b.quantity}`));

    const normalizeComparable = (data) => ({
      ...data,
      content_ids: data.content_ids.map(String).sort(),
      contents: normalizeContents(data.contents)
    });

    expect(normalizeComparable(pickComparable(ajaxPixel))).toEqual(normalizeComparable(pickComparable(baselinePixel)));
    expect(normalizeComparable(pickComparable(ajaxCapi))).toEqual(normalizeComparable(pickComparable(baselineCapi)));
});

test('ViewContent - Variable Product', async ({ page }, testInfo) => {
    let fixture;

    try {
      fixture = await createVariableProductEventFixture();
      const { testId, pixelCapture } = await TestSetup.init(page, 'ViewContent', testInfo);

      console.log(`   📦 Navigating to variable product page: ${fixture.parentUrl}`);
      const eventPromise = pixelCapture.waitForEvent();
      await page.goto(fixture.parentUrl);
      await TestSetup.waitForPageReady(page);
      await eventPromise;

      const validator = new EventValidator(testId);
      await validator.checkDebugLog();
      const rawResult = await validator.validate('ViewContent', page);
      const result = ignoreKnownPurchaseUserDataGap(rawResult);

      const captured = await loadCapturedEvents(testId);
      const pixelEvent = getLatestEvent(captured.pixel, 'ViewContent');
      const capiEvent = getLatestEvent(captured.capi, 'ViewContent');

      assertEventContainsRetailerId(pixelEvent, fixture.parentRetailerId);
      assertEventContainsRetailerId(capiEvent, fixture.parentRetailerId);
      expect(pixelEvent?.custom_data?.content_type).toBe('product_group');
      expect(capiEvent?.custom_data?.content_type).toBe('product_group');

      TestSetup.logResult('ViewContent (Variable Product)', result);
      expect(result.passed).toBe(true);
    } finally {
      if (fixture?.cleanupProductIds?.length) {
        await cleanupProducts(fixture.cleanupProductIds);
      }
    }
});

test('AddToCart - Variable Product (selected variation)', async ({ page }, testInfo) => {
    let fixture;

    try {
      fixture = await createVariableProductEventFixture();
      const targetVariation = fixture.variations.find(v => v.option === 'Large') || fixture.variations[0];
      const { testId, pixelCapture } = await TestSetup.init(page, 'AddToCart', testInfo);

      await page.goto(fixture.parentUrl);
      await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

      const selected = await selectVariationByLabel(page, {
        attributeSlug: fixture.attributeSlug,
        label: targetVariation.option
      });
      expect(selected.variationId).toBe(Number(targetVariation.id));

      console.log(`   🛒 Clicking Add to Cart for variation ${targetVariation.option}`);
      const eventPromise = pixelCapture.waitForEvent();
      await page.click('.single_add_to_cart_button');
      await page.waitForLoadState('networkidle');
      await eventPromise;

      const validator = new EventValidator(testId);
      await validator.checkDebugLog();
      const rawResult = await validator.validate('AddToCart', page);
      const result = ignoreKnownPurchaseUserDataGap(rawResult);

      const captured = await loadCapturedEvents(testId);
      const pixelEvent = getLatestEvent(captured.pixel, 'AddToCart');
      const capiEvent = getLatestEvent(captured.capi, 'AddToCart');

      assertEventContainsRetailerId(pixelEvent, targetVariation.retailer_id);
      assertEventContainsRetailerId(capiEvent, targetVariation.retailer_id);

      // Explicit SKU assertion when retailer ID mode is configured to use SKU.
      const isSkuMode = String(targetVariation.retailer_id) === String(targetVariation.sku);
      if (isSkuMode) {
        const pixelIds = [
          ...asArray(pixelEvent?.custom_data?.content_ids).map(String),
          ...asArray(pixelEvent?.custom_data?.contents).map(entry => String(entry?.id)).filter(Boolean)
        ];
        const capiIds = [
          ...asArray(capiEvent?.custom_data?.content_ids).map(String),
          ...asArray(capiEvent?.custom_data?.contents).map(entry => String(entry?.id)).filter(Boolean)
        ];

        expect(pixelIds).toContain(String(targetVariation.sku));
        expect(capiIds).toContain(String(targetVariation.sku));
      }

      TestSetup.logResult('AddToCart (Variable Product)', result);
      expect(result.passed).toBe(true);
    } finally {
      if (fixture?.cleanupProductIds?.length) {
        await cleanupProducts(fixture.cleanupProductIds);
      }
    }
});

test('Purchase - Variable Product', async ({ page }, testInfo) => {
    let fixture;

    try {
      fixture = await createVariableProductEventFixture();

      await clearCart(page);
      const targetVariation = fixture.variations.find(v => v.option === 'Large') || fixture.variations[0];
      const { testId, pixelCapture } = await TestSetup.init(page, 'Purchase', testInfo);

      await page.goto(fixture.parentUrl);
      await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

      await selectVariationByLabel(page, {
        attributeSlug: fixture.attributeSlug,
        label: targetVariation.option
      });

      await page.click('.single_add_to_cart_button');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(TIMEOUTS.SHORT);

      const cartItems = await getCartItemsViaStoreApi(page);
      expect(cartItems.length).toBe(1);
      expect(String(cartItems[0].id)).toBe(String(targetVariation.id));

      const eventPromise = pixelCapture.waitForEvent();
      await completeCheckoutFromCart(page);
      await eventPromise;

      const validator = new EventValidator(testId);
      await validator.checkDebugLog();
      const rawResult = await validator.validate('Purchase', page);
      const result = ignoreKnownPurchaseUserDataGap(rawResult);

      const captured = await loadCapturedEvents(testId);
      const pixelPurchase = getLatestEvent(captured.pixel, 'Purchase');
      const capiPurchase = getLatestEvent(captured.capi, 'Purchase');
      const pixelContents = asArray(pixelPurchase?.custom_data?.contents);
      const contents = asArray(capiPurchase?.custom_data?.contents);

      assertEventContainsRetailerId(pixelPurchase, targetVariation.retailer_id);
      assertEventContainsRetailerId(capiPurchase, targetVariation.retailer_id);
      expect(pixelContents.some(item => String(item.id) === String(targetVariation.retailer_id) && Number(item.quantity) >= 1)).toBe(true);
      expect(contents.some(item => String(item.id) === String(targetVariation.retailer_id) && Number(item.quantity) >= 1)).toBe(true);

      TestSetup.logResult('Purchase (Variable Product)', result);
      expect(result.passed).toBe(true);
    } finally {
      if (fixture?.cleanupProductIds?.length) {
        await cleanupProducts(fixture.cleanupProductIds);
      }
    }
});

test('ViewContent - Grouped Product', async ({ page }, testInfo) => {
    let fixture;

    try {
      fixture = await createGroupedProductEventFixture();
      const { testId, pixelCapture } = await TestSetup.init(page, 'ViewContent', testInfo);

      console.log(`   📦 Navigating to grouped product page: ${fixture.groupedUrl}`);
      const eventPromise = pixelCapture.waitForEvent();
      await page.goto(fixture.groupedUrl);
      await TestSetup.waitForPageReady(page);
      await eventPromise;

      const validator = new EventValidator(testId);
      await validator.checkDebugLog();
      const rawResult = await validator.validate('ViewContent', page);
      const result = ignoreKnownPurchaseUserDataGap(rawResult);

      const captured = await loadCapturedEvents(testId);
      const pixelEvent = getLatestEvent(captured.pixel, 'ViewContent');
      const capiEvent = getLatestEvent(captured.capi, 'ViewContent');

      assertEventContainsRetailerId(pixelEvent, fixture.groupedRetailerId);
      assertEventContainsRetailerId(capiEvent, fixture.groupedRetailerId);
      expect(pixelEvent?.custom_data?.content_type).toBe('product_group');
      expect(capiEvent?.custom_data?.content_type).toBe('product_group');

      TestSetup.logResult('ViewContent (Grouped Product)', result);
      expect(result.passed).toBe(true);
    } finally {
      if (fixture?.cleanupProductIds?.length) {
        await cleanupProducts(fixture.cleanupProductIds);
      }
    }
});

test('Purchase - Grouped Product', async ({ page }, testInfo) => {
    let fixture;

    try {
      fixture = await createGroupedProductEventFixture();

      await clearCart(page);
      const child = fixture.children[0];
      const { testId, pixelCapture } = await TestSetup.init(page, 'Purchase', testInfo);

      await page.goto(fixture.groupedUrl);
      await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

      await setGroupedProductQuantity(page, child.id, 1);
      await page.click('.single_add_to_cart_button');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(TIMEOUTS.SHORT);

      const cartItems = await getCartItemsViaStoreApi(page);
      expect(cartItems.length).toBe(1);
      expect(String(cartItems[0].id)).toBe(String(child.id));

      const eventPromise = pixelCapture.waitForEvent();
      await completeCheckoutFromCart(page);
      await eventPromise;

      const validator = new EventValidator(testId);
      await validator.checkDebugLog();
      const rawResult = await validator.validate('Purchase', page);
      const result = ignoreKnownPurchaseUserDataGap(rawResult);

      const captured = await loadCapturedEvents(testId);
      const pixelPurchase = getLatestEvent(captured.pixel, 'Purchase');
      const capiPurchase = getLatestEvent(captured.capi, 'Purchase');
      const pixelContents = asArray(pixelPurchase?.custom_data?.contents);
      const contents = asArray(capiPurchase?.custom_data?.contents);

      assertEventContainsRetailerId(pixelPurchase, child.retailer_id);
      assertEventContainsRetailerId(capiPurchase, child.retailer_id);
      expect(pixelContents.some(item => String(item.id) === String(child.retailer_id) && Number(item.quantity) >= 1)).toBe(true);
      expect(contents.some(item => String(item.id) === String(child.retailer_id) && Number(item.quantity) >= 1)).toBe(true);

      TestSetup.logResult('Purchase (Grouped Product)', result);
      expect(result.passed).toBe(true);
    } finally {
      if (fixture?.cleanupProductIds?.length) {
        await cleanupProducts(fixture.cleanupProductIds);
      }
    }
});

test('ViewCategory', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'ViewCategory',  testInfo);

    console.log(`   📂 Navigating to category page`);
    // Set up listener BEFORE triggering the action (prevents race condition)
    const eventPromise = pixelCapture.waitForEvent();
    await page.goto(process.env.TEST_CATEGORY_URL);
    await TestSetup.waitForPageReady(page);
    await eventPromise;

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const result = await validator.validate('ViewCategory', page);

    TestSetup.logResult('ViewCategory', result);
    expect(result.passed).toBe(true);
});

test('InitiateCheckout', async ({ page }, testInfo) => {
    // Keep cart deterministic so single-item category expectations are stable.
    await clearCart(page);

    // init() sets the test cookie used by PHP-side CAPI event logging.
    const { testId, pixelCapture } = await TestSetup.init(page, 'InitiateCheckout',  testInfo);

    await page.goto(process.env.TEST_PRODUCT_URL);
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    console.log(`   🛒 Adding product to cart`);
    await page.click('.single_add_to_cart_button');
    await page.waitForTimeout(TIMEOUTS.SHORT);

    const cartItems = await getCartItemsViaStoreApi(page);
    expect(cartItems.length).toBe(1);

    console.log(`   💳 Navigating to checkout`);
    // Set up listener BEFORE triggering the action (prevents race condition)
    const eventPromise = pixelCapture.waitForEvent();
    await page.goto('/checkout');
    await TestSetup.waitForPageReady(page);
    await eventPromise;
    await page.waitForTimeout(TIMEOUTS.SHORT); // allow CAPI logger write to settle

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const result = await validator.validate('InitiateCheckout', page);

    TestSetup.logResult('InitiateCheckout', result);
    expect(result.passed).toBe(true);
});

test('Purchase', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'Purchase',  testInfo);

    await page.goto(process.env.TEST_PRODUCT_URL);
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    console.log(`   🛒 Adding product to cart`);
    await page.click('.single_add_to_cart_button');
    await page.waitForTimeout(TIMEOUTS.SHORT);

    console.log(`   💳 Completing checkout as guest`);
    const eventPromise = pixelCapture.waitForEvent();
    await completeCheckoutFromCart(page);
    await eventPromise;

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const rawResult = await validator.validate('Purchase', page);
    const result = ignoreKnownPurchaseUserDataGap(rawResult);

    TestSetup.logResult('Purchase', result);
    expect(result.passed).toBe(true);
});

test('Purchase - Multiple Place Order Clicks', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'Purchase',  testInfo);

    await page.goto(process.env.TEST_PRODUCT_URL);
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    console.log(`   🛒 Adding product to cart`);
    await page.click('.single_add_to_cart_button');
    await page.waitForTimeout(TIMEOUTS.SHORT);

    console.log(`   💳 Navigating to checkout`);
    await page.goto('/checkout');
    await TestSetup.waitForPageReady(page);

    // Woo Blocks can show saved address summary with hidden fields for logged-in customers.
    // Reveal editable form before attempting to fill values.
    for (let i = 0; i < 3; i++) {
      const editButton = page.getByRole('button', { name: /edit/i }).first();
      const isVisible = await editButton.isVisible({ timeout: TIMEOUTS.SHORT }).catch(() => false);
      if (!isVisible) {
        break;
      }
      await editButton.click().catch(() => {});
      await page.waitForTimeout(TIMEOUTS.INSTANT);
    }

    // Fill checkout fields
    const defaults = {
      email: process.env.TEST_USER_EMAIL || `e2e+${Date.now()}@example.test`,
      firstName: process.env.TEST_USER_FIRST_NAME || 'E2E',
      lastName: process.env.TEST_USER_LAST_NAME || 'Customer',
      address1: process.env.TEST_USER_ADDRESS_1 || '1 Test Street',
      city: process.env.TEST_USER_CITY || 'London',
      country: process.env.TEST_USER_COUNTRY || 'GB',
      state: process.env.TEST_USER_STATE || 'LND',
      postcode: process.env.TEST_USER_POSTCODE || 'EC1A1BB',
      phone: process.env.TEST_USER_PHONE || '0123456789'
    };

    const fillIfVisible = async (selector, value) => {
      const field = page.locator(selector).first();
      if (await field.isVisible({ timeout: TIMEOUTS.SHORT }).catch(() => false)) {
        await field.fill(value);
      }
    };

    const selectIfVisible = async (selector, value) => {
      const field = page.locator(selector).first();
      if (await field.isVisible({ timeout: TIMEOUTS.SHORT }).catch(() => false)) {
        await field.selectOption(value).catch(async () => {
          await field.fill(value);
        });
      }
    };

    await fillIfVisible('#email', defaults.email);
    for (const prefix of ['shipping', 'billing']) {
      await fillIfVisible(`#${prefix}-first_name`, defaults.firstName);
      await fillIfVisible(`#${prefix}-last_name`, defaults.lastName);
      await fillIfVisible(`#${prefix}-address_1`, defaults.address1);
      await fillIfVisible(`#${prefix}-city`, defaults.city);
      await selectIfVisible(`#${prefix}-country`, defaults.country);
      await selectIfVisible(`#${prefix}-state`, defaults.state);
      await fillIfVisible(`#${prefix}-postcode`, defaults.postcode);
      await fillIfVisible(`#${prefix}-phone`, defaults.phone);
    }

    // Scroll down to see checkout form in video
    await page.evaluate(() => window.scrollBy(0, 400));
    await page.waitForTimeout(TIMEOUTS.SHORT);

    console.log(`   💰 Selecting Cash on Delivery`);
    await page.waitForSelector('.wc-block-components-radio-control__option[for="radio-control-wc-payment-method-options-cod"]', { state: 'visible', timeout: TIMEOUTS.LONG });
    await page.click('label[for="radio-control-wc-payment-method-options-cod"]');
    await page.waitForTimeout(TIMEOUTS.INSTANT);

    const termsCheckbox = page.locator('#wc-terms-and-conditions-checkbox-text').first();
    if (await termsCheckbox.isVisible({ timeout: TIMEOUTS.SHORT }).catch(() => false)) {
      await termsCheckbox.click();
    }

    console.log(`   ✅ Clicking Place Order button multiple times (testing deduplication)`);
    await page.locator('.wc-block-components-checkout-place-order-button').scrollIntoViewIfNeeded();

    // Click Place Order button 3 times rapidly
    const placeOrderButton = page.locator('.wc-block-components-checkout-place-order-button');
    const eventPromise = pixelCapture.waitForEvent();
    console.log(`   🔄 Click #1`);
    await placeOrderButton.click();
    await page.waitForTimeout(100);
    console.log(`   🔄 Click #2`);
    await placeOrderButton.click({force: true}).catch(() => {}); // Might fail if already processing
    await page.waitForTimeout(100);
    console.log(`   🔄 Click #3`);
    await placeOrderButton.click({force: true}).catch(() => {}); // Might fail if already processing

    console.log(`   ⏳ Waiting for order to process...`);
    await page.waitForURL('**/checkout/order-received/**', { timeout: TIMEOUTS.EXTRA_LONG });
    await eventPromise;
    await page.waitForTimeout(TIMEOUTS.NORMAL);

    const validator = new EventValidator(testId);
    await validator.checkDebugLog();
    const rawResult = await validator.validate('Purchase', page);
    const result = ignoreKnownPurchaseUserDataGap(rawResult);

    // Multiple clicks must still produce exactly one Pixel and one CAPI Purchase event.
    TestSetup.logResult('Purchase (Deduplication)', result);
    expect(result.passed).toBe(true);
});

test('Search', async ({ page }, testInfo) => {
    await installSingleSearchRedirectBlockerMuPlugin();

    try {
      const { testId, pixelCapture } = await TestSetup.init(page, 'Search',  testInfo);

      console.log(`   🏠 Navigating to homepage`);
      await page.goto('/');
      await TestSetup.waitForPageReady(page);

      console.log(`   🔍 Typing search query in search box`);
      const searchInput = await getVisibleSearchInput(page);
      if (!searchInput && PROJECTS_REQUIRING_SEARCH.includes(testInfo.project.name)) {
        throw new Error(`Search UI was not found for ${testInfo.project.name}; a search UI is expected here and this must not be skipped.`);
      }
      test.skip(!searchInput, 'Search UI is not available in this browser/theme fixture.');
      await searchInput.fill('test');

      console.log(`   🔎 Submitting search form`);
      // Set up listener BEFORE triggering the action
      const eventPromise = pixelCapture.waitForEvent();

      await submitSearch(page, searchInput);
      await TestSetup.waitForPageReady(page);
      await eventPromise;

      const validator = new EventValidator(testId);
      await validator.checkDebugLog();
      const result = await validator.validate('Search', page);

      if (!result.passed) {
        const diagnostics = {
          project: testInfo.project.name,
          currentUrl: page.url(),
          testId,
          errors: result.errors,
          pixelCustomData: result.pixel?.custom_data,
          capiCustomData: result.capi?.custom_data,
        };
        console.log(`🔎 Search validation diagnostics: ${JSON.stringify(diagnostics)}`);
      }

      TestSetup.logResult('Search', result);
      expect(result.passed).toBe(true);
    } finally {
      await removeSingleSearchRedirectBlockerMuPlugin().catch(() => {});
    }
});

test('Search - No Results', async ({ page }, testInfo) => {
    const { testId, pixelCapture } = await TestSetup.init(page, 'Search', testInfo, true); // expectZeroEvents=true

    console.log(`   🏠 Navigating to homepage`);
    await page.goto('/');
    await TestSetup.waitForPageReady(page);

    // Generate random string that won't match any products
    const randomString = 'xyzabc239nfjsdn' + Date.now();
    console.log(`   🔍 Typing random search query: ${randomString}`);

    const searchInput = await getVisibleSearchInput(page);
    if (!searchInput && PROJECTS_REQUIRING_SEARCH.includes(testInfo.project.name)) {
      throw new Error(`Search UI was not found for ${testInfo.project.name}; a search UI is expected here and this must not be skipped.`);
    }
    test.skip(!searchInput, 'Search UI is not available in this browser/theme fixture.');
    await searchInput.fill(randomString);

    console.log(`   🔎 Submitting search form (expecting no events)`);
    const eventPromise = pixelCapture.waitForEvent(); // Will succeed if no event fires

    await submitSearch(page, searchInput);
    await TestSetup.waitForPageReady(page);
    await eventPromise;

    const validator = new EventValidator(testId, false, true); // expectZeroEvents=true
    await validator.checkDebugLog();
    const result = await validator.validate('Search', page);

    TestSetup.logResult('Search (No Results)', result);
    expect(result.passed).toBe(true);
});


test('ViewContent - Signals held (no immediate Pixel/CAPI send)', async ({ page }, testInfo) => {
    try {
      console.log('🍪 Clearing existing FB cookies...');
      await page.context().clearCookies();

      const { testId, pixelCapture } = await TestSetup.init(page, 'ViewContent', testInfo, true);

      await page.goto('/');
      await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

      const holdResult = await holdSignals(page);
      expect(holdResult.state).toBe('held');

      const eventPromise = pixelCapture.waitForEvent();
      await page.goto(process.env.TEST_PRODUCT_URL);
      await TestSetup.waitForPageReady(page);
      await eventPromise;

      const validator = new EventValidator(testId, false, true);
      await validator.checkDebugLog();
      const result = await validator.validate('ViewContent', page);

      const queuedViewContentEvents = await getQueuedSignalEvents(page, 'ViewContent');
      expect(queuedViewContentEvents.length).toBeGreaterThanOrEqual(1);

      TestSetup.logResult('ViewContent (Signals Held)', result);
      expect(result.passed).toBe(true);
    } finally {
      // Cleanup: always restore signal state even on assertion failures.
      await releaseSignals(page).catch(() => {});
    }
});

test('ViewContent - Signals release flushes queued Pixel/CAPI', async ({ page }, testInfo) => {
    console.log('🍪 Clearing existing FB cookies...');
    await page.context().clearCookies();

    const { testId, pixelCapture } = await TestSetup.init(page, 'ViewContent', testInfo);

    await page.goto('/');
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    const holdResult = await holdSignals(page);
    expect(holdResult.state).toBe('held');

    const recorder = createPixelEventRequestRecorder(page, 'ViewContent');

    try {
      await page.goto(process.env.TEST_PRODUCT_URL);
      await TestSetup.waitForPageReady(page);

      const queuedBeforeRelease = await getQueuedSignalEvents(page, 'ViewContent');
      expect(queuedBeforeRelease.length).toBeGreaterThanOrEqual(1);

      const replayPromise = pixelCapture.waitForEvent();
      const releaseResult = await releaseSignals(page);
      await replayPromise;

      expect(releaseResult.state).toBe('active');

      const releasedPixelEvents = await waitForMinimumPixelEvents(recorder, queuedBeforeRelease.length, 15000);
      const releasedCapiEvents = await waitForMinimumCapiEvents(testId, 'ViewContent', queuedBeforeRelease.length, 15000);

      expect(releasedPixelEvents.length).toBeGreaterThanOrEqual(queuedBeforeRelease.length);
      expect(releasedCapiEvents.length).toBeGreaterThanOrEqual(queuedBeforeRelease.length);

      const queuedIds = new Set(queuedBeforeRelease.map(event => event.event_id).filter(Boolean));
      const replayedPixelIds = new Set(releasedPixelEvents.map(event => event.eventId).filter(Boolean));
      const replayedCapiIds = new Set(releasedCapiEvents.map(event => event.event_id).filter(Boolean));

      queuedIds.forEach((eventId) => {
        expect(replayedPixelIds.has(eventId)).toBe(true);
        expect(replayedCapiIds.has(eventId)).toBe(true);
      });

      const queuedAfterRelease = await getQueuedSignalEvents(page, 'ViewContent');
      expect(queuedAfterRelease.length).toBe(0);
    } finally {
      recorder.stop();
      // Cleanup: always restore signal state even if release-path assertions fail midway.
      await releaseSignals(page).catch(() => {});
    }
});

test('AddToCart - Signals hold/release with multiple shop AJAX clicks', async ({ page }, testInfo) => {
    const ajaxAvailable = await isAjaxAddToCartAvailableOnShop(page, { productUrl: process.env.TEST_PRODUCT_URL });
    test.skip(!ajaxAvailable, 'Shop AJAX AddToCart is not available in this browser/theme fixture.');

    await clearCart(page);
    await page.context().clearCookies();

    const { testId } = await TestSetup.init(page, 'AddToCart', testInfo);

    await page.goto('/shop');
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    const holdResult = await holdSignals(page);
    expect(holdResult.state).toBe('held');

    // Important: initialize the page in held mode so FacebookSignals receives
    // held=true + release endpoint config during page boot.
    console.log('ℹ️ Signals set to held; reloading /shop to initialize held runtime config.');
    await page.reload({ waitUntil: 'networkidle' });
    await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

    const signalRuntimeAfterHold = await getSignalRuntimeSnapshot(page);

    const recorder = createPixelEventRequestRecorder(page, 'AddToCart');

    const releaseAjaxRequests = [];
    const releaseAjaxResponses = [];

    const onReleaseRequest = (request) => {
      const url = request.url();
      if (!url.includes('admin-ajax.php') || !url.includes('action=facebook_release_signals')) {
        return;
      }

      releaseAjaxRequests.push({
        method: request.method(),
        url,
        bodyPreview: (request.postData() || '').slice(0, 500),
      });
    };

    const onReleaseResponse = async (response) => {
      const url = response.url();
      if (!url.includes('admin-ajax.php') || !url.includes('action=facebook_release_signals')) {
        return;
      }

      let text = '';
      try {
        text = await response.text();
      } catch (_) {
        text = '';
      }

      releaseAjaxResponses.push({
        status: response.status(),
        ok: response.ok(),
        url,
        bodyPreview: (text || '').slice(0, 1000),
      });
    };

    page.on('request', onReleaseRequest);
    page.on('response', onReleaseResponse);

    try {
      const targetClicks = 3;

      const shopAjaxButtons = page.locator('a.add_to_cart_button.ajax_add_to_cart, button.add_to_cart_button.ajax_add_to_cart');
      const totalButtons = await shopAjaxButtons.count();
      test.skip(totalButtons < targetClicks, `Need at least ${targetClicks} AJAX add-to-cart buttons on /shop. Found ${totalButtons}.`);

      for (let index = 0; index < targetClicks; index += 1) {
        await shopAjaxButtons.nth(index).click({ force: true });
        await page.waitForTimeout(TIMEOUTS.NORMAL);
        await page.waitForLoadState('networkidle').catch(() => {});
      }

      const pixelWhileHeld = recorder.getEvents();
      expect(pixelWhileHeld.length).toBe(0);

      const queuedBeforeRelease = await getQueuedSignalEvents(page, 'AddToCart');
      expect(queuedBeforeRelease.length).toBeGreaterThanOrEqual(targetClicks);

      const signalRuntimeBeforeRelease = await getSignalRuntimeSnapshot(page);
      console.log(`ℹ️ Queued AddToCart events while held: ${queuedBeforeRelease.length}`);

      const queuedEventIds = queuedBeforeRelease
        .map(event => event.event_id)
        .filter(Boolean);
      expect(queuedEventIds.length).toBeGreaterThanOrEqual(targetClicks);
      expect(new Set(queuedEventIds).size).toBe(queuedEventIds.length);

      const capiWhileHeld = await waitForMinimumCapiEvents(testId, 'AddToCart', 1, 2000);
      expect(capiWhileHeld.length).toBe(0);

      const releaseResult = await releaseSignals(page);
      expect(releaseResult.state).toBe('active');

      // Give release-side async actions a short window to emit network calls.
      await page.waitForTimeout(1000);

      const signalRuntimeAfterRelease = await getSignalRuntimeSnapshot(page);

      const releasedPixelEvents = await waitForMinimumPixelEvents(recorder, queuedBeforeRelease.length, 20000);
      const releasedCapiEvents = await waitForMinimumCapiEvents(testId, 'AddToCart', queuedBeforeRelease.length, 20000);

      const releasedPixelIds = new Set(releasedPixelEvents.map(event => event.eventId).filter(Boolean));
      const releasedCapiIds = new Set(releasedCapiEvents.map(event => event.event_id).filter(Boolean));

      console.log(`ℹ️ Release completed: pixel replays=${releasedPixelEvents.length}, capi replays=${releasedCapiEvents.length}`);

      const diagnostics = {
        testId,
        signalRuntimeAfterHold,
        signalRuntimeBeforeRelease,
        signalRuntimeAfterRelease,
        queuedBeforeReleaseCount: queuedBeforeRelease.length,
        queuedEventIds,
        releaseResultState: releaseResult?.state,
        releaseResultPayload: releaseResult?.response?.data || releaseResult?.response || null,
        releaseAjaxRequests,
        releaseAjaxResponses,
        releasedPixelCount: releasedPixelEvents.length,
        releasedPixelIds: [...releasedPixelIds],
        releasedCapiCount: releasedCapiEvents.length,
        releasedCapiIds: [...releasedCapiIds],
        releasedPixelSample: releasedPixelEvents.slice(0, 3),
        releasedCapiSample: releasedCapiEvents.slice(0, 3),
      };

      if (
        signalRuntimeAfterHold.state !== 'held' ||
        !signalRuntimeAfterHold.hasFacebookSignals ||
        !signalRuntimeAfterHold.facebookSignalsHeldFlag ||
        signalRuntimeAfterHold.configAction !== 'facebook_release_signals' ||
        !signalRuntimeAfterHold.configAjaxUrl ||
        !signalRuntimeAfterHold.configNoncePresent
      ) {
        throw new Error(`Signals runtime did not initialize in held mode after reload. Diagnostics: ${JSON.stringify(diagnostics)}`);
      }

      if (releasedCapiEvents.length === 0) {
        throw new Error(`No released AddToCart CAPI events were captured. Diagnostics: ${JSON.stringify(diagnostics)}`);
      }
      releasedCapiIds.forEach((eventId) => {
        expect(queuedEventIds.includes(eventId)).toBe(true);
      });

      expect(releasedPixelEvents.length).toBeGreaterThan(0);
      if (releasedPixelIds.size > 0) {
        const matchedPixelIds = [...releasedPixelIds].filter(eventId => queuedEventIds.includes(eventId));
        expect(matchedPixelIds.length).toBeGreaterThan(0);
      }

      const groupedCapiById = releasedCapiEvents.reduce((acc, event) => {
        const id = event.event_id || `missing-${acc.size}`;
        acc.set(id, (acc.get(id) || 0) + 1);
        return acc;
      }, new Map());

      groupedCapiById.forEach((count, eventId) => {
        expect(count).toBe(1);
        if (!String(eventId).startsWith('missing-')) {
          const matchedQueued = queuedBeforeRelease.find(event => event.event_id === eventId);
          expect(matchedQueued).toBeTruthy();
        }
      });

      const queuedAfterRelease = await getQueuedSignalEvents(page, 'AddToCart');
      expect(queuedAfterRelease.length).toBe(0);

      const cookies = await page.context().cookies();
      expect(cookies.find(c => c.name === '_fbp')).toBeDefined();

      console.log(`✅ AddToCart held/release replay validated for ${queuedEventIds.length} queued events`);
    } finally {
      recorder.stop();
      page.off('request', onReleaseRequest);
      page.off('response', onReleaseResponse);
      // Cleanup: always restore signal state and cart baseline.
      await releaseSignals(page).catch(() => {});
      await clearCart(page).catch(() => {});
    }
});

// Lead event is not tested as it needs an SMTP server etc
// NOTE: Subscribe test is skipped because it requires WooCommerce Paid Subscriptions
// Free alternatives (YITH, Subscriptio) use different APIs incompatible with facebook-for-woocommerce
// The plugin specifically checks for wcs_get_subscriptions_for_order() which only exists in the official plugin

// =============================================================================
// Isolated Execution Context Tests (Gap 1 Fix)
// =============================================================================
// These tests verify that pixel events fire even when other plugins have JS errors.
// This validates the fix for Gap 1: Shared JavaScript Execution Context.

test('ViewContent - Isolated Execution (with JS errors from other plugins)', async ({ page }, testInfo) => {
    console.log('🧪 Testing isolated event execution with simulated plugin errors...');

    // 1. Install the JS error simulator mu-plugin
    await installJsErrorSimulatorMuPlugin();

    try {
        // 2. Initialize test
        const { testId, pixelCapture } = await TestSetup.init(page, 'ViewContent', testInfo);

        // 3. Set up console error listener to verify errors are being thrown
        const consoleErrors = [];
        page.on('console', msg => {
            if (msg.type() === 'error' || msg.text().includes('[E2E Test]')) {
                consoleErrors.push(msg.text());
            }
        });

        // 4. Navigate to product page
        console.log('   📦 Navigating to product page (with 3 simulated JS errors active)...');
        const eventPromise = pixelCapture.waitForEvent();
        await page.goto(process.env.TEST_PRODUCT_URL);
        await TestSetup.waitForPageReady(page);
        await eventPromise;

        // 5. Verify that errors were indeed thrown (simulator is working)
        console.log(`   🔍 Console messages captured: ${consoleErrors.length}`);
        const simulatorErrors = consoleErrors.filter(msg => msg.includes('[E2E Test]'));
        console.log(`   ⚠️ Simulator errors detected: ${simulatorErrors.length}`);

        if (simulatorErrors.length === 0) {
            console.warn('   ⚠️ Warning: No simulator errors detected - simulator may not be active');
        }

        // 6. Validate that ViewContent event STILL fired despite JS errors
        console.log('   ✅ Validating ViewContent event fired despite JS errors...');
        const validator = new EventValidator(testId);
        await validator.checkDebugLog();
        const result = await validator.validate('ViewContent', page);

        TestSetup.logResult('ViewContent (Isolated Execution)', result);
        expect(result.passed).toBe(true);

        console.log('   🎉 SUCCESS: Pixel events fire even with other plugins\' JS errors!');

    } finally {
        // 7. Always cleanup - remove the error simulator
        console.log('   🧹 Cleaning up JS error simulator...');
        await removeJsErrorSimulatorMuPlugin();
    }
});

test('AddToCart - Isolated Execution (with JS errors from other plugins)', async ({ page }, testInfo) => {
    console.log('🧪 Testing AddToCart isolated execution with simulated plugin errors...');

    // 1. Install the JS error simulator mu-plugin
    await installJsErrorSimulatorMuPlugin();

    try {
        // 2. Initialize test
        const { testId, pixelCapture } = await TestSetup.init(page, 'AddToCart', testInfo);

        // 3. Navigate to product page first
        await page.goto(process.env.TEST_PRODUCT_URL);
        await TestSetup.waitForPageReady(page, TIMEOUTS.INSTANT);

        // 4. Click Add to Cart (with 3 simulated JS errors active)
        console.log('   🛒 Clicking Add to Cart (with 3 simulated JS errors active)...');
        const eventPromise = pixelCapture.waitForEvent();
        await page.click('.single_add_to_cart_button');

        // Wait for page to reload (form submission) and become ready
        await page.waitForLoadState('networkidle');
        await eventPromise;

        // 5. Validate that AddToCart event fired despite JS errors
        console.log('   ✅ Validating AddToCart event fired despite JS errors...');
        const validator = new EventValidator(testId);
        await validator.checkDebugLog();
        const result = await validator.validate('AddToCart', page);

        TestSetup.logResult('AddToCart (Isolated Execution)', result);
        expect(result.passed).toBe(true);

        console.log('   🎉 SUCCESS: AddToCart fires even with other plugins\' JS errors!');

    } finally {
        // 6. Always cleanup
        console.log('   🧹 Cleaning up JS error simulator...');
        await removeJsErrorSimulatorMuPlugin();
    }
});

function getMajorBrowserVersion(versionString) {
    const match = String(versionString || '').match(/^(\d+)/);
    return match ? parseInt(match[1], 10) : NaN;
}

test('Privacy Sandbox - Protected Audience API shape in Chromium', async ({ page, browser }) => {
    test.skip(!test.info().project.name.includes('privacy-sandbox'), 'Privacy Sandbox test only runs on privacy-sandbox project');

    const chromiumMajor = getMajorBrowserVersion(browser.version());
    test.skip(Number.isNaN(chromiumMajor) || chromiumMajor < 115, `Privacy Sandbox requires Chrome/Chromium >= 115 (detected ${browser.version()})`);

    await page.goto('/');
    await TestSetup.waitForPageReady(page);

    const apiShape = await page.evaluate(() => {
        return {
            hasNavigator: typeof navigator !== 'undefined',
            hasJoinAdInterestGroup: typeof navigator?.joinAdInterestGroup === 'function',
            hasRunAdAuction: typeof navigator?.runAdAuction === 'function',
            hasLeaveAdInterestGroup: typeof navigator?.leaveAdInterestGroup === 'function',
        };
    });

    const hasAnyProtectedAudienceApi =
      apiShape.hasJoinAdInterestGroup
      || apiShape.hasRunAdAuction
      || apiShape.hasLeaveAdInterestGroup;

    expect(apiShape.hasNavigator).toBe(true);
    expect(hasAnyProtectedAudienceApi).toBe(true);
});

// Cleanup is handled by GitHub workflow after all tests complete
// This ensures product exists for all tests even if some fail
