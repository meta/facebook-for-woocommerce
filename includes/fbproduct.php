<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

require_once __DIR__ . '/fbutils.php';

use WooCommerce\Facebook\CollectionPage;
use WooCommerce\Facebook\Feed\ShippingProfilesFeed;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Handlers\PluginRender;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\ProductAttributeMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Custom FB Product proxy class
 */
class WC_Facebook_Product {


	/**
	 * Product-related constants used for form rendering.
	 * These constants are used in the admin interface for product settings forms.
	 * The actual product data handling uses the same constants defined in WC_Facebook_Product.
	 */

	/** @var string the "new" condition */
	const CONDITION_NEW = 'new';

	/** @var string the "used" condition */
	const CONDITION_USED = 'used';

	/** @var string the "refurbished" condition */
	const CONDITION_REFURBISHED = 'refurbished';

	/** @var string the "adult" age group */
	const AGE_GROUP_ADULT = 'adult';

	/** @var string the "all ages" age group */
	const AGE_GROUP_ALL_AGES = 'all ages';

	/** @var string the "teen" age group */
	const AGE_GROUP_TEEN = 'teen';

	/** @var string the "kids" age group */
	const AGE_GROUP_KIDS = 'kids';

	/** @var string the "toddler" age group */
	const AGE_GROUP_TODDLER = 'toddler';

	/** @var string the "infant" age group */
	const AGE_GROUP_INFANT = 'infant';

	/** @var string the "newborn" age group */
	const AGE_GROUP_NEWBORN = 'newborn';

	/** @var string the "male" gender */
	const GENDER_MALE = 'male';

	/** @var string the "female" gender */
	const GENDER_FEMALE = 'female';

	/** @var string the "unisex" gender */
	const GENDER_UNISEX = 'unisex';

	// Used for the background sync
	const PRODUCT_PREP_TYPE_ITEMS_BATCH = 'items_batch';
	// Used for the background feed upload
	const PRODUCT_PREP_TYPE_FEED = 'feed';
	// Used for direct update and create calls
	const PRODUCT_PREP_TYPE_NORMAL = 'normal';

	// Should match facebook-commerce.php while we migrate that code over
	// to this object.
	const FB_PRODUCT_DESCRIPTION   = 'fb_product_description';
	const FB_SHORT_DESCRIPTION     = 'fb_product_short_description';
	const FB_PRODUCT_PRICE         = 'fb_product_price';
	const FB_SIZE                  = 'fb_size';
	const FB_COLOR                 = 'fb_color';
	const FB_MATERIAL              = 'fb_material';
	const FB_PATTERN               = 'fb_pattern';
	const FB_PRODUCT_IMAGE         = 'fb_product_image';
	const FB_PRODUCT_CONDITION     = 'fb_product_condition';
	const FB_AGE_GROUP             = 'fb_age_group';
	const FB_GENDER                = 'fb_gender';
	const FB_PRODUCT_VIDEO         = 'fb_product_video';
	const FB_PRODUCT_IMAGES        = 'fb_product_images';
	const FB_VARIANT_IMAGE         = 'fb_image';
	const FB_VISIBILITY            = 'fb_visibility';
	const FB_REMOVE_FROM_SYNC      = 'fb_remove_from_sync';
	const FB_RICH_TEXT_DESCRIPTION = 'fb_rich_text_description';
	const FB_BRAND                 = 'fb_brand';
	const FB_VARIABLE_BRAND        = 'fb_variable_brand';
	const FB_MPN                   = 'fb_mpn';

	const MIN_DATE_1 = '1970-01-29';
	const MIN_DATE_2 = '1970-01-30';
	const MAX_DATE   = '2038-01-17';
	const MAX_TIME   = 'T23:59+00:00';
	const MIN_TIME   = 'T00:00+00:00';

	/**
	 * Maximum length of product description.
	 *
	 * @var int
	 */
	public const MAX_DESCRIPTION_LENGTH = 5000;

	/**
	 * Maximum length of product title.
	 *
	 * @var int
	 */
	public const MAX_TITLE_LENGTH = 150;

	/**
	 * @var array Use Checkout URLs.
	 */
	public static $use_checkout_url = array(
		'simple'    => 1,
		'variable'  => 1,
		'variation' => 1,
	);

	/**
	 * @var int WC_Product ID.
	 */
	public $id;

	/**
	 * @var WC_Product
	 */
	public $woo_product;

	/**
	 * @var string Facebook Product Description.
	 */
	private $fb_description;

	/**
	 * @var array Gallery URLs.
	 */
	private $gallery_urls;

	/**
	 * @var bool Use parent image for variable products.
	 */
	private $fb_use_parent_image;

	/**
	 * @var string Product Description.
	 */
	private $main_description;

	/**
	 * @var bool Product visibility on Facebook.
	 */
	public $fb_visibility;

	/**
	 * @var bool Product rich text description.
	 */
	public $rich_text_description;

	/**
	 * @var string Current type of product preparation being performed
	 */
	protected $current_type_to_prepare;

	/** @var array Standard Facebook fields that WooCommerce attributes can map to */
	private static $standard_facebook_fields = array(
		'size'      => array( 'size' ),
		'color'     => array( 'color', 'colour' ),
		'pattern'   => array( 'pattern' ),
		'material'  => array( 'material' ),
		'gender'    => array( 'gender' ),
		'age_group' => array( 'age_group' ),
	);


	/**
	 * Check if a WooCommerce attribute maps to a standard Facebook field
	 *
	 * @param string $attribute_name The WooCommerce attribute name
	 * @return bool|string False if not mapped, or the Facebook field name if mapped
	 */
	public function check_attribute_mapping( $attribute_name ) {
		// Use the new attribute mapper if available
		if ( class_exists( ProductAttributeMapper::class ) ) {
			return ProductAttributeMapper::check_attribute_mapping( $attribute_name );
		}

		// Fallback to the old implementation
		$sanitized_name = \WC_Facebookcommerce_Utils::sanitize_variant_name( $attribute_name, false );

		foreach ( self::$standard_facebook_fields as $fb_field => $possible_matches ) {
			foreach ( $possible_matches as $match ) {
				if ( stripos( $sanitized_name, $match ) !== false ) {
					return $fb_field;
				}
			}
		}

		return false;
	}

	/**
	 * Get all attributes that are not mapped to standard Facebook fields
	 *
	 * @return array Array of unmapped attributes with 'name' and 'value' keys
	 */
	public function get_unmapped_attributes() {
		// Use the new attribute mapper if available
		if ( class_exists( ProductAttributeMapper::class ) ) {
			return ProductAttributeMapper::get_unmapped_attributes( $this->woo_product );
		}

		// Fallback to the old implementation
		$unmapped_attributes = array();
		$attributes          = $this->woo_product->get_attributes();

		foreach ( $attributes as $attribute_name => $_ ) {
			$value = $this->woo_product->get_attribute( $attribute_name );

			if ( ! empty( $value ) ) {
				$mapped_field = $this->check_attribute_mapping( $attribute_name );

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

	public function __construct( $wpid, $parent_product = null ) {

		if ( $wpid instanceof WC_Product ) {
			$this->id          = $wpid->get_id();
			$this->woo_product = $wpid;
		} else {
			$this->id          = $wpid;
			$this->woo_product = wc_get_product( $wpid );
		}

		$this->fb_description        = '';
		$this->gallery_urls          = null;
		$this->fb_use_parent_image   = null;
		$this->main_description      = '';
		$this->rich_text_description = '';

		if ( get_post_meta( $this->id, self::FB_VISIBILITY, true ) ) {
			$meta                = get_post_meta( $this->id, self::FB_VISIBILITY, true );
			$this->fb_visibility = wc_string_to_bool( $meta );
		} else {
			$this->fb_visibility = '';
			// for products that haven't synced yet
		}

		// Variable products should use some data from the parent_product
		// For performance reasons, that data shouldn't be regenerated every time.
		if ( $parent_product ) {
			$this->gallery_urls          = $parent_product->get_gallery_urls();
			$this->fb_use_parent_image   = $parent_product->get_use_parent_image();
			$this->main_description      = $parent_product->get_fb_description();
			$this->rich_text_description = $parent_product->get_rich_text_description();
		}
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
		if ( in_array( $key, array( 'fb_description', 'gallery_urls', 'fb_use_parent_image', 'main_description' ), true ) ) {
			/* translators: %s property name. */
			_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'The %s property is private and should not be accessed outside its class.', 'facebook-for-woocommerce' ), esc_html( $key ) ), '3.0.32' );
			return $this->$key;
		}

		return null;
	}

	public function exists() {
		return ( null !== $this->woo_product && false !== $this->woo_product );
	}

	public function __call( $func, $args ) {
		if ( $this->woo_product ) {
			return call_user_func_array( array( $this->woo_product, $func ), $args );
		} else {
			return null;
		}
	}

	public function get_gallery_urls() {
		if ( null === $this->gallery_urls ) {
			if ( is_callable( array( $this, 'get_gallery_image_ids' ) ) ) {
				$image_ids = $this->get_gallery_image_ids();
			} else {
				$image_ids = $this->get_gallery_attachment_ids();
			}
			$gallery_urls = array();
			foreach ( $image_ids as $image_id ) {
				$image_url = wp_get_attachment_url( $image_id );
				if ( ! empty( $image_url ) ) {
					array_push(
						$gallery_urls,
						WC_Facebookcommerce_Utils::make_url( $image_url )
					);
				}
			}
			$this->gallery_urls = array_filter( $gallery_urls );
		}

		return $this->gallery_urls;
	}

	public function get_post_data() {
		if ( is_callable( 'get_post' ) ) {
			return get_post( $this->id );
		} else {
			return null;
		}
	}

	public function get_fb_price( $for_items_batch = false ) {
		$product_price = Products::get_product_price( $this->woo_product );

		return $for_items_batch ? self::format_price_for_fb_items_batch( $product_price ) : $product_price;
	}

	private static function format_price_for_fb_items_batch( $price ) {
		// items_batch endpoint requires a string and a currency code
		$formatted = ( $price / 100.0 ) . ' ' . get_woocommerce_currency();
		return $formatted;
	}


	/**
	 * Determines whether the current product is a WooCommerce Bookings product.
	 *
	 * TODO: add an integration that filters the Facebook price instead {WV 2020-07-22}
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	private function is_bookable_product() {

		return facebook_for_woocommerce()->is_plugin_active( 'woocommerce-bookings.php' ) && class_exists( 'WC_Product_Booking' ) && is_callable( 'is_wc_booking_product' ) && is_wc_booking_product( $this );
	}

	/**
	 * Gets a list of image URLs to use for this product in Facebook sync.
	 *
	 * @return array
	 */
	public function get_all_image_urls() {

		$image_urls = array();

		/**
		 * Filters the FB product image size.
		 *
		 * @since 3.0.34
		 *
		 * @param string $size The image size. e.g. 'full', 'medium', 'thumbnail'.
		 */
		$image_size               = apply_filters( 'facebook_for_woocommerce_fb_product_image_size', 'full' );
		$product_image_url        = wp_get_attachment_image_url( $this->woo_product->get_image_id(), $image_size );
		$parent_product_image_url = null;
		$custom_image_url         = $this->woo_product->get_meta( self::FB_PRODUCT_IMAGE );

		if ( $this->woo_product->is_type( 'variation' ) ) {

			if ( wc_get_product( $this->woo_product->get_parent_id() ) ) {
				$parent_product           = wc_get_product( $this->woo_product->get_parent_id() );
				$parent_product_image_url = wp_get_attachment_image_url( $parent_product->get_image_id(), $image_size );
			}
		}

		switch ( $this->woo_product->get_meta( Products::PRODUCT_IMAGE_SOURCE_META_KEY ) ) {

			case Products::PRODUCT_IMAGE_SOURCE_CUSTOM:
				$image_urls = array( $custom_image_url, $product_image_url, $parent_product_image_url );
				break;

			case Products::PRODUCT_IMAGE_SOURCE_MULTIPLE:
				// Get multiple images from FB_PRODUCT_IMAGES meta field
				$multiple_image_ids  = $this->woo_product->get_meta( self::FB_PRODUCT_IMAGES );
				$multiple_image_urls = array();

				if ( ! empty( $multiple_image_ids ) ) {
					// Split comma-separated attachment IDs
					$attachment_ids = array_map( 'trim', explode( ',', $multiple_image_ids ) );
					foreach ( $attachment_ids as $attachment_id ) {
						if ( is_numeric( $attachment_id ) && ! empty( $attachment_id ) ) {
							$image_url = wp_get_attachment_image_url( $attachment_id, $image_size );
							if ( $image_url ) {
								$multiple_image_urls[] = $image_url;
							}
						}
					}
				}

				// Use multiple images first, then fallback to variation and parent images
				$image_urls = array_merge( $multiple_image_urls, array( $product_image_url, $parent_product_image_url ) );
				break;

			case Products::PRODUCT_IMAGE_SOURCE_PARENT_PRODUCT:
				$image_urls = array( $parent_product_image_url, $product_image_url );
				break;

			case Products::PRODUCT_IMAGE_SOURCE_PRODUCT:
			default:
				$image_urls = array( $product_image_url, $parent_product_image_url );
				break;
		}

		$image_urls = array_merge( $image_urls, $this->get_gallery_urls() );
		$image_urls = array_filter( array_unique( $image_urls ) );

		// Regenerate $image_url PHP array indexes after filtering.
		// The array_filter does not touches indexes so if something gets removed we may end up with gaps.
		// Later parts of the code expect something to exist under the 0 index.
		$image_urls = array_values( $image_urls );

		if ( empty( $image_urls ) ) {
			$image_urls[] = wc_placeholder_img_src();
		}

		return $image_urls;
	}

