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

use WC_Facebook_Product;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;
use WooCommerce\Facebook\Handlers\PluginRender;

defined( 'ABSPATH' ) || exit;

/**
 * Products handler.
 *
 * @since 1.10.0
 */
class Products {

	/** @var string the meta key used to flag whether a product should be synced in Facebook */
	const SYNC_ENABLED_META_KEY = '_wc_facebook_sync_enabled';

	/** @var string the meta key used to flag whether a product should be synced in Facebook for woo all products opted in users */
	const OPTEN_IN_SYNC_ENABLED_META_KEY = '_wc_facebook_sync_enabled_v2';

	// TODO probably we'll want to run some upgrade routine or somehow move meta keys to follow the same patter e.g. _wc_facebook_visibility {FN 2020-01-17}
	/** @var string the meta key used to flag whether a product should be visible in Facebook */
	const VISIBILITY_META_KEY = 'fb_visibility';

	/** @var string the meta key used to the source of the product  in Facebook */
	const PRODUCT_IMAGE_SOURCE_META_KEY = '_wc_facebook_product_image_source';

	/** @var string product image source option to use the product image of simple products or the variation image of variations in Facebook */
	const PRODUCT_IMAGE_SOURCE_PRODUCT = 'product';

	/** @var string product image source option to use the parent product image in Facebook */
	const PRODUCT_IMAGE_SOURCE_PARENT_PRODUCT = 'parent_product';

	/** @var string product image source option to use the parent product image in Facebook */
	const PRODUCT_IMAGE_SOURCE_CUSTOM = 'custom';

	/** @var string product image source option to use multiple images in Facebook */
	const PRODUCT_IMAGE_SOURCE_MULTIPLE = 'multiple';

	/** @var string the meta key used to store the source of the product video in Facebook */
	const PRODUCT_VIDEO_SOURCE_META_KEY = '_wc_facebook_product_video_source';

	/** @var string product video source option to upload/choose video from media library */
	const PRODUCT_VIDEO_SOURCE_UPLOAD = 'upload';

	/** @var string product video source option to use a custom video URL */
	const PRODUCT_VIDEO_SOURCE_CUSTOM = 'custom';

	/** @var string the meta key used to store the Google product category ID for the product */
	const GOOGLE_PRODUCT_CATEGORY_META_KEY = '_wc_facebook_google_product_category';

	/** @var string the meta key prefix used to store the Enhanced Catalog Attributes for the product */
	const ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX = '_wc_facebook_enhanced_catalog_attributes_';

	/** @var string the meta key used to store the product gender */
	const GENDER_META_KEY = '_wc_facebook_gender';

	/** @var string the meta key used to store the name of the color attribute for a product */
	const COLOR_ATTRIBUTE_META_KEY = '_wc_facebook_color_attribute';

	/** @var string the meta key used to store the name of the size attribute for a product */
	const SIZE_ATTRIBUTE_META_KEY = '_wc_facebook_size_attribute';

	/** @var string the meta key used to store the name of the pattern attribute for a product */
	const PATTERN_ATTRIBUTE_META_KEY = '_wc_facebook_pattern_attribute';

	/** @var array memoized array of visibility status for products */
	private static $products_visibility = array();


