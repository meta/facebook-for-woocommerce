<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\ProductSync;

use WC_Facebook_Product;
use WC_Facebookcommerce_Integration;
use WC_Product;
use WooCommerce\Facebook\Products;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Facebookcommerce_Utils' ) ) {
	include_once '../fbutils.php';
}

/**
 * Class ProductValidator
 *
 * This class is responsible for validating whether a product should be synced to Facebook.
 *
 * @since 2.5.0
 */
class ProductValidator {
	/**
	 * Maximum allowed attributes in a variation;
	 *
	 * @var int
	 */
	public const MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION = 4;

	/**
	 * The FB integration instance.
	 *
	 * @var WC_Facebookcommerce_Integration
	 */
	protected $integration;

	/**
	 * The product object to validate.
	 *
	 * @var WC_Product
	 */
	protected $product;

	/**
	 * The product parent object if the product has a parent.
	 *
	 * @var WC_Product
	 */
	protected $product_parent;

	/**
	 * The product parent object if the product has a parent.
	 *
	 * @var WC_Facebook_Product
	 */
	protected $fb_product_parent;

	/**
	 * The product object to validate.
	 *
	 * @var WC_Facebook_Product
	 */
	protected $facebook_product;

	/**
	 * ProductValidator constructor.
	 *
	 * @param WC_Facebookcommerce_Integration $integration The FB integration instance.
	 * @param WC_Product                      $product     The product to validate. Accepts both variations and variable products.
	 */
	public function __construct( WC_Facebookcommerce_Integration $integration, WC_Product $product ) {
		$this->product           = $product;
		$this->product_parent    = null;
		$this->fb_product_parent = null;

		if ( $product->get_parent_id() ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			if ( $parent_product instanceof WC_Product ) {
				$this->product_parent    = $parent_product;
				$this->fb_product_parent = new WC_Facebook_Product( $parent_product );
			}
		}

		$this->facebook_product = new WC_Facebook_Product( $this->product, $this->fb_product_parent );
		$this->integration      = $integration;
	}

