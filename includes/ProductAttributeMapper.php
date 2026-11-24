<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook;

defined( 'ABSPATH' ) || exit;

use WC_Facebook_Product;
use WC_Product;
use WooCommerce\Facebook\Products;

/**
 * Product Attribute Mapper for WooCommerce to Meta.
 *
 * This class provides a comprehensive mapping system for WooCommerce product attributes
 * to Meta catalog fields, enhancing the default mapping with more flexibility
 * and better support for custom attributes.
 *
 * @since 3.5.4
 */
class ProductAttributeMapper {

	/** @var array Standard Facebook fields that WooCommerce attributes can map to */
	private static $standard_facebook_fields = array(
		'size'                    => array( 'size' ),
		'color'                   => array( 'color', 'colour' ),
		'pattern'                 => array( 'pattern' ),
		'material'                => array( 'material' ),
		'gender'                  => array( 'gender' ),
		'age_group'               => array( 'age_group' ),
		'brand'                   => array( 'brand', 'manufacturer' ),
		'condition'               => array( 'condition', 'state' ),
		'mpn'                     => array( 'mpn', 'manufacturer_part_number' ),
		'gtin'                    => array( 'gtin', 'upc', 'ean', 'jan', 'isbn' ),
		'google_product_category' => array( 'google_product_category', 'product_category', 'category' ),
	);

	/** @var array Extended Facebook fields based on Meta commerce platform catalog fields */
	private static $extended_facebook_fields = array(
		'sale_price' => array( 'sale_price', 'discount_price', 'offer_price' ),
		'inventory'  => array( 'inventory', 'stock', 'quantity' ),
	);

	/** @var array Maps WooCommerce attribute naming variations to standardized Meta field names */
	private static $attribute_name_mapping = array(
		// Common naming variations for color
		'product_color'     => 'color',
		'item_color'        => 'color',
		'color_family'      => 'color',

		// Common naming variations for size
		'product_size'      => 'size',
		'item_size'         => 'size',
		'shoe_size'         => 'size',
		'clothing_size'     => 'size',

		// Common naming variations for gender
		'target_gender'     => 'gender',
		'product_gender'    => 'gender',

		// Common naming variations for material
		'product_material'  => 'material',
		'fabric'            => 'material',
		'item_material'     => 'material',

		// Common naming variations for pattern
		'product_pattern'   => 'pattern',
		'design'            => 'pattern',

		// Common naming variations for age group
		'product_age_group' => 'age_group',
		'target_age'        => 'age_group',
		'age_range'         => 'age_group',

		// Common naming variations for brand
		'product_brand'     => 'brand',
		'manufacturer_name' => 'brand',

		// Common naming variations for condition
		'product_condition' => 'condition',
		'item_condition'    => 'condition',
	);

	/** @var bool Flag to track if custom mappings have been loaded */
	private static $custom_mappings_loaded = false;

	/**
	 * Initializes the attribute mappings by loading custom mappings from the database.
	 * This ensures that mappings created through the UI are available for use.
	 *
	 * @since 3.0.32
	 *
	 * @return void
	 */
	private static function load_custom_mappings() {
		if ( self::$custom_mappings_loaded ) {
			return;
		}

		// Load custom mappings from the database
		// NOTE: The option name wc_facebook_custom_attribute_mappings is defined in Admin/Settings_Screens/Product_Attributes.php
		$custom_mappings = get_option( 'wc_facebook_custom_attribute_mappings', array() );

		if ( ! empty( $custom_mappings ) && is_array( $custom_mappings ) ) {
			foreach ( $custom_mappings as $wc_attribute => $fb_field ) {
				$sanitized_attribute                                  = self::sanitize_attribute_name( $wc_attribute );
				self::$attribute_name_mapping[ $sanitized_attribute ] = $fb_field;
			}
		}

		self::$custom_mappings_loaded = true;
	}

