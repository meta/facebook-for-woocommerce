<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Integrations;

use WooCommerce\Facebook\WPMLLanguageStatus;

/**
 * WPML integration for Meta for WooCommerce.
 *
 * Handles integration with the WPML multilingual plugin to manage
 * product synchronization based on language settings.
 */
class WPML extends Abstract_Localization_Integration {

	use Facebook_Fields_Translation_Trait;

	/**
	 * Get the plugin file name
	 *
	 * @return string
	 */
	public function get_plugin_file_name(): string {
		return 'sitepress-multilingual-cms/sitepress.php';
	}

	/**
	 * Get the plugin name
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return 'WPML';
	}

	/**
	 * Check if WPML is active and functions are available
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active( 'sitepress-multilingual-cms/sitepress.php' ) ) {
			return false;
		}

		// Check for required constants
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return false;
		}

		// For basic detection, we don't require the full sitepress object
		// This allows the integration to be detected even if WPML isn't fully initialized
		return true;
	}

	/**
	 * Get all available languages
	 *
	 * @return array Array of language data
	 */
	public function get_available_languages(): array {
		if ( ! $this->is_plugin_active() ) {
			return [];
		}

		/** Use WPML filter to get active languages */
		$languages = apply_filters( 'wpml_active_languages', null );
		if ( is_array( $languages ) ) {
			$locales = [];
			foreach ( $languages as $language_data ) {
				// Use default_locale if available, fallback to language code
				if ( isset( $language_data['default_locale'] ) && ! empty( $language_data['default_locale'] ) ) {
					$locales[] = $language_data['default_locale'];
				} elseif ( isset( $language_data['code'] ) ) {
					$locales[] = $language_data['code'];
				}
			}
			return $locales;
		}

		return [];
	}

	/**
	 * Get the default language code
	 *
	 * @return string|null Default language code or null if not set
	 */
	public function get_default_language(): ?string {
		if ( ! $this->is_plugin_active() ) {
			return null;
		}

		/** Use WPML filter to get default language */
		$default_code = apply_filters( 'wpml_default_language', null );

		if ( ! $default_code ) {
			return null;
		}

		/** Get the full locale for the default language */
		$languages = apply_filters( 'wpml_active_languages', null );
		if ( is_array( $languages ) && isset( $languages[ $default_code ] ) ) {
			$language_data = $languages[ $default_code ];
			if ( isset( $language_data['default_locale'] ) && ! empty( $language_data['default_locale'] ) ) {
				return $language_data['default_locale'];
			}
		}

		// Fallback to the short code if no locale is found
		return $default_code;
	}

	/**
	 * Get the language code for a specific product
	 *
	 * @param int $product_id Product ID
	 * @return string|null Language code or null if not found
	 */
	public function get_product_language( int $product_id ): ?string {
		if ( ! $this->is_plugin_active() ) {
			return null;
		}

		/** Use WPML filter to get product language details */
		$language_details = apply_filters( 'wpml_post_language_details', null, $product_id );

		if ( $language_details && isset( $language_details['language_code'] ) ) {
			return $language_details['language_code'];
		}

		return null;
	}

	/**
	 * Switch to a specific language context
	 *
	 * @param string $locale Full locale code (e.g., 'es_ES', 'zh_CN')
	 * @return string|null The previous language code if successful, null otherwise
	 */
	public function switch_to_language( string $locale ): ?string {
		if ( ! $this->is_plugin_active() ) {
			return null;
		}

		/** Store original language */
		$original_lang = apply_filters( 'wpml_current_language', null );

		/** Get the WPML language code for this locale */
		$wpml_languages = apply_filters( 'wpml_active_languages', null );
		$wpml_lang_code = null;

		if ( is_array( $wpml_languages ) ) {
			// Search for the language that matches this locale
			foreach ( $wpml_languages as $code => $language_data ) {
				$locale_match = $language_data['default_locale'] ?? $code;
				if ( $locale_match === $locale ) {
					$wpml_lang_code = $code;
					break;
				}
			}
		}

		// Fallback: extract language code from locale
		if ( ! $wpml_lang_code ) {
			$lang_parts     = explode( '_', $locale );
			$wpml_lang_code = $lang_parts[0];
		}

		/** Switch to target language */
		do_action( 'wpml_switch_language', $wpml_lang_code );

		return $original_lang;
	}

	/**
	 * Restore a previous language context
	 *
	 * @param string $language_code The language code to restore
	 * @return void
	 */
	public function restore_language( string $language_code ): void {
		if ( ! $this->is_plugin_active() ) {
			return;
		}

		do_action( 'wpml_switch_language', $language_code );
	}

