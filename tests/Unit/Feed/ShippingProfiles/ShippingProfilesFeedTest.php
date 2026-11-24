<?php
/** Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

use WooCommerce\Facebook\Feed\ShippingProfilesFeed;

require_once __DIR__ . '/../FeedDataTestBase.php';

/**
 * Class ShippingProfilesFeedUploadTest
 */
class ShippingProfilesFeedTest extends FeedDataTestBase {
	public function test_basic_free_shipping_data(): void {
		$zone = self::create_default_shipping_zone();
        self::create_and_save_method_instance($zone, 'free_shipping', array());

		$result = ShippingProfilesFeed::get_shipping_profiles_data();

		$expected_shipping_profile_data = [
			'shipping_profile_id'      => $zone->get_id().'-all_products',
			'name'                     => 'California',
			'applies_to_all_products'  => 'true',
			'shipping_zones'           => [
				[
					'country'                   => 'US',
					'states'                    => [ 'CA' ],
					'applies_to_entire_country' => false,
				],
			],
			'shipping_rates'           => [
				[
					'name'              => 'Free shipping',
					'has_free_shipping' => 'true',
				],
			],
			'applies_to_rest_of_world' => 'false',
		];

		$this->assertCount( 1, $result, 'Expected one shipping profile returned.' );
		$this->assertEquals( $expected_shipping_profile_data, $result[0], 'Shipping profile output does not match expected data.' );
	}


	// Only sync rates that don't require a coupon
	public function test_free_shipping_requirements(): void {
		$zone = self::create_default_shipping_zone();

        $free_ship_min_amount_name = 'Free Ship Min Amount';
        $free_ship_either_name = 'Free Ship Either';
		$no_requirement_name = 'No Requirement';

		// Should not get translated
        self::create_and_save_method_instance($zone, 'free_shipping', array('requires' => 'coupon'));
        self::create_and_save_method_instance($zone, 'free_shipping', array('requires' => 'both'));

		// Should get translated
		self::create_and_save_method_instance($zone, 'free_shipping', array('requires' => '', 'title' => $no_requirement_name));
        self::create_and_save_method_instance($zone, 'free_shipping', array('requires' => 'min_amount', 'title' => $free_ship_min_amount_name, 'min_amount' => '3'));
        self::create_and_save_method_instance($zone, 'free_shipping', array('requires' => 'either',  'title' => $free_ship_either_name, 'min_amount' => '3'));

		$result = ShippingProfilesFeed::get_shipping_profiles_data();

        $expected_shipping_profile_data = [
            'shipping_profile_id'      => $zone->get_id().'-all_products',
            'name'                     => 'California',
            'applies_to_all_products'  => 'true',
            'shipping_zones'           => [
                [
                    'country'                   => 'US',
                    'states'                    => [ 'CA' ],
                    'applies_to_entire_country' => false,
                ],
            ],
            'shipping_rates'           => [
				[
					'name'              => $no_requirement_name,
					'has_free_shipping' => 'true',
				],
                [
                    'name'              => $free_ship_min_amount_name,
                    'has_free_shipping' => 'true',
					'cart_minimum_for_free_shipping' => '3 USD',
                ],
                [
                    'name'              => $free_ship_either_name,
                    'has_free_shipping' => 'true',
					'cart_minimum_for_free_shipping' => '3 USD',
                ],
            ],
            'applies_to_rest_of_world' => 'false',
        ];

        $this->assertCount( 1, $result, 'Expected one shipping profile.' );
        $this->assertEqualsCanonicalizing( $expected_shipping_profile_data, $result[0],  'Shipping profile output does not match expected data.' );
	}

    // Empty rates should result in no shipping profile data
    public function test_empty_rates(): void {
        self::create_default_shipping_zone();
        $result = ShippingProfilesFeed::get_shipping_profiles_data();
        $this->assertEmpty($result, 'Expected no shipping returned.' );
    }

