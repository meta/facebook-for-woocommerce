<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Admin\Settings_Screens;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Admin\Abstract_Settings_Screen;
use WooCommerce\Facebook\Admin\Google_Product_Category_Field;
use WooCommerce\Facebook\Commerce;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Integrations\WPML;

// Include the localization trait
require_once __DIR__ . '/Localization_Settings_Trait.php';

/**
 * The Product Sync settings screen object.
 */
class Product_Sync extends Abstract_Settings_Screen {

	use Localization_Settings_Trait;

	/** @var string screen ID */
	const ID = 'product_sync';

	/** @var string the sync products action */
	const ACTION_SYNC_PRODUCTS = 'wc_facebook_sync_products';

	/** @var string the get sync status action */
	const ACTION_GET_SYNC_STATUS = 'wc_facebook_get_sync_status';

	/**
	 * Connection constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'initHook' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_admin_field_product_sync_title', array( $this, 'render_title' ) );
		add_action( 'woocommerce_admin_field_product_sync_google_product_categories', array( $this, 'render_google_product_category_field' ) );
		add_action( 'woocommerce_admin_field_product_sync_catalog_display', array( $this, 'render_catalog_display' ) );
		// Only register this action once across all settings screens that use the trait
		if ( ! has_action( 'woocommerce_admin_field_localization_plugin_status' ) ) {
			add_action( 'woocommerce_admin_field_localization_plugin_status', array( $this, 'render_localization_plugin_status' ) );
		}
	}

	/**
	 * Initializes this settings page's properties.
	 */
	public function initHook(): void {
		$this->id                = self::ID;
		$this->label             = __( 'Product sync', 'facebook-for-woocommerce' );
		$this->title             = __( 'Product sync', 'facebook-for-woocommerce' );
		$this->documentation_url = 'https://woocommerce.com/document/facebook-for-woocommerce/#product-sync-settings';
	}

