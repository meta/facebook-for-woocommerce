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

use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Admin\Settings_Screens\Configuration;
use WooCommerce\Facebook\Events\POS\POS_Integration_Registry;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler.
 *
 * @since 1.10.0
 */
class AJAX {

	/** @var string the product attribute search AJAX action */
	const ACTION_SEARCH_PRODUCT_ATTRIBUTES = 'wc_facebook_search_product_attributes';

	// The three offline purchase events actions below, and what each allows, are listed
	// in docs/offline-events.md ("Turning it on"). Keep that list in step when adding,
	// renaming or changing them.

	/** @var string the enable offline purchase events AJAX action */
	const ACTION_ENABLE_OFFLINE_EVENTS = 'wc_facebook_enable_offline_events';

	/** @var string the disable offline purchase events AJAX action */
	const ACTION_DISABLE_OFFLINE_EVENTS = 'wc_facebook_disable_offline_events';

	/** @var string the offline purchase events status AJAX action */
	const ACTION_GET_OFFLINE_EVENTS_STATUS = 'wc_facebook_get_offline_events_status';

	/**
	 * AJAX handler constructor.
	 *
	 * @since 1.10.0
	 */
	public function __construct() {
		// sync all products via AJAX
		add_action( 'wp_ajax_wc_facebook_sync_products', array( $this, 'sync_products' ) );

		// sync all coupons via AJAX
		add_action( 'wp_ajax_wc_facebook_sync_coupons', array( $this, 'sync_coupons' ) );

		// sync all shipping profiles via AJAX
		add_action( 'wp_ajax_wc_facebook_sync_shipping_profiles', array( $this, 'sync_shipping_profiles' ) );

		// sync navigation menu via AJAX
		add_action( 'wp_ajax_wc_facebook_sync_navigation_menu', array( $this, 'sync_navigation_menu' ) );

		// search a product's attributes for the given term
		add_action( 'wp_ajax_' . self::ACTION_SEARCH_PRODUCT_ATTRIBUTES, array( $this, 'admin_search_product_attributes' ) );

		// turn offline (physical store) purchase events on and off
		add_action( 'wp_ajax_' . self::ACTION_ENABLE_OFFLINE_EVENTS, array( $this, 'enable_offline_events' ) );
		add_action( 'wp_ajax_' . self::ACTION_DISABLE_OFFLINE_EVENTS, array( $this, 'disable_offline_events' ) );
		add_action( 'wp_ajax_' . self::ACTION_GET_OFFLINE_EVENTS_STATUS, array( $this, 'get_offline_events_status' ) );
	}


	/**
	 * Searches a product's attributes for the given term.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 *
	 * @throws PluginException If the nonce is invalid or a search term is not provided.
	 */
	public function admin_search_product_attributes() {
		try {
			if ( ! wp_verify_nonce( Helper::get_requested_value( 'security' ), self::ACTION_SEARCH_PRODUCT_ATTRIBUTES ) ) {
				throw new PluginException( 'Invalid nonce' );
			}

			$term = Helper::get_requested_value( 'term' );
			if ( ! $term ) {
				throw new PluginException( 'A search term is required' );
			}

			$product = wc_get_product( (int) Helper::get_requested_value( 'request_data' ) );
			if ( ! $product instanceof \WC_Product ) {
				throw new PluginException( 'A valid product ID is required' );
			}

			$attributes = Admin\Products::get_available_product_attribute_names( $product );
			// filter out any attributes whose slug or proper name don't at least partially match the search term
			$results = array_filter(
				$attributes,
				function ( $name, $slug ) use ( $term ) {
					return false !== stripos( $name, $term ) || false !== stripos( $slug, $term );
				},
				ARRAY_FILTER_USE_BOTH
			);
			wp_send_json( $results );
		} catch ( PluginException $exception ) {
			die();
		}
	}

	/**
	 * Syncs all products via AJAX.
	 *
	 * @internal
	 *
	 * @since 2.0.0
	 */
	public function sync_products() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( Configuration::ACTION_SYNC_PRODUCTS ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		// Allow opt-out of full batch-API sync, for example if store has a large number of products.
		if ( ! facebook_for_woocommerce()->get_integration()->allow_full_batch_api_sync() ) {
			wp_send_json_error( __( 'Full product sync disabled by filter.', 'facebook-for-woocommerce' ) );
			return;
		}

		try {
			facebook_for_woocommerce()->get_products_sync_handler()->create_or_update_all_products();
			wp_send_json_success();
		} catch ( \Exception $exception ) {
			wp_send_json_error( $exception->getMessage() );
		}
	}