	/**
	 * Gets all standardized Meta catalog fields.
	 *
	 * @since 3.5.4
	 *
	 * @return array Array of all supported Meta fields with their variations
	 */
	public static function get_all_facebook_fields() {
		return array_merge( self::$standard_facebook_fields, self::$extended_facebook_fields );
	}

	/**
	 * Check if a WooCommerce attribute maps to a standard Facebook field
	 *
	 * @since 3.5.4
	 *
	 * @param string $attribute_name The WooCommerce attribute name
	 * @return bool|string False if not mapped, or the Facebook field name if mapped
	 */
	public static function check_attribute_mapping( $attribute_name ) {
		// Ensure custom mappings are loaded
		self::load_custom_mappings();

		// Clean the attribute name
		$sanitized_name = self::sanitize_attribute_name( $attribute_name );

		// Check if there's a direct mapping in our attribute_name_mapping
		if ( isset( self::$attribute_name_mapping[ $sanitized_name ] ) ) {
			$result = self::$attribute_name_mapping[ $sanitized_name ];
			return $result;
		}

		// Check for exact matches in standard fields
		foreach ( self::$standard_facebook_fields as $fb_field => $possible_matches ) {
			if ( in_array( $sanitized_name, $possible_matches, true ) ) {
				return $fb_field;
			}
		}

		// If no exact match in standard fields, check if the attribute is a standard field itself
		if ( isset( self::$standard_facebook_fields[ $sanitized_name ] ) ) {
			return $sanitized_name;
		}

		// DISABLED: Fuzzy matching can lead to unpredictable attribute mappings
		// We now only use explicit mappings (from UI) and exact matches for consistent behavior
		// This ensures that attributes are only mapped when the store owner explicitly intends them to be

		return false;
	}

	/**
	 * Sanitizes an attribute name for use in custom data fields
	 *
	 * @since 2.6.0
	 *
	 * @param string $attribute_name The raw attribute name
	 * @return string
	 */
	public static function sanitize_attribute_name( $attribute_name ) {
		// First, get the original attribute name without the pa_ prefix
		$original_name = preg_replace( '/^pa_/', '', $attribute_name );

		// If the attribute is a taxonomy attribute and likely has a display name,
		// try to get the display name first (e.g., "Material" instead of "pa_material")
		if ( strpos( $attribute_name, 'pa_' ) === 0 ) {
			$taxonomy = get_taxonomy( $attribute_name );
			if ( $taxonomy && ! empty( $taxonomy->labels->singular_name ) ) {
				// Return the display name in lowercase for standardization
				return strtolower( $taxonomy->labels->singular_name );
			}
		}

		// Remove pa_ prefix from taxonomy attributes
		$attribute_name = $original_name;

		// Convert spaces and special characters to underscores
		$attribute_name = strtolower( $attribute_name );
		$attribute_name = preg_replace( '/[^a-z0-9_]/', '_', $attribute_name );
		$attribute_name = preg_replace( '/_+/', '_', $attribute_name );
		$attribute_name = trim( $attribute_name, '_' );

		return $attribute_name;
	}

	/**
	 * Get all attributes that are not mapped to standard Facebook fields
	 *
	 * @since 3.5.4
	 *
	 * @param WC_Product $product The WooCommerce product
	 * @return array Array of unmapped attributes with 'name' and 'value' keys
	 */
	public static function get_unmapped_attributes( WC_Product $product ) {
		// Ensure custom mappings are loaded first
		self::load_custom_mappings();

		$unmapped_attributes = array();
		$attributes          = $product->get_attributes();

		foreach ( $attributes as $attribute_name => $_ ) {
			$value = $product->get_attribute( $attribute_name );

			if ( ! empty( $value ) ) {
				// Use the comprehensive check_attribute_mapping method to determine if mapped
				$mapped_field = self::check_attribute_mapping( $attribute_name );

				// If no mapping found, it's unmapped
				if ( false === $mapped_field ) {
					$unmapped_attributes[] = array(
						'name'  => $attribute_name,
						'value' => $value,
					);
				}
			}
		}

		return $unmapped_attributes;
	}

