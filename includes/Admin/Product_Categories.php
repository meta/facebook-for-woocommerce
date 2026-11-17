<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Admin;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Framework\Helper;

/**
 * General handler for the product category admin functionality.
 *
 * @since 2.1.0
 */
class Product_Categories {


	/** @var string ID for the HTML field */
	const FIELD_GOOGLE_PRODUCT_CATEGORY_ID = 'wc_facebook_google_product_category_id';

	/**
	 * Handler constructor.
	 *
	 * @since 2.1.0
	 */
	public function __construct() {

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'product_cat_add_form_fields', array( $this, 'render_add_google_product_category_field' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'render_edit_google_product_category_field' ) );
		add_action( 'product_cat_edit_form_fields', array( $this, 'render_edit_enhanced_catalog_attributes_field' ) );

		add_action( 'created_term', array( $this, 'save_google_product_category_and_enhanced_attributes' ), 10, 3 );
		add_action( 'edit_term', array( $this, 'save_google_product_category_and_enhanced_attributes' ), 10, 3 );

		add_action( 'wp_ajax_wc_facebook_enhanced_catalog_attributes', array( $this, 'ajax_render_enhanced_catalog_attributes_field' ) );
	}

	/**
	 * Enqueues the assets.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 */
	public function enqueue_assets() {

		if ( $this->is_categories_screen() ) {

			wp_enqueue_style( 'wc-facebook-product-categories', facebook_for_woocommerce()->get_plugin_url() . '/assets/css/admin/products-categories.css', array(), \WC_Facebookcommerce::PLUGIN_VERSION );

			wp_enqueue_script(
				'wc-facebook-product-categories',
				facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/product-categories.js',
				array(
					'jquery',
					'wc-backbone-modal',
					'jquery-blockui',
					'jquery-tiptip',
					'facebook-for-woocommerce-modal',
				),
				\WC_Facebookcommerce::PLUGIN_VERSION,
				true
			);

			wp_localize_script(
				'wc-facebook-product-categories',
				'facebook_for_woocommerce_product_categories',
				array(
					'ajax_url'                             => admin_url( 'admin-ajax.php' ),
					'enhanced_attribute_optional_selector' => Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY,
					'enhanced_attribute_page_type_edit_category' => Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_CATEGORY,
					'enhanced_attribute_page_type_add_category' => Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_ADD_CATEGORY,
					'enhanced_attribute_page_type_edit_product' => Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_PRODUCT,
					'default_google_product_category_modal_message' => $this->get_default_google_product_category_modal_message(),
					'default_google_product_category_modal_buttons' => $this->get_default_google_product_category_modal_buttons(),
				)
			);
		}//end if
	}


	/**
	 * Gets the message for Default Google Product Category modal.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	private function get_default_google_product_category_modal_message() {
		return wp_kses_post( __( 'Products and categories that inherit this global setting (i.e. they do not have a specific Google product category set) will use the new default immediately. Are you sure you want to proceed?', 'facebook-for-woocommerce' ) );
	}


	/**
	 * Gets the markup for the buttons used in the Default Google Product Category modal.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	private function get_default_google_product_category_modal_buttons() {
		ob_start();
		?>
		<button
				class="button button-large"
				onclick="jQuery( '.modal-close' ).trigger( 'click' )"
		><?php esc_html_e( 'Cancel', 'facebook-for-woocommerce' ); ?></button>
		<button
				id="btn-ok"
				class="button button-large button-primary"
		><?php esc_html_e( 'Update default Google product category', 'facebook-for-woocommerce' ); ?></button>
		<?php
		return ob_get_clean();
	}


	/**
	 * Renders the Google product category field markup for the add form.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 */
	public function render_add_google_product_category_field() {
		$category_field = new Google_Product_Category_Field();
		?>
			<div class="form-field term-<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>-wrap">
				<span><?php echo esc_html( self::get_catalog_explanation_text() ); ?></span>
				<br/>
				<br/>
				<?php Enhanced_Catalog_Attribute_Fields::render_hidden_input_can_show_attributes(); ?>
				<label for="<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>">
					<?php echo esc_html( $this->get_google_product_category_field_title() ); ?>
					<?php $this->render_google_product_category_tooltip(); ?>
				</label>
				<input type="hidden" id="<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>"
					name="<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>"/>
				<?php $category_field->render( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>
			</div>
		<?php
	}

	/**
	 * Returns the text that explains why certain fields should be inputed for ad performance
	 *
	 * @return string the explanation text
	 */
	public static function get_catalog_explanation_text() {
		return __( 'To optimize ad performance, we recommend providing these additional product attributes in WooCommerce. Updates made here will be overwritten with attributes provided in WooCommerce.', 'facebook-for-woocommerce' );
	}


	/**
	 * Renders the Google product category field markup for the edit form.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_Term $term current taxonomy term object.
	 */
	public function render_edit_google_product_category_field( \WP_Term $term ) {
		$category_field = new Google_Product_Category_Field();
		$value          = get_term_meta( $term->term_id, \WooCommerce\Facebook\Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, true );
		?>
			<tr class="form-field">
				<td colspan="2">
					<span><?php echo esc_html( self::get_catalog_explanation_text() ); ?></span>
				</td>
			</tr>
			<tr class="form-field term-<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>-wrap">
				<th scope="row">
					<label for="<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>">
						<?php echo esc_html( $this->get_google_product_category_field_title() ); ?>
						<?php $this->render_google_product_category_tooltip(); ?>
					</label>
				</th>
				<td>
					<?php Enhanced_Catalog_Attribute_Fields::render_hidden_input_can_show_attributes(); ?>
					<input type="hidden" id="<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>"
						name="<?php echo esc_attr( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>"
						value="<?php echo esc_attr( $value ); ?>"/>
					<?php $category_field->render( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ); ?>
				</td>
			</tr>
		<?php
	}

	/**
	 * Renders the common tooltip markup.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 */
	public function render_google_product_category_tooltip() {
		$tooltip_text = __( 'Choose a default Google product category for products in this category. Products need at least two category levels defined for tax to be correctly applied.', 'facebook-for-woocommerce' );
		?>
			<span class="woocommerce-help-tip" data-tip="<?php echo esc_attr( $tooltip_text ); ?>"></span>
		<?php
	}

	/**
	 * Gets the common field title.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function get_google_product_category_field_title() {
		return __( 'Default Google product category', 'facebook-for-woocommerce' );
	}

	/**
	 * Renders the enhanced catalog attributes  field markup for the edit form.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 */
	public function ajax_render_enhanced_catalog_attributes_field() {
		$category_id = wc_clean( Helper::get_requested_value( 'selected_category' ) );
		$page_type   = wc_clean( Helper::get_requested_value( 'page_type' ) );

		switch ( $page_type ) {
			case Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_CATEGORY:
				$tag_id   = intval( wc_clean( Helper::get_requested_value( 'tag_id' ) ) );
				$taxonomy = wc_clean( Helper::get_requested_value( 'taxonomy' ) );
				$term     = get_term( $tag_id, $taxonomy );
				$this->render_edit_enhanced_catalog_attributes_field( $term, $category_id );
				break;
			case Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_ADD_CATEGORY:
				$this->render_add_enhanced_catalog_attributes_field( $category_id );
				break;
			case Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_PRODUCT:
				$item_id = intval( wc_clean( Helper::get_requested_value( 'item_id' ) ) );
				$product = new \WC_Product( $item_id );
				Products::render_enhanced_catalog_attributes_fields( $category_id, $product );
				break;
		}
	}

	/**
	 * Renders the enhanced catalog attributes  field markup for the edit form.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @param \WP_Term $term current taxonomy term object.
	 * @param string   $category_id passed in category id
	 */
	public function render_edit_enhanced_catalog_attributes_field( \WP_Term $term, $category_id = null ) {
		if ( empty( $category_id ) ) {
			$category_id = get_term_meta( $term->term_id, \WooCommerce\Facebook\Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, true );
		}

		$enhanced_attribute_fields = new Enhanced_Catalog_Attribute_Fields( Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_CATEGORY, $term );
		$category_handler          = facebook_for_woocommerce()->get_facebook_category_handler();

		if (
			empty( $category_id ) ||
			( $category_handler->is_category( $category_id ) &&
			$category_handler->is_root_category( $category_id ) )
		) {
			// show nothing
			?>
				<tr class='form-field'>
				<td colspan="2">
				<span><?php echo esc_html( $category_id ); ?></span>
				</td>
				</tr>
			<?php
			return;
		}

		?>
			<tr class="form-field wc-facebook-enhanced-catalog-attribute-row term-<?php echo esc_attr( Enhanced_Catalog_attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTES_ID ); ?>-title-wrap">
				<th colspan="2" scope="row">
					<label for="<?php echo esc_attr( Enhanced_Catalog_attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTES_ID ); ?>">
						<?php echo esc_html( $this->render_enhanced_catalog_attributes_title() ); ?>
						<?php $this->render_enhanced_catalog_attributes_tooltip(); ?>
					</label>
				</th>
			</tr>
		<?php
		$enhanced_attribute_fields->render( $category_id );
	}


	/**
	 * Renders the enhanced catalog attributes field markup for the edit form.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $category_id the selected category to render attributes for.
	 */
	public function render_add_enhanced_catalog_attributes_field( $category_id ) {
		$enhanced_attribute_fields = new Enhanced_Catalog_Attribute_Fields( Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_ADD_CATEGORY );
		$category_handler          = facebook_for_woocommerce()->get_facebook_category_handler();

		if (
			empty( $category_id ) ||
			( $category_handler->is_category( $category_id ) &&
			$category_handler->is_root_category( $category_id ) )
		) {
			// show nothing
			return;
		}

		?>
			<div class="form-field wc-facebook-enhanced-catalog-attribute-row term-<?php echo esc_attr( Enhanced_Catalog_attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTES_ID ); ?>-title-wrap">
				<label for="<?php echo esc_attr( Enhanced_Catalog_attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTES_ID ); ?>">
					<?php echo esc_html( $this->render_enhanced_catalog_attributes_title() ); ?>
					<?php $this->render_enhanced_catalog_attributes_tooltip(); ?>
				</label>
			</div>
			<table class="form-table">
				<?php $enhanced_attribute_fields->render( $category_id ); ?>
			</table>
		<?php
	}

	/**
	 * Renders the common tooltip markup.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 */
	public function render_enhanced_catalog_attributes_tooltip() {
		$tooltip_text = __( 'Select default values for enhanced attributes within this category', 'facebook-for-woocommerce' );
		?>
			<span class="woocommerce-help-tip" data-tip="<?php echo esc_attr( $tooltip_text ); ?>"></span>
		<?php
	}

	/**
	 * Gets the common field title.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	public function render_enhanced_catalog_attributes_title() {
		return __( 'Category Specific Attributes', 'facebook-for-woocommerce' );
	}

	/**
	 * Saves the POSTed Google product category ID and triggers a sync for the affected products.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @param int    $term_id term ID.
	 * @param int    $tt_id term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function save_google_product_category_and_enhanced_attributes( $term_id, $tt_id, $taxonomy ) {

		$google_product_category_id = wc_clean( Helper::get_posted_value( self::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ) );

		\WooCommerce\Facebook\Product_Categories::update_google_product_category_id( $term_id, $google_product_category_id );
		$this->save_enhanced_catalog_attributes( $term_id );

		$term = get_term( $term_id, $taxonomy );

		if ( $term instanceof \WP_Term ) {

			// get the products in the category being saved
			$products = wc_get_products(
				array(
					'category' => array( $term->slug ),
					'limit'    => -1,
				)
			);

			if ( ! empty( $products ) ) {

				$sync_product_ids = array();

				/**
				 * The result is a list, so its keys are positions, not product IDs.
				 *
				 * @var \WC_Product $product
				 */
				foreach ( $products as $product ) {

					if ( $product instanceof \WC_Product_Variable ) {

						// should sync the variations, not the variable product
						$sync_product_ids = array_merge( $sync_product_ids, $product->get_children() );

					} else {

						$sync_product_ids[] = $product->get_id();
					}
				}

				facebook_for_woocommerce()->get_products_sync_handler()->create_or_update_products( $sync_product_ids );
			}//end if
		}//end if
	}

	/**
	 * Saves the POSTed enhanced catalog attributes and triggers a sync for the affected products.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @param int $term_id term ID.
	 */
	public function save_enhanced_catalog_attributes( $term_id ) {
		$enhanced_catalog_attributes = \WooCommerce\Facebook\Products::get_enhanced_catalog_attributes_from_request();

		foreach ( $enhanced_catalog_attributes as $key => $value ) {
			$meta_key = \WooCommerce\Facebook\Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . $key;
			update_term_meta( $term_id, $meta_key, $value );
		}

		if ( ! isset( $enhanced_catalog_attributes[ Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY ] ) ) {
			// This is a checkbox so won't show in the post data if it's been unchecked,
			// hence if it's unset we should clear the term meta for it.
			$meta_key = \WooCommerce\Facebook\Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY;
			update_term_meta( $term_id, $meta_key, null );
		}
	}


	/**
	 * Determines whether or not the current screen is a categories screen.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function is_categories_screen() {
		return Helper::is_current_screen( 'edit-product_cat' );
	}
}
