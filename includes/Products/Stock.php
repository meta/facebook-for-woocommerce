<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Products;

defined( 'ABSPATH' ) || exit;


/**
 * The product stock handler.
 *
 * @since 2.0.5
 */
class Stock {

	/**
	 * Stock constructor.
	 *
	 * @since 2.0.5
	 */
	public function __construct() {
		$this->add_hooks();
	}


	/**
	 * Adds needed hooks to keep product stock in sync.
	 *
	 * @since 2.0.5
	 */
	private function add_hooks() {
		add_action( 'woocommerce_variation_set_stock', array( $this, 'set_product_stock' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'set_product_stock' ) );
	}


	/**
	 * Attempts to sync a product when its stock changes.
	 *
	 * @internal
	 *
	 * @since 2.0.5
	 *
	 * @param \WC_Product $product the product that was updated
	 */
	public function set_product_stock( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		foreach ( $this->get_products_to_sync( $product ) as $item ) {
			$this->maybe_sync_product_stock_status( $item );
		}
	}


	/**
	 * Gets an array of product objects that could be synced using the Batch API.
	 *
	 * This method returns the product variations of variable products, or the product itself for other product types.
	 * Variable products cannot be synced through the Batch API as they are represented as Product Groups instead of Product Items on Facebook.
	 *
	 * @since 2.0.5
	 *
	 * @param \WC_Product $product a product object
	 * @return \WC_Product[]
	 */
	private function get_products_to_sync( \WC_Product $product ) {
		if ( $product->is_type( 'variable' ) ) {
			return array_filter(
				array_map( 'wc_get_product', $product->get_children() ),
				function ( $item ) {
					return $item instanceof \WC_Product;
				}
			);
		}
		return array( $product );
	}


	/**
	 * Schedules a product sync to update the product's stock status.
	 *
	 * @since 2.0.5
	 *
	 * @param \WC_Product $product a product object
	 */
	private function maybe_sync_product_stock_status( \WC_Product $product ) {
		facebook_for_woocommerce()->get_products_sync_handler()->create_or_update_products( array( $product->get_id() ) );
	}
}