	/**
	 * Gets all mapped attributes for a product.
	 *
	 * @since 3.5.4
	 *
	 * @param WC_Product $product The WooCommerce product
	 * @return array Array of mapped attributes with Meta field name as key and attribute value as value
	 */
	public static function get_mapped_attributes( WC_Product $product ) {
		// Ensure custom mappings are loaded
		self::load_custom_mappings();

		$mapped_attributes = array();
		$attributes        = $product->get_attributes();

		// Get manual attribute mappings from the plugin settings
		$custom_mappings = get_option( 'wc_facebook_custom_attribute_mappings', array() );

		// Filters the product attribute mappings.
		/**
		 * Filters the product attribute mappings.
		 *
		 * @since 3.5.4
		 *
		 * @param array      $mappings The attribute mappings
		 * @param WC_Product $product  The product object
		 */
		$filtered_mappings = apply_filters( 'wc_facebook_product_attribute_mappings', array(), $product );

		// Create a map to track exact slug matches first
		$slug_to_fb_field = array(
			'brand'     => 'brand',
			'age-group' => 'age_group',
			'age_group' => 'age_group',
			'agegroup'  => 'age_group',
			'gender'    => 'gender',
			'material'  => 'material',
			'condition' => 'condition',
			'color'     => 'color',
			'colour'    => 'color',
			'size'      => 'size',
			'pattern'   => 'pattern',
			'mpn'       => 'mpn',
		);

		// Store attributes that have already been processed to avoid duplicates
		$processed_fb_fields = array();

		// Get a complete list of standard Facebook fields for prioritization
		$standard_fields = array( 'brand', 'color', 'size', 'pattern', 'material', 'gender', 'age_group', 'condition', 'mpn' );

		// Create a priority map to resolve conflicts - higher number = higher priority
		$priority_map = array(
			'direct_match'  => 100,     // Highest priority - direct match by attribute name
			'slug_match'    => 80,        // High priority - slug match
			'mapped'        => 60,            // Medium priority - mapped via check_attribute_mapping
			'custom_mapped' => 50,     // Medium priority - manually mapped via UI (reduced from 90)
			'meta'          => 20,               // Low-medium priority - meta value
		);

		// Track attribute sources and their priorities for later conflict resolution
		$attribute_sources = array();

		// PHASE 0: First process any custom attribute mappings set via the UI
		foreach ( $filtered_mappings as $wc_attr_name => $fb_field ) {
			// Find the attribute in the product
			foreach ( $attributes as $attribute_key => $attribute ) {
				$clean_key  = self::sanitize_attribute_name( $attribute_key );
				$clean_name = self::sanitize_attribute_name( $wc_attr_name );

				if ( $clean_key === $clean_name ) {
					$value = $product->get_attribute( $attribute_key );

					if ( ! empty( $value ) ) {
						// Save the attribute with its priority
						if ( ! isset( $attribute_sources[ $fb_field ] ) || $priority_map['custom_mapped'] > $attribute_sources[ $fb_field ]['priority'] ) {
							$attribute_sources[ $fb_field ] = array(
								'value'    => $value,
								'priority' => $priority_map['custom_mapped'],
								'source'   => "UI mapping: {$wc_attr_name}",
							);
						}
					}
				}
			}
		}

		// PHASE 1: Now check for direct standard field matches by attribute name
		foreach ( $attributes as $attribute_name => $attribute ) {
			$value = $product->get_attribute( $attribute_name );

			if ( ! empty( $value ) ) {
				if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && strpos( $value, ', ' ) !== false ) {
					$value = str_replace( ', ', ' | ', $value );
				}

				// Clean up attribute name for matching
				$clean_name = self::sanitize_attribute_name( $attribute_name );

				// Direct match with standard fields - fix: also check attribute display name
				foreach ( self::$standard_facebook_fields as $fb_field => $possible_matches ) {
					// Get the attribute's display name by removing "pa_" prefix and converting underscores to spaces
					$display_name       = ucfirst( str_replace( '_', ' ', preg_replace( '/^pa_/', '', $attribute_name ) ) );
					$lower_display_name = strtolower( $display_name );

					// Also check display name with hyphens converted to underscores (for attributes like "age-group")
					$display_name_with_underscores = str_replace( '-', '_', $lower_display_name );

					// If the attribute name exactly matches the standard field name OR
					// the display name matches the field name (case insensitive) OR
					// the display name with hyphens converted to underscores matches
					if ( $clean_name === $fb_field || $lower_display_name === $fb_field || $display_name_with_underscores === $fb_field ) {
						// Save the attribute with its priority
						if ( ! isset( $attribute_sources[ $fb_field ] ) || $priority_map['direct_match'] > $attribute_sources[ $fb_field ]['priority'] ) {
							$attribute_sources[ $fb_field ] = array(
								'value'    => $value,
								'priority' => $priority_map['direct_match'],
								'source'   => "Direct match: {$attribute_name}",
							);
						}
						break;
					}
				}
			}
		}

