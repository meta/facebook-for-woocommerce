/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Facebook sync validation helpers for E2E tests
 */

const { exec, execSync } = require('child_process');
const { promisify } = require('util');
const path = require('path');

const execAsync = promisify(exec);

let connectionPreflightChecked = false;
let pendingSyncDrainPromise = null;

async function ensureFacebookConnectionConfigured() {
  if (connectionPreflightChecked) {
    return;
  }

  const wordpressPath = process.env.WORDPRESS_PATH;
  if (!wordpressPath) {
    throw new Error('WORDPRESS_PATH is required for sync validator preflight');
  }

  const phpBin = process.env.PHP_BIN || 'php';
  const usePhpNoIni = process.env.USE_PHP_NO_INI === '1';
  const wpCliPath = process.env.WP_CLI_PATH || execSync('command -v wp', { encoding: 'utf8' }).trim();
  const phpNoIniFlag = usePhpNoIni ? '-n ' : '';

  const phpSnippet = [
    '$status = [',
    "  'connected' => facebook_for_woocommerce()->get_connection_handler()->is_connected(),",
    "  'access_token' => (bool) get_option('wc_facebook_access_token'),",
    "  'catalog_id' => (bool) get_option('wc_facebook_product_catalog_id'),",
    "  'pixel_id' => (bool) get_option('wc_facebook_pixel_id'),",
    '];',
    'echo json_encode($status);',
  ].join(' ');

  const command = `${phpBin} ${phpNoIniFlag}${shellEscape(wpCliPath)} eval ${shellEscape(phpSnippet)} --path=${shellEscape(wordpressPath)} --allow-root`;
  const { stdout } = await execAsync(command, { env: process.env });
  const status = parseJsonFromOutput(stdout);

  const configured = Boolean(status.connected && status.access_token && status.catalog_id && status.pixel_id);
  if (!configured) {
    throw new Error(
      `Facebook integration preflight failed for sync validation (connected=${Boolean(status.connected)}, access_token=${Boolean(status.access_token)}, catalog_id=${Boolean(status.catalog_id)}, pixel_id=${Boolean(status.pixel_id)}). Reconnect plugin before running sync-dependent tests.`
    );
  }

  connectionPreflightChecked = true;
}

function shellEscape(value) {
  return `'${String(value).replace(/'/g, `'"'"'`)}'`;
}

function parseJsonFromOutput(stdout) {
  const trimmed = (stdout || '').trim();
  if (!trimmed) {
    throw new Error('Empty sync validator output');
  }

  // Some environments may prepend notices/log lines before JSON.
  const firstBrace = trimmed.indexOf('{');
  const lastBrace = trimmed.lastIndexOf('}');
  if (firstBrace === -1 || lastBrace === -1 || lastBrace < firstBrace) {
    throw new Error(`No JSON object found in validator output: ${trimmed.slice(0, 240)}`);
  }

  return JSON.parse(trimmed.slice(firstBrace, lastBrace + 1));
}

function buildValidatorCommand(mode, id, waitSeconds, maxRetries) {
  const wordpressPath = process.env.WORDPRESS_PATH;
  if (!wordpressPath) {
    throw new Error('WORDPRESS_PATH is required for sync validator');
  }

  const phpBin = process.env.PHP_BIN || 'php';
  const usePhpNoIni = process.env.USE_PHP_NO_INI === '1';
  const wpCliPath = process.env.WP_CLI_PATH || execSync('command -v wp', { encoding: 'utf8' }).trim();
  const validatorFile = path.resolve(__dirname, '../../php/sync-validator.php');

  const className = mode === 'category' ? 'CategorySyncValidator' : 'FacebookSyncValidator';
  const phpSnippet = [
    "define('E2E_SYNC_VALIDATOR_SKIP_MAIN', true);",
    `require ${varToPhpString(validatorFile)};`,
    `$v = new ${className}(${Number(id)}, ${Number(waitSeconds)}, ${Number(maxRetries)});`,
    '$v->validate();',
    'echo $v->getJsonResult();',
  ].join(' ');

  const phpNoIniFlag = usePhpNoIni ? '-n ' : '';
  return `${phpBin} ${phpNoIniFlag}${shellEscape(wpCliPath)} eval ${shellEscape(phpSnippet)} --path=${shellEscape(wordpressPath)} --allow-root`;
}

function varToPhpString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

/**
 * Validate Facebook sync for a product
 * @param {number} productId - Product ID to validate
 * @param {string} productName - Product name for display
 * @param {number} waitSeconds - Seconds to wait before validation
 * @param {number} maxRetries - Maximum retry attempts
 * @returns {Promise<Object>} Validation result
 */
