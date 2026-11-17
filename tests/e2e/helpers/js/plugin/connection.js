/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * Facebook connection management helpers for E2E tests
 */

const { TIMEOUTS } = require('../constants/timeouts');
const { execWP } = require('../wordpress/exec');

const REQUIRED_RECONNECT_CREDENTIALS = {
  FB_ACCESS_TOKEN: 'accessToken',
  FB_BUSINESS_MANAGER_ID: 'businessManagerId',
  FB_EXTERNAL_BUSINESS_ID: 'externalBusinessId',
  FB_PRODUCT_CATALOG_ID: 'productCatalogId',
  FB_PIXEL_ID: 'pixelId',
  FB_PAGE_ID: 'pageId',
};

function isUsableCredential(value) {
  return typeof value === 'string'
    && value.trim() !== ''
    && !['undefined', 'null'].includes(value.trim().toLowerCase());
}

function getReconnectCredentials() {
  const missing = Object.keys(REQUIRED_RECONNECT_CREDENTIALS)
    .filter(name => !isUsableCredential(process.env[name]));

  if (missing.length > 0) {
    throw new Error(
      `Refusing to change the Facebook connection because required credentials are missing: ${missing.join(', ')}`
    );
  }

  return Object.fromEntries(
    Object.entries(REQUIRED_RECONNECT_CREDENTIALS)
      .map(([environmentName, credentialName]) => [credentialName, process.env[environmentName]])
  );
}

function assertFacebookReconnectCredentialsConfigured() {
  getReconnectCredentials();
}

/**
 * Get connection status from Meta for WooCommerce
 * @returns {Promise<Object>} Connection status details
 */
async function getConnectionStatus() {
  const { stdout } = await execWP(
    `if (!function_exists('facebook_for_woocommerce')) {
      echo json_encode([
        'connected' => false,
        'plugin_active' => false,
        'pixel_id' => get_option('wc_facebook_pixel_id'),
        'catalog_id' => get_option('wc_facebook_product_catalog_id'),
        'facebook_config' => get_option('facebook_config'),
        'access_token' => get_option('wc_facebook_access_token'),
        'merchant_access_token' => get_option('wc_facebook_merchant_access_token'),
        'external_business_id' => null
      ]);
    } else {
      \\$conn = facebook_for_woocommerce()->get_connection_handler();
      echo json_encode([
        'connected' => \\$conn->is_connected(),
        'plugin_active' => true,
        'pixel_id' => get_option('wc_facebook_pixel_id'),
        'catalog_id' => get_option('wc_facebook_product_catalog_id'),
        'facebook_config' => get_option('facebook_config'),
        'access_token' => get_option('wc_facebook_access_token'),
        'merchant_access_token' => get_option('wc_facebook_merchant_access_token'),
        'external_business_id' => \\$conn->get_external_business_id()
      ]);
    }`
  );
  return JSON.parse(stdout);
}

/**
 * Disconnect from Facebook and verify cleanup
 * @returns {Promise<Object>} Disconnection result
 */
async function disconnectAndVerify() {
  // Every current caller reconnects immediately afterwards. Fail before the
  // destructive disconnect if the environment cannot restore the connection.
  assertFacebookReconnectCredentialsConfigured();
  console.log('🔌 Disconnecting from Facebook...');

  const before = await getConnectionStatus();
  if (!before.connected) {
    console.log('⚠️ Already disconnected');
    return { before, after: before, success: true, skipped: true };
  }

  await execWP(`facebook_for_woocommerce()->get_connection_handler()->disconnect();`);

  let after;
  for (let i = 0; i < 5; i++) {
    await new Promise(resolve => setTimeout(resolve, TIMEOUTS.LONG));
    after = await getConnectionStatus();

    const isDisconnected = !after.connected && !after.pixel_id && !after.access_token && !after.facebook_config && !after.merchant_access_token;
    if (isDisconnected) {
      console.log(`✅ Disconnected after ${i + 1} attempt(s)`);
      break;
    }
  }

  const failures = [];

  if (after.connected) failures.push('Still connected');
  if (after.pixel_id) failures.push('Pixel ID not cleared');
  if (after.facebook_config) failures.push('Config not deleted');
  if (after.access_token) failures.push('Access token not cleared');
  if (after.merchant_access_token) failures.push('Merchant token not cleared');
  if (after.external_business_id === before.external_business_id) failures.push('External ID not changed');

  if (failures.length > 0) {
    throw new Error('❌ Disconnection failed:\n   - ' + failures.join('\n   - '));
  }

  console.log('✅ Disconnection verified');
  return { before, after, success: true };
}

/**
 * Reconnect to Facebook (mimics workflow setup)
 * @param {Object} options - Connection options
 * @returns {Promise<Object>} Reconnection result
 */
