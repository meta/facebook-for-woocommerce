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
 * Supplies catalog identifiers for product metadata.
 */
class OpenGraph {

	/**
	 * Registers the metadata filter.
	 */
	public function __construct() {
		add_filter( 'woocommerce_open_graph_tags', array( $this, 'add_retailer_id' ), 10, 2 );
	}

	/**
	 * Adds the catalog retailer ID for simple products.
	 *
	 * @param mixed $tags Product metadata groups.
	 * @param mixed $product The queried object.
	 * @return mixed
	 */
	public function add_retailer_id( $tags, $product ) {
		if ( ! is_array( $tags ) || ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return $tags;
		}

		$retailer_id = \WC_Facebookcommerce_Utils::get_fb_retailer_id( new \WC_Facebook_Product( $product ) );
		if ( ! is_string( $retailer_id ) || '' === trim( $retailer_id ) ) {
			return $tags;
		}

		foreach ( $tags as $index => $group ) {
			if ( is_array( $group ) && isset( $group['product:retailer_item_id'] ) ) {
				$tags[ $index ]['product:retailer_item_id'] = $retailer_id;
				return $tags;
			}
		}

		$tags[] = array( 'product:retailer_item_id' => $retailer_id );
		return $tags;
	}
}
