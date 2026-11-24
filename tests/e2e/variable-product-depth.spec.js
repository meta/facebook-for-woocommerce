/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

const { test, expect } = require('@playwright/test');
const {
  TIMEOUTS,
  baseURL,
  safeScreenshot,
  cleanupProduct,
  generateProductName,
  generateUniqueSKU,
  extractProductIdFromUrl,
  publishProduct,
  checkForPhpErrors,
  logTestStart,
  logTestEnd,
  validateFacebookSync,
  processPendingSyncJobs,
  execWP,
  createTestProduct,
  setProductTitle,
  setProductDescription,
  dismissWooInterferingOverlays,
} = require('./helpers/js');

// Not `describe.serial`: this file already runs with --workers=1, so the tests
// execute in declaration order either way. What `.serial` added was retry
// semantics -- one failing test re-runs the WHOLE block, so each retry repeated
// every already-passing test in the file. These tests build 8-variation products
// and wait on catalog convergence, so a single late failure could re-run several
// minutes of passing work twice and push the shard past its 45-minute timeout.
// The tests share no mutable state, so retrying only the failed test is both
// cheaper and more accurate.
test.describe('Variable Product Depth Tests', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium-wp-admin', 'Variable product depth tests require wp-admin project');

    logTestStart(testInfo);
    await page.setViewportSize({ width: 1280, height: 720 });
  });

  const VARIABLE_PRODUCT_CONFIG = {
    attributes: {
      Color: ['Red', 'Blue'],
      Size: ['Small', 'Medium'],
      Material: ['Cotton', 'Wool'],
    },
    basePrice: 41,
    baseStock: 5,
    syncWaitSeconds: 5,
    syncMaxRetries: 8,
  };

  function buildPipeList(values) {
    return values.join('|');
  }

  function getVariationCount(attributes) {
    return Object.values(attributes).reduce((total, values) => total * values.length, 1);
  }

  function buildVariationDefinitions(expectedCount) {
    return Array.from({ length: expectedCount }, (_, index) => ({
      index,
      price: (VARIABLE_PRODUCT_CONFIG.basePrice + index).toFixed(2),
      sku: generateUniqueSKU(`var-depth-${index + 1}`),
      stock: String(VARIABLE_PRODUCT_CONFIG.baseStock + index),
    }));
  }

  function normalizeText(value) {
    return String(value || '').trim().toLowerCase();
  }

  function normalizePrice(value) {
    const match = String(value || '').match(/\d+(?:\.\d+)?/);
    return match ? Number(match[0]).toFixed(2) : null;
  }

  async function resolveProductId(page) {
    const urlProductId = extractProductIdFromUrl(page.url());
    if (urlProductId) {
      return urlProductId;
    }

    const postIdField = page.locator('#post_ID, input[name="post_ID"]').first();
    if (await postIdField.count()) {
      const value = await postIdField.getAttribute('value');
      if (value && /^\d+$/.test(value)) {
        return Number(value);
      }
    }

    throw new Error(`Unable to resolve product ID from URL or #post_ID. Current URL: ${page.url()}`);
  }

  async function waitForProductEditor(page) {
    await page.waitForLoadState('domcontentloaded', { timeout: TIMEOUTS.MAX });
    await page.locator('#title').waitFor({ state: 'visible', timeout: TIMEOUTS.EXTRA_LONG });
    await page.locator('#woocommerce-product-data').waitFor({ state: 'visible', timeout: TIMEOUTS.EXTRA_LONG });
  }

  async function openVariationsTab(page) {
    const variationsTab = page.locator('li.variations_tab a, a[href="#variable_product_options"]').first();

    for (let attempt = 1; attempt <= 3; attempt++) {
      await variationsTab.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
      await variationsTab.scrollIntoViewIfNeeded();

      if (attempt === 1) {
        await variationsTab.click();
      } else {
        await variationsTab.click({ force: true });
      }

      try {
        // Prefer waiting for actionable controls instead of panel class/style,
        // since Woo can keep "hidden" class while content is still interactable.
        const actionableControl = page.locator(
          '#variable_product_options button.generate_variations, '
          + '#variable_product_options button.add_variation, '
          + '#variable_product_options button.save-variation-changes'
        ).first();
        await actionableControl.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
        return;
      } catch (error) {
        if (attempt === 3) {
          throw error;
        }

        console.warn(`⚠️ openVariationsTab attempt ${attempt}/3 could not reveal controls; retrying...`);
        await page.waitForTimeout(TIMEOUTS.SHORT);
      }
    }
  }

  async function expandAllVariations(page) {
    const expandAllButton = page.getByRole('link', { name: 'Expand' }).first();
    await expandAllButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await expandAllButton.click();
    await page.waitForTimeout(TIMEOUTS.NORMAL);
  }

  async function waitForVariationCount(page, expectedCount) {
    await page.waitForFunction(
      (count) => document.querySelectorAll('.woocommerce_variation').length === count,
      expectedCount,
      { timeout: TIMEOUTS.EXTRA_LONG }
    );
  }

  async function waitForWooVariationOverlayToClear(page) {
    await page.waitForFunction(() => {
      const overlays = Array.from(document.querySelectorAll('.blockUI.blockOverlay'));
      if (!overlays.length) {
        return true;
      }

      return overlays.every((overlay) => {
        const style = window.getComputedStyle(overlay);
        return style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity || 1) === 0;
      });
    }, { timeout: TIMEOUTS.EXTRA_LONG }).catch(() => {});
  }

  async function saveVariationChanges(page) {
    const saveVariationsButton = page.locator('button.save-variation-changes');
    await saveVariationsButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await waitForWooVariationOverlayToClear(page);

    if (await saveVariationsButton.isEnabled()) {
      await page.keyboard.press('Tab').catch(() => {});
      await saveVariationsButton.click();
      await waitForWooVariationOverlayToClear(page);
      await expect(saveVariationsButton).toBeDisabled({ timeout: TIMEOUTS.EXTRA_LONG }).catch(() => {});
      await page.waitForTimeout(TIMEOUTS.NORMAL);
      console.log('✅ Saved variation changes');
    } else {
      console.log('ℹ️ Variation changes button already disabled; nothing to save');
    }
  }

  async function setupAttributes(page, attributes) {
    await dismissWooInterferingOverlays(page);
    await page.click('li.attribute_tab a[href="#product_attributes"]');

    const attributeEntries = Object.entries(attributes);
    const addCustomAttributeButton = page.locator('button.add_custom_attribute').first();
    const attributeRows = page.locator('#product_attributes .woocommerce_attribute');

    await addCustomAttributeButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await attributeRows.first().waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });

    const addAttributeRow = async (expectedCount) => {
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          await dismissWooInterferingOverlays(page);
          // Some Woo admin layouts render multiple add buttons; try all visible ones.
          const buttons = page.locator('button.add_custom_attribute');
          const buttonCount = await buttons.count();

          for (let i = 0; i < buttonCount; i++) {
            const button = buttons.nth(i);
            const visible = await button.isVisible().catch(() => false);
            if (!visible) {
              continue;
            }

            await button.scrollIntoViewIfNeeded().catch(() => {});
            await button.click(attempt === 1 ? {} : { force: true });

            const added = await page.waitForFunction(
              (count) => document.querySelectorAll('#product_attributes .woocommerce_attribute').length >= count,
              expectedCount,
              { timeout: TIMEOUTS.LONG }
            ).then(() => true).catch(() => false);

            if (added) {
              return;
            }
          }

          // Fallback: trigger a DOM click directly in case Playwright click misses Woo's delegated listener.
          await page.evaluate(() => {
            const candidates = Array.from(document.querySelectorAll('#product_attributes button.add_custom_attribute, button.add_custom_attribute'));
            const visible = candidates.filter((el) => el.offsetParent !== null);
            const target = visible[visible.length - 1] || candidates[candidates.length - 1];
            if (target) {
              target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
              target.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
              target.click();
            }
          });

          await expect(attributeRows).toHaveCount(expectedCount, { timeout: TIMEOUTS.LONG });
          return;
        } catch (error) {
          if (attempt === 3) {
            throw error;
          }
          console.warn(`⚠️ add_custom_attribute click attempt ${attempt}/3 failed; retrying...`);
          await page.waitForTimeout(TIMEOUTS.SHORT);
        }
      }
    };

    for (let i = 0; i < attributeEntries.length; i++) {
      const [attributeName, values] = attributeEntries[i];

      if (i > 0) {
        await addAttributeRow(i + 1);
      }

      const attributeRow = attributeRows.nth(i);
      await attributeRow.waitFor({ state: 'visible', timeout: TIMEOUTS.EXTRA_LONG });

      const nameField = attributeRow.locator('input.attribute_name').first();
      const valuesField = attributeRow.locator('textarea[name^="attribute_values"]').first();

      await nameField.waitFor({ state: 'visible', timeout: TIMEOUTS.EXTRA_LONG });
      await nameField.fill(attributeName);
      await valuesField.fill(buildPipeList(values));

      const usedForVariationsCheckbox = attributeRow.locator('input.woocommerce_attribute_used_for_variations, input[name^="attribute_variation["]').first();
      if (await usedForVariationsCheckbox.count()) {
        const isChecked = await usedForVariationsCheckbox.isChecked().catch(() => false);
        if (!isChecked) {
          await usedForVariationsCheckbox.check({ force: true });
        }
      }

      console.log(`✅ Configured attribute ${attributeName}: ${values.join(', ')}`);
    }

    await page.locator('#product_attributes .woocommerce_attribute textarea[name^="attribute_values"]').last().press('Tab');
    await page.click('button.save_attributes.button-primary');

    await page.waitForFunction((expectedCount) => {
      return document.querySelectorAll('.woocommerce_attribute.wc-metabox.postbox.closed').length >= expectedCount;
    }, attributeEntries.length, { timeout: TIMEOUTS.EXTRA_LONG });

    console.log('✅ Saved variable product attributes');
  }

  async function generateAllVariations(page, expectedCount) {
    await openVariationsTab(page);

    page.once('dialog', async dialog => {
      console.log(`📢 Variation generation dialog: ${dialog.message()}`);
      await dialog.accept();
    });

    const generateVariationsButton = page.locator('button.generate_variations');
    await generateVariationsButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await generateVariationsButton.click();

    await waitForVariationCount(page, expectedCount);
    const actualCount = await page.locator('.woocommerce_variation').count();
    expect(actualCount).toBe(expectedCount);
    console.log(`✅ Generated ${actualCount} variations`);
  }

  async function assignVariationData(page, variationDefinitions) {
    await openVariationsTab(page);
    await expandAllVariations(page);

    const variationRows = page.locator('.woocommerce_variation');
    await expect(variationRows).toHaveCount(variationDefinitions.length);

    for (let i = 0; i < variationDefinitions.length; i++) {
      const variationRow = variationRows.nth(i);
      const variationData = variationDefinitions[i];

      await variationRow.scrollIntoViewIfNeeded();

      const priceField = variationRow.locator('input[name*="variable_regular_price"]').first();
      await priceField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
      await priceField.fill(variationData.price);

      const skuField = variationRow.locator('input[name*="variable_sku"]').first();
      await skuField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
      await skuField.fill(variationData.sku);

      const manageStockCheckbox = variationRow.locator('input.variable_manage_stock, input[name*="variable_manage_stock"]').first();
      if (await manageStockCheckbox.count()) {
        const isChecked = await manageStockCheckbox.isChecked().catch(() => false);
        if (!isChecked) {
          await manageStockCheckbox.check({ force: true });
        }
      }

      const stockField = variationRow.locator('input[name*="variable_stock"]').first();
      await stockField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
      await stockField.fill(variationData.stock);

      console.log(`✅ Variation ${i + 1}: price=${variationData.price}, sku=${variationData.sku}, stock=${variationData.stock}`);
    }

    await saveVariationChanges(page);
  }

  async function verifyVariationInputs(page, variationDefinitions, { expectedPriceForAll = null } = {}) {
    await openVariationsTab(page);
    await expandAllVariations(page);

    const variationRows = page.locator('.woocommerce_variation');
    await expect(variationRows).toHaveCount(variationDefinitions.length);

    for (let i = 0; i < variationDefinitions.length; i++) {
      const variationRow = variationRows.nth(i);
      const definition = variationDefinitions[i];

      const priceField = variationRow.locator('input[name*="variable_regular_price"]').first();
      const skuField = variationRow.locator('input[name*="variable_sku"]').first();
      const stockField = variationRow.locator('input[name*="variable_stock"]').first();

      await priceField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
      await skuField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
      await stockField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });

      const actualPrice = await priceField.inputValue();
      const actualSku = await skuField.inputValue();
      const actualStock = await stockField.inputValue();

      expect(actualPrice).toBe(expectedPriceForAll ?? definition.price);
      expect(actualSku).toBe(definition.sku);

      if (!expectedPriceForAll) {
        expect(actualStock).toBe(definition.stock);
      }
    }
  }

  async function forceEnqueueVariableProductSync(productId) {
    if (!productId) {
      return;
    }

    try {
      await execWP(`
        $product_id = ${Number(productId)};
        $product = wc_get_product($product_id);
        if ( ! $product ) {
          echo json_encode(['ok' => false, 'reason' => 'missing_product']);
          return;
        }

        $sync = facebook_for_woocommerce()->get_products_sync_handler();
        if ( $product->is_type('variable') ) {
          $sync->create_or_update_all_products_for_bulk_edit([$product_id]);
        } else {
          $sync->create_or_update_products([$product_id]);
        }

        $job = $sync->schedule_sync();
        echo json_encode([
          'ok' => true,
          'job_created' => ! empty($job),
          'product_id' => $product_id,
        ]);
      `);
      console.log(`🔁 Forced sync enqueue for variable product ${productId}`);
    } catch (error) {
      console.warn(`⚠️ Failed to force enqueue sync for product ${productId}: ${error.message}`);
    }
  }

  async function validateVariableProductSync(productId, productName, options = {}) {
    const attempts = options.attempts || 1;
    const waitBetweenAttemptsMs = options.waitBetweenAttemptsMs || (TIMEOUTS.NORMAL + TIMEOUTS.SHORT);

    let lastResult = null;

    for (let attempt = 1; attempt <= attempts; attempt++) {
      await forceEnqueueVariableProductSync(productId);
      await processPendingSyncJobs().catch(() => {});

      const result = await validateFacebookSync(
        productId,
        productName,
        VARIABLE_PRODUCT_CONFIG.syncWaitSeconds,
        VARIABLE_PRODUCT_CONFIG.syncMaxRetries
      );

      lastResult = result;

      if (result?.success) {
        break;
      }

      if (attempt < attempts) {
        const syncStatus = result?.sync_status || 'unknown';
        console.warn(`⚠️ Variable sync validation attempt ${attempt}/${attempts} for product ${productId} returned ${syncStatus}. Retrying...`);
        await new Promise((resolve) => setTimeout(resolve, waitBetweenAttemptsMs));
      }
    }

    expect(lastResult).not.toBeNull();
    expect(lastResult.success).toBe(true);
    return lastResult;
  }

  function assertVariationAttributeMapping(syncResult, expectedCount) {
    const wooData = syncResult.raw_data?.woo_data || [];
    const facebookData = syncResult.raw_data?.facebook_data || [];

    expect(wooData.length).toBe(expectedCount);
    expect(facebookData.length).toBe(expectedCount);

    const facebookByRetailerId = new Map(
      facebookData
        .filter(item => item && item.found !== false)
        .map(item => [item.retailer_id, item])
    );

    for (const wooItem of wooData) {
      const facebookItem = facebookByRetailerId.get(wooItem.retailer_id);
      expect(facebookItem, `Missing Facebook data for retailer_id ${wooItem.retailer_id}`).toBeTruthy();
      expect(normalizeText(facebookItem.color)).toBe(normalizeText(wooItem.color));
      expect(normalizeText(facebookItem.size)).toBe(normalizeText(wooItem.size));
    }

    console.log(`✅ Variation-level color/size mapping preserved for ${expectedCount} synced variations`);
  }

  function assertExpectedVariationPricesInSync(syncResult, expectedPrices) {
    const wooPrices = (syncResult.raw_data?.woo_data || []).map(item => normalizePrice(item.price)).sort();
    const expectedNormalizedPrices = expectedPrices.map(price => normalizePrice(price)).sort();
    expect(wooPrices).toEqual(expectedNormalizedPrices);
  }

  function mapWooDataByRetailerId(syncResult) {
    return new Map((syncResult.raw_data?.woo_data || []).map(item => [item.retailer_id, item]));
  }

  async function createPublishedVariableProduct(page) {
    const productName = generateProductName('VariableDepth');
    const expectedVariationCount = getVariationCount(VARIABLE_PRODUCT_CONFIG.attributes);
    const variationDefinitions = buildVariationDefinitions(expectedVariationCount);

    await page.goto(`${baseURL}/wp-admin/post-new.php?post_type=product`, {
      waitUntil: 'domcontentloaded',
      timeout: TIMEOUTS.MAX,
    });

    await waitForProductEditor(page);
    await setProductTitle(page, productName);
    await setProductDescription(page, 'Advanced variable product depth test with multiple attributes and variation-level updates.');

    await page.selectOption('#product-type', 'variable');
    await page.locator('#_sku').fill(generateUniqueSKU('variable-depth-parent'));

    await setupAttributes(page, VARIABLE_PRODUCT_CONFIG.attributes);
    await generateAllVariations(page, expectedVariationCount);
    await assignVariationData(page, variationDefinitions);

    await publishProduct(page);

    const productId = await resolveProductId(page);
    await checkForPhpErrors(page);

    const syncResult = await validateVariableProductSync(productId, productName);
    assertVariationAttributeMapping(syncResult, expectedVariationCount);
    assertExpectedVariationPricesInSync(syncResult, variationDefinitions.map(item => item.price));

    return {
      productId,
      productName,
      expectedVariationCount,
      variationDefinitions,
      syncResult,
    };
  }

  async function goToProductEditPage(page, productId) {
    const targetUrl = `${baseURL}/wp-admin/post.php?post=${productId}&action=edit`;

    // Avoid redundant navigation when WP already keeps us on the same editor URL.
    if (page.url().includes(`/wp-admin/post.php?post=${productId}&action=edit`)) {
      await waitForProductEditor(page);
      return;
    }

    for (let attempt = 1; attempt <= 3; attempt++) {
      try {
        await page.goto(targetUrl, {
          waitUntil: 'domcontentloaded',
          timeout: TIMEOUTS.MAX,
        });
        await waitForProductEditor(page);
        return;
      } catch (error) {
        const isAborted = String(error?.message || '').includes('net::ERR_ABORTED');
        if (!isAborted || attempt === 3) {
          throw error;
        }

        console.warn(`⚠️ goToProductEditPage attempt ${attempt}/3 hit ERR_ABORTED; retrying...`);
        await page.waitForTimeout(500 * attempt);
      }
    }
  }

  async function bulkSetRegularPrice(page, newPrice) {
    await openVariationsTab(page);
    await expandAllVariations(page);

    page.once('dialog', async dialog => {
      console.log(`📢 Bulk edit dialog: ${dialog.message()}`);
      await dialog.accept(newPrice);
    });

    const bulkActionsSelect = page.locator('select.variation_actions, #field_to_edit').first();
    await bulkActionsSelect.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await bulkActionsSelect.selectOption('variable_regular_price');
    await page.waitForTimeout(TIMEOUTS.NORMAL);

    await saveVariationChanges(page);
    console.log(`✅ Bulk set variation regular price to ${newPrice}`);
  }

  async function editSingleVariation(page, variationIndex, newPrice, newStock) {
    await openVariationsTab(page);
    await expandAllVariations(page);

    const variationRow = page.locator('.woocommerce_variation').nth(variationIndex);
    await variationRow.scrollIntoViewIfNeeded();

    const priceField = variationRow.locator('input[name*="variable_regular_price"]').first();
    await priceField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await priceField.fill(newPrice);

    const manageStockCheckbox = variationRow.locator('input.variable_manage_stock, input[name*="variable_manage_stock"]').first();
    if (await manageStockCheckbox.count()) {
      const isChecked = await manageStockCheckbox.isChecked().catch(() => false);
      if (!isChecked) {
        await manageStockCheckbox.check({ force: true });
      }
    }

    const stockField = variationRow.locator('input[name*="variable_stock"]').first();
    await stockField.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });
    await stockField.fill(newStock);

    await saveVariationChanges(page);
    console.log(`✅ Edited variation ${variationIndex + 1}: new price=${newPrice}, new stock=${newStock}`);
  }

  async function deleteVariation(page, variationIndex, expectedCountAfterDelete) {
    await openVariationsTab(page);
    await expandAllVariations(page);

    // Ensure variation state is saved before deleting, to avoid the
    // "Save changes before changing page?" beforeunload dialog.
    await saveVariationChanges(page);

    const variationRowsBefore = page.locator('.woocommerce_variation');
    const countBefore = await variationRowsBefore.count();
    expect(countBefore).toBeGreaterThan(variationIndex);

    const variationRow = variationRowsBefore.nth(variationIndex);
    await variationRow.scrollIntoViewIfNeeded();

    const removeVariationLink = variationRow.locator('a.remove_variation').first();
    await removeVariationLink.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });

    let sawUnexpectedBeforeUnload = false;
    const onDialog = async (dialog) => {
      const message = dialog.message();
      console.log(`📢 Delete variation dialog: ${message}`);

      if (message.toLowerCase().includes('save changes before changing page')) {
        sawUnexpectedBeforeUnload = true;
        await dialog.dismiss();
        return;
      }

      await dialog.accept();
    };

    page.on('dialog', onDialog);

    try {
      for (let attempt = 1; attempt <= 3; attempt++) {
        sawUnexpectedBeforeUnload = false;
        await waitForWooVariationOverlayToClear(page);
        await removeVariationLink.click().catch(async () => {
          await removeVariationLink.click({ force: true });
        });

        const removedInUi = await page.waitForFunction(
          (beforeCount, index) => {
            const rows = document.querySelectorAll('.woocommerce_variation');
            if (rows.length < beforeCount) return true;
            const target = rows[index];
            if (!target) return true;

            const classes = String(target.className || '');
            return classes.includes('to-remove') || classes.includes('removed') || target.getAttribute('style')?.includes('display: none');
          },
          countBefore,
          variationIndex,
          { timeout: TIMEOUTS.LONG }
        ).then(() => true).catch(() => false);

        if (removedInUi) {
          break;
        }

        if (sawUnexpectedBeforeUnload) {
          await saveVariationChanges(page);
        }

        if (attempt === 3) {
          throw new Error('Failed to mark variation for deletion in UI after 3 attempts');
        }
      }
    } finally {
      page.off('dialog', onDialog);
    }

    await saveVariationChanges(page);
    await waitForVariationCount(page, expectedCountAfterDelete);

    const currentCount = await page.locator('.woocommerce_variation').count();
    expect(currentCount).toBe(expectedCountAfterDelete);
    console.log(`✅ Deleted one variation, ${expectedCountAfterDelete} variations remain in UI`);
  }

  async function regenerateAllVariations(page, expectedCount) {
    await openVariationsTab(page);

    const generateVariationsButton = page.locator('button.generate_variations');
    await generateVariationsButton.waitFor({ state: 'visible', timeout: TIMEOUTS.LONG });

    let sawUnexpectedBeforeUnload = false;
    const onDialog = async (dialog) => {
      const message = dialog.message();
      console.log(`📢 Regenerate variations dialog: ${message}`);

      if (message.toLowerCase().includes('save changes before changing page')) {
        sawUnexpectedBeforeUnload = true;
        await dialog.dismiss();
        return;
      }

      await dialog.accept();
    };

    page.on('dialog', onDialog);

    try {
      for (let attempt = 1; attempt <= 3; attempt++) {
        sawUnexpectedBeforeUnload = false;
        await waitForWooVariationOverlayToClear(page);
        await generateVariationsButton.click().catch(async () => {
          await generateVariationsButton.click({ force: true });
        });

        if (sawUnexpectedBeforeUnload) {
          await saveVariationChanges(page);
          if (attempt < 3) {
            continue;
          }
        }

        const regenerated = await page.waitForFunction(
          (count) => document.querySelectorAll('.woocommerce_variation').length === count,
          expectedCount,
          { timeout: TIMEOUTS.EXTRA_LONG }
        ).then(() => true).catch(() => false);

        if (regenerated) {
          console.log(`✅ Regenerated variations back to ${expectedCount}`);
          return;
        }

        if (attempt === 3) {
          throw new Error(`Failed to regenerate variations back to ${expectedCount}`);
        }
      }
    } finally {
      page.off('dialog', onDialog);
    }
  }

  test('creates a variable product with multiple attributes and preserves variation-level mapping after sync', async ({ page }, testInfo) => {
    let productId = null;

    try {
      const created = await createPublishedVariableProduct(page);
      productId = created.productId;

      await goToProductEditPage(page, productId);
      await verifyVariationInputs(page, created.variationDefinitions);

      logTestEnd(testInfo, true);
    } catch (error) {
      await safeScreenshot(page, 'variable-product-depth-create-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('syncs changes after editing only one variation', async ({ page }, testInfo) => {
    let productId = null;

    try {
      const created = await createPublishedVariableProduct(page);
      productId = created.productId;

      const beforeMap = mapWooDataByRetailerId(created.syncResult);
      const originalTarget = created.syncResult.raw_data.woo_data.find(
        item => normalizePrice(item.price) === created.variationDefinitions[0].price
      );

      expect(originalTarget).toBeTruthy();

      const updatedPrice = '88.88';
      const updatedStock = '42';

      await goToProductEditPage(page, productId);
      await editSingleVariation(page, 0, updatedPrice, updatedStock);
      await publishProduct(page);
      await checkForPhpErrors(page);

      const syncResultAfterEdit = await validateVariableProductSync(productId, created.productName);
      assertVariationAttributeMapping(syncResultAfterEdit, created.expectedVariationCount);

      const afterMap = mapWooDataByRetailerId(syncResultAfterEdit);
      expect(afterMap.get(originalTarget.retailer_id)).toBeTruthy();
      expect(normalizePrice(afterMap.get(originalTarget.retailer_id).price)).toBe(updatedPrice);

      for (const [retailerId, beforeItem] of beforeMap.entries()) {
        const afterItem = afterMap.get(retailerId);
        expect(afterItem).toBeTruthy();

        if (retailerId === originalTarget.retailer_id) {
          continue;
        }

        expect(normalizePrice(afterItem.price)).toBe(normalizePrice(beforeItem.price));
      }

      await goToProductEditPage(page, productId);
      await openVariationsTab(page);
      await expandAllVariations(page);
      const firstVariationRow = page.locator('.woocommerce_variation').first();
      await expect(firstVariationRow.locator('input[name*="variable_regular_price"]').first()).toHaveValue(updatedPrice);
      await expect(firstVariationRow.locator('input[name*="variable_stock"]').first()).toHaveValue(updatedStock);

      logTestEnd(testInfo, true);
    } catch (error) {
      await safeScreenshot(page, 'variable-product-depth-single-edit-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('applies native WooCommerce bulk price edits to every variation without skipping any', async ({ page }, testInfo) => {
    let productId = null;

    try {
      const created = await createPublishedVariableProduct(page);
      productId = created.productId;

      const bulkPrice = '99.99';

      await goToProductEditPage(page, productId);
      await bulkSetRegularPrice(page, bulkPrice);
      await publishProduct(page);
      await checkForPhpErrors(page);

      await goToProductEditPage(page, productId);
      await verifyVariationInputs(page, created.variationDefinitions, { expectedPriceForAll: bulkPrice });

      const syncResultAfterBulkEdit = await validateVariableProductSync(productId, created.productName, {
        attempts: 4,
      });
      assertVariationAttributeMapping(syncResultAfterBulkEdit, created.expectedVariationCount);

      const wooPrices = (syncResultAfterBulkEdit.raw_data.woo_data || []).map(item => normalizePrice(item.price));
      const facebookPrices = (syncResultAfterBulkEdit.raw_data.facebook_data || []).map(item => normalizePrice(item.price));

      expect(new Set(wooPrices)).toEqual(new Set([bulkPrice]));
      expect(new Set(facebookPrices)).toEqual(new Set([bulkPrice]));

      logTestEnd(testInfo, true);
    } catch (error) {
      await safeScreenshot(page, 'variable-product-depth-bulk-edit-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('syncs deletion and regeneration of variations after the initial sync', async ({ page }, testInfo) => {
    let productId = null;

    try {
      const created = await createPublishedVariableProduct(page);
      productId = created.productId;

      await goToProductEditPage(page, productId);
      await deleteVariation(page, 0, created.expectedVariationCount - 1);
      await publishProduct(page);
      await checkForPhpErrors(page);

      const syncResultAfterDelete = await validateVariableProductSync(productId, created.productName);
      assertVariationAttributeMapping(syncResultAfterDelete, created.expectedVariationCount - 1);
      expect(syncResultAfterDelete.raw_data.woo_data).toHaveLength(created.expectedVariationCount - 1);

      await goToProductEditPage(page, productId);
      await regenerateAllVariations(page, created.expectedVariationCount);
      await bulkSetRegularPrice(page, '77.77');
      await publishProduct(page);
      await checkForPhpErrors(page);

      const syncResultAfterRegeneration = await validateVariableProductSync(productId, created.productName, {
        attempts: 4,
        waitBetweenAttemptsMs: TIMEOUTS.EXTRA_LONG,
      });
      assertVariationAttributeMapping(syncResultAfterRegeneration, created.expectedVariationCount);
      expect(syncResultAfterRegeneration.raw_data.woo_data).toHaveLength(created.expectedVariationCount);

      logTestEnd(testInfo, true);
    } catch (error) {
      await safeScreenshot(page, 'variable-product-depth-delete-regenerate-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('Add a new attribute to an already-synced variable product and verify catalog updates', async ({ page }, testInfo) => {
    let productId = null;

    try {
      // 1. Create and sync a Color-only variable product (the programmatic default:
      //    a "Color" attribute with Red/Blue/Green -> 3 variations, no size).
      const product = await createTestProduct({ productType: 'variable', price: '42.00' });
      productId = product.productId;
      console.log(`✅ Created variable product ${productId}: "${product.productName}"`);

      const baseline = await validateVariableProductSync(productId, product.productName, { attempts: 3 });
      expect(baseline.raw_data.woo_data).toHaveLength(3);
      for (const wooItem of baseline.raw_data.woo_data) {
        // No size attribute exists yet, so every catalog variation's size is empty.
        expect(normalizeText(wooItem.size)).toBe('');
      }
      console.log('✅ Baseline synced: 3 Color-only variations, no size attribute');

      // 2. Add a NEW "Size" attribute to the already-synced product and rebuild its
      //    variations as the Color x Size cartesian product (Red/Blue x Small/Large = 4),
      //    each with a stable SKU so retailer IDs are deterministic for matching.
      //    Done programmatically: the WooCommerce variations metabox is dialog/blockUI
      //    heavy and flaky to drive via the UI for a "confirm catalog reflects it" check.
      const addAttrResult = await execWP(`
        $parent_id = ${Number(productId)};
        $product = wc_get_product($parent_id);
        if ( ! $product || ! $product->is_type('variable') ) {
          echo json_encode(['ok' => false, 'reason' => 'bad_parent']);
          return;
        }

        // Append a new variation-defining "Size" attribute, preserving existing ones.
        $attributes = $product->get_attributes();
        $size = new WC_Product_Attribute();
        $size->set_name('Size'); // label contains "size" -> maps to the catalog size field
        $size->set_options(['Small', 'Large']);
        $size->set_visible(true);
        $size->set_variation(true);
        $attributes['size'] = $size;
        $product->set_attributes($attributes);
        $product->save();

        // Remove the old Color-only variations and rebuild as Color x Size so every
        // variation is fully specified (avoids "Any Size" variations with empty values).
        foreach ($product->get_children() as $old_vid) {
          wp_delete_post($old_vid, true);
        }

        $created = [];
        $price = 42;
        foreach (['Red', 'Blue'] as $color) {
          foreach (['Small', 'Large'] as $size_value) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent_id);
            $variation->set_attributes(['color' => $color, 'size' => $size_value]);
            $variation->set_regular_price((string) $price);
            $variation->set_price((string) $price);
            $variation->set_sku('e2e-' . strtolower($color) . '-' . strtolower($size_value) . '-' . $parent_id);
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity(10);
            $variation->set_stock_status('instock');
            $created[] = $variation->save();
            $price++;
          }
        }
        $product = wc_get_product($parent_id);
        $product->save();

        echo json_encode(['ok' => true, 'count' => count($created)]);
      `);

      const addAttr = JSON.parse(addAttrResult.stdout);
      expect(addAttr.ok, `Failed to add Size attribute: ${addAttrResult.stdout}`).toBe(true);
      expect(addAttr.count).toBe(4);
      console.log('✅ Added Size attribute and rebuilt 4 Color x Size variations');

      // 3. Re-sync and assert the catalog reflects the new attribute: 4 variations,
      //    each carrying its size (matched to Woo by retailer_id) with color preserved.
      const after = await validateVariableProductSync(productId, product.productName, { attempts: 4 });
      assertVariationAttributeMapping(after, 4);
      expect(after.raw_data.woo_data).toHaveLength(4);

      for (const fbItem of after.raw_data.facebook_data.filter(item => item && item.found !== false)) {
        expect(normalizeText(fbItem.size), 'New Size attribute should be populated on every synced variation').not.toBe('');
      }
      const syncedSizes = new Set(after.raw_data.facebook_data.map(item => normalizeText(item.size)));
      expect(syncedSizes.has('small') && syncedSizes.has('large')).toBe(true);
      console.log('✅ New Size attribute propagated to all 4 catalog variations');

      logTestEnd(testInfo, true);
    } catch (error) {
      await safeScreenshot(page, 'add-attribute-to-synced-variable-product-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });
});
