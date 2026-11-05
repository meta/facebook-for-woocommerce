<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\API\Plugin\Settings;

use WooCommerce\Facebook\API\Plugin\Settings\FinalizeInstall\Request;

/**
 * Integration coverage for the finalize-install JavaScript API definition.
 */
class FinalizeInstallRequestTest extends \WP_UnitTestCase {

	/**
	 * Confirms the request schema is exposed to the generated JavaScript client.
	 */
	public function test_js_api_definition_exposes_finalize_install_schema(): void {
		$request = new Request( new \WP_REST_Request( 'POST', '/wc-facebook/v1/settings/finalize-install' ) );

		$this->assertSame(
			array(
				'path'      => 'settings/finalize-install',
				'method'    => 'POST',
				'className' => 'finalizeInstall',
				'params'    => array(
					'access_token'                    => 'string',
					'product_catalog_id'              => 'string',
					'pixel_id'                        => 'string',
					'commerce_merchant_settings_id'   => 'string',
					'commerce_partner_integration_id' => 'string',
					'installed_features'              => 'array',
				),
				'required'  => array( 'access_token' ),
			),
			$request->get_js_api_definition()
		);
	}
}