	/**
	 * Gets a list of video URLs to use for this product in Facebook sync.
	 *
	 * @param mixed $parent_id
	 * @return array
	 */
	public function get_all_video_urls( $parent_id = null ) {

		$video_urls = array();
		$product    = $this->woo_product;

		if ( null !== $parent_id ) {
			$product = wc_get_product( $parent_id );
		}

		// Check the video source to determine which meta key to use
		$video_source = $product->get_meta( \WooCommerce\Facebook\Products::PRODUCT_VIDEO_SOURCE_META_KEY );

		// If video source is 'custom', get the custom video URL
		if ( \WooCommerce\Facebook\Products::PRODUCT_VIDEO_SOURCE_CUSTOM === $video_source ) {
			$custom_video_url = trim( $product->get_meta( self::FB_PRODUCT_VIDEO . '_custom_url' ) );
			if ( ! empty( $custom_video_url ) && filter_var( $custom_video_url, FILTER_VALIDATE_URL ) ) {
				$video_urls[] = array( 'url' => $custom_video_url );
			}
			return $video_urls;
		}

		// Otherwise, use uploaded videos (default behavior)
		$attached_videos = get_attached_media( 'video', $this->id );

		$uploaded_video_urls = $product->get_meta( self::FB_PRODUCT_VIDEO );

		if ( empty( $attached_videos ) && empty( $uploaded_video_urls ) ) {
			return $video_urls;
		}

		// Add uploaded video URLs to the list
		if ( is_array( $uploaded_video_urls ) ) {
			foreach ( $uploaded_video_urls as $video_url ) {
				$video_url = trim( $video_url );
				if ( ! empty( $video_url ) ) {
					$video_urls[] = array( 'url' => $video_url );
				}
			}
		}

		// Add attached video URLs to the list, excluding duplicates from uploaded video URLs
		if ( ! empty( $attached_videos ) ) {
			$uploaded_video_url_set = array_flip( array_column( $video_urls, 'url' ) );
			foreach ( $attached_videos as $video ) {
				$url = wp_get_attachment_url( $video->ID );
				if ( $url && ! isset( $uploaded_video_url_set[ $url ] ) ) {
					$video_urls[] = array( 'url' => $url );
				}
			}
		}

		return $video_urls;
	}



	/**
	 * Gets the list of additional image URLs for the product from the complete list of image URLs.
	 *
	 * It assumes the first URL will be used as the product image.
	 * It returns 20 or less image URLs because Facebook doesn't allow more items on the additional_image_urls field.
	 *
	 * @since 2.0.2
	 *
	 * @param array $image_urls all image URLs for the product.
	 * @return array
	 */
	private function get_additional_image_urls( $image_urls ) {

		return array_slice( $image_urls, 1, 20 );
	}


	/** Returns the parent image id for variable products only. */
	public function get_parent_image_id() {
		if ( WC_Facebookcommerce_Utils::is_variation_type( $this->woo_product->get_type() ) ) {
			$parent_data = $this->get_parent_data();
			return $parent_data['image_id'];
		}
		return null;
	}

	public function set_description( $description ) {
		$description          = stripslashes( WC_Facebookcommerce_Utils::clean_string( $description ) );
		$this->fb_description = $description;
		update_post_meta(
			$this->id,
			self::FB_PRODUCT_DESCRIPTION,
			$description
		);
	}

	public function set_product_image( $image ) {
		if ( null !== $image && 0 !== strlen( $image ) ) {
			$image = WC_Facebookcommerce_Utils::clean_string( $image );
			$image = WC_Facebookcommerce_Utils::make_url( $image );
			update_post_meta(
				$this->id,
				self::FB_PRODUCT_IMAGE,
				$image
			);
		}
	}

	public function set_product_video_urls( $attachment_ids ) {
		$video_urls = array_filter(
			array_map(
				function ( $id ) {
					return trim( wp_get_attachment_url( $id ) );
				},
				explode( ',', $attachment_ids )
			)
		);
		update_post_meta(
			$this->id,
			self::FB_PRODUCT_VIDEO,
			$video_urls
		);
	}

	public function set_rich_text_description( $rich_text_description ) {
		$rich_text_description       = stripslashes( $rich_text_description );
		$this->rich_text_description = $rich_text_description;
		update_post_meta(
			$this->id,
			self::FB_RICH_TEXT_DESCRIPTION,
			$rich_text_description
		);
	}

	public function set_fb_brand( $fb_brand ) {
		$fb_brand = stripslashes(
			WC_Facebookcommerce_Utils::clean_string( $fb_brand )
		);
		update_post_meta(
			$this->id,
			self::FB_BRAND,
			$fb_brand
		);
	}

	/**
	 * Utility method to set basic Facebook product attributes
	 *
	 * @param string $key The meta key to store the value under
	 * @param string $value The value to store
	 * @return void
	 */
	private function set_fb_attribute( $key, $value ) {
		$value = stripslashes(
			WC_Facebookcommerce_Utils::clean_string( $value )
		);
		update_post_meta(
			$this->id,
			$key,
			$value
		);
	}

	public function set_fb_material( $fb_material ) {
		$this->set_fb_attribute( self::FB_MATERIAL, $fb_material );
	}

	public function set_fb_pattern( $fb_pattern ) {
		$this->set_fb_attribute( self::FB_PATTERN, $fb_pattern );
	}

	public function set_fb_mpn( $fb_mpn ) {
		$this->set_fb_attribute( self::FB_MPN, $fb_mpn );
	}

	public function set_fb_condition( $fb_condition ) {
		$this->set_fb_attribute( self::FB_PRODUCT_CONDITION, $fb_condition );
	}

	public function set_fb_age_group( $fb_age_group ) {
		$this->set_fb_attribute( self::FB_AGE_GROUP, $fb_age_group );
	}

	public function set_fb_gender( $fb_gender ) {
		$this->set_fb_attribute( self::FB_GENDER, $fb_gender );
	}

	public function set_fb_color( $fb_color ) {
		$this->set_fb_attribute( self::FB_COLOR, $fb_color );
	}

	public function set_fb_size( $fb_size ) {
		$this->set_fb_attribute( self::FB_SIZE, $fb_size );
	}

	public function set_price( $price ) {
		if ( is_numeric( $price ) ) {
			update_post_meta(
				$this->id,
				self::FB_PRODUCT_PRICE,
				$price
			);
		} else {
			delete_post_meta(
				$this->id,
				self::FB_PRODUCT_PRICE
			);
		}
	}

	public function get_use_parent_image() {
		if ( null === $this->fb_use_parent_image ) {
			$variant_image_setting     =
			get_post_meta( $this->id, self::FB_VARIANT_IMAGE, true );
			$this->fb_use_parent_image = ( $variant_image_setting ) ? true : false;
		}
		return $this->fb_use_parent_image;
	}

	public function set_use_parent_image( $setting ) {
		$this->fb_use_parent_image = ( 'yes' === $setting );
		update_post_meta(
			$this->id,
			self::FB_VARIANT_IMAGE,
			$this->fb_use_parent_image
		);
	}

	/**
	 * Gets the FB brand value for the product.
	 *
	 * @param bool $is_api_call Whether this is for API submission
	 * @return string|array String for UI display, array for API if pipe-separated
	 */
	public function get_fb_brand( $is_api_call = false ) {
		// Check if we have a taxonomy attribute for brand
		$brand_values = $this->get_taxonomy_attribute_values( 'pa_brand' );

		if ( $brand_values ) {
			return $this->process_attribute_values( $brand_values, $is_api_call );
		}

		// If this is a variation, first check for variation-specific brand
		if ( $this->is_type( 'variation' ) ) {
			// Get brand directly from variation's post meta
			$fb_brand = get_post_meta(
				$this->id,
				self::FB_BRAND,
				true
			);

			// If variation has no brand set, get from parent
			if ( empty( $fb_brand ) ) {
				$parent_id = $this->get_parent_id();
				if ( $parent_id ) {
					$fb_brand = get_post_meta( $parent_id, self::FB_BRAND, true );
				}
			}
		} else {
			// Get brand directly from post meta for non-variation products
			$fb_brand = get_post_meta(
				$this->id,
				self::FB_BRAND,
				true
			);
		}

		// Only fallback to store name if no brand is found on product or parent
		if ( empty( $fb_brand ) ) {
			$brand          = get_post_meta( $this->id, Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . 'brand', true );
			$brand_taxonomy = get_the_term_list( $this->id, 'product_brand', '', ', ' );

			if ( $brand ) {
				$fb_brand = $brand;
			} elseif ( ! is_wp_error( $brand_taxonomy ) && $brand_taxonomy ) {
				$fb_brand = $brand_taxonomy;
			} else {
				$fb_brand = WC_Facebookcommerce_Utils::get_default_fb_brand();
			}
		}

		$clean_value = WC_Facebookcommerce_Utils::clean_string( $fb_brand );
		return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
	}

	public function get_fb_description() {
		$description = '';

		if ( $this->fb_description ) {
			$description = $this->fb_description;
		}

		if ( empty( $description ) ) {
			// Try to get description from post meta
			$description = get_post_meta(
				$this->id,
				self::FB_PRODUCT_DESCRIPTION,
				true
			);
		}

		// Check if the product type is a variation and no description is found yet
		if ( empty( $description ) && WC_Facebookcommerce_Utils::is_variation_type( $this->woo_product->get_type() ) ) {
			$description = WC_Facebookcommerce_Utils::clean_string( $this->woo_product->get_description() );

			// Fallback to main description
			if ( empty( $description ) && $this->main_description ) {
				$description = $this->main_description;
			}
		}

		// If no description is found from meta or variation, get from post
		if ( empty( $description ) ) {
			$post = $this->get_post_data();
			if ( ! $post ) {
				return apply_filters( 'facebook_for_woocommerce_fb_product_description', '', $this->id );
			}
			$post_content = WC_Facebookcommerce_Utils::clean_string( $post->post_content );
			$post_excerpt = WC_Facebookcommerce_Utils::clean_string( $post->post_excerpt );
			$post_title   = WC_Facebookcommerce_Utils::clean_string( $post->post_title );

			// Prioritize content, then excerpt, then title
			if ( ! empty( $post_content ) ) {
				$description = $post_content;
			}

			if ( empty( $description ) && ! empty( $post_excerpt ) ) {
				$description = $post_excerpt;
			}

			if ( empty( $description ) ) {
				$description = $post_title;
			}
		}
		/**
		 * Filters the FB product description.
		 *
		 * @since 3.2.6
		 *
		 * @param string  $description Facebook product description.
		 * @param int     $id          WooCommerce Product ID.
		 */
		return apply_filters( 'facebook_for_woocommerce_fb_product_description', $description, $this->id );
	}

