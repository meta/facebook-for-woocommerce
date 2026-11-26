<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Feed\Localization;

use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Framework\Logger;

/**
 * Language Feed Data Handler for Facebook Language Override Feeds
 *
 * Handles translation data extraction from localization plugins and CSV generation
 * for Facebook language override feeds. Consolidates both data extraction and formatting.
 *
 * @since 3.6.0
 */
class LanguageFeedData {


	// ===========================================
	// TRANSLATION DATA EXTRACTION METHODS
	// ====================================


	/**
	 * Get all available languages from active localization plugins
	 *
	 * @return array Array of language codes
	 */
	public function get_available_languages(): array {
		$all_languages = [];
		$integrations  = IntegrationRegistry::get_all_localization_integrations();

		foreach ( $integrations as $integration ) {
			if ( $integration->is_plugin_active() ) {
				$languages     = $integration->get_available_languages();
				$all_languages = array_merge( $all_languages, $languages );
			}
		}

		// Remove duplicates and default language
		$all_languages    = array_unique( $all_languages );
		$default_language = $this->get_default_language();

		if ( $default_language ) {
			$all_languages = array_filter(
				$all_languages,
				function ( $lang ) use ( $default_language ) {
					return $lang !== $default_language;
				}
			);
		}

		return array_values( $all_languages );
	}


	/**
	 * Get the default language from the first active localization plugin
	 *
	 * @return string|null Default language code or null if not available
	 */
	public function get_default_language(): ?string {
		$integration = IntegrationRegistry::get_active_localization_integration();

		if ( $integration ) {
			return $integration->get_default_language();
		}

		return null;
	}

	/**
	 * Get products from the default language
	 *
	 * Uses the first active localization plugin to get products from the default language.
	 *
	 * @param int $limit Maximum number of products to return (-1 for all products, legacy feed behavior)
	 * @param int $offset Offset for pagination
	 * @return array Array of product IDs from the default language
	 */
	public function get_products_from_default_language( int $limit = 10, int $offset = 0 ): array {
		$integration = IntegrationRegistry::get_active_localization_integration();

		if ( $integration ) {
			return $integration->get_products_from_default_language( $limit, $offset );
		}

		// Fallback: get regular products if no localization plugin is active
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
		];