	// We should not sync shipping profiles with a min threshold of
	public function test_ignore_discounts_setting(): void {
		$zone = self::create_default_shipping_zone();
		$ignore_discounts_min_amount_name = 'ignore_discounts_with_min_amount';
		$ignore_discounts_no_requirement_name = 'ignore_discounts_with_no_requirement';
		$dont_ignore_discounts_name = 'dont_ignore_discounts';
		self::create_and_save_method_instance($zone, 'free_shipping', array('requires' => 'min_amount', 'ignore_discounts' => 'yes', 'title' => $ignore_discounts_min_amount_name));
		// We don't care about the ignoring discounts field if there is no subtotal requirement on the shipping profile.
		self::create_and_save_method_instance($zone, 'free_shipping', array('ignore_discounts' => 'yes', 'title' => $ignore_discounts_no_requirement_name));
		self::create_and_save_method_instance($zone, 'free_shipping', array('ignore_discounts' => 'no',  'title' => $dont_ignore_discounts_name));

		$result = ShippingProfilesFeed::get_shipping_profiles_data();

		$expected_shipping_profile_data = [
			'shipping_profile_id'      => $zone->get_id().'-all_products',
			'name'                     => 'California',
			'applies_to_all_products'  => 'true',
			'shipping_zones'           => [
				[
					'country'                   => 'US',
					'states'                    => [ 'CA' ],
					'applies_to_entire_country' => false,
				],
			],

			'shipping_rates'           => [
				[
					'name'              => $ignore_discounts_no_requirement_name,
					'has_free_shipping' => 'true',
				],
				[
					'name'              => $dont_ignore_discounts_name,
					'has_free_shipping' => 'true',
				],
			],
			'applies_to_rest_of_world' => 'false',
		];


		$this->assertCount( 1, $result, 'Expected one shipping profile returned.' );
		$this->assertEqualsCanonicalizing( $expected_shipping_profile_data, $result[0], 'Shipping profile output does not match expected data.' );
	}

	public function test_flat_rate_shipping(): void {
		$zone = self::create_default_shipping_zone();
		$basic_flat_rate_shipping_no_cost = 'basic_flat_rate_shipping_no_cost';
		$with_shipping_classes = 'with_shipping_classes';
		self::create_and_save_method_instance($zone, 'flat_rate', array('title' => $basic_flat_rate_shipping_no_cost, 'cost' => '0'));
		self::create_and_save_method_instance($zone, 'flat_rate', array('title' => $with_shipping_classes, 'cost' => '0', 'class_cost_1' => '0', 'class_cost_2' => '1', 'no_class_cost' => '0'));

		// Should not sync
		$has_base_cost = 'has_base_cost';
		self::create_and_save_method_instance($zone, 'flat_rate', array('title' => $has_base_cost, 'cost' => '1', 'class_cost_4' => '0'));


		$result = ShippingProfilesFeed::get_shipping_profiles_data();
		$expected_shipping_profile_data = [
			[
				'shipping_profile_id'      => $zone->get_id().'-all_products',
				'name'                     => 'California',
				'applies_to_all_products'  => 'true',
				'shipping_zones'           => [
					[
						'country'                   => 'US',
						'states'                    => [ 'CA' ],
						'applies_to_entire_country' => false,
					],
				],
				'shipping_rates'           => [
					[
						'name'              => $basic_flat_rate_shipping_no_cost,
						'has_free_shipping' => 'true',
					],
				],
				'applies_to_rest_of_world' => 'false',
			],
			[
				'shipping_profile_id'      => $zone->get_id().'-1',
				'name'                     => 'California',
				'applies_to_all_products'  => 'false',
				'applicable_products_filter' => '{"tags":{"eq":"shipping_class_1"}}',
				'shipping_zones'           => [
					[
						'country'                   => 'US',
						'states'                    => [ 'CA' ],
						'applies_to_entire_country' => false,
					],
				],
				'shipping_rates'           => [
					[
						'name'              => $with_shipping_classes,
						'has_free_shipping' => 'true',
					],
				],
				'applies_to_rest_of_world' => 'false',
			],
			[
				'shipping_profile_id'      => $zone->get_id().'-0',
				'name'                     => 'California',
				'applies_to_all_products'  => 'false',
				'applicable_products_filter' => '{"tags":{"eq":"no_shipping_class"}}',
				'shipping_zones'           => [
					[
						'country'                   => 'US',
						'states'                    => [ 'CA' ],
						'applies_to_entire_country' => false,
					],
				],
				'shipping_rates'           => [
					[
						'name'              => $with_shipping_classes,
						'has_free_shipping' => 'true',
					],
				],
				'applies_to_rest_of_world' => 'false',
			],
		];

		$this->assertCount( 3, $result, 'Expected one shipping profile returned.' );
		$this->assertEqualsCanonicalizing( $expected_shipping_profile_data, $result, 'Shipping profile output does not match expected data.' );
	}

