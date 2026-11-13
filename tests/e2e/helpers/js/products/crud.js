/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Product CRUD helpers for E2E tests
 */

const { exec, execSync } = require('child_process');
const { promisify } = require('util');
const path = require('path');

const execAsync = promisify(exec);
const { execWP } = require('../wordpress/exec');
const {
  getProductCatalogRequestIds,
  processPendingSyncJobs,
} = require('../plugin/sync');

function shellEscape(value) {
  return `'${String(value).replace(/'/g, `'"'"'`)}'`;
}

function varToPhpString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function parseJsonFromOutput(stdout) {
  const trimmed = (stdout || '').trim();
  if (!trimmed) {
    throw new Error('Empty product creator output');
  }

  const firstBrace = trimmed.indexOf('{');
  const lastBrace = trimmed.lastIndexOf('}');
  if (firstBrace === -1 || lastBrace === -1 || lastBrace < firstBrace) {
    throw new Error(`No JSON object found in product creator output: ${trimmed.slice(0, 240)}`);
  }

  return JSON.parse(trimmed.slice(firstBrace, lastBrace + 1));
}

function buildProductCreatorCommand(productType, productName, price, stock, sku, categoryIds) {
  const wordpressPath = process.env.WORDPRESS_PATH;
  if (!wordpressPath) {
    throw new Error('WORDPRESS_PATH is required for product creator');
  }

  const phpBin = process.env.PHP_BIN || 'php';
  const usePhpNoIni = process.env.USE_PHP_NO_INI === '1';
  const wpCliPath = process.env.WP_CLI_PATH || execSync('command -v wp', { encoding: 'utf8' }).trim();
  const creatorFile = path.resolve(__dirname, '../../php/product-creator.php');
  const categoryIdsJson = JSON.stringify(categoryIds || []);

  const phpSnippet = [
    "define('E2E_PRODUCT_CREATOR_SKIP_MAIN', true);",
    `require ${varToPhpString(creatorFile)};`,
    `\$productType = ${varToPhpString(productType)};`,
    `\$name = ${varToPhpString(productName)};`,
    `\$price = ${Number(price)};`,
    `\$stock = ${Number(stock)};`,
    `\$sku = ${varToPhpString(sku)};`,
    `\$categoryIds = json_decode(${varToPhpString(categoryIdsJson)}, true) ?: [];`,
    `if (\$productType === 'simple') { \$result = E2EProductCreator::createSimpleProduct(\$name, \$sku, \$price, \$stock, \$categoryIds); }`,
    `elseif (\$productType === 'variable') { \$result = E2EProductCreator::createVariableProduct(\$name, \$sku, \$price); }`,
    `else { \$result = ['success' => false, 'error' => 'Unsupported product type: ' . \$productType . ". Use 'simple' or 'variable'."]; }`,
    'echo json_encode(\$result);',
  ].join(' ');

  const phpNoIniFlag = usePhpNoIni ? '-n ' : '';
  return `${phpBin} ${phpNoIniFlag}${shellEscape(wpCliPath)} eval ${shellEscape(phpSnippet)} --path=${shellEscape(wordpressPath)} --allow-root`;
}

/**
 * Generate a product name with timestamp and instance ID
 * @param {string} productType - Type of product
 * @returns {string} Generated product name
 */
function generateProductName(productType) {
  const now = new Date();
  const timestamp = now.toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const runId = process.env.GITHUB_RUN_ID || 'local';
  return `Test ${productType.toUpperCase()} Product E2E ${timestamp}-${runId}`;
}

/**
 * Generate unique SKU for any product type
 * @param {string} productType - Type of product
 * @returns {string} Generated SKU
 */
function generateUniqueSKU(productType) {
  const runId = process.env.GITHUB_RUN_ID || 'local';
  const randomSuffix = Math.random().toString(36).substring(2, 8);
  return `E2E-${productType.toUpperCase()}-${runId}-${randomSuffix}`;
}

/**
 * Extract product ID from URL
 * @param {string} url - URL to parse
 * @returns {number|null} Product ID
 */
function extractProductIdFromUrl(url) {
  console.log(`🔍 Extracting Product ID from URL: ${url}`);
  const urlMatch = url.match(/post=(\d+)/);
  const productId = urlMatch ? parseInt(urlMatch[1]) : null;
  console.log(`✅ Extracted Product ID: ${productId}`);
  return productId;
}

/**
 * Cleanup/delete a product from WooCommerce
 * @param {number} productId - Product ID to delete
 */
