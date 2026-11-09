<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\COGS;

use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;
use WooCommerce\Facebook\Tests\Integration\COGS\WPFactoryCogsIntegrationTests;
use WC_Product_Variable;
use WC_Product_Variation;

abstract class CogsIntegrationTestsBase extends IntegrationTestCase {

	const WOOCOMMERCE_PLUGIN_FILE_PATH = 'woocommerce/woocommerce.php';

	/**
	 * Plugin instance
	 * @var \WC_Facebookcommerce
	 */
	protected $plugin;

	/**
	 * Integration instance
	 * @var \WC_Facebookcommerce_Integration  
	 */
	protected $integration;

	protected $original_active_plugins;

	/**
	 * Set up before each test
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_active_plugins = get_option('active_plugins', []);
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$this->mark_plugin_as_active(self::WOOCOMMERCE_PLUGIN_FILE_PATH);
	}

	protected function enable_wpfactory_cogs_plugin() {
		global $wp_filter;

		// Backup all plugins_loaded hooks
		$backup = $wp_filter['plugins_loaded'] ?? null;
		// Remove all hooks temporarily
		unset($wp_filter['plugins_loaded']);

		$this->assertTrue(function_exists('is_plugin_active'), 'is_plugin_active function not found!');
		$this->assertTrue(is_plugin_active(self::WOOCOMMERCE_PLUGIN_FILE_PATH), 'Failed to assert that this plugin is enabled: ' . self::WOOCOMMERCE_PLUGIN_FILE_PATH);
		$is_wpfactory_active = is_plugin_active(WPFactoryCogsIntegrationTests::PLUGIN_FILE_PATH);
		
		if ( ! $is_wpfactory_active ) {
			$this->assertTrue(function_exists('activate_plugin'), 'activate_plugin function not found!');
			require_once WP_PLUGIN_DIR . '/' . WPFactoryCogsIntegrationTests::PLUGIN_FILE_PATH;
			activate_plugin( WPFactoryCogsIntegrationTests::PLUGIN_FILE_PATH );

			$this->assertTrue(is_plugin_active(WPFactoryCogsIntegrationTests::PLUGIN_FILE_PATH), 'Failed to assert that this plugin is enabled: ' . WPFactoryCogsIntegrationTests::PLUGIN_FILE_PATH);;
		}
				// Fire plugins_loaded for just this plugin
		do_action('plugins_loaded');
		// WPFactory renamed the activation action alg_wc_cog_on_activation -> wpfcogs_on_activation in v4.1.6.
		do_action('wpfcogs_on_activation');
		// Restore original hooks
		$wp_filter['plugins_loaded'] = $backup;
	}

	protected function install_3p_plugin($plugin_file_path, $plugin_slug, $plugin_download_url){
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file_path ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
			include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$response = wp_remote_get( $plugin_download_url );

			$this->assertFalse( is_wp_error( $response ), 'Plugin download failed: ' . ( is_wp_error( $response ) ? $response->get_error_message() : '' ) );

			$body = wp_remote_retrieve_body( $response );
			$code = wp_remote_retrieve_response_code( $response );

			$this->assertNotEmpty( $body, "Plugin download returned empty body (HTTP {$code}) from: {$plugin_download_url}" );

			$plugin_zip = wp_upload_bits( $plugin_slug . '.zip', null, $body );
			$upgrader   = new \Plugin_Upgrader();
			$result     = $upgrader->install( $plugin_zip['file'] );

			if ( is_wp_error( $result ) ) {
				throw new \Exception('Cannot install/enable WPFactory plugin');
			}
		}
		return true;
	}

	protected function create_cogs_data_input($product, $quantity) {
		return array('product' => $product, 'qty' => $quantity);
	}

	protected function enable_cogs_in_woo_settings() {
		wc_get_container()
		->get( 'Automattic\WooCommerce\Internal\Features\FeaturesController' )
		->change_feature_enable('cost_of_goods_sold', true);
	}

	protected function disable_cogs_in_woo_settings() {
		wc_get_container()
		->get( 'Automattic\WooCommerce\Internal\Features\FeaturesController' )
		->change_feature_enable('cost_of_goods_sold', false);
	}

	protected function mark_plugin_as_active( $plugin ): void {
		$active_plugins = get_option('active_plugins', []);
		if (!in_array( $plugin , $active_plugins, true)) {
			$active_plugins[] = $plugin;
			update_option('active_plugins', $active_plugins);
		}
	}

	protected function create_variable_product_with_variations(): WC_Product_Variable {
		// Create variable product
		$variable_product = $this->create_variable_product();

		// Create variations
		$variations = [
			[ 'attributes' => [ 'size' => 'Small' ], 'price' => '25.99' ],
			[ 'attributes' => [ 'size' => 'Medium' ], 'price' => '29.99' ],
			[ 'attributes' => [ 'size' => 'Large' ], 'price' => '34.99' ]
		];

		foreach ( $variations as $variation_data ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $variable_product->get_id() );
			$variation->set_attributes( $variation_data['attributes'] );
			$variation->set_regular_price( $variation_data['price'] );
			$variation->set_status( 'publish' );
			$variation->save();
		}

		return $variable_product;
	}

	/**
	 * Tear down after each test
	 */
	public function tearDown(): void {
		// Clear any test data
		$this->clear_products();
		$this->reset_plugin_settings();
		update_option('active_plugins', $this->original_active_plugins);
		parent::tearDown();
	}
} 