	public function test_applies_to_rest_of_world_not_synced(): void {
		$rest_of_the_world_zone = \WC_Shipping_Zones::get_zone_by( 'zone_id', 0 );
		$this->assertEquals( 'Locations not covered by your other zones', $rest_of_the_world_zone->get_zone_name() );
		self::create_and_save_method_instance($rest_of_the_world_zone, 'free_shipping', array());
		$result = ShippingProfilesFeed::get_shipping_profiles_data();
		$this->assertEmpty($result, 'Expected no shipping returned.' );
	}


	public function testCountry(): void {
		$zone = new WC_Shipping_Zone();
		$zone->add_location( 'CA', 'country' );
		$zone->save();
		self::create_and_save_method_instance($zone, 'free_shipping', array());
		$result = ShippingProfilesFeed::get_shipping_profiles_data();
		$shipping_zone_data = $result[0]['shipping_zones'];

		$expected_shipping_zones = [
			[
				'country'                   => 'CA',
				'states'                    => [],
				'applies_to_entire_country' => true,
			],
		];
		$this->assertEqualsCanonicalizing( $expected_shipping_zones, $shipping_zone_data );


		$zone->add_location('CA:BC', 'state');
		$zone->save();
		$result = ShippingProfilesFeed::get_shipping_profiles_data();
		$shipping_zone_data = $result[0]['shipping_zones'];
		$expected_shipping_zones = [
			[
				'country'                   => 'CA',
				'states'                    => ['BC'],
				'applies_to_entire_country' => true,
			],
		];
		$this->assertEqualsCanonicalizing( $expected_shipping_zones, $shipping_zone_data );
	}

	public function testContinent(): void {
		$zone = new WC_Shipping_Zone();
		$zone->add_location( 'NA', 'continent' );
		$zone->save();
		self::create_and_save_method_instance($zone, 'free_shipping', array());
		$result = ShippingProfilesFeed::get_shipping_profiles_data();
		$shipping_zone_data = $result[0]['shipping_zones'];

		// Use a few example countries to assert on
		$expected_shipping_zones = [
			[
				'country'                   => 'US',
				'states'                    => [],
				'applies_to_entire_country' => true,
			],
			[
				'country'                   => 'CA',
				'states'                    => [],
				'applies_to_entire_country' => true,
			],
			[
				'country'                   => 'CA',
				'states'                    => [],
				'applies_to_entire_country' => true,
			],
		];
		foreach($expected_shipping_zones as $expected_shipping_zone) {
			$this->assertContains($expected_shipping_zone, $shipping_zone_data);
	 	}

		// Prevent too many asserts by just making sure every one is true.
		$every_country_data_applies_to_entire_country = true;
		foreach($shipping_zone_data as $country_data) {
			if (!$country_data['applies_to_entire_country']) {
				$every_country_data_applies_to_entire_country = false;
			}
		}
		$this->asserttrue($every_country_data_applies_to_entire_country);
	}

	public function testEntireWorld(): void {
		$zone = new WC_Shipping_Zone();
		$zone->save();
		self::create_and_save_method_instance($zone, 'free_shipping', array());
		$result = ShippingProfilesFeed::get_shipping_profiles_data();
		$shipping_zone_data = $result[0]['shipping_zones'];

		$expected_shipping_zones = array_map(
			function ( $country_code ) {
				return [
					'country'                   => $country_code,
					'states'                    => [],
					'applies_to_entire_country' => true,
				];
			},
			array_keys( WC()->countries->get_countries() )
		);

		$this->assertEqualsCanonicalizing( $expected_shipping_zones, $shipping_zone_data );

		// Prevent too many asserts by just making sure every one is true.
		$every_country_data_applies_to_entire_country = true;
		foreach($shipping_zone_data as $country_data) {
			if (!$country_data['applies_to_entire_country']) {
				$every_country_data_applies_to_entire_country = false;
			}
		}
		$this->asserttrue($every_country_data_applies_to_entire_country);
	}

