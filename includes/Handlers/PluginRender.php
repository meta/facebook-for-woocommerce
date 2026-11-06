<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Handlers;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Framework\Plugin\Compatibility;
use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Framework\Plugin\Exception;

/**
 * PluginRender
 * This is an class that is triggered for Opt in/ Opt out experience
 * from @ver 3.5.2
 */
class PluginRender {
	/** @var object storing plugin object */
	private \WC_Facebookcommerce $plugin;

	/** @var string opt out plugin version action */
	const ALL_PRODUCTS_PLUGIN_VERSION = '3.5.3';

	/** @var string opt out sync action */
	const ACTION_OPT_OUT_OF_SYNC = 'wc_facebook_opt_out_of_sync';

	/** @var string opt out sync action */
	const ACTION_SYNC_BACK_IN = 'wc_facebook_sync_back_in';

	/** @var string master sync option */
	const MASTER_SYNC_OPT_OUT_TIME = 'wc_facebook_master_sync_opt_out_time';

	/** @var string  action */
	const ACTION_CLOSE_BANNER = 'wc_banner_close_action';

	/** @var string  product set banner closed action */
	const ACTION_PRODUCT_SET_BANNER_CLOSED = 'wc_facebook_product_set_banner_closed';

	public function __construct( \WC_Facebookcommerce $plugin ) {
		$this->plugin = $plugin;
		$this->should_show_banners();
		$this->add_hooks();
	}

	public static function enqueue_assets() {
		wp_enqueue_script( 'wc-backbone-modal', null, array( 'backbone' ), \WC_Facebookcommerce::PLUGIN_VERSION, true );
		wp_enqueue_script(
			'facebook-for-woocommerce-modal',
			facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/modal.js',
			array( 'jquery', 'wc-backbone-modal', 'jquery-blockui' ),
			\WC_Facebookcommerce::PLUGIN_VERSION,
			true
		);
		wp_enqueue_script(
			'facebook-for-woocommerce-plugin-update',
			facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/plugin-rendering.js',
			array( 'jquery', 'wc-backbone-modal', 'jquery-blockui', 'jquery-tiptip', 'facebook-for-woocommerce-modal', 'wc-enhanced-select' ),
			\WC_Facebookcommerce::PLUGIN_VERSION,
			true
		);
		wp_localize_script(
			'facebook-for-woocommerce-plugin-update',
			'facebook_for_woocommerce_plugin_update',
			array(
				'ajax_url'                        => admin_url( 'admin-ajax.php' ),
				'opt_out_of_sync'                 => wp_create_nonce( self::ACTION_OPT_OUT_OF_SYNC ),
				'banner_close'                    => wp_create_nonce( self::ACTION_CLOSE_BANNER ),
				'sync_back_in'                    => wp_create_nonce( self::ACTION_SYNC_BACK_IN ),
				'product_set_banner_closed_nonce' => wp_create_nonce( self::ACTION_PRODUCT_SET_BANNER_CLOSED ),
				'sync_in_progress'                => Sync::is_sync_in_progress(),
				'opt_out_confirmation_message'    => self::get_opt_out_modal_message(),
				'opt_out_confirmation_buttons'    => self::get_opt_out_modal_buttons(),
			)
		);
	}

	private static function add_hooks() {
		add_action( 'admin_enqueue_scripts', [ __CLASS__,  'enqueue_assets' ] );
		add_action( 'wp_ajax_wc_facebook_opt_out_of_sync', [ __CLASS__,  'opt_out_of_sync_clicked' ] );
		add_action( 'wp_ajax_nopriv_wc_facebook_opt_out_of_sync', [ __CLASS__,'opt_out_of_sync_clicked' ] );
		add_action( 'wp_ajax_wc_banner_close_action', [ __CLASS__,  'reset_upcoming_version_banners' ] );
		add_action( 'wp_ajax_nopriv_wc_banner_close_action', [ __CLASS__,'reset_upcoming_version_banners' ] );
		add_action( 'wp_ajax_wc_facebook_sync_all_products', [ __CLASS__,  'sync_all_clicked' ] );
		add_action( 'wp_ajax_nopriv_wc_facebook_sync_all_products', [ __CLASS__,'sync_all_clicked' ] );
		add_action( 'wp_ajax_wc_banner_post_update_close_action', [ __CLASS__,  'reset_plugin_updated_successfully_banner' ] );
		add_action( 'wp_ajax_nopriv_wc_banner_post_update_close_action', [ __CLASS__,'reset_plugin_updated_successfully_banner' ] );
		add_action( 'wp_ajax_wc_banner_post_update__master_sync_off_close_action', [ __CLASS__,  'reset_plugin_updated_successfully_but_master_sync_off_banner' ] );
		add_action( 'wp_ajax_nopriv_wc_banner_post_update__master_sync_off_close_action', [ __CLASS__,'reset_plugin_updated_successfully_but_master_sync_off_banner' ] );
		add_action( 'wp_ajax_wc_facebook_product_set_banner_closed', [ __CLASS__,  'product_set_banner_closed' ] );
	}