	/**
	 * Enqueues the assets.
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 */
	public function enqueue_assets() {
		if ( ! $this->is_current_screen_page() ) {
			return;
		}
		wp_enqueue_script( 'wc-backbone-modal', null, array( 'backbone' ), \WC_Facebookcommerce::PLUGIN_VERSION, true );
		wp_enqueue_script(
			'facebook-for-woocommerce-modal',
			facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/modal.js',
			array( 'jquery', 'wc-backbone-modal', 'jquery-blockui' ),
			\WC_Facebookcommerce::PLUGIN_VERSION,
			true
		);
		wp_enqueue_script(
			'facebook-for-woocommerce-settings-sync',
			facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/settings-sync.js',
			array( 'jquery', 'wc-backbone-modal', 'jquery-blockui', 'jquery-tiptip', 'facebook-for-woocommerce-modal', 'wc-enhanced-select' ),
			\WC_Facebookcommerce::PLUGIN_VERSION,
			true
		);

		/* translators: Placeholders: {count} number of remaining items */
		$sync_remaining_items_string = _n_noop( '{count} item remaining.', '{count} items remaining.', 'facebook-for-woocommerce' );

		wp_localize_script(
			'facebook-for-woocommerce-settings-sync',
			'facebook_for_woocommerce_settings_sync',
			array(
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'set_excluded_terms_prompt_nonce' => wp_create_nonce( 'set-excluded-terms-prompt' ),
				'sync_products_nonce'             => wp_create_nonce( self::ACTION_SYNC_PRODUCTS ),
				'sync_status_nonce'               => wp_create_nonce( self::ACTION_GET_SYNC_STATUS ),
				'sync_in_progress'                => Sync::is_sync_in_progress(),
				'excluded_category_ids'           => facebook_for_woocommerce()->get_integration()->get_excluded_product_category_ids(),
				'excluded_tag_ids'                => facebook_for_woocommerce()->get_integration()->get_excluded_product_tag_ids(),
				'i18n'                            => array(
					'confirm_resync'                => esc_html__( 'Your products will now be resynced to Facebook, this may take some time.', 'facebook-for-woocommerce' ),
					'confirm_sync'                  => esc_html__( "Meta for WooCommerce automatically syncs your products on create/update. Are you sure you want to force product resync?\n\nThis will query all published products and may take some time. You only need to do this if your products are out of sync or some of your products did not sync.", 'facebook-for-woocommerce' ),
					/* translators: Placeholders %s - html code for a spinner icon */
					'sync_in_progress'              => sprintf( esc_html__( 'Your products are syncing - you may safely leave this page %s', 'facebook-for-woocommerce' ), '<span class="spinner is-active"></span>' ),
					'sync_remaining_items_singular' => sprintf( esc_html( translate_nooped_plural( $sync_remaining_items_string, 1 ) ), '<strong>', '</strong>', '<span class="spinner is-active"></span>' ),
					'sync_remaining_items_plural'   => sprintf( esc_html( translate_nooped_plural( $sync_remaining_items_string, 2 ) ), '<strong>', '</strong>', '<span class="spinner is-active"></span>' ),
					'general_error'                 => esc_html__( 'There was an error trying to sync the products to Facebook.', 'facebook-for-woocommerce' ),
					'feed_upload_error'             => esc_html__( 'Something went wrong while uploading the product information, please try again.', 'facebook-for-woocommerce' ),
				),
				'default_google_product_category_modal_message' => $this->get_default_google_product_category_modal_message(),
				'default_google_product_category_modal_message_empty' => $this->get_default_google_product_category_modal_message_empty(),
				'default_google_product_category_modal_buttons' => $this->get_default_google_product_category_modal_buttons(),
			)
		);
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
	 * Gets the message for Default Google Product Category modal when the selection is empty.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	private function get_default_google_product_category_modal_message_empty() {
		return sprintf(
			/* translators: Placeholders: %1$s - <strong> tag, %2$s - </strong> tag */
			esc_html__( 'Products and categories that inherit this global setting (they do not have a specific Google product category set) will use the new default immediately.  %1$sIf you have cleared the Google Product Category%2$s, items inheriting the default will not be available for Instagram checkout. Are you sure you want to proceed?', 'facebook-for-woocommerce' ),
			'<strong>',
			'</strong>'
		);
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
	 * Renders the custom title.
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 *
	 * @param array $field field data
	 */
	public function render_title( $field ) {
		?>
		<h2>

			<?php esc_html_e( 'Product sync', 'facebook-for-woocommerce' ); ?>

			<?php if ( facebook_for_woocommerce()->get_connection_handler()->is_connected() ) : ?>
				<a
					id="woocommerce-facebook-settings-sync-products"
					class="button product-sync-field"
					href="#"
					style="vertical-align: middle; margin-left: 20px;"
				><?php esc_html_e( 'Sync products', 'facebook-for-woocommerce' ); ?></a>
			<?php endif; ?>

		</h2>
		<div><p id="sync_progress" style="display: none"></p></div>
		<table class="form-table">

		<?php
	}


	/**
	 * Saves the Product Sync settings.
	 *
	 * @since 2.0.0
	 */
	public function save() {
		$integration              = facebook_for_woocommerce()->get_integration();
		$previous_product_cat_ids = $integration->get_excluded_product_category_ids();
		$previous_product_tag_ids = $integration->get_excluded_product_tag_ids();
		parent::save();
		// when settings are saved, if there are new excluded categories/terms we should exclude corresponding products from sync
		$new_product_cat_ids = array_diff( $integration->get_excluded_product_category_ids(), $previous_product_cat_ids );
		$new_product_tag_ids = array_diff( $integration->get_excluded_product_tag_ids(), $previous_product_tag_ids );
		$this->disable_sync_for_excluded_products( $new_product_cat_ids, $new_product_tag_ids );
	}


	/**
	 * Disables sync for products that belong to any of the given categories or tags.
	 *
	 * @since 2.0.0
	 *
	 * @param array $product_cat_ids IDs of excluded categories
	 * @param array $product_tag_ids IDs of excluded tags
	 */
	private function disable_sync_for_excluded_products( $product_cat_ids, $product_tag_ids ) {
		// disable sync for all products belonging to excluded categories
		Products::disable_sync_for_products_with_terms(
			array(
				'taxonomy' => 'product_cat',
				'include'  => $product_cat_ids,
			)
		);
		// disable sync for all products belonging to excluded tags
		Products::disable_sync_for_products_with_terms(
			array(
				'taxonomy' => 'product_tag',
				'include'  => $product_tag_ids,
			)
		);
	}


	/**
	 * Gets the id => name options for the exclusion multiselect of a taxonomy.
	 *
	 * The list has to contain every term in every language, plus every term that is already
	 * saved as excluded. Polylang and WPML narrow term queries to the admin's current
	 * language, so a saved exclusion for a term in another language, or in none, was not
	 * rendered and the multiselect silently dropped it on the next save. An empty 'lang'
	 * is Polylang's documented way to lift its filter and core ignores the argument; the
	 * caller runs this with WPML on 'all' languages. Saved IDs the query still misses are
	 * added through get_term(), which looks a term up by ID. That lookup has to run under
	 * 'all' too: with its "adjust IDs" option WPML swaps a term for its translation in the
	 * current language in AJAX and front-end requests.
	 *
	 * @since 3.7.9
	 *
	 * @param string $taxonomy  product_cat or product_tag
	 * @param array  $saved_ids term IDs saved as excluded, read from the raw option: the
	 *                          integration getters return the IDs in effect, which is empty
	 *                          under the all-products rollout switch, while WooCommerce marks
	 *                          the multiselect's selection from the raw option
	 * @return array term ID => term name
	 */
	private function get_term_options( string $taxonomy, array $saved_ids ): array {
		$terms   = get_terms(
			array(
				'taxonomy'     => $taxonomy,
				'hide_empty'   => false,
				'hierarchical' => false,
				'fields'       => 'id=>name',
				'lang'         => '',
			)
		);
		$options = is_array( $terms ) ? $terms : array();

		foreach ( $saved_ids as $saved_id ) {
			$saved_id = (int) $saved_id;
			if ( $saved_id > 0 && ! isset( $options[ $saved_id ] ) ) {
				$term = get_term( $saved_id, $taxonomy );
				if ( $term instanceof \WP_Term ) {
					$options[ $term->term_id ] = $term->name;
				}
			}
		}

		return $options;
	}


	/**
	 * Gets the id => name options for both exclusion multiselects, keyed by taxonomy.
	 *
	 * Both lists, including the by-ID lookups of saved exclusions, are built inside one
	 * WPML switch to 'all' languages; see get_term_options() for why that is needed.
	 *
	 * @since 3.7.9
	 *
	 * @return array taxonomy => ( term ID => term name )
	 */
	private function get_exclusion_options(): array {
		return WPML::run_in_language(
			'all',
			function () {
				return array(
					'product_cat' => $this->get_term_options( 'product_cat', (array) get_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, array() ) ),
					'product_tag' => $this->get_term_options( 'product_tag', (array) get_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_TAG_IDS, array() ) ),
				);
			}
		);
	}