	// Dont Sync if Local Pickup
	private static function create_default_shipping_zone(): WC_Shipping_Zone {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'California' );
		$zone->set_zone_order( 3 );
		$zone->add_location( 'US:CA', 'state' );
		$zone->save();
		return $zone;
	}

	public function test_disabled_shipping_method(): void {
		$zone = self::create_default_shipping_zone();
		// We don't care about the ignoring discounts field if there is no subtotal requirement on the shipping profile.
		self::create_and_save_method_instance($zone, 'free_shipping', array('ignore_discounts' => 'yes', 'title' => 'enabled_method'), true);
		self::create_and_save_method_instance($zone, 'free_shipping', array('ignore_discounts' => 'no',  'title' => 'disabled_method'), false);


		$result = ShippingProfilesFeed::get_shipping_profiles_data();

		$expected_shipping_profile_data = [
			'shipping_profile_id'      => $zone->get_id().'-all_products',
			'name'                     => 'California',
			'applies_to_all_products'  => 'true',
			'shipping_zones'           => [
				[
					'country'                   => 'US',
					'states'                    => [ 'CA' ],
					'applies_to_entire_country' => false,
				],
			],
			'shipping_rates'           => [
				[
					'name'              => 'enabled_method',
					'has_free_shipping' => 'true',
				],
			],
			'applies_to_rest_of_world' => 'false',
		];

		$this->assertCount( 1 , $result, 'Expected one shipping profile returned.' );
		$this->assertEquals( $expected_shipping_profile_data, $result[0], 'Shipping profile output does not match expected data.' );
	}



	private static function create_and_save_method_instance( WC_Shipping_Zone $zone, $method_type, array $settings, bool $is_enabled = true ): void {
		global $wpdb;
        $method_instance_id = $zone->add_shipping_method( $method_type );
        $method            = $zone->get_shipping_methods()[ $method_instance_id ];

		$method->init_instance_settings();
		$instance_settings = $method->instance_settings;

		foreach ( $settings as $key => $value ) {
			$instance_settings[ $key ] = $value;
		}

		update_option( $method->get_instance_option_key(), apply_filters( 'woocommerce_shipping_' . $method->id . '_instance_settings_values', $instance_settings, $method ) );

		// Update enabled flag
		$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $is_enabled ), array( 'instance_id' => $method_instance_id) );
		do_action( 'woocommerce_shipping_zone_method_status_toggled', $method_instance_id, $method->id, $zone->get_id(), $is_enabled );

        $zone->save();
	}

	/**
	 * Test get_feed_type method returns correct feed type.
	 *
	 * @covers WooCommerce\Facebook\Feed\ShippingProfilesFeed::get_feed_type
	 */
	public function test_get_feed_type(): void {
		$result = ShippingProfilesFeed::get_feed_type();
		$this->assertEquals( 'SHIPPING_PROFILES', $result, 'Feed type should be SHIPPING_PROFILES.' );
	}

	/**
	 * Test get_shipping_class_tag_for_class with NO_SHIPPING_CLASS_ID.
	 *
	 * @covers WooCommerce\Facebook\Feed\ShippingProfilesFeed::get_shipping_class_tag_for_class
	 */
	public function test_get_shipping_class_tag_for_no_shipping_class(): void {
		$result = ShippingProfilesFeed::get_shipping_class_tag_for_class( ShippingProfilesFeed::NO_SHIPPING_CLASS_ID );
		$this->assertEquals( ShippingProfilesFeed::NO_SHIPPING_CLASS_TAG, $result, 'Should return no_shipping_class tag for NO_SHIPPING_CLASS_ID.' );
	}

	/**
	 * Test get_shipping_class_tag_for_class with regular class IDs.
	 *
	 * @covers WooCommerce\Facebook\Feed\ShippingProfilesFeed::get_shipping_class_tag_for_class
	 */
	public function test_get_shipping_class_tag_for_regular_class(): void {
		$test_cases = [
			'1'   => 'shipping_class_1',
			'2'   => 'shipping_class_2',
			'123' => 'shipping_class_123',
		];

		foreach ( $test_cases as $class_id => $expected_tag ) {
			$result = ShippingProfilesFeed::get_shipping_class_tag_for_class( $class_id );
			$this->assertEquals( $expected_tag, $result, "Should return {$expected_tag} for class ID {$class_id}." );
		}
	}

	/**
	 * Test constructor creates instance successfully.
	 *
	 * @covers WooCommerce\Facebook\Feed\ShippingProfilesFeed::__construct
	 */
	public function test_constructor_creates_instance(): void {
		$instance = new ShippingProfilesFeed();
		$this->assertInstanceOf( ShippingProfilesFeed::class, $instance, 'Should create an instance of ShippingProfilesFeed.' );
	}
}
