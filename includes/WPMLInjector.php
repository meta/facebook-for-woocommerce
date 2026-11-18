<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 *
 * phpcs:disable Squiz.Commenting.VariableComment.Missing
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
 */

namespace WooCommerce\Facebook;

defined( 'ABSPATH' ) || exit;

/**
 * WPML Injector class for Facebook integration.
 */
class WPMLInjector {

	public static $settings     = null;
	public static $default_lang = null;
	const OPTION                = 'fb_wmpl_language_visibility';


	/**
	 * Constructor for Facebook_WPML_Injector class.
	 */
	public function __construct() {

		self::$settings     = get_option( self::OPTION );
		self::$default_lang = apply_filters( 'wpml_default_language', null );

		if ( is_admin() ) {
			add_action( 'icl_menu_footer', array( $this, 'wpml_support' ) );
			add_action( 'icl_ajx_custom_call', array( $this, 'wpml_ajax_support' ), 10, 2 );
		}
	}

	/**
	 * Whether a product should be hidden from the Facebook catalog.
	 *
	 * @since 1.9.10
	 *
	 * @param int $product_id The product ID.
	 *
	 * @return bool
	 */
	public static function should_hide( $product_id ) {
		/**
		 * Apply WPML filters to the product ID.
		 *
		 * The wpml_post_language_details filter is applied to obtain the language data for the product.
		 *
		 * Possible values for $product_lang after the filter is applied:
		 *
		 * 1. null - The WPML filter wasn't applied.
		 * 2. array - WPML returned the language data from wpml_get_language_information().
		 * 3. WP_Error - WPML didn't recognize the post ID.
		 */
		$product_lang = apply_filters( 'wpml_post_language_details', null, $product_id );

		if ( ! is_array( $product_lang ) ) {
			/**
			 * Whether to hide the product from Facebook.
			 *
			 * @since 3.0.32
			 *
			 * @param bool  $should_hide  Whether to hide the product from Facebook.
			 * @param int   $product_id   The product ID.
			 * @param mixed $product_lang The result of applying the WPML filters.
			 */
			return (bool) apply_filters( 'wc_facebook_wpml_should_hide_product', true, $product_id, $product_lang );
		}

		// Pull out the language code from the language details.
		$language_code = $product_lang['language_code'] ?? '';

		/*
		 * Polylang answers the WPML filters through its compatibility layer and defines
		 * ICL_LANGUAGE_CODE, so this check runs on Polylang stores too. It reports a product
		 * without a language as an empty array. That read as "not the default language" below,
		 * and the product reached the catalog hidden. A product in no language is not a
		 * translation of anything, so it stays visible. WPML's own answers are left as they are.
		 */
		if ( '' === (string) $language_code && ! static::is_wpml_running() ) {
			/** This filter is documented above. */
			return (bool) apply_filters( 'wc_facebook_wpml_should_hide_product', false, $product_id, $product_lang );
		}

		// Option doesn't exist: Backwards Compatibility
		if ( ! self::$settings ) {
			return (bool) apply_filters( 'wc_facebook_wpml_should_hide_product', self::$default_lang !== $language_code, $product_id, $product_lang );
		}

		// Hide products from non-active languages, of if the language status isn't visible.
		$should_hide = ! isset( self::$settings[ $language_code ] ) || WPMLLanguageStatus::VISIBLE !== self::$settings[ $language_code ];

		return (bool) apply_filters( 'wc_facebook_wpml_should_hide_product', $should_hide, $product_id, $product_lang );
	}

	/**
	 * Whether WPML itself is running, as opposed to another plugin answering its filters.
	 *
	 * @since 3.7.9
	 *
	 * @return bool
	 */
	protected static function is_wpml_running(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' );
	}

