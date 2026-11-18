<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Integrations;

/**
 * Abstract base class for localization plugin integrations.
 *
 * Provides a common interface for integrating with various WordPress
 * localization/multilingual plugins like Polylang, WPML, etc.
 */
abstract class Abstract_Localization_Integration {

	/**
	 * Get the plugin file name (relative to plugins directory)
	 *
	 * @return string Plugin file name (e.g., 'polylang/polylang.php')
	 */
	abstract public function get_plugin_file_name(): string;

	/**
	 * Get the human-readable plugin name
	 *
	 * @return string Plugin name (e.g., 'Polylang')
	 */
	abstract public function get_plugin_name(): string;

	/**
	 * Check if the localization plugin is active and available
	 *
	 * @return bool True if plugin is active and functions are available
	 */
	abstract public function is_plugin_active(): bool;

	/**
	 * Check if the integration is available and properly configured
	 *
	 * An integration is considered available if:
	 * 1. The plugin is active
	 * 2. It has a valid default language configured
	 *
	 * @return bool True if integration is available and properly configured
	 */
	public function is_available(): bool {
		if ( ! $this->is_plugin_active() ) {
			return false;
		}

		// Check if the plugin has a valid default language configured
		$default_language = $this->get_default_language();
		if ( empty( $default_language ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get all available languages
	 *
	 * @return array Array of language data
	 */
	abstract public function get_available_languages(): array;

	/**
	 * Get the default language code
	 *
	 * @return string|null Default language code or null if not set
	 */
	abstract public function get_default_language(): ?string;

	/**
	 * Get the default language as the localization plugin itself identifies it
	 *
	 * This is the value to compare with a product's language. The plugin's identifier
	 * cannot be derived from the locale that get_default_language() returns: WPML's code
	 * for pt_BR is 'pt-br' and for nb_NO it is 'no', and Polylang slugs are free text.
	 *
	 * @since 3.7.9
	 *
	 * @return string|null WPML language code or Polylang slug of the default language, null if not set
	 */
	public function get_default_language_identifier(): ?string {
		$default_language_locale = $this->get_default_language();

		return $default_language_locale ? $this->get_plugin_language_identifier( $default_language_locale ) : null;
	}

	/**
	 * Check if the plugin is installed (but not necessarily active)
	 *
	 * @return bool True if plugin files exist
	 */
	public function is_plugin_installed(): bool {
		$plugin_file = WP_PLUGIN_DIR . '/' . $this->get_plugin_file_name();
		return file_exists( $plugin_file );
	}

	/**
	 * Get plugin version if available
	 *
	 * @return string|null Plugin version or null if not available
	 */
	public function get_plugin_version(): ?string {
		if ( ! $this->is_plugin_active() ) {
			return null;
		}

		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $this->get_plugin_file_name() );
		return $plugin_data['Version'] ?? null;
	}

	/**
	 * Get products from the default language
	 *
	 * Uses a template method pattern to reduce code duplication.
	 * Subclasses only need to implement the plugin-specific language checking logic.
	 *
	 * @param int $limit Maximum number of products to return (-1 for all products)
	 * @param int $offset Offset for pagination
	 * @return array Array of product IDs from the default language
	 */
	public function get_products_from_default_language( int $limit = 10, int $offset = 0 ): array {
		if ( ! $this->is_plugin_active() ) {
			return [];
		}

		$default_language_locale = $this->get_default_language();
		if ( ! $default_language_locale ) {
			return [];
		}

		// Get plugin-specific language identifier for the default language
		$default_language_identifier = $this->get_plugin_language_identifier( $default_language_locale );
		if ( ! $default_language_identifier ) {
			return [];
		}

		// Query products
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'fields'         => 'ids',
		];

		$all_products              = get_posts( $args );
		$default_language_products = [];

		// Filter products using plugin-specific language check
		foreach ( $all_products as $product_id ) {
			if ( $this->is_product_in_language( $product_id, $default_language_identifier ) ) {
				$default_language_products[] = $product_id;
			}
		}

		return $default_language_products;
	}

	/**
	 * Get the plugin-specific language identifier for a given locale
	 *
	 * Converts a full locale (e.g., 'es_ES') to the plugin's internal language identifier.
	 * For WPML, this is the WPML language code. For Polylang, this is the language slug.
	 *
	 * @param string $locale Full locale code (e.g., 'es_ES', 'fr_FR')
	 * @return string|null Plugin-specific language identifier or null if not found
	 */
	abstract protected function get_plugin_language_identifier( string $locale ): ?string;

	/**
	 * Check if a product is in a specific language
	 *
	 * Uses plugin-specific API to determine if a product belongs to a language.
	 *
	 * @param int    $product_id Product ID to check
	 * @param string $language_identifier Plugin-specific language identifier
	 * @return bool True if product is in the specified language
	 */
	abstract protected function is_product_in_language( int $product_id, string $language_identifier ): bool;

	/**
	 * Get detailed translation information for a product
	 *
	 * Default implementation that can be overridden by specific integrations.
	 *
	 * @param int $product_id Product ID (should be from default language)
	 * @return array Detailed translation information
	 */
	public function get_product_translation_details( int $product_id ): array {
		// Default implementation - return basic structure
		// Specific integrations should override this method
		return [
			'product_id'         => $product_id,
			'default_language'   => $this->get_default_language(),
			'translations'       => [],
			'translation_status' => [],
			'translated_fields'  => [],
		];
	}

	/**
	 * Switch to a specific language context
	 *
	 * This method is used by the Facebook_Fields_Translation_Trait to switch
	 * the current language context when checking for translated permalinks.
	 * Implementations should store and return the previous language code.
	 *
	 * @param string $locale Full locale code (e.g., 'es_ES', 'zh_CN')
	 * @return string|null The previous language code if successful, null otherwise
	 * @since 3.6.0
	 */
	abstract public function switch_to_language( string $locale ): ?string;

	/**
	 * Restore a previous language context
	 *
	 * This method is used by the Facebook_Fields_Translation_Trait to restore
	 * the original language context after checking for translated permalinks.
	 *
	 * @param string $language_code The language code to restore
	 * @return void
	 * @since 3.6.0
	 */
	abstract public function restore_language( string $language_code ): void;

	/**
	 * Get availability data for telemetry reporting
	 *
	 * Provides standardized data collection for integration availability logging.
	 * This method is used by the IntegrationAvailabilityLogger to collect
	 * telemetry data about which integrations are available and active.
	 *
	 * @return array Integration availability data
	 */
	public function get_availability_data(): array {
		$data = [
			'plugin_name'  => $this->get_plugin_name(),
			'plugin_file'  => $this->get_plugin_file_name(),
			'is_installed' => $this->is_plugin_installed(),
			'is_active'    => $this->is_plugin_active(),
		];

		// Add version if available
		$version = $this->get_plugin_version();
		if ( $version ) {
			$data['version'] = $version;
		}

		return $data;
	}
}
