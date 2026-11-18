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
use WooCommerce\Facebook\Products;

/**
 * The Facebook sync mode chosen on the product edit screen is saved with the product, not only by the
 * deferred sync, so it holds even when that sync runs late or not at all.
 */
class ScheduleProductSyncSyncModeTest extends \WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var WC_Facebookcommerce_Integration */
	private $integration;

	public function setUp(): void {
		parent::setUp();

		$plugin            = $this->createMock( WC_Facebookcommerce::class );
		$this->integration = new WC_Facebookcommerce_Integration( $plugin );

		// Scheduling succeeds but the event never runs, as on a store whose cron has not got to it yet.
		$this->add_filter_with_safe_teardown( 'pre_schedule_event', '__return_true' );
	}

	public function tearDown(): void {
		unset( $_POST['wc_facebook_sync_mode'] );

		parent::tearDown();
	}

	/**
	 * Saves the product edit form with the given sync mode, as WooCommerce does on Update.
	 *
	 * @param WC_Product $product   Product being saved.
	 * @param string     $sync_mode Sync mode chosen on the Facebook tab.
	 */
	private function save_product_form( WC_Product $product, string $sync_mode ): void {
		$_POST['wc_facebook_sync_mode'] = $sync_mode;

		$this->integration->schedule_product_sync( $product->get_id() );
	}

	private function hidden_simple_product( array $props = array() ): WC_Product {
		$product = WC_Helper_Product::create_simple_product( true, $props );
		$product->update_meta_data( Products::VISIBILITY_META_KEY, 'no' );
		$product->save_meta_data();

		return $product;
	}

	/**
	 * Switching a hidden simple product to "Sync and show" makes it visible right away (issue #4123).
	 */
	public function test_sync_and_show_is_saved_with_the_product() {
		$product = $this->hidden_simple_product();

		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_SHOW );

		$this->assertSame( 'yes', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
		$this->assertTrue( Products::is_product_visible( wc_get_product( $product->get_id() ) ) );
	}

	/**
	 * "Sync and hide" is saved with the product.
	 */
	public function test_sync_and_hide_is_saved_with_the_product() {
		$product = WC_Helper_Product::create_simple_product();

		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_HIDE );

		$this->assertSame( 'no', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
		$this->assertSame( 'yes', get_post_meta( $product->get_id(), Products::get_product_sync_meta_key(), true ) );
	}

	/**
	 * Virtual products are still kept hidden when "Sync and show" is chosen.
	 */
	public function test_virtual_product_stays_hidden_on_sync_and_show() {
		$product = $this->hidden_simple_product( array( 'virtual' => true ) );

		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_SHOW );

		$this->assertSame( 'no', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Saving the form leaves no Facebook settings on the product besides the sync mode: the rest is
	 * still saved by the deferred save.
	 */
	public function test_only_the_sync_mode_is_saved_before_the_deferred_save() {
		$product = $this->hidden_simple_product();
		$before  = get_post_meta( $product->get_id() );

		$_POST[ WC_Facebook_Product::FB_PRODUCT_DESCRIPTION ] = 'Changed on the form';
		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_SHOW );
		unset( $_POST[ WC_Facebook_Product::FB_PRODUCT_DESCRIPTION ] );

		// The sync mode itself, and the change time the plugin records whenever visibility changes.
		$expected_changes = array( Products::VISIBILITY_META_KEY, Products::get_product_sync_meta_key(), '_last_change_time' );
		$after            = get_post_meta( $product->get_id() );
		foreach ( $expected_changes as $meta_key ) {
			unset( $before[ $meta_key ], $after[ $meta_key ] );
		}

		$this->assertSame( $before, $after );
	}

	/**
	 * Variable products keep the visibility saved on each variation; the product-level mode is left
	 * to the deferred save, as before.
	 */
	public function test_variable_product_variations_keep_their_visibility() {
		$product   = WC_Helper_Product::create_variation_product();
		$variation = wc_get_product( $product->get_children()[0] );
		$variation->update_meta_data( Products::VISIBILITY_META_KEY, 'no' );
		$variation->save_meta_data();

		$parent_meta_before = get_post_meta( $product->get_id() );

		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_SHOW );

		$this->assertSame( 'no', get_post_meta( $variation->get_id(), Products::VISIBILITY_META_KEY, true ) );
		$this->assertSame( $parent_meta_before, get_post_meta( $product->get_id() ) );
	}

	/**
	 * The deferred save is still scheduled with the submitted form, as before.
	 */
	public function test_deferred_save_is_still_scheduled() {
		$product   = $this->hidden_simple_product();
		$scheduled = array();
		$this->add_filter_with_safe_teardown(
			'pre_schedule_event',
			function ( $pre, $event ) use ( &$scheduled ) {
				$scheduled[] = $event;
				return true;
			},
			5,
			2
		);

		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_SHOW );

		$this->assertCount( 1, $scheduled );
		$this->assertSame( 'wc_facebook_async_sync', $scheduled[0]->hook );

		$sync_data = get_transient( $scheduled[0]->args[0] );
		$this->assertSame( $product->get_id(), $sync_data['product_id'] );
		$this->assertSame( Admin::SYNC_MODE_SYNC_AND_SHOW, $sync_data['post_data']['wc_facebook_sync_mode'] );
	}
}