	/**
	 * Runs a callback with WPML switched to a language, then switches back.
	 *
	 * WPML narrows term and post queries to its current language in every context: the
	 * admin's language switcher selection on admin and admin-ajax requests, the default
	 * language in WP-Cron. Pass a language code to pin a query to it, or 'all' to query
	 * every language. Nothing is switched when WPML is not active, when no language is
	 * given, or when WPML is already on that language, so the common case has no side
	 * effects. The previous language is restored even if the callback throws. Polylang is
	 * unaffected: it reads the query's own 'lang' argument instead.
	 *
	 * @since 3.7.9
	 *
	 * @param string|null $language WPML language code or 'all'; null or '' leaves the language alone
	 * @param callable    $callback the work to run while switched
	 * @return mixed the callback's return value
	 */
	public static function run_in_language( ?string $language, callable $callback ) {
		$current = apply_filters( 'wpml_current_language', null );
		$switch  = null !== $current && ! empty( $language ) && $language !== $current;

		if ( $switch ) {
			do_action( 'wpml_switch_language', $language );
		}

		try {
			return $callback();
		} finally {
			if ( $switch ) {
				do_action( 'wpml_switch_language', $current );
			}
		}
	}

	/**
	 * Get the plugin-specific language identifier for a given locale
	 *
	 * Converts a full locale (e.g., 'es_ES') to WPML's language code.
	 *
	 * @param string $locale Full locale code (e.g., 'es_ES', 'fr_FR')
	 * @return string|null WPML language code or null if not found
	 */
	protected function get_plugin_language_identifier( string $locale ): ?string {
		$wpml_languages = apply_filters( 'wpml_active_languages', null );

		if ( is_array( $wpml_languages ) ) {
			foreach ( $wpml_languages as $code => $language_data ) {
				$language_locale = $language_data['default_locale'] ?? $code;
				if ( $language_locale === $locale ) {
					return $code;
				}
			}
		}

		// Fallback: use the locale as the code
		return $locale;
	}

	/**
	 * Check if a product is in a specific language
	 *
	 * @param int    $product_id Product ID to check
	 * @param string $language_identifier WPML language code
	 * @return bool True if product is in the specified language
	 */
	protected function is_product_in_language( int $product_id, string $language_identifier ): bool {
		$product_language = apply_filters( 'wpml_post_language_details', null, $product_id );

		if ( $product_language && isset( $product_language['language_code'] ) ) {
			return $product_language['language_code'] === $language_identifier;
		}

		return false;
	}

	/**
	 * Get products from the default language
	 *
	 * Uses WPML's API to find products that are in the default language.
	 * This ensures we're working with the original products, not translations.
	 *
	 * @param int $limit Maximum number of products to return (-1 for all products, matching legacy feed behavior)
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

		// Use a larger batch size to account for translations when limit is specified
		// We need to get more products initially because some will be filtered out
		if ( -1 !== $limit ) {
			$batch_size = max( $limit * 3, 50 ); // Get 3x the requested amount or minimum 50
		} else {
			$batch_size = $limit; // Use -1 for all products (legacy approach)
		}

		// Query products
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $batch_size,
			'offset'         => $offset,
			'fields'         => 'ids',
		];

		$all_products              = get_posts( $args );
		$default_language_products = [];

		// Filter products using plugin-specific language check
		foreach ( $all_products as $product_id ) {
			if ( $this->is_product_in_language( $product_id, $default_language_identifier ) ) {
				$default_language_products[] = $product_id;

				// Stop when we have enough products (unless $limit = -1, which means get all)
				if ( -1 !== $limit && count( $default_language_products ) >= $limit ) {
					break;
				}
			}
		}

		return $default_language_products;
	}

	/**
	 * Get detailed translation information for a product
	 *
	 * Uses WPML's API to get comprehensive translation data including
	 * which fields are translated and translation status.
	 *
	 * @param int $product_id Product ID (should be from default language)
	 * @return array Detailed translation information
	 */
	public function get_product_translation_details( int $product_id ): array {
		if ( ! $this->is_plugin_active() ) {
			return [];
		}

		$details = [
			'product_id'         => $product_id,
			'default_language'   => $this->get_default_language(),
			'translations'       => [],
			'translation_status' => [],
		];

		/** Get the mapping between full locales and WPML language codes */
		$wpml_languages = apply_filters( 'wpml_active_languages', null );
		if ( ! is_array( $wpml_languages ) ) {
			return $details;
		}

		$locale_to_code_map = [];
		$code_to_locale_map = [];
		foreach ( $wpml_languages as $code => $language_data ) {
			$locale                        = $language_data['default_locale'] ?? $code;
			$locale_to_code_map[ $locale ] = $code;
			$code_to_locale_map[ $code ]   = $locale;
		}

		$languages        = $this->get_available_languages(); // This now returns full locales
		$default_language = $this->get_default_language(); // This now returns full locale

		foreach ( $languages as $full_locale ) {
			// Skip the default language
			if ( $full_locale === $default_language ) {
				continue;
			}

			// Get the WPML language code for this locale
			$wpml_code = $locale_to_code_map[ $full_locale ] ?? $full_locale;

			/** Get translated product ID using the WPML language code */
			$translated_id = apply_filters( 'wpml_object_id', $product_id, 'post', false, $wpml_code );

			if ( $translated_id && $translated_id !== $product_id ) {
				// Store using the full locale as the key
				$details['translations'][ $full_locale ] = $translated_id;

				/** Get translation status using WPML's API with the WPML code */
				$translation_status                            = apply_filters( 'wpml_translation_status', null, $product_id, $wpml_code );
				$details['translation_status'][ $full_locale ] = $translation_status;

				// Get which fields are translated
				$details['translated_fields'][ $full_locale ] = $this->get_translated_fields( $product_id, $translated_id, $full_locale );
			}
		}

		return $details;
	}

