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

use WooCommerce\Facebook\Admin\Enhanced_Catalog_Attribute_Fields;
use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\ProductAttributeMapper;
use WooCommerce\Facebook\RolloutSwitches;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Admin handler.
 *
 * @since 1.10.0
 */
class Admin {

	/** @var string the "sync and show" sync mode slug */
	const SYNC_MODE_SYNC_AND_SHOW = 'sync_and_show';

	/** @var string the "sync and show" sync mode slug */
	const SYNC_MODE_SYNC_AND_HIDE = 'sync_and_hide';

	/** @var string the "sync disabled" sync mode slug */
	const SYNC_MODE_SYNC_DISABLED = 'sync_disabled';

	/** @var string the "fb_sync_enabled" sync mode slug */
	const INCLUDE_FACEBOOK_SYNC = 'fb_sync_enabled';

	/** @var string the "fb_sync_disabled" sync mode slug */
	const EXCLUDE_FACEBOOK_SYNC = 'fb_sync_disabled';

	/** @var string the "include" sync mode for bulk edit */
	const BULK_EDIT_SYNC = 'bulk_edit_sync';

	/** @var string the "exclude" sync mode for bulk edit */
	const BULK_EDIT_DELETE = 'bulk_edit_delete';

	/** @var string message ID for dismissing the WordPress.com update notice */
	const ADMIN_DISMISS_WPCOM_UPDATE_NOTICE = 'dismiss_wpcom_update_notice';

	/** @var Product_Categories the product category admin handler */
	protected $product_categories;

	/** @var array screens ids where to include scripts */
	protected $screen_ids = [];

