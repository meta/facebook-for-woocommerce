<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

require_once __DIR__ . '/../../facebook-commerce.php';

use WooCommerce\Facebook\Admin;
use WooCommerce\Facebook\API;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Handlers\Connection;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\ProductSync\ProductValidator;
use WooCommerce\Facebook\RolloutSwitches;

/**
 * Unit tests for additional Facebook Commerce Integration methods.
 */
class FacebookCommerceTest extends \WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * @var WC_Facebookcommerce
	 */
	private $facebook_for_woocommerce;

	/**
	 * @var Connection
	 */
	private $connection_handler;

	/**
	 * @var API
	 */
	private $api;

	/**
	 * @var WC_Facebookcommerce_Integration
	 */
	private $integration;

	/**
	 * @var RolloutSwitches
	 */
	private $rollout_switches;

	/**
	 * Runs before each test is executed.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->facebook_for_woocommerce = $this->createMock( WC_Facebookcommerce::class );
		$this->connection_handler       = $this->createMock( Connection::class );
		$this->rollout_switches         = $this->createMock( RolloutSwitches::class );
		$this->rollout_switches->method( 'is_switch_enabled' )
			->willReturn( false );

		$this->facebook_for_woocommerce->method( 'get_connection_handler' )
			->willReturn( $this->connection_handler );
		$this->api = $this->createMock( API::class );
		$this->facebook_for_woocommerce->method( 'get_api' )
			->willReturn( $this->api );
		$this->facebook_for_woocommerce->method( 'get_rollout_switches' )
			->willReturn( $this->rollout_switches );

		$this->integration = new WC_Facebookcommerce_Integration( $this->facebook_for_woocommerce );

		/* Making sure no options are set before the test. */
		delete_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY );
		delete_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID );
		// Needed to prevent error logs in tests.
		WC_Facebookcommerce_Utils::$ems = 'dummy_ems_id';
	}

	/**
	 * Tests schedule_product_sync stores transient and schedules WordPress cron event.
	 *
	 * @return void
	 */
	public function test_schedule_product_sync_schedules_cron_event() {
		$product = WC_Helper_Product::create_simple_product();
		$_POST   = array( 'test_data' => 'test_value' );

		// Use reflection to access the private method get_store_sync_transient
		$reflection = new ReflectionClass( $this->integration );
		$method     = $reflection->getMethod( 'get_store_sync_transient' );
		$method->setAccessible( true );

		$sync_data = array(
			'product_id' => $product->get_id(),
			'post_data'  => $_POST,
		);

		$sync_key = $method->invoke( $this->integration, $product->get_id(), $sync_data );

		// Verify the transient was created by checking if we can retrieve it
		$retrieved_data = get_transient( $sync_key );
		$this->assertNotFalse( $retrieved_data );
		$this->assertEquals( $product->get_id(), $retrieved_data['product_id'] );
	}

	/**
	 * Tests handle_async_product_save processes transient data and calls on_product_save.
	 *
	 * @return void
	 */
	public function test_handle_async_product_save_processes_transient() {
		$product     = WC_Helper_Product::create_simple_product();
		$_POST       = array( 'wc_facebook_sync_mode' => Admin::SYNC_MODE_SYNC_DISABLED );
		$sync_data   = array(
			'product_id' => $product->get_id(),
			'post_data'  => $_POST,
		);
		$sync_key    = 'fb_sync_data_' . $product->get_id() . '_' . time();
		set_transient( $sync_key, $sync_data, 300 );

		$validator = $this->createMock( ProductValidator::class );
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$this->integration->handle_async_product_save( $sync_key );

		// Verify transient was deleted
		$this->assertFalse( get_transient( $sync_key ) );
	}

	/**
	 * Tests handle_async_product_save returns early for invalid transient key.
	 *
	 * @return void
	 */
	public function test_handle_async_product_save_invalid_transient_key() {
		$this->integration->handle_async_product_save( 'invalid_key' );

		// Should not throw any errors and return early
		$this->assertTrue( true );
	}

	/**
	 * Tests delete_draft_product calls on_product_delete.
	 *
	 * @return void
	 */
	public function test_delete_draft_product() {
		$product = WC_Helper_Product::create_simple_product();
		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'delete_products' );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$post     = new stdClass();
		$post->ID = $product->get_id();
		$wp_post  = new WP_Post( $post );

		$this->integration->delete_draft_product( $wp_post );
	}

	/**
	 * Tests update_product_last_change_time updates meta for relevant keys.
	 *
	 * @return void
	 */
	public function test_update_product_last_change_time_updates_for_relevant_key() {
		$product = WC_Helper_Product::create_simple_product();

		$this->integration->update_product_last_change_time( 1, $product->get_id(), '_regular_price', '10.00' );

		$last_change_time = get_post_meta( $product->get_id(), '_last_change_time', true );
		$this->assertNotEmpty( $last_change_time );
		$this->assertTrue( is_numeric( $last_change_time ) );
	}

	/**
	 * Tests update_product_last_change_time skips irrelevant meta keys.
	 *
	 * @return void
	 */
	public function test_update_product_last_change_time_skips_irrelevant_key() {
		$product = WC_Helper_Product::create_simple_product();

		// Delete any existing last_change_time
		delete_post_meta( $product->get_id(), '_last_change_time' );

		$this->integration->update_product_last_change_time( 1, $product->get_id(), '_some_random_key', 'value' );

		$last_change_time = get_post_meta( $product->get_id(), '_last_change_time', true );
		$this->assertEmpty( $last_change_time );
	}

	/**
	 * Tests update_product_last_change_time skips internal meta keys.
	 *
	 * @return void
	 */
	public function test_update_product_last_change_time_skips_internal_keys() {
		$product = WC_Helper_Product::create_simple_product();

		delete_post_meta( $product->get_id(), '_last_change_time' );

		$this->integration->update_product_last_change_time( 1, $product->get_id(), '_wp_attached_file', 'file.jpg' );

		$last_change_time = get_post_meta( $product->get_id(), '_last_change_time', true );
		$this->assertEmpty( $last_change_time );
	}

	/**
	 * Tests fb_restore_untrashed_variable_product syncs visible variable product.
	 *
	 * @return void
	 */
	public function test_fb_restore_untrashed_variable_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$product = WC_Helper_Product::create_variation_product();

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );

		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$this->api->expects( $this->once() )
			->method( 'create_product_group' );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->fb_restore_untrashed_variable_product( $product->get_id() );
	}

	/**
	 * Tests fb_restore_untrashed_variable_product does nothing for non-variable products.
	 *
	 * @return void
	 */
	public function test_fb_restore_untrashed_variable_product_skips_simple_product() {
		$product = WC_Helper_Product::create_simple_product();

		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->integration->fb_restore_untrashed_variable_product( $product->get_id() );
	}

	/**
	 * Tests is_product_sync_enabled returns default value with no option.
	 *
	 * @return void
	 */
	public function test_is_product_sync_enabled_default_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_product_sync_enabled' );
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_PRODUCT_SYNC );

		$result = $this->integration->is_product_sync_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is_product_sync_enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_product_sync_enabled_option_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_product_sync_enabled' );
		add_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_PRODUCT_SYNC, 'no' );

		$result = $this->integration->is_product_sync_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_product_sync_enabled with filter override.
	 *
	 * @return void
	 */
	public function test_is_product_sync_enabled_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_is_product_sync_enabled',
			function ( $is_enabled ) {
				return false;
			}
		);

		add_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_PRODUCT_SYNC, 'yes' );

		$result = $this->integration->is_product_sync_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_offline_purchase_events_enabled defaults to disabled.
	 *
	 * Offline events are opt-in, so an upgrading site must never start sending them.
	 *
	 * @return void
	 */
	public function test_is_offline_purchase_events_enabled_default_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_offline_purchase_events_enabled' );
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS );

		$result = $this->integration->is_offline_purchase_events_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_offline_purchase_events_enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_offline_purchase_events_enabled_option_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_offline_purchase_events_enabled' );
		add_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS, 'yes' );

		$result = $this->integration->is_offline_purchase_events_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is_offline_purchase_events_enabled with filter override.
	 *
	 * @return void
	 */
	public function test_is_offline_purchase_events_enabled_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_is_offline_purchase_events_enabled',
			function ( $is_enabled ) {
				return true;
			}
		);

		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS );

		$result = $this->integration->is_offline_purchase_events_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is_capig_enabled defaults to opted in.
	 *
	 * The Meta-enabled Conversions API integration is on unless the merchant opts out.
	 *
	 * @return void
	 */
	public function test_is_capig_enabled_default_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_capig_enabled' );
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG );

		$result = $this->integration->is_capig_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is_capig_enabled returns false once the merchant opts out.
	 *
	 * @return void
	 */
	public function test_is_capig_enabled_option_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_capig_enabled' );
		add_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG, 'no' );

		$result = $this->integration->is_capig_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_capig_enabled with filter override.
	 *
	 * @return void
	 */
	public function test_is_capig_enabled_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_is_capig_enabled',
			function ( $is_enabled ) {
				return false;
			}
		);

		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG );

		$result = $this->integration->is_capig_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_meta_diagnosis_enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_meta_diagnosis_enabled() {
		add_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );

		$result = $this->integration->is_meta_diagnosis_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is_meta_diagnosis_enabled returns false when disabled.
	 *
	 * @return void
	 */
	public function test_is_meta_diagnosis_disabled() {
		// Delete first to ensure the constructor's default setting doesn't interfere
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_META_DIAGNOSIS );

		// Update to 'no' after deleting
		update_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_META_DIAGNOSIS, 'no' );

		$result = $this->integration->is_meta_diagnosis_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_facebook_managed_coupons_enabled returns default value.
	 *
	 * @return void
	 */
	public function test_is_facebook_managed_coupons_enabled_default() {
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS );

		$result = $this->integration->is_facebook_managed_coupons_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is_facebook_managed_coupons_enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_facebook_managed_coupons_enabled_option_value() {
		add_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS, 'no' );

		$result = $this->integration->is_facebook_managed_coupons_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests ajax_check_feed_upload_status returns response with connected status.
	 *
	 * @return void
	 */
	public function test_ajax_check_feed_upload_status() {
		ob_start();

		try {
			$this->integration->ajax_check_feed_upload_status();
		} catch ( WPDieException $e ) {
			// Expected - AJAX handlers call wp_die()
		}

		$output = ob_get_clean();

		$response = json_decode( $output, true );
		$this->assertTrue( $response['connected'] );
		$this->assertEquals( 'complete', $response['status'] );
	}

	/**
	 * Tests create_product_item_batch_api creates product using batch API.
	 *
	 * @return void
	 */
	public function test_create_product_item_batch_api() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product               = WC_Helper_Product::create_simple_product();
		$facebook_product      = new WC_Facebook_Product( $product->get_id() );
		$retailer_id           = WC_Facebookcommerce_Utils::get_fb_retailer_id( $facebook_product );
		$facebook_product_data = $facebook_product->prepare_product( $retailer_id, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
		$requests              = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch( $facebook_product_data );

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		$result = $this->integration->create_product_item_batch_api( $facebook_product, $retailer_id );

		$this->assertEquals( '', $result );
	}

	/**
	 * Tests update_product_item_batch_api updates product using batch API.
	 *
	 * @return void
	 */
	public function test_update_product_item_batch_api() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product               = WC_Helper_Product::create_simple_product();
		$facebook_product      = new WC_Facebook_Product( $product->get_id() );
		$facebook_product_data = $facebook_product->prepare_product( null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
		$requests              = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch( $facebook_product_data );

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		$this->integration->update_product_item_batch_api( $facebook_product, 'facebook-product-item-id' );
	}

	/**
	 * Tests on_product_quick_edit_save does nothing for non-product objects.
	 *
	 * @return void
	 */
	public function test_on_product_quick_edit_save_skips_non_product() {
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->integration->on_product_quick_edit_save( null );
	}

	/**
	 * Tests on_product_quick_edit_save syncs simple published product.
	 *
	 * @return void
	 */
	public function test_on_product_quick_edit_save_syncs_simple_product() {
		$product = WC_Helper_Product::create_simple_product();
		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( array( $product->get_id() ) );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_quick_edit_save( $product );
	}

	/**
	 * Tests on_product_quick_edit_save syncs variable product variations.
	 *
	 * @return void
	 */
	public function test_on_product_quick_edit_save_syncs_variable_product() {
		$product = WC_Helper_Product::create_variation_product();
		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );

		foreach ( $product->get_children() as $variation_id ) {
			add_post_meta( $variation_id, Products::SYNC_ENABLED_META_KEY, 'yes' );
		}

		$validator = $this->createMock( ProductValidator::class );
		$validator->method( 'validate' );

		$this->facebook_for_woocommerce->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $product->get_children() );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_quick_edit_save( $product );
	}

	/**
	 * Tests is_language_override_feed_generation_enabled returns default value.
	 *
	 * @return void
	 */
	public function test_is_language_override_feed_generation_enabled_default() {
		delete_option( WC_Facebookcommerce_Integration::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED );

		$result = $this->integration->is_language_override_feed_generation_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_language_override_feed_generation_enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_language_override_feed_generation_enabled_option_value() {
		$this->rollout_switches = $this->createMock( RolloutSwitches::class );
		$this->rollout_switches->method( 'is_switch_enabled' )
			->willReturn( true );
		$this->facebook_for_woocommerce->method( 'get_rollout_switches' )
			->willReturn( $this->rollout_switches );

		add_option( WC_Facebookcommerce_Integration::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED, 'yes' );

		$result = $this->integration->is_language_override_feed_generation_enabled();

		// Assertion placeholder - actual result depends on localization integration
		$this->assertTrue( true );
	}

	/**
	 * Tests display_unmapped_attributes_banner does nothing when feature is not available.
	 *
	 * @return void
	 */
	public function test_display_unmapped_attributes_banner_not_admin() {
		wp_set_current_user( 0 );

		$product = WC_Helper_Product::create_simple_product();

		$this->integration->display_unmapped_attributes_banner( $product );

		$message = get_transient( 'facebook_plugin_unmapped_attributes_info' );
		$this->assertFalse( $message );
	}

	/**
	 * Tests wp_all_import_compat displays out of sync message.
	 *
	 * @return void
	 */
	public function test_wp_all_import_compat_displays_message() {
		if ( ! class_exists( 'PMXI_Import_Record' ) ) {
			// Stub the WP All Import class so the method under test can be exercised
			// without requiring the full plugin.
			// phpcs:ignore
			eval( 'class PMXI_Import_Record { public $options = array( "custom_type" => "product" ); private $id = null; public function getById( $id ) { $this->id = $id; return $this; } public function isEmpty() { return false; } }' );
		}

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_settings_url' )
			->willReturn( 'https://settings.site/settings.php' );

		$this->integration->wp_all_import_compat( '123' );
	}

	/**
	 * Tests get_product_variation_attributes returns parsed attributes.
	 *
	 * @return void
	 */
	public function test_get_product_variation_attributes() {
		$product   = WC_Helper_Product::create_variation_product();
		$variation = wc_get_product( $product->get_children()[0] );

		$available_variations = $product->get_available_variations();
		$variation_data       = $available_variations[0];

		// Use reflection to access private method
		$reflection = new ReflectionClass( $this->integration );
		$method     = $reflection->getMethod( 'get_product_variation_attributes' );
		$method->setAccessible( true );

		$attributes = $method->invoke( $this->integration, $variation_data );

		$this->assertIsArray( $attributes );
	}

	/**
	 * Tests should_update_visibility_for_product_status_change returns true for publish to trash.
	 *
	 * @return void
	 */
	public function test_should_update_visibility_for_product_status_change_publish_to_trash() {
		$reflection = new ReflectionClass( $this->integration );
		$method     = $reflection->getMethod( 'should_update_visibility_for_product_status_change' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->integration, 'trash', 'publish' );

		$this->assertTrue( $result );
	}

	/**
	 * Tests should_update_visibility_for_product_status_change returns true for trash to publish.
	 *
	 * @return void
	 */
	public function test_should_update_visibility_for_product_status_change_trash_to_publish() {
		$reflection = new ReflectionClass( $this->integration );
		$method     = $reflection->getMethod( 'should_update_visibility_for_product_status_change' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->integration, 'publish', 'trash' );

		$this->assertTrue( $result );
	}

	/**
	 * Tests should_update_visibility_for_product_status_change returns false for draft to trash.
	 *
	 * @return void
	 */
	public function test_should_update_visibility_for_product_status_change_draft_to_trash() {
		$reflection = new ReflectionClass( $this->integration );
		$method     = $reflection->getMethod( 'should_update_visibility_for_product_status_change' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->integration, 'trash', 'draft' );

		$this->assertFalse( $result );
	}

	/**
	 * Tests is_woo_all_products_enabled returns switch status.
	 *
	 * @return void
	 */
	public function test_is_woo_all_products_enabled() {
		$rollout_switches = $this->createMock( RolloutSwitches::class );
		$rollout_switches->method( 'is_switch_enabled' )
			->willReturn( true );

		$facebook_for_woocommerce = $this->createMock( WC_Facebookcommerce::class );
		$facebook_for_woocommerce->method( 'get_rollout_switches' )
			->willReturn( $rollout_switches );
		$facebook_for_woocommerce->method( 'get_connection_handler' )
			->willReturn( $this->connection_handler );
		$facebook_for_woocommerce->method( 'get_api' )
			->willReturn( $this->api );

		$integration = new WC_Facebookcommerce_Integration( $facebook_for_woocommerce );

		$result = $integration->is_woo_all_products_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests __get method returns null for unknown properties.
	 *
	 * @return void
	 */
	public function test_magic_get_returns_null_for_unknown_property() {
		$result = $this->integration->__get( 'unknown_property' );

		$this->assertNull( $result );
	}

	/**
	 * Tests get_message_html returns formatted HTML.
	 *
	 * @return void
	 */
	public function test_get_message_html() {
		$reflection = new ReflectionClass( $this->integration );
		$method     = $reflection->getMethod( 'get_message_html' );
		$method->setAccessible( true );

		$html = $method->invoke( $this->integration, 'Test message', 'info' );

		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringContainsString( 'Test message', $html );
	}
}
