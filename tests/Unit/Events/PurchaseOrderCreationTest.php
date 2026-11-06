<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Events;

use WC_Facebookcommerce_EventsTracker;
use WooCommerce\Facebook\Events\AAMSettings;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;

/**
 * Exercises the real order-save lifecycle rather than replaying new-order hooks
 * after the line items have already been saved.
 *
 * @covers WC_Facebookcommerce_EventsTracker
 */
class PurchaseOrderCreationTest extends AbstractWPUnitTestWithSafeFiltering {

	/** @var WC_Facebookcommerce_EventsTracker */
	private $tracker;

	/** @var string|null */
	private $user_agent;

	public function setUp(): void {
		parent::setUp();
		$this->user_agent           = $_SERVER['HTTP_USER_AGENT'] ?? null;
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0.0.0 Safari/537.36';
		wp_set_current_user( 0 );
		$this->add_filter_with_safe_teardown( 'facebook_for_woocommerce_integration_pixel_enabled', '__return_true' );
		$this->add_filter_with_safe_teardown(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => '{"events_received":1}',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
				);
			}
		);
		$this->tracker = new WC_Facebookcommerce_EventsTracker(
			array(),
			new AAMSettings( array( 'pixelId' => 'test_pixel_123' ) )
		);
	}

	public function tearDown(): void {
		if ( null === $this->user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $this->user_agent;
		}
		parent::tearDown();
	}

	/** @return array */
	public function order_storage(): array {
		return array( 'posts' => array( 'no' ), 'hpos' => array( 'yes' ) );
	}

	/**
	 * @dataProvider order_storage
	 * @param string $hpos Whether to enable HPOS.
	 */
	public function test_new_order_uses_items_before_they_are_persisted( string $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$this->assertSame( 'yes' === $hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		$order  = $this->build_order();
		$before = null;
		$after  = null;
		$this->add_filter_with_safe_teardown(
			'woocommerce_new_order',
			function ( $id ) use ( &$before ) {
				$before = count( wc_get_order( $id )->get_items() );
			},
			1
		);
		$this->add_filter_with_safe_teardown(
			'woocommerce_new_order',
			function ( $id ) use ( &$after ) {
				$after = count( wc_get_order( $id )->get_items() );
			},
			99
		);

		$order->save();

		$this->assertSame( 0, $before );
		$this->assertSame( 0, $after, 'Tracking must not recursively save the new order and its items.' );
		$this->assertCount( 2, wc_get_order( $order->get_id() )->get_items() );
		$this->assert_complete_purchase( $order );

		$event_id = wc_get_order( $order->get_id() )->get_meta( '_meta_event_id' );
		$this->assertNotEmpty( $event_id );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ) );

		// The later ID-only hooks must keep the same event and not resend CAPI,
		// including after the transient has expired.
		delete_transient( '_wc_' . facebook_for_woocommerce()->get_id() . '_purchase_tracked_' . $order->get_id() . '_server' );
		do_action( 'woocommerce_checkout_update_order_meta', $order->get_id(), array() );
		ob_start();
		try {
			do_action( 'woocommerce_thankyou', $order->get_id() );
			do_action( 'woocommerce_thankyou', $order->get_id() );
		} finally {
			ob_end_clean();
		}
		$this->assertCount( 1, $this->tracker->get_tracked_events() );
		$this->assertSame( $event_id, wc_get_order( $order->get_id() )->get_meta( '_meta_event_id' ) );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_browser' ) );
	}

	/**
	 * With HPOS, a metadata change is followed by a full save() whenever a second has
	 * passed since the order was last modified. In a slow run that happened between
	 * creating the order and tracking it, so saving the tracking metadata during
	 * woocommerce_new_order saved the order and its items early, and the test above
	 * failed now and then.
	 *
	 * The filter forces that condition while woocommerce_new_order runs, so it is
	 * checked every run. It is left alone before the hook: WooCommerce saves the
	 * order's meta itself there, while changes are still pending, and never does a
	 * full save at that point.
	 */
	public function test_new_order_is_not_saved_when_hpos_would_update_the_modified_date(): void {
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		$this->add_filter_with_safe_teardown(
			'woocommerce_orders_table_datastore_should_save_after_meta_change',
			static function ( $should_save ) {
				return $should_save || doing_action( 'woocommerce_new_order' );
			}
		);
		$order = $this->build_order();
		$after = null;
		$this->add_filter_with_safe_teardown(
			'woocommerce_new_order',
			function ( $id ) use ( &$after ) {
				$after = count( wc_get_order( $id )->get_items() );
			},
			99
		);

		$order->save();

		$this->assertSame( 0, $after, 'Tracking must not recursively save the new order and its items.' );
		$this->assertCount( 2, wc_get_order( $order->get_id() )->get_items() );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ) );
		$this->assert_complete_purchase( $order );
	}

	/**
	 * Some plugins fire woocommerce_new_order themselves, outside a save. No save
	 * finishes afterwards, so the tracking metadata is saved at shutdown.
	 */
	public function test_new_order_fired_outside_a_save_saves_tracking_metadata_at_shutdown(): void {
		remove_action( 'woocommerce_new_order', array( $this->tracker, 'inject_purchase_event' ), 10 );
		$order = $this->build_order();
		$order->save();
		add_action( 'woocommerce_new_order', array( $this->tracker, 'inject_purchase_event' ), 10, 2 );
		// Only the callback registered by tracking should run at shutdown here.
		remove_all_actions( 'shutdown' );

		do_action( 'woocommerce_new_order', $order->get_id(), $order );

		$this->assert_complete_purchase( $order );
		$this->assertEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ), 'Saved only once no save follows.' );

		do_action( 'shutdown' );

		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ) );
	}

	public function test_after_save_callback_removes_itself(): void {
		$count_callbacks = static function (): int {
			$hook = $GLOBALS['wp_filter']['woocommerce_after_order_object_save'] ?? null;
			return $hook ? array_sum( array_map( 'count', $hook->callbacks ) ) : 0;
		};
		$before = $count_callbacks();
		$order  = $this->build_order();

		$order->save();

		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ) );
		$this->assertSame( $before, $count_callbacks() );
	}

	/**
	 * Subscription plugins and similar integrations save an empty order before adding its items.
	 * The order is reported at the end of the request, from its persisted state.
	 *
	 * @dataProvider order_storage
	 * @param string $hpos Whether to enable HPOS.
	 */
	public function test_empty_order_is_reported_at_request_end_once_items_exist( string $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->save();
		$order->set_customer_note( 'Still empty' );
		$order->save();

		$this->assertCount( 0, $this->tracker->get_tracked_events() );
		$this->assertFalse( $order->meta_exists( '_meta_purchase_tracked_server' ) );
		$this->assertFalse( $order->meta_exists( '_meta_event_id' ) );

		$source = $this->build_order();
		foreach ( $source->get_items() as $item ) {
			$order->add_item( $item );
		}
		$order->set_currency( $source->get_currency() );
		$order->set_total( $source->get_total() );
		$order->save();
		$this->assertCount( 0, $this->tracker->get_tracked_events(), 'Nothing is reported until the request ends.' );

		$this->tracker->inject_deferred_purchase_events();
		$this->assert_complete_purchase( $order );
		$event_id = wc_get_order( $order->get_id() )->get_meta( '_meta_event_id' );
		$this->assertNotEmpty( $event_id );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ) );

		// A second flush, later saves and the thank-you page keep the same event and do not
		// resend CAPI, including after the transient has expired.
		$this->tracker->inject_deferred_purchase_events();
		delete_transient( '_wc_' . facebook_for_woocommerce()->get_id() . '_purchase_tracked_' . $order->get_id() . '_server' );
		$order->save();
		ob_start();
		try {
			do_action( 'woocommerce_thankyou', $order->get_id() );
		} finally {
			ob_end_clean();
		}
		$this->assertCount( 1, $this->tracker->get_tracked_events() );
		$this->assertSame( $event_id, wc_get_order( $order->get_id() )->get_meta( '_meta_event_id' ) );
	}

	/**
	 * Woo Subscriptions inserts the items directly, and WP Swings saves the order before
	 * calculating totals. The persisted state at request end is what gets reported.
	 *
	 * @dataProvider order_storage
	 * @param string $hpos Whether to enable HPOS.
	 */
	public function test_directly_inserted_items_and_late_totals_are_reported_from_the_persisted_order( string $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->set_currency( 'USD' );
		$order->save();

		foreach ( $this->build_order()->get_items() as $item ) {
			$item->set_order_id( $order->get_id() );
			$item->save();
		}

		// An intermediate save with items but no totals yet, as WP Swings does through its meta helper.
		$intermediate = wc_get_order( $order->get_id() );
		$intermediate->update_meta_data( '_qa_renewal', 'yes' );
		$intermediate->save();
		$this->assertSame( '0.00', $intermediate->get_total() );

		$fresh = wc_get_order( $order->get_id() );
		$fresh->calculate_totals();
		$fresh->save();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );

		$this->tracker->inject_deferred_purchase_events();
		$this->assert_complete_purchase( $fresh );
		$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( '_meta_purchase_tracked_server' ) );
	}

	/**
	 * @dataProvider purchase_statuses
	 * @param string $status Order status.
	 * @param int    $count Expected events.
	 */
	public function test_deferred_order_keeps_existing_status_eligibility( string $status, int $count ): void {
		$order = new \WC_Order();
		$order->set_status( $status );
		$order->save();

		foreach ( $this->build_order()->get_items() as $item ) {
			$order->add_item( $item );
		}
		$order->set_currency( 'USD' );
		$order->set_total( 45 );
		$order->save();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );

		$this->tracker->inject_deferred_purchase_events();
		$this->assertCount( $count, $this->tracker->get_tracked_events() );
	}

	/** Only orders that were created without items are reported at request end. */
	public function test_orders_excluded_at_creation_are_not_reported_at_request_end(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = $this->build_order();
		$order->save();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );

		wp_set_current_user( 0 );
		$order->set_status( 'processing' );
		$order->save();
		$this->tracker->inject_deferred_purchase_events();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );
		$this->assertFalse( wc_get_order( $order->get_id() )->meta_exists( '_meta_purchase_tracked_server' ) );
	}

	/** Orders that are still empty, or were deleted, by the end of the request are skipped. */
	public function test_orders_still_empty_or_deleted_at_request_end_are_skipped(): void {
		$empty = new \WC_Order();
		$empty->set_status( 'pending' );
		$empty->save();

		$deleted = new \WC_Order();
		$deleted->set_status( 'pending' );
		$deleted->save();
		$deleted->delete( true );

		$this->tracker->inject_deferred_purchase_events();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );
		$this->assertFalse( wc_get_order( $empty->get_id() )->meta_exists( '_meta_purchase_tracked_server' ) );
	}

	/**
	 * Blocks creates a draft first and fires new_order on the pending transition.
	 *
	 * @dataProvider order_storage
	 * @param string $hpos Whether to enable HPOS.
	 */
	public function test_draft_transition_keeps_the_existing_purchase_timing( string $hpos ): void {
		update_option( 'woocommerce_custom_orders_table_enabled', $hpos );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		$this->assertSame( 'yes' === $hpos, \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
		$order = $this->build_order();
		$order->set_status( 'checkout-draft' );
		$order->save();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );
		$order->update_status( 'pending' );
		$this->assert_complete_purchase( $order );
	}

	/** @return array */
	public function purchase_statuses(): array {
		return array(
			'pending'    => array( 'pending', 1 ),
			'on hold'    => array( 'on-hold', 1 ),
			'processing' => array( 'processing', 1 ),
			'completed'  => array( 'completed', 1 ),
			'failed'     => array( 'failed', 0 ),
			'cancelled'  => array( 'cancelled', 0 ),
			'refunded'   => array( 'refunded', 0 ),
		);
	}

	/**
	 * @dataProvider purchase_statuses
	 * @param string $status Order status.
	 * @param int    $count Expected events.
	 */
	public function test_order_status_eligibility_is_unchanged( string $status, int $count ): void {
		$order = $this->build_order();
		$order->set_status( $status );
		$order->save();
		$this->assertCount( $count, $this->tracker->get_tracked_events() );
		if ( $count ) {
			$this->assert_complete_purchase( $order );
		}
	}

	public function test_admin_order_creation_remains_excluded(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = $this->build_order();
		$order->save();
		$this->assertCount( 0, $this->tracker->get_tracked_events() );
		$this->assertFalse( $order->meta_exists( '_meta_purchase_tracked_server' ) );
	}

	public function test_id_only_call_still_loads_the_saved_order(): void {
		remove_action( 'woocommerce_new_order', array( $this->tracker, 'inject_purchase_event' ), 10 );
		$order = $this->build_order();
		$order->save();
		$this->tracker->inject_purchase_event( $order->get_id() );
		$this->assert_complete_purchase( $order );
	}

	public function test_mismatched_order_object_cannot_report_another_order(): void {
		remove_action( 'woocommerce_new_order', array( $this->tracker, 'inject_purchase_event' ), 10 );
		$order = $this->build_order();
		$order->save();
		$other = $this->build_order();
		$other->set_total( 999 );
		$other->save();
		$this->tracker->inject_purchase_event( $order->get_id(), $other );
		$this->assert_complete_purchase( $order );
		$this->assertFalse( wc_get_order( $other->get_id() )->meta_exists( '_meta_purchase_tracked_server' ) );
	}

	public function test_new_order_passes_complete_quantities_to_cogs(): void {
		update_option(
			'wc_facebook_for_woocommerce_rollout_switches',
			array( \WooCommerce\Facebook\RolloutSwitches::SWITCH_VALUE_OPTIMIZATION_ENABLED => 'yes' )
		);
		$provider = new class() {
			/** @var array */
			public $quantities = array();

			/**
			 * @param array $products Products and quantities used for the purchase.
			 * @return int
			 */
			public function calculate_cogs_for_products( $products ) {
				$this->quantities = array_column( $products, 'qty' );
				return 3 * array_sum( $this->quantities );
			}
		};
		$property = new \ReflectionProperty( $this->tracker, 'cogs_provider' );
		$property->setAccessible( true );
		$property->setValue( $this->tracker, $provider );
		$order = $this->build_order();
		$order->save();
		$this->assert_complete_purchase( $order );
		$this->assertSame( array( 2, 1 ), $provider->quantities );
		$data = $this->tracker->get_tracked_events()[0]->get_data();
		$this->assertEquals( 36, $data['custom_data']['net_revenue'] );
	}

	/** @return \WC_Order Unsaved order with two unsaved line items. */
	private function build_order(): \WC_Order {
		$simple = new \WC_Product_Simple();
		$simple->set_name( 'Purchase simple' );
		$simple->set_regular_price( 10 );
		$simple->save();
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Purchase variation' );
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_regular_price( 25 );
		$variation->save();

		$order = new \WC_Order();
		$order->set_status( 'pending' );
		$order->set_currency( 'USD' );
		$order->add_product( $simple, 2 );
		$order->add_product( $variation, 1 );
		$order->set_total( 45 );
		return $order;
	}

	/** @param \WC_Order $order Expected order. */
	private function assert_complete_purchase( \WC_Order $order ): void {
		$events = $this->tracker->get_tracked_events();
		$this->assertCount( 1, $events );
		$data         = $events[0]->get_data()['custom_data'];
		$contents     = json_decode( $data['contents'], true );
		$expected_ids = array_values(
			array_map(
				function ( $item ) {
					return (string) ( $item->get_variation_id() ?: $item->get_product_id() );
				},
				$order->get_items()
			)
		);
		$this->assertCount( 2, $contents );
		$this->assertSame( $expected_ids, array_column( $contents, 'id' ) );
		$this->assertSame( array( 2, 1 ), array_column( $contents, 'quantity' ) );
		$this->assertSame( array( 'Purchase simple', 'Purchase variation' ), json_decode( $data['content_name'], true ) );
		$this->assertSame( $expected_ids, json_decode( $data['content_ids'], true ) );
		$this->assertSame( '45.00', $data['value'] );
		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( $order->get_id(), $data['order_id'] );
	}
}
