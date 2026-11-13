<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

use WooCommerce\Facebook\Handlers\WhatsAppExtension;
use WooCommerce\Facebook\RolloutSwitches;

/**
 * Event Processor for sending WhatsApp Utility Message when Order Management events are triggered
 */
class WC_Facebookcommerce_Iframe_Whatsapp_Utility_Event {

	/** @var array Mapping of Order Status to Event name */
	const ORDER_STATUS_TO_EVENT_MAPPING = array(
		'processing' => 'ORDER_PLACED',
		'completed'  => 'ORDER_FULFILLED',
		'refunded'   => 'ORDER_REFUNDED',
	);

	/** @var \WC_Facebookcommerce */
	private $plugin;


	public function __construct( WC_Facebookcommerce $plugin ) {
		$rollout_switches = $plugin->get_rollout_switches();
		$this->plugin     = $plugin;
		add_action( 'woocommerce_order_status_changed', array( $this, 'process_wc_order_status_changed' ), 10, 3 );
	}


	/**
	 * Hook to process Order Processing, Order Completed and Order Refunded events for WhatsApp Utility Messages
	 *
	 * @param string $order_id Order id
	 * @param string $old_status Old Order Status
	 * @param string $new_status New Order Status
	 *
	 * @return void
	 * @since 2.3.0
	 */
	public function process_wc_order_status_changed( $order_id, $old_status, $new_status ) {
		$supported_statuses = array_keys( self::ORDER_STATUS_TO_EVENT_MAPPING );
		if ( ! in_array( $new_status, $supported_statuses, true ) ) {
			return;
		}

		// A store that never connected WhatsApp utility messaging has nothing to send.
		// Leave without logging: otherwise every order writes WhatsApp lines that
		// merchants read as the Purchase event failing (see issue #4019).
		if ( ! $this->plugin->get_whatsapp_connection_handler()->is_connected() ) {
			return;
		}

		$event = self::ORDER_STATUS_TO_EVENT_MAPPING[ $new_status ];
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order_details_link = $order->get_checkout_order_received_url();
		// Get WhatsApp Phone number from entered Billing and Shipping phone number
		$billing_phone_number  = $order->get_billing_phone();
		$shipping_phone_number = $order->get_shipping_phone();
		$phone_number          = ! empty( $billing_phone_number ) ? $billing_phone_number : $shipping_phone_number;
		// Get Country Code from Billing and Shipping Country to override Country Calling Code
		$should_use_billing_info = ! empty( $billing_phone_number );
		$country_code            = $should_use_billing_info ? $order->get_billing_country() : $order->get_shipping_country();
		// Get Customer first name
		$first_name = $order->get_billing_first_name();
		// Get Total Refund Amount for Order Refunded event
		$total_refund = 0;
		foreach ( $order->get_refunds() as $refund ) {
			$total_refund += $refund->get_amount();
		}
		$currency      = $order->get_currency();
		$refund_amount = $total_refund * 1000;
		if ( empty( $phone_number ) || empty( $event ) || empty( $first_name ) ) {
			wc_get_logger()->info(
				sprintf(
				/* translators: %1$s order ID */
					__( 'WhatsApp utility message for order %1$s not sent: the order has no phone number or customer first name.', 'facebook-for-woocommerce' ),
					$order_id,
				)
			);
			return;
		}
		// Always build order metadata for rich order status
		$order_metadata = self::build_order_metadata( $order, $currency );

		WhatsAppExtension::process_whatsapp_utility_message_event( $this->plugin, $event, $order_id, $order_details_link, $phone_number, $first_name, $refund_amount, $currency, $country_code, $order_metadata );
	}

	/**
	 * Build order metadata from WooCommerce order object.
	 *
	 * @param \WC_Order $order The WooCommerce order object.
	 * @param string    $currency The order currency code.
	 * @return array Order metadata with keys: order_url, order_date, currency, shipping_method, items.
	 */
	private static function build_order_metadata( $order, $currency ) {
		$order_metadata = array(
			'order_url'       => ( method_exists( $order, 'get_view_order_url' ) ? $order->get_view_order_url() : $order->get_checkout_order_received_url() ),
			'order_date'      => ( method_exists( $order, 'get_date_created' ) ? ( $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '' ) : '' ),
			'currency'        => $currency,
			'shipping_method' => ( method_exists( $order, 'get_shipping_method' ) ? $order->get_shipping_method() : '' ),
			'items'           => array(),
		);

		$items = method_exists( $order, 'get_items' ) ? $order->get_items() : array();
		foreach ( $items as $item ) {
			$product_id                = method_exists( $item, 'get_product_id' ) ? $item->get_product_id() : ( isset( $item['product_id'] ) ? $item['product_id'] : 0 );
			$item_total                = method_exists( $item, 'get_total' ) ? $item->get_total() : ( isset( $item['line_total'] ) ? $item['line_total'] : 0 );
			$order_metadata['items'][] = array(
				'product_id' => $product_id,
				'name'       => method_exists( $item, 'get_name' ) ? $item->get_name() : ( isset( $item['name'] ) ? $item['name'] : '' ),
				'quantity'   => method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : ( isset( $item['qty'] ) ? $item['qty'] : null ),
				'amount'     => $item_total,
			);
		}

		return $order_metadata;
	}
}