		// PHASE 2: Look for exact slug matches
		foreach ( $attributes as $attribute_name => $attribute ) {
			$value = $product->get_attribute( $attribute_name );

			if ( ! empty( $value ) ) {
				// Fix delimiter issue: WooCommerce uses commas for global attributes, but we need pipes
				if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && strpos( $value, ', ' ) !== false ) {
					$value = str_replace( ', ', ' | ', $value );
				}

				// Get both the original slug (with hyphens) and sanitized name (with underscores)
				$original_slug = preg_replace( '/^pa_/', '', $attribute_name ); // Remove pa_ but keep hyphens
				$clean_name    = self::sanitize_attribute_name( $attribute_name ); // This converts hyphens to underscores

				// Check for exact match in our slug mapping using both forms
				$fb_field = null;
				if ( isset( $slug_to_fb_field[ $original_slug ] ) ) {
					$fb_field = $slug_to_fb_field[ $original_slug ];
				} elseif ( isset( $slug_to_fb_field[ $clean_name ] ) ) {
					$fb_field = $slug_to_fb_field[ $clean_name ];
				}

				if ( $fb_field ) {
					// Save the attribute with its priority
					if ( ! isset( $attribute_sources[ $fb_field ] ) || $priority_map['slug_match'] > $attribute_sources[ $fb_field ]['priority'] ) {
						$attribute_sources[ $fb_field ] = array(
							'value'    => $value,
							'priority' => $priority_map['slug_match'],
							'source'   => "Slug match: {$attribute_name}",
						);
					}
				}
			}
		}

		// PHASE 3: Look for mapped attributes via check_attribute_mapping
		foreach ( $attributes as $attribute_name => $attribute ) {
			$value = $product->get_attribute( $attribute_name );

			if ( ! empty( $value ) && ! empty( $attribute_name ) ) {
				// Fix delimiter issue: WooCommerce uses commas for global attributes, but we need pipes
				if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() && strpos( $value, ', ' ) !== false ) {
					$value = str_replace( ', ', ' | ', $value );
				}

				$mapped_field = self::check_attribute_mapping( $attribute_name );

				// Skip if no mapping found
				if ( false !== $mapped_field ) {
					// Normalize certain field values to conform to Facebook requirements
					$original_value = $value;
					switch ( $mapped_field ) {
						case 'gender':
							$value = self::normalize_gender_value( $value );
							break;
						case 'age_group':
							$value = self::normalize_age_group_value( $value );
							break;
						case 'condition':
							$value = self::normalize_condition_value( $value );
							break;
					}

					// Save the attribute with its priority
					if ( ! isset( $attribute_sources[ $mapped_field ] ) || $priority_map['mapped'] > $attribute_sources[ $mapped_field ]['priority'] ) {
						$attribute_sources[ $mapped_field ] = array(
							'value'    => $value,
							'priority' => $priority_map['mapped'],
							'source'   => "Mapped via check_attribute_mapping: {$attribute_name}",
						);
					}
				}
			}
		}

		// PHASE 4: For fields not found in product attributes, check meta values
		foreach ( $standard_fields as $field ) {
			// Check meta values only if we haven't found a higher priority source
			if ( ! isset( $attribute_sources[ $field ] ) || $attribute_sources[ $field ]['priority'] < $priority_map['meta'] ) {
				// Check for alternative storage in dedicated meta fields
				$meta_key   = '_wc_facebook_enhanced_catalog_attributes_' . $field;
				$meta_value = $product->get_meta( $meta_key, true );

				if ( ! empty( $meta_value ) ) {
					$attribute_sources[ $field ] = array(
						'value'    => $meta_value,
						'priority' => $priority_map['meta'],
						'source'   => 'Meta value',
					);
				}
			}
		}

		// Now build the final mapped attributes based on priority
		foreach ( $attribute_sources as $fb_field => $source_data ) {
			$mapped_attributes[ $fb_field ] = $source_data['value'];
		}

		return $mapped_attributes;
	}

	/**
	 * Normalizes gender values to Facebook's expected format.
	 *
	 * @since 3.5.4
	 *
	 * @param string $value The original gender value
	 * @return string Normalized gender value
	 */
	public static function normalize_gender_value( $value ) {
		$value = strtolower( trim( $value ) );

		// Map common gender values to Facebook's expected values
		$gender_map = array(
			'men'       => WC_Facebook_Product::GENDER_MALE,
			'man'       => WC_Facebook_Product::GENDER_MALE,
			'boy'       => WC_Facebook_Product::GENDER_MALE,
			'boys'      => WC_Facebook_Product::GENDER_MALE,
			'masculine' => WC_Facebook_Product::GENDER_MALE,

			'women'     => WC_Facebook_Product::GENDER_FEMALE,
			'woman'     => WC_Facebook_Product::GENDER_FEMALE,
			'girl'      => WC_Facebook_Product::GENDER_FEMALE,
			'girls'     => WC_Facebook_Product::GENDER_FEMALE,
			'feminine'  => WC_Facebook_Product::GENDER_FEMALE,

			'unisex'    => WC_Facebook_Product::GENDER_UNISEX,
			'uni sex'   => WC_Facebook_Product::GENDER_UNISEX,
			'uni-sex'   => WC_Facebook_Product::GENDER_UNISEX,
			'neutral'   => WC_Facebook_Product::GENDER_UNISEX,
			'all'       => WC_Facebook_Product::GENDER_UNISEX,
		);

		return isset( $gender_map[ $value ] ) ? $gender_map[ $value ] : $value;
	}

	/**
	 * Normalizes age group values to Facebook's expected format.
	 *
	 * @since 3.5.4
	 *
	 * @param string $value The original age group value
	 * @return string Normalized age group value
	 */
	public static function normalize_age_group_value( $value ) {
		$value = strtolower( trim( $value ) );

		// Map common age group values to Facebook's expected values
		$age_group_map = array(
			'adult'      => WC_Facebook_Product::AGE_GROUP_ADULT,
			'adults'     => WC_Facebook_Product::AGE_GROUP_ADULT,
			'grown-up'   => WC_Facebook_Product::AGE_GROUP_ADULT,
			'grownup'    => WC_Facebook_Product::AGE_GROUP_ADULT,

			'all ages'   => WC_Facebook_Product::AGE_GROUP_ALL_AGES,
			'everyone'   => WC_Facebook_Product::AGE_GROUP_ALL_AGES,
			'any'        => WC_Facebook_Product::AGE_GROUP_ALL_AGES,

			'teen'       => WC_Facebook_Product::AGE_GROUP_TEEN,
			'teens'      => WC_Facebook_Product::AGE_GROUP_TEEN,
			'teenager'   => WC_Facebook_Product::AGE_GROUP_TEEN,
			'teenagers'  => WC_Facebook_Product::AGE_GROUP_TEEN,
			'adolescent' => WC_Facebook_Product::AGE_GROUP_TEEN,

			'kid'        => WC_Facebook_Product::AGE_GROUP_KIDS,
			'kids'       => WC_Facebook_Product::AGE_GROUP_KIDS,
			'child'      => WC_Facebook_Product::AGE_GROUP_KIDS,
			'children'   => WC_Facebook_Product::AGE_GROUP_KIDS,

			'toddler'    => WC_Facebook_Product::AGE_GROUP_TODDLER,
			'toddlers'   => WC_Facebook_Product::AGE_GROUP_TODDLER,

			'infant'     => WC_Facebook_Product::AGE_GROUP_INFANT,
			'infants'    => WC_Facebook_Product::AGE_GROUP_INFANT,
			'baby'       => WC_Facebook_Product::AGE_GROUP_INFANT,
			'babies'     => WC_Facebook_Product::AGE_GROUP_INFANT,

			'newborn'    => WC_Facebook_Product::AGE_GROUP_NEWBORN,
			'newborns'   => WC_Facebook_Product::AGE_GROUP_NEWBORN,
		);

		return isset( $age_group_map[ $value ] ) ? $age_group_map[ $value ] : $value;
	}

	/**
	 * Normalizes condition values to Facebook's expected format.
	 *
	 * @since 3.5.4
	 *
	 * @param string $value The original condition value
	 * @return string Normalized condition value
	 */
	private static function normalize_condition_value( $value ) {
		$value = strtolower( trim( $value ) );

		// Map common condition values to Facebook's expected values
		$condition_map = array(
			'new'           => WC_Facebook_Product::CONDITION_NEW,
			'brand new'     => WC_Facebook_Product::CONDITION_NEW,
			'brand-new'     => WC_Facebook_Product::CONDITION_NEW,
			'newest'        => WC_Facebook_Product::CONDITION_NEW,
			'sealed'        => WC_Facebook_Product::CONDITION_NEW,

			'used'          => WC_Facebook_Product::CONDITION_USED,
			'pre-owned'     => WC_Facebook_Product::CONDITION_USED,
			'preowned'      => WC_Facebook_Product::CONDITION_USED,
			'pre owned'     => WC_Facebook_Product::CONDITION_USED,
			'second hand'   => WC_Facebook_Product::CONDITION_USED,
			'secondhand'    => WC_Facebook_Product::CONDITION_USED,
			'second-hand'   => WC_Facebook_Product::CONDITION_USED,

			'refurbished'   => WC_Facebook_Product::CONDITION_REFURBISHED,
			'renewed'       => WC_Facebook_Product::CONDITION_REFURBISHED,
			'refreshed'     => WC_Facebook_Product::CONDITION_REFURBISHED,
			'reconditioned' => WC_Facebook_Product::CONDITION_REFURBISHED,
		);

		return isset( $condition_map[ $value ] ) ? $condition_map[ $value ] : $value;
	}

	/**
	 * Adds a custom mapping from a WooCommerce attribute to a Facebook field.
	 *
	 * @since 3.5.4
	 *
	 * @param string $wc_attribute The WooCommerce attribute name
	 * @param string $fb_field The Facebook field to map to
	 * @return bool Whether the mapping was added successfully
	 */
	public static function add_custom_attribute_mapping( $wc_attribute, $fb_field ) {
		$sanitized_attribute = self::sanitize_attribute_name( $wc_attribute );

		// Make sure the Facebook field is valid
		$all_fields = array_keys( self::get_all_facebook_fields() );
		if ( ! in_array( $fb_field, $all_fields, true ) ) {
			return false;
		}

		// Add the mapping
		self::$attribute_name_mapping[ $sanitized_attribute ] = $fb_field;
		return true;
	}

	/**
	 * Removes a custom attribute mapping.
	 *
	 * @since 3.5.4
	 *
	 * @param string $wc_attribute The WooCommerce attribute name
	 * @return bool Whether the mapping was removed successfully
	 */
	public static function remove_custom_attribute_mapping( $wc_attribute ) {
		$sanitized_attribute = self::sanitize_attribute_name( $wc_attribute );

		if ( isset( self::$attribute_name_mapping[ $sanitized_attribute ] ) ) {
			unset( self::$attribute_name_mapping[ $sanitized_attribute ] );
			return true;
		}

		return false;
	}

	/**
	 * Sets all custom mappings from an associative array.
	 *
	 * @since 3.5.4
	 *
	 * @param array $mappings Associative array of WooCommerce attribute => Facebook field
	 * @return int Number of successfully added mappings
	 */
	public static function set_custom_attribute_mappings( array $mappings ) {
		$success_count = 0;

		foreach ( $mappings as $wc_attribute => $fb_field ) {
			if ( self::add_custom_attribute_mapping( $wc_attribute, $fb_field ) ) {
				++$success_count;
			}
		}

		// Mark custom mappings as loaded
		self::$custom_mappings_loaded = true;

		return $success_count;
	}

	/**
	 * Gets all currently defined custom attribute mappings.
	 *
	 * @since 3.5.4
	 *
	 * @return array Associative array of custom attribute mappings
	 */
	public static function get_custom_attribute_mappings() {
		return self::$attribute_name_mapping;
	}

	/**
	 * Prepares a product's attributes for Facebook according to the mapping.
	 *
	 * @since 3.5.4
	 *
	 * @param WC_Product $product The WooCommerce product
	 * @return array Array of Facebook-mapped attributes ready for the API
	 */
	public static function prepare_product_attributes_for_facebook( WC_Product $product ) {
		$mapped_attributes   = self::get_mapped_attributes( $product );
		$fb_ready_attributes = array();

		// Process each mapped attribute according to Facebook's requirements
		foreach ( $mapped_attributes as $fb_field => $value ) {
			switch ( $fb_field ) {
				case 'gender':
				case 'age_group':
				case 'condition':
					// These fields are already normalized
					$fb_ready_attributes[ $fb_field ] = $value;
					break;

				case 'color':
				case 'size':
				case 'pattern':
				case 'material':
				case 'brand':
				case 'mpn':
					// These fields should be trimmed and limited
					$fb_ready_attributes[ $fb_field ] = substr( trim( $value ), 0, 100 );
					break;

				default:
					// For all other fields, just pass the value
					$fb_ready_attributes[ $fb_field ] = $value;
					break;
			}
		}

		return $fb_ready_attributes;
	}

	/**
	 * Gets mapped attributes that correspond to the standard Facebook product fields.
	 *
	 * @since 2.6.0
	 *
	 * @param \WC_Product $product the product object
	 * @return array
	 */
	public static function get_mapped_standard_attributes( \WC_Product $product ) {
		$all_mapped_attributes = self::get_mapped_attributes( $product );
		$standard_field_names  = array(
			'brand',
			'condition',
			'gender',
			'color',
			'size',
			'pattern',
			'material',
			'age_group',
		);

		$standard_attributes = array();
		foreach ( $standard_field_names as $field ) {
			if ( isset( $all_mapped_attributes[ $field ] ) ) {
				$standard_attributes[ $field ] = $all_mapped_attributes[ $field ];
			}
		}

		return $standard_attributes;
	}

	/**
	 * Saves mapped attributes to product meta.
	 *
	 * @since 3.5.4
	 *
	 * @param WC_Product $product The WooCommerce product
	 * @param array      $mapped_attributes Array of mapped attributes to save (optional, if not provided will map attributes first)
	 * @return array The mapped attributes that were saved
	 */
	public static function save_mapped_attributes( WC_Product $product, $mapped_attributes = null ) {
		if ( null === $mapped_attributes ) {
			$mapped_attributes = self::get_mapped_attributes( $product );
		}

		$product_id = $product->get_id();

		// Save each mapped attribute to product meta using the correct meta keys
		foreach ( $mapped_attributes as $field_name => $value ) {
			switch ( $field_name ) {
				// Standard Facebook fields - these use fb_ prefix
				case 'brand':
					$meta_key = 'fb_brand';
					break;
				case 'color':
					$meta_key = 'fb_color';
					break;
				case 'material':
					$meta_key = 'fb_material';
					break;
				case 'size':
					$meta_key = 'fb_size';
					break;
				case 'pattern':
					$meta_key = 'fb_pattern';
					break;
				case 'age_group':
					$meta_key = 'fb_age_group';
					break;
				case 'gender':
					$meta_key = 'fb_gender';
					break;
				case 'condition':
					$meta_key = 'fb_product_condition';
					break;
				case 'mpn':
					$meta_key = 'fb_mpn';
					break;
				case 'gtin':
					$meta_key = 'fb_gtin';
					break;

				// Extended Facebook fields - these use different patterns
				case 'sale_price':
					$meta_key = '_wc_facebook_sale_price';
					break;
				case 'inventory':
					$meta_key = '_wc_facebook_inventory';
					break;
				case 'shipping_weight':
					$meta_key = '_wc_facebook_shipping_weight';
					break;
				case 'shipping':
					$meta_key = '_wc_facebook_shipping';
					break;
				case 'tax':
					$meta_key = '_wc_facebook_tax';
					break;
				case 'image_link':
					$meta_key = '_wc_facebook_image_link';
					break;
				case 'additional_image_link':
					$meta_key = '_wc_facebook_additional_image_link';
					break;

				// For any other extended fields or unknown fields
				default:
					// Use enhanced catalog attributes pattern for other fields
					$meta_key = '_wc_facebook_enhanced_catalog_attributes_' . $field_name;
					break;
			}

			// Update the meta value
			update_post_meta( $product_id, $meta_key, $value );
		}

		// Clear WordPress meta cache to ensure fresh values are read
		wp_cache_delete( $product_id, 'post_meta' );

		if ( ! get_transient( 'fb_feed_generation_mode' ) ) {
			// Also clear WooCommerce product cache
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}

			// Clear any object cache for this product
			clean_post_cache( $product_id );
		}

		return $mapped_attributes;
	}

	/**
	 * Gets mapped attributes and saves them to product meta in one operation.
	 *
	 * @since 3.5.4
	 *
	 * @param WC_Product $product The WooCommerce product
	 * @return array The mapped attributes that were saved
	 */
	public static function get_and_save_mapped_attributes( WC_Product $product ) {
		try {
			$mapped_attributes = self::get_mapped_attributes( $product );
			$result            = self::save_mapped_attributes( $product, $mapped_attributes );
			return $result;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'ProductAttributeMapper sync error: ' . $e->getMessage() );
			return array();
		}
	}

	/**
	 * Initialize the attribute mapper.
	 *
	 * @since 3.5.4
	 *
	 * @return void
	 */
	public static function init() {
		// Load custom mappings when the class is initialized
		self::load_custom_mappings();
	}
}

// Initialize when WooCommerce is fully loaded
add_action( 'woocommerce_init', array( 'WooCommerce\Facebook\ProductAttributeMapper', 'init' ), 10 );

// Also try initializing on plugins_loaded as a fallback
add_action( 'plugins_loaded', array( 'WooCommerce\Facebook\ProductAttributeMapper', 'init' ), 20 );