	/**
	 * Admin constructor.
	 *
	 * @since 1.10.0
	 */
	public function __construct() {

		$order_screen_id = class_exists( OrderUtil::class ) ? OrderUtil::get_order_admin_screen() : 'shop_order';

		$this->screen_ids = [
			'product',
			'edit-product',
			'woocommerce_page_wc-facebook',
			'marketing_page_wc-facebook',
			'edit-product_cat',
			$order_screen_id,
		];

		// enqueue admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// handle dismiss action for WordPress.com update notice (runs before admin_notices)
		add_action( 'admin_init', array( $this, 'handle_wpcom_update_notice_dismiss' ) );

		// add admin notice about WordPress.com automatic updates ending (always check, regardless of connection status)
		// Note: check is deferred to admin_notices time so the dismiss handler (admin_init) runs first
		add_action( 'admin_notices', array( $this, 'maybe_show_wpcom_update_notice' ) );

		$plugin = facebook_for_woocommerce();
		// only alter the admin UI if the plugin is connected to Facebook and ready to sync products
		if ( ! $plugin->get_connection_handler()->is_connected() || ! $plugin->get_integration()->get_product_catalog_id() ) {
			return;
		}

		$this->product_categories = new Admin\Product_Categories();

		// add a modal in admin product pages
		add_action( 'admin_footer', array( $this, 'render_modal_template' ) );
		add_action( 'admin_footer', array( $this, 'add_tab_switch_script' ) );

		// add admin notice to inform that disabled products may need to be deleted manually
		add_action( 'admin_notices', array( $this, 'maybe_show_product_disabled_sync_notice' ) );

		// add admin notice if the user is enabling sync for virtual products using the bulk action
		add_action( 'admin_notices', array( $this, 'maybe_add_enabling_virtual_products_sync_notice' ) );
		add_filter( 'request', array( $this, 'filter_virtual_products_affected_enabling_sync' ) );

		// add admin notice to inform sync mode has been automatically set to Sync and hide for virtual products and variations
		add_action( 'admin_notices', array( $this, 'add_handled_virtual_products_variations_notice' ) );

		// add columns for displaying Facebook sync enabled/disabled and catalog visibility status
		add_filter( 'manage_product_posts_columns', array( $this, 'add_product_list_table_columns' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'add_product_list_table_columns_content' ) );

		// add input to filter products by Facebook sync enabled
		add_action( 'restrict_manage_posts', array( $this, 'add_products_by_sync_enabled_input_filter' ), 40 );
		add_filter( 'request', array( $this, 'filter_products_by_sync_enabled' ) );

		// add bulk actions to manage products sync
		add_action( 'woocommerce_product_bulk_edit_end', array( $this, 'add_facebook_sync_bulk_edit_dropdown_at_bottom' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'handle_products_sync_bulk_actions' ), 10, 1 );

		// add Product data tab
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_settings_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'add_product_settings_tab_content' ) );

		// add Variation edit fields
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'add_product_variation_edit_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_product_variation_edit_fields' ), 10, 2 );
		add_action( 'wp_ajax_get_facebook_product_data', array( $this, 'ajax_get_facebook_product_data' ) );

		add_action( 'wp_ajax_sync_facebook_attributes', array( $this, 'ajax_sync_facebook_attributes' ) );
	}

	/**
	 * Enqueues admin scripts.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function enqueue_scripts() {
		global $current_screen;

		if ( isset( $current_screen->id ) ) {

			if ( in_array( $current_screen->id, $this->screen_ids, true ) || facebook_for_woocommerce()->is_plugin_settings() ) {

				// enqueue modal functions
				wp_enqueue_script(
					'facebook-for-woocommerce-modal',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/modal.js',
					array( 'jquery', 'wc-backbone-modal', 'jquery-blockui' ),
					\WC_Facebookcommerce::PLUGIN_VERSION,
					false
				);

				// enqueue google product category select
				wp_enqueue_script(
					'wc-facebook-google-product-category-fields',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/google-product-category-fields.js',
					array( 'jquery' ),
					\WC_Facebookcommerce::PLUGIN_VERSION,
					false
				);

				wp_localize_script(
					'wc-facebook-google-product-category-fields',
					'facebook_for_woocommerce_google_product_category',
					array(
						'i18n' => array(
							'top_level_dropdown_placeholder' => __( 'Search main categories...', 'facebook-for-woocommerce' ),
							'second_level_empty_dropdown_placeholder' => __( 'Choose a main category first', 'facebook-for-woocommerce' ),
							'general_dropdown_placeholder' => __( 'Choose a category', 'facebook-for-woocommerce' ),
						),
					)
				);
			}

			if ( 'product' === $current_screen->id || 'edit-product' === $current_screen->id ) {
				wp_enqueue_style(
					'facebook-for-woocommerce-products-admin',
					facebook_for_woocommerce()->get_plugin_url() . '/assets/css/admin/facebook-for-woocommerce-products-admin.css',
					[],
					\WC_Facebookcommerce::PLUGIN_VERSION
				);
				wp_enqueue_script(
					'facebook-for-woocommerce-products-admin',
					facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/products-admin.js',
					[ 'jquery', 'wc-backbone-modal', 'jquery-blockui', 'facebook-for-woocommerce-modal' ],
					\WC_Facebookcommerce::PLUGIN_VERSION,
					false
				);
				wp_localize_script(
					'facebook-for-woocommerce-products-admin',
					'facebook_for_woocommerce_products_admin',
					[
						'ajax_url'                        => admin_url( 'admin-ajax.php' ),
						'enhanced_attribute_optional_selector' => Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY,
						'enhanced_attribute_page_type_edit_category' => Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_CATEGORY,
						'enhanced_attribute_page_type_add_category' => Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_ADD_CATEGORY,
						'enhanced_attribute_page_type_edit_product' => Enhanced_Catalog_Attribute_Fields::PAGE_TYPE_EDIT_PRODUCT,
						'is_product_published'            => $this->is_current_product_published(),
						'is_sync_enabled_for_product'     => $this->is_sync_enabled_for_current_product(),
						'set_product_visibility_nonce'    => wp_create_nonce( 'set-products-visibility' ),
						'set_product_sync_prompt_nonce'   => wp_create_nonce( 'set-product-sync-prompt' ),
						'product_not_ready_modal_message' => $this->get_product_not_ready_modal_message(),
						'product_not_ready_modal_buttons' => $this->get_product_not_ready_modal_buttons(),
						'product_removed_from_sync_field_id' => '#' . \WC_Facebook_Product::FB_REMOVE_FROM_SYNC,
						'i18n'                            => [
							'missing_google_product_category_message' => __( 'Please enter a Google product category and at least one sub-category to sell this product on Instagram.', 'facebook-for-woocommerce' ),
						],
					]
				);
			}//end if

			if ( facebook_for_woocommerce()->is_plugin_settings() ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
				wp_enqueue_script( 'wc-enhanced-select' );
			}
		}//end if
	}

	/**
	 * Determines whether sync is enabled for the current product.
	 *
	 * @since 2.0.5
	 *
	 * @return bool
	 */
	private function is_sync_enabled_for_current_product() {
		global $post;
		$product = wc_get_product( $post );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		return Products::is_sync_enabled_for_product( $product );
	}

	/**
	 * Determines whether the current product is published.
	 *
	 * @since 2.6.15
	 *
	 * @return bool
	 */
	private function is_current_product_published() {
		global $post;
		$product = wc_get_product( $post );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		return 'publish' === $product->get_status();
	}

	/**
	 * Gets the markup for the message used in the product not ready modal.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	private function get_product_not_ready_modal_message() {
		ob_start();
		?>
		<p><?php esc_html_e( 'To sell this product on Instagram, please ensure it meets the following requirements:', 'facebook-for-woocommerce' ); ?></p>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Has a price defined', 'facebook-for-woocommerce' ); ?></li>
			<li>
			<?php
			echo esc_html(
				sprintf(
				/* translators: Placeholders: %1$s - <strong> opening HTML tag, %2$s - </strong> closing HTML tag */
					__( 'Has %1$sManage Stock%2$s enabled on the %1$sInventory%2$s tab', 'facebook-for-woocommerce' ),
					'<strong>',
					'</strong>'
				)
			);
			?>
			</li>
			<li>
			<?php
			echo esc_html(
				sprintf(
				/* translators: Placeholders: %1$s - <strong> opening HTML tag, %2$s - </strong> closing HTML tag */
					__( 'Has the %1$sFacebook Sync%2$s setting set to "Sync and show" or "Sync and hide"', 'facebook-for-woocommerce' ),
					'<strong>',
					'</strong>'
				)
			);
			?>
			</li>
		</ul>
		<?php
		return ob_get_clean();
	}

	/**
	 * Gets the markup for the buttons used in the product not ready modal.
	 *
	 * @since 2.1.0
	 *
	 * @return string
	 */
	private function get_product_not_ready_modal_buttons() {
		ob_start();
		?>
		<button
			id="btn-ok"
			class="button button-large button-primary"
		><?php esc_html_e( 'Close', 'facebook-for-woocommerce' ); ?></button>
		<?php
		return ob_get_clean();
	}

	/**
	 * Gets the product category admin handler instance.
	 *
	 * @since 2.1.0
	 *
	 * @return Product_Categories
	 */
	public function get_product_categories_handler() {
		return $this->product_categories;
	}

	/**
	 * Adds Facebook-related columns in the products edit screen.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param array $columns array of keys and labels
	 * @return array
	 */
	public function add_product_list_table_columns( $columns ) {
		$columns['facebook_sync'] = __( 'Synced to Meta catalog', 'facebook-for-woocommerce' );
		return $columns;
	}

	/**
	 * Outputs sync information for products in the edit screen.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param string $column the current column in the posts table
	 */
	public function add_product_list_table_columns_content( $column ) {
		global $post;

		if ( 'facebook_sync' !== $column ) {
			return;
		}

		$product        = wc_get_product( $post );
		$should_sync    = false;
		$no_sync_reason = '';

		if ( $product instanceof \WC_Product ) {
			try {
				facebook_for_woocommerce()->get_product_sync_validator( $product )->validate();
				$should_sync = true;
			} catch ( \Exception $e ) {
				$no_sync_reason = $e->getMessage();
			}
		}

		if ( $should_sync ) {
			esc_html_e( 'Synced', 'facebook-for-woocommerce' );
		} else {
			esc_html_e( 'Not synced', 'facebook-for-woocommerce' );
			if ( ! empty( $no_sync_reason ) ) {
				echo wp_kses_post( wc_help_tip( $no_sync_reason ) );
			}
		}
	}

	/**
	 * Adds a dropdown input to let shop managers filter products by sync setting.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function add_products_by_sync_enabled_input_filter() {
		global $typenow;

		if ( 'product' !== $typenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$choice = isset( $_GET['fb_sync_enabled'] ) ? (string) sanitize_text_field( wp_unslash( $_GET['fb_sync_enabled'] ) ) : '';
		?>
		<select name="fb_sync_enabled">
			<option value="" <?php selected( $choice, '' ); ?>><?php esc_html_e( 'Filter by synced to Meta', 'facebook-for-woocommerce' ); ?></option>
			<option value="<?php echo esc_attr( self::INCLUDE_FACEBOOK_SYNC ); ?>" <?php selected( $choice, self::INCLUDE_FACEBOOK_SYNC ); ?>><?php esc_html_e( 'Synced', 'facebook-for-woocommerce' ); ?></option>
			<option value="<?php echo esc_attr( self::EXCLUDE_FACEBOOK_SYNC ); ?>" <?php selected( $choice, self::EXCLUDE_FACEBOOK_SYNC ); ?>><?php esc_html_e( 'Not synced', 'facebook-for-woocommerce' ); ?></option>
		</select>
		<?php
	}


	/**
	 * Adds a dropdown input to Include or Exclude product in Facebook Bulk Sync.
	 *
	 * @internal
	 */
	public function add_facebook_sync_bulk_edit_dropdown_at_bottom() {
		global $typenow;

		if ( 'product' !== $typenow ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$choice = isset( $_GET['facebook_bulk_sync_options'] ) ? (string) sanitize_text_field( wp_unslash( $_GET['facebook_bulk_sync_options'] ) ) : '';

		?>
		<label>
			<span class="title"><?php esc_html_e( 'Sync to Meta catalog', 'facebook-for-woocommerce' ); ?></span>
			<span class="input-text-wrap">
				<select class="facebook_bulk_sync_options" name="facebook_bulk_sync_options">
				<option value=""> <?php esc_html_e( '— No Change —', 'facebook-for-woocommerce' ); ?></option>;
				<option value="<?php echo esc_attr( self::BULK_EDIT_SYNC ); ?>" <?php selected( $choice, self::BULK_EDIT_SYNC ); ?>><?php esc_html_e( 'Sync', 'facebook-for-woocommerce' ); ?></option>
				<option value="<?php echo esc_attr( self::BULK_EDIT_DELETE ); ?>" <?php selected( $choice, self::BULK_EDIT_DELETE ); ?>><?php esc_html_e( 'Do not sync', 'facebook-for-woocommerce' ); ?></option>
				</select>
			</span>
		</label>
		<?php
	}

	/**
	 * Filters products by Facebook sync setting.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param array $query_vars product query vars for the edit screen
	 * @return array
	 */
	public function filter_products_by_sync_enabled( $query_vars ) {
		$valid_values = array(
			self::INCLUDE_FACEBOOK_SYNC,
			self::EXCLUDE_FACEBOOK_SYNC,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_REQUEST['fb_sync_enabled'] ) && in_array( $_REQUEST['fb_sync_enabled'], $valid_values, true ) ) {
			// store original meta query
			$original_meta_query = ! empty( $query_vars['meta_query'] ) ? $query_vars['meta_query'] : [];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$filter_value = sanitize_text_field( wp_unslash( $_REQUEST['fb_sync_enabled'] ) );
			// by default use an "AND" clause if multiple conditions exist for a meta query
			if ( ! empty( $query_vars['meta_query'] ) ) {
				$query_vars['meta_query']['relation'] = 'AND';
			} else {
				$query_vars['meta_query'] = []; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			if ( self::INCLUDE_FACEBOOK_SYNC === $filter_value ) {
				$query_vars     = $this->add_query_vars_to_find_products_with_sync_enabled( $query_vars );
				$found_ids      = get_posts( array_merge( $query_vars, array( 'fields' => 'ids' ) ) );
				$found_products = empty( $found_ids ) ? [] : wc_get_products(
					array(
						'limit'   => -1,
						'include' => $found_ids,
					)
				);
				/** @var \WC_Product[] $found_products */
				foreach ( $found_products as $product ) {
					try {
						facebook_for_woocommerce()->get_product_sync_validator( $product )->validate();
					} catch ( \Exception $e ) {
						$exclude_products[] = $product->get_id();
					}
				}

				if ( ! empty( $exclude_products ) ) {
					if ( ! empty( $query_vars['post__not_in'] ) ) {
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Excluding products that fail sync validation from the admin product list filter; the exclusion set is bounded by the current page of results.
						$query_vars['post__not_in'] = array_merge( $query_vars['post__not_in'], $exclude_products );
					} else {
						// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Excluding products that fail sync validation from the admin product list filter; the exclusion set is bounded by the current page of results.
						$query_vars['post__not_in'] = $exclude_products;
					}
				}
				/**
				 * Showing only published products as private ones are not synced
				 */
				$query_vars['post_status'] = 'publish';
			} else {
				$query_vars = $this->add_query_vars_to_find_products_with_sync_disabled( $query_vars );
			}
		}

		if ( isset( $query_vars['meta_query'] ) && empty( $query_vars['meta_query'] ) ) {
			unset( $query_vars['meta_query'] );
		}

		return $query_vars;
	}


	/**
	 * Adds query vars to limit the results to products that have sync enabled.
	 *
	 * @since 1.10.0
	 *
	 * @param array $query_vars
	 * @return array
	 */
	private function add_query_vars_to_find_products_with_sync_enabled( array $query_vars ) {
		$meta_query = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			array(
				'key'   => Products::get_product_sync_meta_key(),
				'value' => 'yes',
			),
			array(
				'key'     => Products::get_product_sync_meta_key(),
				'compare' => 'NOT EXISTS',
			),
		);

		if ( empty( $query_vars['meta_query'] ) ) {
			$query_vars['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} elseif ( is_array( $query_vars['meta_query'] ) ) {
			$original_meta_query      = $query_vars['meta_query'];
			$query_vars['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				$original_meta_query,
				$meta_query,
			);
		}

		return $query_vars;
	}

	/**
	 * Adds query vars to limit the results to products that have sync disabled.
	 *
	 * @since 3.5.5
	 *
	 * @param array $query_vars
	 * @return array
	 */
	private function add_query_vars_to_find_products_with_sync_disabled( array $query_vars ) {
		$meta_query = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => Products::get_product_sync_meta_key(),
				'value' => 'no',
			),
		);

		if ( empty( $query_vars['meta_query'] ) ) {
			$query_vars['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} elseif ( is_array( $query_vars['meta_query'] ) ) {
			$original_meta_query      = $query_vars['meta_query'];
			$query_vars['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				$original_meta_query,
				$meta_query,
			);
		}
		return $query_vars;
	}


	/**
	 * Adds bulk actions in the products edit screen.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param array $bulk_actions array of bulk action keys and labels
	 * @return array
	 */
	public function add_products_sync_bulk_actions( $bulk_actions ) {
		$bulk_actions['facebook_include'] = __( 'Include in Facebook sync', 'facebook-for-woocommerce' );
		$bulk_actions['facebook_exclude'] = __( 'Exclude from Facebook sync', 'facebook-for-woocommerce' );
		return $bulk_actions;
	}


	/**
	 * Handles a Facebook product sync bulk action.
	 * Called every time for a product
	 *
	 * @internal
	 *
	 * @param string $product_edit the product metadata that is being edited.
	 */
	public function handle_products_sync_bulk_actions( $product_edit ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sync_mode = isset( $_GET['facebook_bulk_sync_options'] ) ? (string) sanitize_text_field( wp_unslash( $_GET['facebook_bulk_sync_options'] ) ) : null;

		if ( $sync_mode ) {
			/** @var \WC_Product[] $enabling_sync_virtual_products virtual products that are being included */
			$enabling_sync_virtual_products = [];
			/** @var \WC_Product_Variation[] $enabling_sync_virtual_variations virtual variations that are being included */
			$enabling_sync_virtual_variations = [];
			/** @var \WC_Product $product to store the product meta data */
			$product = wc_get_product( $product_edit );

			if ( $product && $this::BULK_EDIT_SYNC === $sync_mode ) {
				if ( $product->is_virtual() && ! Products::is_sync_enabled_for_product( $product ) ) {
					$enabling_sync_virtual_products[ $product->get_id() ] = $product;
				} elseif ( $product->is_type( 'variable' ) ) {
						// collect the virtual variations
					foreach ( $product->get_children() as $variation_id ) {
						$variation = wc_get_product( $variation_id );
						if ( $variation && $variation->is_virtual() && ! Products::is_sync_enabled_for_product( $variation ) ) {
							$enabling_sync_virtual_variations[ $variation->get_id() ] = $variation;
						}
					}//end foreach
					if ( ! empty( $enabling_sync_virtual_variations ) ) {
						$enabling_sync_virtual_products[ $product->get_id() ] = $product;
					}//end if
				}//end if
			}//end if

			if ( ! empty( $enabling_sync_virtual_products ) || ! empty( $enabling_sync_virtual_variations ) ) {
				// display notice if enabling sync for virtual products or variations
				set_transient( 'wc_' . facebook_for_woocommerce()->get_id() . '_enabling_virtual_products_sync_show_notice_' . get_current_user_id(), true, 15 * MINUTE_IN_SECONDS );
				set_transient( 'wc_' . facebook_for_woocommerce()->get_id() . '_enabling_virtual_products_sync_affected_products_' . get_current_user_id(), array_keys( $enabling_sync_virtual_products ), 15 * MINUTE_IN_SECONDS );

				// set visibility for virtual products
				foreach ( $enabling_sync_virtual_products as $product ) {

					// do not set visibility for variable products
					if ( ! $product->is_type( 'variable' ) ) {
						Products::set_product_visibility( $product, false );
					}
				}

				// set visibility for virtual variations
				foreach ( $enabling_sync_virtual_variations as $variation ) {

					Products::set_product_visibility( $variation, false );
				}
			}//end if

			$products[] = $product;

			if ( $this::BULK_EDIT_SYNC === $sync_mode ) {

				Products::enable_sync_for_products( $products );

				$this->resync_products( $products );

			} elseif ( $this::BULK_EDIT_DELETE === $sync_mode ) {

				Products::disable_sync_for_products( $products );

			}
		} //end if
	}

	/**
	 * Re-syncs the given products.
	 *
	 * @since 2.0.0
	 *
	 * @param \WC_Product[] $products Array of product objects to resync
	 */
	private function resync_products( array $products ) {

		$integration = facebook_for_woocommerce()->get_integration();

		// re-sync each product
		foreach ( $products as $product ) {

			if ( $product->is_type( 'variable' ) ) {

				// create product group and schedule product variations to be synced in the background
				$integration->on_product_publish( $product->get_id() );

			} elseif ( $integration->product_should_be_synced( $product ) ) {

				// schedule simple products to be updated in the catalog in the background
				facebook_for_woocommerce()->get_products_sync_handler()->create_or_update_products( array( $product->get_id() ) );
			}
		}
	}

	/**
	 * Adds a message for after a product or set of products get excluded from sync.
	 *
	 * @since 2.0.0
	 */
	public function maybe_show_product_disabled_sync_notice() {

		$transient_name = 'wc_' . facebook_for_woocommerce()->get_id() . '_show_product_disabled_sync_notice_' . get_current_user_id();
		$message_id     = 'wc-' . facebook_for_woocommerce()->get_id_dasherized() . '-product-disabled-sync';

		$count = get_transient( $transient_name );
		if ( $count && ( Helper::is_current_screen( 'edit-product' ) || Helper::is_current_screen( 'product' ) ) ) {

			$message = sprintf(
				/* translators: Placeholders: %1$s - <strong> tag, %2$s - </strong> tag, %3$s - <a> tag, %4$s - <a> tag */
				_n( '%1$sHeads up!%2$s If this product was previously visible in Facebook, you may need to delete it from the %3$sFacebook catalog%4$s to completely hide it from customer view.', '%1$sHeads up!%2$s If these products were previously visible in Facebook, you may need to delete them from the %3$sFacebook catalog%4$s to completely hide them from customer view.', $count, 'facebook-for-woocommerce' ),
				'<strong>',
				'</strong>',
				'<a href="https://facebook.com/products" target="_blank">',
				'</a>'
			);

			$message .= '<a class="button js-wc-plugin-framework-notice-dismiss">' . esc_html__( "Don't show this notice again", 'facebook-for-woocommerce' ) . '</a>';

			facebook_for_woocommerce()->get_admin_notice_handler()->add_admin_notice(
				$message,
				$message_id,
				array(
					'dismissible' => false,
					// we add our own dismiss button
														'notice_class' => 'notice-info',
				)
			);

			delete_transient( $transient_name );
		}//end if
	}


	/**
	 * Prints a notice on products page to inform users that the virtual products selected for the Include bulk action will have sync enabled, but will be hidden.
	 *
	 * @internal
	 *
	 * @since 1.11.3-dev.2
	 */
	public function maybe_add_enabling_virtual_products_sync_notice() {

		$show_notice_transient_name       = 'wc_' . facebook_for_woocommerce()->get_id() . '_enabling_virtual_products_sync_show_notice_' . get_current_user_id();
		$affected_products_transient_name = 'wc_' . facebook_for_woocommerce()->get_id() . '_enabling_virtual_products_sync_affected_products_' . get_current_user_id();

		$affected_products = get_transient( $affected_products_transient_name );
		if ( Helper::is_current_screen( 'edit-product' ) && get_transient( $show_notice_transient_name ) && $affected_products ) {

			$message = sprintf(
				esc_html(
				/* translators: Placeholders: %1$s - number of affected products, %2$s opening HTML <a> tag, %3$s - closing HTML </a> tag, %4$s - opening HTML <a> tag, %5$s - closing HTML </a> tag */
					_n(
						'%2$s%1$s product%3$s or some of its variations could not be updated to show in the Facebook catalog — %4$sFacebook Commerce Policies%5$s prohibit selling some product types (like virtual products). You may still advertise Virtual products on Facebook.',
						'%2$s%1$s products%3$s or some of their variations could not be updated to show in the Facebook catalog — %4$sFacebook Commerce Policies%5$s prohibit selling some product types (like virtual products). You may still advertise Virtual products on Facebook.',
						count( $affected_products ),
						'facebook-for-woocommerce'
					)
				),
				count( $affected_products ),
				'<a href="' . esc_url( add_query_arg( array( 'facebook_show_affected_products' => 1 ) ) ) . '">',
				'</a>',
				'<a href="https://www.facebook.com/policies/commerce/prohibited_content/subscriptions_and_digital_products" target="_blank">',
				'</a>'
			);

			facebook_for_woocommerce()->get_admin_notice_handler()->add_admin_notice(
				$message,
				'wc-' . facebook_for_woocommerce()->get_id_dasherized() . '-enabling-virtual-products-sync',
				array(
					'dismissible'  => false,
					'notice_class' => 'notice-info',
				)
			);

			delete_transient( $show_notice_transient_name );
		}//end if
	}


	/**
	 * Tweaks the query to show a filtered view with the affected products.
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 *
	 * @param array $query_vars product query vars for the edit screen
	 * @return array
	 */
	public function filter_virtual_products_affected_enabling_sync( $query_vars ) {

		$transient_name = 'wc_' . facebook_for_woocommerce()->get_id() . '_enabling_virtual_products_sync_affected_products_' . get_current_user_id();

		$affected_products = get_transient( $transient_name );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['facebook_show_affected_products'] ) && Helper::is_current_screen( 'edit-product' ) && $affected_products ) {

			$query_vars['post__in'] = $affected_products;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return $query_vars;
	}


	/**
	 * Prints a notice to inform sync mode has been automatically set to Sync and hide for virtual products and variations.
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 */
	public function add_handled_virtual_products_variations_notice() {

		if ( 'yes' === get_option( 'wc_facebook_background_handle_virtual_products_variations_complete', 'no' ) &&
			'yes' !== get_option( 'wc_facebook_background_handle_virtual_products_variations_skipped', 'no' ) ) {

			facebook_for_woocommerce()->get_admin_notice_handler()->add_admin_notice(
				sprintf(
					/* translators: Placeholders: %1$s - opening HTML <strong> tag, %2$s - closing HTML </strong> tag, %3$s - opening HTML <a> tag, %4$s - closing HTML </a> tag */
					esc_html__( '%1$sHeads up!%2$s Facebook\'s %3$sCommerce Policies%4$s do not support selling virtual products, so we have hidden your synced Virtual products in your Facebook catalog. You may still advertise Virtual products on Facebook.', 'facebook-for-woocommerce' ),
					'<strong>',
					'</strong>',
					'<a href="https://www.facebook.com/policies/commerce/prohibited_content/subscriptions_and_digital_products" target="_blank">',
					'</a>'
				),
				'wc-' . facebook_for_woocommerce()->get_id_dasherized() . '-updated-virtual-products-sync',
				array(
					'notice_class'            => 'notice-info',
					'always_show_on_settings' => false,
				)
			);
		}
	}


	/**
	 * Adds a new tab to the Product edit page.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param array $tabs product tabs
	 * @return array
	 */
	public function add_product_settings_tab( $tabs ) {

		$tabs['fb_commerce_tab'] = array(
			'label'  => __( 'Facebook', 'facebook-for-woocommerce' ),
			'target' => 'facebook_options',
			'class'  => array( 'show_if_simple', 'show_if_variable', 'show_if_external' ),
		);

		return $tabs;
	}

	/**
	 * Outputs the form field for Facebook Product Videos with a description tip.
	 *
	 * @param array $video_urls Array of video URLs.
	 */
	private function render_facebook_product_video_field( $video_urls ) {
		$attachment_ids = [];

		// Output the form field for Facebook Product Videos with a description tip
		?>
		<p class="form-field fb_product_video_field">
			<label for="fb_product_video"><?php esc_html_e( 'Facebook Product Video', 'facebook-for-woocommerce' ); ?></label>
			<button type="button" class="button" id="open_media_library" name="fb_product_video"><?php esc_html_e( 'Choose', 'facebook-for-woocommerce' ); ?></button>
			<span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'Choose the product video that should be synced to the Facebook catalog and displayed for this product.', 'facebook-for-woocommerce' ); ?>" tabindex="0"></span>
		</p>
		<div id="fb_product_video_selected_thumbnails">
		<?php

		if ( ! empty( $video_urls ) ) {
			foreach ( $video_urls as $video_url ) {
				$attachment_id = attachment_url_to_postid( $video_url );
				if ( $attachment_id ) {
					$attachment_ids[] = $attachment_id;
					// Get the video thumbnail URL
					$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
					if ( ! $thumbnail_url ) {
						// Fallback to a default icon if no thumbnail is available
						$thumbnail_url = esc_url( wp_mime_type_icon( 'video' ) );
					}
					// Escape URLs and attributes
					$video_url_escaped     = esc_url( $video_url );
					$attachment_id_escaped = esc_attr( $attachment_id );
					?>
					<p class="form-field video-thumbnail">
						<img src="<?php echo esc_url( $thumbnail_url ); ?>">
						<span data-attachment-id="<?php echo esc_attr( $attachment_id_escaped ); ?>"><?php echo esc_html( $video_url_escaped ); ?></span>
						<a href="#" class="remove-video" data-attachment-id="<?php echo esc_attr( $attachment_id_escaped ); ?>"><?php esc_html_e( 'Remove', 'facebook-for-woocommerce' ); ?></a>
					</p>
					<?php
				}
			}
		}
		?>
		</div>

		<?php
		// hidden input to store attachment IDs
		woocommerce_wp_hidden_input(
			[
				'id'    => \WC_Facebook_Product::FB_PRODUCT_VIDEO,
				'name'  => \WC_Facebook_Product::FB_PRODUCT_VIDEO,
				'value' => esc_attr( implode( ',', $attachment_ids ) ), // Store attachment IDs
			]
		);
	}

	/**
	 * Renders the Facebook Product Images field for variations.
	 *
	 * @param array $attachment_ids Array of attachment IDs.
	 * @param int   $index      The variation index.
	 * @param int   $variation_id The variation ID.
	 */
	private function render_facebook_product_images_field( $attachment_ids, $index, $variation_id ) {
		// Check if multiple images feature is enabled via rollout switch
		$plugin = isset( $GLOBALS['wc_facebook_commerce'] ) ? $GLOBALS['wc_facebook_commerce'] : facebook_for_woocommerce();
		if ( ! $plugin || ! $plugin->get_rollout_switches()->is_switch_enabled( RolloutSwitches::SWITCH_MULTIPLE_IMAGES_ENABLED ) ) {
			return;
		}

		// attachment_ids is already an array of attachment IDs

		// Output the form field for Facebook Product Images with a description tip
		?>
		<p class="form-field product-image-source-field show-if-product-image-source-<?php echo esc_attr( Products::PRODUCT_IMAGE_SOURCE_MULTIPLE ); ?>">
			<!-- <label for="fb_product_images_<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Facebook Product Images', 'facebook-for-woocommerce' ); ?></label> -->
			<button type="button" class="button fb-open-images-library" data-variation-index="<?php echo esc_attr( $index ); ?>" data-variation-id="<?php echo esc_attr( $variation_id ); ?>"><?php esc_html_e( 'Add Multiple Images', 'facebook-for-woocommerce' ); ?></button>
			<span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'Choose multiple product images that should be synced to the Facebook catalog and displayed for this variation.', 'facebook-for-woocommerce' ); ?>" tabindex="0"></span>

			<div id="fb_product_images_selected_thumbnails_<?php echo esc_attr( $index ); ?>" class="fb-product-images-thumbnails">
			<?php
			if ( ! empty( $attachment_ids ) && is_array( $attachment_ids ) ) {
				foreach ( $attachment_ids as $attachment_id ) {
					$attachment_id = intval( $attachment_id );
					if ( $attachment_id > 0 ) {
						// Get the image thumbnail URL
						$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
						$full_url      = wp_get_attachment_url( $attachment_id );
						$filename      = basename( get_attached_file( $attachment_id ) );

						if ( $thumbnail_url && $full_url ) {
							?>
								<p class="form-field image-thumbnail">
									<img src="<?php echo esc_url( $thumbnail_url ); ?>">
									<span data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>"><?php echo esc_html( $filename ); ?></span>
									<a href="#" class="remove-image" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>"><?php esc_html_e( 'Remove', 'facebook-for-woocommerce' ); ?></a>
								</p>
								<?php
						}
					}
				}
			}
			?>
			</div>

			<?php
			// hidden input to store attachment IDs
			woocommerce_wp_hidden_input(
				[
					'id'    => sprintf( 'variable_%s%s', \WC_Facebook_Product::FB_PRODUCT_IMAGES, $index ),
					'name'  => sprintf( 'variable_%s%s', \WC_Facebook_Product::FB_PRODUCT_IMAGES, $index ),
					'value' => esc_attr( implode( ',', $attachment_ids ) ), // Store attachment IDs
				]
			);
			?>
		</p>
		<?php
	}

	/**
	 * Adds content to the new Facebook tab on the Product edit page.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function add_product_settings_tab_content() {
		global $post;

		// all products have sync enabled unless explicitly disabled
		$sync_enabled = 'no' !== get_post_meta( $post->ID, Products::get_product_sync_meta_key(), true );
		$visibility   = get_post_meta( $post->ID, Products::VISIBILITY_META_KEY, true );
		$is_visible   = $visibility ? wc_string_to_bool( $visibility ) : true;
		$product      = wc_get_product( $post );

		$rich_text_description = get_post_meta( $post->ID, \WC_Facebookcommerce_Integration::FB_RICH_TEXT_DESCRIPTION, true );
		$price                 = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_PRICE, true );
		$image_source          = get_post_meta( $post->ID, Products::PRODUCT_IMAGE_SOURCE_META_KEY, true );
		$image                 = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_IMAGE, true );
		$video_source          = get_post_meta( $post->ID, Products::PRODUCT_VIDEO_SOURCE_META_KEY, true );
		$video_urls            = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_VIDEO, true );
		$custom_video_url      = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url', true );
		$fb_brand              = get_post_meta( $post->ID, \WC_Facebook_Product::FB_BRAND, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_BRAND, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_brand', true );
		$fb_mpn                = get_post_meta( $post->ID, \WC_Facebook_Product::FB_MPN, true );
		$fb_condition          = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PRODUCT_CONDITION, true );
		$fb_age_group          = get_post_meta( $post->ID, \WC_Facebook_Product::FB_AGE_GROUP, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_AGE_GROUP, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_age_group', true );
		$fb_gender             = get_post_meta( $post->ID, \WC_Facebook_Product::FB_GENDER, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_GENDER, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_gender', true );
		$fb_size               = get_post_meta( $post->ID, \WC_Facebook_Product::FB_SIZE, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_SIZE, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_size', true );
		$fb_color              = get_post_meta( $post->ID, \WC_Facebook_Product::FB_COLOR, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_COLOR, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_color', true );
		$fb_material           = get_post_meta( $post->ID, \WC_Facebook_Product::FB_MATERIAL, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_MATERIAL, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_material', true );
		$fb_pattern            = get_post_meta( $post->ID, \WC_Facebook_Product::FB_PATTERN, true ) ? get_post_meta( $post->ID, \WC_Facebook_Product::FB_PATTERN, true ) : get_post_meta( $post->ID, '_wc_facebook_enhanced_catalog_attributes_pattern', true );

		if ( $sync_enabled ) {
			$sync_mode = $is_visible ? self::SYNC_MODE_SYNC_AND_SHOW : self::SYNC_MODE_SYNC_AND_HIDE;
		} else {
			$sync_mode = self::SYNC_MODE_SYNC_DISABLED;
		}

		// 'id' attribute needs to match the 'target' parameter set above
		?>
		<div id='facebook_options' class='panel woocommerce_options_panel'>
			<div>
				<?php

				woocommerce_wp_select(
					array(
						'id'          => 'wc_facebook_sync_mode',
						'label'       => __( 'Facebook Sync', 'facebook-for-woocommerce' ),
						'options'     => array(
							self::SYNC_MODE_SYNC_AND_SHOW => __( 'Sync and show in catalog', 'facebook-for-woocommerce' ),
							self::SYNC_MODE_SYNC_AND_HIDE => __( 'Sync and hide in catalog', 'facebook-for-woocommerce' ),
							self::SYNC_MODE_SYNC_DISABLED => __( 'Do not sync', 'facebook-for-woocommerce' ),
						),
						'value'       => $sync_mode,
						'desc_tip'    => true,
						'description' => __( 'Choose whether to sync this product to Facebook and, if synced, whether it should be visible in the catalog.', 'facebook-for-woocommerce' ),
					)
				);
				?>
			</div>

		<?php
		// Video fields are now rendered in the options_group section with radio buttons
		?>


			<div class='options_group hide_if_variable'>
				<?php
				echo '<div class="wp-editor-wrap">';
				echo '<label for="' . esc_attr( \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION ) . '">' .
					esc_html__( 'Facebook Description', 'facebook-for-woocommerce' ) .
					'</label>';
				wp_editor(
					$rich_text_description,
					\WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION,
					array(
						'id'            => \WC_Facebook_Product::FB_PRODUCT_DESCRIPTION,
						'textarea_name' => \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION,
						'textarea_rows' => 10,
						'media_buttons' => true,
						'teeny'         => true,
						'quicktags'     => false,
						'tinymce'       => array(
							'toolbar1' => 'bold,italic,bullist,spellchecker,fullscreen',
						),
					)
				);
				echo '</div>';

				woocommerce_wp_radio(
					array(
						'id'            => 'fb_product_image_source',
						'label'         => __( 'Facebook Product Image', 'facebook-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Choose the product image that should be synced to the Facebook catalog and displayed for this product.', 'facebook-for-woocommerce' ),
						'options'       => array(
							Products::PRODUCT_IMAGE_SOURCE_PRODUCT => __( 'Use WooCommerce image', 'facebook-for-woocommerce' ),
							Products::PRODUCT_IMAGE_SOURCE_CUSTOM  => __( 'Use custom image', 'facebook-for-woocommerce' ),
						),
						'value'         => $image_source ? $image_source : Products::PRODUCT_IMAGE_SOURCE_PRODUCT,
						'class'         => 'short enable-if-sync-enabled js-fb-product-image-source',
						'wrapper_class' => 'fb-product-image-source-field',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_PRODUCT_IMAGE,
						'label'       => __( 'Custom Image URL', 'facebook-for-woocommerce' ),
						'value'       => $image,
						'class'       => sprintf( 'enable-if-sync-enabled product-image-source-field show-if-product-image-source-%s', Products::PRODUCT_IMAGE_SOURCE_CUSTOM ),
						'desc_tip'    => true,
						'description' => __( 'Please enter an absolute URL (e.g. https://domain.com/image.jpg).', 'facebook-for-woocommerce' ),
					)
				);

				// Render the Facebook Product Video field with radio buttons
				$this->render_facebook_product_video_field( $video_urls );

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_PRODUCT_PRICE,
						'label'       => sprintf(
						/* translators: Placeholders %1$s - WC currency symbol */
							__( 'Facebook Price (%1$s)', 'facebook-for-woocommerce' ),
							get_woocommerce_currency_symbol()
						),
						'desc_tip'    => true,
						'description' => __( 'Custom price for product on Facebook. Please enter in monetary decimal (.) format without thousand separators and currency symbols. If blank, product price will be used.', 'facebook-for-woocommerce' ),
						'cols'        => 40,
						'rows'        => 60,
						'value'       => $price,
						'class'       => 'enable-if-sync-enabled',
					)
				);

				woocommerce_wp_hidden_input(
					array(
						'id'    => \WC_Facebook_Product::FB_REMOVE_FROM_SYNC,
						'value' => '',
					)
				);
				?>
			</div>

			<div class='wc_facebook_commerce_fields'>
				<p class="text-heading">
					<span><?php echo esc_html( \WooCommerce\Facebook\Admin\Product_Categories::get_catalog_explanation_text() ); ?></span>
					<a href="#" class="go-to-attributes-link" style="text-decoration: underline; cursor: pointer;">
						<?php echo esc_html__( 'Go to attributes', 'facebook-for-woocommerce' ); ?>
					</a>
				</p>
			</div>

			<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('.go-to-attributes-link').click(function(e) {
					e.preventDefault();
					$('li.attribute_options.attribute_tab a[href="#product_attributes"]').trigger('click');
					$('html, body').animate({
						scrollTop: $('#product_attributes').offset().top - 50
					}, 500);
				});
			});
			</script>

			<?php
				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_MPN,
						'name'        => \WC_Facebook_Product::FB_MPN,
						'label'       => __( 'Manufacturer Part Number (MPN)', 'facebook-for-woocommerce' ),
						'value'       => $fb_mpn,
						'class'       => 'enable-if-sync-enabled',
						'desc_tip'    => true,
						'description' => __( 'Manufacturer Part Number (MPN) of the item', 'facebook-for-woocommerce' ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_BRAND,
						'name'        => \WC_Facebook_Product::FB_BRAND,
						'label'       => __( 'Brand', 'facebook-for-woocommerce' ),
						'value'       => $fb_brand,
						'class'       => 'enable-if-sync-enabled',
						'desc_tip'    => true,
						'description' => __( 'Brand name of the item', 'facebook-for-woocommerce' ),
						'placeholder' => \WC_Facebookcommerce_Utils::get_default_fb_brand(),
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => \WC_Facebook_Product::FB_PRODUCT_CONDITION,
						'name'        => \WC_Facebook_Product::FB_PRODUCT_CONDITION,
						'label'       => __( 'Condition', 'facebook-for-woocommerce' ),
						'options'     => array(
							'' => __( 'Select', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::CONDITION_NEW => __( 'New', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::CONDITION_REFURBISHED => __( 'Refurbished', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::CONDITION_USED => __( 'Used', 'facebook-for-woocommerce' ),
						),
						'value'       => $fb_condition,
						'desc_tip'    => true,
						'description' => __( 'This refers to the condition of your product. Supported values are new, refurbished and used.', 'facebook-for-woocommerce' ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_SIZE,
						'label'       => __( 'Size', 'facebook-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Size of the product item', 'facebook-for-woocommerce' ),
						'name'        => \WC_Facebook_Product::FB_SIZE,
						'cols'        => 40,
						'rows'        => 60,
						'value'       => $fb_size,
						'class'       => 'enable-if-sync-enabled',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_COLOR,
						'name'        => \WC_Facebook_Product::FB_COLOR,
						'label'       => __( 'Color', 'facebook-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Color of the product item', 'facebook-for-woocommerce' ),
						'cols'        => 40,
						'rows'        => 60,
						'value'       => $fb_color,
						'class'       => 'enable-if-sync-enabled',
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => \WC_Facebook_Product::FB_AGE_GROUP,
						'name'        => \WC_Facebook_Product::FB_AGE_GROUP,
						'label'       => __( 'Age Group', 'facebook-for-woocommerce' ),
						'options'     => array(
							'' => __( 'Select', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_ADULT => __( 'Adult', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_ALL_AGES => __( 'All Ages', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_TEEN => __( 'Teen', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_KIDS => __( 'Kids', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_TODDLER => __( 'Toddler', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_INFANT => __( 'Infant', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::AGE_GROUP_NEWBORN => __( 'Newborn', 'facebook-for-woocommerce' ),
						),
						'value'       => $fb_age_group,
						'desc_tip'    => true,
						'description' => __( 'Select the age group for this product.', 'facebook-for-woocommerce' ),
					)
				);

				woocommerce_wp_select(
					array(
						'id'          => \WC_Facebook_Product::FB_GENDER,
						'name'        => \WC_Facebook_Product::FB_GENDER,
						'label'       => __( 'Gender', 'facebook-for-woocommerce' ),
						'options'     => array(
							'' => __( 'Select', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::GENDER_FEMALE => __( 'Female', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::GENDER_MALE => __( 'Male', 'facebook-for-woocommerce' ),
							\WC_Facebook_Product::GENDER_UNISEX => __( 'Unisex', 'facebook-for-woocommerce' ),
						),
						'value'       => $fb_gender,
						'desc_tip'    => true,
						'description' => __( 'Select the gender for this product.', 'facebook-for-woocommerce' ),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_MATERIAL,
						'label'       => __( 'Material', 'facebook-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Material of the product item', 'facebook-for-woocommerce' ),
						'name'        => \WC_Facebook_Product::FB_MATERIAL,
						'cols'        => 40,
						'rows'        => 60,
						'value'       => $fb_material,
						'class'       => 'enable-if-sync-enabled',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => \WC_Facebook_Product::FB_PATTERN,
						'label'       => __( 'Pattern', 'facebook-for-woocommerce' ),
						'desc_tip'    => true,
						'description' => __( 'Pattern of the product item', 'facebook-for-woocommerce' ),
						'name'        => \WC_Facebook_Product::FB_PATTERN,
						'cols'        => 40,
						'rows'        => 60,
						'value'       => $fb_pattern,
						'class'       => 'enable-if-sync-enabled',
					)
				);

			?>

			<div class='wc-facebook-commerce-options-group options_group google_product_catgory'>
				<?php \WooCommerce\Facebook\Admin\Products::render_google_product_category_fields_and_enhanced_attributes( $product ); ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Outputs the Facebook settings fields for a single variation.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 *
	 * @param int      $index the index of the current variation
	 * @param array    $variation_data unused
	 * @param \WC_Post $post the post type for the current variation
	 */
	public function add_product_variation_edit_fields( $index, $variation_data, $post ) {
		$variation = wc_get_product( $post );

		if ( ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		$parent = wc_get_product( $variation->get_parent_id() );

		if ( ! $parent instanceof \WC_Product ) {
			return;
		}

		// Get variation meta values
		$description      = $this->get_product_variation_meta( $variation, \WC_Facebookcommerce_Integration::FB_RICH_TEXT_DESCRIPTION, $parent );
		$price            = $this->get_product_variation_meta( $variation, \WC_Facebook_Product::FB_PRODUCT_PRICE, $parent );
		$image_url        = $this->get_product_variation_meta( $variation, \WC_Facebook_Product::FB_PRODUCT_IMAGE, $parent );
		$image_source     = $variation->get_meta( Products::PRODUCT_IMAGE_SOURCE_META_KEY );
		$image_urls       = $this->get_product_variation_meta( $variation, \WC_Facebook_Product::FB_PRODUCT_IMAGES, $parent );
		$video_source     = $variation->get_meta( Products::PRODUCT_VIDEO_SOURCE_META_KEY );
		$video_urls       = $this->get_product_variation_meta( $variation, \WC_Facebook_Product::FB_PRODUCT_VIDEO, $parent );
		$custom_video_url = $variation->get_meta( \WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url' );
		$fb_mpn           = $this->get_product_variation_meta( $variation, \WC_Facebook_Product::FB_MPN, $parent );

		?>
		<div class="facebook-metabox wc-metabox closed">
			<h3>
				<strong><?php esc_html_e( 'Meta for WooCommerce', 'facebook-for-woocommerce' ); ?></strong>
				<div class="handlediv" aria-label="<?php esc_attr_e( 'Click to toggle', 'facebook-for-woocommerce' ); ?>"></div>
			</h3>
			<div class="wc-metabox-content" style="display: none;">
				<?php wp_nonce_field( 'facebook_variation_save', 'facebook_variation_nonce_' . $variation->get_id() ); ?>
				<?php
				woocommerce_wp_textarea_input(
					array(
						'id'            => sprintf( 'variable_%s%s', \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION, $index ),
						'name'          => sprintf( 'variable_%s[%s]', \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION, $index ),
						'label'         => __( 'Facebook Description', 'facebook-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Custom (plain-text only) description for product on Facebook. If blank, product description will be used. If product description is blank, shortname will be used.', 'facebook-for-woocommerce' ),
						'value'         => $description,
						'class'         => 'enable-if-sync-enabled',
						'wrapper_class' => 'form-row form-row-full',
					)
				);

				// Build image source options
				$image_source_options = array(
					Products::PRODUCT_IMAGE_SOURCE_PRODUCT => __( 'Use variation image', 'facebook-for-woocommerce' ),
					Products::PRODUCT_IMAGE_SOURCE_PARENT_PRODUCT => __( 'Use parent image', 'facebook-for-woocommerce' ),
					Products::PRODUCT_IMAGE_SOURCE_CUSTOM  => __( 'Use custom image', 'facebook-for-woocommerce' ),
				);

				// Add multiple images option only if rollout switch is enabled
				$plugin = isset( $GLOBALS['wc_facebook_commerce'] ) ? $GLOBALS['wc_facebook_commerce'] : facebook_for_woocommerce();
				if ( $plugin && $plugin->get_rollout_switches()->is_switch_enabled( RolloutSwitches::SWITCH_MULTIPLE_IMAGES_ENABLED ) ) {
					$image_source_options[ Products::PRODUCT_IMAGE_SOURCE_MULTIPLE ] = __( 'Add multiple images', 'facebook-for-woocommerce' );
				}

				woocommerce_wp_select(
					array(
						'id'            => "variable_fb_product_image_source$index",
						'name'          => "variable_fb_product_image_source[$index]",
						'label'         => __( 'Facebook Product Image', 'facebook-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Choose the product image that should be synced to the Facebook catalog and displayed for this product.', 'facebook-for-woocommerce' ),
						'options'       => $image_source_options,
						'value'         => $image_source ? $image_source : Products::PRODUCT_IMAGE_SOURCE_PRODUCT,
						'class'         => 'enable-if-sync-enabled js-fb-product-image-source',
						'wrapper_class' => 'fb-product-image-source-field',
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'            => sprintf( 'variable_%s%s', \WC_Facebook_Product::FB_PRODUCT_IMAGE, $index ),
						'name'          => sprintf( 'variable_%s[%s]', \WC_Facebook_Product::FB_PRODUCT_IMAGE, $index ),
						'label'         => __( 'Custom Image URL', 'facebook-for-woocommerce' ),
						'value'         => $image_url,
						'class'         => sprintf( 'enable-if-sync-enabled product-image-source-field show-if-product-image-source-%s', Products::PRODUCT_IMAGE_SOURCE_CUSTOM ),
						'wrapper_class' => 'form-row form-row-full',
						'desc_tip'      => true,
						'description'   => __( 'Please enter an absolute URL (e.g. https://domain.com/image.jpg).', 'facebook-for-woocommerce' ),
					)
				);

				// Render Facebook Product Images field
				$image_ids_array = ! empty( $image_urls ) ? explode( ',', $image_urls ) : [];
				// Clean up the IDs and ensure they're numeric
				$image_ids_array = array_filter( array_map( 'trim', $image_ids_array ), 'is_numeric' );

				$this->render_facebook_product_images_field( $image_ids_array, $index, $variation->get_id() );

				// Render Facebook Product Video field with radio buttons for variations
				woocommerce_wp_select(
					array(
						'id'            => "variable_fb_product_video_source$index",
						'name'          => "variable_fb_product_video_source[$index]",
						'label'         => __( 'Facebook Product Video', 'facebook-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Choose the product video that should be synced to the Facebook catalog and displayed for this variation.', 'facebook-for-woocommerce' ),
						'options'       => array(
							Products::PRODUCT_VIDEO_SOURCE_UPLOAD => __( 'Choose video(s)', 'facebook-for-woocommerce' ),
							Products::PRODUCT_VIDEO_SOURCE_CUSTOM => __( 'Use custom video', 'facebook-for-woocommerce' ),
						),
						'value'         => $video_source ? $video_source : Products::PRODUCT_VIDEO_SOURCE_UPLOAD,
						'class'         => 'enable-if-sync-enabled js-fb-product-video-source',
						'wrapper_class' => 'fb-product-video-source-field',
					)
				);

				// Add Choose Video button inline with first radio option
				?>
		<div class="product-video-source-field product-video-source-container show-if-product-video-source-<?php echo esc_attr( Products::PRODUCT_VIDEO_SOURCE_UPLOAD ); ?>">
			<button type="button" class="button fb-open-video-library enable-if-sync-enabled" data-variation-index="<?php echo esc_attr( $index ); ?>" data-variation-id="<?php echo esc_attr( $variation->get_id() ); ?>">
				<?php esc_html_e( 'Choose Video', 'facebook-for-woocommerce' ); ?>
			</button>
		</div>
		<div id="fb_product_video_selected_thumbnails_<?php echo esc_attr( $index ); ?>" class="fb-product-video-thumbnails product-video-source-field <?php echo esc_attr( 'show-if-product-video-source-' . Products::PRODUCT_VIDEO_SOURCE_UPLOAD ); ?>" >
			<?php
				$attachment_ids   = [];
				$video_urls_array = ! empty( $video_urls ) && is_string( $video_urls ) ? explode( ',', $video_urls ) : ( is_array( $video_urls ) ? $video_urls : [] );
			if ( ! empty( $video_urls_array ) && is_array( $video_urls_array ) ) {
				foreach ( $video_urls_array as $video_url ) {
					$attachment_id = attachment_url_to_postid( $video_url );
					if ( $attachment_id ) {
						$attachment_ids[] = $attachment_id;
						// Get the video thumbnail URL
						$thumbnail_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
						if ( ! $thumbnail_url ) {
							// Fallback to a default icon if no thumbnail is available
							$thumbnail_url = esc_url( wp_mime_type_icon( 'video' ) );
						}
						// Get filename
						$filename = basename( get_attached_file( $attachment_id ) );
						?>
							<p class="form-field video-thumbnail">
								<img src="<?php echo esc_url( $thumbnail_url ); ?>">
								<span data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>"><?php echo esc_html( $filename ); ?></span>
								<a href="#" class="remove-video" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>" data-variation-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Remove', 'facebook-for-woocommerce' ); ?></a>
							</p>
							<?php
					}
				}
			}
			?>
		</div>
		<?php
		// hidden input to store attachment IDs for variations
		woocommerce_wp_hidden_input(
			[
				'id'    => 'variable_' . \WC_Facebook_Product::FB_PRODUCT_VIDEO . $index,
				'name'  => 'variable_' . \WC_Facebook_Product::FB_PRODUCT_VIDEO . $index,
				'value' => esc_attr( implode( ',', $attachment_ids ) ),
				'class' => sprintf( 'product-video-source-field show-if-product-video-source-%s', Products::PRODUCT_VIDEO_SOURCE_UPLOAD ),
			]
		);

			// Render custom video URL field (shown when "Use custom video" is selected)
			woocommerce_wp_text_input(
				array(
					'id'            => sprintf( 'variable_%s_custom_url%s', \WC_Facebook_Product::FB_PRODUCT_VIDEO, $index ),
					'name'          => sprintf( 'variable_%s_custom_url[%s]', \WC_Facebook_Product::FB_PRODUCT_VIDEO, $index ),
					'label'         => __( 'Custom Video URL', 'facebook-for-woocommerce' ),
					'value'         => $custom_video_url,
					'class'         => sprintf( 'enable-if-sync-enabled product-video-source-field show-if-product-video-source-%s', Products::PRODUCT_VIDEO_SOURCE_CUSTOM ),
					'wrapper_class' => 'form-row form-row-full',
					'desc_tip'      => true,
					'description'   => __( 'Please enter an absolute URL (e.g. https://domain.com/video.mp4). The URL must be publicly accessible for Facebook to display the video in Commerce Manager.', 'facebook-for-woocommerce' ),
					'type'          => 'url',
				)
			);

			woocommerce_wp_text_input(
				array(
					'id'            => sprintf( 'variable_%s%s', \WC_Facebook_Product::FB_PRODUCT_PRICE, $index ),
					'name'          => sprintf( 'variable_%s[%s]', \WC_Facebook_Product::FB_PRODUCT_PRICE, $index ),
					'label'         => sprintf(
					/* translators: Placeholders %1$s - WC currency symbol */
						__( 'Facebook Price (%1$s)', 'facebook-for-woocommerce' ),
						get_woocommerce_currency_symbol()
					),
					'desc_tip'      => true,
					'description'   => __( 'Custom price for product on Facebook. Please enter in monetary decimal (.) format without thousand separators and currency symbols. If blank, product price will be used.', 'facebook-for-woocommerce' ),
					'value'         => wc_format_decimal( $price ),
					'class'         => 'enable-if-sync-enabled',
					'wrapper_class' => 'form-row form-full',
				)
			);

				woocommerce_wp_text_input(
					array(
						'id'            => sprintf( 'variable_%s%s', \WC_Facebook_Product::FB_MPN, $index ),
						'name'          => sprintf( 'variable_%s[%s]', \WC_Facebook_Product::FB_MPN, $index ),
						'label'         => __( 'Manufacturer Parts Number (MPN)', 'facebook-for-woocommerce' ),
						'desc_tip'      => true,
						'description'   => __( 'Manufacturer Parts Number', 'facebook-for-woocommerce' ),
						'value'         => $fb_mpn,
						'class'         => 'enable-if-sync-enabled',
						'wrapper_class' => 'form-row form-full',
					)
				);

		?>
			</div>
		</div>

		<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Remove any existing click handlers first
				$('.facebook-metabox h3, .facebook-metabox .handlediv').off('click');

				// Add new click handler
				$('.facebook-metabox h3, .facebook-metabox .handlediv').on('click', function(e) {
					e.preventDefault(); // Prevent any default behavior
					e.stopPropagation(); // Stop event bubbling

					var $metabox = $(this).closest('.facebook-metabox');
					$metabox.toggleClass('closed');
					$metabox.find('.wc-metabox-content').slideToggle();
				});

				// Ensure metaboxes start closed
				$('.facebook-metabox').addClass('closed')
									.find('.wc-metabox-content')
									.hide();
			});
		</script>
		<?php
	}


	/**
	 * Gets the stored value for the given meta of a product variation.
	 *
	 * If no value is found, we try to use the value stored in the parent product.
	 *
	 * @since 1.10.0
	 *
	 * @param \WC_Product_Variation $variation the product variation
	 * @param string                $key the name of the meta to retrieve
	 * @param \WC_Product           $parent_product the parent product
	 * @return mixed
	 */
	private function get_product_variation_meta( $variation, $key, $parent_product ) {
		$value = $variation->get_meta( $key );
		if ( '' === $value && $parent_product instanceof \WC_Product ) {
			$value = $parent_product->get_meta( $key );
		}
		return $value;
	}


		/**
		 * Saves the submitted Facebook settings for each variation.
		 *
		 * @internal
		 *
		 * @since 1.10.0
		 *
		 * @param int $variation_id the ID of the product variation being edited
		 * @param int $index the index of the current variation
		 */
	public function save_product_variation_edit_fields( $variation_id, $index ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product_Variation ) {
			return;
		}

		if ( ! $this->verify_variation_nonce( $variation_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing

		$sync_mode    = $this->determine_variation_sync_mode( $variation );
		$sync_enabled = self::SYNC_MODE_SYNC_DISABLED !== $sync_mode;

		$variation_data = $this->process_variation_post_data( $index );
		$this->save_variation_meta_data( $variation, $variation_data );
		$this->handle_variation_sync_operations( $variation, $sync_enabled, $sync_mode );

		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Verifies the nonce for variation save operation.
	 *
	 * @param int $variation_id the ID of the product variation
	 * @return bool true if nonce is valid, false otherwise
	 */
	private function verify_variation_nonce( $variation_id ) {
		$nonce_field = 'facebook_variation_nonce_' . $variation_id;
		return isset( $_POST[ $nonce_field ] ) && wp_verify_nonce( sanitize_key( $_POST[ $nonce_field ] ), 'facebook_variation_save' );
	}

	/**
	 * Determines the sync mode for a variation.
	 *
	 * @param \WC_Product_Variation $variation the product variation
	 * @return string the sync mode
	 */
	private function determine_variation_sync_mode( $variation ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$sync_mode = isset( $_POST['wc_facebook_sync_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_facebook_sync_mode'] ) ) : self::SYNC_MODE_SYNC_DISABLED;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		if ( ! isset( $_POST['wc_facebook_sync_mode'] ) ) {
			$sync_mode = $this->get_parent_product_sync_mode( $variation );
		}

		if ( self::SYNC_MODE_SYNC_AND_SHOW === $sync_mode && $variation->is_virtual() ) {
			$sync_mode = self::SYNC_MODE_SYNC_AND_HIDE;
		}

		return $sync_mode;
	}

	/**
	 * Gets the sync mode from the parent product.
	 *
	 * @param \WC_Product_Variation $variation the product variation
	 * @return string the sync mode
	 */
	private function get_parent_product_sync_mode( $variation ) {
		$parent_product = wc_get_product( $variation->get_parent_id() );
		if ( ! $parent_product ) {
			return self::SYNC_MODE_SYNC_DISABLED;
		}

		$parent_sync_enabled = 'no' !== get_post_meta( $parent_product->get_id(), Products::SYNC_ENABLED_META_KEY, true );
		$parent_visibility   = get_post_meta( $parent_product->get_id(), Products::VISIBILITY_META_KEY, true );
		$parent_is_visible   = $parent_visibility ? wc_string_to_bool( $parent_visibility ) : true;

		if ( $parent_sync_enabled ) {
			return $parent_is_visible ? self::SYNC_MODE_SYNC_AND_SHOW : self::SYNC_MODE_SYNC_AND_HIDE;
		}

		return self::SYNC_MODE_SYNC_DISABLED;
	}

	/**
	 * Processes and sanitizes POST data for variation.
	 *
	 * @param int $index the variation index
	 * @return array the processed variation data
	 */
	private function process_variation_post_data( $index ) {
		$posted_param = 'variable_' . \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- Intentionally getting raw value to apply different sanitization methods below, nonce verification handled in save_product_variation_edit_fields method
		$description_raw = isset( $_POST[ $posted_param ][ $index ] ) ? wp_unslash( $_POST[ $posted_param ][ $index ] ) : null;

		// Create separate sanitized versions for different purposes
		$description_plain = $description_raw ? sanitize_text_field( $description_raw ) : null; // Plain text for regular description
		$description_rich  = $description_raw ? wp_kses_post( $description_raw ) : null; // HTML-preserved for rich text description

		$posted_param = 'variable_' . \WC_Facebook_Product::FB_MPN;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$fb_mpn       = isset( $_POST[ $posted_param ][ $index ] ) ? sanitize_text_field( wp_unslash( $_POST[ $posted_param ][ $index ] ) ) : null;
		$posted_param = 'variable_fb_product_image_source';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$image_source = isset( $_POST[ $posted_param ][ $index ] ) ? sanitize_key( wp_unslash( $_POST[ $posted_param ][ $index ] ) ) : '';
		$posted_param = 'variable_' . \WC_Facebook_Product::FB_PRODUCT_IMAGE;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$image_url    = isset( $_POST[ $posted_param ][ $index ] ) ? esc_url_raw( wp_unslash( $_POST[ $posted_param ][ $index ] ) ) : null;
		$posted_param = 'variable_fb_product_video_source';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$video_source = isset( $_POST[ $posted_param ][ $index ] ) ? sanitize_key( wp_unslash( $_POST[ $posted_param ][ $index ] ) ) : '';
		// Use the same pattern as FB_PRODUCT_IMAGES - flat key with index appended
		$posted_param = 'variable_' . \WC_Facebook_Product::FB_PRODUCT_VIDEO . $index;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$video_ids_raw = isset( $_POST[ $posted_param ] ) ? sanitize_text_field( wp_unslash( $_POST[ $posted_param ] ) ) : '';
		// Convert attachment IDs to URLs (same logic as WC_Facebook_Product::set_product_video_urls)
		if ( ! empty( $video_ids_raw ) ) {
			$video_urls_array = array_filter(
				array_map(
					function ( $id ) {
						$id = trim( $id );
						return $id ? wp_get_attachment_url( $id ) : false;
					},
					explode( ',', $video_ids_raw )
				)
			);
			$video_urls       = $video_urls_array; // Store as array like simple products do
		} else {
			$video_urls = array();
		}
		$posted_param = 'variable_' . \WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$custom_video_url_raw = isset( $_POST[ $posted_param ][ $index ] ) ? esc_url_raw( wp_unslash( $_POST[ $posted_param ][ $index ] ) ) : null;
		// Validate the custom video URL if provided
		$custom_video_url = null;
		if ( ! empty( $custom_video_url_raw ) ) {
			$custom_video_url_raw = trim( $custom_video_url_raw );
			// Only save if it's a valid URL
			if ( filter_var( $custom_video_url_raw, FILTER_VALIDATE_URL ) ) {
				$custom_video_url = $custom_video_url_raw;
			}
		}
		// Fix: Look for the actual POST key format that WooCommerce generates
		$posted_param = 'variable_' . \WC_Facebook_Product::FB_PRODUCT_IMAGES . $index;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$image_ids    = isset( $_POST[ $posted_param ] ) ? sanitize_text_field( wp_unslash( $_POST[ $posted_param ] ) ) : '';
		$posted_param = 'variable_' . \WC_Facebook_Product::FB_PRODUCT_PRICE;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is handled in save_product_variation_edit_fields method
		$price = isset( $_POST[ $posted_param ][ $index ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ $posted_param ][ $index ] ) ) ) : '';

		return array(
			'description_plain' => $description_plain,
			'description_rich'  => $description_rich,
			'fb_mpn'            => $fb_mpn,
			'image_source'      => $image_source,
			'image_url'         => $image_url,
			'video_source'      => $video_source,
			'video_urls'        => $video_urls,
			'custom_video_url'  => $custom_video_url,
			'image_ids'         => $image_ids,
			'price'             => $price,
		);
	}

	/**
	 * Saves the variation meta data.
	 *
	 * @param \WC_Product_Variation $variation the product variation
	 * @param array                 $data the variation data to save
	 */
	private function save_variation_meta_data( $variation, $data ) {
		$variation->update_meta_data( \WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION, $data['description_plain'] );
		$variation->update_meta_data( \WC_Facebookcommerce_Integration::FB_RICH_TEXT_DESCRIPTION, $data['description_rich'] );
		$variation->update_meta_data( Products::PRODUCT_IMAGE_SOURCE_META_KEY, $data['image_source'] );
		$variation->update_meta_data( \WC_Facebook_Product::FB_MPN, $data['fb_mpn'] );
		$variation->update_meta_data( \WC_Facebook_Product::FB_PRODUCT_IMAGE, $data['image_url'] );
		$variation->update_meta_data( Products::PRODUCT_VIDEO_SOURCE_META_KEY, $data['video_source'] );
		$variation->update_meta_data( \WC_Facebook_Product::FB_PRODUCT_VIDEO, $data['video_urls'] );
		$variation->update_meta_data( \WC_Facebook_Product::FB_PRODUCT_VIDEO . '_custom_url', $data['custom_video_url'] );
		$variation->update_meta_data( \WC_Facebook_Product::FB_PRODUCT_IMAGES, $data['image_ids'] );
		$variation->update_meta_data( \WC_Facebook_Product::FB_PRODUCT_PRICE, $data['price'] );
		$variation->save_meta_data();
	}

	/**
	 * Handles sync operations for the variation.
	 *
	 * @param \WC_Product_Variation $variation the product variation
	 * @param bool                  $sync_enabled whether sync is enabled
	 * @param string                $sync_mode the sync mode
	 */
	private function handle_variation_sync_operations( $variation, $sync_enabled, $sync_mode ) {
		if ( $sync_enabled ) {
			Products::enable_sync_for_products( array( $variation ) );
			Products::set_product_visibility( $variation, self::SYNC_MODE_SYNC_AND_HIDE !== $sync_mode );
		} else {
			Products::disable_sync_for_products( array( $variation ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}


	/**
	 * Outputs a modal template in admin product pages.
	 *
	 * @internal
	 *
	 * @since 1.10.0
	 */
	public function render_modal_template() {
		global $current_screen;

		// bail if not on the products, product edit, or settings screen
		if ( ! $current_screen || ! in_array( $current_screen->id, $this->screen_ids, true ) ) {
			return;
		}
		?>
		<script type="text/template" id="tmpl-facebook-for-woocommerce-modal">
			<div class="wc-backbone-modal facebook-for-woocommerce-modal">
				<div class="wc-backbone-modal-content">
					<section class="wc-backbone-modal-main" role="main">
						<header class="wc-backbone-modal-header">
							<h1><?php esc_html_e( 'Meta for WooCommerce', 'facebook-for-woocommerce' ); ?></h1>
							<button class="modal-close modal-close-link dashicons dashicons-no-alt">
								<span class="screen-reader-text"><?php esc_html_e( 'Close modal panel', 'facebook-for-woocommerce' ); ?></span>
							</button>
						</header>
						<article>{{{data.message}}}</article>
						<footer>
							<div class="inner">{{{data.buttons}}}</div>
						</footer>
					</section>
				</div>
			</div>
			<div class="wc-backbone-modal-backdrop modal-close"></div>
		</script>
		<?php
	}

	public function add_tab_switch_script() {
		global $post;
		if ( ! $post || get_post_type( $post ) !== 'product' ) {
			return;
		}
		?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {

				// State object to track badge display status
				var syncedBadgeState = {
					material: false,
					color: false,
					size: false,
					pattern: false,
					brand: false,
					mpn: false,
					age_group: false,
					gender: false,
					condition: false
				};

				// Store manual input values
				var manualValues = {};

				// Track which fields are currently synced
				var syncedFields = {};

				// Helper function to clean up any previous sync UI elements
				function cleanupSyncedField(fieldId) {
					var $field = $(fieldId);

					// First find all multi-value displays and sync indicators in the parent wrapper
					var $parent = $field.parent();
					$parent.find('.multi-value-display').remove();
					$parent.find('.sync-indicator').remove();

					// Also remove any elements directly after the field
					$field.next('.multi-value-display').remove();
					$field.next('.sync-indicator').remove();

					// Double check for elements with specific classes anywhere in the row
					var $row = $parent.closest('.form-field, .form-row');
					if ($row.length) {
						$row.find('.multi-value-display').remove();
						$row.find('.sync-indicator').remove();
					}

					// Show the original field if it was hidden
					$field.show();

					// Reset the field state
					$field.prop('disabled', false).removeClass('synced-attribute');
				}

				// Function to completely reset a field to its default state
				function resetFieldToDefault(fieldId) {
					var $field = $(fieldId);

					// Skip if field doesn't exist
					if (!$field.length) {
						return;
					}

					// Clean up UI elements
					cleanupSyncedField(fieldId);

					// Reset select fields to first option (usually "Select")
					if ($field.is('select')) {
						// Check if the select has options
						if ($field.find('option').length > 0) {
							$field.val('').trigger('change');
							$field.find('option:first').prop('selected', true);
						}

						// Reset select2 if it's initialized
						if ($field.hasClass('wc-enhanced-select') || $field.hasClass('select2-hidden-accessible')) {
							try {
								$field.select2('val', '');
								// Also reset the select2 container styles
								$field.next('.select2-container').find('.select2-selection').css({
									'cursor': '',
									'background-color': '',
									'color': ''
								});
							} catch (e) {
								// Ignore select2 errors
							}
						}
					}

					// Reset all styles and classes
					$field
						.val('')
						.prop('disabled', false)
						.removeClass('synced-attribute')
						.css({
							'cursor': '',
							'background-color': '',
							'color': '',
							'border-color': '',
							'opacity': ''
						})
						.show();

					// Also reset any select2 container if it exists
					if ($field.next('.select2-container').length) {
						$field.next('.select2-container').css({
							'cursor': '',
							'opacity': ''
						});
					}
				}

				// Function to sync Facebook attributes
				function syncFacebookAttributes() {
					// First clean up any stray elements that might exist globally
					$('.multi-value-display + .wc-attributes-icon').remove();
					$('.woocommerce_options_panel').find('.multi-value-display, .wc-attributes-icon').each(function() {
						// Only remove elements that are duplicates (more than one per field)
						var $parent = $(this).parent();
						var $siblings = $parent.find('.' + $(this).attr('class'));
						if ($siblings.length > 1) {
							// Keep only the first one, remove others
							$siblings.not(':first').remove();
						}
					});

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'sync_facebook_attributes',
							product_id: <?php echo esc_js( $post->ID ); ?>,
							nonce: '<?php echo esc_js( wp_create_nonce( 'sync_facebook_attributes' ) ); ?>'
						},
						success: function(response) {
							if (response.success) {
								var fields = {
									'material': '<?php echo esc_js( \WC_Facebook_Product::FB_MATERIAL ); ?>',
									'color': '<?php echo esc_js( \WC_Facebook_Product::FB_COLOR ); ?>',
									'size': '<?php echo esc_js( \WC_Facebook_Product::FB_SIZE ); ?>',
									'pattern': '<?php echo esc_js( \WC_Facebook_Product::FB_PATTERN ); ?>',
									'brand': '<?php echo esc_js( \WC_Facebook_Product::FB_BRAND ); ?>',
									'mpn': '<?php echo esc_js( \WC_Facebook_Product::FB_MPN ); ?>',
									'age_group': '<?php echo esc_js( \WC_Facebook_Product::FB_AGE_GROUP ); ?>',
									'gender': '<?php echo esc_js( \WC_Facebook_Product::FB_GENDER ); ?>',
									'condition': '<?php echo esc_js( \WC_Facebook_Product::FB_PRODUCT_CONDITION ); ?>'
								};

								// Loop through each field
								Object.keys(fields).forEach(function(key) {
									var fieldId = '#' + fields[key];
									var $field = $(fieldId);

									// Skip if field doesn't exist
									if (!$field.length) {
										return;
									}

									// First thoroughly clean up any previous sync UI elements
									cleanupSyncedField(fieldId);

									if (response.data && response.data[key]) {
										// Field has a synced value
										var syncedValue = response.data[key];
										var isMultipleValues = syncedValue.includes(' | ');

										// For fields with multiple values or dropdown fields that need special handling
										if (isMultipleValues || (key === 'age_group' || key === 'gender' || key === 'condition')) {
											// First check if this is a standard dropdown or a multi-value field
											if (isMultipleValues && ($field.is('select') || key === 'age_group' || key === 'gender' || key === 'condition')) {
												// Check if we already have a multi-value display for this field
												if ($field.next('.multi-value-display').length === 0) {
													// For dropdown fields with multiple values (used in variations)
													// Disable the original dropdown
													$field.prop('disabled', true).addClass('synced-attribute').hide();

													// Create a styled disabled field to show multiple values
													var fieldWidth = $field.outerWidth();
													var $multiDisplay = $('<input type="text" class="multi-value-display wc-enhanced-select" disabled>')
														.val(syncedValue)
														.css({
															'width': '50%',
															'max-width': '100%',
															'height': '34px',
															'margin': '0',
															'padding': '0 8px',
															'background-color': '#f0f0f1',
															'border': '1px solid #ddd',
															'border-radius': '4px',
															'box-sizing': 'border-box',
															'font-size': '14px',
															'line-height': '32px',
															'color': 'rgba(44, 51, 56, .5)',
															'display': 'inline-block',
															'vertical-align': 'middle',
															'cursor': 'not-allowed'
														})
														.insertAfter($field);

													// Always add the sync badge after the multi-value display
													// Only if it doesn't already exist
													if ($multiDisplay.next('.wc-attributes-icon').length === 0) {
														$multiDisplay.after('<span class="wc-attributes-icon" data-tip="Synced from product attributes"></span>');
													}
												} else {
													// Update the existing multi-value display
													$field.next('.multi-value-display').val(syncedValue);
												}
											// If this is a dropdown field like gender, age_group, or condition with a single mapped value,
											// select that value in the dropdown and disable it
											} else if (key === 'age_group' || key === 'gender' || key === 'condition') {
												// First check if this dropdown has a corresponding value
												var hasMatchingOption = false;
												var matchingOptionValue = '';
												$field.find('option').each(function() {
													var optionValue = $(this).val();
													var optionText = $(this).text();

													// Check both option value and option text (case-insensitive)
													if (optionValue === syncedValue ||
														optionText.toLowerCase() === syncedValue.toLowerCase() ||
														optionValue.toLowerCase() === syncedValue.toLowerCase()) {
														hasMatchingOption = true;
														matchingOptionValue = optionValue; // Use the actual option value, not the synced value
														return false; // break loop
													}
												});

												if (hasMatchingOption) {
													$field.val(matchingOptionValue) // Use the correct option value
														.prop('disabled', true)
														.addClass('synced-attribute')
														.css({
															'cursor': 'not-allowed',
															'background-color': '#f0f0f1',
															'color': 'rgba(44, 51, 56, .5)'
														});

													// Add the sync badge if it doesn't exist
													if ($field.next('.wc-attributes-icon').length === 0) {
														$field.after('<span class="sync-indicator wc-attributes-icon" data-tip="Synced from the Attributes tab." style="margin-left: 4px;"><span class="sync-tooltip">Synced from the Attributes tab.</span></span>');
													}
												} else if (isMultipleValues) {
													// This is a multi-value field but not a dropdown
													$field.prop('disabled', true).addClass('synced-attribute').hide();

													// Create a styled disabled field to show multiple values
													var $multiDisplay = $('<input type="text" class="multi-value-display" disabled>')
														.val(syncedValue)
														.insertAfter($field);

													// Add the sync badge
													$multiDisplay.after('<span class="wc-attributes-icon" data-tip="Synced from product attributes"></span>');
												}
											} else if (isMultipleValues) {
												// For non-dropdown multi-value fields
												$field.val(syncedValue)
													.prop('disabled', true)
													.addClass('synced-attribute')
													.css({
														'cursor': 'not-allowed',
														'background-color': '#f0f0f1',
														'color': 'rgba(44, 51, 56, .5)'
													})
													.show();

												// Add the sync badge if it doesn't exist
												if ($field.next('.wc-attributes-icon').length === 0) {
													$field.after('<span class="sync-indicator wc-attributes-icon" data-tip="Synced from the Attributes tab." style="margin-left: 4px;"><span class="sync-tooltip">Synced from the Attributes tab.</span></span>');
												}
											} else {
												// Single value fields that are dropdowns (age_group, gender, condition)
												$field.val(syncedValue)
													.prop('disabled', true)
													.addClass('synced-attribute')
													.css({
														'cursor': 'not-allowed',
														'background-color': '#f0f0f1',
														'color': 'rgba(44, 51, 56, .5)'
													})
													.show();

												// Add the sync badge if it doesn't exist
												if ($field.next('.wc-attributes-icon').length === 0) {
													$field.after('<span class="sync-indicator wc-attributes-icon" data-tip="Synced from the Attributes tab." style="margin-left: 4px;"><span class="sync-tooltip">Synced from the Attributes tab.</span></span>');
												}
											}
										} else {
											// Standard fields with single values
											$field.val(syncedValue)
												.prop('disabled', true)
												.addClass('synced-attribute')
												.css({
													'cursor': 'not-allowed',
													'background-color': '#f0f0f1',
													'color': 'rgba(44, 51, 56, .5)'
												})
												.show();

											// Add the sync badge if it doesn't exist
											if ($field.next('.wc-attributes-icon').length === 0) {
												$field.after('<span class="sync-indicator wc-attributes-icon" data-tip="Synced from the Attributes tab." style="margin-left: 4px;"><span class="sync-tooltip">Synced from the Attributes tab.</span></span>');
											}
										}

										// Mark this field as synced
										syncedFields[key] = true;
										syncedBadgeState[key] = true;
									} else {
										// If this field was previously synced but now isn't
										if (syncedFields[key]) {
											// Reset synced state
											syncedFields[key] = false;

											// Completely reset the field value
											resetFieldToDefault(fieldId);
										} else if (manualValues[key] && !$field.val()) {
											// Restore manual value if field is empty
											$field.val(manualValues[key]);
										}

										// Reset the badge state
										syncedBadgeState[key] = false;
									}
								});
							}
						}
					});
				}

				// Function to completely reset all fields after attribute removal
				function resetAllFields() {
					var fields = {
						'material': '<?php echo esc_js( \WC_Facebook_Product::FB_MATERIAL ); ?>',
						'color': '<?php echo esc_js( \WC_Facebook_Product::FB_COLOR ); ?>',
						'size': '<?php echo esc_js( \WC_Facebook_Product::FB_SIZE ); ?>',
						'pattern': '<?php echo esc_js( \WC_Facebook_Product::FB_PATTERN ); ?>',
						'brand': '<?php echo esc_js( \WC_Facebook_Product::FB_BRAND ); ?>',
						'mpn': '<?php echo esc_js( \WC_Facebook_Product::FB_MPN ); ?>',
						'age_group': '<?php echo esc_js( \WC_Facebook_Product::FB_AGE_GROUP ); ?>',
						'gender': '<?php echo esc_js( \WC_Facebook_Product::FB_GENDER ); ?>',
						'condition': '<?php echo esc_js( \WC_Facebook_Product::FB_PRODUCT_CONDITION ); ?>'
					};

					Object.keys(fields).forEach(function(key) {
						var fieldId = '#' + fields[key];
						resetFieldToDefault(fieldId);
						syncedFields[key] = false;
						syncedBadgeState[key] = false;
					});
				}

				// Store manual input values for text fields
				$('.woocommerce_options_panel input[type="text"]').on('input', function() {
					var fieldId = $(this).attr('id');
					for (var key in syncedBadgeState) {
						if (fieldId && fieldId.includes(key)) {
							manualValues[key] = $(this).val();
							// When manually entering a value, mark as not synced
							syncedFields[key] = false;
						}
					}
				});

				// Store manual selection values for select fields
				$('.woocommerce_options_panel select').on('change', function() {
					var fieldId = $(this).attr('id');
					for (var key in syncedBadgeState) {
						if (fieldId && fieldId.includes(key)) {
							manualValues[key] = $(this).val();
							// When manually selecting a value, mark as not synced
							syncedFields[key] = false;
						}
					}
				});

				// Listen for attribute removal
				$('.product_data_tabs').on('click', '.remove_row', function(e) {
					// Store information about which row was removed
					var $removedRow = $(this).closest('tr');
					var attributeName = $removedRow.find('td.attribute_name').text().trim().toLowerCase();

					// Wait a brief moment for WooCommerce to remove the attribute
					setTimeout(function() {
						// Clean up any extra UI elements that might be leftover
						$('.woocommerce_options_panel').find('.multi-value-display').each(function() {
							// For each multi-value display, check if there's a corresponding select field
							var $this = $(this);
							var $select = $this.prev('select');

							// If no select exists or the select has no options, remove the multi-value display
							if ($select.length === 0 || $select.find('option').length <= 1) {
								$this.next('.sync-indicator').remove();
								$this.remove();
							}
						});

						// Only trigger if we're on the Facebook tab
						if ($('.fb_commerce_tab').hasClass('active')) {
							// First reset all fields to ensure dropdowns are cleared
							resetAllFields();

							// Then perform a complete cleanup of all UI elements
							$('.woocommerce_options_panel').find('.multi-value-display, .sync-indicator').remove();

							// Re-check all select fields for emptiness
							$('.woocommerce_options_panel select').each(function() {
								if ($(this).find('option').length <= 1) {
									// Reset to first option for empty selects
									$(this).val('').prop('selected', true);
									// Make sure it's visible and enabled
									$(this).show().prop('disabled', false).removeClass('synced-attribute');
								}
							});

							// Then sync to update based on remaining attributes
							syncFacebookAttributes();
						}
					}, 300); // Increased timeout to ensure WooCommerce has fully removed the attribute
				});

				// Listen for attribute saves
				$(document).on('click', 'button.save_attributes', function() {
					// Store reference to the button and attributes panel
					var $button = $(this);
					var $attributesPanel = $('#product_attributes');

					// Wait a brief moment for WooCommerce to save the attributes
					setTimeout(function() {
						// Perform cleanup of any stray elements across the entire form
						$('.woocommerce_options_panel').find('.multi-value-display, .sync-indicator').each(function() {
							var $element = $(this);
							var $prevSelect = $element.prev('select');

							// If this is a multi-value display without a valid select, remove it
							if ($element.hasClass('multi-value-display') &&
								(!$prevSelect.length || $prevSelect.find('option').length <= 1 || !$prevSelect.is(':visible'))) {
								$element.next('.sync-indicator').remove();
								$element.remove();
							}

							// If this is a sync indicator without a valid field before it, remove it
							if ($element.hasClass('sync-indicator') &&
								(!$element.prev().length ||
								($element.prev().is('select') && $element.prev().find('option').length <= 1))) {
								$element.remove();
							}
						});

						// Re-check all select fields
						$('.woocommerce_options_panel select').each(function() {
							var $select = $(this);

							// Check for empty or nearly empty selects
							if ($select.find('option').length <= 1) {
								// Clean up any associated UI elements
								$select.next('.multi-value-display').next('.sync-indicator').remove();
								$select.next('.multi-value-display').remove();
								$select.next('.sync-indicator').remove();

								// Reset the select
								$select.val('').prop('selected', true)
									.show().prop('disabled', false).removeClass('synced-attribute');
							}
						});

						// Only trigger if we're on the Facebook tab
						if ($('.fb_commerce_tab').hasClass('active')) {
							syncFacebookAttributes();
						}
					}, 500);
				});

				// Function to clean up all UI elements and empty dropdowns
				function cleanupAllUIElements() {
					// Remove all multi-value displays and sync indicators
					$('.woocommerce_options_panel').find('.multi-value-display, .sync-indicator').remove();

					// Reset all select fields
					$('.woocommerce_options_panel select').each(function() {
						var $select = $(this);
						$select.show().prop('disabled', false).removeClass('synced-attribute');

						// If the select has no options ensure it's properly reset
						if ($select.find('option').length < 1) {
							$select.val('').prop('selected', true);
						}

						// Reset select2 if applicable
						if ($select.hasClass('wc-enhanced-select') || $select.hasClass('select2-hidden-accessible')) {
							try {
								$select.select2('val', '');
							} catch (e) {
								// Ignore select2 errors
							}
						}
					});

					// Reset all text inputs styling
					$('.woocommerce_options_panel input[type="text"]').each(function() {
						var $input = $(this);
						if ($input.hasClass('multi-value-display')) {
							$input.remove();
							return;
						}
						$input.show().prop('disabled', false).removeClass('synced-attribute');
					});
				}

				// Original tab click handler
				$('.product_data_tabs li').on('click', function() {
					var tabClass = $(this).attr('class');

					// If we're clicking on a tab that isn't the Facebook tab,
					// clean up all UI elements first
					if (!tabClass || !tabClass.includes('fb_commerce_tab')) {
						cleanupAllUIElements();
					} else if (tabClass && tabClass.includes('fb_commerce_tab')) {
						// If we're clicking on the Facebook tab
						// First clean up any previous UI elements
						cleanupAllUIElements();
						// Then sync to get the latest data
						syncFacebookAttributes();
					}
				});

				// Reset badge states when leaving the Facebook tab
				$('.product_data_tabs li').not('.fb_commerce_tab').on('click', function() {
					Object.keys(syncedBadgeState).forEach(function(key) {
						syncedBadgeState[key] = false;
					});
				});

				// Initial store of values
				Object.keys(syncedBadgeState).forEach(function(key) {
					var fieldId = '#fb_' + key;
					if (key === 'age_group') fieldId = '#' + '<?php echo esc_js( \WC_Facebook_Product::FB_AGE_GROUP ); ?>';
					if (key === 'gender') fieldId = '#' + '<?php echo esc_js( \WC_Facebook_Product::FB_GENDER ); ?>';
					if (key === 'condition') fieldId = '#' + '<?php echo esc_js( \WC_Facebook_Product::FB_PRODUCT_CONDITION ); ?>';

					var $field = $(fieldId);
					var value = $field.val();
					if (value && !$field.hasClass('synced-attribute')) {
						manualValues[key] = value;
					}
				});

				// When the page loads, immediately sync if we're on the Facebook tab
				if ($('.fb_commerce_tab').hasClass('active')) {
					syncFacebookAttributes();
				}
			});
		</script>
		<?php
	}

	public function sync_product_attributes( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [];
		}

		// Use the ProductAttributeMapper to get mapped attributes
		if ( class_exists( '\WooCommerce\Facebook\ProductAttributeMapper' ) ) {
			$mapped_attributes = \WooCommerce\Facebook\ProductAttributeMapper::get_and_save_mapped_attributes( $product );
			return $mapped_attributes;
		}

		// Fallback to old method if ProductAttributeMapper is not available
		$attributes      = $product->get_attributes();
		$facebook_fields = [];

		$attribute_map = [
			'material'  => \WC_Facebook_Product::FB_MATERIAL,
			'color'     => \WC_Facebook_Product::FB_COLOR,
			'colour'    => \WC_Facebook_Product::FB_COLOR, // Add support for British spelling
			'size'      => \WC_Facebook_Product::FB_SIZE,
			'pattern'   => \WC_Facebook_Product::FB_PATTERN,
			'brand'     => \WC_Facebook_Product::FB_BRAND,
			'mpn'       => \WC_Facebook_Product::FB_MPN,
			'age_group' => \WC_Facebook_Product::FB_AGE_GROUP,
			'gender'    => \WC_Facebook_Product::FB_GENDER,
			'condition' => \WC_Facebook_Product::FB_PRODUCT_CONDITION,
		];

		// Dropdown-based attributes that should match specific values
		$dropdown_attrs = [
			'age_group' => [
				\WC_Facebook_Product::AGE_GROUP_ADULT,
				\WC_Facebook_Product::AGE_GROUP_ALL_AGES,
				\WC_Facebook_Product::AGE_GROUP_TEEN,
				\WC_Facebook_Product::AGE_GROUP_KIDS,
				\WC_Facebook_Product::AGE_GROUP_TODDLER,
				\WC_Facebook_Product::AGE_GROUP_INFANT,
				\WC_Facebook_Product::AGE_GROUP_NEWBORN,
			],
			'gender'    => [
				\WC_Facebook_Product::GENDER_MALE,
				\WC_Facebook_Product::GENDER_FEMALE,
				\WC_Facebook_Product::GENDER_UNISEX,
			],
			'condition' => [
				\WC_Facebook_Product::CONDITION_NEW,
				\WC_Facebook_Product::CONDITION_USED,
				\WC_Facebook_Product::CONDITION_REFURBISHED,
			],
		];

		// Process all attributes and track which have been processed
		$processed_fields = [];

		foreach ( $attributes as $attribute ) {
			// Get all possible variations of the attribute name for matching
			$raw_name             = $attribute->get_name();
			$clean_name           = str_replace( 'pa_', '', $raw_name );
			$normalized_attr_name = strtolower( $clean_name );
			$attribute_label      = wc_attribute_label( $raw_name );
			$normalized_label     = strtolower( $attribute_label );

			// Create variations for more flexible matching
			$name_variations = [
				$normalized_attr_name,
				$normalized_label,
				str_replace( [ '_', ' ', '-' ], '', $normalized_attr_name ),
				str_replace( [ '_', ' ', '-' ], '', $normalized_label ),
			];

			// Find matching Facebook field
			$matched_facebook_field = null;
			$field_name             = null;

			// Look for matches in attribute map
			foreach ( $attribute_map as $fb_attr_name => $fb_meta_key ) {
				$fb_variations = [
					$fb_attr_name,
					str_replace( [ '_', ' ', '-' ], '', $fb_attr_name ),
				];

				// Check for any variation match
				$matched = false;
				foreach ( $name_variations as $name_var ) {
					foreach ( $fb_variations as $fb_var ) {
						if ( $name_var === $fb_var ) {
							$matched                = true;
							$matched_facebook_field = $fb_meta_key;
							$field_name             = $fb_attr_name;
							break 2;
						}
					}
				}

				if ( $matched ) {
					break;
				}
			}

			// Special case for color/colour conversion
			if ( 'colour' === $field_name ) {
				$field_name = 'color';
			}

			// If we found a match and haven't processed this field yet
			if ( $matched_facebook_field && ! in_array( $field_name, $processed_fields, true ) ) {
				$values = [];

				if ( is_object( $attribute ) && method_exists( $attribute, 'is_taxonomy' ) && $attribute->is_taxonomy() ) {
					$terms = $attribute->get_terms();
					if ( $terms && ! is_wp_error( $terms ) ) {
						$values = wp_list_pluck( $terms, 'name' );
					}
				} else {
					$values = $attribute->get_options();
				}

				if ( ! empty( $values ) ) {
					// For dropdown attributes, validate against allowed values
					if ( array_key_exists( $field_name, $dropdown_attrs ) ) {
						$valid_values = [];

						foreach ( $values as $value ) {
							$normalized_value = strtolower( trim( $value ) );

							foreach ( $dropdown_attrs[ $field_name ] as $allowed_value ) {
								if ( strtolower( $allowed_value ) === $normalized_value ) {
									$valid_values[] = $allowed_value;
									break;
								}
							}
						}

						if ( ! empty( $valid_values ) ) {
							$joined_values                  = implode( ' | ', $valid_values );
							$facebook_fields[ $field_name ] = $joined_values;
							update_post_meta( $product_id, $matched_facebook_field, $joined_values );
						} else {
							delete_post_meta( $product_id, $matched_facebook_field );
							$facebook_fields[ $field_name ] = '';
						}
					} else {
						// Regular attributes - join multiple values with a pipe character and spaces
						$joined_values                  = implode( ' | ', $values );
						$facebook_fields[ $field_name ] = $joined_values;
						update_post_meta( $product_id, $matched_facebook_field, $joined_values );
					}
				} else {
					delete_post_meta( $product_id, $matched_facebook_field );
					$facebook_fields[ $field_name ] = '';
				}

				// Mark this field as processed
				$processed_fields[] = $field_name;
			}
		}

		return $facebook_fields;
	}

	public function ajax_sync_facebook_attributes() {
		check_ajax_referer( 'sync_facebook_attributes', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? intval( $_POST['product_id'] ) : 0;
		if ( $product_id ) {
			$synced_fields = $this->sync_product_attributes( $product_id );
			wp_send_json_success( $synced_fields );
		}
		wp_send_json_error( 'Invalid product ID' );
	}

	/**
	 * Conditionally displays the WordPress.com update notice.
	 *
	 * Registered as the admin_notices callback so the check runs after admin_init,
	 * which is where the dismiss handler saves user meta. Evaluating in the
	 * constructor would cause the notice to flash once on the dismiss redirect page.
	 *
	 * @since 3.5.3
	 *
	 * @return void
	 */
	public function maybe_show_wpcom_update_notice(): void {
		if ( $this->should_show_wpcom_update_notice() ) {
			$this->wpcom_update_notice();
		}
	}

	/**
	 * Determines whether the WordPress.com update notice should be shown.
	 *
	 * @since 3.5.3
	 *
	 * @return bool Whether the WordPress.com update notice should be shown.
	 */
	public function should_show_wpcom_update_notice() {
		if ( 1 !== (int) get_option( 'is_wordpress_com_hosted' ) ) {
			return false;
		}

		return ! get_user_meta(
			get_current_user_id(),
			self::ADMIN_DISMISS_WPCOM_UPDATE_NOTICE,
			true
		);
	}

	/**
	 * Returns the WordPress.com update notice message.
	 *
	 * @since 3.5.3
	 *
	 * @return string The WordPress.com update notice message.
	 */
	public function get_wpcom_update_notice_message() {
		$plugin_url = 'https://wordpress.org/plugins/facebook-for-woocommerce/';
		return sprintf(
			/* translators: %s: WordPress.org plugin page URL */
			__(
				'Meta for WooCommerce is expected to be removed from the WordPress.com marketplace soon. When this happens, it will no longer receive automatic updates on WordPress.com-hosted websites. <a href="%s">Download the latest version from WordPress.org</a> and install it manually via <strong>Plugins</strong> &rsaquo; <strong>Add New Plugin</strong> &rsaquo; <strong>Upload Plugin</strong> to ensure you receive future updates.',
				'facebook-for-woocommerce'
			),
			esc_url( $plugin_url )
		);
	}

	/**
	 * Displays a notice about WordPress.com automatic updates ending.
	 *
	 * The dismiss control is a plain link that triggers a page reload with a GET parameter,
	 * ensuring the dismissal is saved server-side before the notice re-renders. It is rendered
	 * as an anchor so the dismiss URL lives in an href attribute, which is the correct output
	 * context for esc_url().
	 *
	 * The .notice-dismiss class is preserved so WordPress core does not inject a second,
	 * client-only dismiss button onto the notice.
	 *
	 * @since 3.5.3
	 *
	 * @return void
	 */
	public function wpcom_update_notice() {
		printf(
			'
<div class="notice notice-warning is-dismissible">
	<p>%1$s</p>
	<a
		href="%2$s"
		class="notice-dismiss"
		style="text-decoration: none;">
		<span class="screen-reader-text">%3$s</span>
	</a>
</div>
			',
			wp_kses_post( $this->get_wpcom_update_notice_message() ),
			esc_url( add_query_arg( self::ADMIN_DISMISS_WPCOM_UPDATE_NOTICE, '1' ) ),
			esc_html__( 'Dismiss this notice.', 'facebook-for-woocommerce' )
		);
	}

	/**
	 * Handles the dismiss action for the WordPress.com update notice.
	 *
	 * @since 3.5.3
	 *
	 * @return void
	 */
	public function handle_wpcom_update_notice_dismiss() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::ADMIN_DISMISS_WPCOM_UPDATE_NOTICE ] ) ) {
			return;
		}

		update_user_meta(
			get_current_user_id(),
			self::ADMIN_DISMISS_WPCOM_UPDATE_NOTICE,
			1
		);
	}
}