		return get_posts( $args );
	}

	/**
	 * Get detailed translation information for a product
	 *
	 * Uses the first active localization plugin to get translation details.
	 *
	 * @param int $product_id Product ID (should be from default language)
	 * @return array Detailed translation information
	 */
	public function get_product_translation_details( int $product_id ): array {
		$integration = IntegrationRegistry::get_active_localization_integration();

		if ( $integration ) {
			return $integration->get_product_translation_details( $product_id );
		}

		// Fallback: return basic structure if no localization plugin is active
		return [
			'product_id'         => $product_id,
			'default_language'   => null,
			'translations'       => [],
			'translation_status' => [],
			'translated_fields'  => [],
		];
	}

	// ===========================================
	// CSV FORMATTING AND GENERATION METHODS
	// ===========================================

	/**
	 * Get all unique translated fields across all products for a language
	 *
	 * @param string $language_code Language code
	 * @param int    $limit Maximum number of products to check
	 * @return array Array of unique field names that have translations
	 */
	public function get_translated_fields_for_language( string $language_code, int $limit = 100 ): array {
		if ( ! IntegrationRegistry::has_active_localization_plugin() ) {
			return [];
		}

		$product_ids           = $this->get_products_from_default_language( $limit, 0 );
		$all_translated_fields = [];

		foreach ( $product_ids as $product_id ) {
			$details = $this->get_product_translation_details( $product_id );

			if ( isset( $details['translated_fields'][ $language_code ] ) ) {
				$translated_fields     = $details['translated_fields'][ $language_code ];
				$all_translated_fields = array_merge( $all_translated_fields, $translated_fields );
			}
		}

		return array_unique( $all_translated_fields );
	}

	/**
	 * Map translated field names to Facebook CSV column names
	 *
	 * @param array $translated_fields Array of translated field names
	 * @return array Array of Facebook CSV column names
	 */
	private function map_translated_fields_to_csv_columns( array $translated_fields ): array {
		// Mapping from WPML field names to Facebook CSV column names
		$field_mapping = [
			'name'                  => 'title',
			'description'           => 'description',
			'short_description'     => 'short_description',
			'rich_text_description' => 'rich_text_description',
			'image_id'              => 'image_link',
			'gallery_image_ids'     => 'additional_image_link',
			'link'                  => 'link',
			'video'                 => 'video',
		];

		$csv_columns = [];
		foreach ( $translated_fields as $field ) {
			if ( isset( $field_mapping[ $field ] ) ) {
				$csv_columns[] = $field_mapping[ $field ];
			}
		}

		// Remove duplicates and ensure required columns
		$csv_columns = array_unique( $csv_columns );

		return $csv_columns;
	}

	/**
	 * Extract translation data and convert to CSV format for a specific language
	 *
	 * This method processes products from the default language and generates CSV rows
	 * for Facebook's language override feed. It handles both simple and variable products.
	 *
	 * **Variable Product Behavior:**
	 * For variable products, localization plugins do NOT support direct 1:1 variation-level
	 * translations. Instead, this method:
	 * 1. Gets the translated parent product content (title, description, images)
	 * 2. Applies that translated content to ALL variations of the original product
	 * 3. Each variation gets its own CSV row with the same translated parent content
	 *
	 * Note: Multiple plugin handling is managed by IntegrationRegistry::get_active_localization_integration()
	 *
	 * @param string $language_code Language code (e.g., 'es_ES', 'fr_FR')
	 * @param int    $limit Maximum number of products to process
	 * @param int    $offset Offset for pagination
	 * @return array CSV data ready for conversion to CSV string with dynamic columns
	 */
	public function get_language_csv_data( string $language_code, int $limit = 100, int $offset = 0 ): array {
		if ( ! IntegrationRegistry::has_active_localization_plugin() ) {
			return [
				'data'              => [],
				'columns'           => [ 'id', 'override' ],
				'translated_fields' => [],
			];
		}

		// First, determine which fields are translated for this language
		$translated_fields = $this->get_translated_fields_for_language( $language_code, $limit );
		$csv_columns       = $this->map_translated_fields_to_csv_columns( $translated_fields );

		$product_ids = $this->get_products_from_default_language( $limit, $offset );
		$csv_data    = [];

		foreach ( $product_ids as $product_id ) {
			// Skip products that don't pass Facebook sync validation
			$original_product = wc_get_product( $product_id );
			if ( ! $original_product ) {
				continue;
			}

			// Use Facebook's product sync validator if available
			if ( function_exists( 'facebook_for_woocommerce' ) ) {
				$sync_validator = facebook_for_woocommerce()->get_product_sync_validator( $original_product );
				if ( ! $sync_validator->passes_all_checks() ) {
					continue;
				}
			}

			$details = $this->get_product_translation_details( $product_id );

			if ( empty( $details['translations'] ) || ! isset( $details['translations'][ $language_code ] ) ) {
				continue;
			}

			$translated_id             = $details['translations'][ $language_code ];
			$product_translated_fields = $details['translated_fields'][ $language_code ] ?? [];

			// Only include products that have actual translated content
			if ( empty( $product_translated_fields ) ) {
				continue;
			}

			$translated_product = wc_get_product( $translated_id );
			if ( ! $translated_product ) {
				continue;
			}

			// Create Facebook product instances for proper field extraction
			if ( ! class_exists( 'WC_Facebook_Product' ) ) {
				require_once WC_FACEBOOKCOMMERCE_PLUGIN_DIR . '/includes/fbproduct.php';
			}

			$original_fb_product   = new \WC_Facebook_Product( $original_product );
			$translated_fb_product = new \WC_Facebook_Product( $translated_product );

			// Determine the ID based on product type (matching catalog backend logic):
			// - Simple products: Use just the woo_id
			// - Variable products: Iterate through each variation and use the variation woo_id
			if ( $original_product->is_type( 'variable' ) ) {
				// For variable products, build the translated content once (it's the same for all variations)
				$translated_content = [
					'override' => \WooCommerce\Facebook\Locale::convert_to_facebook_language_code( $language_code ),
				];

				// Build translated field values once (they're shared across all variations)
				foreach ( $csv_columns as $column ) {
					$translated_content[ $column ] = $this->get_translated_field_value(
						$column,
						$original_fb_product,
						$translated_fb_product,
						$product_translated_fields,
						$language_code
					);
				}

				// Check if at least one translatable field has content
				$has_content = false;
				foreach ( $csv_columns as $column ) {
					if ( ! empty( $translated_content[ $column ] ) ) {
						$has_content = true;
						break;
					}
				}

				// If there's no translated content, skip this entire variable product
				if ( ! $has_content ) {
					continue;
				}

				// Now iterate through variations and create a row for each with the same content.
				// Read in every language: the original's variations are not in the request's language.
				$variations = \WC_Facebookcommerce_Utils::get_children_in_every_language( $original_product );

				foreach ( $variations as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation ) {
						continue; // Skip invalid variations
					}

					// Get product ID based on content ID migration rollout switch
					$variation_id = $this->get_product_id( $variation );

					// Create row with the variation ID and reuse the translated content
					$csv_row = array_merge(
						[ 'id' => $variation_id ],
						$translated_content
					);

					$csv_data[] = $csv_row;
				}
			} else {
				// Get product ID based on content ID migration rollout switch
				$product_id = $this->get_product_id( $original_product );

				// Start with required columns
				$csv_row = [
					'id'       => $product_id,
					'override' => \WooCommerce\Facebook\Locale::convert_to_facebook_language_code( $language_code ),
				];

				// Add dynamic columns based on what's actually translated
				foreach ( $csv_columns as $column ) {
					$csv_row[ $column ] = $this->get_translated_field_value(
						$column,
						$original_fb_product,
						$translated_fb_product,
						$product_translated_fields,
						$language_code
					);
				}

				// Only add row if at least one translatable field has content
				$has_content = false;
				foreach ( $csv_columns as $column ) {
					if ( ! empty( $csv_row[ $column ] ) ) {
						$has_content = true;
						break;
					}
				}

				if ( $has_content ) {
					$csv_data[] = $csv_row;
				}
			}
		}

		return [
			'data'              => $csv_data,
			'columns'           => array_merge( [ 'id', 'override' ], $csv_columns ),
			'translated_fields' => $translated_fields,
		];
	}

	/**
	 * Get the value for a specific translated field using Facebook product methods
	 *
	 * @param string               $column CSV column name
	 * @param \WC_Facebook_Product $original_fb_product Original Facebook product
	 * @param \WC_Facebook_Product $translated_fb_product Translated Facebook product
	 * @param array                $product_translated_fields Fields that are translated for this product
	 * @param string               $language_code Target language code for permalink translation
	 * @return string Field value with proper validation and cleaning
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	private function get_translated_field_value(
		string $column,
		\WC_Facebook_Product $original_fb_product,
		\WC_Facebook_Product $translated_fb_product,
		array $product_translated_fields,
		string $language_code = ''
	): string {
		// Import required classes for validation
		if ( ! class_exists( '\WooCommerce\Facebook\Framework\Helper' ) ) {
			require_once WC_FACEBOOKCOMMERCE_PLUGIN_DIR . '/includes/Framework/Helper.php';
		}

		$value = '';

		switch ( $column ) {
			case 'title':
				if ( in_array( 'name', $product_translated_fields, true ) ) {
					$title = $translated_fb_product->get_name();
					$value = \WooCommerce\Facebook\Framework\Helper::str_truncate(
						\WC_Facebookcommerce_Utils::clean_string( $title ),
						\WC_Facebook_Product::MAX_TITLE_LENGTH
					);
				}
				break;

			case 'description':
				if ( in_array( 'description', $product_translated_fields, true ) ) {
					// Use get_fb_description() to match main feed behavior (strips HTML tags)
					$description = $translated_fb_product->get_fb_description();
					$value       = \WooCommerce\Facebook\Framework\Helper::str_truncate(
						$description,
						\WC_Facebook_Product::MAX_DESCRIPTION_LENGTH
					);
				}
				break;

			case 'short_description':
				if ( in_array( 'short_description', $product_translated_fields, true ) ) {
					$short_description = $translated_fb_product->get_fb_short_description();
					$value             = \WooCommerce\Facebook\Framework\Helper::str_truncate(
						$short_description,
						\WC_Facebook_Product::MAX_DESCRIPTION_LENGTH
					);
				}
				break;

			case 'rich_text_description':
				if ( in_array( 'rich_text_description', $product_translated_fields, true ) ) {
					$rich_text_description = $translated_fb_product->get_rich_text_description();
					$value                 = \WooCommerce\Facebook\Framework\Helper::str_truncate(
						$rich_text_description,
						\WC_Facebook_Product::MAX_DESCRIPTION_LENGTH
					);
				}
				break;

			case 'image_link':
				if ( in_array( 'image_id', $product_translated_fields, true ) ) {
					$image_urls = $translated_fb_product->get_all_image_urls();
					$value      = $image_urls[0] ?? '';
				}
				break;

			case 'additional_image_link':
				if ( in_array( 'gallery_image_ids', $product_translated_fields, true ) ) {
					$image_urls        = $translated_fb_product->get_all_image_urls();
					$additional_images = array_slice( $image_urls, 1, 5 ); // Max 5 additional images
					$value             = ! empty( $additional_images ) ? implode( ',', $additional_images ) : '';
				}
				break;

			case 'video':
				if ( in_array( 'video', $product_translated_fields, true ) ) {
					$video_urls = $translated_fb_product->get_all_video_urls();
					if ( ! empty( $video_urls ) ) {
						// Format video URLs as JSON array of objects with 'url' key, matching main feed format
						$value = wp_json_encode( $video_urls );
					}
				}
				break;

			case 'link':
				if ( in_array( 'link', $product_translated_fields, true ) ) {
					// Use the translated product's built-in get_permalink() method
					// This automatically handles the correct URL structure and translated slugs
					$value = $translated_fb_product->get_permalink();
				}
				break;
		}

		return $value;
	}




	/**
	 * Format price for CSV using Facebook's approach
	 *
	 * @param int    $price Price in cents
	 * @param string $currency Currency code
	 * @return string Formatted price
	 */
	private function format_price_for_csv( $price, $currency ): string {
		return (string) ( round( $price / 100.0, 2 ) ) . ' ' . $currency;
	}

	/**
	 * Get the product ID based on the content ID migration rollout switch
	 *
	 * @param \WC_Product $product Product object
	 * @return string Product ID (either simple ID or retailer ID based on GK)
	 */
	private function get_product_id( \WC_Product $product ): string {
		// Check if content ID migration is enabled
		if ( function_exists( 'facebook_for_woocommerce' ) ) {
			$rollout_switches = facebook_for_woocommerce()->get_rollout_switches();
			if ( $rollout_switches && $rollout_switches->is_switch_enabled(
				\WooCommerce\Facebook\RolloutSwitches::SWITCH_CONTENT_ID_MIGRATION_ENABLED
			) ) {
				// New behavior: Use simple WooCommerce ID
				return (string) $product->get_id();
			}
		}

		// Old behavior: Use Facebook retailer ID format
		return \WC_Facebookcommerce_Utils::get_fb_retailer_id( $product );
	}
}