	/**
	 * Get the short description for a product.
	 *
	 * This function retrieves the short product description, but unlike the main description
	 * it should only use values specifically set for short description.
	 *
	 * @return string The short description for the product.
	 */
	public function get_fb_short_description() {
		$short_description = '';

		// For variations, first try to get the short description from the parent product
		if ( WC_Facebookcommerce_Utils::is_variation_type( $this->woo_product->get_type() ) ) {
			// Get the parent product
			$parent_id = $this->woo_product->get_parent_id();
			if ( $parent_id ) {
				$parent_post = get_post( $parent_id );
				if ( $parent_post && ! empty( $parent_post->post_excerpt ) ) {
					$short_description = WC_Facebookcommerce_Utils::clean_string( $parent_post->post_excerpt );
				}
			}

			// If no parent description found, try getting the variation's own excerpt
			if ( empty( $short_description ) ) {
				$post = $this->get_post_data();
				if ( $post && ! empty( $post->post_excerpt ) ) {
					$cleaned_excerpt = WC_Facebookcommerce_Utils::clean_string( $post->post_excerpt );

					// Check if this is a WooCommerce-generated attribute summary
					if ( ! WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary( $cleaned_excerpt ) ) {
						$short_description = $cleaned_excerpt;
					}
				}
			}

			// If still no short description, check if main description is short enough
			if ( empty( $short_description ) ) {
				$main_description = WC_Facebookcommerce_Utils::clean_string( $this->woo_product->get_description() );
				if ( ! empty( $main_description ) && strlen( $main_description ) <= 1000 ) {
					$short_description = $main_description;
				}
			}

			return apply_filters( 'facebook_for_woocommerce_fb_product_short_description', $short_description, $this->id );
		}

		// Use the product's short description (excerpt) from WooCommerce
		$post = $this->get_post_data();
		if ( ! $post ) {
			return apply_filters( 'facebook_for_woocommerce_fb_product_short_description', '', $this->id );
		}
		$post_excerpt = WC_Facebookcommerce_Utils::clean_string( $post->post_excerpt );

		if ( ! empty( $post_excerpt ) ) {
			// Check if this is a WooCommerce-generated attribute summary
			if ( ! WC_Facebookcommerce_Utils::is_woocommerce_attribute_summary( $post_excerpt ) ) {
				$short_description = $post_excerpt;
			}
		}

		// If no short description (excerpt) found, check if main description is short enough
		if ( empty( $short_description ) ) {
			$post_content = WC_Facebookcommerce_Utils::clean_string( $post->post_content );
			if ( ! empty( $post_content ) && strlen( $post_content ) <= 1000 ) {
				$short_description = $post_content;
			}
		}

		/**
		 * Filters the FB product short description.
		 *
		 * @param string  $short_description Facebook product short description.
		 * @param int     $id                WooCommerce Product ID.
		 */
		return apply_filters( 'facebook_for_woocommerce_fb_product_short_description', $short_description, $this->id );
	}

	/**
	 * Get internal label (tags) to be set on the product.
	 * https://www.facebook.com/business/help/120325381656392?id=725943027795860
	 * When creating a filter rule, the field that should be referenced is 'tags'
	 * An internal-label of 'shipping_class_1' would be queried by a filter of '{"tags":{"eq":"shipping_class_1"}}'
	 *
	 * @return array The labels/tags for the product
	 */
	public function get_internal_labels(): array {
		$labels   = [];
		$labels[] = $this->get_shipping_class_label();

		// Wrap labels in single quotes
		return array_map(
			function ( string $label ) {
				return sprintf( '%s', $label );
			},
			$labels
		);
	}

	private function get_shipping_class_label(): string {
		$shipping_class_id = (string) $this->woo_product->get_shipping_class_id();
		return ShippingProfilesFeed::get_shipping_class_tag_for_class( $shipping_class_id );
	}

	/**
	 * Get the rich text description for a product.
	 *
	 * This function retrieves the rich text product description, prioritizing Facebook
	 * rich text descriptions over WooCommerce product descriptions.
	 * 1. Check if the Facebook rich text description is set and not empty.
	 * 2. If the rich text description is not set or empty, use the WooCommerce RTD if available.
	 *
	 * @return string The rich text description for the product.
	 */
	public function get_rich_text_description() {
		$rich_text_description = '';

		// For variations, first check if there's a Facebook description set specifically for that variation
		if ( $this->woo_product->is_type( 'variation' ) ) {
			$rich_text_description = get_post_meta(
				$this->id,
				self::FB_RICH_TEXT_DESCRIPTION,
				true
			);
			if ( $rich_text_description ) {
				return $rich_text_description;
			}
		}

		// Check if the fb description is set as that takes preference
		if ( $this->rich_text_description ) {
			$rich_text_description = $this->rich_text_description;
		} elseif ( $this->fb_description ) {
			$rich_text_description = $this->fb_description;
		}

		// Try to get rich text description from post meta if description has been set
		if ( empty( $rich_text_description ) ) {
			$rich_text_description = get_post_meta(
				$this->id,
				self::FB_RICH_TEXT_DESCRIPTION,
				true
			);
		}

		// If still empty and this is a variation, inherit from parent
		if ( empty( $rich_text_description ) && $this->woo_product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $this->woo_product->get_parent_id() );
			if ( $parent_product ) {
				$rich_text_description = get_post_meta(
					$parent_product->get_id(),
					self::FB_RICH_TEXT_DESCRIPTION,
					true
				);
			}
		}

		// If still empty, use the post content
		if ( empty( $rich_text_description ) ) {
			$post = get_post( $this->id );
			if ( $post ) {
				$rich_text_description = $post->post_content;

				// If post content is empty, fall back to short description (post_excerpt)
				if ( empty( $rich_text_description ) ) {
					$rich_text_description = $post->post_excerpt;
				}
			}
		}

