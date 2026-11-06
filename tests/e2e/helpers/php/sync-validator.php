<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * E2E Facebook Sync Validator - For Products & Categories
 *
 * Validates sync between WooCommerce and Facebook with comprehensive debugging
 * Follows same flow pattern for all entity types: getData -> checkSync -> compareFields
 *
 * Location: tests/e2e/helpers/php/sync-validator.php
 *
 * Usage:
 *   Products:   php helpers/php/sync-validator.php <product_id> [wait_seconds] [max_retries]
 *   Categories: php helpers/php/sync-validator.php --type=category <category_id> [wait_seconds] [max_retries]
 */

// Bootstrap WordPress only when not already loaded (e.g. direct php execution).
$wp_url = getenv('WORDPRESS_URL');

if (!defined('ABSPATH')) {
    $wp_path = getenv('WORDPRESS_PATH') . '/wp-load.php';

    if (!file_exists($wp_path)) {
        echo json_encode([
            'success' => false,
            'error' => 'WordPress not found at: ' . $wp_path
        ]);
        exit(1);
    }

    require_once($wp_path);
}

/**
 * Facebook Sync Validator Class
 */
class FacebookSyncValidator {

    private const POLL_INTERVAL_SECONDS = 10;
    private const MAX_POLL_SECONDS = 300;

    private $product_id;
    private $product;
    private $integration;
    private $result;
    private $max_retries;

    /**
     * Field mappings between WooCommerce and Facebook fields
     */
    private const FIELD_MAPPINGS = [
        'title' => 'name',
        'price' => 'price',
        'retailer_id' => 'retailer_id',
        'availability' => 'availability',
        'description' => 'description',
        'brand' => 'brand',
        'condition' => 'condition',
        'image_url' => 'image_url'
    ];

    /**
     * Helper method to add debug messages
     */
    private function debug($message) {
        $this->result['debug'][] = $message;
    }


    /**
     * Initialize the validator and verify dependencies
     */
    public function __construct($product_id, $wait_seconds = 5, $max_retries = 6) {
        $this->product_id = (int)$product_id;
        $this->max_retries = (int)$max_retries;
        $this->result = [
            'success' => false,
            'product_id' => $this->product_id,
            'product_type' => 'unknown',
            'sync_status' => 'unknown',
            'retailer_id' => null,
            'facebook_id' => null,
            'mismatches' => [],
            'summary' => [],
            'debug' => [],
            'error' => null
        ];

        // Wait for Facebook processing
        if ($wait_seconds > 0) {
            sleep($wait_seconds);
            $this->debug("Waited {$wait_seconds} seconds before validation");
        }

        $this->validateDependencies();
        $this->initializeProduct();
        $this->initializeIntegration();
    }

    /**
     * Check if required plugins and extensions are available
     */
    private function validateDependencies() {
        if (!function_exists('wc_get_product')) {
            throw new Exception('WooCommerce not active');
        }
        if (!function_exists('facebook_for_woocommerce')) {
            throw new Exception('Facebook plugin not loaded');
        }
        if (!$this->product_id) {
            throw new Exception('Product ID required');
        }
    }

    /**
     * Initialize product
     */
    private function initializeProduct() {
        $this->debug("Initializing product: {$this->product_id}");
        $this->product = wc_get_product($this->product_id);

        // Fail fast if the product ID doesn't exist in WooCommerce
        if (!$this->product) {
            throw new Exception("Product {$this->product_id} not found");
        }

        // Get and log retailer ID
        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);
        $this->debug("Product retailer ID: {$retailer_id} and type: {$this->product->get_type()}");

