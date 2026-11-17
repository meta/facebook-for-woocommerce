<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Admin;

use WooCommerce\Facebook\Admin\Enhanced_Settings;
use WooCommerce\Facebook\Admin\Settings_Screens\Configuration;
use WooCommerce\Facebook\Admin\Settings_Screens\Product_Attributes;
use WooCommerce\Facebook\Admin\Settings_Screens\Shops;
use WooCommerce\Facebook\Handlers\Connection;
use WooCommerce\Facebook\RolloutSwitches;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for the enhanced settings controller.
 */
class Enhanced_SettingsTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * Restores request state after each test.
	 */
	public function tearDown(): void {
		unset( $_GET['page'], $_GET['tab'], $_REQUEST['page'], $_REQUEST['tab'] );
		wp_dequeue_script( 'wc-facebook-enhanced-settings-sync' );
		wp_dequeue_style( 'wc-facebook-admin-shops-settings' );

		parent::tearDown();
	}

	/**
	 * Provides the supported settings-screen combinations.
	 *
	 * @return array<string, array{bool, string[]}>
	 */
	public static function screen_configuration_provider(): array {
		return array(
			'disconnected merchants see Shops only' => array(
				false,
				array( Shops::ID ),
			),
			'connected merchants see Shops, attributes and configuration' => array(
				true,
				array( Shops::ID, Product_Attributes::ID, Configuration::ID ),
			),
		);
	}

	/**
	 * Provides tab IDs removed with the legacy settings experience.
	 *
	 * @return array<string, array{string}>
	 */
	public static function removed_tab_provider(): array {
		return array(
			'legacy Connection tab'   => array( 'connection' ),
			'legacy Advertise tab'    => array( 'advertise' ),
			'retired Product sync tab' => array( 'product_sync' ),
		);
	}

	/**
	 * Provides WooCommerce menu configurations.
	 *
	 * @return array<string, array{bool, string}>
	 */
	public static function marketing_menu_provider(): array {
		return array(
			'marketing navigation enabled'  => array( true, 'woocommerce-marketing' ),
			'marketing navigation disabled' => array( false, 'woocommerce' ),
		);
	}

	/**
	 * Provides requests that should not enqueue Shops assets.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function non_shops_request_provider(): array {
		return array(
			'another admin page' => array( 'another-page', Shops::ID ),
			'Configuration tab'  => array( Enhanced_Settings::PAGE_ID, Configuration::ID ),
		);
	}

	/**
	 * Verifies that connected merchants without a stored CPI use the current settings controller.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_plugin_bootstrap_uses_enhanced_settings_when_cpi_is_missing(): void {
		set_current_screen( 'dashboard' );
		update_option( Connection::OPTION_ACCESS_TOKEN, 'access-token' );
		delete_option( Connection::OPTION_COMMERCE_PARTNER_INTEGRATION_ID );

		$callbacks_before = $this->count_enhanced_settings_menu_callbacks();

		new \WC_Facebookcommerce();

		$this->assertSame( $callbacks_before + 1, $this->count_enhanced_settings_menu_callbacks() );
	}

	/**
	 * @dataProvider screen_configuration_provider
	 *
	 * @param bool     $is_connected Whether the merchant is connected.
	 * @param string[] $expected_screen_ids Expected screen IDs.
	 */
	public function test_build_menu_item_array_uses_current_settings_experience(
		bool $is_connected,
		array $expected_screen_ids
	): void {
		$settings = $this->create_settings( $is_connected );

		$this->assertSame( $expected_screen_ids, array_keys( $settings->build_menu_item_array() ) );
	}

	/**
	 * @dataProvider marketing_menu_provider
	 *
	 * @param bool   $marketing_enabled Whether WooCommerce Marketing is enabled.
	 * @param string $expected_menu Expected parent menu slug.
	 */
	public function test_root_menu_item_uses_woocommerce_navigation(
		bool $marketing_enabled,
		string $expected_menu
	): void {
		$settings = $this->getMockBuilder( Enhanced_Settings::class )
			->setConstructorArgs( array( $this->create_plugin( true ) ) )
			->onlyMethods( array( 'is_marketing_enabled' ) )
			->getMock();
		$settings->method( 'is_marketing_enabled' )->willReturn( $marketing_enabled );

		$this->assertSame( $expected_menu, $settings->root_menu_item() );
	}

	/**
	 * Verifies that invalid settings-screen entries are ignored.
	 */
	public function test_get_screens_filters_invalid_entries(): void {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_admin_settings_screens',
			static function ( array $screens ): array {
				$screens['invalid'] = new \stdClass();
				return $screens;
			}
		);

		$screens = $this->create_settings( true )->get_screens();

		$this->assertSame( array( Shops::ID, Product_Attributes::ID, Configuration::ID ), array_keys( $screens ) );
	}

	/**
	 * Verifies that extensions can continue adding settings tabs through the public filter.
	 */
	public function test_get_tabs_applies_public_filter(): void {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_admin_settings_tabs',
			static function ( array $tabs ): array {
				$tabs['extension_tab'] = 'Extension tab';
				return $tabs;
			}
		);

		$tabs = $this->create_settings( false )->get_tabs();

		$this->assertArrayHasKey( 'extension_tab', $tabs );
		$this->assertSame( 'Extension tab', $tabs['extension_tab'] );
	}

	/**
	 * Verifies that unknown screens cannot be selected for rendering or saving.
	 */
	public function test_get_screen_rejects_unknown_screen(): void {
		$this->assertNull( $this->create_settings( true )->get_screen( 'unknown' ) );
	}

	/**
	 * @dataProvider non_shops_request_provider
	 *
	 * @param string $page Requested admin page.
	 * @param string $tab Requested settings tab.
	 */
	public function test_shops_assets_are_not_enqueued_for_other_screens( string $page, string $tab ): void {
		$_GET['page'] = $page;
		$_GET['tab']  = $tab;
		$_REQUEST      = array_merge( $_REQUEST, $_GET );

		$shops = new Shops();
		$shops->initHook();
		$shops->enqueue_assets();

		$this->assertFalse( wp_script_is( 'wc-facebook-enhanced-settings-sync', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'wc-facebook-admin-shops-settings', 'enqueued' ) );
	}

	/**
	 * Verifies that the primary settings URL defaults to the Shops screen.
	 */
	public function test_shops_assets_are_enqueued_when_tab_is_omitted(): void {
		$_GET['page'] = Enhanced_Settings::PAGE_ID;
		$_REQUEST      = array_merge( $_REQUEST, $_GET );
		unset( $_GET['tab'], $_REQUEST['tab'] );

		$shops = new Shops();
		$shops->initHook();
		$shops->enqueue_assets();

		$this->assertTrue( wp_style_is( 'wc-facebook-admin-shops-settings', 'enqueued' ) );

		// The sync script moved to the Configuration tab along with the sync buttons,
		// so Shops resolving its own screen no longer implies enqueueing it.
		$this->assertFalse( wp_script_is( 'wc-facebook-enhanced-settings-sync', 'enqueued' ) );
	}

	/**
	 * Ensures bookmarks for removed tabs resolve to the current Shops screen.
	 *
	 * @dataProvider removed_tab_provider
	 *
	 * @param string $removed_tab Removed settings tab ID.
	 */
	public function test_render_falls_back_to_shops_for_removed_tab( string $removed_tab ): void {
		delete_option( Connection::OPTION_ACCESS_TOKEN );
		delete_option( Connection::OPTION_MERCHANT_ACCESS_TOKEN );
		delete_transient( 'wc_facebook_connection_invalid' );

		$_GET['page'] = Enhanced_Settings::PAGE_ID;
		$_GET['tab']  = $removed_tab;
		$_REQUEST      = array_merge( $_REQUEST, $_GET );

		$settings = $this->create_settings( false );
		$settings->normalize_requested_tab();

		$this->assertSame( Shops::ID, $_REQUEST['tab'] );
		$this->assertSame( Shops::ID, $_GET['tab'] );

		$shops = $settings->get_screen( Shops::ID );
		$shops->initHook();
		$shops->enqueue_assets();

		$this->assertTrue( wp_style_is( 'wc-facebook-admin-shops-settings', 'enqueued' ) );

		ob_start();
		$settings->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="facebook-commerce-iframe-enhanced"', $output );
		$this->assertStringContainsString( 'commerce_extension/splash', $output );
	}

	/**
	 * Creates an enhanced settings controller for the requested state.
	 *
	 * No persistent entities are required for these tests, so a data preparer is unnecessary.
	 *
	 * @param bool $is_connected Whether the merchant is connected.
	 * @return Enhanced_Settings
	 */
	private function create_settings( bool $is_connected ): Enhanced_Settings {
		return new Enhanced_Settings( $this->create_plugin( $is_connected ) );
	}

	/**
	 * Creates a plugin double for the requested settings state.
	 *
	 * @param bool $is_connected Whether the merchant is connected.
	 * @return \WC_Facebookcommerce
	 */
	private function create_plugin( bool $is_connected ): \WC_Facebookcommerce {
		$connection = $this->createMock( Connection::class );
		$connection->method( 'is_connected' )->willReturn( $is_connected );

		$rollout_switches = $this->createMock( RolloutSwitches::class );

		$plugin = $this->getMockBuilder( \WC_Facebookcommerce::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_connection_handler', 'get_rollout_switches' ) )
			->getMock();
		$plugin->method( 'get_connection_handler' )->willReturn( $connection );
		$plugin->method( 'get_rollout_switches' )->willReturn( $rollout_switches );

		return $plugin;
	}

	/**
	 * Counts enhanced settings controllers registered on the admin menu hook.
	 */
	private function count_enhanced_settings_menu_callbacks(): int {
		$hook = $GLOBALS['wp_filter']['admin_menu'] ?? null;
		if ( ! $hook instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $hook->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && $function[0] instanceof Enhanced_Settings ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
