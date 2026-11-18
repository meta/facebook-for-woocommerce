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

		$plugin = $this->createMock( WC_Facebookcommerce::class );
		// Lets a test run the deferred save without sending the product anywhere.
		$plugin->method( 'get_product_sync_validator' )->willThrowException( new Exception( 'Not sent in tests' ) );
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
	 * A product switched to "Sync and show" with a new Facebook price is never shown at the old price:
	 * the price is saved together with the visibility.
	 */
	public function test_facebook_price_is_saved_with_the_visibility() {
		$product = $this->hidden_simple_product();
		( new WC_Facebook_Product( $product->get_id() ) )->set_price( '20' );

		$_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ] = '30';
		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_SHOW );
		unset( $_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ] );

		$this->assertSame( 'yes', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
		$this->assertSame( '30', get_post_meta( $product->get_id(), WC_Facebook_Product::FB_PRODUCT_PRICE, true ) );
		$this->assertSame( 3000, ( new WC_Facebook_Product( $product->get_id() ) )->get_fb_price() );
	}

	/**
	 * The other fields of the Facebook tab are saved with the Update too.
	 */
	public function test_facebook_tab_fields_are_saved_with_the_update() {
		$product = $this->hidden_simple_product();

		$_POST[ WC_Facebook_Product::FB_PRODUCT_DESCRIPTION ] = 'Changed on the form';
		$_POST[ WC_Facebook_Product::FB_BRAND ]               = 'Changed brand';
		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_AND_HIDE );
		unset( $_POST[ WC_Facebook_Product::FB_PRODUCT_DESCRIPTION ], $_POST[ WC_Facebook_Product::FB_BRAND ] );

		$this->assertSame( 'Changed on the form', get_post_meta( $product->get_id(), WC_Facebook_Product::FB_PRODUCT_DESCRIPTION, true ) );
		$this->assertSame( 'Changed brand', get_post_meta( $product->get_id(), WC_Facebook_Product::FB_BRAND, true ) );
	}

	/**
	 * Turning sync off is still left entirely to the deferred save: nothing from the tab is saved
	 * with the Update.
	 */
	public function test_nothing_is_saved_when_sync_is_turned_off() {
		$product = $this->hidden_simple_product();
		( new WC_Facebook_Product( $product->get_id() ) )->set_price( '20' );
		$before = get_post_meta( $product->get_id() );

		$_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ] = '30';
		$this->save_product_form( $product, Admin::SYNC_MODE_SYNC_DISABLED );
		unset( $_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ] );

		$this->assertSame( $before, get_post_meta( $product->get_id() ) );
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

	/**
	 * Saves the product edit form twice, a second apart so each save is queued under its own key, and
	 * returns the queued deferred saves, oldest first.
	 *
	 * @param WC_Product $product Product being saved.
	 * @param array      $first   Form data of the first save.
	 * @param array      $second  Form data of the second save.
	 * @return array
	 */
	private function save_product_form_twice( WC_Product $product, array $first, array $second ): array {
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

		foreach ( array( $first, $second ) as $i => $form ) {
			if ( $i > 0 ) {
				sleep( 1 );
			}
			$_POST = $form;
			$this->integration->schedule_product_sync( $product->get_id() );
			$_POST = array();
		}

		$this->assertCount( 2, $scheduled );
		$this->assertNotSame( $scheduled[0]->args[0], $scheduled[1]->args[0] );

		return $scheduled;
	}

	private function run_deferred_save( $event ): void {
		do_action( $event->hook, ...$event->args );
		$_POST = array();
	}

	/**
	 * An older deferred save that runs after a newer one does not put back the older choice.
	 */
	public function test_older_deferred_save_does_not_undo_a_newer_save() {
		$product   = $this->hidden_simple_product();
		$scheduled = $this->save_product_form_twice(
			$product,
			array( 'wc_facebook_sync_mode' => Admin::SYNC_MODE_SYNC_AND_SHOW ),
			array( 'wc_facebook_sync_mode' => Admin::SYNC_MODE_SYNC_AND_HIDE )
		);

		$this->run_deferred_save( $scheduled[1] );
		$this->run_deferred_save( $scheduled[0] );

		$this->assertSame( 'no', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * The latest deferred save is still applied, whichever order the saves run in.
	 */
	public function test_latest_deferred_save_is_applied() {
		$product   = $this->hidden_simple_product();
		$scheduled = $this->save_product_form_twice(
			$product,
			array( 'wc_facebook_sync_mode' => Admin::SYNC_MODE_SYNC_AND_SHOW ),
			// Turning sync off is only saved by the deferred save.
			array( 'wc_facebook_sync_mode' => Admin::SYNC_MODE_SYNC_DISABLED )
		);
		$this->assertSame( 'yes', get_post_meta( $product->get_id(), Products::get_product_sync_meta_key(), true ) );

		$this->run_deferred_save( $scheduled[0] );
		$this->run_deferred_save( $scheduled[1] );

		$this->assertSame( 'no', get_post_meta( $product->get_id(), Products::get_product_sync_meta_key(), true ) );
	}

	/**
	 * Products an earlier save marked for deletion from the catalog are still deleted when that save
	 * is skipped for a newer one: the form fills the list only in the edit that turned sync off.
	 */
	public function test_removals_from_an_older_save_are_kept() {
		$product   = WC_Helper_Product::create_variation_product();
		$children  = $product->get_children();
		$scheduled = $this->save_product_form_twice(
			$product,
			array(
				'wc_facebook_sync_mode'                  => Admin::SYNC_MODE_SYNC_AND_SHOW,
				WC_Facebook_Product::FB_REMOVE_FROM_SYNC => (string) $children[0],
			),
			array(
				'wc_facebook_sync_mode'                  => Admin::SYNC_MODE_SYNC_AND_SHOW,
				WC_Facebook_Product::FB_REMOVE_FROM_SYNC => (string) $children[1],
			)
		);

		$latest = get_transient( $scheduled[1]->args[0] );
		$this->assertSame( $children[0] . ',' . $children[1], $latest['post_data'][ WC_Facebook_Product::FB_REMOVE_FROM_SYNC ] );
	}
}
