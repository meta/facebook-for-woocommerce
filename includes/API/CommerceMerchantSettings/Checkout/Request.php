<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\API\CommerceMerchantSettings\Checkout;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\API;

/**
 * Sets the store's checkout URL for one country on its Commerce Merchant Settings
 * and publishes the shop: POST /{commerce_merchant_settings_id} with `checkout_config`.
 *
 * @since 3.7.8
 */
class Request extends API\Request {

	/**
	 * Constructor.
	 *
	 * @param string $commerce_merchant_settings_id Commerce Merchant Settings ID.
	 * @param string $checkout_url Base checkout URL; Meta appends the cart parameters.
	 * @param string $country_code ISO country code the checkout URL applies to.
	 */
	public function __construct( string $commerce_merchant_settings_id, string $checkout_url, string $country_code ) {
		parent::__construct( "/{$commerce_merchant_settings_id}", 'POST' );

		$this->set_data(
			array(
				'checkout_config' => array(
					'checkout_url' => $checkout_url,
					'country_code' => $country_code,
					// Without it, Meta only publishes for some partner platforms, so always ask.
					'publish_shop' => true,
				),
			)
		);
	}
}
