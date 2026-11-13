/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Checkout/purchase flow helpers for E2E tests
 */

const { TIMEOUTS } = require('../constants/timeouts');
const { execWP } = require('../wordpress/exec');

/**
 * Complete a purchase flow from product to order confirmation
 * @param {import('@playwright/test').Page} page - Playwright page
 * @param {string|null} productUrl - Optional product URL
 * @returns {Promise<Object>} Order details
 */
async function completePurchaseFlow(page, productUrl = null) {
  const url = productUrl || process.env.TEST_PRODUCT_URL;

  console.log(`   📦 Navigating to product page`);
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.EXTRA_LONG });

  console.log(`   🛒 Adding product to cart`);
  await page.click('.single_add_to_cart_button');
  await page.waitForTimeout(TIMEOUTS.SHORT);

  console.log(`   💳 Navigating to checkout`);
  const { stdout } = await execWP('echo wp_json_encode(wc_get_checkout_url());');
  const checkoutUrl = JSON.parse(stdout.trim());
  await page.goto(checkoutUrl, { waitUntil: 'domcontentloaded', timeout: TIMEOUTS.EXTRA_LONG });
  await page.evaluate(() => window.scrollBy(0, 400));

  console.log(`   📝 Filling billing address from environment variables`);

  // Skip form fill if billing address already saved (Edit button visible)
  const editButton = page.locator('.wc-block-components-address-card__edit[aria-label="Edit billing address"]');
  if (await editButton.isVisible({ timeout: TIMEOUTS.SHORT }).catch(() => false)) {
    console.log(`   ✅ Billing address already saved, skipping form fill`);
  } else {
    // Fill in billing details from environment variables
    await page.fill('#billing-first_name', process.env.TEST_USER_FIRST_NAME);
    await page.fill('#billing-last_name', process.env.TEST_USER_LAST_NAME);
    await page.fill('#billing-address_1', process.env.TEST_USER_ADDRESS_1);
    await page.fill('#billing-city', process.env.TEST_USER_CITY);

    await page.selectOption('#billing-country', process.env.TEST_USER_COUNTRY);
    await page.waitForTimeout(TIMEOUTS.INSTANT);

    await page.selectOption('#billing-state', process.env.TEST_USER_STATE);
    await page.waitForTimeout(TIMEOUTS.INSTANT);

    const postcodeField = page.locator('#billing-postcode');
    await postcodeField.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
    await postcodeField.fill(process.env.TEST_USER_POSTCODE);

    if (process.env.TEST_USER_PHONE) {
      await page.fill('#billing-phone', process.env.TEST_USER_PHONE);
    }

    console.log(`   ✅ Billing address filled`);
  }

  console.log(`   💰 Selecting Cash on Delivery`);
  await page.waitForSelector('.wc-block-components-radio-control__option[for="radio-control-wc-payment-method-options-cod"]', {
    state: 'visible',
    timeout: TIMEOUTS.LONG
  });
  await page.click('label[for="radio-control-wc-payment-method-options-cod"]');
  await page.waitForTimeout(TIMEOUTS.INSTANT);

  console.log(`   ✅ Placing order`);
  await page.locator('.wc-block-components-checkout-place-order-button').scrollIntoViewIfNeeded();

  console.log(`   ⏳ Waiting for order to process...`);
  await page.click('.wc-block-components-checkout-place-order-button');
  await page.waitForURL(url => (
    /\/checkout\/order-received\/\d+/.test(url.pathname)
      || /^\d+$/.test(url.searchParams.get('order-received') || '')
  ), { timeout: TIMEOUTS.EXTRA_LONG });

  const orderReceivedUrl = page.url();
  console.log(`   ✅ Order completed: ${orderReceivedUrl}`);

  const parsedOrderUrl = new URL(orderReceivedUrl);
  const orderIdMatch = parsedOrderUrl.pathname.match(/order-received\/(\d+)/);
  const orderId = orderIdMatch
    ? orderIdMatch[1]
    : parsedOrderUrl.searchParams.get('order-received');

  return { orderReceivedUrl, orderId };
}

module.exports = {
  completePurchaseFlow
};