async function validateFacebookSync(productId, productName, waitSeconds = 10, maxRetries = 6) {
  if (!productId) {
    console.warn('⚠️ No product ID provided for Facebook sync validation');
    return null;
  }

  const displayName = productName ? `"${productName}" (ID: ${productId})` : `ID: ${productId}`;
  console.log(`🔍 Validating Facebook sync for product ${displayName}...`);

  try {
    await drainPendingSyncJobs();
    await ensureFacebookConnectionConfigured();
    const command = buildValidatorCommand('product', productId, waitSeconds, maxRetries);
    const { stdout } = await execAsync(command, {
      cwd: path.resolve(__dirname, '../../php'),
      env: process.env,
    });

    const result = parseJsonFromOutput(stdout);
    console.log('📄 OUTPUT FROM FACEBOOK SYNC VALIDATOR:');
    const { raw_data, ...resultWithoutRawData } = result;
    console.log(JSON.stringify(resultWithoutRawData, null, 2));

    if (result.success) {
      console.log(`🎉 Facebook Sync Validation Succeeded for ${displayName}:`);
    } else {
      console.warn(`⚠️ Facebook Sync Validation Failed.\nDepending on the test case, this may or may not be an actual error. Check the debug logs above.`);
    }

    return result;

  } catch (error) {
    console.warn(`⚠️ Facebook sync validation error: ${error.message}`);
    return null;
  }
}

/**
 * Validate category sync to Facebook product set
 * @param {number} categoryId - Category ID to validate
 * @param {string} categoryName - Category name for display
 * @param {number} waitSeconds - Seconds to wait before validation
 * @param {number} maxRetries - Maximum retry attempts
 * @returns {Promise<Object>} Validation result
 */
async function validateCategorySync(categoryId, categoryName = null, waitSeconds = 10, maxRetries = 6) {
  if (!categoryId) {
    console.warn('⚠️ No category ID provided for sync validation');
    return null;
  }

  const displayName = categoryName
    ? `"${categoryName}" (ID: ${categoryId})`
    : `ID: ${categoryId}`;
  console.log(`🔍 Validating category sync for ${displayName}...`);

  try {
    await drainPendingSyncJobs();
    await ensureFacebookConnectionConfigured();
    const command = buildValidatorCommand('category', categoryId, waitSeconds, maxRetries);
    const { stdout } = await execAsync(command, {
      cwd: path.resolve(__dirname, '../../php'),
      env: process.env,
    });

    const result = parseJsonFromOutput(stdout);

    console.log('📄 OUTPUT FROM CATEGORY SYNC VALIDATOR:');
    const { debug, raw_data, ...resultWithoutDebug } = result;
    console.log(JSON.stringify(resultWithoutDebug, null, 2));

    if (result.success) {
      console.log(`🎉 Category Sync Validation Succeeded for ${displayName}`);
      console.log(`   Product Set ID: ${result.facebook_product_set_id}`);
      console.log(`   Retailer ID: ${result.retailer_id}`);
    } else {
      console.warn(`⚠️ Category Sync Validation Failed for ${displayName}`);
      if (result.error) {
        console.warn(`   Error: ${result.error}`);
      }
      if (result.mismatches && Object.keys(result.mismatches).length > 0) {
        console.warn(`   Mismatches: ${Object.keys(result.mismatches).length}`);
      }
    }

    return result;

  } catch (error) {
    console.warn(`⚠️ Category sync validation error: ${error.message}`);
    return null;
  }
}

/**
 * Drains pending sync jobs before reading Facebook catalog state.
 *
 * Multiple validators are often started together with Promise.all(). Reuse the
 * same in-flight drain so they do not race while processing the local queue.
 * A completed drain is not cached because later product/category mutations may
 * enqueue new work that must be processed before the next validation.
 *
 * @return {Promise<Object>} Processing result
 */
async function drainPendingSyncJobs() {
  if (!pendingSyncDrainPromise) {
    pendingSyncDrainPromise = processPendingSyncJobs().finally(() => {
      pendingSyncDrainPromise = null;
    });
  }

  return pendingSyncDrainPromise;
}

/**
 * Process pending Facebook sync background jobs directly.
 *
 * The background job handler normally dispatches via a loopback HTTP request
 * to admin-ajax.php, which doesn't work on single-threaded PHP servers (like
 * the built-in dev server used in CI). This function bypasses the loopback by
 * invoking the job handler directly via CLI.
 *
 * @returns {Promise<Object>} Processing result
 */
async function processPendingSyncJobs() {
  console.log('🔄 Processing pending Facebook sync background jobs...');

  try {
    const phpDir = path.resolve(__dirname, '../../php');
    const { stdout } = await execAsync(
      'php process-sync-jobs.php',
      { cwd: phpDir, timeout: 120000 }
    );

    const result = parseJsonFromOutput(stdout);
    if (result.success) {
      console.log(`✅ Processed ${result.jobs_processed} sync job(s)`);
    } else {
      console.warn(`⚠️ Sync job processing issue: ${result.message}`);
    }
    return result;

  } catch (error) {
    console.warn(`⚠️ Sync job processing error: ${error.message}`);
    return { success: false, error: error.message };
  }
}

module.exports = {
  validateFacebookSync,
  processPendingSyncJobs,
  validateCategorySync
};