	public function should_show_banners() {
		$current_version = $this->plugin->get_version();

		/**
		 * Case when current version is less or equal to latest
		 * but latest is below 3.5.1
		 * Should show the opt in/ opt out banner
		 */
		if ( version_compare( $current_version, self::ALL_PRODUCTS_PLUGIN_VERSION, '<' ) ) {
			if ( get_transient( 'upcoming_woo_all_products_banner_hide' ) ) {
				return;
			}
			add_action( 'admin_notices', [ __CLASS__, 'upcoming_woo_all_products_banner' ], 0, 1 );
		} else {
			add_action( 'admin_notices', [ __CLASS__, 'plugin_updated_banner' ], 0, 1 );
		}
	}

	public static function get_opt_out_time() {
		$option_value = get_option( self::MASTER_SYNC_OPT_OUT_TIME );
		if ( ! $option_value ) {
			return '';
		}
		return $option_value;
	}

	public static function is_master_sync_on() {
		$option_value = self::get_opt_out_time();
		return '' === $option_value;
	}

	public static function upcoming_woo_all_products_banner() {
		$screen = get_current_screen();

		if ( isset( $screen->id ) && 'marketing_page_wc-facebook' === $screen->id ) {
			echo '<div id="opt_out_banner" class="' . esc_html( self::get_opt_out_banner_class() ) . '">
            <h4>When you update to version <b>' . esc_html( self::ALL_PRODUCTS_PLUGIN_VERSION ) . '</b> your products will automatically sync to your catalog at Meta catalog</h4>
            The next time you update your Meta for WooCommerce plugin, all your products will be synced automatically. This is to help you drive sales and optimize your ad performance. <a href="https://www.facebook.com/business/help/4049935305295468">Learn more about changes to how your products will sync to Meta</a>
                <p>
                    <a href="edit.php?post_type=product"> Review products </a>
                    <a href="javascript:void(0);" style="text-decoration: underline; cursor: pointer; margin-left: 10px" class="opt_out_of_sync_button"> Opt out of automatic sync</a>
                </p>
            </div>
            ';

			echo '<div id="opted_our_successfullly_banner" class="' . esc_html( self::get_opted_out_successfully_banner_class() ) . '">
            <h4>You’ve opted out of automatic syncing on the next plugin update </h4>
                <p>
                    Products that are not synced will not be available for your customers to discover on your ads and shops. To manually add products, <a href="https://www.facebook.com/business/help/4049935305295468">learn how to sync products to your Meta catalog</a>
                </p>
            </div>';
		}
	}

	public static function plugin_updated_banner() {
		$screen = get_current_screen();

		if ( isset( $screen->id ) && 'marketing_page_wc-facebook' === $screen->id ) {

			if ( self::is_master_sync_on() && ! get_transient( 'plugin_updated_banner_hide' ) ) {
				echo '<div class="notice notice-success is-dismissible plugin_updated_successfully" style="">
                <h4>You’ve updated to the latest plugin version</h4>
                    <p>
                        As part of this update, all your products automatically sync to Meta. It may take some time before all your products are synced. If you change your mind, go to WooCommerce > Products and select which products to un-sync. <a href="https://www.facebook.com/business/help/4049935305295468"> About syncing products to Meta </a>
                    </p>
                </div>';
			} else {
				$is_master_sync_on                     = self::is_master_sync_on();
				$plugin_updated_and_master_sync_on     = ! $is_master_sync_on || get_transient( 'plugin_updated_banner_hide' ) ? 'hidden' : '';
				$plugin_updated_but_not_master_sync_on = $is_master_sync_on || get_transient( 'plugin_updated_with_master_sync_off_banner_hide' ) ? 'hidden' : '';

				/**
				 *Showing updated successfully banner after user has already clicked sync all
				 * This banner will be shown only once and if user decides to dismiss it
				 * Won't be shown again
				 */

				echo '<div id="plugin_updated_successfully_after_user_opts_in" class="notice notice-success is-dismissible plugin_updated_successfully ' . esc_html( $plugin_updated_and_master_sync_on ) . '"" style="">
				 <h4>Your products will be synced automatically</h4>   
					 <p>
						 It may take some time before all your products are synced. If you change your mind, go to WooCommerce > Products and select which products to un-sync.<a href="https://www.facebook.com/business/help/4049935305295468"> About syncing products to Meta</a>
					 </p>
				 </div>';

				/**
				 * Shows up every fortnight after version 3.5.3
				 * If and only if the user has opted out and also upgraded the plugin to 3.5.3
				 */

					echo '<div id="plugin_updated_successfully_but_master_sync_off" class="notice notice-success is-dismissible ' . esc_html( $plugin_updated_but_not_master_sync_on ) . '"" style="">
					<h4>You’ve updated to the latest plugin version</h4>   
						<p>
							To see all the changes, view the changelog. Since you’ve opted out of automatically syncing all your products, some of your products are not yet on Meta. We recommend turning on auto syncing to help drive your sales and improve ad performance.<a href="https://www.facebook.com/business/help/4049935305295468"> About syncing products to Meta </a>
						</p>
						<p>
							<a href="javascript:void(0);" class="button wc-forward" id="sync_all_products">
								Sync all products
							</a>
						</p>
					</div>';

			}
		}
	}

