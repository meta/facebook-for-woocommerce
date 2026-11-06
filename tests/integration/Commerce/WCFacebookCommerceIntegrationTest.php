<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

require_once __DIR__ . '/../../../facebook-commerce.php';

use WooCommerce\Facebook\Admin;
use WooCommerce\Facebook\Admin\Products as AdminProducts;
use WooCommerce\Facebook\Admin\Enhanced_Catalog_Attribute_Fields;
use WooCommerce\Facebook\API;
use WooCommerce\Facebook\Framework\Api\Exception as ApiException;
use WooCommerce\Facebook\Handlers\Connection;
use WooCommerce\Facebook\Products;
use WooCommerce\Facebook\ProductSync\ProductExcludedException;
use WooCommerce\Facebook\ProductSync\ProductValidator;
use WooCommerce\Facebook\Framework\AdminMessageHandler;
use WooCommerce\Facebook\Handlers\PluginRender;
use WooCommerce\Facebook\RolloutSwitches;

/**
 * Unit tests for Facebook Graph API calls.
 */
class WCFacebookCommerceIntegrationTest extends \WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * @var WC_Facebookcommerce
	 */
	private $facebook_for_woocommerce;

	/**
	 * @var Connection
	 */
	private $connection_handler;

	/**
	 * @var Api
	 */
	private $api;

	/**
	 * @var WC_Facebookcommerce_Integration
	 */
	private $integration;


	/**
	 * @var RolloutSwitches
	 */
	private $rollout_switches;

	/**
	 * REQUEST_URI as it was before a test overrode it, or false when no test has. phpunit.xml.dist
	 * sets backupGlobals="false", so anything written to $_SERVER has to be restored by hand.
	 * A null here means REQUEST_URI was not set at all and must be unset again.
	 *
	 * @var string|null|false
	 */
	private $original_request_uri = false;

	/**
	 * Default plugin options.
	 *
	 * @var array
	 */
	private static $default_options = [
		WC_Facebookcommerce_Pixel::PIXEL_ID_KEY     => '0',
		WC_Facebookcommerce_Pixel::USE_PII_KEY      => true,
		WC_Facebookcommerce_Pixel::USE_S2S_KEY      => false,
		WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
	];

	/**
	 * Runs before each test is executed.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->facebook_for_woocommerce = $this->createMock( WC_Facebookcommerce::class );
		$this->connection_handler       = $this->createMock( Connection::class );
		$this->rollout_switches = $this->createMock(RolloutSwitches::class);
		$this->rollout_switches->method('is_switch_enabled')
								->willReturn(false);


		$this->facebook_for_woocommerce->method( 'get_connection_handler' )
			->willReturn( $this->connection_handler );
		$this->api = $this->createMock( Api::class );
		$this->facebook_for_woocommerce->method( 'get_api' )
			->willReturn( $this->api );
		$this->facebook_for_woocommerce->method('get_rollout_switches')
			->willReturn($this->rollout_switches);



		$this->integration = new WC_Facebookcommerce_Integration( $this->facebook_for_woocommerce );

		/* Making sure no options are set before the test. */
		delete_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY );
		delete_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID );
		// Needed to prevent error logs in tests.
		WC_Facebookcommerce_Utils::$ems = 'dummy_ems_id';
	}

	/**
	 * Runs after each test is executed.
	 */
	public function tearDown(): void {
		if ( false !== $this->original_request_uri ) {
			if ( null === $this->original_request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $this->original_request_uri;
			}

			$this->original_request_uri = false;
		}

		parent::tearDown();
	}

	/**
	 * Tests init pixel method does nothing for non admin users.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_non_admin_user_must_do_nothing() {
		$this->assertFalse( is_admin(), 'Current user must not be an admin user.' );
		$this->assertFalse( $this->integration->init_pixel() );
	}

	/**
	 * Tests init pixel inits with default options.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_default_options() {
		/* Setting up Admin user. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			self::$default_options,
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests migrating some setting from wc settings to wp options when init.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_migrating_wc_pixel_settings_to_wp_options() {
		/* Setting up Admin User. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/* Setting up WC Facebook pixel id. */
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, '112233445566778899' );

		/* Setting up initial options. */
		add_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			self::$default_options
		);

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertFalse( has_filter( 'wc_facebook_pixel_id' ) );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			[
				WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '112233445566778899',
				WC_Facebookcommerce_Pixel::USE_PII_KEY  => true,
				WC_Facebookcommerce_Pixel::USE_S2S_KEY  => false,
				WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
			],
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests init pixel with filter set.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_overwrites_pixel_id_with_filter() {
		/* Setting up Admin User. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/* Setting up initial options. */
		add_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			self::$default_options
		);

		$this->add_filter_with_safe_teardown(
			'wc_facebook_pixel_id',
			function ( $wc_facebook_pixel_id ) {
				return '998877665544332211';
			}
		);

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertTrue( has_filter( 'wc_facebook_pixel_id' ) );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			[
				WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '998877665544332211',
				WC_Facebookcommerce_Pixel::USE_PII_KEY  => true,
				WC_Facebookcommerce_Pixel::USE_S2S_KEY  => false,
				WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
			],
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests init pixel for admin user uses filter to overwrite use pii settings.
	 *
	 * @return void
	 */
	public function test_init_pixel_for_admin_user_must_init_pixel_overwrites_use_pii_with_filter() {
		/* Setting up Admin User. */
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/* Setting up initial options. */
		add_option(
			WC_Facebookcommerce_Pixel::SETTINGS_KEY,
			self::$default_options
		);

		$this->add_filter_with_safe_teardown(
			'wc_facebook_is_advanced_matching_enabled',
			function ( $use_pii ) {
				return false;
			}
		);

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertTrue( has_filter( 'wc_facebook_is_advanced_matching_enabled' ) );
		$this->assertTrue( $this->integration->init_pixel() );
		$this->assertEquals(
			[
				WC_Facebookcommerce_Pixel::PIXEL_ID_KEY => '0',
				WC_Facebookcommerce_Pixel::USE_PII_KEY  => false,
				WC_Facebookcommerce_Pixel::USE_S2S_KEY  => false,
				WC_Facebookcommerce_Pixel::ACCESS_TOKEN_KEY => '',
			],
			get_option( WC_Facebookcommerce_Pixel::SETTINGS_KEY )
		);
	}

	/**
	 * Tests loading of background processor.
	 *
	 * @return void
	 */
	public function test_load_background_sync_process() {
		$this->integration->load_background_sync_process();

        $ref = new \ReflectionClass( $this->integration );
        $background_processor_prop = $ref->getProperty( 'background_processor' );
        $background_processor_prop->setAccessible( true );
        $background_processor = $background_processor_prop->getValue( $this->integration );

        $this->assertInstanceOf(WC_Facebookcommerce_Background_Process::class, $background_processor);
		$this->assertEquals(
			10,
			has_action(
				'wp_ajax_ajax_fb_background_check_queue',
				[ $this->integration, 'ajax_fb_background_check_queue' ]
			)
		);
	}

	/**
	 * Tests fetching variable product item ids from product meta.
	 *
	 * @return void
	 */
	public function test_get_variation_product_item_ids_from_meta() {
		/** @var WC_Product_Variable $parent */
		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$expected_output = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$variation->add_meta_data(
				WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
				'some-facebook-product-item-id-' . $variation_id
			);
			$variation->save_meta_data();

			$expected_output[ $variation_id ] = 'some-facebook-product-item-id-' . $variation_id;
		}
		/* From Product Meta or FB API. */
		$facebook_product_id = 'some-facebook-product-group-id';

		$output = $this->integration->get_variation_product_item_ids( $product, $facebook_product_id );

		$this->assertEquals( $expected_output, $output );
	}

	/**
	 * Tests fetching product item ids from facebook with any filters.
	 *
	 * @return void
	 */
	public function test_get_variation_product_item_ids_from_facebook_with_no_fb_retailer_id_filters() {
		/** @var WC_Product_Variable $parent */
		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$expected_output = [];
		$facebook_output = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation                        = wc_get_product( $variation_id );
			$expected_output[ $variation_id ] = 'some-facebook-api-product-item-id-' . $variation_id;
			$facebook_output[]                = [
				'id'          => 'some-facebook-api-product-item-id-' . $variation_id,
				'retailer_id' => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $variation ),
			];
		}
		/* From Product Meta or FB API. */
		$facebook_product_group_id = 'some-facebook-product-group-id';
		$facebook_response         = new API\ProductCatalog\ProductGroups\Read\Response( json_encode( [ 'data' => $facebook_output ] ) );

		$this->api->expects( $this->once() )
			->method( 'get_product_group_products' )
			->with( $facebook_product_group_id )
			->willReturn( $facebook_response );

		$output = $this->integration->get_variation_product_item_ids( $product, $facebook_product_group_id );

		$this->assertFalse( has_filter( 'wc_facebook_fb_retailer_id' ) );
		$this->assertEquals( $expected_output, $output );
	}

	/**
	 * Tests fetching variable product item ids from facebook with filters.
	 *
	 * @return void
	 */
	public function test_get_variation_product_item_ids_from_facebook_with_fb_retailer_id_filters() {
		/** @var WC_Product_Variable $parent */
		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$expected_output = [];
		$facebook_output = [];
		foreach ( $product->get_children() as $variation_id ) {
			$variation                        = wc_get_product( $variation_id );
			$expected_output[ $variation_id ] = 'some-facebook-api-product-item-id-' . $variation_id;
			$facebook_output[]                = [
				'id'          => 'some-facebook-api-product-item-id-' . $variation_id,
				'retailer_id' => \WC_Facebookcommerce_Utils::get_fb_retailer_id( $variation ) . '_modified',
			];
		}
		/* From Product Meta or FB API. */
		$facebook_product_group_id = 'some-facebook-product-group-id';
		$facebook_response         = new API\ProductCatalog\ProductGroups\Read\Response( json_encode( [ 'data' => $facebook_output ] ) );

		$this->api->expects( $this->once() )
			->method( 'get_product_group_products' )
			->with( $facebook_product_group_id )
			->willReturn( $facebook_response );

		$this->add_filter_with_safe_teardown(
			'wc_facebook_fb_retailer_id',
			function ( $retailer_id ) {
				return $retailer_id . '_modified';
			}
		);

		$output = $this->integration->get_variation_product_item_ids( $product, $facebook_product_group_id );

		$this->assertTrue( has_filter( 'wc_facebook_fb_retailer_id' ) );
		$this->assertEquals( $expected_output, $output );
	}

	/**
	 * Tests fetching product count without filters.
	 *
	 * @return void
	 */
	public function test_get_product_count_returns_product_count_with_no_filters() {
		$count = $this->integration->get_product_count();

		$this->assertFalse( has_filter( 'wp_count_posts' ) );
		$this->assertEquals( 0, $count );

		WC_Helper_Product::create_simple_product();
		WC_Helper_Product::create_simple_product();

		$count = $this->integration->get_product_count();

		$this->assertFalse( has_filter( 'wp_count_posts' ) );
		$this->assertEquals( 2, $count );
	}

	/**
	 * Tests filters overwrite product counts.
	 *
	 * @return void
	 */
	public function test_get_product_count_returns_product_count_with_filters() {
		$this->add_filter_with_safe_teardown(
			'wp_count_posts',
			function( $counts ) {
				$counts->publish = 21;
				return $counts;
			}
		);

		$count = $this->integration->get_product_count();

		$this->assertEquals( 10, has_filter( 'wp_count_posts' ) );
		$this->assertEquals( 21, $count );
	}

	/**
	 * Tests default allow full batch api sync status.
	 *
	 * @return void
	 */
	public function test_allow_full_batch_api_sync_returns_default_allow_status_with_no_filters() {
		$status = $this->integration->allow_full_batch_api_sync();

		$this->assertTrue( $status );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_block_full_batch_api_sync' ) );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_allow_full_batch_api_sync' ) );
	}

	/**
	 * Tests default allow full batch api sync uses facebook_for_woocommerce_block_full_batch_api_sync filter
	 * to overwrite allowance status.
	 *
	 * @return void
	 */
	public function test_allow_full_batch_api_sync_uses_block_full_batch_api_sync_filter() {
		$this->add_filter_with_safe_teardown(
			'facebook_for_woocommerce_block_full_batch_api_sync',
			function ( bool $status ) {
				return true;
			}
		);

		$status = $this->integration->allow_full_batch_api_sync();

		$this->assertFalse( $status );
		$this->assertTrue( has_filter( 'facebook_for_woocommerce_block_full_batch_api_sync' ) );
		$this->assertFalse( has_filter( 'facebook_for_woocommerce_allow_full_batch_api_sync' ) );
	}

	/**
	 * Tests plugin enqueues scripts and styles for non admin user for non plugin settings screens.
	 *
	 * @return void
	 */
	public function test_load_assets_loads_only_info_banner_assets_for_not_admin_or_not_a_plugin_settings_page() {
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'is_plugin_settings' )
			->willReturn( false );

		$this->integration->load_assets();

		do_action( 'wp_enqueue_scripts' );
		do_action( 'wp_enqueue_styles' );

		$this->assertTrue( wp_script_is( 'wc_facebook_infobanner_jsx' ) );
		$this->assertFalse( wp_style_is( 'wc_facebook_css' ) );
	}

	/**
	 * Tests plugin enqueues scripts and styles for admin user for plugin settings screens.
	 *
	 * @return void
	 */
	public function test_load_assets_loads_only_info_banner_assets_for_admin_at_plugin_settings_page() {
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'is_plugin_settings' )
			->willReturn( true );

		ob_start();
		$this->integration->load_assets();
		$output = ob_get_clean();

		do_action( 'wp_enqueue_scripts' );
		do_action( 'wp_enqueue_styles' );

		$this->assertTrue( wp_script_is( 'wc_facebook_infobanner_jsx' ) );
		$this->assertTrue( wp_style_is( 'wc_facebook_css' ) );
		$this->assertMatchesRegularExpression( '/window.facebookAdsToolboxConfig = {/', $output );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_save_existing_simple_product_sync_enabled_updates_the_product() {
		$product_to_update = WC_Helper_Product::create_simple_product();
		$product_to_delete = WC_Helper_Product::create_simple_product();

		$_POST['wc_facebook_sync_mode'] = Admin::SYNC_MODE_SYNC_AND_SHOW;

		$_POST[ WC_Facebook_Product::FB_REMOVE_FROM_SYNC ] = $product_to_delete->get_id();

		$_POST[ WC_Facebookcommerce_Integration::FB_PRODUCT_DESCRIPTION ] = 'Facebook product description.';
		$_POST[ WC_Facebook_Product::FB_PRODUCT_PRICE ]                   = '199';
		$_POST['fb_product_image_source']                                 = 'Image source meta key value.';
		$_POST[ WC_Facebook_Product::FB_PRODUCT_IMAGE ]                   = 'Facebook product image.';
		$_POST[ AdminProducts::FIELD_COMMERCE_ENABLED ]                   = 1;
		$_POST[ AdminProducts::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ]         = 1718;

		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr1' ] = 'Enhanced catalog attribute one.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr2' ] = 'Enhanced catalog attribute two.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr3' ] = 'Enhanced catalog attribute three.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr4' ] = 'Enhanced catalog attribute four.';

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product_to_update )
			->willReturn( $validator );

		$product_to_update->set_stock_status( 'instock' );

		add_post_meta( $product_to_update->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );
		add_option(PluginRender::MASTER_SYNC_OPT_OUT_TIME,'Random time');

		$product_to_update->set_meta_data( Products::VISIBILITY_META_KEY, true );

		$facebook_product                                    = new WC_Facebook_Product( $product_to_update->get_id() );
		$facebook_product_data                               = $facebook_product->prepare_product(null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
		$this->integration->update_product_catalog_id( '123123123123123123' );
		/* Data coming from _POST data. */
		$facebook_product_data['description']				 = 'Facebook product description.';
		$facebook_product_data['rich_text_description']		 = 'Facebook product description.';
		$facebook_product_data['price']                      = '199 USD';
		$facebook_product_data['google_product_category']    = '1718';

		$requests = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch($facebook_product_data);

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		$this->integration->on_product_save( $product_to_update->get_id() );

		$this->assertEquals( 'yes', get_post_meta( $product_to_update->get_id(), Products::SYNC_ENABLED_META_KEY, true ) );
		$this->assertEquals( 'yes', get_post_meta( $product_to_update->get_id(), Products::VISIBILITY_META_KEY, true ) );
		$this->assertEquals( 'imagesourcemetakeyvalue', get_post_meta( $product_to_update->get_id(), Products::PRODUCT_IMAGE_SOURCE_META_KEY, true ) );

		$this->assertEquals( 'Enhanced catalog attribute one.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr1', true ) );
		$this->assertEquals( 'Enhanced catalog attribute two.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr2', true ) );
		$this->assertEquals( 'Enhanced catalog attribute three.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr3', true ) );
		$this->assertEquals( 'Enhanced catalog attribute four.', get_post_meta( $product_to_update->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr4', true ) );

		$this->assertEquals( null, get_post_meta( $product_to_update->get_id(), Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY, true ) );
		$this->assertEquals( 1718, get_post_meta( $product_to_update->get_id(), Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, true ) );

		// Verify Facebook-specific fields were saved
		$facebook_product_to_update = new WC_Facebook_Product( $product_to_update->get_id() );
		$updated_product_data = $facebook_product_to_update->prepare_product(null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );

		$this->assertEquals( 'Facebook product description.', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_DESCRIPTION, true ) );
		$this->assertEquals( 'Facebook product description.', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_RICH_TEXT_DESCRIPTION, true ) );

		// Verify the actual values are still stored in meta
		$this->assertEquals( 'Facebook product description.', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_DESCRIPTION, true ) );
		$this->assertEquals( '199', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_PRICE, true ) );
		$this->assertEquals( 'http://example.orgFacebook product image.', get_post_meta( $facebook_product_to_update->get_id(), WC_Facebook_Product::FB_PRODUCT_IMAGE, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_save_existing_variable_product_sync_enabled_updates_the_product() {
		$parent           = WC_Helper_Product::create_variation_product();
		$fb_product       = new WC_Facebook_Product( $parent->get_id() );
		$parent_to_delete = WC_Helper_Product::create_variation_product();

		$_POST['wc_facebook_sync_mode']                           = Admin::SYNC_MODE_SYNC_AND_SHOW;
		$_POST[ WC_Facebook_Product::FB_REMOVE_FROM_SYNC ]        = $parent_to_delete->get_id();
		$_POST[ AdminProducts::FIELD_COMMERCE_ENABLED ]           = 1;
		$_POST[ AdminProducts::FIELD_GOOGLE_PRODUCT_CATEGORY_ID ] = 1920;

		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr1' ] = 'Enhanced catalog attribute one.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr2' ] = 'Enhanced catalog attribute two.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr3' ] = 'Enhanced catalog attribute three.';
		$_POST[ Enhanced_Catalog_Attribute_Fields::FIELD_ENHANCED_CATALOG_ATTRIBUTE_PREFIX . '_attr4' ] = 'Enhanced catalog attribute four.';

		add_option(PluginRender::MASTER_SYNC_OPT_OUT_TIME,'Random time');
		add_post_meta( $parent_to_delete->get_id(), Products::SYNC_ENABLED_META_KEY, 'no' );
		foreach ( $parent_to_delete->get_children() as $id ) {
			add_post_meta( $id, Products::SYNC_ENABLED_META_KEY, 'no' );
		}

		$sync = $this->createMock( Products\Sync::class );
		$sync->expects( $this->once() )
			->method( 'delete_products' )
			->with(
				array_map(
					function ( $id ) {
						$variation = wc_get_product( $id );
						return \WC_Facebookcommerce_Utils::get_fb_retailer_id( $variation );
					},
					$parent_to_delete->get_children()
				)
			);
		$sync->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $parent->get_children() );
		$this->facebook_for_woocommerce->expects( $this->exactly( 2 ) )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync );

		add_post_meta( $parent->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-variable-product-group-item-id' );

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$parent->set_meta_data( Products::VISIBILITY_META_KEY, true );

		$this->api->expects( $this->once() )
			->method( 'create_product_group' );

		$this->integration->on_product_save( $parent->get_id() );

		$this->assertEquals( 'Enhanced catalog attribute one.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr1', true ) );
		$this->assertEquals( 'Enhanced catalog attribute two.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr2', true ) );
		$this->assertEquals( 'Enhanced catalog attribute three.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr3', true ) );
		$this->assertEquals( 'Enhanced catalog attribute four.', get_post_meta( $parent->get_id(), Products::ENHANCED_CATALOG_ATTRIBUTES_META_KEY_PREFIX . '_attr4', true ) );

		$this->assertEquals( null, get_post_meta( $parent->get_id(), Enhanced_Catalog_Attribute_Fields::OPTIONAL_SELECTOR_KEY, true ) );
		$this->assertEquals( 1920, get_post_meta( $parent->get_id(), Products::GOOGLE_PRODUCT_CATEGORY_META_KEY, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_delete_simple_product() {
		$product_to_delete = WC_Helper_Product::create_simple_product();

		add_post_meta( $product_to_delete->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-id' );
		add_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id' );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'delete_products' )
			->willReturn('');
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_delete( $product_to_delete->get_id() );

		$this->assertEquals( null, get_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( null, get_post_meta( $product_to_delete->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_delete_variable_product() {
		$parent_to_delete = WC_Helper_Product::create_variation_product();

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'delete_products' )
			->with(
				array_map(
					function ( $id ) {
						return WC_Facebookcommerce_Utils::get_fb_retailer_id( wc_get_product( $id ) );
					},
					$parent_to_delete->get_children()
				)
			);
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_delete( $parent_to_delete->get_id() );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_fb_change_product_published_status_for_simple_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );
		add_option(PluginRender::MASTER_SYNC_OPT_OUT_TIME, 'Random time');

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$product                               = WC_Helper_Product::create_simple_product();
		$facebook_product                      = new WC_Facebook_Product( $product );
		$product_data                          = $facebook_product->prepare_product(null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
		$requests                              = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch($product_data);
		$this->integration->update_product_catalog_id( '123123123123123123' );
		if ( empty( $product_data['additional_image_urls'] ) ) {
			$product_data['additional_image_urls'] = '';
		}

		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-id' );

		$product_validator = $this->createMock( ProductValidator::class );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->willReturn( $product_validator );

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		/* Statuses involved into logic: publish, trash */
		$new_status = 'publish';
		$old_status = 'trash';
		$data       = new stdClass();
		$data->ID   = $product->get_id();
		$post       = new WP_Post( $data );

		$this->integration->fb_change_product_published_status( $new_status, $old_status, $post );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_fb_change_product_published_status_for_variable_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		/** @var WC_Product_Variable $product */
		$product = WC_Helper_Product::create_variation_product();

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $product->get_children() );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$product_validator = $this->createMock( ProductValidator::class );
		/* Called for all the six children and the parent. Called seven times. */
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $product_validator );

		/* Statuses involved into logic: publish, trash */
		$new_status = 'publish';
		$old_status = 'trash';
		$data       = new stdClass();
		$data->ID   = $product->get_id();
		$post       = new WP_Post( $data );

		$this->integration->fb_change_product_published_status( $new_status, $old_status, $post );
	}

	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_publish_simple_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$product = WC_Helper_Product::create_simple_product();

		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product )
			->willReturn( $validator );

		$this->integration->update_product_catalog_id( '123123123123123123' );
		$facebook_product                               = new WC_Facebook_Product( $product->get_id() );
		$facebook_product_data                          = $facebook_product->prepare_product(null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );

		$requests = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch($facebook_product_data);

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		$this->integration->on_product_publish( $product->get_id() );
	}

	/**
	 * Overrides REQUEST_URI for the current test, remembering the original for tearDown.
	 *
	 * @param string $uri the request URI to set
	 * @return void
	 */
	private function set_request_uri( string $uri ) {
		if ( false === $this->original_request_uri ) {
			$this->original_request_uri = array_key_exists( 'REQUEST_URI', $_SERVER ) ? $_SERVER['REQUEST_URI'] : null;
		}

		$_SERVER['REQUEST_URI'] = $uri;
	}

	/**
	 * Makes the current request look like a WooCommerce REST API request.
	 *
	 * WC()->is_rest_api_request() returns false on an empty REQUEST_URI *before* applying the
	 * woocommerce_is_rest_api_request filter, so the request URI has to carry the REST prefix —
	 * filtering alone never reaches the handler.
	 *
	 * @return void
	 */
	private function simulate_rest_api_request() {
		$this->set_request_uri( '/' . trailingslashit( rest_get_url_prefix() ) . 'wc/v3/products/1' );
	}

	/**
	 * Makes the current request look like an ordinary, non-REST request.
	 *
	 * @return void
	 */
	private function simulate_non_rest_request() {
		$this->set_request_uri( '/wp-admin/post.php?post=1&action=edit' );
	}

	/**
	 * Configures the plugin so the REST handler gets past its is_configured() guard.
	 *
	 * @return void
	 */
	private function configure_plugin_for_sync() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$this->connection_handler->method( 'is_connected' )->willReturn( true );
	}

	/**
	 * A product saved through the WooCommerce REST API must be queued for sync to the Meta catalog.
	 *
	 * @return void
	 */
	public function test_on_product_save_via_api_syncs_product_on_rest_request() {
		// Build the fixture before entering the REST context: creating a product fires
		// woocommerce_new_product, which is the very hook this handler listens on.
		$product = WC_Helper_Product::create_simple_product();

		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		// Simulate a WooCommerce REST API request made by a user allowed to edit products.
		$this->simulate_rest_api_request();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->configure_plugin_for_sync();

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		// The product is queued for the background job rather than pushed inline, so a batch REST
		// update costs one job instead of a Graph call per product.
		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( array( $product->get_id() ) );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->api->expects( $this->never() )
			->method( 'send_item_updates' );

		$this->integration->on_product_save_via_api( $product->get_id() );
	}

	/**
	 * A variable product saved over the REST API queues its syncable variations, which carry the
	 * catalog items; the parent's product group is derived from their item_group_id.
	 *
	 * @return void
	 */
	public function test_on_product_save_via_api_queues_variations_for_variable_product() {
		$parent = WC_Helper_Product::create_variation_product();

		$this->simulate_rest_api_request();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->configure_plugin_for_sync();

		$validator = $this->createMock( ProductValidator::class );
		$validator->method( 'validate' );
		$this->facebook_for_woocommerce->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $parent->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_save_via_api( $parent->get_id() );
	}

	/**
	 * A product save outside of a REST API request must not be synced by this handler; those
	 * requests (e.g. the admin product editor) are handled through their own hooks.
	 *
	 * @return void
	 */
	public function test_on_product_save_via_api_does_nothing_outside_rest_request() {
		$product = WC_Helper_Product::create_simple_product();

		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		$this->simulate_non_rest_request();

		$this->configure_plugin_for_sync();

		// The handler must bail before doing any sync work.
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_product_sync_validator' );
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->integration->on_product_save_via_api( $product->get_id() );
	}

	/**
	 * A REST API save made by a user without product edit permission must not be synced. This covers
	 * unprivileged contexts that also fire woocommerce_update_product, such as a stock decrement during
	 * a Store API checkout.
	 *
	 * @return void
	 */
	public function test_on_product_save_via_api_does_nothing_without_edit_permission() {
		$product = WC_Helper_Product::create_simple_product();

		add_post_meta( $product->get_id(), Products::SYNC_ENABLED_META_KEY, 'yes' );
		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id' );

		// A REST API request, but made by a customer who cannot edit products.
		$this->simulate_rest_api_request();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		$this->configure_plugin_for_sync();

		// The handler must bail before doing any sync work.
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_product_sync_validator' );
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->integration->on_product_save_via_api( $product->get_id() );
	}

	/**
	 * A product excluded from sync must not be queued, even on an authorized REST API save.
	 *
	 * @return void
	 */
	public function test_on_product_save_via_api_does_nothing_for_excluded_product() {
		$product = WC_Helper_Product::create_simple_product();

		$this->simulate_rest_api_request();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->configure_plugin_for_sync();

		$validator = $this->createMock( ProductValidator::class );
		$validator->method( 'validate' )
			->willThrowException( new ProductExcludedException( 'Sync disabled for this product.' ) );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->integration->on_product_save_via_api( $product->get_id() );
	}


	/**
	 * Sunny day test with all the conditions evaluated to true and maximum conditions triggered.
	 *
	 * @return void
	 */
	public function test_on_product_publish_variable_product() {
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '1234567891011121314' );

		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-variable-product-group-item-id' );

		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		$this->api->expects( $this->once() )
			->method( 'create_product_group' );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $facebook_product->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_product_publish( $product->get_id() );
	}

	/**
	 * Tests update of existing variable product.
	 *
	 * @return void
	 */
	public function test_on_variable_product_publish_existing_product_updates_product_group() {
		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-variable-product-group-item-id' );

		$this->api->expects( $this->once() )
			->method( 'create_product_group' );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $facebook_product->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_variable_product_publish( $product->get_id(), $facebook_product );
	}

	/**
	 * Tests update of existing variable product.
	 *
	 * @return void
	 */
	public function test_on_variable_product_publish_new_product_creates_product() {
		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->exactly( 7 ) )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->exactly( 7 ) )
			->method( 'get_product_sync_validator' )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );

		add_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, '' );

		$this->api->expects( $this->once() )
			->method( 'create_product_group' )
			->willReturn( new API\ProductCatalog\ProductGroups\Create\Response( '{"id":"facebook-variable-product-group-item-id"}' ) );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $facebook_product->get_children() );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->on_variable_product_publish( $product->get_id(), $facebook_product );

		$this->assertEquals(
			'facebook-variable-product-group-item-id',
			get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true )
		);
	}

	/**
	 * Tests on simple product publish update callback/hook updates existing product.
	 *
	 * @return void
	 */
	public function test_on_simple_product_publish_existing_product_updates_product() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );

		/* Product should be synced with all its variations. So seven calls expected. */
		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $facebook_product->woo_product )
			->willReturn( $validator );

		update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
		$facebook_product->woo_product->set_stock_status( 'instock' );

		$this->integration->update_product_catalog_id( '123123123123123123' );
		$facebook_product_data                          = $facebook_product->prepare_product(null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );

		$requests = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch($facebook_product_data);

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		/**
		 * In this update we are removing the dependency on fbid.
		 * Hence if the handles exist, then the return is an empty string.
		 * So the product is created/updated
		 */
		$empty_handles = $this->integration->on_simple_product_publish( $product->get_id(), $facebook_product );

		$this->assertEquals( '', $empty_handles );
	}

	/**
	 * Tests product should be synced calls to return success.
	 *
	 * @return void
	 */
	public function test_product_should_be_synced_calls_facebook_api() {
		$product = WC_Helper_Product::create_simple_product();

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product )
			->willReturn( $validator );

		$output = $this->integration->product_should_be_synced( $product );

		$this->assertTrue( $output );
	}

	/**
	 * Test product should be synced handles exception from facebook api and returns failure.
	 *
	 * @return void
	 */
	public function test_product_should_be_synced_calls_facebook_api_with_exception() {
		$product = WC_Helper_Product::create_simple_product();

		$validator = $this->createMock( ProductValidator::class );
		$validator->expects( $this->once() )
			->method( 'validate' )
			->will( $this->throwException( new Exception() ) );
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_product_sync_validator' )
			->with( $product )
			->willReturn( $validator );

		$output = $this->integration->product_should_be_synced( $product );

		$this->assertFalse( $output );
	}

	/**
	 * Tests create simple product with provided product group id.
	 *
	 * @return void
	 */
	public function test_create_product_simple_creates_product_with_provided_product_group_id() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product               = WC_Helper_Product::create_simple_product();
		$facebook_product      = new WC_Facebook_Product( $product->get_id() );
		$facebook_product_data = $facebook_product->prepare_product(null, \WC_Facebook_Product::PRODUCT_PREP_TYPE_ITEMS_BATCH );
		$requests              = WC_Facebookcommerce_Utils::prepare_product_requests_items_batch($facebook_product_data);

		$this->api->expects( $this->once() )
			->method( 'send_item_updates' )
			->with(
				$this->integration->get_product_catalog_id(),
				$requests
			)
			->willReturn( new API\ProductCatalog\ItemsBatch\Create\Response( '{"handles":"abcxyz"}' ) );

		$facebook_product_item_id = $this->integration->create_product_simple( $facebook_product, 'facebook-simple-product-group-id' );
	}

	/**
	 * Tests create product group for product with variants.
	 *
	 * @return void
	 */
	public function test_create_product_group_creates_group_with_variants() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$retailer_id      = 'product-retailer-id';
		$data             = [
			'retailer_id' => $retailer_id,
			'variants'    => $facebook_product->prepare_variants_for_group(),
		];
		$this->api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( new API\ProductCatalog\ProductGroups\Create\Response( '{"id":"facebook-product-group-id"}' ) );

		$facebook_product_group_id = $this->integration->create_product_group( $facebook_product, $retailer_id, true );

		$this->assertEquals( 'facebook-product-group-id', $facebook_product_group_id );
		$this->assertEquals( 'facebook-product-group-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Tests create product group fails due to some facebook error and returns empty string.
	 *
	 * @return void
	 */
	public function test_create_product_group_fails_to_create_group() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '123456789101112' );

		$product          = WC_Helper_Product::create_variation_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		add_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id' );
		$retailer_id      = 'product-retailer-id';
		$data             = [
			'retailer_id' => $retailer_id,
			'variants' => $facebook_product->prepare_variants_for_group(),
		];
		$this->api->expects( $this->once() )
			->method( 'create_product_group' )
			->with( '123456789101112', $data )
			->willReturn( new API\ProductCatalog\ProductGroups\Create\Response( '{"error":{"message":"Unsupported post request. Object with ID \'4964146013695812\' does not exist, cannot be loaded due to missing permissions, or does not support this operation. Please read the Graph API documentation at https:\/\/developers.facebook.com\/docs\/graph-api","type":"GraphMethodException","code":100,"error_subcode":33,"fbtrace_id":"AtmMkt0H2dwNBhdRfcYqzVY"}}' ) );

		$facebook_product_group_id = $this->integration->create_product_group( $facebook_product, $retailer_id );

		$this->assertEquals( '', $facebook_product_group_id );
	}

	/**
	 * Tests create product item creates product item and sets it into meta.
	 *
	 * @return void
	 */
	public function test_create_product_item_creates_product_item() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$product_group_id = 'facebook-product-group-id';
		$retailer_id      = 'product-retailer-id';
		$data             = $facebook_product->prepare_product( $retailer_id );
		$this->api->expects( $this->once() )
			->method( 'create_product_item' )
			->with( 'facebook-product-group-id', $data )
			->willReturn( new API\ProductCatalog\Products\Create\Response( '{"id":"facebook-product-item-id"}' ) );

		$facebook_item_id = $this->integration->create_product_item( $facebook_product, $retailer_id, $product_group_id );

		$this->assertEquals( 'facebook-product-item-id', $facebook_item_id );
		$this->assertEquals( 'facebook-product-item-id', get_post_meta( $facebook_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
	}

	/**
	 * Tests create product item fails to create and returns empty string.
	 *
	 * @return void
	 */
	public function test_create_product_item_fails_to_create_product_item() {
		$product          = WC_Helper_Product::create_simple_product();
		$facebook_product = new WC_Facebook_Product( $product->get_id() );
		$product_group_id = 'facebook-product-group-id';
		$retailer_id      = 'product-retailer-id';
		$data             = $facebook_product->prepare_product( $retailer_id );
		$this->api->expects( $this->once() )
			->method( 'create_product_item' )
			->with( 'facebook-product-group-id', $data )
			->willReturn( new API\ProductCatalog\Products\Create\Response( '{"error":{"message":"Unsupported post request. Object with ID \'4964146013695812\' does not exist, cannot be loaded due to missing permissions, or does not support this operation. Please read the Graph API documentation at https:\/\/developers.facebook.com\/docs\/graph-api","type":"GraphMethodException","code":100,"error_subcode":33,"fbtrace_id":"AtmMkt0H2dwNBhdRfcYqzVY"}}' ) );

		$facebook_item_id = $this->integration->create_product_item( $facebook_product, $retailer_id, $product_group_id );

		$this->assertEquals( '', $facebook_item_id );
	}

	/**
	 * Tests update product item updates product item.
	 *
	 * @return void
	 */
	public function test_update_product_item_updates_product_item() {
		$product                       = WC_Helper_Product::create_simple_product();
		$facebook_product              = new WC_Facebook_Product( $product->get_id() );
		$facebook_product_item_id      = 'facebook-product-item-id';
		$data                          = $facebook_product->prepare_product();
		$data['additional_image_urls'] = '';
		$this->api->expects( $this->once() )
			->method( 'update_product_item' )
			->with( 'facebook-product-item-id', $data )
			->willReturn( new API\ProductCatalog\Products\Update\Response( '{"success":true}' ) );

		$this->integration->update_product_item( $facebook_product, $facebook_product_item_id );
	}

	/**
	 * Tests transient message.
	 *
	 * @return void
	 */
	public function test_display_info_message() {
		$this->integration->display_info_message( 'Test message.' );

		$this->assertEquals( '<b>Meta for WooCommerce</b><br/>Test message.', get_transient( 'facebook_plugin_api_info' ) );
	}

	/**
	 * Tests transient message.
	 *
	 * @return void
	 */
	public function test_display_sticky_message() {
		$this->integration->display_sticky_message( 'Test message.' );

		$this->assertEquals( '<b>Meta for WooCommerce</b><br/>Test message.', get_transient( 'facebook_plugin_api_sticky' ) );
	}

	/**
	 * Tests transient message removal.
	 *
	 * @return void
	 */
	public function test_remove_sticky_message() {
		set_transient( 'facebook_plugin_api_sticky', 'Some test text.', 60 );

		$this->integration->remove_sticky_message();

		$this->assertFalse( get_transient( 'facebook_plugin_api_sticky' ) );
	}

	/**
	 * Tests remove re-sync message removes the message.
	 *
	 * @return void
	 */
	public function test_remove_resync_message_removes_the_message() {
		set_transient( 'facebook_plugin_api_sticky', 'Sync some test message.' );
		set_transient( 'facebook_plugin_resync_sticky', 'Some re-sync test message.' );

		$this->integration->remove_resync_message();

		$this->assertFalse( get_transient( 'facebook_plugin_resync_sticky' ) );
	}

	/**
	 * Tests remove re-sync message does not remove the message
	 * if facebook_plugin_api_sticky transient message does not
	 * start with 'Sync'.
	 *
	 * @return void
	 */
	public function test_remove_resync_message_does_not_remove_the_message_when_sticky_message_starts_with_no_sync() {
		set_transient( 'facebook_plugin_api_sticky', 'Some test message.' );
		set_transient( 'facebook_plugin_resync_sticky', 'Some re-sync test message.' );

		$this->integration->remove_resync_message();

		$this->assertEquals( 'Some re-sync test message.', get_transient( 'facebook_plugin_resync_sticky' ) );
	}

	/**
	 * Tests remove re-sync message does not remove the message
	 * if facebook_plugin_api_sticky transient message is empty.
	 *
	 * @return void
	 */
	public function test_remove_resync_message_does_not_remove_the_message_when_sticky_message_is_empty() {
		set_transient( 'facebook_plugin_api_sticky', '' );
		set_transient( 'facebook_plugin_resync_sticky', 'Some re-sync test message.' );

		$this->integration->remove_resync_message();

		$this->assertEquals( 'Some re-sync test message.', get_transient( 'facebook_plugin_resync_sticky' ) );
	}

	/**
	 * Tests display error message adds transient message.
	 *
	 * @return void
	 */
	public function test_display_error_message() {
		$this->integration->display_error_message( 'Hello, this is a test message.' );

		$this->assertEquals( 'Hello, this is a test message.', get_transient( 'facebook_plugin_api_error' ) );
	}

	/**
	 * Tests ajax_woo_adv_bulk_edit_compat for user with appropriate permissions.
	 *
	 * @return void
	 */
	public function test_ajax_woo_adv_bulk_edit_compat_for_user_with_appropriate_permissions() {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_settings_url' )
			->willReturn( 'https://settings.site/settings.php' );

		$_POST['type'] = 'product';

		$this->integration->ajax_woo_adv_bulk_edit_compat( 'dummy' );

		$this->assertTrue( is_admin(), 'Current user must be an admin user.' );
		$this->assertEquals(
			'<b>Meta for WooCommerce</b><br/>' .
			'Products may be out of Sync with Facebook due to your recent advanced bulk edit.' .
			' <a href="https://settings.site/settings.php&fb_force_resync=true&remove_sticky=true">Re-Sync them with FB.</a>',
			get_transient( 'facebook_plugin_api_sticky' )
		);
	}

	/**
	 * Tests ajax_woo_adv_bulk_edit_compat for user without appropriate permissions.
	 *
	 * @return void
	 */
	public function test_ajax_woo_adv_bulk_edit_compat_for_user_without_appropriate_permissions() {
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_settings_url' );

		$_POST['type'] = 'product';
		$this->integration->ajax_woo_adv_bulk_edit_compat( 'dummy' );
	}

	/**
	 * Tests display out of sync message sets a message with settings url.
	 *
	 * @return void
	 */
	public function test_display_out_of_sync_message() {
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_settings_url' )
			->willReturn( 'https://settings.site/settings.php' );

		$this->integration->display_out_of_sync_message( 'some text to insert into message' );

		$this->assertEquals(
			'<b>Meta for WooCommerce</b><br/>' .
			'Products may be out of Sync with Facebook due to your recent some text to insert into message.' .
			' <a href="https://settings.site/settings.php&fb_force_resync=true&remove_sticky=true">Re-Sync them with FB.</a>',
			get_transient( 'facebook_plugin_api_sticky' )
		);
	}

	/**
	 * Tests get existing facebook id returns facebook product group id from error data.
	 *
	 * @return void
	 */
	public function test_get_existing_fbid_returns_product_group_id() {
		$product_id                   = 123456789;
		$error_data                   = new stdClass();
		$error_data->product_group_id = 'facebook-product-group-id';
		$error_data->product_item_id  = 'facebook-product-item-id';

		$facebook_id = $this->integration->get_existing_fbid( $error_data, $product_id );

		$this->assertEquals( 'facebook-product-group-id', $facebook_id );
		$this->assertEquals( 'facebook-product-group-id', get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
	}

	/**
	 * Tests get existing facebook id returns facebook product item id from error data.
	 *
	 * @return void
	 */
	public function test_get_existing_fbid_returns_product_item_id() {
		$product_id                  = 123456789;
		$error_data                  = new stdClass();
		$error_data->product_item_id = 'facebook-product-item-id';

		$facebook_id = $this->integration->get_existing_fbid( $error_data, $product_id );

		$this->assertEquals( 'facebook-product-item-id', $facebook_id );
		$this->assertEquals( 'facebook-product-item-id', get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
	}

	/**
	 * Tests get existing facebook id returns nothing and does nothing.
	 *
	 * @return void
	 */
	public function test_get_existing_fbid_returns_does_nothing() {
		$product_id = 123456789;
		$error_data = new stdClass();

		$this->integration->get_existing_fbid( $error_data, $product_id );

		$this->assertEmpty( get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEmpty( get_post_meta( $product_id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
	}

	/**
	 * Tests display of error messages depending on GET parameter present and its value.
	 *
	 * @return void
	 */
	public function test_checks_triggers_display_errors() {
		$_GET['page'] = 'wc-facebook';

		$this->integration->errors = [
			'Some error message one.',
			'Some error message two.',
		];

		set_transient( 'facebook_plugin_api_error', 'Facebook plugin api error message text.' );
		set_transient( 'facebook_plugin_api_warning', 'Facebook plugin api warning text.' );
		set_transient( 'facebook_plugin_api_success', 'Facebook plugin api success text.' );
		set_transient( 'facebook_plugin_api_info', 'Facebook plugin api info text.' );
		set_transient( 'facebook_plugin_api_sticky', 'Facebook plugin api sticky text.' );

		ob_start();
		$this->integration->checks();
		$output = ob_get_clean();

		/* Check produced function output expected. */
		$this->assertEquals(
			'<div id="woocommerce_errors" class="error notice is-dismissible"><p>Some error message one.</p><p>Some error message two.</p></div><div class="notice is-dismissible notice-error"><p><strong>Meta for WooCommerce error:</strong></br>Facebook plugin api error message text.</p></div><div class="notice is-dismissible notice-warning"><p>Facebook plugin api warning text.</p></div><div class="notice is-dismissible notice-success"><p>Facebook plugin api success text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api info text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api sticky text.</p></div>',
			$output
		);
	}

	/**
	 * Tests does display facebook api messages, no errors.
	 *
	 * @return void
	 */
	public function test_checks_does_not_trigger_display_errors() {
		set_transient( 'facebook_plugin_api_error', 'Facebook plugin api error message text.' );
		set_transient( 'facebook_plugin_api_warning', 'Facebook plugin api warning text.' );
		set_transient( 'facebook_plugin_api_success', 'Facebook plugin api success text.' );
		set_transient( 'facebook_plugin_api_info', 'Facebook plugin api info text.' );
		set_transient( 'facebook_plugin_api_sticky', 'Facebook plugin api sticky text.' );

		ob_start();
		$this->integration->checks();
		$output = ob_get_clean();

		/* Check produced function output expected. */
		$this->assertEquals(
			'<div class="notice is-dismissible notice-error"><p><strong>Meta for WooCommerce error:</strong></br>Facebook plugin api error message text.</p></div><div class="notice is-dismissible notice-warning"><p>Facebook plugin api warning text.</p></div><div class="notice is-dismissible notice-success"><p>Facebook plugin api success text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api info text.</p></div><div class="notice is-dismissible notice-info"><p>Facebook plugin api sticky text.</p></div>',
			$output
		);
	}

	/**
	 * Tests get_sample_product_feed to return proper JSON with 12 recent products.
	 *
	 * @return void
	 */
	public function test_get_sample_product_feed_with_twelve_recent_products() {
		/* Generate 13 products. */
		$product_retailer_ids = array_slice(
			array_map(
				function ( $index ) {
					/** @var WC_Product_Simple $product */
					$product = WC_Helper_Product::create_simple_product();
					$product->set_name( 'Test product ' . ( $index + 1 ) );
					$product->save();

					return WC_Facebookcommerce_Utils::get_fb_retailer_id( new WC_Facebook_Product( $product ) );
				},
				array_keys( array_fill( 0, 13, true ) )
			),
			1
		);

		/* Feed of 12 recent products. */
		$json = $this->integration->get_sample_product_feed();

		/* 12 recent products with product titled "Test product 1" missing from the feed. */
		$returned_product_retailer_ids = array_reverse(
			array_map(
				function ( $product ) {
					return $product['id'];
				},
				current( json_decode( $json, true ) )
			)
		);
		$this->assertEquals( $product_retailer_ids, $returned_product_retailer_ids );
		$this->assertCount( 12, current( json_decode( $json ) ) );
	}

	/**
	 * Tests delete post meta loop deletes meta for the given products.
	 *
	 * @return void
	 */
	public function test_delete_post_meta_loop() {
		/* Generate 13 products. */
		$products = array_map(
			function ( $index ) {
				/** @var WC_Product_Simple $product */
				$product = WC_Helper_Product::create_simple_product();
				$product->set_name( 'Test product ' . ( $index + 1 ) );
				$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-' . ( $index + 1 ) );
				$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-' . ( $index + 1 ) );
				$product->add_meta_data( Products::VISIBILITY_META_KEY, true );
				$product->save();

				return $product->get_id();
			},
			array_keys( array_fill( 0, 3, true ) )
		);

		$this->integration->delete_post_meta_loop( $products );

		$this->assertEquals( '', get_post_meta( $products[0], WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[1], WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[2], WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );

		$this->assertEquals( '', get_post_meta( $products[0], WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[1], WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $products[2], WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );

		$this->assertEquals( '', get_post_meta( $products[0], Products::VISIBILITY_META_KEY, true ) );
		$this->assertEquals( '', get_post_meta( $products[1], Products::VISIBILITY_META_KEY, true ) );
		$this->assertEquals( '', get_post_meta( $products[2], Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests reset all product facebook meta does nothing is not admin user.
	 *
	 * @return void
	 */
	public function test_reset_all_products_as_non_admin_user() {
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] );

		$this->assertFalse( $this->integration->reset_all_products() );
	}

	/**
	 * Tests reset all product facebook meta, including variation products if any.
	 *
	 * @return void
	 */
	public function test_reset_all_products_as_admin_user() {
		$user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit-post' );

		/** @var WC_Product_Simple $product */
		$product =   WC_Helper_Product::create_simple_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-1' );
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-1' );
		$product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$product->save();

		/** @var WC_Product_Variable $variable_product */
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-2' );
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-2' );
		$variable_product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$variable_product->save();

		$result = $this->integration->reset_all_products();

		$this->assertTrue( $result );

		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		foreach ( $variable_product->get_children() as $id ) {
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, Products::VISIBILITY_META_KEY, true ) );
		}
	}

	/**
	 * Tests reset of facebook group and item ids from simple product's metadata.
	 *
	 * @return void
	 */
	public function test_reset_single_product_for_simple_product() {
		/** @var WC_Product_Simple $product */
		$product = WC_Helper_Product::create_simple_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-1' );
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-1' );
		$product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$product->save();

		$this->integration->reset_single_product( $product->get_id() );

		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests reset of facebook group and item ids from variable product's metadata.
	 *
	 * @return void
	 */
	public function test_reset_single_product_for_variable_product() {
		/** @var WC_Product_Variable $variable_product */
		$variable_product = WC_Helper_Product::create_variation_product();
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, 'facebook-product-group-id-2' );
		$variable_product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'facebook-product-item-id-2' );
		$variable_product->add_meta_data( Products::VISIBILITY_META_KEY, true );
		$variable_product->save();

		$this->integration->reset_single_product( $variable_product->get_id() );

		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
		$this->assertEquals( '', get_post_meta( $variable_product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		foreach ( $variable_product->get_children() as $id ) {
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, true ) );
			$this->assertEquals( '', get_post_meta( $id, Products::VISIBILITY_META_KEY, true ) );
		}
	}

	/**
	 * Tests get_product_catalog_id returns product catalog id from options with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_product_catalog_id_returns_product_catalog_from_options_using_no_filter() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, '321321321321321321' );
		$this->teardown_callback_category_safely( 'wc_facebook_product_catalog_id' );

		$product_catalog_id = $this->integration->get_product_catalog_id();

		$this->assertEquals( '321321321321321321', $product_catalog_id );
	}

	/**
	 * Tests get_product_catalog_id returns product catalog id with filters on it.
	 *
	 * @return void
	 */
	public function test_get_product_catalog_id_returns_product_catalog_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_product_catalog_id',
			function ( $product_catalog_id ) {
				return '3213-2132-1321-3213-2132';
			}
		);

		$product_catalog_id = $this->integration->get_product_catalog_id();

		$this->assertEquals( '3213-2132-1321-3213-2132', $product_catalog_id );
	}

	/**
	 * Tests get_external_merchant_settings_id returns id from object properly with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_external_merchant_settings_id_returns_settings_id_from_initialised_property_using_no_filter() {
		$this->integration->external_merchant_settings_id = '123123123123123123';
		$this->teardown_callback_category_safely( 'wc_facebook_external_merchant_settings_id' );

		$external_merchant_settings_id = $this->integration->get_external_merchant_settings_id();

		$this->assertEquals( '123123123123123123', $external_merchant_settings_id );
	}

	/**
	 * Tests get_external_merchant_settings_id returns id from options with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_external_merchant_settings_id_returns_settings_id_from_options_using_no_filter() {
		$this->integration->external_merchant_settings_id = null;
		add_option( WC_Facebookcommerce_Integration::OPTION_EXTERNAL_MERCHANT_SETTINGS_ID, '321321321321321321' );
		$this->teardown_callback_category_safely( 'wc_facebook_external_merchant_settings_id' );

		$external_merchant_settings_id = $this->integration->get_external_merchant_settings_id();

		$this->assertEquals( '321321321321321321', $external_merchant_settings_id );
		$this->assertEquals( '321321321321321321', $this->integration->external_merchant_settings_id );
	}

	/**
	 * Tests get_external_merchant_settings_id returns id with filters on it.
	 *
	 * @return void
	 */
	public function test_get_external_merchant_settings_id_returns_settings_id_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_external_merchant_settings_id',
			function ( $external_merchant_settings_id ) {
				return '3213-2132-1321-3213-2132';
			}
		);

		$external_merchant_settings_id = $this->integration->get_external_merchant_settings_id();

		$this->assertEquals( '3213-2132-1321-3213-2132', $external_merchant_settings_id );
	}

	/**
	 * Tests get_feed_id returns id from object properly with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_feed_id_returns_id_from_initialised_property_using_no_filter() {
		$this->integration->feed_id = '123123123123123123';
		$this->teardown_callback_category_safely( 'wc_facebook_feed_id' );

		$feed_id = $this->integration->get_feed_id();

		$this->assertEquals( '123123123123123123', $feed_id );
	}

	/**
	 * Tests get_feed_id returns id from options with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_feed_id_returns_id_from_options_using_no_filter() {
		$this->integration->feed_id = null;
		add_option( WC_Facebookcommerce_Integration::OPTION_FEED_ID, '321321321321321321' );
		$this->teardown_callback_category_safely( 'wc_facebook_feed_id' );

		$feed_id = $this->integration->get_feed_id();

		$this->assertEquals( '321321321321321321', $feed_id );
		$this->assertEquals( '321321321321321321', $this->integration->feed_id );
	}

	/**
	 * Tests get_feed_id returns id with filters on it.
	 *
	 * @return void
	 */
	public function test_get_feed_id_returns_id_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_feed_id',
			function ( $feed_id ) {
				return '3213-2132-1321-3213-2132';
			}
		);

		$feed_id = $this->integration->get_feed_id();

		$this->assertEquals( '3213-2132-1321-3213-2132', $feed_id );
	}

	/**
	 * Tests get_upload_id returns id from options with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_upload_id_returns_id_from_options_using_no_filter() {
		add_option( WC_Facebookcommerce_Integration::OPTION_UPLOAD_ID, '321321321321321321' );
		$this->teardown_callback_category_safely( 'wc_facebook_upload_id' );

		$upload_id = $this->integration->get_upload_id();

		$this->assertEquals( '321321321321321321', $upload_id );
	}

	/**
	 * Tests get_upload_id returns id with filters on it.
	 *
	 * @return void
	 */
	public function test_get_upload_id_returns_id_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_upload_id',
			function ( $upload_id ) {
				return '3213-2132-1321-3213-2132';
			}
		);

		$upload_id = $this->integration->get_upload_id();

		$this->assertEquals( '3213-2132-1321-3213-2132', $upload_id );
	}

	/**
	 * Tests get_pixel_install_time returns id from object properly with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_pixel_install_time_returns_id_from_initialised_property_using_no_filter() {
		$this->integration->pixel_install_time = '123123123123123123';
		$this->teardown_callback_category_safely( 'wc_facebook_pixel_install_time' );

		$pixel_install_time = $this->integration->get_pixel_install_time();

		$this->assertEquals( '123123123123123123', $pixel_install_time );
	}

	/**
	 * Tests get_pixel_install_time returns id from options with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_pixel_install_time_returns_id_from_options_using_no_filter() {
		$this->integration->pixel_install_time = null;
		add_option( WC_Facebookcommerce_Integration::OPTION_PIXEL_INSTALL_TIME, '321321321321321321' );
		$this->teardown_callback_category_safely( 'wc_facebook_pixel_install_time' );

		$pixel_install_time = $this->integration->get_pixel_install_time();

		$this->assertEquals( '321321321321321321', $pixel_install_time );
		$this->assertEquals( '321321321321321321', $this->integration->pixel_install_time );
	}

	/**
	 * Tests get_pixel_install_time returns id with filters on it.
	 *
	 * @return void
	 */
	public function test_get_pixel_install_time_returns_id_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_pixel_install_time',
			function ( $pixel_install_time ) {
				return '321321321321';
			}
		);

		$pixel_install_time = $this->integration->get_pixel_install_time();

		$this->assertEquals( 321321321321, $pixel_install_time );
	}

	/**
	 * Tests get_js_sdk_version returns version from options with no filters on it.
	 *
	 * @return void
	 */
	public function test_get_js_sdk_version_returns_id_from_options_using_no_filter() {
		add_option( WC_Facebookcommerce_Integration::OPTION_JS_SDK_VERSION, 'v1.0.0' );
		$this->teardown_callback_category_safely( 'wc_facebook_js_sdk_version' );

		$js_sdk_version = $this->integration->get_js_sdk_version();

		$this->assertEquals( 'v1.0.0', $js_sdk_version );
	}

	/**
	 * Tests get_js_sdk_version returns version with filters on it.
	 *
	 * @return void
	 */
	public function test_get_js_sdk_version_returns_id_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_js_sdk_version',
			function ( $js_sdk_version ) {
				return 'v2.0.0';
			}
		);

		$js_sdk_version = $this->integration->get_js_sdk_version();

		$this->assertEquals( 'v2.0.0', $js_sdk_version );
	}

	/**
	 * Tests get facebook page id no filters applied.
	 *
	 * @return void
	 */
	public function test_get_facebook_page_id_no_filters() {
		$this->setExpectedDeprecated( WC_Facebookcommerce_Integration::class . '::get_facebook_page_id' );
		$this->teardown_callback_category_safely( 'wc_facebook_page_id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, '222333111444555666777' );

		$facebook_page_id = $this->integration->get_facebook_page_id();

		$this->assertEquals( '222333111444555666777', $facebook_page_id );
	}

	/**
	 * Tests get facebook page id with filter.
	 *
	 * @return void
	 */
	public function test_get_facebook_page_id_with_filter() {
		$this->setExpectedDeprecated( WC_Facebookcommerce_Integration::class . '::get_facebook_page_id' );
		$this->add_filter_with_safe_teardown(
			'wc_facebook_page_id',
			function ( $facebook_page_id ) {
				return '444333222111999888777666555';
			}
		);

		$facebook_page_id = $this->integration->get_facebook_page_id();

		$this->assertEquals( '444333222111999888777666555', $facebook_page_id );
	}

	/**
	 * Tests get facebook pixel id no filters applied.
	 *
	 * @return void
	 */
	public function test_get_facebook_pixel_id_no_filters() {
		$this->teardown_callback_category_safely( 'wc_facebook_pixel_id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PIXEL_ID, '222333111444555666777' );

		$facebook_pixel_id = $this->integration->get_facebook_pixel_id();

		$this->assertEquals( '222333111444555666777', $facebook_pixel_id );
	}

	/**
	 * Tests get facebook pixel id with filter.
	 *
	 * @return void
	 */
	public function test_get_facebook_pixel_id_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_pixel_id',
			function ( $facebook_pixel_id ) {
				return '444333222111999888777666555';
			}
		);

		$facebook_pixel_id = $this->integration->get_facebook_pixel_id();

		$this->assertEquals( '444333222111999888777666555', $facebook_pixel_id );
	}

	/**
	 * Tests product catalog id option update with valid catalog id value.
	 *
	 * @return void
	 */
	public function test_update_product_catalog_id_with_valid_id() {
		$id = '11223344556677889900';

		$this->integration->update_product_catalog_id( $id );

		$this->assertEquals( '11223344556677889900', get_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
	}

	/**
	 * Tests product catalog id option update with invalid catalog id value.
	 *
	 * @return void
	 */
	public function test_update_product_catalog_id_with_invalid_id() {
		$id = 1241231;

		$this->integration->update_product_catalog_id( $id );

		$this->assertEquals( '', get_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID ) );
	}

	/**
	 * Tests update external merchant settings id with valid id.
	 *
	 * @return void
	 */
	public function test_update_external_merchant_settings_id_with_valid_id() {
		$id = '41234123512351234';

		$this->integration->update_external_merchant_settings_id( $id );

		$this->assertEquals( '41234123512351234', get_option( WC_Facebookcommerce_Integration::OPTION_EXTERNAL_MERCHANT_SETTINGS_ID ) );
	}

	/**
	 * Tests update external merchant settings id with invalid id.
	 *
	 * @return void
	 */
	public function test_update_external_merchant_settings_id_with_invalid_id() {
		$id = 43513451324;

		$this->integration->update_external_merchant_settings_id( $id );

		$this->assertEquals( '', get_option( WC_Facebookcommerce_Integration::OPTION_EXTERNAL_MERCHANT_SETTINGS_ID ) );
	}

	/**
	 * Tests update feed id with valid id.
	 *
	 * @return void
	 */
	public function test_update_feed_id_with_valid_id() {
		$id = '41234123512351234';

		$this->integration->update_feed_id( $id );

		$this->assertEquals( '41234123512351234', get_option( WC_Facebookcommerce_Integration::OPTION_FEED_ID ) );
	}

	/**
	 * Tests update feed id with invalid id.
	 *
	 * @return void
	 */
	public function test_update_feed_id_with_invalid_id() {
		$id = 43513451324;

		$this->integration->update_feed_id( $id );

		$this->assertEquals( '', get_option( WC_Facebookcommerce_Integration::OPTION_FEED_ID ) );
	}

	/**
	 * Tests update upload id with valid id.
	 *
	 * @return void
	 */
	public function test_update_upload_id_with_valid_id() {
		$id = '41234123512351234';

		$this->integration->update_upload_id( $id );

		$this->assertEquals( '41234123512351234', get_option( WC_Facebookcommerce_Integration::OPTION_UPLOAD_ID ) );
	}

	/**
	 * Tests update upload id with invalid id.
	 *
	 * @return void
	 */
	public function test_update_upload_id_with_invalid_id() {
		$id = 43513451324;

		$this->integration->update_upload_id( $id );

		$this->assertEquals( '', get_option( WC_Facebookcommerce_Integration::OPTION_UPLOAD_ID ) );
	}

	/**
	 * Tests update facebook pixel install time with valid value.
	 *
	 * @return void
	 */
	public function test_update_pixel_install_time_with_valid_value() {
		$id = 1659519256;

		$this->integration->update_pixel_install_time( $id );

		$this->assertEquals( 1659519256, get_option( WC_Facebookcommerce_Integration::OPTION_PIXEL_INSTALL_TIME ) );
	}

	/**
	 * Tests update facebook pixel install time with invalid value.
	 *
	 * @return void
	 */
	public function test_update_pixel_install_time_with_invalid_value() {
		$id = null;

		$this->integration->update_pixel_install_time( $id );

		$this->assertEquals( '', get_option( WC_Facebookcommerce_Integration::OPTION_PIXEL_INSTALL_TIME ) );
	}

	/**
	 * Tests update js sdk version with valid value.
	 *
	 * @return void
	 */
	public function test_update_js_sdk_version_with_valid_value() {
		$id = 'v1.2.1';

		$this->integration->update_js_sdk_version( $id );

		$this->assertEquals( 'v1.2.1', get_option( WC_Facebookcommerce_Integration::OPTION_JS_SDK_VERSION ) );
	}

	/**
	 * Tests update js sdk version with invalid value.
	 *
	 * @return void
	 */
	public function test_update_js_sdk_version_with_invalid_value() {
		$id = null;

		$this->integration->update_js_sdk_version( $id );

		$this->assertEquals( '', get_option( WC_Facebookcommerce_Integration::OPTION_JS_SDK_VERSION ) );
	}

	/**
	 * Tests is configured returns true.
	 *
	 * @return void
	 */
	public function test_is_configured_returns_true() {
		add_option(
			WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID,
			'facebook-page-id'
		);
		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$result = $this->integration->is_configured();

		$this->assertTrue( $result );
	}

	/**
	 * A shop connected without a Facebook Page — an Instagram-only asset selection,
	 * for example — is configured. The Page is not used to reach any Meta endpoint
	 * and is optional during onboarding, so requiring it here disabled product sync
	 * on otherwise healthy stores.
	 *
	 * @return void
	 */
	public function test_is_configured_returns_true_without_a_facebook_page_id() {
		delete_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID );
		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( true );

		$result = $this->integration->is_configured();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is configured returns false when is not connected to facebook.
	 *
	 * @return void
	 */
	public function test_is_configured_returns_false_is_not_connected() {
		add_option(
			WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID,
			'facebook-page-id'
		);
		$this->connection_handler->expects( $this->once() )
			->method( 'is_connected' )
			->willReturn( false );

		$result = $this->integration->is_configured();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is advanced matching enabled with no filter return default value.
	 *
	 * @return void
	 */
	public function test_is_advanced_matching_enabled_no_filter() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_advanced_matching_enabled' );

		$output = $this->integration->is_advanced_matching_enabled();

		$this->assertTrue( $output );
	}

	/**
	 * Tests is advanced matching enabled with filter.
	 *
	 * @return void
	 */
	public function test_is_advanced_matching_enabled_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_is_advanced_matching_enabled',
			function ( $is_enabled ) {
				return false;
			}
		);

		$output = $this->integration->is_advanced_matching_enabled();

		$this->assertFalse( $output );
	}

	/**
	 * Tests is legacy feed file generation enabled with no option set.
	 *
	 * @return void
	 */
	public function test_is_legacy_feed_file_generation_enabled_no_option() {
		delete_option( WC_Facebookcommerce_Integration::OPTION_LEGACY_FEED_FILE_GENERATION_ENABLED );

		$result = $this->integration->is_legacy_feed_file_generation_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is legacy feed file generation enabled with option set.
	 *
	 * @return void
	 */
	public function test_is_legacy_feed_file_generation_enabled_with_option() {
		add_option(
			WC_Facebookcommerce_Integration::OPTION_LEGACY_FEED_FILE_GENERATION_ENABLED,
			'no'
		);

		$result = $this->integration->is_legacy_feed_file_generation_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is debug mode enabled returns default value.
	 *
	 * @return void
	 */
	public function test_is_debug_mode_enabled_returns_default_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_debug_mode_enabled' );
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_DEBUG_MODE );

		$result = $this->integration->is_debug_mode_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is debug mode enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_debug_mode_enabled_returns_option_value() {
		$this->teardown_callback_category_safely( 'wc_facebook_is_debug_mode_enabled' );
		add_option(
			WC_Facebookcommerce_Integration::SETTING_ENABLE_DEBUG_MODE,
			'yes'
		);

		$result = $this->integration->is_debug_mode_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is debug mode enabled with filter.
	 *
	 * @return void
	 */
	public function test_is_debug_mode_enabled_with_filter() {
		$this->add_filter_with_safe_teardown(
			'wc_facebook_is_debug_mode_enabled',
			function ( $is_enabled ) {
				return false;
			}
		);
		add_option(
			WC_Facebookcommerce_Integration::SETTING_ENABLE_DEBUG_MODE,
			'yes'
		);

		$result = $this->integration->is_debug_mode_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is new style feed generation enabled returns default value.
	 *
	 * @return void
	 */
	public function test_is_new_style_feed_generation_enabled_default_value() {
		delete_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_NEW_STYLE_FEED_GENERATOR );

		$result = $this->integration->is_new_style_feed_generation_enabled();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is new style feed generation enabled returns option value.
	 *
	 * @return void
	 */
	public function test_is_new_style_feed_generation_enabled_option_value() {
		add_option(
			WC_Facebookcommerce_Integration::SETTING_ENABLE_NEW_STYLE_FEED_GENERATOR,
			'yes'
		);

		$result = $this->integration->is_new_style_feed_generation_enabled();

		$this->assertTrue( $result );
	}

	/**
	 * Tests are headers requested for debug default value.
	 *
	 * @return void
	 */
	public function test_are_headers_requested_for_debug_default_value() {
		delete_option( WC_Facebookcommerce_Integration::SETTING_REQUEST_HEADERS_IN_DEBUG_MODE );

		$result = $this->integration->are_headers_requested_for_debug();

		$this->assertFalse( $result );
	}

	/**
	 * Tests are headers requested for debug option value.
	 *
	 * @return void
	 */
	public function test_are_headers_requested_for_debug_option_value() {
		add_option(
			WC_Facebookcommerce_Integration::SETTING_REQUEST_HEADERS_IN_DEBUG_MODE,
			true
		);

		$result = $this->integration->are_headers_requested_for_debug();

		$this->assertTrue( $result );
	}

	/**
	 * Tests is feed migrated returns default value.
	 *
	 * @return void
	 */
	public function test_is_feed_migrated_default_value() {
		delete_option( 'wc_facebook_feed_migrated' );

		$result = $this->integration->is_feed_migrated();

		$this->assertFalse( $result );
	}

	/**
	 * Tests is feed migrated returns option value.
	 *
	 * @return void
	 */
	public function test_is_feed_migrated_option_value() {
		add_option( 'wc_facebook_feed_migrated', 'yes' );

		$result = $this->integration->is_feed_migrated();

		$this->assertTrue( $result );
	}

	/**
	 * Tests maybe display facebook api messages displays all the possible messages.
	 *
	 * @return void
	 */
	public function test_maybe_display_facebook_api_messages() {
		set_transient( 'facebook_plugin_api_error', 'Api error message.' );
		set_transient( 'facebook_plugin_api_warning', 'Api warning message.' );
		set_transient( 'facebook_plugin_api_success', 'Api success message.' );
		set_transient( 'facebook_plugin_api_info', 'Api info message.' );
		set_transient( 'facebook_plugin_api_sticky', 'Api sticky message.' );

		ob_start();
		$this->integration->maybe_display_facebook_api_messages();
		$output = ob_get_clean();

		$this->assertEquals( '<div class="notice is-dismissible notice-error"><p><strong>Meta for WooCommerce error:</strong></br>Api error message.</p></div><div class="notice is-dismissible notice-warning"><p>Api warning message.</p></div><div class="notice is-dismissible notice-success"><p>Api success message.</p></div><div class="notice is-dismissible notice-info"><p>Api info message.</p></div><div class="notice is-dismissible notice-info"><p>Api sticky message.</p></div>', $output );

		$this->assertEmpty( get_transient( 'facebook_plugin_api_error' ) );
		$this->assertEmpty( get_transient( 'facebook_plugin_api_warning' ) );
		$this->assertEmpty( get_transient( 'facebook_plugin_api_success' ) );
		$this->assertEmpty( get_transient( 'facebook_plugin_api_info' ) );
		$this->assertEquals( 'Api sticky message.', get_transient( 'facebook_plugin_api_sticky' ) );
	}

	/**
	 * Tests admin options renders html.
	 *
	 * @return void
	 */
	public function test_admin_options() {
		$message_handler = $this->createMock( AdminMessageHandler::class );
		$message_handler->expects( $this->once() )
			->method( 'show_messages' );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_message_handler' )
			->willReturn( $message_handler );

		ob_start();
		$this->integration->admin_options();
		$output = ob_get_clean();

		$this->assertEquals(
			'<div id="integration-settings" style="display: none"><table class="form-table"></table></div>',
			$output
		);
	}

	/**
	 * Tests filter function.
	 *
	 * @return void
	 */
	public function test_fb_duplicate_product_reset_meta() {
		$output = $this->integration->fb_duplicate_product_reset_meta( [] );

		$this->assertEquals(
			[
				WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID,
				WC_Facebookcommerce_Integration::FB_PRODUCT_GROUP_ID,
			],
			$output
		);
	}

	/**
	 * Tests update facebook visibility does nothing
	 * when connection is not configured or there is no
	 * facebook catalog id present.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_not_configured_no_catalog_id() {
		/* Make is_configured() return false. */
		delete_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID );
		/* Make get_product_catalog_id() return false. */
		delete_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID );

		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->api->expects( $this->never() )
			->method( 'update_product_item' );

		$this->integration->update_fb_visibility( 123, '' );
	}

	/**
	 * Tests update facebook visibility does nothing if no product exists.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_no_such_product() {
		$this->facebook_for_woocommerce->expects( $this->never() )
			->method( 'get_products_sync_handler' );

		$this->api->expects( $this->never() )
			->method( 'update_product_item' );

		$this->integration->update_fb_visibility( 123, '' );
	}

	/**
	 * Tests visibility update to hidden for variation product.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_to_hidden_for_variation_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'facebook-catalog-id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		$this->connection_handler->expects( $this->once() )->method( 'is_connected' )->willReturn( true );

		$product   = WC_Helper_Product::create_variation_product();
		$variation = wc_get_product( $product->get_children()[0] );

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( [ $variation->get_id() ] );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->update_fb_visibility(
			$variation->get_id(),
			WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN
		);

		$this->assertEquals( 'no', get_post_meta( $variation->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}
	/**
	 * Tests visibility update to published for variation product.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_to_published_for_variation_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'facebook-catalog-id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		$this->connection_handler->expects( $this->once() )->method( 'is_connected' )->willReturn( true );

		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( Products::VISIBILITY_META_KEY, 'no' );
		$product->save_meta_data();

		$variation = wc_get_product( $product->get_children()[0] );
		$variation->add_meta_data( Products::VISIBILITY_META_KEY, 'no' );
		$variation->save_meta_data();

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( [ $variation->get_id() ] );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->update_fb_visibility(
			$variation->get_id(),
			WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE
		);

		$this->assertEquals( 'yes', get_post_meta( $variation->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests product visibility update to hidden for variable product and all of its variations.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_to_hidden_for_variable_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'facebook-catalog-id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		$this->connection_handler->expects( $this->once() )->method( 'is_connected' )->willReturn( true );

		$product = WC_Helper_Product::create_variation_product();

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $product->get_children() );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->update_fb_visibility(
			$product->get_id(),
			WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN
		);

		$this->assertEquals( 'no', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		foreach ( $product->get_children() as $variation ) {
			$variation = wc_get_product( $variation );
			$this->assertEquals( 'no', get_post_meta( $variation->get_id(), Products::VISIBILITY_META_KEY, true ) );
		}
	}

	/**
	 * Tests product visibility update to published for variable product and all of its variations.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_to_published_for_variable_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'facebook-catalog-id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		$this->connection_handler->expects( $this->once() )->method( 'is_connected' )->willReturn( true );

		$product = WC_Helper_Product::create_variation_product();
		$product->add_meta_data( Products::VISIBILITY_META_KEY, 'no' );
		$product->save_meta_data();

		foreach ( $product->get_children() as $variation ) {
			$variation = wc_get_product( $variation );
			$variation->add_meta_data( Products::VISIBILITY_META_KEY, 'no' );
			$variation->save_meta_data();
		}

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->with( $product->get_children() );

		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->update_fb_visibility(
			$product->get_id(),
			WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE
		);

		$this->assertEquals( 'yes', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );

		foreach ( $product->get_children() as $variation ) {
			$variation = wc_get_product( $variation );
			$this->assertEquals( 'yes', get_post_meta( $variation->get_id(), Products::VISIBILITY_META_KEY, true ) );
		}
	}

	/**
	 * Tests update facebook product visibility to hidden for simple product.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_to_hidden_for_simple_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'facebook-catalog-id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		$this->connection_handler->expects( $this->once() )->method( 'is_connected' )->willReturn( true );

		$product = WC_Helper_Product::create_simple_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'some-facebook-product-group-id' );
		$product->save_meta_data();

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->willReturn('');
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );


		$this->integration->update_fb_visibility(
			$product->get_id(),
			WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_HIDDEN
		);

		$this->assertEquals( 'no', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests update facebook product visibility to published for simple product.
	 *
	 * @return void
	 */
	public function test_update_fb_visibility_to_published_for_simple_product() {
		add_option( WC_Facebookcommerce_Integration::OPTION_PRODUCT_CATALOG_ID, 'facebook-catalog-id' );
		add_option( WC_Facebookcommerce_Integration::SETTING_FACEBOOK_PAGE_ID, 'facebook-page-id' );
		$this->connection_handler->expects( $this->once() )->method( 'is_connected' )->willReturn( true );

		$product = WC_Helper_Product::create_simple_product();
		$product->add_meta_data( WC_Facebookcommerce_Integration::FB_PRODUCT_ITEM_ID, 'some-facebook-product-group-id' );
		$product->add_meta_data( Products::VISIBILITY_META_KEY, 'no' );
		$product->save_meta_data();

		$sync_handler = $this->createMock( Products\Sync::class );
		$sync_handler->expects( $this->once() )
			->method( 'create_or_update_products' )
			->willReturn('');
		$this->facebook_for_woocommerce->expects( $this->once() )
			->method( 'get_products_sync_handler' )
			->willReturn( $sync_handler );

		$this->integration->update_fb_visibility(
			$product->get_id(),
			WC_Facebookcommerce_Integration::FB_SHOP_PRODUCT_VISIBLE
		);

		$this->assertEquals( 'yes', get_post_meta( $product->get_id(), Products::VISIBILITY_META_KEY, true ) );
	}

	/**
	 * Tests if meta diagnosis is enabled by default.
	 *
	 * @return void
	 */
	public function  test_meta_diagnosis_enabled_by_default() {
		$this->assertEquals( 'yes', get_option( WC_Facebookcommerce_Integration::SETTING_ENABLE_META_DIAGNOSIS ) );
	}
}