	/**
	 * Gets the screen settings.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$exclusion_options = $this->get_exclusion_options();
		$settings          = array(
			array(
				'type'  => 'product_sync_title',
				'title' => __( 'Product sync', 'facebook-for-woocommerce' ),
			),
			array(
				'id'       => \WC_Facebookcommerce_Integration::SETTING_ENABLE_PRODUCT_SYNC,
				'title'    => __( 'Enable product sync', 'facebook-for-woocommerce' ),
				'type'     => 'checkbox',
				'label'    => ' ',
				'default'  => 'yes',
				'desc_tip' => __( 'Enable product syncing with Facebook.', 'facebook-for-woocommerce' ),
			),

			array(
				'id'                => \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS,
				'title'             => __( 'Exclude categories from sync', 'facebook-for-woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select product-sync-field',
				'css'               => 'min-width: 300px;',
				'desc_tip'          => __( 'Products in any of these categories will not sync to Facebook.', 'facebook-for-woocommerce' ),
				'default'           => array(),
				'options'           => $exclusion_options['product_cat'],
				'custom_attributes' => array(
					'data-placeholder' => __( 'Search for a product category&hellip;', 'facebook-for-woocommerce' ),
				),
			),

			array(
				'id'                => \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_TAG_IDS,
				'title'             => __( 'Exclude tags from sync', 'facebook-for-woocommerce' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select product-sync-field',
				'css'               => 'min-width: 300px;',
				'desc_tip'          => __( 'Products with any of these tags will not sync to Facebook.', 'facebook-for-woocommerce' ),
				'default'           => array(),
				'options'           => $exclusion_options['product_tag'],
				'custom_attributes' => array(
					'data-placeholder' => __( 'Search for a product tag&hellip;', 'facebook-for-woocommerce' ),
				),
			),

			array(
				'id'       => Commerce::OPTION_GOOGLE_PRODUCT_CATEGORY_ID,
				'type'     => 'product_sync_google_product_categories',
				'title'    => __( 'Default Google product category', 'facebook-for-woocommerce' ),
				'desc_tip' => __( 'Choose a default Google product category for your products. Defaults can also be set for product categories. Products need at least two category levels defined for tax to be correctly applied.', 'facebook-for-woocommerce' ),
			),
			array(
				'type'  => 'product_sync_catalog_display',
				'title' => __( 'Catalog', 'facebook-for-woocommerce' ),
			),
			array( 'type' => 'sectionend' ),

		);

		// Append localization settings
		$localization_settings = $this->get_localization_settings();
		return array_merge( $settings, $localization_settings );
	}

	/**
	 * Renders the Google category field markup.
	 *
	 * @internal

	 * @since 2.1.0
	 *
	 * @param array $field field data
	 */
	public function render_google_product_category_field( $field ) {
		$category_field = new Google_Product_Category_Field();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?>
					<span class="woocommerce-help-tip" data-tip="<?php echo esc_attr( $field['desc_tip'] ); ?>"></span>
				</label>
			</th>
			<td class="forminp forminp-<?php echo esc_attr( sanitize_title( $field['type'] ) ); ?>">
				<?php $category_field->render( $field['id'] ); ?>
				<input id="<?php echo esc_attr( $field['id'] ); ?>" type="hidden" name="<?php echo esc_attr( $field['id'] ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Renders the Catalog Display field markup.
	 *
	 * @internal
	 *
	 * @since 3.5.4
	 *
	 * @param array $field field data
	 */
	public function render_catalog_display( $field ) {
		$catalog_item = $this->get_catalog_item_data();

		// Only display if catalog ID exists
		if ( empty( $catalog_item ) ) {
			return;
		}

		$this->render_catalog_row( $catalog_item );
	}

	/**
	 * Gets the catalog item data with API call and fallbacks.
	 *
	 * @return array|null Catalog item data or null if no catalog ID exists
	 */
	private function get_catalog_item_data() {
		$integration = facebook_for_woocommerce()->get_integration();
		$catalog_id  = $integration->get_product_catalog_id();

		// Return null if no catalog ID exists
		if ( empty( $catalog_id ) ) {
			return null;
		}

		// Build catalog item similar to Connection screen
		$catalog_item = array(
			'label' => __( 'Catalog', 'facebook-for-woocommerce' ),
			'value' => $catalog_id,
			'url'   => "https://www.facebook.com/commerce/catalogs/{$catalog_id}/products/",
		);

		// Try to get the catalog name for display
		try {
			$response = facebook_for_woocommerce()->get_api()->get_catalog( $catalog_id );
			$name     = $response->name ?? '';
			if ( $name ) {
				$catalog_item['value'] = $name;
			} else {
				// API succeeded but returned empty name - use store name fallback
				$catalog_item['value'] = $this->get_catalog_fallback_name();
			}
		} catch ( ApiException $exception ) {
			// Log the exception with additional information
			$message = sprintf( 'Meta APIs thrown APIException while fetching the Catalog details for catalog %s: %s', $catalog_id, $exception->getMessage() );
			Logger::log(
				$message,
				[],
				array(
					'should_send_log_to_meta'        => false,
					'should_save_log_in_woocommerce' => true,
					'woocommerce_log_level'          => \WC_Log_Levels::ERROR,
				)
			);

			// Use store name as fallback
			$catalog_item['value'] = $this->get_catalog_fallback_name();
		}

		return $catalog_item;
	}

	/**
	 * Gets the fallback catalog name using store name.
	 *
	 * @return string Fallback catalog name
	 */
	private function get_catalog_fallback_name() {
		$store_name = get_bloginfo( 'name' );
		if ( ! empty( $store_name ) ) {
			return sprintf( '%s Catalog', $store_name );
		}
		return __( 'Facebook Catalog', 'facebook-for-woocommerce' );
	}

	/**
	 * Renders the catalog row HTML.
	 *
	 * @param array $catalog_item Catalog item data
	 */
	private function render_catalog_row( $catalog_item ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html( $catalog_item['label'] ); ?>
			</th>
			<td class="forminp">
				<a href="<?php echo esc_url( $catalog_item['url'] ); ?>" target="_blank">
					<?php echo esc_html( $catalog_item['value'] ); ?>
					<span class="dashicons dashicons-external" style="margin-left: 5px; vertical-align: middle; text-decoration: none;"></span>
				</a>
			</td>
		</tr>
		<?php
	}

	/**
	 * Gets the "disconnected" message.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_disconnected_message() {
		return sprintf(
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
			__( 'Please %1$sconnect to Facebook%2$s to enable and manage product sync.', 'facebook-for-woocommerce' ),
			'<a href="' . esc_url( facebook_for_woocommerce()->get_settings_url() ) . '">',
			'</a>'
		);
	}
}
