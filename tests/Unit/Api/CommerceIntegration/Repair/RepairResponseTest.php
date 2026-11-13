<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\API\CommerceIntegration\Repair;

use WooCommerce\Facebook\API\CommerceIntegration\Repair\Response;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithSafeFiltering;

/**
 * Tests for the Commerce Partner Integration repair response.
 */
class RepairResponseTest extends AbstractWPUnitTestWithSafeFiltering {

	/**
	 * Tests that only string or integer IDs are returned, as a string.
	 *
	 * @dataProvider integration_id_type_provider
	 *
	 * @param mixed  $id       Decoded id value.
	 * @param string $expected Expected return value.
	 */
	public function test_get_commerce_partner_integration_id_type_safety( $id, string $expected ): void {
		$response = new Response(
			wp_json_encode(
				array(
					'success' => true,
					'id'      => $id,
				)
			)
		);

		$this->assertSame( $expected, $response->get_commerce_partner_integration_id() );
	}

	/**
	 * Data provider for test_get_commerce_partner_integration_id_type_safety.
	 *
	 * @return array
	 */
	public function integration_id_type_provider(): array {
		return array(
			'string' => array( '1234567890123', '1234567890123' ),
			'int'    => array( 1234567890123, '1234567890123' ),
			'array'  => array( array( '1234567890123' ), '' ),
			'null'   => array( null, '' ),
			'float'  => array( 1.5, '' ),
			'bool'   => array( true, '' ),
		);
	}
}