	/**
	 * Syncs all coupons via AJAX.
	 *
	 * @internal
	 *
	 * @since 3.5.0
	 */
	public function sync_coupons() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( Configuration::ACTION_SYNC_COUPONS ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		try {
			facebook_for_woocommerce()->feed_manager->get_feed_instance( 'promotions' )->regenerate_feed();
			wp_send_json_success();
		} catch ( \Exception $exception ) {
			wp_send_json_error( $exception->getMessage() );
		}
	}

	/**
	 * Syncs all shipping profiles via AJAX.
	 *
	 * @internal
	 *
	 * @since 3.5.0
	 */
	public function sync_shipping_profiles() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( Configuration::ACTION_SYNC_SHIPPING_PROFILES ) ) {
			wp_send_json_error( 'Permission denied' );
		}
		try {
			facebook_for_woocommerce()->feed_manager->get_feed_instance( 'shipping_profiles' )->regenerate_feed();
			wp_send_json_success();
		} catch ( \Exception $exception ) {
			wp_send_json_error( $exception->getMessage() );
		}
	}

	/**
	 * Syncs navigation menu via AJAX.
	 *
	 * @internal
	 *
	 * @since 3.5.0
	 */
	public function sync_navigation_menu() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( Configuration::ACTION_SYNC_NAVIGATION_MENU ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		try {
			facebook_for_woocommerce()->feed_manager->get_feed_instance( 'navigation_menu' )->regenerate_feed();
			wp_send_json_success();
		} catch ( \Exception $exception ) {
			wp_send_json_error( $exception->getMessage() );
		}
	}

	/**
	 * Enables offline (physical store) purchase events via AJAX.
	 *
	 * Refuses when no supported point-of-sale plugin is active: the setting would
	 * have no effect, and silently storing "yes" would misreport the store as
	 * reporting in-store sales when nothing can produce them.
	 *
	 * @internal
	 *
	 * @since 3.7.7
	 */
	public function enable_offline_events() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_ENABLE_OFFLINE_EVENTS ) ) {
			wp_send_json_error( 'Permission denied' );
			return;
		}

		// The same rule the settings screen applies, so the two cannot disagree.
		if ( ! facebook_for_woocommerce()->get_integration()->can_enable_offline_purchase_events() ) {
			wp_send_json_error(
				array(
					'message' => __( 'No supported point of sale plugin is active, so offline purchase events cannot be enabled.', 'facebook-for-woocommerce' ),
					'status'  => $this->get_offline_events_state(),
				)
			);
			return;
		}

		$this->set_offline_events_enabled( true );

		wp_send_json_success( $this->get_offline_events_state() );
	}


	/**
	 * Disables offline (physical store) purchase events via AJAX.
	 *
	 * Always permitted, including when no point-of-sale plugin is active, so the
	 * setting can be cleared after a POS plugin is deactivated.
	 *
	 * @internal
	 *
	 * @since 3.7.7
	 */
	public function disable_offline_events() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_DISABLE_OFFLINE_EVENTS ) ) {
			wp_send_json_error( 'Permission denied' );
			return;
		}

		$this->set_offline_events_enabled( false );

		wp_send_json_success( $this->get_offline_events_state() );
	}


	/**
	 * Gets the current offline (physical store) purchase events state via AJAX.
	 *
	 * @internal
	 *
	 * @since 3.7.7
	 */
	public function get_offline_events_status() {
		if ( ! \WC_Facebookcommerce_Utils::is_legit_ajax_call( self::ACTION_GET_OFFLINE_EVENTS_STATUS ) ) {
			wp_send_json_error( 'Permission denied' );
			return;
		}

		wp_send_json_success( $this->get_offline_events_state() );
	}


	/**
	 * Persists the offline purchase events opt-in.
	 *
	 * @since 3.7.7
	 *
	 * @param bool $enabled whether offline purchase events should be reported.
	 */
	private function set_offline_events_enabled( bool $enabled ) {
		update_option(
			\WC_Facebookcommerce_Integration::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS,
			wc_bool_to_string( $enabled )
		);
	}


	/**
	 * Describes the current offline purchase events state.
	 *
	 * Returned by every offline events handler so a caller can render the control
	 * without a second round trip.
	 *
	 * @since 3.7.7
	 *
	 * @return array
	 */
	private function get_offline_events_state(): array {
		$integrations = $this->get_supported_pos_slugs();

		return array(
			'enabled'      => facebook_for_woocommerce()->get_integration()->is_offline_purchase_events_enabled(),
			'supported'    => ! empty( $integrations ),
			'integrations' => $integrations,
		);
	}


	/**
	 * Gets the slugs of the point-of-sale integrations whose plugin is active.
	 *
	 * @since 3.7.7
	 *
	 * @return string[]
	 */
	private function get_supported_pos_slugs(): array {
		$slugs = array();

		foreach ( ( new POS_Integration_Registry() )->get_supported_integrations() as $integration ) {
			$slugs[] = $integration->get_slug();
		}

		return $slugs;
	}
}
