/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Product navigation helpers for E2E tests
 */

const { TIMEOUTS } = require('../constants/timeouts');

const baseURL = process.env.WORDPRESS_URL;

/**
 * Navigate to products table and filter by product type
 * @param {import('@playwright/test').Page} page - Playwright page
 * @param {string} productType - Product type to filter
 * @param {string|null} productSKU - Optional SKU to search
 */
async function filterProducts(page, productType, productSKU = null) {
  console.log('📋 Navigating to Products page...');
  await page.goto(`${baseURL}/wp-admin/edit.php?post_type=product`, {
    waitUntil: 'domcontentloaded',
    timeout: TIMEOUTS.MAX
  });

  console.log('🔍 Filtering by Simple product type...');
  const productTypeFilter = page.locator('select#dropdown_product_type');
  if (await productTypeFilter.isVisible({ timeout: TIMEOUTS.LONG })) {
    const filterButton = page.locator("#post-query-submit");
    await productTypeFilter.selectOption(productType.toLowerCase());
    await filterButton.click();
    await page.waitForLoadState('domcontentloaded');
    console.log('✅ Filtered by product type');
  } else {
    console.warn('⚠️ Product type filter not found, proceeding without filter');
  }

  if (productSKU) {
    console.log(`🔍 Searching for product with SKU: ${productSKU}`);
    const searchBox = page.locator('#post-search-input');
    if (await searchBox.isVisible({ timeout: TIMEOUTS.LONG })) {
      await searchBox.fill(productSKU);
      const searchButton = page.locator('#search-submit');
      await searchButton.click();
      await page.waitForLoadState('domcontentloaded');
      console.log('✅ Searched for product by SKU');
    } else {
      console.warn('⚠️ Search box not found, cannot search by SKU');
    }
  }

  await page.locator('.wp-list-table').waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
}

/**
 * Click the first visible product from products table
 * @param {import('@playwright/test').Page} page - Playwright page
 */
async function clickFirstProduct(page) {
  const firstProductRow = page.locator('.wp-list-table tbody tr.iedit').first();
  await firstProductRow.isVisible({ timeout: TIMEOUTS.LONG });
  const productNameElement = firstProductRow.locator('.row-title');
  const productName = await productNameElement.textContent();
  console.log(`✅ Found product: "${productName}"`);

  await productNameElement.click();
  await page.waitForLoadState('domcontentloaded', { timeout: TIMEOUTS.MAX });
  console.log('✅ Opened product editor');
}

/**
 * Bring the Publish box back within reach of a click.
 *
 * On the classic editor, wp-admin/js/editor-expand.js pins #side-sortables with
 * `position: fixed; bottom: 20px` after a downward scroll whenever the editor and the
 * sidebar are both taller than the window. The top of the sidebar, Publish box included,
 * then sits above the viewport, and a fixed element cannot be scrolled into view, so a
 * click on #publish retries until it times out. Scrolling to the top makes that script
 * drop the pin.
 *
 * @param {import('@playwright/test').Page} page - Playwright page
 */
async function releasePinnedSidebar(page) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForFunction(() => {
    const sidebar = document.querySelector('#side-sortables');
    return !sidebar || window.getComputedStyle(sidebar).position !== 'fixed';
  }, null, { timeout: TIMEOUTS.MEDIUM }).catch(() => {
    console.warn('⚠️ The editor sidebar is still pinned after scrolling to the top');
  });
}

/**
 * Publish a product
 * @param {import('@playwright/test').Page} page - Playwright page
 * @returns {Promise<boolean>} Success status
 */
async function publishProduct(page) {
  await releasePinnedSidebar(page);
  await page.locator('#publishing-action').scrollIntoViewIfNeeded();
  const publishButton = page.locator('#publish');
  await publishButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
  await publishButton.waitFor({ state: 'attached', timeout: TIMEOUTS.LONG });
  await publishButton.click();
  console.log('Clicked Publish button');
  let publishSuccess = true;
  await page.waitForURL(/\/wp-admin\/post\.php\?post=\d+/, { timeout: TIMEOUTS.EXTRA_LONG }).catch(() => {
    console.warn('⚠️ URL did not change after publishing. Current URL: ' + page.url())
    publishSuccess = false;
  });

  if (!publishSuccess) {
    console.warn(`⚠️ Encountered Wordpress Publish button bug. Clicking Publish did not change url. Current url: ${page.url()} Clicking Publish button again`);
    await releasePinnedSidebar(page);
    await publishButton.click();
    await page.waitForTimeout(TIMEOUTS.MEDIUM);
  }

  let updateButton = page.getByRole('button', { name: 'Update' });
  await updateButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });

  await page.waitForLoadState('domcontentloaded', { timeout: TIMEOUTS.MAX });
  console.log('✅ Published product');
  return true;
}

module.exports = {
  filterProducts,
  clickFirstProduct,
  releasePinnedSidebar,
  publishProduct
};
