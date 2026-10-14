<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\API\CommerceIntegration\Finalize;

use WooCommerce\Facebook\API\Response as APIResponse;

defined( 'ABSPATH' ) || exit;

/**
 * Response from the Commerce Partner Integration finalize-install endpoint.
 */
class Response extends APIResponse {

	/**
	 * Determines whether the integration was finalized successfully.
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		return '' !== $this->get_commerce_partner_integration_id()
			&& '' !== $this->get_external_business_id()
			&& 'ACCESS_TOKEN_DEPOSITED' === $this->get_installation_status();
	}

	/**
	 * Gets the Commerce Partner Integration ID.
	 *
	 * @return string
	 */
	public function get_commerce_partner_integration_id(): string {
		return $this->get_string_value( 'id' );
	}

	/**
	 * Gets the external business ID.
	 *
	 * @return string
	 */
	public function get_external_business_id(): string {
		return $this->get_string_value( 'external_business_id' );
	}

	/**
	 * Gets the installation status.
	 *
	 * @return string
	 */
	public function get_installation_status(): string {
		return $this->get_string_value( 'installation_status' );
	}

	/**
	 * Gets the Commerce Merchant Settings ID.
	 *
	 * @return string
	 */
	public function get_commerce_merchant_settings_id(): string {
		return $this->get_string_value( 'commerce_merchant_settings_id' );
	}

	/**
	 * Gets the product catalog ID.
	 *
	 * @return string
	 */
	public function get_catalog_id(): string {
		return $this->get_string_value( 'catalog_id' );
	}

	/**
	 * Gets the pixel ID.
	 *
	 * @return string
	 */
	public function get_pixel_id(): string {
		return $this->get_string_value( 'pixel_id' );
	}

	/**
	 * Maps the finalize-install response to settings update fields.
	 *
	 * @return array
	 */
	public function get_installation_assets(): array {
		$assets = array(
			'commerce_partner_integration_id' => $this->get_commerce_partner_integration_id(),
			'commerce_merchant_settings_id'   => $this->get_commerce_merchant_settings_id(),
			'product_catalog_id'              => $this->get_catalog_id(),
			'pixel_id'                        => $this->get_pixel_id(),
		);

		return array_filter(
			$assets,
			function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * Gets a string response value, rejecting arrays and objects.
	 *
	 * @param string $key Response field key.
	 * @return string
	 */
	private function get_string_value( string $key ): string {
		if ( ! is_array( $this->response_data ) ) {
			return '';
		}

		$value = $this->response_data[ $key ] ?? '';

		return is_string( $value ) ? $value : '';
	}
}
