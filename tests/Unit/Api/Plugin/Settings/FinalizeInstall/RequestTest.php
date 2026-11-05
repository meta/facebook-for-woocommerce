<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\API\Plugin\Settings\FinalizeInstall;

use WooCommerce\Facebook\API\Plugin\Settings\FinalizeInstall\Request;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Tests for the finalize-install request definition.
 */
class RequestTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The JavaScript request schema must declare the finalize-install parameters.
	 */
	public function test_param_schema_declares_finalize_install_parameters(): void {
		$wp_request = $this->createMock( \WP_REST_Request::class );
		$wp_request->method( 'get_json_params' )->willReturn( array() );
		$wp_request->method( 'get_params' )->willReturn( array() );

		$request = new Request( $wp_request );

		$this->assertSame(
			array(
				'access_token'                    => array(
					'type'     => 'string',
					'required' => true,
				),
				'product_catalog_id'              => array(
					'type'     => 'string',
					'required' => false,
				),
				'pixel_id'                        => array(
					'type'     => 'string',
					'required' => false,
				),
				'commerce_merchant_settings_id'   => array(
					'type'     => 'string',
					'required' => false,
				),
				'commerce_partner_integration_id' => array(
					'type'     => 'string',
					'required' => false,
				),
				'installed_features'              => array(
					'type'     => 'array',
					'required' => false,
				),
			),
			$request->get_param_schema()
		);
	}
}