		return apply_filters( 'facebook_for_woocommerce_fb_rich_text_description', $rich_text_description, $this->id );
	}

	/**
	 * @param array $product_data
	 * @param bool  $for_items_batch
	 *
	 * @return array
	 */
	public function add_sale_price( $product_data, $for_items_batch = false ) {

		$sale_price                = $this->woo_product->get_sale_price();
		$sale_price_effective_date = '';
		$sale_start                = '';
		$sale_end                  = '';

		// discard sale price if it's not lower than product price
		$product_price = $this->get_fb_price();
		if ( ! ( is_numeric( $sale_price ) && (int) round( (float) $sale_price * 100 ) < (int) $product_price ) ) {
			$sale_price = '';
		}

		// check if sale exist
		if ( is_numeric( $sale_price ) && $sale_price > 0 ) {
			$sale_start                = $this->woo_product->get_date_on_sale_from();
			$sale_start                =
				$sale_start
					? date_i18n( WC_DateTime::ATOM, $sale_start->getOffsetTimestamp() )
					: self::MIN_DATE_1 . self::MIN_TIME;
			$sale_end                  = $this->woo_product->get_date_on_sale_to();
			$sale_end                  =
				$sale_end
					? date_i18n( WC_DateTime::ATOM, $sale_end->getOffsetTimestamp() )
					: self::MAX_DATE . self::MAX_TIME;
			$sale_price_effective_date =
				( self::MIN_DATE_1 . self::MIN_TIME === $sale_start && self::MAX_DATE . self::MAX_TIME === $sale_end )
				? ''
				: $sale_start . '/' . $sale_end;
				$sale_price            =
				intval( round( $this->get_price_plus_tax( $sale_price ) * 100 ) );

			// Set Sale start and end as empty if set to default values
			if ( self::MIN_DATE_1 . self::MIN_TIME === $sale_start && self::MAX_DATE . self::MAX_TIME === $sale_end ) {
				$sale_start = '';
				$sale_end   = '';
			}
		}

		// check if sale is expired and sale time range is valid
		if ( $for_items_batch ) {
			$product_data['sale_price_effective_date'] = $sale_price_effective_date;
			$product_data['sale_price']                = is_numeric( $sale_price ) ? self::format_price_for_fb_items_batch( $sale_price ) : '';
		} else {
			$product_data['sale_price_start_date'] = $sale_start;
			$product_data['sale_price_end_date']   = $sale_end;
			$product_data['sale_price']            = is_numeric( $sale_price ) ? $sale_price : 0;
		}

		return $product_data;
	}

	public function get_price_plus_tax( $price ) {
		$woo_product = $this->woo_product;
		// // wc_get_price_including_tax exist for Woo > 2.7
		if ( function_exists( 'wc_get_price_including_tax' ) ) {
			$args = array(
				'qty'   => 1,
				'price' => $price,
			);
			return get_option( 'woocommerce_tax_display_shop' ) === 'incl'
					? wc_get_price_including_tax( $woo_product, $args )
					: wc_get_price_excluding_tax( $woo_product, $args );
		} else {
			return get_option( 'woocommerce_tax_display_shop' ) === 'incl'
					? $woo_product->get_price_including_tax( 1, $price )
					: $woo_product->get_price_excluding_tax( 1, $price );
		}
	}

	public function get_grouped_product_option_names( $key, $option_values ) {
		// Convert all slug_names in $option_values into the visible names that
		// advertisers have set to be the display names for a given attribute value
		$terms = get_the_terms( $this->id, $key );
		return ! is_array( $terms ) ? array() : array_map(
			function ( $slug_name ) use ( $terms ) {
				foreach ( $terms as $term ) {
					if ( $term->slug === $slug_name ) {
						return $term->name;
					}
				}
				return $slug_name;
			},
			$option_values
		);
	}

	public function get_fb_condition() {
		// Check for taxonomy attributes for condition
		$condition_values = $this->get_attribute_by_type( 'condition' );
		if ( $condition_values ) {
			$condition = $this->process_attribute_values( $condition_values );
			return ! empty( $condition ) ? $condition : self::CONDITION_NEW;
		}

		// Get condition directly from post meta
		$fb_condition = get_post_meta(
			$this->id,
			self::FB_PRODUCT_CONDITION,
			true
		);

		// If empty and this is a variation, get the parent condition
		if ( empty( $fb_condition ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_condition = get_post_meta( $parent_id, self::FB_PRODUCT_CONDITION, true );
			}
		}

		// Extract first value from array or object
		$fb_condition = $this->get_first_value_from_complex_type( $fb_condition );

		return WC_Facebookcommerce_Utils::clean_string( $fb_condition ) ? WC_Facebookcommerce_Utils::clean_string( $fb_condition ) : self::CONDITION_NEW;
	}


	public function get_fb_age_group() {
		// If this is a variation, get its specific age group value
		if ( $this->is_type( 'variation' ) ) {
			$attributes = $this->woo_product->get_attributes();

			foreach ( $attributes as $key => $value ) {
				$attr_key = strtolower( $key );
				if ( 'age_group' === $attr_key ) {
					// Extract first value from array or object for attribute
					$value = $this->get_first_value_from_complex_type( $value );
					return WC_Facebookcommerce_Utils::clean_string( $value );
				}
			}
		}

		// Check for taxonomy attributes
		$age_group_values = $this->get_attribute_by_type( 'age_group' );
		if ( $age_group_values ) {
			return $this->process_attribute_values( $age_group_values );
		}

		// Get age group directly from post meta
		$fb_age_group = get_post_meta(
			$this->id,
			self::FB_AGE_GROUP,
			true
		);

		// If empty and this is a variation, get the parent age group
		if ( empty( $fb_age_group ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_age_group = get_post_meta( $parent_id, self::FB_AGE_GROUP, true );
			}
		}

		// Extract first value from array or object
		$fb_age_group = $this->get_first_value_from_complex_type( $fb_age_group );

		return WC_Facebookcommerce_Utils::clean_string( $fb_age_group );
	}

	public function get_fb_gender() {
		// If this is a variation, get its specific gender value
		if ( $this->is_type( 'variation' ) ) {
			$attributes = $this->woo_product->get_attributes();

			foreach ( $attributes as $key => $value ) {
				$attr_key = strtolower( $key );
				if ( 'gender' === $attr_key ) {
					// Extract first value from array or object for attribute
					$value = $this->get_first_value_from_complex_type( $value );
					return WC_Facebookcommerce_Utils::clean_string( $value );
				}
			}
		}

		// Check for taxonomy attributes
		$gender_values = $this->get_attribute_by_type( 'gender' );
		if ( $gender_values ) {
			return $this->process_attribute_values( $gender_values );
		}

		// Get gender directly from post meta
		$fb_gender = get_post_meta(
			$this->id,
			self::FB_GENDER,
			true
		);

		// If empty and this is a variation, get the parent condition
		if ( empty( $fb_gender ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_gender = get_post_meta( $parent_id, self::FB_GENDER, true );
			}
		}

		// Extract first value from array or object
		$fb_gender = $this->get_first_value_from_complex_type( $fb_gender );

		return WC_Facebookcommerce_Utils::clean_string( $fb_gender );
	}

	private function convert_pipe_separated_values( $value, $is_api_call = false ) {
		if ( ! $is_api_call ) {
			// Return as is for UI display
			return $value;
		}

		// Convert pipe-separated string to array for API
		if ( is_string( $value ) && strpos( $value, ' | ' ) !== false ) {
			return array_map( 'trim', explode( ' | ', $value ) );
		}

		return $value;
	}

	/**
	 * Gets taxonomy attribute values for a product
	 *
	 * @param string $attribute_name The attribute name to check (like 'pa_material')
	 * @return array|null Array of term names if found, null if not
	 */
	private function get_taxonomy_attribute_values( $attribute_name ) {
		if ( ! $this->woo_product ) {
			return null;
		}

		$attributes      = $this->woo_product->get_attributes();
		$attribute_found = false;
		$attribute_obj   = null;
		$requested_type  = '';

		// Determine which type of attribute we're looking for based on the input name
		if ( strpos( $attribute_name, 'material' ) !== false ) {
			$requested_type = 'material';
		} elseif ( strpos( $attribute_name, 'color' ) !== false || strpos( $attribute_name, 'colour' ) !== false ) {
			$requested_type = 'color';
		} elseif ( strpos( $attribute_name, 'size' ) !== false ) {
			$requested_type = 'size';
		} elseif ( strpos( $attribute_name, 'pattern' ) !== false ) {
			$requested_type = 'pattern';
		}

		// First try to get by exact slug
		if ( isset( $attributes[ $attribute_name ] ) ) {
			$attribute_found = true;
			$attribute_obj   = $attributes[ $attribute_name ];
		} else {
			// For numeric/non-descriptive slugs, we need to try to match by label
			$requested_attr_name = str_replace( 'pa_', '', $attribute_name );

			// Try to match attributes by attribute label and requested type
			foreach ( $attributes as $attr_key => $attr_obj ) {
				$attr_label       = wc_attribute_label( $attr_key );
				$normalized_label = strtolower( $attr_label );

				// If we determined what type we're looking for, check if the label contains that type
				if ( ! empty( $requested_type ) && strpos( $normalized_label, $requested_type ) !== false ) {
					$attribute_found = true;
					$attribute_obj   = $attr_obj;
					break;
				}

				// As a fallback, check if label contains the requested attribute name
				$normalized_requested = strtolower( $requested_attr_name );
				if ( strpos( $normalized_label, $normalized_requested ) !== false ) {
					$attribute_found = true;
					$attribute_obj   = $attr_obj;
					break;
				}
			}
		}

		// Handle variation products specially to get only their specific term
		if ( $this->is_type( 'variation' ) ) {
			// For variations, get the attribute value directly from the variation
			$parent_product = wc_get_product( $this->get_parent_id() );
			if ( ! $parent_product ) {
				return null;
			}

			// Try all possible attribute keys if we're looking for a specific type
			if ( ! empty( $requested_type ) ) {
				foreach ( $attributes as $attr_key => $value ) {
					$attr_label       = wc_attribute_label( $attr_key );
					$normalized_label = strtolower( $attr_label );

					if ( strpos( $normalized_label, $requested_type ) !== false ) {
						$attribute_value = $this->woo_product->get_attribute( $attr_key );
						if ( ! empty( $attribute_value ) ) {
							return array( $attribute_value );
						}
					}
				}
			}

			// Try with the original attribute name
			$attribute_value = $this->woo_product->get_attribute( $attribute_name );

			// If attribute value exists, return it
			if ( ! empty( $attribute_value ) ) {
				return array( $attribute_value );
			}
			// If no specific value, try parent product
			return $this->get_parent_taxonomy_attribute_values( $attribute_name );
		} elseif ( $attribute_found && $attribute_obj ) { // For regular products
			if ( is_object( $attribute_obj ) && method_exists( $attribute_obj, 'is_taxonomy' ) && $attribute_obj->is_taxonomy() ) {
				$terms = $attribute_obj->get_terms();
				if ( $terms && ! is_wp_error( $terms ) ) {
					return wp_list_pluck( $terms, 'name' );
				}
			}
		}

		return null;
	}

	/**
	 * Gets parent product taxonomy attribute values
	 *
	 * @param string $attribute_name The attribute name to check
	 * @return array|null Array of term names if found, null if not
	 */
	private function get_parent_taxonomy_attribute_values( $attribute_name ) {
		if ( ! $this->is_type( 'variation' ) ) {
			return null;
		}

		$parent_id = $this->get_parent_id();
		if ( ! $parent_id ) {
			return null;
		}

		$parent_product = wc_get_product( $parent_id );
		if ( ! $parent_product ) {
			return null;
		}

		$parent_attributes = $parent_product->get_attributes();
		$attribute_found   = false;
		$attribute_obj     = null;
		$requested_type    = '';

		// Determine which type of attribute we're looking for based on the input name
		if ( strpos( $attribute_name, 'material' ) !== false ) {
			$requested_type = 'material';
		} elseif ( strpos( $attribute_name, 'color' ) !== false || strpos( $attribute_name, 'colour' ) !== false ) {
			$requested_type = 'color';
		} elseif ( strpos( $attribute_name, 'size' ) !== false ) {
			$requested_type = 'size';
		} elseif ( strpos( $attribute_name, 'pattern' ) !== false ) {
			$requested_type = 'pattern';
		}

		// First try to get by exact slug
		if ( isset( $parent_attributes[ $attribute_name ] ) ) {
			$attribute_found = true;
			$attribute_obj   = $parent_attributes[ $attribute_name ];
		} else {
			// For numeric/non-descriptive slugs, we need to try to match by label
			$requested_attr_name = str_replace( 'pa_', '', $attribute_name );

			// Try to match attributes by attribute label and requested type
			foreach ( $parent_attributes as $attr_key => $attr_obj ) {
				$attr_label       = wc_attribute_label( $attr_key );
				$normalized_label = strtolower( $attr_label );

				// If we determined what type we're looking for, check if the label contains that type
				if ( ! empty( $requested_type ) && strpos( $normalized_label, $requested_type ) !== false ) {
					$attribute_found = true;
					$attribute_obj   = $attr_obj;
					break;
				}

				// As a fallback, check if label contains the requested attribute name
				$normalized_requested = strtolower( $requested_attr_name );
				if ( strpos( $normalized_label, $normalized_requested ) !== false ) {
					$attribute_found = true;
					$attribute_obj   = $attr_obj;
					break;
				}
			}
		}

		if ( $attribute_found && $attribute_obj && is_object( $attribute_obj ) && method_exists( $attribute_obj, 'is_taxonomy' ) && $attribute_obj->is_taxonomy() ) {
			$terms = $attribute_obj->get_terms();
			if ( $terms && ! is_wp_error( $terms ) ) {
				return wp_list_pluck( $terms, 'name' );
			}
		}

		return null;
	}

	/**
	 * Gets a WooCommerce attribute by type, supporting both standard and numeric/custom slugs.
	 *
	 * @param string $attribute_type The attribute type to search for (material, color, size, etc.)
	 * @return array|null Array of attribute values if found, null if not
	 */
	private function get_attribute_by_type( $attribute_type ) {
		if ( ! $this->woo_product ) {
			return null;
		}

		// First try the standard taxonomy name
		$standard_taxonomy = 'pa_' . $attribute_type;
		$attribute_values  = $this->get_taxonomy_attribute_values( $standard_taxonomy );
		if ( $attribute_values ) {
			return $attribute_values;
		}

		// If not found, try to find by matching the attribute label
		$attributes = $this->woo_product->get_attributes();

		// Loop through all attributes to find one that matches our type
		foreach ( $attributes as $attr_key => $attr_obj ) {
			$attr_label       = wc_attribute_label( $attr_key );
			$normalized_label = strtolower( $attr_label );

			// Check if the attribute label contains our target type
			if ( stripos( $normalized_label, $attribute_type ) !== false ) {
				// Found an attribute with the requested type in the label
				if ( is_object( $attr_obj ) && method_exists( $attr_obj, 'is_taxonomy' ) && $attr_obj->is_taxonomy() ) {
					$terms = $attr_obj->get_terms();
					if ( $terms && ! is_wp_error( $terms ) ) {
						return wp_list_pluck( $terms, 'name' );
					}
				} elseif ( is_object( $attr_obj ) && method_exists( $attr_obj, 'get_options' ) ) {
					return $attr_obj->get_options();
				}
			}
		}

		// For variations, also check direct attribute values
		if ( $this->is_type( 'variation' ) ) {
			foreach ( $attributes as $key => $value ) {
				$attr_label       = wc_attribute_label( $key );
				$normalized_label = strtolower( $attr_label );

				if ( stripos( $normalized_label, $attribute_type ) !== false ) {
					$attr_value = $this->woo_product->get_attribute( $key );
					if ( ! empty( $attr_value ) ) {
						return array( $attr_value );
					}
				}
			}

			// If still not found, check parent product
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$parent_product = wc_get_product( $parent_id );
				if ( $parent_product ) {
					$parent_attributes = $parent_product->get_attributes();

					foreach ( $parent_attributes as $attr_key => $attr_obj ) {
						$attr_label       = wc_attribute_label( $attr_key );
						$normalized_label = strtolower( $attr_label );

						if ( stripos( $normalized_label, $attribute_type ) !== false ) {
							if ( is_object( $attr_obj ) && method_exists( $attr_obj, 'is_taxonomy' ) && $attr_obj->is_taxonomy() ) {
								$terms = $attr_obj->get_terms();
								if ( $terms && ! is_wp_error( $terms ) ) {
									return wp_list_pluck( $terms, 'name' );
								}
							} elseif ( is_object( $attr_obj ) && method_exists( $attr_obj, 'get_options' ) ) {
								return $attr_obj->get_options();
							}
						}
					}
				}
			}
		}

		return null;
	}

	/**
	 * Utility method to get first value from a potential array or object
	 * For simple products, always returns just the first value of arrays
	 * For variable/variation products, preserves the full array
	 *
	 * @param mixed $value The value to process
	 * @return mixed The first value for simple products, original array for variations
	 */
	private function get_first_value_from_complex_type( $value ) {
		// Only extract first value for simple products (not variations/variable)
		if ( $this->is_type( 'simple' ) ) {
			if ( is_array( $value ) ) {
				return ! empty( $value ) ? $value[0] : '';
			} elseif ( is_object( $value ) ) {
				$vars = get_object_vars( $value );
				return ! empty( $vars ) ? array_values( $vars )[0] : '';
			}
		}

		// For variations or non-array/object values, just return as is
		return $value;
	}

	/**
	 * Helper method to process attribute values consistently across different attribute types
	 * Handles the logic of returning a single value for simple products and multiple values for variable products
	 *
	 * @param array $attribute_values Array of attribute values
	 * @param bool  $is_api_call Whether this is for API submission
	 * @return string|array Processed attribute value(s)
	 */
	private function process_attribute_values( $attribute_values, $is_api_call = false ) {
		if ( ! $attribute_values ) {
			return '';
		}

		// For simple products, just take the first element
		if ( $this->is_type( 'simple' ) ) {
			if ( is_array( $attribute_values ) && ! empty( $attribute_values ) ) {
				$value = $attribute_values[0];
				// Clean and truncate the value directly for simple products
				return mb_substr( WC_Facebookcommerce_Utils::clean_string( $value ), 0, 200 );
			}
		} else {
			// For variable/variation products, keep all values
			$joined_values = implode( ' | ', $attribute_values );
			return $this->convert_pipe_separated_values( $joined_values, $is_api_call );
		}

		return '';
	}

	/**
	 * Gets the FB material value for the product.
	 *
	 * @param bool $is_api_call Whether this is for API submission
	 * @return string|array String for UI display, array for API if pipe-separated
	 */
	public function get_fb_material( $is_api_call = false ) {
		// Use generic attribute finder
		$material_values = $this->get_attribute_by_type( 'material' );

		if ( $material_values ) {
			return $this->process_attribute_values( $material_values, $is_api_call );
		}

		// Get material directly from post meta as fallback
		$fb_material = get_post_meta(
			$this->id,
			self::FB_MATERIAL,
			true
		);

		// If empty and this is a variation, get the parent material
		if ( empty( $fb_material ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_material = get_post_meta( $parent_id, self::FB_MATERIAL, true );
			}
		}

		// Extract first value from array or object
		$fb_material = $this->get_first_value_from_complex_type( $fb_material );

		$clean_value = mb_substr( WC_Facebookcommerce_Utils::clean_string( $fb_material ), 0, 200 );
		return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
	}

	/**
	 * Gets the FB color value for the product.
	 *
	 * @param bool $is_api_call Whether this is for API submission
	 * @return string|array String for UI display, array for API if pipe-separated
	 */
	public function get_fb_color( $is_api_call = false ) {
		// Use generic attribute finder - try both color and colour
		$color_values = $this->get_attribute_by_type( 'color' );

		// Try British spelling if US spelling fails
		if ( ! $color_values ) {
			$color_values = $this->get_attribute_by_type( 'colour' );
		}

		if ( $color_values ) {
			return $this->process_attribute_values( $color_values, $is_api_call );
		}

		// Get color directly from post meta as fallback
		$fb_color = get_post_meta(
			$this->id,
			self::FB_COLOR,
			true
		);

		// If empty and this is a variation, get the parent color
		if ( empty( $fb_color ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_color = get_post_meta( $parent_id, self::FB_COLOR, true );
			}
		}

		// Extract first value from array or object
		$fb_color = $this->get_first_value_from_complex_type( $fb_color );

		$clean_value = mb_substr( WC_Facebookcommerce_Utils::clean_string( $fb_color ), 0, 200 );
		return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
	}

	/**
	 * Gets the FB size value for the product.
	 *
	 * @param bool $is_api_call Whether this is for API submission
	 * @return string|array String for UI display, array for API if pipe-separated
	 */
	public function get_fb_size( $is_api_call = false ) {
		// Use generic attribute finder
		$size_values = $this->get_attribute_by_type( 'size' );

		if ( $size_values ) {
			return $this->process_attribute_values( $size_values, $is_api_call );
		}

		// Get size directly from post meta as fallback
		$fb_size = get_post_meta(
			$this->id,
			self::FB_SIZE,
			true
		);

		// If empty and this is a variation, get the parent size
		if ( empty( $fb_size ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_size = get_post_meta( $parent_id, self::FB_SIZE, true );
			}
		}

		// Extract first value from array or object
		$fb_size = $this->get_first_value_from_complex_type( $fb_size );

		$clean_value = mb_substr( WC_Facebookcommerce_Utils::clean_string( $fb_size ), 0, 200 );
		return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
	}

	/**
	 * Gets the FB pattern value for the product.
	 *
	 * @param bool $is_api_call Whether this is for API submission
	 * @return string|array String for UI display, array for API if pipe-separated
	 */
	public function get_fb_pattern( $is_api_call = false ) {
		// Use generic attribute finder
		$pattern_values = $this->get_attribute_by_type( 'pattern' );

		if ( $pattern_values ) {
			return $this->process_attribute_values( $pattern_values, $is_api_call );
		}

		// Get pattern directly from post meta as fallback
		$fb_pattern = get_post_meta(
			$this->id,
			self::FB_PATTERN,
			true
		);

		// If empty and this is a variation, get the parent pattern
		if ( empty( $fb_pattern ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_pattern = get_post_meta( $parent_id, self::FB_PATTERN, true );
			}
		}

		// Extract first value from array or object
		$fb_pattern = $this->get_first_value_from_complex_type( $fb_pattern );

		$clean_value = mb_substr( WC_Facebookcommerce_Utils::clean_string( $fb_pattern ), 0, 200 );
		return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
	}


	public function update_visibility( $is_product_page, $visible_box_checked ) {
		$visibility = get_post_meta( $this->id, self::FB_VISIBILITY, true );
		if ( $visibility && ! $is_product_page ) {
			// If the product was previously set to visible, keep it as visible
			// (unless we're on the product page)
			$this->fb_visibility = $visibility;
		} else {
			// If the product is not visible OR we're on the product page,
			// then update the visibility as needed.
			$this->fb_visibility = $visible_box_checked ? true : false;
			update_post_meta( $this->id, self::FB_VISIBILITY, $this->fb_visibility );
		}
	}

	/** Wrapper function to find item_id for default variation */
	public function find_matching_product_variation() {
		if ( is_callable( array( $this, 'get_default_attributes' ) ) ) {
			$default_attributes = $this->get_default_attributes();
		} else {
			$default_attributes = $this->get_variation_default_attributes();
		}

		if ( ! $default_attributes ) {
			return;
		}
		foreach ( $default_attributes as $key => $value ) {
			if ( strncmp( $key, 'attribute_', strlen( 'attribute_' ) ) === 0 ) {
				continue;
			}
			unset( $default_attributes[ $key ] );
			$default_attributes[ sprintf( 'attribute_%s', $key ) ] = $value;
		}
		if ( class_exists( 'WC_Data_Store' ) ) {
			// for >= woo 3.0.0
			$data_store = WC_Data_Store::load( 'product' );
			return $data_store->find_matching_product_variation(
				$this,
				$default_attributes
			);
		} else {
			return $this->get_matching_variation( $default_attributes );
		}
	}

	private function build_checkout_url( $product_url ) {
		// Use product_url for external/bundle product setting.
		$product_type = $this->get_type();
		if ( ! $product_type || ! isset( self::$use_checkout_url[ $product_type ] ) ) {
				$checkout_url = $product_url;
		} elseif ( wc_get_cart_url() ) {
			$char = '?';
			// Some merchant cart pages are actually a querystring
			if ( strpos( wc_get_cart_url(), '?' ) !== false ) {
				$char = '&';
			}

			$checkout_url = WC_Facebookcommerce_Utils::make_url(
				wc_get_cart_url() . $char
			);

			if ( WC_Facebookcommerce_Utils::is_variation_type( $this->get_type() ) ) {
				$query_data = array(
					'add-to-cart'  => $this->get_parent_id(),
					'variation_id' => $this->get_id(),
				);

				$query_data = array_merge(
					$query_data,
					$this->get_variation_attributes()
				);

			} else {
				$query_data = array(
					'add-to-cart' => $this->get_id(),
				);
			}

			$checkout_url = $checkout_url . http_build_query( $query_data );

		} else {
			$checkout_url = null;
		}//end if
	}

	/**
	 * Gets product data to send to Facebook.
	 *
	 * @param string $retailer_id the retailer ID of the product
	 * @param string $type_to_prepare_for whether the data is going to be used in a feed upload, an items_batch update or a direct api call
	 * @return array
	 */
	public function prepare_product( $retailer_id = null, $type_to_prepare_for = self::PRODUCT_PREP_TYPE_NORMAL ) {

		// Directly sync mapped attributes BEFORE preparing product data
		if ( class_exists( ProductAttributeMapper::class ) ) {
			try {
				$product = wc_get_product( $this->id );
				if ( $product ) {
					ProductAttributeMapper::get_and_save_mapped_attributes( $product );
				}
			} catch ( Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WC_Facebook_Product::prepare_product() sync error: ' . $e->getMessage() );
			}
		}

		// Store the preparation type for later use
		$this->current_type_to_prepare = $type_to_prepare_for;

		if ( ! $retailer_id ) {
			$retailer_id = WC_Facebookcommerce_Utils::get_fb_retailer_id( $this );
		}

		$image_urls = $this->get_all_image_urls();

		// Replace WordPress sanitization's ampersand with a real ampersand.
		$product_url = str_replace(
			'&amp%3B',
			'&',
			html_entity_decode( $this->get_permalink() )
		);

		$id = $this->get_id();
		if ( WC_Facebookcommerce_Utils::is_variation_type( $this->get_type() ) ) {
			$id = $this->get_parent_id();
		}

		$categories   = WC_Facebookcommerce_Utils::get_product_categories( $id );
		$category_ids = array_map( 'strval', WC_Facebookcommerce_Utils::get_product_category_ids( $id ) );
		$tags_ids     = array_map( 'strval', WC_Facebookcommerce_Utils::get_excluded_product_tags_ids( $id ) );

		// Determine if this is an API call where we should convert pipe-separated values to arrays
		$is_api_call = ( self::PRODUCT_PREP_TYPE_ITEMS_BATCH === $type_to_prepare_for );

		$product_data                          = array();
		$product_data['description']           = Helper::str_truncate( $this->get_fb_description(), self::MAX_DESCRIPTION_LENGTH );
		$product_data['short_description']     = $this->get_fb_short_description();
		$product_data['rich_text_description'] = $this->get_rich_text_description();
		$product_data['product_type']          = $categories['categories'];
		$product_data['availability']          = $this->is_in_stock() ? 'in stock' : 'out of stock';
		$product_data['visibility']            = Products::is_product_visible( $this->woo_product ) ? \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE : \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN;
		$product_data['retailer_id']           = $retailer_id;
		$product_data['external_variant_id']   = $this->get_id();
		$product_data['internal_label']        = $this->get_internal_labels();
		$product_data['disabled_capabilities'] = $this->get_disabled_capabilities();

		// PRIORITY 1: Set actual product data (from product meta, attributes, etc.)
		$product_data['brand']     = Helper::str_truncate( $this->get_fb_brand( $is_api_call ), 100 );
		$product_data['mpn']       = Helper::str_truncate( $this->get_fb_mpn( $is_api_call ), 100 );
		$product_data['condition'] = $this->get_fb_condition();
		$product_data['size']      = $this->get_fb_size( $is_api_call );
		$product_data['color']     = $this->get_fb_color( $is_api_call );
		$product_data['pattern']   = Helper::str_truncate( $this->get_fb_pattern( $is_api_call ), 100 );
		$product_data['age_group'] = $this->get_fb_age_group();
		$product_data['gender']    = $this->get_fb_gender();
		$product_data['material']  = Helper::str_truncate( $this->get_fb_material(), 100 );
		// Generate and add collection URI
		$collection_uri                                     = site_url( CollectionPage::ENDPOINT_PATH );
		$product_data[ CollectionPage::PRODUCT_FEED_FIELD ] = $collection_uri;
		if ( $this->get_type() === 'variation' ) {
			$parent_id      = $this->woo_product->get_parent_id();
			$parent_product = wc_get_product( $parent_id );

			if ( $parent_product ) {
				$parent_product_visibility            = $parent_product->get_meta( Products::VISIBILITY_META_KEY );
				$current_variation_product_visibility = Products::is_product_visible( $this->woo_product );

				/**
				 * If parent's visibility is already marked we know we should assign it to the child/variation as well
				 */
				if ( 'yes' === $parent_product_visibility ) {
					if ( ! $current_variation_product_visibility ) {
						$product_data['is_woo_all_products_sync'] = 1;
					}
					$product_data['visibility'] = \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE;
				} elseif ( 'no' === $parent_product_visibility ) {
					$product_data['visibility'] = \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN;
				} else {
					/**
					 * If the visibility is empty,
					 * We then check for the variation's visibility.
					 * If even a single one is marked yes, we bail it out as published.
					 * If all marked no we honor the visibility as hidden.
					 *
					 * The variations are read in every language: with a narrowed children query the
					 * parent looked like it had none, and it was saved as hidden further down.
					 */
					$variations           = \WC_Facebookcommerce_Utils::get_variation_ids_in_every_language( $parent_id );
					$variation_visibility = false;

					foreach ( $variations as $variation_id ) {
						$variation = wc_get_product( $variation_id );

						if ( $variation ) {
							$variation_visibility = $variation_visibility || Products::is_product_visible( $variation );
						}

						if ( $variation_visibility ) {
							break;
						}
					}

					/**
					 * Tagging those products who were previously having visibility hidden
					 * But now have visibility published
					 */
					if ( $variation_visibility && ! $current_variation_product_visibility ) {
						$product_data['is_woo_all_products_sync'] = 1;
					}

					$product_data['visibility'] = $variation_visibility ? \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE : \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN;
					/**
					 *  Since this function will be called again for other variations as well for the same parent product.
					 *  We can now assign the visibility marker to the parent product
					 *  That way it won't come to this block next time
					 */

					update_post_meta( $parent_id, Products::VISIBILITY_META_KEY, $variation_visibility ? 'yes' : 'no' );
				}
			}
		}

		/**
		 * Additional check to ensure product is marked hidden in case of out of stock
		 */
		$product_id      = $this->get_id();
		$current_product = wc_get_product( $product_id );

		if ( $current_product && ! $current_product->is_in_stock() ) {
			$product_data['visibility'] = \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN;
		}

		// Set any attributes not already set by direct mappings
		if ( ! isset( $product_data['brand'] ) ) {
			$product_data['brand'] = Helper::str_truncate( $this->get_fb_brand( $is_api_call ), 100 );
		}

		if ( ! isset( $product_data['mpn'] ) ) {
			$product_data['mpn'] = Helper::str_truncate( $this->get_fb_mpn( $is_api_call ), 100 );
		}

		if ( ! isset( $product_data['condition'] ) ) {
			$product_data['condition'] = $this->get_fb_condition();
		}

		if ( ! isset( $product_data['size'] ) ) {
			$product_data['size'] = $this->get_fb_size( $is_api_call );
		}

		if ( ! isset( $product_data['color'] ) ) {
			$product_data['color'] = $this->get_fb_color( $is_api_call );
		}

		if ( ! isset( $product_data['pattern'] ) ) {
			$product_data['pattern'] = Helper::str_truncate( $this->get_fb_pattern( $is_api_call ), 100 );
		}

		// Only set age_group if we actually have a value (make it optional)
		if ( ! isset( $product_data['age_group'] ) ) {
			$age_group_value = $this->get_fb_age_group();
			if ( ! empty( $age_group_value ) ) {
				$product_data['age_group'] = $age_group_value;
			}
		}

		if ( ! isset( $product_data['gender'] ) ) {
			$product_data['gender'] = $this->get_fb_gender();
		}

		if ( ! isset( $product_data['material'] ) ) {
			$product_data['material'] = Helper::str_truncate( $this->get_fb_material(), 100 );
		}
		// For API calls, check mapped attributes first to ensure they're properly handled
		if ( $is_api_call && class_exists( ProductAttributeMapper::class ) ) {
			// Get our attribute mappings - only use explicitly defined mappings
			$attribute_mappings = $this->get_default_attribute_mappings();

			// Check each mapped attribute
			foreach ( $attribute_mappings as $woo_attribute => $fb_attribute ) {
				// Skip if the natural attribute is already set
				if ( isset( $product_data[ $fb_attribute ] ) && ! empty( $product_data[ $fb_attribute ] ) ) {
					continue;
				}

				// Get the attribute value
				$attribute_value = $this->woo_product->get_attribute( $woo_attribute );

				if ( ! empty( $attribute_value ) ) {
					// Normalize the value based on the Facebook attribute type
					switch ( $fb_attribute ) {
						case 'age_group':
							$normalized_value = ProductAttributeMapper::normalize_age_group_value( $attribute_value );
							break;
						case 'gender':
							$normalized_value = ProductAttributeMapper::normalize_gender_value( $attribute_value );
							break;
						case 'condition':
							// Only allow specific condition values
							$normalized_value = strtolower( trim( $attribute_value ) );
							if ( ! in_array( $normalized_value, array( 'new', 'used', 'refurbished' ), true ) ) {
								$normalized_value = 'new'; // Default to new if not valid
							}
							break;
						default:
							// For other attributes, just clean the string
							$normalized_value = WC_Facebookcommerce_Utils::clean_string( $attribute_value );

							// Handle array conversion for API calls (if value contains pipe separator)
							if ( is_string( $normalized_value ) && strpos( $normalized_value, ' | ' ) !== false ) {
								$normalized_value = array_map( 'trim', explode( ' | ', $normalized_value ) );
							}
							break;
					}

					// Set the value in product data only if it's not already set
					if ( ! isset( $product_data[ $fb_attribute ] ) || empty( $product_data[ $fb_attribute ] ) ) {
						$product_data[ $fb_attribute ] = $normalized_value;
					}
				}
			}
		}

		// PRIORITY 2: Check attribute mappings, but respect explicitly set Facebook meta values
		// Priority order: 1. Explicit Facebook meta values  2. Mapped attribute values  3. Default values
		if ( class_exists( ProductAttributeMapper::class ) ) {
			$mapped_attributes = ProductAttributeMapper::get_mapped_attributes( $this->woo_product );

			// Process each mapped attribute - respect explicitly set values first
			foreach ( $mapped_attributes as $fb_field => $value ) {
				// For extended fields, always prioritize mapped values over existing ones
				$is_extended_field = in_array(
					$fb_field,
					array(
						'sale_price',
						'inventory',
						'additional_image_link',
						'tax',
					),
					true
				);

				// Check if there's an explicitly set Facebook meta value for this field
				$explicit_meta_value = null;
				if ( $is_extended_field ) {
					// Check for explicit meta values for extended fields
					switch ( $fb_field ) {
						case 'sale_price':
							$explicit_meta_value = get_post_meta( $this->id, '_wc_facebook_sale_price', true );
							break;
						case 'inventory':
							$explicit_meta_value = get_post_meta( $this->id, '_wc_facebook_inventory', true );
							break;
						case 'tax':
							$explicit_meta_value = get_post_meta( $this->id, '_wc_facebook_tax', true );
							break;
						case 'additional_image_link':
							$explicit_meta_value = get_post_meta( $this->id, '_wc_facebook_additional_image_link', true );
							break;
					}
				} else {
					// Check for explicit meta values for standard fields
					switch ( $fb_field ) {
						case 'brand':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_brand', true );
							break;
						case 'color':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_color', true );
							break;
						case 'material':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_material', true );
							break;
						case 'size':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_size', true );
							break;
						case 'pattern':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_pattern', true );
							break;
						case 'age_group':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_age_group', true );
							break;
						case 'gender':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_gender', true );
							break;
						case 'condition':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_product_condition', true );
							break;
						case 'mpn':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_mpn', true );
							break;
						case 'gtin':
							$explicit_meta_value = get_post_meta( $this->id, 'fb_gtin', true );
							break;
					}
				}

				// Determine if we should set the field
				$should_set_field = false;
				if ( ! empty( $explicit_meta_value ) ) {
					// If there's an explicit meta value, use it and skip mapped value
					$product_data[ $fb_field ] = $explicit_meta_value;
					$should_set_field          = false; // We've already set the value
				} elseif ( $is_extended_field ) {
					// For extended fields, use mapped value if no explicit value and mapped value is not empty
					$should_set_field = ! empty( $value );
				} else {
					// For standard fields, only set if field is empty and mapped value is not empty
					$should_set_field = ( ! isset( $product_data[ $fb_field ] ) || empty( $product_data[ $fb_field ] ) ) && ! empty( $value );
				}

				if ( $should_set_field ) {

					// Process the extended fields that might be mapped
					switch ( $fb_field ) {
						case 'inventory':
							$product_data[ $fb_field ] = is_numeric( $value ) ? (int) $value : 0;
							break;

						case 'tax':
							$product_data[ $fb_field ] = WC_Facebookcommerce_Utils::clean_string( $value );
							break;

						case 'sale_price':
							// Handle sale price mapping if it's numeric - OVERRIDE existing value
							if ( is_numeric( $value ) ) {
								$product_data[ $fb_field ] = $is_api_call ? self::format_price_for_fb_items_batch( $value * 100 ) : (int) ( $value * 100 );
							}
							break;

						case 'additional_image_link':
							// Handle additional image link - could be array or string
							if ( is_array( $value ) ) {
								$product_data[ $fb_field ] = $value;
							} else {
								$product_data[ $fb_field ] = array( WC_Facebookcommerce_Utils::clean_string( $value ) );
							}
							break;

						// For standard fields, only fill if empty
						case 'brand':
							$product_data[ $fb_field ] = Helper::str_truncate( WC_Facebookcommerce_Utils::clean_string( $value ), 100 );
							break;
						case 'mpn':
							$product_data[ $fb_field ] = Helper::str_truncate( WC_Facebookcommerce_Utils::clean_string( $value ), 100 );
							break;
						case 'condition':
							$clean_value = strtolower( trim( $value ) );
							if ( in_array( $clean_value, array( 'new', 'used', 'refurbished' ), true ) ) {
								$product_data[ $fb_field ] = $clean_value;
							}
							break;
						case 'age_group':
							$normalized_value = ProductAttributeMapper::normalize_age_group_value( $value );
							if ( ! empty( $normalized_value ) ) {
								$product_data[ $fb_field ] = $normalized_value;
							}
							break;
						case 'gender':
							$product_data[ $fb_field ] = ProductAttributeMapper::normalize_gender_value( $value );
							break;
						case 'color':
						case 'size':
						case 'pattern':
						case 'material':
							$product_data[ $fb_field ] = Helper::str_truncate( WC_Facebookcommerce_Utils::clean_string( $value ), 100 );
							break;

						default:
							// For other extended fields, just clean and set
							$product_data[ $fb_field ] = WC_Facebookcommerce_Utils::clean_string( $value );
							break;
					}
				}
			}
		}

		// Set any attributes not already set by direct mappings
		if ( ! isset( $product_data['brand'] ) ) {
			$product_data['brand'] = Helper::str_truncate( $this->get_fb_brand( $is_api_call ), 100 );
		}

		if ( ! isset( $product_data['mpn'] ) ) {
			$product_data['mpn'] = Helper::str_truncate( $this->get_fb_mpn( $is_api_call ), 100 );
		}

		if ( ! isset( $product_data['condition'] ) ) {
			$product_data['condition'] = $this->get_fb_condition();
		}

		if ( ! isset( $product_data['size'] ) ) {
			$product_data['size'] = $this->get_fb_size( $is_api_call );
		}

		if ( ! isset( $product_data['color'] ) ) {
			$product_data['color'] = $this->get_fb_color( $is_api_call );
		}

		if ( ! isset( $product_data['pattern'] ) ) {
			$product_data['pattern'] = Helper::str_truncate( $this->get_fb_pattern( $is_api_call ), 100 );
		}

		// Only set age_group if we actually have a value (make it optional)
		if ( ! isset( $product_data['age_group'] ) ) {
			$age_group_value = $this->get_fb_age_group();
			if ( ! empty( $age_group_value ) ) {
				$product_data['age_group'] = $age_group_value;
			}
		}

		if ( ! isset( $product_data['gender'] ) ) {
			$product_data['gender'] = $this->get_fb_gender();
		}

		if ( ! isset( $product_data['material'] ) ) {
			$product_data['material'] = Helper::str_truncate( $this->get_fb_material(), 100 );
		}

		/**
		 * Visibility has been set for the products, both for simple and variations
		 * Now if prevously they had product sync checkbox/ global products sync off, we will mark the products
		 */

		$deprecated_global_sync_checkbox_status = 'yes' === get_option( 'wc_facebook_enable_product_sync', 'yes' );
		if ( false === $deprecated_global_sync_checkbox_status ) {
			/**
			 * Previously they wouldn't have syned
			 * But now they are
			 */
			$product_data['is_woo_all_products_sync'] = 1;
		}

		/*
		 * If a category was previously excluded that meant that it was not getting synced
		 * 3.5.3 changes will make sure these categories are no longer kept from syncing
		 * So we are tagging them as well since they are now synced with us
		 */
		$deprecated_excluded_category_ids = get_option( 'wc_facebook_excluded_product_category_ids' );

		if ( $deprecated_excluded_category_ids && $category_ids && ! empty( array_intersect( $deprecated_excluded_category_ids, $category_ids ) ) ) {
			$product_data['is_woo_all_products_sync'] = 1;
		}

		/**
		 * Doing same tagging for proudct tags exclusion
		*/
		$deprecated_excluded_tag_ids = get_option( 'wc_facebook_excluded_product_tag_ids' );

		if ( $deprecated_excluded_tag_ids && $tags_ids && ! empty( array_intersect( $deprecated_excluded_tag_ids, $tags_ids ) ) ) {
			$product_data['is_woo_all_products_sync'] = 1;
		}

		if ( self::PRODUCT_PREP_TYPE_ITEMS_BATCH === $type_to_prepare_for ) {

			$product_data['title']                 = Helper::str_truncate( WC_Facebookcommerce_Utils::clean_string( $this->get_title() ), self::MAX_TITLE_LENGTH );
			$product_data['image_link']            = $image_urls[0];
			$product_data['additional_image_link'] = $this->get_additional_image_urls( $image_urls );
			$product_data['link']                  = $product_url;
			$product_data['price']                 = $this->get_fb_price( true );

			$product_data = $this->add_sale_price( $product_data, true );
		} else {
			$product_data['name']                  = WC_Facebookcommerce_Utils::clean_string( $this->get_title() );
			$product_data['image_url']             = $image_urls[0];
			$product_data['additional_image_urls'] = $this->get_additional_image_urls( $image_urls );
			$product_data['url']                   = $product_url;
			$product_data['price']                 = $this->get_fb_price();
			$product_data['currency']              = get_woocommerce_currency();

			/**
			 * 'category' is a required field for creating a ProductItem object when posting to /{product_catalog_id}/products.
			 * This field should have the Google product category for the item. Google product category is not a required field
			 * in the WooCommerce product editor. Hence, we are setting 'category' to Woo product categories by default and overriding
			 * it when a Google product category is set.
			 *
			 * @see https://developers.facebook.com/docs/marketing-api/reference/product-catalog/products/#parameters-2
			 * @see https://github.com/woocommerce/facebook-for-woocommerce/pull/2575
			 * @see https://github.com/woocommerce/facebook-for-woocommerce/issues/2593
			 */
			$product_data['category'] = $categories['categories'];

			$product_data = $this->add_sale_price( $product_data );
		}//end if

		$video_urls = $this->get_all_video_urls();

		// If this is a variation with no videos, fall back to parent product videos
		if ( $this->get_type() === 'variation' && empty( $video_urls ) ) {
			$parent_id  = $this->woo_product->get_parent_id();
			$video_urls = $this->get_all_video_urls( $parent_id );
		}

		if ( ! empty( $video_urls ) && self::PRODUCT_PREP_TYPE_NORMAL !== $type_to_prepare_for ) {
			$product_data['video'] = $video_urls;
		}

		$google_product_category = Products::get_google_product_category_id( $this->woo_product );
		if ( $google_product_category ) {
			$product_data['google_product_category'] = $google_product_category;
		}

		// Currently only items batch and feed support enhanced catalog fields
		if ( $google_product_category && self::PRODUCT_PREP_TYPE_NORMAL !== $type_to_prepare_for ) {
			$product_data = $this->apply_enhanced_catalog_fields_from_attributes( $product_data, $google_product_category );
		}

		// Add stock quantity if the product or variant is stock managed.
		// In case if variant is not stock managed but parent is, fallback on parent value.
		if ( $this->woo_product->managing_stock() ) {
			$product_data['quantity_to_sell_on_facebook'] = (int) max( 0, $this->woo_product->get_stock_quantity() );
		} elseif ( $this->woo_product->is_type( 'variation' ) ) {
			$parent_product = wc_get_product( $this->woo_product->get_parent_id() );
			if ( $parent_product && $parent_product->managing_stock() ) {
				$product_data['quantity_to_sell_on_facebook'] = (int) max( 0, $parent_product->get_stock_quantity() );
			}
		}

		// Add GTIN (Global Trade Item Number)
		if ( method_exists( $this->woo_product, 'get_global_unique_id' ) && $this->woo_product->get_global_unique_id() ) {
			$product_data['gtin'] = $this->woo_product->get_global_unique_id();
		}

		$date_modified = $this->woo_product->get_date_modified();
		if ( $date_modified ) {
			$external_update_time = (int) $date_modified->getTimestamp();
			$last_change_time     = (int) $this->woo_product->get_meta( '_last_change_time' );

			// Use the newer timestamp if _last_change_time is valid, otherwise use external_update_time
			if ( $last_change_time > 0 ) {
				$product_data['external_update_time'] = max( $external_update_time, $last_change_time );
			} else {
				$product_data['external_update_time'] = $external_update_time;
			}
		}

		$product_data['plugin_version'] = facebook_for_woocommerce()->get_version();

		// Only use checkout URLs if they exist.
		$checkout_url = $this->build_checkout_url( $product_url );
		if ( $checkout_url ) {
			$product_data['checkout_url'] = $checkout_url;
		}

		// If using WPML, set the product to hidden unless it is in the
		// default language. WPML >= 3.2 Supported.
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			if ( class_exists( \WooCommerce\Facebook\WPMLInjector::class ) && \WooCommerce\Facebook\WPMLInjector::should_hide( $id ) ) {
				$product_data['visibility'] = \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN;
			}
		}

		// Exclude variations that are "virtual" products from export to Facebook &&
		// No Visibility Option for Variations
		// get_virtual() returns true for "unassembled bundles", so we exclude
		// bundles from this check.
		if ( true === $this->get_virtual() && 'bundle' !== $this->get_type() ) {
			$product_data['visibility'] = \WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN;
		}

		if ( self::PRODUCT_PREP_TYPE_FEED !== $type_to_prepare_for ) {
			$this->prepare_variants_for_item( $product_data );
		} elseif (
			WC_Facebookcommerce_Utils::is_all_caps( $product_data['description'] )
		) {
			$product_data['description'] =
			mb_strtolower( $product_data['description'] );
		}

		/**
		* Filters the generated product data.
		*
		* @param int   $id           Woocommerce product id
		* @param array $product_data An array of product data
		*/
		$product_data = apply_filters(
			'facebook_for_woocommerce_integration_prepare_product',
			$product_data,
			$id
		);

		// For API calls, normalize values to match Facebook's requirements
		if ( self::PRODUCT_PREP_TYPE_ITEMS_BATCH === $type_to_prepare_for ) {
			$product_data = $this->normalize_api_values( $product_data );

		}

		return $product_data;
	}

	/**
	 * Adds enhanced catalog fields to product data array. Separated from
	 * the main function to make it easier to develop and debug, potentially
	 * worth refactoring into main prepare_product function when complete.
	 *
	 * @param array  $product_data       The preparted product data map.
	 * @param string $google_category_id The Google product category id string.
	 * @return array
	 */
	private function apply_enhanced_catalog_fields_from_attributes( $product_data, $google_category_id ) {
		$category_handler = facebook_for_woocommerce()->get_facebook_category_handler();
		if ( empty( $google_category_id ) || ! $category_handler->is_category( $google_category_id ) ) {
			return $product_data;
		}
		$enhanced_data = array();

		$all_attributes = $category_handler->get_attributes_with_fallback_to_parent_category( $google_category_id );

		if ( empty( $all_attributes ) ) {
			return $product_data;
		}

		foreach ( $all_attributes as $attribute ) {
			$value            = Products::get_enhanced_catalog_attribute( $attribute['key'], $this->woo_product );
			$convert_to_array = (
				isset( $attribute['can_have_multiple_values'] ) &&
				true === $attribute['can_have_multiple_values'] &&
				'string' === $attribute['type']
			);

			if ( ! empty( $value ) &&
				$category_handler->is_valid_value_for_attribute( $google_category_id, $attribute['key'], $value )
			) {
				if ( $convert_to_array ) {
					$value = array_map( 'trim', explode( ',', $value ) );
				}
				$enhanced_data[ $attribute['key'] ] = $value;
			}
		}

		return array_merge( $product_data, $enhanced_data );
	}


	/**
	 * Filters list of attributes to only those available for a given product
	 *
	 * @param \WC_Product $product WooCommerce Product
	 * @param array       $all_attributes List of Enhanced Catalog attributes to match
	 * @return array
	 */
	public function get_matched_attributes_for_product( $product, $all_attributes ) {
		$matched_attributes = array();
		$sanitized_keys     = array_map(
			function ( $key ) {
					return \WC_Facebookcommerce_Utils::sanitize_variant_name( $key, false );
			},
			array_keys( $product->get_attributes() )
		);

		$matched_attributes = array_filter(
			$all_attributes,
			function ( $attribute ) use ( $sanitized_keys ) {
				if ( is_array( $attribute ) && isset( $attribute['key'] ) ) {
					return in_array( $attribute['key'], $sanitized_keys, true );
				}
				return false; // Return false if $attribute is not valid
			}
		);

		return $matched_attributes;
	}

	/**
	 * Normalizes variant data for Facebook.
	 *
	 * @param array $product_data variation product data
	 * @return array
	 */
	public function prepare_variants_for_item( &$product_data ) {

		/** @var \WC_Product_Variation $product */
		$product = $this;

		if ( ! $product->is_type( 'variation' ) ) {
			return array();
		}

		$attributes = $product->get_variation_attributes();

		if ( ! $attributes ) {
			return array();
		}

		$variant_names = array_keys( $attributes );
		$variant_data  = array();

		// Loop through variants (size, color, etc) if they exist
		// For each product field type, pull the single variant
		foreach ( $variant_names as $original_variant_name ) {

			// Ensure that the attribute exists before accessing it
			if ( ! isset( $attributes[ $original_variant_name ] ) ) {
				continue; // Skip if the attribute is not set
			}

			// don't handle any attributes that are designated as Commerce attributes
			if ( in_array( str_replace( 'attribute_', '', strtolower( $original_variant_name ) ), Products::get_distinct_product_attributes( $this->woo_product ), true ) ) {
				continue;
			}

			// Retrieve label name for attribute
			$label = wc_attribute_label( $original_variant_name, $product );

			// Clean up variant name (e.g. pa_color should be color)
			$new_name = \WC_Facebookcommerce_Utils::sanitize_variant_name( $original_variant_name, false );

			// Sometimes WC returns an array, sometimes it's an assoc array, depending
			// on what type of taxonomy it's using.  array_values will guarantee we
			// only get a flat array of values.
			if ( \WC_Facebookcommerce_Utils::get_variant_option_name( $this->id, $label, $attributes[ $original_variant_name ] ) ) {

				$options = \WC_Facebookcommerce_Utils::get_variant_option_name( $this->id, $label, $attributes[ $original_variant_name ] );

				if ( is_array( $options ) ) {

					$option_values = array_values( $options );

				} else {

					$option_values = array( $options );

					// If this attribute has value 'any', options will be empty strings
					// Redirect to product page to select variants.
					// Reset checkout url since checkout_url (build from query data will
					// be invalid in this case.
					if ( count( $option_values ) === 1 && empty( $option_values[0] ) ) {
							$option_values[0]             = 'any';
							$product_data['checkout_url'] = $product_data['url'];
					}
				}

				if ( \WC_Facebookcommerce_Utils::FB_VARIANT_GENDER === $new_name && ! isset( $product_data[ \WC_Facebookcommerce_Utils::FB_VARIANT_GENDER ] ) ) {

					// If we can't validate the gender, this will be null.
					$product_data[ $new_name ] = \WC_Facebookcommerce_Utils::validate_gender( $option_values[0] );
				}

				switch ( $new_name ) {

					case \WC_Facebookcommerce_Utils::FB_VARIANT_GENDER:
						// If we can't validate the GENDER field, we'll fall through to the
						// default case and set the gender into custom data.
						if ( $product_data[ $new_name ] ) {

							$variant_data[] = array(
								'product_field' => $new_name,
								'label'         => $label,
								'options'       => $option_values,
							);
						}

						break;

					default:
						// This is for any custom_data.
						if ( ! isset( $product_data['custom_data'] ) ) {
							$product_data['custom_data'] = array();
						}
						$new_name                                 = wc_attribute_label( $new_name, $product );
						$product_data['custom_data'][ $new_name ] = urldecode( $option_values[0] );
						break;
				}//end switch
			} else {
				continue;
			}//end if
		}//end foreach

		return $variant_data;
	}


	/**
	 * Normalizes variable product variations data for Facebook.
	 *
	 * @param bool $feed_data whether this is used for feed data
	 * @return array
	 * @throws \Exception If this function is called for non-variable products.
	 */
	public function prepare_variants_for_group( $feed_data = false ) {

		/** @var \WC_Product_Variable $product */
		$product        = $this;
		$final_variants = array();

		try {

			if ( ! $product->is_type( 'variable' ) ) {
				throw new \Exception( 'prepare_variants_for_group called on non-variable product' );
			}

			$variation_attributes = $product->get_variation_attributes();

			if ( ! $variation_attributes ) {
				return array();
			}

			foreach ( array_keys( $product->get_attributes() ) as $name ) {

				$label = wc_attribute_label( $name, $product );

				if ( taxonomy_is_product_attribute( $name ) ) {
					$key = $name;
				} else {
					// variation_attributes keys are labels for custom attrs for some reason
					$key = $label;
				}

				if ( ! $key ) {
					throw new \Exception( "Critical error: can't get attribute name or label!" );
				}

				if ( isset( $variation_attributes[ $key ] ) ) {
					// array of the options (e.g. small, medium, large)
					$option_values = $variation_attributes[ $key ];
				} else {
					// skip variations without valid attribute options
					continue;
				}

				// If this is a variable product, check default attribute.
				// If it's being used, show it as the first option on Facebook.
				if ( $product->get_variation_default_attribute( $key ) ) {

					$first_option = $product->get_variation_default_attribute( $key );

					$index = array_search( $first_option, $option_values, true );

					unset( $option_values[ $index ] );

					array_unshift( $option_values, $first_option );
				}

				if ( function_exists( 'taxonomy_is_product_attribute' ) && taxonomy_is_product_attribute( $name ) ) {
					$option_values = $this->get_grouped_product_option_names( $key, $option_values );
				}

				switch ( $name ) {

					case Products::get_product_color_attribute( $this->woo_product ):
						$name = WC_Facebookcommerce_Utils::FB_VARIANT_COLOR;
						break;

					case Products::get_product_size_attribute( $this->woo_product ):
						$name = WC_Facebookcommerce_Utils::FB_VARIANT_SIZE;
						break;

					case Products::get_product_pattern_attribute( $this->woo_product ):
						$name = WC_Facebookcommerce_Utils::FB_VARIANT_PATTERN;
						break;

					default:
						/**
						 * For API approach, product_field need to start with 'custom_data:'
						 *
						 * @link https://developers.facebook.com/docs/marketing-api/reference/product-variant/
						 */
						$name = \WC_Facebookcommerce_Utils::sanitize_variant_name( $name );
				}//end switch

				// for feed uploading, product field should remove prefix 'custom_data:'
				if ( $feed_data ) {
					$name = str_replace( 'custom_data:', '', $name );
				}

				$final_variants[] = array(
					'product_field' => $name,
					'label'         => $label,
					'options'       => $option_values,
				);
			}//end foreach
		} catch ( \Exception $e ) {
			Logger::log(
				$e->getMessage(),
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);
			return array();
		}//end try

		return $final_variants;
	}

	/**
	 * Some products cannot be directly used in a checkout link. This field is used to exclude those
	 * from being shown on Facebook Shops.
	 *
	 * @return array<string> list of disabled capabilities
	 */
	private function get_disabled_capabilities(): array {
		$product_type = $this->woo_product->get_type();

		// grouped and external products do not work with the checkout URL
		if ( 'grouped' === $product_type || 'external' === $product_type ) {
			return array( 'mini_shops' );
		}

		// product variations that have undefined attributes ("Any Size...", "Any Color...", etc) are unsupported
		if ( 'variation' === $product_type ) {
			$attributes = $this->woo_product->get_attributes();

			foreach ( $attributes as $_attribute_name => $attribute_value ) {
				if ( '' === $attribute_value || null === $attribute_value ) {
					return array( 'mini_shops' );
				}
			}
		}

		return array();
	}

	public function get_fb_mpn( $is_api_call = false ) {
		// Check for taxonomy attribute for MPN
		$mpn_values = $this->get_attribute_by_type( 'mpn' );
		if ( $mpn_values ) {
			return $this->process_attribute_values( $mpn_values, $is_api_call );
		}

		// If this is a variation, get its specific mpn value
		if ( $this->is_type( 'variation' ) ) {
			$attributes = $this->woo_product->get_attributes();

			foreach ( $attributes as $key => $value ) {
				$attr_key = strtolower( $key );
				if ( 'mpn' === $attr_key ) {
					// Extract first value from array or object for attribute
					$value       = $this->get_first_value_from_complex_type( $value );
					$clean_value = WC_Facebookcommerce_Utils::clean_string( $value );
					return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
				}
			}
		}

		// Get material directly from post meta for non-variation products
		$fb_mpn = get_post_meta(
			$this->id,
			self::FB_MPN,
			true
		);

		// If empty and this is a variation, get the parent mpn
		if ( empty( $fb_mpn ) && $this->is_type( 'variation' ) ) {
			$parent_id = $this->get_parent_id();
			if ( $parent_id ) {
				$fb_mpn = get_post_meta( $parent_id, self::FB_MPN, true );
			}
		}

		// Extract first value from array or object
		$fb_mpn = $this->get_first_value_from_complex_type( $fb_mpn );

		$clean_value = WC_Facebookcommerce_Utils::clean_string( $fb_mpn );
		return $this->convert_pipe_separated_values( $clean_value, $is_api_call );
	}

	/**
	 * Maps Facebook attribute display names (as shown in UI) to their API field names
	 *
	 * @return array Mapping of display names to API field names
	 */
	private static function get_facebook_attribute_display_to_api_mapping() {
		return array(
			'Age group'                 => 'age_group',
			'Availability'              => 'availability',
			'Brand'                     => 'brand',
			'Color'                     => 'color',
			'Condition'                 => 'condition',
			'Gender'                    => 'gender',
			'Material'                  => 'material',
			'Pattern'                   => 'pattern',
			'Size'                      => 'size',
			'MPN'                       => 'mpn',
			'GTIN'                      => 'gtin',
			// Extended fields
			'Additional image link'     => 'additional_image_link',
			'Image link'                => 'image_link',
			'Title'                     => 'title',
			'Description'               => 'description',
			'Price'                     => 'price',
			'Sale price'                => 'sale_price',
			'Sale price effective date' => 'sale_price_effective_date',
		);
	}

	/**
	 * Gets default attribute mappings when the ProductAttributeMapper doesn't have a method for it.
	 *
	 * @return array Map of WooCommerce attributes to Facebook attributes
	 */
	private function get_default_attribute_mappings() {
		// Get the mapping from Facebook display names to API field names
		$fb_display_to_api = self::get_facebook_attribute_display_to_api_mapping();

		// First check if the Facebook options exist in database
		// These are stored by the Facebook UI as woo attribute -> Facebook display name
		$saved_ui_mappings = get_option( 'wc_facebook_product_attribute_mappings', array() );

		// If we have UI mappings, convert them to API field names
		if ( ! empty( $saved_ui_mappings ) && is_array( $saved_ui_mappings ) ) {
			$api_mappings = array();

			foreach ( $saved_ui_mappings as $woo_attribute => $fb_display_name ) {
				// Convert Facebook display name to API field name
				if ( isset( $fb_display_to_api[ $fb_display_name ] ) ) {
					$api_field                      = $fb_display_to_api[ $fb_display_name ];
					$api_mappings[ $woo_attribute ] = $api_field;
				} else {
					// If we can't map it, keep the original
					$api_mappings[ $woo_attribute ] = $fb_display_name;
				}
			}

			return $api_mappings;
		}

		// If no UI mappings, try to get mappings from the ProductAttributeMapper
		if ( class_exists( ProductAttributeMapper::class ) &&
			method_exists( ProductAttributeMapper::class, 'get_custom_attribute_mappings' ) ) {
			$mappings = ProductAttributeMapper::get_custom_attribute_mappings();

			if ( ! empty( $mappings ) ) {
				return $mappings;
			}
		}

		// Fall back to basic mappings only if no other mappings exist
		$mappings = array(
			'pa_age_group' => 'age_group',
			'pa_age'       => 'age_group',
			'pa_brand'     => 'brand',
			'pa_gender'    => 'gender',
			'pa_color'     => 'color',
			'pa_size'      => 'size',
			'pa_material'  => 'material',
			'pa_pattern'   => 'pattern',
			'pa_condition' => 'condition',

			// Without pa_ prefix
			'age_group'    => 'age_group',
			'age'          => 'age_group',
			'brand'        => 'brand',
			'gender'       => 'gender',
			'color'        => 'color',
			'size'         => 'size',
			'material'     => 'material',
			'pattern'      => 'pattern',
			'condition'    => 'condition',
		);

		return $mappings;
	}

	/**
	 * Normalizes values for Facebook API calls to ensure they match Facebook's format requirements.
	 *
	 * @param array $product_data The product data to normalize
	 * @return array The normalized product data
	 */
	private function normalize_api_values( $product_data ) {

		// Normalize age_group for API only if it's already set
		if ( isset( $product_data['age_group'] ) && ! empty( $product_data['age_group'] ) ) {

			// Ensure age_group is properly formatted for API
			$age_group = strtolower( trim( $product_data['age_group'] ) );
			// List of valid values Facebook accepts for age_group in API calls
			// Updated to match Facebook's actual supported values
			$valid_age_groups = array( 'newborn', 'infant', 'toddler', 'kids', 'teen', 'adult', 'all ages' );

			if ( ! in_array( $age_group, $valid_age_groups, true ) ) {
				// Try to map to a valid value
				if ( 'teenager' === $age_group ) {
					$product_data['age_group'] = 'teen'; // Map teenager to teen
				} elseif ( 'children' === $age_group || 'child' === $age_group ) {
					$product_data['age_group'] = 'kids'; // Map children to kids
				} elseif ( false !== strpos( $age_group, 'kid' ) || false !== strpos( $age_group, 'child' ) ) {
					// Default to kids if no matching value and contains kid/child
					$product_data['age_group'] = 'kids';
				} else {
					// Remove age_group if it's not a valid value
					unset( $product_data['age_group'] );
				}
			}
		}

		// Normalize gender for API
		if ( isset( $product_data['gender'] ) ) {
			$gender        = strtolower( trim( $product_data['gender'] ) );
			$valid_genders = array( 'male', 'female', 'unisex' );

			if ( ! in_array( $gender, $valid_genders, true ) ) {
				// Map to supported values
				if ( in_array( $gender, array( 'man', 'men', 'boys', 'boy' ), true ) ) {
					$product_data['gender'] = 'male';
				} elseif ( in_array( $gender, array( 'woman', 'women', 'girls', 'girl' ), true ) ) {
					$product_data['gender'] = 'female';
				}
			}
		}

		// Normalize condition for API
		if ( isset( $product_data['condition'] ) ) {
			$condition        = strtolower( trim( $product_data['condition'] ) );
			$valid_conditions = array( 'new', 'used', 'refurbished' );

			if ( ! in_array( $condition, $valid_conditions, true ) ) {
				// Default to new for invalid conditions
				$product_data['condition'] = 'new';
			}
		}

		return $product_data;
	}

	/**
	 * Gets available product attribute mapping options for admin settings.
	 *
	 * This method can be called by the admin interface to show available mapping options.
	 *
	 * @return array Array of WooCommerce to Facebook attribute mappings
	 */
	public static function get_product_attribute_mapping_options() {
		// If the ProductAttributeMapper class exists, use its fields
		if ( class_exists( ProductAttributeMapper::class ) &&
			method_exists( ProductAttributeMapper::class, 'get_all_facebook_fields' ) ) {
			$all_fields          = ProductAttributeMapper::get_all_facebook_fields();
			$facebook_attributes = array();

			// Convert field mapping array to our format
			foreach ( $all_fields as $field => $variations ) {
				$facebook_attributes[ $field ] = ucfirst( str_replace( '_', ' ', $field ) );
			}

			return array(
				'facebook_attributes'       => $facebook_attributes,
				'facebook_attribute_values' => array(
					'age_group' => array(
						'adult'    => __( 'Adult', 'facebook-for-woocommerce' ),
						'all ages' => __( 'All Ages', 'facebook-for-woocommerce' ),
						'kids'     => __( 'Kids', 'facebook-for-woocommerce' ),
						'teen'     => __( 'Teen', 'facebook-for-woocommerce' ),
						'infant'   => __( 'Infant', 'facebook-for-woocommerce' ),
						'newborn'  => __( 'Newborn', 'facebook-for-woocommerce' ),
						'toddler'  => __( 'Toddler', 'facebook-for-woocommerce' ),
					),
					'gender'    => array(
						'female' => __( 'Female', 'facebook-for-woocommerce' ),
						'male'   => __( 'Male', 'facebook-for-woocommerce' ),
						'unisex' => __( 'Unisex', 'facebook-for-woocommerce' ),
					),
					'condition' => array(
						'new'         => __( 'New', 'facebook-for-woocommerce' ),
						'refurbished' => __( 'Refurbished', 'facebook-for-woocommerce' ),
						'used'        => __( 'Used', 'facebook-for-woocommerce' ),
					),
				),
			);
		}

		// Fall back to our own definition if ProductAttributeMapper is not available
		return array(
			'facebook_attributes'       => array(
				// Basic catalog attributes
				'age_group' => __( 'Age Group', 'facebook-for-woocommerce' ),
				'brand'     => __( 'Brand', 'facebook-for-woocommerce' ),
				'gender'    => __( 'Gender', 'facebook-for-woocommerce' ),
				'color'     => __( 'Color', 'facebook-for-woocommerce' ),
				'size'      => __( 'Size', 'facebook-for-woocommerce' ),
				'material'  => __( 'Material', 'facebook-for-woocommerce' ),
				'pattern'   => __( 'Pattern', 'facebook-for-woocommerce' ),
				'condition' => __( 'Condition', 'facebook-for-woocommerce' ),

				// Product identifiers
				'mpn'       => __( 'MPN (Manufacturer Part Number)', 'facebook-for-woocommerce' ),
				'gtin'      => __( 'GTIN (Global Trade Item Number)', 'facebook-for-woocommerce' ),
			),
			'facebook_attribute_values' => array(
				'age_group' => array(
					'adult'    => __( 'Adult', 'facebook-for-woocommerce' ),
					'all ages' => __( 'All Ages', 'facebook-for-woocommerce' ),
					'kids'     => __( 'Kids', 'facebook-for-woocommerce' ),
					'teen'     => __( 'Teen', 'facebook-for-woocommerce' ),
					'infant'   => __( 'Infant', 'facebook-for-woocommerce' ),
					'newborn'  => __( 'Newborn', 'facebook-for-woocommerce' ),
					'toddler'  => __( 'Toddler', 'facebook-for-woocommerce' ),
				),
				'gender'    => array(
					'female' => __( 'Female', 'facebook-for-woocommerce' ),
					'male'   => __( 'Male', 'facebook-for-woocommerce' ),
					'unisex' => __( 'Unisex', 'facebook-for-woocommerce' ),
				),
				'condition' => array(
					'new'         => __( 'New', 'facebook-for-woocommerce' ),
					'refurbished' => __( 'Refurbished', 'facebook-for-woocommerce' ),
					'used'        => __( 'Used', 'facebook-for-woocommerce' ),
				),
			),
		);
	}

	/**
	 * Attempts to find the best Facebook attribute match for a WooCommerce attribute when no exact mapping exists
	 *
	 * @param string $attribute_name The WooCommerce attribute name to match
	 * @return string|false The matched Facebook attribute or false if no match found
	 */
	private function find_best_attribute_match( $attribute_name ) {

		// Clean up attribute name, remove pa_ prefix and standardize
		$clean_name = ProductAttributeMapper::sanitize_attribute_name( $attribute_name );

		// Common fuzzy matches by attribute category
		$fuzzy_matches = array(
			'material'  => array( 'mat', 'matl', 'fabric', 'textile', 'cloth' ),
			'color'     => array( 'col', 'clr', 'colour', 'couleur' ),
			'size'      => array( 'sz', 'dimension', 'dimensions' ),
			'pattern'   => array( 'pat', 'print', 'design' ),
			'gender'    => array( 'sex', 'gen', 'for' ),
			'age_group' => array( 'age', 'years', 'group' ),
			'condition' => array( 'cond', 'state', 'quality' ),
			'brand'     => array( 'make', 'manufacturer', 'producer', 'vendor' ),
		);

		// Check for fuzzy matches
		foreach ( $fuzzy_matches as $fb_attribute => $patterns ) {
			foreach ( $patterns as $pattern ) {
				// Check if the attribute name contains the pattern
				if ( stripos( $clean_name, $pattern ) !== false ) {
					return $fb_attribute;
				}
			}
		}

		// Check for common substrings that might indicate a match
		$common_substrings = array(
			'material'  => array( 'material', 'fabric' ),
			'color'     => array( 'color', 'colour' ),
			'size'      => array( 'size', 'dimension' ),
			'pattern'   => array( 'pattern', 'print', 'design' ),
			'gender'    => array( 'gender', 'sex' ),
			'age_group' => array( 'age' ),
			'condition' => array( 'condition', 'state' ),
			'brand'     => array( 'brand', 'make' ),
		);

		foreach ( $common_substrings as $fb_attribute => $substrings ) {
			foreach ( $substrings as $substring ) {
				// Check for substring in the attribute name (allowing partial words)
				if ( stripos( $clean_name, $substring ) !== false ) {
					return $fb_attribute;
				}
			}
		}

		return false;
	}
}