	/**
	 * Sets the sync handling for products to enabled or disabled.
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product[] $products array of product objects
	 * @param bool          $enabled whether sync should be enabled for $products
	 */
	private static function set_sync_for_products( array $products, $enabled ) {
		$enabled               = wc_bool_to_string( $enabled );
		$product_sync_meta_key = self::get_product_sync_meta_key();

		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product ) {
				if ( $product->is_type( 'variable' ) ) {
					foreach ( $product->get_children() as $variation ) {
						$product_variation = wc_get_product( $variation );
						if ( $product_variation instanceof \WC_Product ) {
							$product_variation->update_meta_data( $product_sync_meta_key, $enabled );
							$product_variation->save_meta_data();
						}
					}
				}

				// Adding sync mode settings to simple product as well as main product for variants.
				$product->update_meta_data( $product_sync_meta_key, $enabled );
				$product->save_meta_data();

				// Remove excluded product from FB.
				if ( 'no' === $enabled ) {
					facebook_for_woocommerce()->get_integration()->delete_fb_product( $product );
				}
			}//end if
		}//end foreach
	}

	/**
	 * Chooses a particular key for product sync
	 * based on user opt in status for Woo all products
	 *
	 * @since 3.5.1
	 */
	public static function get_product_sync_meta_key() {
		if ( PluginRender::is_master_sync_on() ) {
			return self::OPTEN_IN_SYNC_ENABLED_META_KEY;
		} else {
			return self::SYNC_ENABLED_META_KEY;
		}
	}

	/**
	 * Enables sync for given products.
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product[] $products an array of product objects
	 */
	public static function enable_sync_for_products( array $products ) {
		self::set_sync_for_products( $products, true );
	}


	/**
	 * Disables sync for given products.
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product[] $products an array of product objects
	 */
	public static function disable_sync_for_products( array $products ) {
		self::set_sync_for_products( $products, false );
	}


	/**
	 * Disables sync for products that belong to the given category or tag.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args {
	 *     @type string|array $taxonomy product_cat or product_tag
	 *     @type string|array $include array or comma/space-separated string of term IDs to include
	 * }
	 */
	public static function disable_sync_for_products_with_terms( array $args ) {
		$args     = wp_parse_args(
			$args,
			array(
				'taxonomy' => 'product_cat',
				'include'  => array(),
			)
		);
		$products = array();
		// get all products belonging to the given terms
		if ( is_array( $args['include'] ) && ! empty( $args['include'] ) ) {
			$terms = get_terms(
				array(
					'taxonomy' => $args['taxonomy'],
					'fields'   => 'slugs',
					'include'  => array_map( 'intval', $args['include'] ),
				)
			);
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$taxonomy = 'product_tag' === $args['taxonomy'] ? 'tag' : 'category';
				$products = wc_get_products(
					array(
						$taxonomy => $terms,
						'limit'   => -1,
					)
				);
			}
		}//end if
		if ( ! empty( $products ) ) {
			self::disable_sync_for_products( $products );
		}
	}


	/**
	 * Determines whether the given product should be synced.
	 *
	 * @deprecated use \WooCommerce\Facebook\ProductSync\ProductValidator::validate() instead
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function product_should_be_synced( \WC_Product $product ) {
		try {
			facebook_for_woocommerce()->get_product_sync_validator( $product )->validate();
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Determines whether the given product should be synced assuming the product is published.
	 *
	 * If a product is enabled for sync, but belongs to an excluded term, it will return as excluded from sync:
	 *
	 * @deprecated use \WooCommerce\Facebook\ProductSync\ProductValidator::validate() instead
	 *
	 * @since 2.0.0-dev.1
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function published_product_should_be_synced( \WC_Product $product ) {
		try {
			facebook_for_woocommerce()->get_product_sync_validator( $product )->validate_but_skip_status_check();
			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}


	/**
	 * Determines whether the given product should be removed from the catalog.
	 *
	 * @since 2.0.0
	 * @deprecated 3.7.9 This only reported products in an excluded category or tag, and categories
	 *             and tags can no longer be excluded from sync, so no product is removed for it.
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function product_should_be_deleted( \WC_Product $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature kept for backwards compatibility.
		wc_deprecated_function( __METHOD__, '3.7.9' );

		return false;
	}


	/**
	 * Determines whether a product is enabled to be synced in Facebook.
	 *
	 * If the product is not explicitly set to disable sync, it'll be considered enabled.
	 * This applies to products that may not have the meta value set.
	 *
	 * @deprecated use \WooCommerce\Facebook\ProductSync\ProductValidator::passes_product_sync_field_check() instead
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product $product product object
	 * @return bool
	 */
	public static function is_sync_enabled_for_product( \WC_Product $product ) {
		return facebook_for_woocommerce()->get_product_sync_validator( $product )->passes_product_sync_field_check();
	}


	/**
	 * Determines whether the product's terms would make it excluded to be synced from Facebook.
	 *
	 * @since 1.10.0
	 *
	 * @deprecated 3.7.9 Categories and tags can no longer be excluded from sync, so no product is excluded by its terms.
	 *
	 * @param \WC_Product $product product object
	 * @return bool if true, product should be excluded from sync, if false, product can be included in sync (unless manually excluded by individual product meta)
	 */
	public static function is_sync_excluded_for_product_terms( \WC_Product $product ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature kept for backwards compatibility.
		wc_deprecated_function( __METHOD__, '3.7.9' );

		return false;
	}


	/**
	 * Sets a product's visibility in the Facebook shop.
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product $product product object
	 * @param bool        $visibility true for 'published' or false for 'hidden'
	 * @return bool success
	 */
	public static function set_product_visibility( \WC_Product $product, $visibility ) {
		unset( self::$products_visibility[ $product->get_id() ] );
		if ( ! is_bool( $visibility ) ) {
			return false;
		}

		// Updating visibility for the all variable products
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation ) {
				$product_variation = wc_get_product( $variation );
				if ( $product_variation instanceof \WC_Product ) {
					$product_variation->update_meta_data( self::VISIBILITY_META_KEY, wc_bool_to_string( $visibility ) );
					$product_variation->save_meta_data();
				}
			}
		}

		$product->update_meta_data( self::VISIBILITY_META_KEY, wc_bool_to_string( $visibility ) );
		$product->save_meta_data();
		self::$products_visibility[ $product->get_id() ] = $visibility;
		return true;
	}


	/**
	 * Checks whether a product should be visible on Facebook.
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product $product
	 * @return bool
	 */
	public static function is_product_visible( \WC_Product $product ) {
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $product->is_in_stock() ) {
			self::$products_visibility[ $product->get_id() ] = false;
			return false;
		}
		// accounts for a legacy bool value, current should be (string) 'yes' or (string) 'no'
		if ( ! isset( self::$products_visibility[ $product->get_id() ] ) ) {
			$meta = $product->get_meta( self::VISIBILITY_META_KEY );

			if ( $product->is_type( 'variable' ) ) {
				// assume variable products are not visible until a visible child is found
				$is_visible = false;
				foreach ( $product->get_children() as $child_id ) {
					$child_product = wc_get_product( $child_id );
					if ( $child_product && self::is_product_visible( $child_product ) ) {
						$is_visible = true;
						break;
					}
				}
			} elseif ( $meta ) {
				$is_visible = wc_string_to_bool( $product->get_meta( self::VISIBILITY_META_KEY ) );
			} else {
				$is_visible = true;
			}//end if
			self::$products_visibility[ $product->get_id() ] = $is_visible;
		}//end if
		return self::$products_visibility[ $product->get_id() ];
	}


	/**
	 * Gets the product price used for Facebook sync.
	 *
	 * TODO: Consider adding memoization, but ensure we can protect the implementation against price changes during the same request {WV-2020-08-20}
	 *       See https://github.com/facebookincubator/facebook-for-woocommerce/pull/1468
	 *
	 * @since 2.0.0-dev.1
	 *
	 * @param \WC_Product $product
	 * @return int
	 */
	public static function get_product_price( \WC_Product $product ) {
		$facebook_price = $product->get_meta( WC_Facebook_Product::FB_PRODUCT_PRICE );
		// use the user defined Facebook price if set
		if ( is_numeric( $facebook_price ) ) {
			$price = $facebook_price;
		} elseif ( $product->is_type( 'composite' ) ) {

			$price = $product->get_composite_price( 'min', true );

		} elseif ( $product->is_type( 'bundle' ) ) {

			// If product is a product bundle with individually priced items, we rely on their pricing.
			$price = $product->get_bundle_price( 'min', true );

		} elseif ( $product->is_type( 'mix-and-match' ) && is_callable( array( $product, 'get_container_price' ) ) ) {

			// If product is Mix and Match product with individually priced items, we rely on their pricing, since MNM 2.0.
			$price = $product->get_container_price( 'min', true );

		} else {
			$price = wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		}
		$price = (int) ( $price ? round( $price * 100 ) : 0 );
		/**
		 * Filters the product price used for Facebook sync.
		 *
		 * @since 2.0.0-dev.1
		 *
		 * @param int $price product price in cents
		 * @param float $facebook_price user defined facebook price
		 * @param \WC_Product $product product object
		 */
		return (int) apply_filters( 'wc_facebook_product_price', $price, (float) $facebook_price, $product );
	}


	/**
	 * Determines whether the product meets all of the criteria needed for Commerce.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 */
	public static function is_product_ready_for_commerce( \WC_Product $product ) {
		return $product->managing_stock()
			&& self::get_product_price( $product )
			&& self::is_commerce_enabled_for_product( $product )
			&& self::product_should_be_synced( $product );
	}


	/**
	 * Gets the Google product category ID stored for the product.
	 *
	 * If the product is a variation, it will get this value from its parent.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_google_product_category_id( \WC_Product $product ) {
		// attempt to get from product or parent product metadata
		if ( $product->is_type( 'variation' ) ) {
			$parent_product             = wc_get_product( $product->get_parent_id() );
			$google_product_category_id = $parent_product instanceof \WC_Product ? $parent_product->get_meta( self::GOOGLE_PRODUCT_CATEGORY_META_KEY ) : null;
		} else {
			$google_product_category_id = $product->get_meta( self::GOOGLE_PRODUCT_CATEGORY_META_KEY );
		}
		// fallback to the highest category's Google product category ID
		if ( empty( $google_product_category_id ) ) {
			$google_product_category_id = self::get_google_product_category_id_from_highest_category( $product );
		}
		// fallback to plugin-level default Google product category ID
		if ( empty( $google_product_category_id ) ) {
			$google_product_category_id = facebook_for_woocommerce()->get_commerce_handler()->get_default_google_product_category_id();
		}
		return $google_product_category_id;
	}

	/**
	 * Gets the stored Google product category ID from the highest category.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	private static function get_google_product_category_id_from_highest_category( \WC_Product $product ) {
		$google_product_category_id = '';
		// get all categories for the product
		if ( $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$categories     = $parent_product instanceof \WC_Product ? get_the_terms( $parent_product->get_id(), 'product_cat' ) : array();
		} else {
			$categories = get_the_terms( $product->get_id(), 'product_cat' );
		}
		if ( ! is_array( $categories ) ) {
			return $google_product_category_id;
		}
		$categories_per_level = array();
		if ( empty( $categories ) ) {
			return $categories_per_level;
		}
		// determine the level (depth) of each category
		foreach ( $categories as $category ) {
			$level           = 0;
			$parent_category = $category;
			while ( 0 !== (int) $parent_category->parent ) {
				$parent_category = get_term( $parent_category->parent, 'product_cat' );
				if ( ! $parent_category instanceof \WP_Term ) {
					break;
				}
				++$level;
			}
			if ( empty( $categories_per_level[ $level ] ) ) {
				$categories_per_level[ $level ] = array();
			}
			$categories_per_level[ $level ][] = $category;
		}
		// sort descending by level
		krsort( $categories_per_level );
		// remove categories without a Google product category
		foreach ( $categories_per_level as $level => $categories ) {
			foreach ( $categories as $key => $category ) {
				$google_product_category_id = Product_Categories::get_google_product_category_id( $category->term_id );
				if ( empty( $google_product_category_id ) ) {
					unset( $categories_per_level[ $level ][ $key ] );
				}
			}
			if ( empty( $categories_per_level[ $level ] ) ) {
				unset( $categories_per_level[ $level ] );
			}
		}
		if ( ! empty( $categories_per_level ) ) {
			// get highest level categories
			$categories                 = current( $categories_per_level );
			$google_product_category_id = '';
			foreach ( $categories as $category ) {
				$category_google_product_category_id = Product_Categories::get_google_product_category_id( $category->term_id );
				if ( empty( $google_product_category_id && ! empty( $category_google_product_category_id ) ) ) {
					$google_product_category_id = $category_google_product_category_id;
				} elseif ( $google_product_category_id !== $category_google_product_category_id ) {
					// conflicting Google product category IDs, discard them
					$google_product_category_id = '';
				}
			}
		}//end if
		return $google_product_category_id;
	}

	/**
	 * Gets an ordered list of the categories for the product organised by level.
	 *
	 * @param \WC_Product $product the product object.
	 * @return string
	 */
	private static function get_ordered_categories_for_product( \WC_Product $product ) {
		// get all categories for the product
		if ( $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$categories     = $parent_product instanceof \WC_Product ? get_the_terms( $parent_product->get_id(), 'product_cat' ) : array();
		} else {
			$categories = get_the_terms( $product->get_id(), 'product_cat' );
		}
		if ( empty( $categories ) ) {
			return array();
		}
		$categories_per_level = array();
		// determine the level (depth) of each category
		foreach ( $categories as $category ) {
			$level           = 0;
			$parent_category = $category;
			while ( 0 !== (int) $parent_category->parent ) {
				$parent_category = get_term( $parent_category->parent, 'product_cat' );
				if ( ! $parent_category instanceof \WP_Term ) {
					break;
				}
				++$level;
			}
			if ( empty( $categories_per_level[ $level ] ) ) {
				$categories_per_level[ $level ] = array();
			}
			$categories_per_level[ $level ][] = $category;
		}
		// sort descending by level
		krsort( $categories_per_level );
		return $categories_per_level;
	}

	/**
	 * Gets the first unconflicted value for a meta key from the categories a
	 * product belongs to. This does the same job as the above google category
	 * code but (I think) in a slightly simpler form, not going to change
	 * the google category one just yet until I've got unit tests doing what
	 * I want. TODO: refactor the get_google_product_category_id_from_highest_category
	 * function to use this.
	 *
	 * @param \WC_Product $product the product object.
	 * @param string      $meta_key the meta key we're looking for.
	 * @return string
	 */
	private static function get_meta_value_from_categories_for_product( \WC_Product $product, $meta_key ) {
		$categories_per_level = self::get_ordered_categories_for_product( $product );
		// The plan is to find the first level with a value for the meta key
		// Then we need to check the rest of this level and if there's a conflict
		// continue to the next level up.

		// We're looking fdr the first non-conflicted level basically
		$meta_value = null;
		foreach ( $categories_per_level as $level => $categories ) {
			foreach ( $categories as $category ) {
				$category_meta_value = get_term_meta( $category->term_id, $meta_key, true );
				if ( empty( $category_meta_value ) ) {
					// No value here, move on
					continue;
				}
				if ( empty( $meta_value ) ) {
					// We've found a value for this level and there's no conflict as it's
					// the first one we've found on this level.
					$meta_value = $category_meta_value;
				} elseif ( $meta_value !== $category_meta_value ) {
					// conflict we need to jump out of this loop and go to the next level
					$meta_value = null;
					break;
				}
			}
			if ( ! empty( $meta_value ) ) {
				// We have an unconflicted value, we can use it so break out of the
				// level loop
				break;
			}
		}//end foreach
		return $meta_value;
	}


	/**
	 * Updates the stored Google product category ID for the product.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @param string      $category_id the Google product category ID
	 */
	public static function update_google_product_category_id( \WC_Product $product, $category_id ) {

		$product->update_meta_data( self::GOOGLE_PRODUCT_CATEGORY_META_KEY, $category_id );
		$product->save_meta_data();
	}


	/**
	 * Gets the stored gender for the product (`female`, `male`, or `unisex`).
	 *
	 * Defaults to `unisex` if not otherwise set.
	 *
	 * If the product is a variation, it will get this value from its parent.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_gender( \WC_Product $product ) {

		if ( $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$gender         = $parent_product instanceof \WC_Product ? $parent_product->get_meta( self::GENDER_META_KEY ) : null;
		} else {
			$gender = $product->get_meta( self::GENDER_META_KEY );
		}

		if ( ! in_array( $gender, array( 'female', 'male', 'unisex' ), true ) ) {
			$gender = 'unisex';
		}

		return $gender;
	}


	/**
	 * Updates the stored gender for the product.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @param string      $gender the gender (`female`, `male`, or `unisex`)
	 */
	public static function update_product_gender( \WC_Product $product, $gender ) {

		$product->update_meta_data( self::GENDER_META_KEY, $gender );
		$product->save_meta_data();
	}


	/**
	 * Gets the configured color attribute.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_color_attribute( \WC_Product $product ) {

		if ( $product->is_type( 'variation' ) ) {

			// get the attribute from the parent
			$product = wc_get_product( $product->get_parent_id() );
		}

		$attribute_name = '';

		if ( $product ) {

			$meta_value = $product->get_meta( self::COLOR_ATTRIBUTE_META_KEY );

			// check if an attribute with that name exists
			if ( self::product_has_attribute( $product, $meta_value ) ) {
				$attribute_name = $meta_value;
			}

			if ( empty( $attribute_name ) ) {
				// try to find a matching attribute
				foreach ( self::get_available_product_attributes( $product ) as $slug => $attribute ) {

					if ( stripos( $attribute->get_name(), 'color' ) !== false || stripos( $attribute->get_name(), 'colour' ) !== false ) {
						$attribute_name = $slug;
						break;
					}
				}
			}
		}

		return $attribute_name;
	}

	/**
	 * Updates the configured color attribute.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @param string      $attribute_name the attribute to be used to store the color
	 * @throws PluginException If the provided attribute name does not match any of the available attributes for the product.
	 */
	public static function update_product_color_attribute( \WC_Product $product, $attribute_name ) {

		// check if the name matches an available attribute
		if ( ! empty( $attribute_name ) && ! self::product_has_attribute( $product, $attribute_name ) ) {
			throw new PluginException( esc_html( "The provided attribute name $attribute_name does not match any of the available attributes for the product {$product->get_name()}" ) );
		}

		if ( self::get_product_color_attribute( $product ) !== $attribute_name && in_array( $attribute_name, self::get_distinct_product_attributes( $product ), true ) ) {
			throw new PluginException( esc_html( "The provided attribute $attribute_name is already used for the product {$product->get_name()}" ) );
		}

		$product->update_meta_data( self::COLOR_ATTRIBUTE_META_KEY, $attribute_name );
		$product->save_meta_data();
	}


	/**
	 * Gets the stored color for a product.
	 *
	 * If the product is a variation and it doesn't have the color attribute, falls back to the parent.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_color( \WC_Product $product ) {

		$color_value     = '';
		$color_attribute = self::get_product_color_attribute( $product );

		if ( ! empty( $color_attribute ) ) {
			$color_value = $product->get_attribute( $color_attribute );
		}

		if ( empty( $color_value ) && $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$color_value    = $parent_product instanceof \WC_Product ? self::get_product_color( $parent_product ) : '';
		}

		return $color_value;
	}


	/**
	 * Gets the configured size attribute.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_size_attribute( \WC_Product $product ) {

		if ( $product->is_type( 'variation' ) ) {

			// get the attribute from the parent
			$product = wc_get_product( $product->get_parent_id() );
		}

		$attribute_name = '';

		if ( $product ) {

			$meta_value = $product->get_meta( self::SIZE_ATTRIBUTE_META_KEY );

			// check if an attribute with that name exists
			if ( self::product_has_attribute( $product, $meta_value ) ) {
				$attribute_name = $meta_value;
			}

			if ( empty( $attribute_name ) ) {
				// try to find a matching attribute
				foreach ( self::get_available_product_attributes( $product ) as $slug => $attribute ) {

					if ( stripos( $attribute->get_name(), 'size' ) !== false ) {
						$attribute_name = $slug;
						break;
					}
				}
			}
		}

		return $attribute_name;
	}


	/**
	 * Updates the configured size attribute.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @param string      $attribute_name the attribute to be used to store the size
	 * @throws PluginException If the provided attribute name does not match any of the available attributes for the product.
	 */
	public static function update_product_size_attribute( \WC_Product $product, $attribute_name ) {

		// check if the name matches an available attribute
		if ( ! empty( $attribute_name ) && ! self::product_has_attribute( $product, $attribute_name ) ) {
			throw new PluginException( esc_html( "The provided attribute name $attribute_name does not match any of the available attributes for the product {$product->get_name()}" ) );
		}

		if ( self::get_product_size_attribute( $product ) !== $attribute_name && in_array( $attribute_name, self::get_distinct_product_attributes( $product ), true ) ) {
			throw new PluginException( esc_html( "The provided attribute $attribute_name is already used for the product {$product->get_name()}" ) );
		}

		$product->update_meta_data( self::SIZE_ATTRIBUTE_META_KEY, $attribute_name );
		$product->save_meta_data();
	}


	/**
	 * Gets the stored size for a product.
	 *
	 * If the product is a variation and it doesn't have the size attribute, falls back to the parent.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_size( \WC_Product $product ) {

		$size_value     = '';
		$size_attribute = self::get_product_size_attribute( $product );

		if ( ! empty( $size_attribute ) ) {
			$size_value = $product->get_attribute( $size_attribute );
		}

		if ( empty( $size_value ) && $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$size_value     = $parent_product instanceof \WC_Product ? self::get_product_size( $parent_product ) : '';
		}

		return $size_value;
	}


	/**
	 * Gets the configured pattern attribute.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_pattern_attribute( \WC_Product $product ) {

		if ( $product->is_type( 'variation' ) ) {

			// get the attribute from the parent
			$product = wc_get_product( $product->get_parent_id() );
		}

		$attribute_name = '';

		if ( $product ) {

			$meta_value = $product->get_meta( self::PATTERN_ATTRIBUTE_META_KEY );

			// check if an attribute with that name exists
			if ( self::product_has_attribute( $product, $meta_value ) ) {
				$attribute_name = $meta_value;
			}

			if ( empty( $attribute_name ) ) {
				// try to find a matching attribute
				foreach ( self::get_available_product_attributes( $product ) as $slug => $attribute ) {

					if ( stripos( $attribute->get_name(), 'pattern' ) !== false ) {
						$attribute_name = $slug;
						break;
					}
				}
			}
		}

		return $attribute_name;
	}


	/**
	 * Updates the configured pattern attribute.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @param string      $attribute_name the attribute to be used to store the pattern
	 * @throws PluginException If the provided attribute name does not match any of the available attributes for the product.
	 */
	public static function update_product_pattern_attribute( \WC_Product $product, $attribute_name ) {
		// check if the name matches an available attribute
		if ( ! empty( $attribute_name ) && ! self::product_has_attribute( $product, $attribute_name ) ) {
			throw new PluginException( esc_html( "The provided attribute name $attribute_name does not match any of the available attributes for the product {$product->get_name()}" ) );
		}
		if ( self::get_product_pattern_attribute( $product ) !== $attribute_name && in_array( $attribute_name, self::get_distinct_product_attributes( $product ), true ) ) {
			throw new PluginException( esc_html( "The provided attribute $attribute_name is already used for the product {$product->get_name()}" ) );
		}
		$product->update_meta_data( self::PATTERN_ATTRIBUTE_META_KEY, $attribute_name );
		$product->save_meta_data();
	}


	/**
	 * Gets the stored pattern for a product.
	 *
	 * If the product is a variation and it doesn't have the pattern attribute, falls back to the parent.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string
	 */
	public static function get_product_pattern( \WC_Product $product ) {

		$pattern_value     = '';
		$pattern_attribute = self::get_product_pattern_attribute( $product );

		if ( ! empty( $pattern_attribute ) ) {
			$pattern_value = $product->get_attribute( $pattern_attribute );
		}

		if ( empty( $pattern_value ) && $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$pattern_value  = $parent_product instanceof \WC_Product ? self::get_product_pattern( $parent_product ) : '';
		}

		return $pattern_value;
	}


	/**
	 * Gets all product attributes that are valid for assignment for color, size, or pattern.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return \WC_Product_Attribute[]
	 */
	public static function get_available_product_attributes( \WC_Product $product ) {

		if ( $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			return $parent_product instanceof \WC_Product ? self::get_available_product_attributes( $parent_product ) : array();
		}

		return $product->get_attributes();
	}


	/**
	 * Gets the value for a given enhanced catalog attribute
	 *
	 * @since 2.1.0
	 *
	 * @param string      $key         The attribute key.
	 * @param \WC_Product $product The product object.
	 * @return string
	 */
	public static function get_enhanced_catalog_attribute( $key, \WC_Product $product ) {
		if ( ! $product ) {
			// Break
			return null;
		}

		$value = $product->get_meta( self::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . $key );

		if ( empty( $value ) ) {
			$product_id = $product->get_id();

			// Check normal product attributes
			foreach ( $product->get_attributes() as $slug => $attribute ) {
				if ( $product->is_type( 'variation' ) ) {
					$attr_name = $slug;
					$attr_val  = \WC_Facebookcommerce_Utils::get_variant_option_name( $product_id, 'attribute_' . $slug, $attribute );
				} else {
					$attr_name = $attribute->get_name();
					$attr_val  = $product->get_attribute( $slug );
				}

				$sanitized_attr_name = \WC_Facebookcommerce_Utils::sanitize_attribute_name( \WC_Facebookcommerce_Utils::sanitize_variant_name( $attr_name, false ) );
				if ( $sanitized_attr_name === $key ) {
					$value = $attr_val;
					break;
				}
			}
		}

		// Check parent if we're a variation
		if ( empty( $value ) && $product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $product->get_parent_id() );
			$value          = $parent_product instanceof \WC_Product ? self::get_enhanced_catalog_attribute( $key, $parent_product ) : '';
		}

		// Check categories for default values
		if ( empty( $value ) ) {
			$value = self::get_meta_value_from_categories_for_product( $product, self::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . $key );
		}

		return $value;
	}

	/**
	 * Updates the passed enhanced catalog attribute
	 *
	 * @param \WC_Product $product the product object.
	 * @param string      $attribute_key the attribute key.
	 * @param mixed       $value the attribute value.
	 */
	public static function update_product_enhanced_catalog_attribute( \WC_Product $product, $attribute_key, $value ) {
		// Ensure that we don't override a default with the same value
		// as the default.
		if ( self::get_enhanced_catalog_attribute( $attribute_key, $product ) === $value ) {
			return;
		}
		$product->update_meta_data( self::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . $attribute_key, wp_unslash( $value ) );
		$product->save_meta_data();
	}


	/**
	 * Gets and cleans the submitted values for enhanced catalog attributes from the request.
	 *
	 * Helper function used by both product categories and product pages.
	 *
	 * @since 2.1.0
	 *
	 * @return array associative array
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */
	public static function get_enhanced_catalog_attributes_from_request() {
		$prefix     = Admin\Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX;
		$attributes = array_filter(
			$_POST,
			function ( $key ) use ( $prefix ) {
				return substr( $key, 0, strlen( $prefix ) ) === $prefix;
			},
			ARRAY_FILTER_USE_KEY
		);

		return array_reduce(
			array_keys( $attributes ),
			function ( $attrs, $attr_key ) use ( $prefix ) {
				return array_merge(
					$attrs,
					array(
						str_replace( $prefix, '', $attr_key ) => wc_clean( Helper::get_posted_value( $attr_key ) ),
					)
				);
			},
			array()
		);
	}

	/**
	 * Checks if the product has an attribute with the given name.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @param string      $attribute_name the attribute name
	 * @return bool
	 */
	public static function product_has_attribute( \WC_Product $product, $attribute_name ) {

		$found = false;

		foreach ( self::get_available_product_attributes( $product ) as $slug => $attribute ) {

			// taxonomy attributes have a slugged name, but custom attributes do not so we check the attribute key
			if ( $attribute_name === $slug ) {
				$found = true;
				break;
			}
		}

		return $found;
	}


	/**
	 * Gets the attributes that are set for the product's color, size, and pattern.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product the product object
	 * @return string[]
	 */
	public static function get_distinct_product_attributes( \WC_Product $product ) {

		return array_filter(
			array(
				self::get_product_color_attribute( $product ),
				self::get_product_size_attribute( $product ),
				self::get_product_pattern_attribute( $product ),
			)
		);
	}


	/**
	 * Gets a product by its Facebook product ID, from the `fb_product_item_id` or `fb_product_group_id`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $fb_product_id Facebook product ID
	 * @return \WC_Product|null
	 */
	public static function get_product_by_fb_product_id( $fb_product_id ) {

		$product = null;

		// try to by the `fb_product_item_id` meta
		$products = wc_get_products(
			array(
				'limit'      => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_key'   => \WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $fb_product_id,
			)
		);

		if ( ! empty( $products ) ) {
			$product = current( $products );
		}

		if ( empty( $product ) ) {
			// try to by the `fb_product_group_id` meta
			$products = wc_get_products(
				array(
					'limit'      => 1,
					// phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_key'   => \WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID,
					// phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => $fb_product_id,
				)
			);

			if ( ! empty( $products ) ) {
				$product = current( $products );
			}
		}

		return ! empty( $product ) ? $product : null;
	}


	/**
	 * Gets a product by its Facebook retailer ID.
	 *
	 * @see \WC_Facebookcommerce_Utils::get_fb_retailer_id().
	 *
	 * @since 2.1.0
	 *
	 * @param string $fb_retailer_id Facebook retailer ID
	 * @return \WC_Product|null
	 */
	public static function get_product_by_fb_retailer_id( $fb_retailer_id ) {

		if ( strpos( $fb_retailer_id, \WC_Facebookcommerce_Utils::FB_RETAILER_ID_PREFIX ) !== false ) {
			$product_id = str_replace( \WC_Facebookcommerce_Utils::FB_RETAILER_ID_PREFIX, '', $fb_retailer_id );
		} else {
			$product_id = substr( $fb_retailer_id, strrpos( $fb_retailer_id, '_' ) + 1 );
		}

		$product = wc_get_product( $product_id );

		return ! empty( $product ) ? $product : null;
	}
}
