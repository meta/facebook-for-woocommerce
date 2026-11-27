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
const { execWP } = require('../wordpress/exec');

const execAsync = promisify(exec);
let syncProcessingQueue = Promise.resolve();
const INITIAL_CATALOG_POLL_SECONDS = 120;
const RETRY_CATALOG_POLL_SECONDS = 180;

let connectionPreflightChecked = false;

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
    '$is_configured = static function ($value) {',
    '  $value = is_scalar($value) ? trim((string) $value) : "";',
    '  return "" !== $value && !in_array(strtolower($value), ["undefined", "null"], true);',
    '};',
    '$status = [',
    "  'connected' => facebook_for_woocommerce()->get_connection_handler()->is_connected(),",
    "  'access_token' => $is_configured(get_option('wc_facebook_access_token')),",
    "  'catalog_id' => $is_configured(get_option('wc_facebook_product_catalog_id')),",
    "  'pixel_id' => $is_configured(get_option('wc_facebook_pixel_id')),",
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

function parseJsonArrayFromOutput(stdout) {
  const trimmed = (stdout || '').trim();
  const firstBracket = trimmed.indexOf('[');
  const lastBracket = trimmed.lastIndexOf(']');

  if (firstBracket === -1 || lastBracket === -1 || lastBracket < firstBracket) {
    throw new Error(`No JSON array found in output: ${trimmed.slice(0, 240)}`);
  }

  return JSON.parse(trimmed.slice(firstBracket, lastBracket + 1));
}

/**
 * Resolve the identifiers stored in product sync job request keys.
 * UPDATE jobs use WooCommerce IDs, while DELETE jobs use retailer IDs.
 * Variable products enqueue one request per variation.
 *
 * @param {number|number[]} productIds WooCommerce product IDs
 * @param {'UPDATE'|'DELETE'} action Catalog action being awaited
 * @returns {Promise<string[]>} Product sync request identifiers
 */
