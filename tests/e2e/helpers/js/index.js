/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * E2E Test Helpers - Barrel Export
 *
 * Location: tests/e2e/helpers/js/index.js
 *
 * This file re-exports all helper modules for clean imports in spec files.
 *
 * Usage:
 *   const { loginToWordPress, createTestProduct, TIMEOUTS } = require('./helpers/js');
 *
 * Or import specific modules:
 *   const { auth, products, facebook } = require('./helpers/js');
 */

// =============================================================================
// Constants
// =============================================================================
const { TIMEOUTS } = require('./constants/timeouts');

// =============================================================================
// Auth
// =============================================================================
const {
  baseURL,
  username,
  password,
  loginToWordPress
} = require('./auth/login');

// =============================================================================
// Products
// =============================================================================
const {
  generateProductName,
  generateUniqueSKU,
  extractProductIdFromUrl,
  cleanupProduct,
  cleanupProducts,
  createTestProduct
} = require('./products/crud');

const {
  filterProducts,
  clickFirstProduct,
  publishProduct
} = require('./products/navigation');

const {
  generateProductFeedCSV,
  deleteFeedFile,
  generateProductUpdateCSV
} = require('./products/feed');

// =============================================================================
// Categories
// =============================================================================
const {
  createTestCategory,
  cleanupCategory
} = require('./categories/crud');

// =============================================================================
// Facebook Plugin
// =============================================================================
const {
  validateFacebookSync,
  processPendingSyncJobs,
  validateCategorySync,
  getProductCatalogRequestIds
} = require('./plugin/sync');

const {
  getConnectionStatus,
  assertFacebookReconnectCredentialsConfigured,
  disconnectAndVerify,
  reconnectAndVerify,
  verifyProductsFacebookFieldsCleared
} = require('./plugin/connection');

const {
  openFacebookOptions
} = require('./plugin/options');

// =============================================================================
// WordPress
// =============================================================================
const {
  wpSitePath,
  execWP,
  ensureDebugModeEnabled,
  checkWooCommerceLogs
} = require('./wordpress/exec');

const {
  installPlugin,
  uninstallPlugin,
  deactivatePlugin,
  activatePlugin,
  installPixelBlockerMuPlugin,
  removePixelBlockerMuPlugin,
  installJsErrorSimulatorMuPlugin,
  removeJsErrorSimulatorMuPlugin,
  installSingleSearchRedirectBlockerMuPlugin,
  removeSingleSearchRedirectBlockerMuPlugin,
  installBlockThemeHeaderSearchMuPlugin,
  removeBlockThemeHeaderSearchMuPlugin
} = require('./wordpress/plugins');

const {
  runWpCli,
  getActiveThemeStatus,
  switchThemeBySlug,
  acquireThemeLock,
  releaseThemeLock
} = require('./wordpress/themes');

// =============================================================================
// Checkout
// =============================================================================
const {
  completePurchaseFlow
} = require('./checkout/purchase');

// =============================================================================
// Batch Monitor
// =============================================================================
const {
  enableBatchMonitoring,
  disableBatchMonitoring,
  readBatchLog,
  waitForBatchLogProducts,
  installMonitoringPlugin,
  uninstallMonitoringPlugin,
  getMonitoringStatus
} = require('./batch-monitor');

// =============================================================================
// Utils
// =============================================================================
const {
  safeScreenshot,
  logTestStart,
  logTestEnd
} = require('./utils/logging');

const {
  ERROR_WHITELIST,
  checkForPhpErrors,
  checkForJsErrors
} = require('./utils/errors');

const {
  setProductTitle,
  setProductDescription,
  exactSearchSelect2Container,
  getVisibleSearchInput,
  submitSearch,
  dismissWooInterferingOverlays
} = require('./utils/ui');

// =============================================================================
// Events
// =============================================================================
const EventValidator = require('./events/validator');
const PixelCapture = require('./events/capture');
const EVENT_FIELD_CONTRACTS = require('./events/field-contracts');
const EVENT_SCHEMAS = EVENT_FIELD_CONTRACTS; // Backward-compatible alias
const TestSetup = require('./events/setup');