async function cleanupProduct(productId) {
  if (!productId) return;

  console.log(`🧹 Cleaning up product ${productId}...`);
  let requestIds = [];

  try {
    requestIds = await getProductCatalogRequestIds(productId, 'DELETE');
  } catch (error) {
    console.log(`⚠️ Could not resolve catalog deletion IDs for product ${productId}: ${error.message}`);
  }

  try {
    const startTime = new Date();
    await execWP(`wp_delete_post(${productId}, true);`);
    const syncResult = await processPendingSyncJobs({
      waitForBatchCompletion: true,
      requestIds,
      actions: ['DELETE'],
    });
    if (!syncResult.success) {
      throw new Error(syncResult.error || 'Meta product deletion did not complete');
    }
    const endTime = new Date();
    console.log(`⏱️ Cleanup took ${endTime - startTime}ms`);
    console.log(`✅ Product ${productId} deleted from WooCommerce`);
  } catch (error) {
    console.log(`⚠️ Cleanup failed: ${error.message}`);
  }
}

/**
 * Batch cleanup/delete multiple products in a single PHP process
 * @param {number[]} productIds - Array of product IDs to delete
 */
async function cleanupProducts(productIds) {
  if (!productIds || productIds.length === 0) return;

  const validIds = productIds.filter(id => id);
  if (validIds.length === 0) return;

  console.log(`🧹 Batch cleaning up ${validIds.length} products...`);
  let requestIds = [];

  try {
    requestIds = await getProductCatalogRequestIds(validIds, 'DELETE');
  } catch (error) {
    console.log(`⚠️ Could not resolve catalog deletion IDs: ${error.message}`);
  }

  try {
    const startTime = new Date();
    const idsPhp = validIds.join(',');
    await execWP(`foreach ([${idsPhp}] as \\$id) { wp_delete_post(\\$id, true); }`);
    const syncResult = await processPendingSyncJobs({
      waitForBatchCompletion: true,
      requestIds,
      actions: ['DELETE'],
    });
    if (!syncResult.success) {
      throw new Error(syncResult.error || 'Meta product deletions did not complete');
    }
    const endTime = new Date();
    console.log(`⏱️ Batch cleanup took ${endTime - startTime}ms`);
    console.log(`✅ ${validIds.length} products deleted from WooCommerce`);
  } catch (error) {
    console.log(`⚠️ Batch cleanup failed: ${error.message}`);
  }
}

/**
 * Create a test product programmatically via WooCommerce API
 * @param {Object} options - Product options
 * @returns {Promise<Object>} Created product details
 */
async function createTestProduct(options = {}) {
  const productType = options.productType || 'simple';
  const productName = options.productName || generateProductName(productType);
  const sku = options.sku || generateUniqueSKU(productType);
  const price = options.price || '19.99';
  const stock = options.stock || '10';
  const categoryIds = options.categoryIds || [];

  console.log(`📦 Creating "${productType}" product via WooCommerce API: "${productName}"...`);

  try {
    const startTime = Date.now();

    const command = buildProductCreatorCommand(productType, productName, price, stock, sku, categoryIds);
    const { stdout } = await execAsync(command, {
      cwd: __dirname,
      env: process.env,
    });

    const result = parseJsonFromOutput(stdout);

    if (result.success) {
      console.log(`✅ ${result.message}`);
      console.log(`   Name: ${result.product_name}`);
      console.log(`   SKU: ${result.sku}`);

      if (productType === 'simple') {
        console.log(`   Price: ${result.price}`);
        console.log(`   Stock: ${result.stock}`);
      } else {
        console.log(`   Variations: ${result.variation_count}`);
        console.log(`   VariationIds: ${result.variation_ids}`);
      }

      if (categoryIds.length > 0) {
        console.log(`   Categories: ${categoryIds.join(', ')}`);
      }

      const endTime = Date.now();
      console.log(`⏱️ Test Product creation took ${endTime - startTime}ms`);

      return {
        productId: result.product_id,
        productName: result.product_name,
        price: result.price,
        stock: result.stock,
        sku: result.sku
      };
    } else {
      throw new Error(`Product creation failed: ${result.error}`);
    }

  } catch (error) {
    console.log(`❌ Failed to create test product: ${error.message}`);
    if (error?.stderr) {
      console.log(`   STDERR: ${String(error.stderr).trim().slice(0, 2000)}`);
    }
    if (error?.stdout) {
      console.log(`   STDOUT: ${String(error.stdout).trim().slice(0, 2000)}`);
    }
    throw error;
  }
}

module.exports = {
  generateProductName,
  generateUniqueSKU,
  extractProductIdFromUrl,
  cleanupProduct,
  cleanupProducts,
  createTestProduct
};