	public static function opt_out_of_sync_clicked() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_OPT_OUT_OF_SYNC ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$latest_date = gmdate( 'Y-m-d H:i:s' );
		update_option( self::MASTER_SYNC_OPT_OUT_TIME, $latest_date );
		wp_send_json_success( 'Opted out successfully' );
	}

	public static function sync_all_clicked() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_SYNC_BACK_IN ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		update_option( self::MASTER_SYNC_OPT_OUT_TIME, '' );
		wp_send_json_success( 'Synced all in successfully' );
	}

	public static function product_set_banner_closed() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_PRODUCT_SET_BANNER_CLOSED ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		set_transient( 'fb_product_set_banner_dismissed', true );
	}

	/**
	 * Banner for initmation of WooAllProducts version will show up
	 * after a week
	 */
	public static function reset_upcoming_version_banners() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_CLOSE_BANNER ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		set_transient( 'upcoming_woo_all_products_banner_hide', true, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Banner for  WooAllProducts version upgrade will show up
	 * after a year
	 * NOTE: We are doing this because anyway we will remove this in cleanup post : 3.5.3
	 */
	public static function reset_plugin_updated_successfully_banner() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_CLOSE_BANNER ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		set_transient( 'plugin_updated_banner_hide', true, 12 * MONTH_IN_SECONDS );
	}

	/**
	 * Banner for WooAllProducts versiong upgrade will show up
	 * But this will keep showing every week fortnight if user not synced in
	 */
	public static function reset_plugin_updated_successfully_but_master_sync_off_banner() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_CLOSE_BANNER ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		set_transient( 'plugin_updated_with_master_sync_off_banner_hide', true, 2 * WEEK_IN_SECONDS );
	}



	public static function get_opted_out_successfully_banner_class() {
		$hidden              = ! self::is_master_sync_on();
		$opt_in_banner_class = 'notice notice-success is-dismissible';

		if ( $hidden ) {
			$opt_in_banner_class = 'notice notice-success is-dismissible';
		} else {
			$opt_in_banner_class = 'notice notice-success is-dismissible hidden';
		}
		return $opt_in_banner_class;
	}

	public static function get_opt_out_banner_class() {
		$hidden               = ! self::is_master_sync_on();
		$opt_out_banner_class = 'notice notice-info is-dismissible';

		if ( $hidden ) {
			$opt_out_banner_class = 'notice notice-info is-dismissible hidden';
		} else {
			$opt_out_banner_class = 'notice notice-info is-dismissible';
		}
		return $opt_out_banner_class;
	}

	public static function get_opt_out_modal_message() {
		return '
            <h4>Opt out of automatic product sync?</h4>
            <p>
                If you opt out, we will not be syncing your products to your Meta catalog even after you update your Meta for WooCommerce plugin.
            </p>

            <p>
                However, we strongly recommend syncing all products to help drive sales and optimize ad performance. Products that aren’t synced will not be available for your customers to discover and buy in your ads and shops.
            </p>

            <p>
                If you change your mind later, you can easily un-sync your products by going to WooCommerce > Products.
            </p>
        ';
	}

	public static function get_opt_out_modal_buttons() {
		return '
            <a href="javascript:void(0);" class="button wc-forward upgrade_plugin_button" id="modal_opt_out_button">
            	Opt out
            </a>
        ';
	}
}