const {
  createVariableProductEventFixture,
  createGroupedProductEventFixture,
  selectVariationByLabel,
  setGroupedProductQuantity
} = require('./events/product-types');

const {
  loadCapturedEvents,
  getLatestEvent,
  asArray,
  assertEventContainsRetailerId,
  ignoreKnownPurchaseUserDataGap,
  ignoreKnownGuestCheckoutUserDataGap,
  createTempCustomerUser,
  deleteTempCustomerUser,
  getCartItemsViaStoreApi,
  clearCart,
  completeCheckoutFromCart
} = require('./events/runtime');

const {
  triggerAjaxAddToCartFromShop,
  isAjaxAddToCartAvailableOnShop
} = require('./events/ajax-cart');

const {
  holdSignals,
  releaseSignals,
  getSignalState,
  getQueuedSignalEvents
} = require('./events/signals');

// =============================================================================
// Module Exports (Grouped)
// =============================================================================

// Export grouped modules for namespace imports
const auth = {
  baseURL,
  username,
  password,
  loginToWordPress
};

const products = {
  generateProductName,
  generateUniqueSKU,
  extractProductIdFromUrl,
  cleanupProduct,
  cleanupProducts,
  createTestProduct,
  filterProducts,
  clickFirstProduct,
  publishProduct,
  generateProductFeedCSV,
  deleteFeedFile,
  generateProductUpdateCSV
};

const categories = {
  createTestCategory,
  cleanupCategory
};

const facebook = {
  validateFacebookSync,
  processPendingSyncJobs,
  validateCategorySync,
  getProductCatalogRequestIds,
  getConnectionStatus,
  assertFacebookReconnectCredentialsConfigured,
  disconnectAndVerify,
  reconnectAndVerify,
  verifyProductsFacebookFieldsCleared,
  openFacebookOptions
};

const wordpress = {
  wpSitePath,
  execWP,
  ensureDebugModeEnabled,
  checkWooCommerceLogs,
  installPlugin,
  uninstallPlugin,
  deactivatePlugin,
  activatePlugin,
  installPixelBlockerMuPlugin,
  removePixelBlockerMuPlugin,
  installJsErrorSimulatorMuPlugin,
  removeJsErrorSimulatorMuPlugin,
  installSingleSearchRedirectBlockerMuPlugin,
  removeSingleSearchRedirectBlockerMuPlugin,
  installBlockThemeHeaderSearchMuPlugin,
  removeBlockThemeHeaderSearchMuPlugin,
  runWpCli,
  getActiveThemeStatus,
  switchThemeBySlug,
  acquireThemeLock,
  releaseThemeLock
};

const checkout = {
  completePurchaseFlow
};

const batchMonitor = {
  enableBatchMonitoring,
  disableBatchMonitoring,
  readBatchLog,
  waitForBatchLogProducts,
  installMonitoringPlugin,
  uninstallMonitoringPlugin,
  getMonitoringStatus
};

const utils = {
  safeScreenshot,
  logTestStart,
  logTestEnd,
  ERROR_WHITELIST,
  checkForPhpErrors,
  checkForJsErrors,
  setProductTitle,
  setProductDescription,
  exactSearchSelect2Container,
  getVisibleSearchInput,
  submitSearch,
  dismissWooInterferingOverlays
};

const events = {
  EventValidator,
  PixelCapture,
  EVENT_FIELD_CONTRACTS,
  EVENT_SCHEMAS,
  TestSetup,
  createVariableProductEventFixture,
  createGroupedProductEventFixture,
  selectVariationByLabel,
  setGroupedProductQuantity,
  loadCapturedEvents,
  getLatestEvent,
  asArray,
  assertEventContainsRetailerId,
  ignoreKnownPurchaseUserDataGap,
  ignoreKnownGuestCheckoutUserDataGap,
  createTempCustomerUser,
  deleteTempCustomerUser,
  getCartItemsViaStoreApi,
  clearCart,
  completeCheckoutFromCart,
  triggerAjaxAddToCartFromShop,
  isAjaxAddToCartAvailableOnShop,
  holdSignals,
  releaseSignals,
  getSignalState,
  getQueuedSignalEvents
};