	/**
	 * __get method for backward compatibility.
	 *
	 * @param string $key property name
	 * @return mixed
	 * @since 3.0.32
	 */
	public function __get( $key ) {
		// Add warning for private properties.
		if ( 'facebook_product' === $key ) {
			/* translators: %s property name. */
			_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'The %s property is protected and should not be accessed outside its class.', 'facebook-for-woocommerce' ), esc_html( $key ) ), '3.0.32' );
			return $this->$key;
		}

		return null;
	}

	/**
	 * Validate whether the product should be synced to Facebook.
	 *
	 * @throws ProductExcludedException If product should not be synced.
	 */
	public function validate() {
		$this->validate_product_sync_field();
		$this->validate_product_status();
		$this->validate_product_visibility();
		$this->validate_product_language();
	}

	/**
	 * Validate whether the product should be synced to Facebook but skip the status check for backwards compatibility.
	 *
	 * @internal Do not use this as it will likely be removed.
	 *
	 * @throws ProductExcludedException If product should not be synced.
	 */
	public function validate_but_skip_status_check() {
		$this->validate_product_sync_field();
		$this->validate_product_visibility();
	}

	/**
	 * Validate whether the product should be synced to Facebook but skip the sync field check.
	 *
	 * @since 3.0.6
	 * @throws ProductExcludedException|ProductInvalidException If product should not be synced.
	 */
	public function validate_but_skip_sync_field() {
		$this->validate_product_visibility();
	}

	/**
	 * Validate whether the product should be synced to Facebook.
	 *
	 * @return bool
	 */
	public function passes_all_checks(): bool {
		try {
			$this->validate();
		} catch ( ProductExcludedException $e ) {
			return false;
		} catch ( ProductInvalidException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the product's terms (categories and tags) allow it to sync.
	 *
	 * @deprecated 3.7.9 Categories and tags can no longer be excluded from sync, so every product passes.
	 *
	 * @return bool
	 */
	public function passes_product_terms_check(): bool {
		wc_deprecated_function( __METHOD__, '3.7.9' );

		return true;
	}

	/**
	 * Check if the product's product sync meta field allows it to sync.
	 *
	 * @return bool
	 */
	public function passes_product_sync_field_check(): bool {
		try {
			$this->validate_product_sync_field();
		} catch ( ProductExcludedException $e ) {
			return false;
		} catch ( ProductInvalidException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate whether the product should be synced to Facebook, but skip the sync field validation.
	 *
	 * @return bool
	 */
	public function passes_all_checks_except_sync_field(): bool {
		try {
			$this->validate_but_skip_sync_field();
		} catch ( ProductExcludedException $e ) {
			return false;
		} catch ( ProductInvalidException $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether the product's status excludes it from sync.
	 *
	 * @throws ProductExcludedException If product should not be synced.
	 */
	protected function validate_product_status() {
		$product = $this->product_parent ? $this->product_parent : $this->product;

		if ( 'publish' !== $product->get_status() ) {
			throw new ProductExcludedException( esc_html__( 'Product is not published.', 'facebook-for-woocommerce' ) );
		}
	}

	/**
	 * Check whether the product's visibility excludes it from sync.
	 *
	 * Products are excluded if they are hidden from the store catalog or from search results.
	 *
	 * @throws ProductExcludedException If product should not be synced.
	 */
	protected function validate_product_visibility() {
		$product = $this->product_parent ? $this->product_parent : $this->product;

		/**
		 * Instead of directly calling $product->is_visible(), copying the logic of is_visible() here
		 * excluding the logic for woocommerce_hide_out_of_stock_items because we want to sync out of
		 * stock items as well irrespective of Inventory settings.
		 * ===Logic Starts here===
		 */
		$visible = 'visible' === $product->get_catalog_visibility() || ( is_search() && 'search' === $product->get_catalog_visibility() ) || ( ! is_search() && 'catalog' === $product->get_catalog_visibility() );
		if ( 'trash' === $product->get_status() ) {
			$visible = false;
		} elseif ( 'publish' !== $product->get_status() && ! current_user_can( 'edit_post', $product->get_id() ) ) {
			$visible = false;
		}
		if ( $product->get_parent_id() ) {
			$parent_product = wc_get_product( $product->get_parent_id() );

			if ( $parent_product && 'publish' !== $parent_product->get_status() && ! current_user_can( 'edit_post', $parent_product->get_id() ) ) {
				$visible = false;
			}
		}
		/**
		 * ===Logic Ends here===
		 */

		if ( ! $visible ) {
			throw new ProductExcludedException( esc_html__( 'This product cannot be synced to Facebook because it is hidden from your store catalog.', 'facebook-for-woocommerce' ) );
		}
	}

	/**
	 * Validate if the product is excluded from at the "product level" (product meta value).
	 *
	 * @throws ProductExcludedException If product should not be synced.
	 */
	protected function validate_product_sync_field() {
		$invalid_exception = new ProductExcludedException( __( 'Sync disabled in product field.', 'facebook-for-woocommerce' ) );

		/**
		 * Filters whether a product should be synced to FB.
		 *
		 * @since 2.6.26
		 *
		 * @param WC_Product $product the product object.
		 */
		if ( ! apply_filters( 'wc_facebook_should_sync_product', true, $this->product ) ) {
			throw new ProductExcludedException( esc_html__( 'Product excluded by wc_facebook_should_sync_product filter.', 'facebook-for-woocommerce' ) );
		}
		/**
		 * The variable check will be used when we have create update of a product
		 * Either from Product details page or bulk editor
		 */
		if ( $this->product->is_type( 'variable' ) ) {
			foreach ( $this->product->get_children() as $child_id ) {
				$child_product = wc_get_product( $child_id );
				if ( $child_product && 'no' !== $child_product->get_meta( Products::get_product_sync_meta_key() ) ) {
					// At least one product is "sync-enabled" so bail before exception.
					return;
				}
			}

			// Variable product has no variations with sync enabled so it shouldn't be synced.
			throw $invalid_exception;
		} elseif ( $this->product->get_type() === 'variation' ) {
			/**
			 * This check will run for background jobs like sync all and feeds.
			 * Parent product can be unavailable during trash/delete cascades
			 * (for example when translation plugins process variations after parent removal).
			 */
			if ( ! $this->product_parent instanceof WC_Product ) {
				throw $invalid_exception;
			}

			$parent_sync = $this->product_parent->get_meta( Products::get_product_sync_meta_key() );

			if ( 'yes' === $parent_sync ) {
				return;
			} elseif ( 'no' === $parent_sync ) {
				throw $invalid_exception;
			} else {
				$variation_sync = false;
				foreach ( $this->product_parent->get_children() as $child_id ) {
					$child_product = wc_get_product( $child_id );
					if ( $child_product && 'no' !== $child_product->get_meta( Products::get_product_sync_meta_key() ) ) {
						// At least one product is "sync-enabled" so bail before exception.
						$variation_sync = true;
						break;
					}
				}

				/**
				 * Updating parent level sync for UI issues and
				 * Future variation checks for sync
				 */
				update_post_meta( $this->product_parent->get_id(), Products::get_product_sync_meta_key(), $variation_sync ? 'yes' : 'no' );
				if ( $variation_sync ) {
					return;
				}
			}

			// Variable product has no variations with sync enabled so it shouldn't be synced.
			throw $invalid_exception;
		} elseif ( 'no' === $this->product->get_meta( Products::get_product_sync_meta_key() ) ) {
				throw $invalid_exception;
		}
	}

	/**
	 * Check if variation product has proper settings.
	 *
	 * @throws ProductInvalidException If product variation violates some requirements.
	 */
	protected function validate_variation_structure() {
		// Check if we are dealing with a variation.
		if ( ! $this->product->is_type( 'variation' ) ) {
			return;
		}
		$attributes = $this->product->get_attributes();

		$used_attributes_count = count(
			array_filter(
				$attributes
			)
		);

		// No more than MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION ar allowed to be used.
		if ( $used_attributes_count > self::MAX_NUMBER_OF_ATTRIBUTES_IN_VARIATION ) {
			throw new ProductInvalidException( esc_html__( 'Too many attributes selected for product. Use 4 or less.', 'facebook-for-woocommerce' ) );
		}
	}

	/**
	 * Validate if the product is in the default language when a localization plugin is active.
	 *
	 * Only products in the default language should be synced to the main product catalog.
	 * Translated products are handled separately via language override feeds.
	 *
	 * @throws ProductExcludedException If product is not in the default language.
	 */
	protected function validate_product_language() {
		// Get the product to check (use parent for variations)
		$product_to_check = $this->product_parent ? $this->product_parent : $this->product;
		$product_id       = $product_to_check->get_id();

		// Only validate language if language override feed generation is enabled
		// Use the integration method instead of get_option() to handle all necessary checks
		$is_language_feed_enabled = $this->integration->is_language_override_feed_generation_enabled();

		if ( ! $is_language_feed_enabled ) {
			return;
		}

		$integration = \WooCommerce\Facebook\Integrations\IntegrationRegistry::get_active_localization_integration();

		// If no localization plugin is active, skip language validation
		if ( ! $integration ) {
			return;
		}

		$default_language = $integration->get_default_language();

		// If we can't determine the default language, skip validation to avoid blocking sync
		if ( ! $default_language ) {
			return;
		}

		// Get the product's language using the integration's method
		$product_language = $integration->get_product_language( $product_id );

		// If we can't determine the product's language, skip validation to avoid blocking sync
		if ( ! $product_language ) {
			return;
		}

		// Compare product language with default language
		// Use Locale utility to extract language code for consistent comparison
		$default_lang_code = $this->extract_language_code( $default_language );
		$product_lang_code = $this->extract_language_code( $product_language );

		if ( $product_lang_code !== $default_lang_code ) {
			throw new ProductExcludedException(
				esc_html(
					sprintf(
						/* translators: 1: product language, 2: default language */
						__( 'Product is in language "%1$s" but only default language "%2$s" products are synced to the main catalog.', 'facebook-for-woocommerce' ),
						$product_language,
						$default_language
					)
				)
			);
		}
	}

	/**
	 * Extract language code from locale string.
	 *
	 * Converts locale format (en_US) to language code (en) for consistent comparison.
	 * This uses the same logic as Locale::convert_to_facebook_language_code().
	 *
	 * @param string $locale_or_language Locale string or language code.
	 * @return string Language code (lowercase).
	 */
	private function extract_language_code( string $locale_or_language ): string {
		$parts = explode( '_', $locale_or_language );
		return strtolower( $parts[0] );
	}
}