	public function wpml_ajax_support( $call, $REQUEST ) {
		/** @var SitePress $sitepress */
		global $sitepress;
		if ( isset( $REQUEST['icl_ajx_action'] ) ) {
			$call = $REQUEST['icl_ajx_action'];
		}
		if ( 'icl_fb_woo' === $call ) {
			$active_languages = array_keys( $sitepress->get_active_languages() );
			$settings         = array();
			foreach ( $active_languages as $lang ) {
				$settings[ $lang ] = 'on' === $REQUEST[ $lang ] ?
				WPMLLanguageStatus::VISIBLE : WPMLLanguageStatus::HIDDEN;
			}

			update_option( 'fb_wmpl_language_visibility', $settings, false );
			self::$settings = $settings;
		}
	}


	/**
	 * Prints the content for Facebook Visibility section.
	 *
	 * The section is shown at the bottom of the WPML > Languages settings page.
	 */
	public function wpml_support() {
		/** @var SitePress $sitepress */
		global $sitepress;

		// there is no nonce to check here and the value of $_GET['page] is being compared against a known and safe string
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'] ) && false !== strpos( esc_url_raw( wp_unslash( $_GET['page'] ) ), 'languages.php' ) ) {

			/** @var array $active_languages */
			$active_languages = $sitepress->get_active_languages();
			$settings         = get_option( self::OPTION );

			// Default setting is only show default lang.
			if ( ! $settings ) {

				$settings = array_fill_keys(
					array_keys( $active_languages ),
					WPMLLanguageStatus::HIDDEN
				);

				if ( self::$default_lang ) {
					$settings[ self::$default_lang ] = WPMLLanguageStatus::VISIBLE;
				}
			}

			// Count how many languages are currently set to VISIBLE
			$visible_languages_count = count(
				array_filter(
					$settings,
					function ( $status ) {
						return WPMLLanguageStatus::VISIBLE === $status;
					}
				)
			);

			// Check if user has legacy multi-language setup (2+ languages visible)
			$has_legacy_setup = $visible_languages_count >= 2;

			// Don't show the section at all for new/eligible users
			if ( ! $has_legacy_setup ) {
				return;
			}

			?>
			<div id="lang-sec-fb" class="wpml-section wpml-section-languages">
				<div class="wpml-section-header">
					<h3><?php esc_html_e( 'Facebook Visibility', 'facebook-for-woocommerce' ); ?></h3>
				</div>
				<div class="wpml-section-content">
					<?php esc_html_e( 'WooCommerce Products with languages that are selected here will be visible to customers who see your Facebook Shop.', 'facebook-for-woocommerce' ); ?>

					<div class="wpml-section-content-inner">
						<form id="icl_fb_woo" name="icl_fb_woo" action="">

							<?php foreach ( $settings as $language => $set ) : ?>

								<p>
									<label>
										<input type="checkbox" id="icl_fb_woo_chk" name="<?php echo esc_attr( $language ); ?>" <?php checked( $set, WPMLLanguageStatus::VISIBLE ); ?>>
										<?php echo isset( $active_languages[ $language ]['native_name'] ) ? esc_html( $active_languages[ $language ]['native_name'] ) : esc_html( $language ); ?>
									</label>
								</p>

							<?php endforeach; ?>

							<?php // TODO: restore success message removed in e7f290f when FBE 2.0 changes are available {WV 2020-04-27} ?>
							<p class="icl_ajx_response_fb" id="icl_ajx_response_fb" hidden="true"><?php esc_html_e( "Saved. An automated sync from Facebook will run every hour to update the catalog with any changes you've made.", 'facebook-for-woocommerce' ); ?></p>

							<p class="buttons-wrap">
								<input
									class="button button-primary"
									name="save"
									value="<?php esc_attr_e( 'Save', 'facebook-for-woocommerce' ); ?>"
									type="submit"
								/>
							</p>
						</form>
						<script type="text/javascript">
							addLoadEvent( function() {
								jQuery( '#icl_fb_woo' ).submit( iclSaveForm );
								jQuery( '#icl_fb_woo' ).submit( function() {
									jQuery( '#icl_ajx_response_fb' ).show();
								} );
							} );
						</script>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