// =============================================================================
// Flat Exports (for destructuring imports)
// =============================================================================
module.exports = {
  // Constants
  TIMEOUTS,

  // Auth
  baseURL,
  username,
  password,
  loginToWordPress,

  // Products - CRUD
  generateProductName,
  generateUniqueSKU,
  extractProductIdFromUrl,
  cleanupProduct,
  cleanupProducts,
  createTestProduct,

  // Products - Navigation
  filterProducts,
  clickFirstProduct,
  publishProduct,

  // Products - Feed
  generateProductFeedCSV,
  deleteFeedFile,
  generateProductUpdateCSV,

  // Categories
  createTestCategory,
  cleanupCategory,

  // Facebook - Sync
  validateFacebookSync,
  processPendingSyncJobs,
  validateCategorySync,
  getProductCatalogRequestIds,

  // Facebook - Connection
  getConnectionStatus,
  assertFacebookReconnectCredentialsConfigured,
  disconnectAndVerify,
  reconnectAndVerify,
  verifyProductsFacebookFieldsCleared,

  // Facebook - Options
  openFacebookOptions,

  // WordPress - Exec
  wpSitePath,
  execWP,
  ensureDebugModeEnabled,
  checkWooCommerceLogs,

  // WordPress - Plugins
  installPlugin,
  uninstallPlugin,
  deactivatePlugin,
  activatePlugin,
  installPixelBlockerMuPlugin,
  removePixelBlockerMuPlugin,
  installJsErrorSimulatorMuPlugin,
  removeJsErrorSimulatorMuPlugin,
  installSingleSearchRedirectBlockerMuPlugin,
  removeSingleSearchRedirectBlockerMuPlugin,
  installBlockThemeHeaderSearchMuPlugin,
  removeBlockThemeHeaderSearchMuPlugin,

  // WordPress - Themes
  runWpCli,
  getActiveThemeStatus,
  switchThemeBySlug,
  acquireThemeLock,
  releaseThemeLock,

  // Checkout
  completePurchaseFlow,

  // Batch Monitor
  enableBatchMonitoring,
  disableBatchMonitoring,
  readBatchLog,
  waitForBatchLogProducts,
  installMonitoringPlugin,
  uninstallMonitoringPlugin,
  getMonitoringStatus,

  // Utils - Logging
  safeScreenshot,
  logTestStart,
  logTestEnd,

  // Utils - Errors
  ERROR_WHITELIST,
  checkForPhpErrors,
  checkForJsErrors,

  // Utils - UI
  setProductTitle,
  setProductDescription,
  exactSearchSelect2Container,
  getVisibleSearchInput,
  submitSearch,
  dismissWooInterferingOverlays,

  // Events
  EventValidator,
  PixelCapture,
  EVENT_FIELD_CONTRACTS,
  EVENT_SCHEMAS,
  TestSetup,
  createVariableProductEventFixture,
  createGroupedProductEventFixture,
  selectVariationByLabel,
  setGroupedProductQuantity,
  loadCapturedEvents,
  getLatestEvent,
  asArray,
  assertEventContainsRetailerId,
  ignoreKnownPurchaseUserDataGap,
  ignoreKnownGuestCheckoutUserDataGap,
  createTempCustomerUser,
  deleteTempCustomerUser,
  getCartItemsViaStoreApi,
  clearCart,
  completeCheckoutFromCart,
  triggerAjaxAddToCartFromShop,
  isAjaxAddToCartAvailableOnShop,
  holdSignals,
  releaseSignals,
  getSignalState,
  getQueuedSignalEvents,

  // Grouped module exports (for namespace imports)
  auth,
  products,
  categories,
  facebook,
  wordpress,
  checkout,
  batchMonitor,
  utils,
  events
};
