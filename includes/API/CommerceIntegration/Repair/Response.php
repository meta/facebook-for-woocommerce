<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\API\CommerceIntegration\Repair;

use WooCommerce\Facebook\API\Response as ApiResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Response object for Commerce Integration Repair API.
 *
 * @property-read string commerce_partner_integration_id The ID of the commerce partner integration
 */
class Response extends ApiResponse {
	/**
	 * Returns whether the repair request was successful.
	 *
	 * @return bool
	 * @since 3.4.8
	 */
	public function is_successful(): bool {
		return (bool) $this->success;
	}

	/**
	 * Returns the commerce partner integration ID.
	 *
	 * @return string
	 * @since 3.4.8
	 */
	public function get_commerce_partner_integration_id(): string {
		// Graph may encode the ID as a number; any other type is not a usable ID.
		// Returning a non-string here under strict_types would throw a TypeError past
		// the caller's ApiException handler and abort the rest of the daily sync.
		$id = $this->get_id();

		return is_string( $id ) || is_int( $id ) ? (string) $id : '';
	}
}
