/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

const { test, expect } = require('@playwright/test');
const {
  TIMEOUTS,
  safeScreenshot,
  cleanupProduct,
  extractProductIdFromUrl,
  checkForPhpErrors,
  logTestStart,
  logTestEnd,
  validateFacebookSync,
  createTestProduct,
  setProductDescription,
  filterProducts,
  clickFirstProduct,
  publishProduct,
  openFacebookOptions,
  setProductTitle
} = require('./helpers/js');

test.describe('Meta for WooCommerce - Product Modification E2E Tests', () => {

  test.beforeEach(async ({ page }, testInfo) => {
    // Log test start first for proper chronological order
    logTestStart(testInfo);

    // Ensure browser stability
    await page.setViewportSize({ width: 1280, height: 720 });
  });

  async function validateFacebookSyncWithRetries(page, {
    productId,
    productName,
    waitSeconds = 10,
    maxRetries = 6,
    attempts = 3,
    accept = (result) => !!result?.success,
  }) {
    let lastResult = null;

    for (let attempt = 1; attempt <= attempts; attempt++) {
      const result = await validateFacebookSync(productId, productName, waitSeconds, maxRetries);
      lastResult = result;

      if (accept(result)) {
        return result;
      }

      if (attempt < attempts) {
        const syncStatus = result?.sync_status || 'unknown';
        console.warn(`⚠️ Sync validation attempt ${attempt}/${attempts} for product ${productId} returned ${syncStatus}. Retrying...`);
        await page.waitForTimeout(TIMEOUTS.NORMAL + TIMEOUTS.SHORT);
      }
    }

    return lastResult;
  }

  test('Edit simple product and verify Facebook sync', async ({ page }, testInfo) => {
    let productId = null;
    let createdProductId = null;
    let originalName = '';
    let originalPrice = '';
    let originalStock = '';

    try {
      const createdProduct = await createTestProduct({
        type: 'simple',
        price: '25.00',
        stock: '20'
      });

      createdProductId = createdProduct.productId;
      originalName = createdProduct.productName;
      originalPrice = createdProduct.price;
      originalStock = createdProduct.stock;
      console.log(`✅ Created product ID ${createdProductId} for editing test`);

      await filterProducts(page, 'simple', createdProduct.sku);
      await clickFirstProduct(page);

      // Extract product ID from URL
      const currentUrl = page.url();
      productId = extractProductIdFromUrl(currentUrl);

      if (productId !== createdProductId) {
        console.warn(`⚠️ Selected Product ID from URL: (${productId}) does not match created test product ID: (${createdProductId}). This could indicate failure to cleanup previous test run.`);
      }

      console.log(`✅ Editing product ID: ${productId}`);

      // Edit product attributes
      console.log('✏️ Editing product attributes...');

      // Generate unique values for editing
      const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
      const newTitle = `${originalName} - EDITED ${timestamp}`;
      const newPrice = (parseFloat(originalPrice || '10.00') + 5).toFixed(2);
      const newStock = (parseInt(originalStock || '10', 10) + 5).toString();
      const newDescription = `This is a test product with a description updated on: ${timestamp}`;

      // Edit title and description
      await setProductTitle(page, newTitle);
      await setProductDescription(page, newDescription);

      // Edit price
      await page.click('li.general_tab a');
      const regularPriceField = page.locator('#_regular_price');
      await regularPriceField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await regularPriceField.scrollIntoViewIfNeeded();
      await regularPriceField.fill(newPrice);
      console.log(`✅ Updated price to: ${newPrice}`);

      // Edit stock quantity
      await page.click('li.inventory_tab a');
      const stockField = page.locator('#_stock');
      const trackStockCheckBox = page.locator('#_manage_stock');
      await trackStockCheckBox.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      if (!(await trackStockCheckBox.isChecked())) {
        await trackStockCheckBox.check();
      }
      await stockField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await stockField.scrollIntoViewIfNeeded();
      await stockField.fill(newStock);
      console.log(`✅ Updated stock to: ${newStock}`);

      // Click Update button
      await publishProduct(page);

      // Verify no PHP errors after update
      await checkForPhpErrors(page);
      console.log('✅ No PHP errors detected after update');

      // Validate Facebook sync after editing
      console.log('🔄 Validating Facebook sync after edit...');
      const result = await validateFacebookSyncWithRetries(page, {
        productId,
        productName: newTitle,
        waitSeconds: 10,
        maxRetries: 8,
        attempts: 3,
      });
      expect(result['success']).toBe(true);

      // Verify the changes were saved
      console.log('🔍 Verifying changes were saved...');
      await page.reload({ waitUntil: 'domcontentloaded', timeout: TIMEOUTS.MAX });

      const titleField = page.locator('#title');
      titleField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      const updatedTitle = await titleField.inputValue();
      expect(updatedTitle).toBe(newTitle);
      console.log('✅ Title change verified');

      const updatedPrice = await regularPriceField.inputValue();
      expect(updatedPrice).toBe(newPrice);
      console.log('✅ Price change verified');

      const updatedStock = await stockField.inputValue();
      expect(updatedStock).toBe(newStock);
      console.log('✅ Stock change verified');

      console.log('✅ Simple product edit test completed successfully');
      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`❌ Simple product edit test failed: ${error.message}`);
      await safeScreenshot(page, 'simple-product-edit-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      // Cleanup created product if we created one for this test
      if (createdProductId) {
        await cleanupProduct(createdProductId);
      }
    }
  });

  test('Quick Edit simple product price and verify Facebook sync', async ({ page }, testInfo) => {
    let createdProductId = null;
    let originalPrice = '50.00';
    let newPrice = '75.00';

    try {
      // Step 1: Create test product with initial price
      console.log('🔧 Creating test product for Quick Edit test...');
      const createdProduct = await createTestProduct({
        type: 'simple',
        price: originalPrice,
        stock: '100'
      });

      createdProductId = createdProduct.productId;
      console.log(`✅ Created product ID ${createdProductId} with price $${originalPrice}`);

      // Step 2: Navigate to Products page
      // Step 3: Filter by Simple product type
      // Step 4: Wait for products table to load
      await filterProducts(page, 'simple', createdProduct.sku);

      // Step 5: Find the product row and trigger quick edit
      console.log('🔍 Looking for test product...');
      const productRow = page.locator('.wp-list-table tbody tr.iedit').first();
      await productRow.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });

      const productNameElement = productRow.locator('.row-title');
      const productName = await productNameElement.textContent();
      console.log(`✅ Found product: "${productName}"`);

      // Step 6: Trigger Quick Edit
      console.log('📝 Triggering Quick Edit...');
      await productRow.hover();

      // Click the Quick Edit button
      const quickEditLink = productRow.locator('.row-actions .editinline');
      await quickEditLink.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await quickEditLink.click();
      console.log('✅ Clicked Quick Edit link');

      // Step 7: Wait for Quick Edit form to appear
      console.log('⏳ Waiting for Quick Edit form...');
      const quickEditRow = page.locator('.inline-edit-row').first();
      await quickEditRow.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      console.log('✅ Quick Edit form appeared');

      // Step 8: Update the price
      console.log(`💰 Updating price from $${originalPrice} to $${newPrice}...`);
      const regularPriceField = quickEditRow.locator('input[name="_regular_price"]');
      await regularPriceField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });

      // Clear the field and enter new price
      await regularPriceField.clear();
      await regularPriceField.fill(newPrice);
      console.log(`✅ Entered new price: $${newPrice}`);

      // Step 9: Save changes by clicking Update button
      console.log('💾 Saving changes...');
      const updateButton = quickEditRow.locator('.inline-edit-save .button-primary');
      await updateButton.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await updateButton.click();
      console.log('✅ Clicked Update button');

      // Step 10: Wait for the inline editor to close
      console.log('⏳ Waiting for Quick Edit form to close...');
      await quickEditRow.waitFor({ state: 'hidden', timeout: TIMEOUTS.LONG });
      console.log('✅ Quick Edit form closed');

      // Wait a moment for the table row to update
      await page.waitForTimeout(TIMEOUTS.NORMAL);

      // Step 11: Verify price change in UI
      console.log('🔍 Verifying price change in products table...');
      // Reload the page to ensure we see the updated data
      await page.reload({ waitUntil: 'domcontentloaded', timeout: TIMEOUTS.MAX });

      // Find the product row again and check the price column
      const updatedProductRow = page.locator('.wp-list-table tbody tr.iedit').first();
      const priceColumn = updatedProductRow.locator('.price');

      if (await priceColumn.isVisible({ timeout: TIMEOUTS.MEDIUM })) {
        const displayedPrice = await priceColumn.textContent();
        console.log(`✅ Price column shows: ${displayedPrice}`);
        // The price might be formatted with currency symbol, so we just check it contains our new price
        expect(displayedPrice).toContain(newPrice);
        console.log('✅ Price change verified in UI');
      } else {
        console.log('⚠️ Price column not visible, skipping UI verification');
      }

      // Step 12: Validate Facebook sync and verify price was updated
      console.log('🔄 Validating Facebook sync after Quick Edit...');
      const result = await validateFacebookSyncWithRetries(page, {
        productId: createdProductId,
        productName: createdProduct.productName,
        waitSeconds: 20,
        maxRetries: 10,
        attempts: 5,
      });

      // Verify the price field specifically - should have NO mismatches for price
      const priceMismatches = Object.values(result['mismatches'] || {}).filter(
        mismatch => mismatch.field === 'price'
      );

      if (priceMismatches.length > 0) {
        const mismatch = priceMismatches[0];
        console.error(`❌ Price mismatch detected!`);
        console.error(`   WooCommerce price: ${mismatch.woocommerce_value}`);
        console.error(`   Facebook price: ${mismatch.facebook_value}`);
        throw new Error(`Price not synced correctly to Facebook. Expected ${newPrice} but Facebook has ${mismatch.facebook_value}`);
      }

      // Check overall sync success
      expect(result['success']).toBe(true);
      console.log('✅ Facebook sync validated successfully');

      console.log(`📊 Facebook price after Quick Edit: $${newPrice}`);
      console.log(`✅ Price change ($${originalPrice} → $${newPrice}) successfully synced to Facebook`);

      // Step 13: Check for PHP errors
      await checkForPhpErrors(page);
      console.log('✅ No PHP errors detected');

      console.log('✅ Quick Edit product price test completed successfully');
      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`❌ Quick Edit product price test failed: ${error.message}`);
      await safeScreenshot(page, 'quick-edit-price-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      // Step 14: Cleanup
      if (createdProductId) {
        await cleanupProduct(createdProductId);
      }
    }
  });

  test('Edit variable product and verify Facebook Sync', async ({ page }, testInfo) => {
    let productId = null;
    let createdProductId = null;
    let originalName = '';

    try {
      const createdProduct = await createTestProduct({
        productType: 'variable',
        price: '103.00'
      });

      createdProductId = createdProduct.productId;
      originalName = createdProduct.productName;
      console.log(`✅ Created variable product ID ${createdProductId} for editing test`);

      await filterProducts(page, 'variable', createdProduct.sku);
      await clickFirstProduct(page);

      // Extract product ID from URL
      const currentUrl = page.url();
      productId = extractProductIdFromUrl(currentUrl);

      if (productId !== createdProductId) {
        console.warn(`⚠️ Selected Product ID from URL: (${productId}) does not match created test product ID: (${createdProductId}). This could indicate failure to cleanup previous test run.`);
      }

      console.log(`✅ Editing variable product ID: ${productId}`);

      // Edit product attributes
      console.log('✏️ Editing variable product attributes...');

      // Generate unique values for editing
      const timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
      const newTitle = `${originalName} - EDITED ${timestamp}`;
      const newPrice = (parseFloat('103.00') + 5).toFixed(2);

      // Edit title
      await setProductTitle(page, newTitle);

      // Click on Variations tab
      console.log('📝 Editing variation prices using bulk actions...');
      const variationsTab = page.locator('li.variations_tab a');
      await variationsTab.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
      await variationsTab.click();
      console.log('✅ Opened Variations tab');

      // Setup popup/prompt handler before selecting the option (popup appears immediately on selection)
      page.on('dialog', async dialog => {
        console.log(`📢 Dialog appeared: ${dialog.message()}`);
        if (dialog.message() === 'Enter a value') {
          await dialog.accept(newPrice);
          console.log(`✅ Entered new price in popup: ${newPrice}`);
        }
        else {
          await dialog.dismiss();
        }
      });

      const expandAllButton = page.getByRole('link', { name: 'Expand' }).first();
      await expandAllButton.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
      await expandAllButton.click();
      console.log('✅ Expanded all variations');

      // Select bulk action "Set regular prices"
      const bulkActionsSelect = page.locator('select.variation_actions');
      await bulkActionsSelect.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
      // Select the option - this triggers the popup immediately
      await bulkActionsSelect.selectOption('variable_regular_price');

      // Wait for dialog to appear and be handled
      await page.waitForTimeout(TIMEOUTS.NORMAL);

      // Click "Save changes" button for variations
      const saveVariationsButton = page.locator('button.save-variation-changes');
      if (await saveVariationsButton.isVisible({ timeout: TIMEOUTS.NORMAL }) && await saveVariationsButton.isEnabled({ timeout: TIMEOUTS.NORMAL })) {
        await saveVariationsButton.click();
        await page.waitForLoadState('domcontentloaded');
        console.log('✅ Clicked "Save changes" for variations');
      }
      else {
        console.warn('⚠️ "Save changes" button maybe disabled, skipping click');
      }

      // Click Update button
      await publishProduct(page);

      // Verify no PHP errors after update
      await checkForPhpErrors(page);
      console.log('✅ No PHP errors detected after update');

      // Validate Facebook sync after editing
      console.log('🔄 Validating Facebook sync after edit...');
      const result = await validateFacebookSyncWithRetries(page, {
        productId,
        productName: newTitle,
        waitSeconds: 30,
        maxRetries: 8,
        attempts: 3,
      });
      expect(result['success']).toBe(true);

      // Verify the changes were saved
      console.log('🔍 Verifying changes were saved...');
      await page.reload({ waitUntil: 'domcontentloaded', timeout: TIMEOUTS.MAX });

      const titleField = page.locator('#title');
      titleField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      const updatedTitle = await titleField.inputValue();
      expect(updatedTitle).toBe(newTitle);
      console.log('✅ Title change verified');

      // Validate that the new price is showing in the UI for all variations
      console.log('🔍 Validating new price for all variations in the UI...');
      // After reload, go to Variations tab again
      await variationsTab.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
      await variationsTab.click();

      // Expand all variations to check their prices
      await expandAllButton.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
      await expandAllButton.click();

      // Get all variation rows
      const variationRows = page.locator('.woocommerce_variation');
      const count = await variationRows.count();
      expect(count).toBe(3);

      for (let i = 0; i < count; i++) {
        const variationRow = variationRows.nth(i);
        const priceField = variationRow.locator('input[name*="variable_regular_price"]');
        await priceField.waitFor({ state: 'visible', timeout: TIMEOUTS.NORMAL });
        const priceValue = await priceField.inputValue();
        expect(priceValue).toBe(newPrice);
        console.log(`✅ Variation ${i + 1} price verified: ${priceValue}`);
      }

      console.log('✅ Variable product edit test completed successfully');
      logTestEnd(testInfo, true);

    } catch (error) {
      console.log(`❌ Variable product edit test failed: ${error.message}`);
      await safeScreenshot(page, 'variable-product-edit-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (createdProductId) {
        await cleanupProduct(createdProductId);
      }
    }
  });

  test('Edit Facebook-specific options for simple product', async ({ page }, testInfo) => {
    let productId = null;

    try {
      // Create a test product programmatically (faster than UI)
      console.log('📦 Creating test simple product...');
      const { productId: createdId, productName, sku } = await createTestProduct({
        productType: 'simple',
        price: '19.99',
        stock: '10'
      });
      productId = createdId;
      console.log(`✅ Created product ${productId} with SKU: ${sku}`);

      await filterProducts(page, 'simple', sku);
      await clickFirstProduct(page);
      await checkForPhpErrors(page);
      await openFacebookOptions(page);
      // Add Facebook Price
      console.log('💰 Adding Facebook price...');
      const fbPrice = '24.99';
      const fbPriceField = page.locator('#fb_product_price, input[name="fb_product_price"]');

      // The Facebook-specific price field is the field under test — its absence is a
      // real regression, not something to warn past. Require it before filling.
      await fbPriceField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await fbPriceField.fill(fbPrice);
      console.log(`✅ Facebook price set: $${fbPrice}`);

      // Set custom Facebook product image
      console.log('🖼️ Setting custom Facebook product image...');

      // Look for "Use custom image" option
      const useCustomImageRadio = page.locator('input[type="radio"][value="custom"], input[name="fb_product_image_source"][value="custom"]');

      await useCustomImageRadio.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await useCustomImageRadio.click();
      console.log('✅ Selected "Use custom image" option');

      // Add custom image URL
      const customImageUrl = 'https://www.facebook.com/images/fb_icon_325x325.png';
      const customImageField = page.locator('#fb_product_image, input[name="fb_product_image"]');

      await customImageField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await customImageField.fill(customImageUrl);
      console.log(`✅ Custom image URL set: ${customImageUrl}`);

      // Click Update button
      console.log('💾 Updating product...');
      await publishProduct(page);

      // Check for any errors on the page
      await checkForPhpErrors(page);
      console.log('✅ No errors detected on page');

      // Validate Facebook sync
      console.log('🔍 Validating Facebook catalog sync...');
      const syncResult = await validateFacebookSyncWithRetries(page, {
        productId,
        productName,
        waitSeconds: 10,
        maxRetries: 8,
        attempts: 3,
      });
      expect(syncResult.success).toBe(true);

      // The Facebook-specific price (24.99) must override the WooCommerce regular
      // price (19.99) in the catalog. Assert the exact synced value — a missing or
      // unchanged price means the Facebook-specific field did not propagate.
      const syncedFbPrice = syncResult['raw_data']['facebook_data'][0]['price'].replace(/[^0-9.]/g, '');
      expect(syncedFbPrice).toBe(fbPrice);
      console.log(`✅ Facebook-specific price propagated to catalog: ${syncedFbPrice}`);

      // Take final screenshot
      await safeScreenshot(page, 'facebook-options-update-success.png');

      // Mark test as successful
      logTestEnd(testInfo, true);

    } catch (error) {
      console.error(`❌ Test failed: ${error.message}`);
      await safeScreenshot(page, 'facebook-options-edit-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('Edit Facebook-specific options for variable product', async ({ page }, testInfo) => {
    let productId = null;
    let originalPrice = '29.99';

    try {
      // Create a test variable product programmatically
      console.log('📦 Creating test variable product...');
      const { productId: createdId, productName, sku } = await createTestProduct({
        productType: 'variable',
        price: originalPrice
      });
      productId = createdId;
      console.log(`✅ Created variable product ${productId} with SKU: ${sku}`);

      await filterProducts(page, 'variable', sku);
      await clickFirstProduct(page);
      await checkForPhpErrors(page);

      // Click on Variations tab first
      console.log('📝 Opening Variations tab...');
      const variationsTab = page.locator('li.variations_tab a');
      await variationsTab.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await variationsTab.click();
      console.log('✅ Opened Variations tab');

      // Expand all variations
      const expandAllButton = page.getByRole('link', { name: 'Expand' }).first();
      await expandAllButton.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await expandAllButton.click();
      console.log('✅ Expanded all variations');

      // Get all variation rows
      const variationRows = page.locator('.woocommerce_variation');
      const variationCount = await variationRows.count();
      console.log(`📊 Found ${variationCount} variations`);

      // Edit Facebook-specific fields for each variation
      for (let i = 0; i < variationCount; i++) {
        const variationRow = variationRows.nth(i);
        console.log(` Editing variation ${i + 1}...`);

        // Scroll variation into view
        await variationRow.scrollIntoViewIfNeeded();

        // Set per-variation Facebook price — the field is under test, so require it.
        const fbPriceField = variationRow.locator(`#variable_fb_product_price${i}`);
        await fbPriceField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
        const newPrice = (parseFloat(originalPrice || '10.00') + (i + 1)).toFixed(2);
        await fbPriceField.fill(newPrice);
        console.log(`  ✅ Set Facebook price: ${newPrice}`);

        // Set Custom Facebook Image
        const customImageRadioBtn = variationRow.getByRole('radio', { name: 'Use custom image' }).first()
        if (await customImageRadioBtn.isVisible({ timeout: TIMEOUTS.MEDIUM })) {
          await customImageRadioBtn.scrollIntoViewIfNeeded();
          await customImageRadioBtn.click();
          const customImageField = variationRow.locator(`#variable_fb_product_image${i}`);
          const customImageUrl = 'https://www.facebook.com/images/fb_icon_325x325.png';
          if (await customImageField.isVisible({ timeout: TIMEOUTS.MEDIUM })) {
            await customImageField.fill(customImageUrl);
            console.log(`  ✅ Set Custom Image for variation ${i + 1}`);
          } else {
            console.warn(`  ⚠️ Custom Image field not found for variation ${i + 1}`);
          }
        } else {
          console.warn(`  ⚠️ Custom Image Radiobutton not found for variation ${i + 1}`);
        }
      }

      // Save variation changes
      console.log('\n💾 Saving variation changes...');
      const saveVariationsButton = page.locator('button.save-variation-changes');
      if (await saveVariationsButton.isVisible({ timeout: TIMEOUTS.MEDIUM }) && await saveVariationsButton.isEnabled({ timeout: TIMEOUTS.MEDIUM })) {
        await saveVariationsButton.click();
        await page.waitForLoadState('domcontentloaded');
        console.log('✅ Saved variation changes');
      } else {
        console.warn('⚠️ "Save changes" button not visible or enabled, skipping');
      }

      await openFacebookOptions(page);
      console.log('✅ Updating Global Facebook Options brand, size');
      const fbBrandField = page.locator('#fb_brand');
      await fbBrandField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await fbBrandField.fill('FBOptionsUpdateTestBrand');

      const fbSizeField = page.locator('#fb_size');
      await fbSizeField.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await fbSizeField.fill('XXXL');

      // Click Update button for the product
      await publishProduct(page);

      // Check for any errors on the page
      await checkForPhpErrors(page);
      console.log('✅ No errors detected on page');

      // Validate Facebook sync
      console.log('🔍 Validating Facebook catalog sync...');
      const syncResult = await validateFacebookSyncWithRetries(page, {
        productId,
        productName,
        waitSeconds: 30,
        maxRetries: 8,
        attempts: 3,
      });
      expect(syncResult.success).toBe(true);

      // The Facebook-specific brand set above must propagate to the catalog. Assert
      // the exact value on the first synced variation — a warn-and-continue here
      // previously let a broken brand-sync pass silently.
      expect(syncResult['raw_data']['facebook_data'][0]['brand']).toBe('FBOptionsUpdateTestBrand');
      console.log('✅ Facebook-specific brand propagated to catalog');

      // Take final screenshot
      await safeScreenshot(page, 'facebook-options-variable-update-success.png');
      // Mark test as successful
      console.log('✅ Variable product Facebook options edit test completed successfully');
      logTestEnd(testInfo, true);

    } catch (error) {
      console.error(`❌ Test failed: ${error.message}`);
      await safeScreenshot(page, 'facebook-options-variable-edit-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

  test('Mark product as Out of stock and verify Facebook sync', async ({ page }, testInfo) => {
    let productId = null;

    try {
      // Create a test product programmatically
      console.log('📦 Creating test simple product...');
      const { productId: createdId, productName, sku } = await createTestProduct({
        productType: 'simple',
        price: '15.00',
        stock: '50'
      });
      productId = createdId;
      console.log(`✅ Created product ${productId} with SKU: ${sku}`);

      // Find and click on the created product
      console.log(`🔍 Looking for product with SKU: ${sku}...`);
      await filterProducts(page, 'simple', sku);
      await clickFirstProduct(page);

      const currentUrl = page.url();
      const urlProductId = extractProductIdFromUrl(currentUrl);
      console.log(`✅ Editing product ID: ${urlProductId}`);

      // Click on Inventory tab
      console.log('📦 Opening Inventory tab...');
      const inventoryTab = page.locator('li.inventory_tab a');
      await inventoryTab.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await inventoryTab.click();
      console.log('✅ Opened Inventory tab');

      // Set stock status to "Out of stock"
      console.log('📝 Setting stock status to "Out of stock"...');
      const trackStockCheckBox = page.locator('#_manage_stock');
      if (await trackStockCheckBox.isVisible({ timeout: TIMEOUTS.NORMAL })) {
        if ((await trackStockCheckBox.isChecked())) {
          await trackStockCheckBox.uncheck();
        }
      }
      const stockStatusSelect = page.locator('input[name="_stock_status"][value="outofstock"]')
      await stockStatusSelect.waitFor({ state: 'visible', timeout: TIMEOUTS.MEDIUM });
      await stockStatusSelect.scrollIntoViewIfNeeded();
      await stockStatusSelect.click();
      console.log('✅ Set stock status to "Out of stock"');

      // Click Update button
      console.log('💾 Updating product...');
      await publishProduct(page);

      // Verify no PHP errors after update
      await checkForPhpErrors(page);
      console.log('✅ No PHP errors detected after update');

      // Validate Facebook sync
      console.log('🔄 Validating Facebook sync after stock status change...');
      const syncResult = await validateFacebookSyncWithRetries(page, {
        productId,
        productName,
        waitSeconds: 20,
        maxRetries: 10,
        attempts: 5,
      });
      expect(syncResult.success).toBe(true);
      expect(syncResult['raw_data']['facebook_data'][0]['availability']).toBe('out of stock');
      console.log('✅ Facebook sync validated successfully');

      console.log('✅ Mark product as Out of stock test completed successfully');
      logTestEnd(testInfo, true);

    } catch (error) {
      console.error(`❌ Test failed: ${error.message}`);
      await safeScreenshot(page, 'product-out-of-stock-test-failure.png');
      logTestEnd(testInfo, false);
      throw error;
    } finally {
      if (productId) {
        await cleanupProduct(productId);
      }
    }
  });

});
