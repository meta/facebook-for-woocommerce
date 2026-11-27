<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Api\ProductCatalog\ProductGroups\Read;

use WooCommerce\Facebook\API\ProductCatalog\ProductGroups\Read\Request;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for the Product Group products request.
 */
class RequestTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The default request keeps the existing ID and retailer ID fields.
	 */
	public function test_default_fields() {
		$request = new Request( 'group-123', 1000 );

		$this->assertSame( 'GET', $request->get_method() );
		$this->assertSame( '/group-123/products', $request->get_path() );
		$this->assertSame(
			[
				'fields' => 'id,retailer_id',
				'limit'  => 1000,
			],
			$request->get_params()
		);
	}

	/**
	 * Callers can request a coherent product-group snapshot with extra fields.
	 */
	public function test_custom_fields() {
		$request = new Request( 'group-456', 50, 'id,retailer_id,name,price' );

		$this->assertSame(
			[
				'fields' => 'id,retailer_id,name,price',
				'limit'  => 50,
			],
			$request->get_params()
		);
	}
}