	/**
	 * Get availability data for telemetry reporting
	 *
	 * Extends the base method to include WPML-specific features.
	 *
	 * @return array Integration availability data
	 */
	public function get_availability_data(): array {
		$data = parent::get_availability_data();

		if ( $this->is_plugin_active() ) {
			$data['languages']                       = $this->get_available_languages();
			$data['default_language']                = $this->get_default_language();
			$data['has_legacy_multi_language_setup'] = $this->has_legacy_multi_language_setup();
		}

		return $data;
	}

	/**
	 * Get the integration status.
	 *
	 * Returns a status string indicating the current state of the integration:
	 * - "Active" - Plugin is active and properly configured
	 * - "Active - Ineligible" - Plugin is active but using legacy multi-language setup (ineligible for override feeds)
	 * - "Installed" - Plugin is installed but not active
	 * - "Not Available" - Plugin is not installed
	 * - "Misconfigured" - Plugin is active but missing required configuration
	 *
	 * @return string Integration status
	 * @since 3.6.0
	 */
	public function get_integration_status(): string {
		// Check if plugin is installed
		if ( ! $this->is_plugin_installed() ) {
			return 'Not Available';
		}

		// Check if plugin is active
		if ( ! $this->is_plugin_active() ) {
			return 'Installed';
		}

		// Check if properly configured (has default language)
		if ( ! $this->is_available() ) {
			return 'Misconfigured';
		}

		// Check if using legacy multi-language setup
		if ( $this->has_legacy_multi_language_setup() ) {
			return 'Active - Ineligible';
		}

		return 'Active';
	}

	/**
	 * Checks if WPML is using the legacy multi-language setup (syncing translations as separate products).
	 *
	 * Legacy WPML users who have multiple languages selected in fb_wmpl_language_visibility
	 * should NOT use language override feeds to prevent duplicate products.
	 *
	 * @return bool True if using legacy multi-language setup
	 * @since 3.6.0
	 */
	public function has_legacy_multi_language_setup(): bool {
		// Only check if WPML is active
		if ( ! $this->is_plugin_active() ) {
			return false;
		}

		// Check the fb_wmpl_language_visibility option (from fbwpml.php)
		$wpml_settings = get_option( 'fb_wmpl_language_visibility', array() );

		if ( empty( $wpml_settings ) || ! is_array( $wpml_settings ) ) {
			return false;
		}

		// Count how many languages are set to VISIBLE
		$visible_languages = array_filter(
			$wpml_settings,
			function ( $status ) {
				return WPMLLanguageStatus::VISIBLE === $status;
			}
		);

		// If more than 1 language is visible, they're using legacy multi-language sync
		return count( $visible_languages ) > 1;
	}

	/**
	 * Checks if this integration is eligible for language override feeds.
	 *
	 * WPML is ineligible if using legacy multi-language setup.
	 *
	 * @return bool True if eligible for language override feeds
	 * @since 3.6.0
	 */
	public function is_eligible_for_language_override_feeds(): bool {
		return ! $this->has_legacy_multi_language_setup();
	}
}