async function reconnectAndVerify(options = {}) {
  const enablePixel = options.enablePixel ?? 'yes';
  const enableS2S = options.enableS2S ?? 'yes';

  console.log(`🔄 Reconnecting to Facebook (pixel=${enablePixel}, s2s=${enableS2S})...`);

  const before = await getConnectionStatus();
  if (before.connected) {
    console.log('⚠️ Already connected');
    return { before, after: before, success: true, skipped: true };
  }

  const creds = getReconnectCredentials();

  console.log('   Deactivating plugin...');
  await execWP(`deactivate_plugins('facebook-for-woocommerce/facebook-for-woocommerce.php');`);

  console.log('   Setting options...');
  const dbOptions = [
    ['wc_facebook_access_token', creds.accessToken],
    ['wc_facebook_merchant_access_token', creds.accessToken],
    ['wc_facebook_business_manager_id', creds.businessManagerId],
    ['wc_facebook_external_business_id', creds.externalBusinessId],
    ['wc_facebook_product_catalog_id', creds.productCatalogId],
    ['wc_facebook_pixel_id', creds.pixelId],
    ['wc_facebook_page_id', creds.pageId],
    ['wc_facebook_enable_server_to_server', enableS2S],
    ['wc_facebook_enable_pixel', enablePixel],
    ['wc_facebook_enable_advanced_matching', 'yes'],
    ['wc_facebook_debug_mode', 'yes'],
    ['wc_facebook_enable_debug_mode', 'yes'],
    ['wc_facebook_has_connected_fbe_2', 'yes'],
    ['wc_facebook_has_authorized_pages_read_engagement', 'yes'],
    ['wc_facebook_enable_product_sync', 'yes']
  ];

  for (const [name, value] of dbOptions) {
    await execWP(`update_option('${name}', '${value}');`);
  }

  console.log('🔄 Activating plugin to initialize connection...');
  await execWP(`activate_plugins('facebook-for-woocommerce/facebook-for-woocommerce.php');`);

  const after = await getConnectionStatus();
  const failures = [];

  if (!after.connected) failures.push('Not connected');
  if (after.pixel_id !== creds.pixelId) failures.push(`Pixel ID mismatch (expected: ${creds.pixelId}, got: ${after.pixel_id})`);
  if (!after.access_token) failures.push('Access token missing');
  if (after.external_business_id !== creds.externalBusinessId) failures.push(`External ID mismatch (expected: ${creds.externalBusinessId}, got: ${after.external_business_id})`);
  if (after.catalog_id !== creds.productCatalogId) failures.push(`Catalog ID mismatch (expected: ${creds.productCatalogId}, got: ${after.catalog_id})`);

  if (failures.length > 0) {
    throw new Error('❌ Reconnection failed:\n   - ' + failures.join('\n   - '));
  }

  console.log('✅ Reconnection verified');
  return { before, after, success: true, credentials: creds };
}

/**
 * Verify all products have Facebook fields cleared
 * @returns {Promise<Object>} Verification result
 */
async function verifyProductsFacebookFieldsCleared() {
  console.log('🔍 Verifying all product Facebook fields are cleared...');

  const { stdout } = await execWP(`
    \\$products = get_posts([
      'post_type' => ['product', 'product_variation'],
      'posts_per_page' => -1,
      'post_status' => 'any'
    ]);

    \\$issues = [];

    foreach (\\$products as \\$product) {
      \\$meta = get_post_meta(\\$product->ID);
      \\$fb_fields = [];

      foreach (\\$meta as \\$key => \\$value) {
        if (strpos(\\$key, 'fb_') === 0 || strpos(\\$key, '_fb_') === 0 || strpos(\\$key, 'facebook_') === 0) {
          if (!empty(\\$value[0])) {
            \\$fb_fields[\\$key] = \\$value[0];
          }
        }
      }

      if (!empty(\\$fb_fields)) {
        \\$issues[] = [
          'id' => \\$product->ID,
          'type' => \\$product->post_type,
          'fields' => \\$fb_fields
        ];
      }
    }

    echo json_encode([
      'success' => empty(\\$issues),
      'total' => count(\\$products),
      'issues' => \\$issues
    ]);
  `);

  const result = JSON.parse(stdout);

  console.log(`✅ Checked ${result.total} products and variations`);

  if (result.issues.length > 0) {
    console.log(`❌ Found ${result.issues.length} products with Facebook fields not cleared:`);
    result.issues.forEach(issue => {
      console.log(`   - Product ID ${issue.id} (${issue.type}):`);
      Object.entries(issue.fields).forEach(([key, value]) => {
        console.log(`     • ${key}: ${value}`);
      });
    });
    throw new Error(`${result.issues.length} products still have Facebook fields`);
  }

  console.log('✅ All product Facebook fields cleared');
  return { success: true, total: result.total };
}

module.exports = {
  getConnectionStatus,
  assertFacebookReconnectCredentialsConfigured,
  disconnectAndVerify,
  reconnectAndVerify,
  verifyProductsFacebookFieldsCleared
};