        $this->result['product_type'] = $this->product->get_type();
        $this->debug("Initialized {$this->result['product_type']} product: {$this->product->get_name()}");
    }

    /**
     * Set up Facebook API integration and verify configuration
     */
    private function initializeIntegration() {
        $this->integration = facebook_for_woocommerce()->get_integration();
        if (!$this->integration) {
            throw new Exception('Facebook integration not available');
        }
        if (!$this->integration->is_configured()) {
            throw new Exception('Facebook integration not configured');
        }
        $this->debug('Facebook integration initialized and configured');
    }

    /**
     * Main validation method - validates sync between WooCommerce and Facebook
     * 1. Get both platform data (WooCommerce + Facebook)
     * 2. Check sync status using fetched Facebook data
     * 3. Compare fields between platforms
     * 4. Set success based on sync status and no mismatches
     */
    public function validate() {
        try {
            $actual_type = $this->product->get_type();

            // Step 1: Get both platform data (WooCommerce + Facebook)
            $data = $this->getBothPlatformData($actual_type);
            $this->result['raw_data'] = $data;

            // Step 2: Check sync status using fetched Facebook data
            $this->checkSyncStatus($data);

            // Step 3: Compare fields between platforms
            $this->compareFields($data);

            // Set success based on sync status and no mismatches
            $this->result['success'] = ($this->result['sync_status'] === 'synced' && count($this->result['mismatches']) === 0);
        } catch (Exception $e) {
            $this->result['error'] = $e->getMessage();
            $this->debug("Validation failed: " . $e->getMessage());
        }
        return $this->result;
    }

    /**
     * Get both WooCommerce and Facebook data for any product type
     */
    private function getBothPlatformData($product_type) {
        $this->debug("Fetching both platform data for {$product_type} product");

        if ($product_type === 'variable') {
            return $this->getVariableProductData();
        } else {
            return $this->getSimpleProductData();
        }
    }

    /**
     * Get data for simple products
     */
    private function getSimpleProductData() {
        // Get WooCommerce data
        $retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);
        $this->result['retailer_id'] = $retailer_id;

        $woo_data = $this->extractWooCommerceFields($this->product, $retailer_id);
        $this->debug("Extracted WooCommerce data for simple product");
        // $this->debug("WooCommerce data: " . json_encode($woo_data, JSON_PRETTY_PRINT));

        // Get Facebook data using full retailer ID
        $facebook_data = $this->fetchFacebookData([$retailer_id]);

        return [
            'type' => 'simple',
            'woo_data' => [$woo_data],
            'facebook_data' => $facebook_data
        ];
    }

    /**
     * Get data for variable products (variations only)
     */
    private function getVariableProductData() {
        $failed_variations = [];
        $woo_data_array = [];
        $retailer_ids = [];

        // Set parent retailer_id for result tracking
        $this->result['retailer_id']  = WC_Facebookcommerce_Utils::get_fb_retailer_id($this->product);

        // All variations
        $variations = $this->product->get_children();
        $this->debug("Processing " . count($variations) . " variations: [" . implode(', ', $variations) . "]");

        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);

            try {
                $var_retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id($variation);
                $woo_data_array[] = $this->extractWooCommerceFields($variation, $var_retailer_id);
                $retailer_ids[] = $var_retailer_id;

                $this->debug("Extracted variation {$variation_id} data successfully");
            } catch (Exception $e) {
                $failed_variations[] = $variation_id;
                $this->debug("Variation {$variation_id} data extraction failed: " . $e->getMessage());
            }
        }

        // Summary for variable products
        $total_variations = count($variations);
        $successful_variations = $total_variations - count($failed_variations);
        $this->result['summary'] = [
            'total_variations' => $total_variations,
            'successful_variations' => $successful_variations,
            'failed_variations' => count($failed_variations),
            'failed_variation_ids' => $failed_variations
        ];

        if (count($failed_variations) > 0) {
            $this->debug("Failed to process variations: " . implode(', ', $failed_variations));
        }

        // Poll every variation within one shared retry window. Previously each
        // missing variation exhausted the complete exponential backoff before
        // the next variation was checked, multiplying the validation duration.
        $product_group_id = (string)get_post_meta(
            $this->product_id,
            WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID,
            true
        );
        if ($product_group_id) {
            $this->debug("Polling Facebook product group {$product_group_id} as one variation snapshot");
        } else {
            $this->debug("Facebook product group ID is unavailable; falling back to retailer_id lookups");
        }

        $facebook_data_array = $this->fetchFacebookData($retailer_ids, $product_group_id);

        return [
            'type' => 'variable',
            'woo_data' => $woo_data_array,
            'facebook_data' => $facebook_data_array
        ];
    }

    /**
     * Extract WooCommerce product fields
     */
    private function extractWooCommerceFields($product, $retailer_id) {
        // Create Facebook product wrapper to get the prepared data; variations will have parent product
        $fb_product = $product->get_parent_id() ?
            new WC_Facebook_Product($product, new WC_Facebook_Product(wc_get_product($product->get_parent_id()))) :
            new WC_Facebook_Product($product);

        $product_data = $fb_product->prepare_product($retailer_id, WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH);
        return [
            'id' => $product->get_id(),  // Always include id for both simple and variable products
            'title' => $product_data['title'] ?? $product->get_name(),
            'price' => $product_data['price'] ?? $product->get_regular_price(),
            // Keep full description to avoid false mismatches on long-content performance tests.
            'description' => $product_data['description'] ?? '',
            'availability' => $product_data['availability'] ?? '',
            'retailer_id' => $retailer_id,
            'condition' => $product_data['condition'] ?? '',
            'brand' => $product_data['brand'] ?? '',
            'color' => $product_data['color'] ?? '',
            'size' => $product_data['size'] ?? '',
            'image_url' => $product_data['image_link'] ?? ''
        ];
    }

    /**
     * Find the product group created for a WooCommerce retailer ID.
     *
     * Catalog batch status can be finished while the filtered products edge
     * still returns no item. The product-group edge becomes authoritative
     * sooner and lets the validator read the group's products directly.
     */
    private function findFacebookProductGroupId($catalog_id, $retailer_id) {
        $api = facebook_for_woocommerce()->get_api();
        $access_token = $api ? $api->get_access_token() : '';

        if (!$access_token) {
            throw new Exception('Facebook access token is unavailable for product group lookup');
        }

        $url = add_query_arg(
            [
                'filter' => wp_json_encode(['retailer_id' => ['eq' => (string)$retailer_id]]),
                'fields' => 'id,retailer_id',
                // Meta currently returns the newest groups even when the
                // retailer filter is not applied, so verify matches locally.
                'limit' => 100
            ],
            \WooCommerce\Facebook\API::GRAPH_API_URL
                . \WooCommerce\Facebook\API::API_VERSION
                . "/{$catalog_id}/product_groups"
        );

        $response = wp_remote_get(
            $url,
            [
                'headers' => ['Authorization' => "Bearer {$access_token}"],
                'timeout' => 30
            ]
        );

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (200 !== $response_code) {
            throw new Exception($body['error']['message'] ?? "HTTP {$response_code}");
        }

        foreach ((array)($body['data'] ?? []) as $group) {
            if ((string)($group['retailer_id'] ?? '') === (string)$retailer_id) {
                return (string)($group['id'] ?? '');
            }
        }

        return '';
    }

    /**
     * Fetch Facebook data for all retailer IDs within one shared retry window.
     */
    private function fetchFacebookData($retailer_ids, $product_group_id = '') {
        $api = facebook_for_woocommerce()->get_api();
        $catalog_id = $this->integration->get_product_catalog_id();
        $fields = 'id,name,price,description,availability,retailer_id,condition,brand,color,size,image_url,product_group{id},product_sets{id,retailer_id}';
        $requested_retailer_ids = array_map('strval', $retailer_ids);
        $unique_retailer_ids = array_values(array_unique($requested_retailer_ids));
        $facebook_data = [];
        $poll_timeout_seconds = $this->getPollTimeoutSeconds();
        $deadline = microtime(true) + $poll_timeout_seconds;
        $attempt = 0;
        $complete_snapshot_count = 0;
        $required_complete_snapshots = $product_group_id ? 1 : (count($unique_retailer_ids) > 1 ? 2 : 1);
        $stable_snapshot_found = false;

        do {
            $attempt++;
            $current_facebook_data = [];
            $missing_retailer_ids = [];
            $has_api_error = false;

            if (!$product_group_id && !empty($this->result['retailer_id'])) {
                try {
                    $product_group_id = $this->findFacebookProductGroupId(
                        $catalog_id,
                        $this->result['retailer_id']
                    );

                    if ($product_group_id) {
                        $required_complete_snapshots = 1;
                        $this->debug("Discovered Facebook product group {$product_group_id} from the catalog group edge");
                    }
                } catch (Exception $e) {
                    // Fall back to the existing product search. Any connection
                    // failure there remains a distinct validation error.
                    $this->debug("Facebook product group lookup failed: " . $e->getMessage());
                }
            }

            if ($product_group_id) {
                try {
                    $response = $api->get_product_group_products($product_group_id, 1000, $fields);
                    $group_products = $response->response_data['data'] ?? [];

                    foreach ($group_products as $group_product) {
                        $retailer_id = (string)($group_product['retailer_id'] ?? '');
                        if ($retailer_id && in_array($retailer_id, $unique_retailer_ids, true)) {
                            $current_facebook_data[$retailer_id] = $this->formatFacebookData($group_product);
                        }
                    }

                    foreach ($unique_retailer_ids as $retailer_id) {
                        if (!isset($current_facebook_data[$retailer_id])) {
                            $current_facebook_data[$retailer_id] = ['found' => false];
                            $missing_retailer_ids[] = $retailer_id;
                        }
                    }
                } catch (Exception $e) {
                    $has_api_error = true;
                    foreach ($unique_retailer_ids as $retailer_id) {
                        $current_facebook_data[$retailer_id] = ['found' => false, 'error' => $e->getMessage()];
                        $missing_retailer_ids[] = $retailer_id;
                    }
                    $this->debug("Facebook product group API error: " . $e->getMessage());
                }
            } else {
                foreach ($unique_retailer_ids as $retailer_id) {
                    try {
                        $response = $api->get_product_facebook_fields($catalog_id, $retailer_id, $fields);

                        if ($response && $response->response_data && isset($response->response_data['data'][0])) {
                            $current_facebook_data[$retailer_id] = $this->formatFacebookData($response->response_data['data'][0]);
                        } else {
                            $current_facebook_data[$retailer_id] = ['found' => false];
                            $missing_retailer_ids[] = $retailer_id;
                        }
                    } catch (Exception $e) {
                        $current_facebook_data[$retailer_id] = ['found' => false, 'error' => $e->getMessage()];
                        $missing_retailer_ids[] = $retailer_id;
                        $has_api_error = true;
                        $this->debug("Facebook API error for retailer_id {$retailer_id}: " . $e->getMessage());
                    }
                }

                // A retailer-id lookup can become visible before its siblings
                // and exposes the authoritative product group ID. Switch to the
                // group endpoint as soon as that happens so subsequent polling
                // reads one coherent variation snapshot instead of independent
                // eventually-consistent search results.
                if (!$has_api_error && count($missing_retailer_ids) > 0) {
                    foreach ($current_facebook_data as $data) {
                        if (!empty($data['product_group_id'])) {
                            $product_group_id = (string)$data['product_group_id'];
                            $required_complete_snapshots = 1;
                            $this->debug("Discovered Facebook product group {$product_group_id}; switching to group snapshot polling");
                            break;
                        }
                    }

                    if ($product_group_id) {
                        $facebook_data = $current_facebook_data;
                        continue;
                    }
                }
            }

            // Use one complete catalog snapshot. An ID observed on an earlier
            // attempt may disappear from the eventually consistent query, so
            // latching individual successes can produce a false positive.
            $facebook_data = $current_facebook_data;

            if (count($missing_retailer_ids) === 0) {
                $complete_snapshot_count++;
                if ($complete_snapshot_count >= $required_complete_snapshots) {
                    $stable_snapshot_found = true;
                    $this->debug("Successfully fetched all Facebook data in {$complete_snapshot_count} consecutive snapshot(s), ending on attempt #{$attempt}");
                    break;
                }

                $this->debug("Facebook API attempt #{$attempt} returned a complete snapshot; confirming stability");
            } else {
                $complete_snapshot_count = 0;
            }

            // Preserve API errors as distinct failures instead of retrying an
            // authentication or request error for the entire poll window.
            if ($has_api_error) {
                break;
            }

            $remaining_seconds = $deadline - microtime(true);
            if ($remaining_seconds <= 0) {
                break;
            }

            $sleep_seconds = min(self::POLL_INTERVAL_SECONDS, max(1, (int) ceil($remaining_seconds)));
            if (count($missing_retailer_ids) === 0) {
                $this->debug("Waiting {$sleep_seconds}s to confirm the complete catalog snapshot remains stable");
            } else {
                $missing_ids = implode(', ', $missing_retailer_ids);
                $this->debug("Facebook API attempt #{$attempt} did not find retailer_ids: [{$missing_ids}] (waiting {$sleep_seconds}s; {$poll_timeout_seconds}s shared poll window)");
            }
            sleep($sleep_seconds);
        } while (true);

        if (!$stable_snapshot_found && $complete_snapshot_count > 0) {
            $this->debug("A complete catalog snapshot was observed but could not be confirmed on a consecutive poll");
            foreach ($facebook_data as $retailer_id => $data) {
                $facebook_data[$retailer_id]['found'] = false;
                $facebook_data[$retailer_id]['unstable'] = true;
            }
        }

        foreach ($facebook_data as $retailer_id => $data) {
            if (!($data['found'] ?? false) && !isset($data['error'])) {
                $this->debug("No Facebook data found for retailer_id: {$retailer_id} after {$attempt} attempts within a {$poll_timeout_seconds}s shared poll window");
            }
        }

        return array_map(function($retailer_id) use ($facebook_data) {
            return $facebook_data[$retailer_id];
        }, $requested_retailer_ids);
    }

    /**
     * Use one bounded propagation window for every positive catalog lookup.
     * The legacy retry argument is retained so callers can pass zero when they
     * expect an item to be absent, but it no longer shortens positive checks.
     */
    private function getPollTimeoutSeconds() {
        if ($this->max_retries <= 1) {
            return 0;
        }

        $poll_override = getenv('FB_E2E_CATALOG_POLL_SECONDS');
        if (false !== $poll_override && is_numeric($poll_override)) {
            return (int)min(self::MAX_POLL_SECONDS, max(0, (int)$poll_override));
        }

        return self::MAX_POLL_SECONDS;
    }

    /**
     * Normalize one Facebook catalog response for field comparison.
     */
    private function formatFacebookData($fb_data) {
        global $wp_url;

        return [
            'id' => $fb_data['id'] ?? null,
            'name' => $fb_data['name'] ?? '',
            'price' => $fb_data['price'] ?? '',
            'description' => $fb_data['description'] ?? '',
            'availability' => $fb_data['availability'] ?? '',
            'retailer_id' => $fb_data['retailer_id'] ?? '',
            'condition' => $fb_data['condition'] ?? '',
            'brand' => $fb_data['brand'] ?? '',
            'color' => $fb_data['color'] ?? '',
            'size' => $fb_data['size'] ?? '',
            'image_url' => (!empty($fb_data['image_url'])) ? $fb_data['image_url'] : ($wp_url . '/wp-content/uploads/woocommerce-placeholder.webp'),
            'product_group_id' => $fb_data['product_group']['id'] ?? null,
            'product_sets' => $fb_data['product_sets']['data'] ?? [],
            'found' => true
        ];
    }


    /**
     * Check if products are synced to Facebook (unified for both simple and variable)
     */
    private function checkSyncStatus($data) {
        $total_product_count = count($data['woo_data']);
        $synced_products = array_filter($data['facebook_data'], function($fb_data) {
            return $fb_data['found'] ?? false;
        });
        $synced_count = count($synced_products);

        // Get unique product group IDs from synced products
        $product_group_ids = array_unique(array_filter(array_map(function($fb_data) {
            return $fb_data['product_group_id'] ?? null;
        }, $synced_products)));

        // Synced if:
        // 1. ALL products/variations exist in Facebook
        // 2. All products/variations belong to the same product group
        if ($total_product_count > 0 && $synced_count === $total_product_count && count($product_group_ids) === 1) {
            $this->result['sync_status'] = 'synced';
            $this->result['facebook_id'] = reset($product_group_ids); // Use the common group ID
            $this->debug("{$data['type']} Product {$this->result['retailer_id']} is fully synced with Facebook product group: {$this->result['facebook_id']}");

        } else {
            $this->result['sync_status'] = 'not_synced';

            if ($synced_count < $total_product_count) {
                // Find missing products/variations
                $missing_items = [];
                for ($i = 0; $i < $total_product_count; $i++) {
                    if (!($data['facebook_data'][$i]['found'] ?? false)) {
                        // cos we can't just loop on $data['facebook_data'] as it does not have retailer_id during failure fetches
                        $product_id = $data['woo_data'][$i]['id'] ?? "unknown_{$i}";
                        $retailer_id = $data['woo_data'][$i]['retailer_id'] ?? "unknown_retailer_{$i}";
                        $missing_items[] = "ID:{$product_id} (retailer:{$retailer_id})";
                    }
                }

                $this->debug("Products/variations not synced to Facebook: " . implode(', ', $missing_items));

            } elseif (count($product_group_ids) > 1) {
                $product_type = $data['type'] === 'variable' ? 'variations' : 'product';
                $this->debug("{$data['type']} product not synced - {$product_type} belong to different product groups: " . implode(', ', $product_group_ids));
            }
        }
    }

    /**
     * Compare fields between WooCommerce and Facebook for all products
     */
    private function compareFields($data) {
        $mismatches = [];
        $compared_products = 0;

        // Loop through each product (simple = 1 item, variable = N items)
        for ($i = 0; $i < count($data['woo_data']); $i++) {
            $woo_data = $data['woo_data'][$i];
            $facebook_data = $data['facebook_data'][$i];

            if (!($facebook_data['found'] ?? false)) {
                continue; // Skip products not found in Facebook
                // these are logged in checkSyncStatus as missing variations
            }

            $compared_products++;

            // Use the consistent id field from woo_data for both simple and variable products
            $product_id = $woo_data['id'] ?? $this->product_id;

            $product_mismatches = $this->compareProductFields(
                $woo_data,
                $facebook_data,
                $product_id
            );

            if (count($product_mismatches) > 0) {
                $mismatches = array_merge($mismatches, $product_mismatches);
                $this->debug("Found mismatches for product/variation -  {$product_id}");
            }
        }

        $this->result['mismatches'] = $mismatches;
        $this->debug("Compared fields for {$compared_products} products, found " . count($mismatches) . " total mismatches");
    }

    /**
     * Compare fields for a single product
     */
    private function compareProductFields($woo_data, $facebook_data, $product_id) {
        $mismatches = [];

        foreach (self::FIELD_MAPPINGS as $woo_field => $fb_field) {
            $woo_value = $woo_data[$woo_field] ?? '';
            $fb_value = $facebook_data[$fb_field] ?? '';

            $normalized_woo = $this->normalizeValue($woo_value, $woo_field);
            $normalized_fb = $this->normalizeValue($fb_value, $woo_field);

            if ($normalized_woo !== $normalized_fb) {
                $this->debug("MISMATCH {$woo_field}: WooCommerce='{$woo_value}' (normalized='{$normalized_woo}') vs Facebook='{$fb_value}' (normalized='{$normalized_fb}')");

                $mismatches["{$product_id}_{$woo_field}"] = [
                    'product_id' => $product_id,
                    'field' => $woo_field,
                    'woocommerce_value' => $woo_value,
                    'facebook_value' => $fb_value
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Helper function to truncate text with ellipsis
     */
    private function truncateText($text, $length) {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . '...';
    }

    private function normalizeValue($value, $field = '') {
        $normalized = trim(strtolower((string)$value));

        // Special handling for price fields
        if ($field === 'price') {
            return $this->normalizePrice($normalized);
        }

        return $normalized;
    }

    /**
     * Normalize price values to handle different currency formats
     * Examples:
     * "34 GBP" -> "34.00"
     * "£34.00" -> "34.00"
     * "$25.99" -> "25.99"
     * "19.99 USD" -> "19.99"
     */
    private function normalizePrice($price) {
        if (empty($price)) return '';

        // Remove currency symbols and codes
        $price = preg_replace('/[^\d.,]/', '', (string)$price);
        $price = preg_replace('/,(?=\d{3,})/', '', $price); // Remove thousands separators
        $price = str_replace(',', '.', $price); // Convert comma decimals

        return is_numeric($price) ? number_format((float)$price, 2, '.', '') : $price;
    }

    public function getJsonResult() {
        return json_encode($this->result, JSON_PRETTY_PRINT);
    }

    public static function validateProduct($product_id, $wait_seconds = 5) {
        $validator = new self($product_id, $wait_seconds);
        return $validator->validate();
    }
}

/**
 * Category Sync Validator Class
 *
 * Validates WooCommerce category sync to Facebook product sets
 */
class CategorySyncValidator {

    private $category_id;
    private $category;
    private $integration;
    private $result;
    private $max_retries;

    /**
     * Field mappings between WooCommerce category and Facebook product set
     */
    private const FIELD_MAPPINGS = [
        'name' => 'name',
        'retailer_id' => 'retailer_id'
    ];

    /**
     * Helper method to add debug messages
     */
    private function debug($message) {
        $this->result['debug'][] = $message;
    }

    /**
     * Initialize the validator and verify dependencies
     */
    public function __construct($category_id, $wait_seconds = 5, $max_retries = 6) {
        $this->category_id = (int)$category_id;
        $this->max_retries = (int)$max_retries;
        $this->result = [
            'success' => false,
            'category_id' => $this->category_id,
            'term_taxonomy_id' => null,
            'sync_status' => 'unknown',
            'retailer_id' => null,
            'facebook_product_set_id' => null,
            'mismatches' => [],
            'debug' => [],
            'error' => null
        ];

        // Wait for Facebook processing
        if ($wait_seconds > 0) {
            sleep($wait_seconds);
            $this->debug("Waited {$wait_seconds} seconds before validation");
        }

        $this->initializeIntegration();
        $this->validateDependencies();

        // Initialize category - if it fails, we still allow validation to continue
        // but it will fail gracefully in the validate() method
        if (!$this->initializeCategory()) {
            return;
        }
    }

    /**
     * Check if required functions are available
     */
    private function validateDependencies() {
        if (!function_exists('get_term')) {
            throw new Exception('WordPress term functions not available');
        }
        if (!function_exists('facebook_for_woocommerce')) {
            throw new Exception('Facebook plugin not loaded');
        }
        if (!$this->category_id) {
            throw new Exception('Category ID required');
        }
    }

    /**
     * Initialize category
     */
    private function initializeCategory() {
        $this->debug("Initializing category: {$this->category_id}");
        $this->category = get_term($this->category_id, 'product_cat');

        if (is_wp_error($this->category) || !$this->category) {
            $this->result['success'] = false;
            $this->result['error'] = "Category {$this->category_id} not found in WooCommerce";
            $this->result['message'] = "The category does not exist in WooCommerce. It may have been deleted.";
            $this->debug("Category {$this->category_id} not found in WooCommerce");
            return false;
        }

        $this->result['term_taxonomy_id'] = $this->category->term_taxonomy_id;
        $this->debug("Initialized category: {$this->category->name} (term_taxonomy_id: {$this->category->term_taxonomy_id})");
        return true;
    }

    /**
     * Set up Facebook API integration and verify configuration
     */
    private function initializeIntegration() {
        $this->integration = facebook_for_woocommerce()->get_integration();
        if (!$this->integration) {
            throw new Exception('Facebook integration not available');
        }
        if (!$this->integration->is_configured()) {
            throw new Exception('Facebook integration not configured');
        }
        $this->debug('Facebook integration initialized and configured');
    }

    /**
     * Main validation method - validates sync between WooCommerce category and Facebook product set
     */
    public function validate() {
        try {
            // Step 1: Get WooCommerce category data
            $woo_data = $this->getCategoryData();
            $this->debug("Extracted WooCommerce category data");

            // Step 2: Fetch Facebook product set data
            $retailer_id = $this->getRetailerId($this->category);
            $this->result['retailer_id'] = $retailer_id;
            $fb_data = $this->fetchFacebookProductSetData($retailer_id);

            $this->result['raw_data'] = [
                'woo_data' => $woo_data,
                'facebook_data' => $fb_data
            ];
            // Step 3: Check sync status
            $this->checkSyncStatus($woo_data, $fb_data);

            // Step 4: Compare fields if synced
            if ($fb_data['found']) {
                $this->compareFields($woo_data, $fb_data);
            }

            // Set success based on sync status and no mismatches
            $this->result['success'] = (
                $this->result['sync_status'] === 'synced' &&
                count($this->result['mismatches']) === 0
            );

        } catch (Exception $e) {
            $this->result['error'] = $e->getMessage();
            $this->debug("Validation failed: " . $e->getMessage());
        }

        return $this->result;
    }

    /**
     * Extract WooCommerce category data
     */
    private function getCategoryData() {
        $external_url = get_term_link($this->category, 'product_cat');

        // Handle WP_Error from get_term_link
        if (is_wp_error($external_url)) {
            $external_url = '';
        }

        return [
            'name' => $this->category->name ?? '',
            'description' => $this->category->description ?? '',
            'external_url' => $external_url ?? '',
            'retailer_id' => $this->getRetailerId($this->category) ?? '',
            'term_taxonomy_id' => $this->category->term_taxonomy_id ?? ''
        ];
    }

    /**
     * Get retailer ID for category (uses term_taxonomy_id)
     */
    private function getRetailerId($category) {
        // Important: Categories use term_taxonomy_id as retailer_id (not term_id)
        return isset($category->term_taxonomy_id) ? (string)$category->term_taxonomy_id : $this->category_id;
    }

    /**
     * Fetch Facebook product set data via API with retry logic
     */
    private function fetchFacebookProductSetData($retailer_id) {
        $api = facebook_for_woocommerce()->get_api();
        $catalog_id = $this->integration->get_product_catalog_id();
        $retry_count = 0;

        do {
            try {
                $response = $api->read_product_set_item($catalog_id, $retailer_id);
                $product_set_id = $response->get_product_set_id();

                if ($product_set_id) {
                    $this->debug(
                        $retry_count === 0
                            ? "Successfully fetched product set for retailer_id: {$retailer_id}"
                            : "Successfully fetched product set on retry #" . ($retry_count + 1)
                    );

                    // Get full product set data from response.
                    // The API filters by retailer_id, but retailer_id (term_taxonomy_id) can be
                    // recycled across test runs, so multiple product sets may share the same
                    // retailer_id. Prefer the one matching the current category name; fall back
                    // to the first result so "update name" tests still work when the name hasn't
                    // propagated yet.
                    $response_data = $response->response_data["data"];
                    $product_set_data = null;
                    if (is_array($response_data) && !empty($response_data)) {
                        // Try name match first
                        if (isset($this->category->name)) {
                            foreach ($response_data as $item) {
                                if (isset($item['name']) && $item['name'] === $this->category->name) {
                                    $product_set_data = $item;
                                    break;
                                }
                            }
                        }
                        // Fall back to first result
                        if (!$product_set_data) {
                            $product_set_data = $response_data[0];
                        }
                    }

                    if ($product_set_data) {
                        // Parse metadata if it's a JSON string
                        $metadata = [];
                        if (isset($product_set_data['metadata'])) {
                            $metadata = is_string($product_set_data['metadata'])
                                ? json_decode($product_set_data['metadata'], true)
                                : $product_set_data['metadata'];
                        }

                        return [
                            'id' => $product_set_data['id'] ?? $product_set_id,
                            'name' => $product_set_data['name'] ?? '',
                            'retailer_id' => $product_set_data['retailer_id'] ?? '',
                            'found' => true
                        ];
                    }
                }

            } catch (Exception $e) {
                $this->debug("Facebook API error for retailer_id {$retailer_id}: " . $e->getMessage());
            }

            $retry_count++;
            if ($retry_count < $this->max_retries) {
                $backoff_seconds = pow(2, $retry_count);
                $this->debug("Retry attempt #{$retry_count} of {$this->max_retries} for retailer_id: {$retailer_id} (waiting {$backoff_seconds}s)");
                sleep($backoff_seconds);
            }

        } while ($retry_count < $this->max_retries);

        $this->debug("No product set found for retailer_id: {$retailer_id} after {$this->max_retries} retries");
        return ['found' => false];
    }

    /**
     * Check if category is synced as product set
     */
    private function checkSyncStatus($woo_data, $fb_data) {
        if ($fb_data['found']) {
            $this->result['sync_status'] = 'synced';
            $this->result['facebook_product_set_id'] = $fb_data['id'];
            $this->debug("Category is synced as product set: {$fb_data['id']}");
        } else {
            $this->result['sync_status'] = 'not_synced';
            $this->debug("Category is NOT synced to Facebook");
        }
    }

    /**
     * Compare fields between WooCommerce category and Facebook product set
     */
    private function compareFields($woo_data, $fb_data) {
        $mismatches = [];

        foreach (self::FIELD_MAPPINGS as $woo_field => $fb_field) {
            $woo_value = $woo_data[$woo_field] ?? '';
            $fb_value = $fb_data[$fb_field] ?? '';

            $normalized_woo = $this->normalizeValue($woo_value);
            $normalized_fb = $this->normalizeValue($fb_value);

            if ($normalized_woo !== $normalized_fb) {
                $this->debug("MISMATCH {$woo_field}: WooCommerce='{$woo_value}' vs Facebook='{$fb_value}'");

                $mismatches["{$this->category_id}_{$woo_field}"] = [
                    'field' => $woo_field,
                    'woocommerce_value' => $woo_value,
                    'facebook_value' => $fb_value
                ];
            }
        }

        $this->result['mismatches'] = $mismatches;
        $this->debug("Compared fields, found " . count($mismatches) . " mismatches");
    }

    /**
     * Normalize values for comparison
     */
    private function normalizeValue($value) {
        // Handle WP_Error objects
        if (is_wp_error($value)) {
            return '';
        }
        return trim(strtolower((string)$value));
    }

    /**
     * Get JSON result
     */
    public function getJsonResult() {
        return json_encode($this->result, JSON_PRETTY_PRINT);
    }
}

// Main execution when called directly.
// Skip auto-run when included from WP-CLI eval context.
if (php_sapi_name() === 'cli' && !defined('E2E_SYNC_VALIDATOR_SKIP_MAIN')) {
    try {
        $cli_argv = isset($argv) && is_array($argv) ? $argv : (isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : []);

        // Check if --type=category flag is present
        $is_category = in_array('--type=category', $cli_argv, true);

        if ($is_category) {
            // Category validation mode
            // Usage: php e2e-facebook-sync-validator.php --type=category <category_id> [wait_seconds] [max_retries]

            $category_id = isset($cli_argv[2]) ? (int)$cli_argv[2] : null;
            $wait_seconds = isset($cli_argv[3]) ? (int)$cli_argv[3] : 10;
            $max_retries = isset($cli_argv[4]) ? (int)$cli_argv[4] : 6;

            if (!$category_id) {
                echo json_encode(['success' => false, 'error' => 'Category ID required']);
                exit(1);
            }

            $validator = new CategorySyncValidator($category_id, $wait_seconds, $max_retries);

        } else {
            // Product validation mode (existing - unchanged)
            // Usage: php e2e-facebook-sync-validator.php <product_id> [wait_seconds] [max_retries]

            $product_id = isset($cli_argv[1]) ? (int)$cli_argv[1] : null;
            $wait_seconds = isset($cli_argv[2]) ? (int)$cli_argv[2] : 10;
            $max_retries = isset($cli_argv[3]) ? (int)$cli_argv[3] : 6;

            if (!$product_id) {
                echo json_encode(['success' => false, 'error' => 'Product ID required']);
                exit(1);
            }

            $validator = new FacebookSyncValidator($product_id, $wait_seconds, $max_retries);
        }

        $result = $validator->validate();
        echo $validator->getJsonResult();

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => ["Exception: " . $e->getMessage()]
        ]);
        exit(1);
    }
}
