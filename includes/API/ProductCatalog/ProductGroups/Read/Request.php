<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\API\ProductCatalog\ProductGroups\Read;

use WooCommerce\Facebook\API\Request as ApiRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Request object for Product Catalog > Product Groups > Update Graph Api.
 *
 * @link https://developers.facebook.com/docs/marketing-api/reference/product-catalog/product_groups/#Reading
 */
class Request extends ApiRequest {

	/**
	 * @param string $product_group_id Facebook Product Group ID.
	 * @param int    $limit Limit.
	 * @param string $fields_string Comma-separated fields to request for each product.
	 */
	public function __construct( string $product_group_id, int $limit, string $fields_string = 'id,retailer_id' ) {
		parent::__construct( "/{$product_group_id}/products", 'GET' );
		$this->set_params(
			[
				'fields' => $fields_string,
				'limit'  => $limit,
			]
		);
	}
}