async function getProductCatalogRequestIds(productIds, action = 'UPDATE') {
  const normalizedProductIds = [...new Set((Array.isArray(productIds) ? productIds : [productIds])
    .map(productId => Number.parseInt(productId, 10))
    .filter(productId => Number.isInteger(productId) && productId > 0))];
  const normalizedAction = String(action).toUpperCase();

  if (normalizedProductIds.length === 0) {
    throw new Error('At least one valid WooCommerce product ID is required');
  }

  if (!['UPDATE', 'DELETE'].includes(normalizedAction)) {
    throw new Error(`Unsupported catalog action: ${action}`);
  }

  const { stdout } = await execWP(`
    $request_ids = [];

    foreach ([${normalizedProductIds.join(',')}] as $product_id) {
      $product = wc_get_product($product_id);
      if (! $product instanceof \\WC_Product) {
        continue;
      }

      $products = $product->is_type('variable')
        ? array_filter(array_map('wc_get_product', $product->get_children()))
        : [$product];

      foreach ($products as $candidate) {
        if (! $candidate instanceof \\WC_Product) {
          continue;
        }

        $request_id = 'DELETE' === '${normalizedAction}'
          ? \\WC_Facebookcommerce_Utils::get_fb_retailer_id($candidate)
          : $candidate->get_id();

        if ('' !== (string) $request_id) {
          $request_ids[] = (string) $request_id;
        }
      }
    }

    echo wp_json_encode(array_values(array_unique($request_ids)));
  `);

  const requestIds = parseJsonArrayFromOutput(stdout).map(String).filter(Boolean);
  if (requestIds.length === 0) {
    throw new Error(`Unable to resolve ${normalizedAction} request IDs for product(s): ${normalizedProductIds.join(', ')}`);
  }

  return requestIds;
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

async function runProductSyncValidator(productId, waitSeconds, maxRetries, pollSeconds) {
  const command = buildValidatorCommand('product', productId, waitSeconds, maxRetries);
  const { stdout } = await execAsync(command, {
    cwd: path.resolve(__dirname, '../../php'),
    env: {
      ...process.env,
      FB_E2E_CATALOG_POLL_SECONDS: String(pollSeconds),
    },
  });

  return parseJsonFromOutput(stdout);
}

function getMissingWooProductIds(result) {
  if (result?.error || !result?.raw_data) {
    return [];
  }

  const wooData = Array.isArray(result.raw_data.woo_data) ? result.raw_data.woo_data : [];
  const facebookData = Array.isArray(result.raw_data.facebook_data) ? result.raw_data.facebook_data : [];

  return [...new Set(wooData
    .filter((_, index) => facebookData[index]?.found !== true && !facebookData[index]?.error)
    .map(product => Number.parseInt(product.id, 10))
    .filter(productId => Number.isInteger(productId) && productId > 0))];
}

async function queueProductCatalogUpdates(productIds) {
  const normalizedProductIds = [...new Set(productIds
    .map(productId => Number.parseInt(productId, 10))
    .filter(productId => Number.isInteger(productId) && productId > 0))];

  if (normalizedProductIds.length === 0) {
    return;
  }

  await execWP(`
    facebook_for_woocommerce()
      ->get_products_sync_handler()
      ->create_or_update_products([${normalizedProductIds.join(',')}]);
  `);
}

/**
 * Validate Facebook sync for a product
 * @param {number} productId - Product ID to validate
 * @param {string} productName - Product name for display
 * @param {number} waitSeconds - Seconds to wait before validation
 * @param {number} maxRetries - Maximum retry attempts
 * @param {Object} options - Optional validation controls
 * @param {Array<number|string>} options.requestIds - Pre-resolved catalog request IDs
 * @returns {Promise<Object>} Validation result
 */
async function validateFacebookSync(productId, productName, waitSeconds = 10, maxRetries = 6, options = {}) {
  if (!productId) {
    console.warn('⚠️ No product ID provided for Facebook sync validation');
    return null;
  }

  const displayName = productName ? `"${productName}" (ID: ${productId})` : `ID: ${productId}`;
  console.log(`🔍 Validating Facebook sync for product ${displayName}...`);

  try {
    await ensureFacebookConnectionConfigured();
    const expectedAction = maxRetries === 0 ? 'DELETE' : 'UPDATE';
    const supportsMissingItemRecovery = maxRetries > 1;
    const requestIds = [...new Set((options.requestIds || []).map(String).filter(Boolean))];
    if (requestIds.length > 0) {
      await waitForCatalogRequestIds(requestIds, expectedAction);
    } else {
      await waitForProductCatalogBatches(productId, expectedAction);
    }

    let result = await runProductSyncValidator(
      productId,
      waitSeconds,
      maxRetries,
      supportsMissingItemRecovery ? INITIAL_CATALOG_POLL_SECONDS : 0
    );

    const missingProductIds = supportsMissingItemRecovery ? getMissingWooProductIds(result) : [];
    if (!result.success && missingProductIds.length > 0) {
      console.log(
        `🔁 Meta finished the original batch but did not index WooCommerce ID(s) ` +
        `${missingProductIds.join(', ')}; submitting one bounded recovery batch...`
      );
      await queueProductCatalogUpdates(missingProductIds);
      await waitForProductCatalogBatches(missingProductIds, 'UPDATE');
      result = await runProductSyncValidator(
        productId,
        0,
        maxRetries,
        RETRY_CATALOG_POLL_SECONDS
      );
    }

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
 * Process pending Facebook sync background jobs directly.
 *
 * The background job handler normally dispatches via a loopback HTTP request
 * to admin-ajax.php, which doesn't work on single-threaded PHP servers (like
 * the built-in dev server used in CI). This function bypasses the loopback by
 * invoking the job handler directly via CLI.
 *
 * @param {Object} options - Processing options
 * @param {boolean} options.waitForBatchCompletion - Wait until Meta finishes each submitted batch
 * @param {Array<number|string>} options.requestIds - Sync request IDs whose completed job handles should also be checked
 * @param {Array<string>} options.actions - Completed job actions to match (UPDATE and/or DELETE)
 * @returns {Promise<Object>} Processing result
 */
function processPendingSyncJobs(options = {}) {
  const processing = syncProcessingQueue.then(() => executePendingSyncJobs(options));
  syncProcessingQueue = processing.catch(() => undefined);
  return processing;
}

async function executePendingSyncJobs({ waitForBatchCompletion = false, requestIds = [], actions = ['UPDATE'] } = {}) {
  console.log('🔄 Processing pending Facebook sync background jobs...');

  try {
    const phpDir = path.resolve(__dirname, '../../php');
    const normalizedRequestIds = [...new Set(requestIds.map(String).filter(Boolean))];
    const normalizedActions = [...new Set(actions.map(action => String(action).toUpperCase()))]
      .filter(action => ['UPDATE', 'DELETE'].includes(action));
    const { stdout } = await execAsync(
      'php process-sync-jobs.php',
      {
        cwd: phpDir,
        timeout: waitForBatchCompletion ? 360000 : 120000,
        env: {
          ...process.env,
          FB_E2E_WAIT_FOR_BATCH_COMPLETION: waitForBatchCompletion ? '1' : '0',
          FB_E2E_SYNC_REQUEST_IDS: normalizedRequestIds.join(','),
          FB_E2E_SYNC_ACTIONS: normalizedActions.join(','),
        },
      }
    );

    const result = parseJsonFromOutput(stdout);
    if (result.success) {
      if (result.sync_events_processed > 0) {
        console.log(`✅ Processed ${result.sync_events_processed} due product sync event(s)`);
      }
      console.log(`✅ Processed ${result.jobs_processed} sync job(s)`);
      if (waitForBatchCompletion && result.batch_count > 0) {
        console.log(`✅ Meta finished ${result.batch_count} submitted batch(es) without item errors`);
      }
    } else {
      console.warn(`⚠️ Sync job processing issue: ${result.message}`);
    }
    return result;

  } catch (error) {
    let processingError = error.message;

    try {
      const output = error.stdout ? String(error.stdout) : '';
      const result = parseJsonFromOutput(output);
      processingError = result.error || result.message || processingError;
    } catch {
      // Keep the process error when PHP did not emit a JSON result.
    }

    console.warn(`⚠️ Sync job processing error: ${processingError}`);
    return { success: false, error: processingError };
  }
}

/**
 * Drain the local queue, recover the latest completed job for every requested
 * product item, and wait until Meta finishes the corresponding catalog batch.
 *
 * @param {number|number[]} productIds WooCommerce product IDs
 * @param {'UPDATE'|'DELETE'} action Catalog action being awaited
 * @returns {Promise<Object>} Processing result
 */
async function waitForProductCatalogBatches(productIds, action = 'UPDATE') {
  const normalizedAction = String(action).toUpperCase();
  const requestIds = await getProductCatalogRequestIds(productIds, normalizedAction);
  return waitForCatalogRequestIds(requestIds, normalizedAction);
}

async function waitForCatalogRequestIds(requestIds, action = 'UPDATE') {
  const normalizedAction = String(action).toUpperCase();
  const result = await processPendingSyncJobs({
    waitForBatchCompletion: true,
    requestIds,
    actions: [normalizedAction],
  });

  if (!result.success) {
    throw new Error(
      result.error || `Unable to complete ${normalizedAction} catalog batches for request IDs: ${requestIds.join(', ')}`
    );
  }

  return result;
}

module.exports = {
  validateFacebookSync,
  processPendingSyncJobs,
  validateCategorySync,
  getProductCatalogRequestIds,
  waitForProductCatalogBatches
};
