<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\ProductSync;

use WC_Helper_Product;
use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Integrations\WPML;
use WooCommerce\Facebook\ProductSync\ProductExcludedException;
use WooCommerce\Facebook\RolloutSwitches;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Tests which products the validator keeps in the main catalog on a multilingual store.
 *
 * WPML is stood in for by its public filters, answering the way WPML 4.7 does: language
 * codes such as 'pt-br' and 'no' for products and for the default language, and locales
 * such as 'pt_BR' and 'nb_NO' in the active languages list.
 */
class ProductValidatorLanguageTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var array<string, mixed> the registry's private state before the test */
	private $registry_state = array();

	/** @var string|null WPML's default language code */
	private $default_code;

	/** @var array<string, string> WPML language code => locale, for the active languages */
	private $active_languages = array();

	/** @var array<int, string> product ID => WPML language code */
	private $product_languages = array();

	public function setUp(): void {
		parent::setUp();

		$this->default_code      = null;
		$this->active_languages  = array();
		$this->product_languages = array();

		// The language check only runs while language override feeds are on.
		$this->mock_set_option(
			'wc_facebook_for_woocommerce_rollout_switches',
			array( RolloutSwitches::SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED => 'yes' )
		);

		// The real WPML integration, minus the check that the WPML plugin is installed and active.
		$wpml = new class() extends WPML {
			public function is_plugin_active(): bool {
				return true;
			}
		};
		$this->set_registry_state(
			array(
				'localization_integrations' => array( 'wpml' => get_class( $wpml ) ),
				'integration_instances'     => array( 'wpml' => $wpml ),
			)
		);

		$this->add_filter_with_safe_teardown(
			'wpml_default_language',
			function () {
				return $this->default_code;
			}
		);
		$this->add_filter_with_safe_teardown(
			'wpml_active_languages',
			function () {
				$languages = array();
				foreach ( $this->active_languages as $code => $locale ) {
					$languages[ $code ] = array(
						'code'           => $code,
						'default_locale' => $locale,
					);
				}
				return $languages;
			}
		);
		$this->add_filter_with_safe_teardown(
			'wpml_post_language_details',
			function ( $details, $post_id ) {
				if ( ! isset( $this->product_languages[ $post_id ] ) ) {
					return $details;
				}
				return array( 'language_code' => $this->product_languages[ $post_id ] );
			},
			10,
			2
		);
	}

	public function tearDown(): void {
		$this->set_registry_state( $this->registry_state );
		parent::tearDown();
	}

	/**
	 * Swaps the registry's private lists, remembering what they held the first time.
	 *
	 * @param array<string, mixed> $state property name => value
	 */
	private function set_registry_state( array $state ): void {
		foreach ( $state as $name => $value ) {
			$property = new \ReflectionProperty( IntegrationRegistry::class, $name );
			$property->setAccessible( true );
			if ( ! array_key_exists( $name, $this->registry_state ) ) {
				$this->registry_state[ $name ] = $property->getValue();
			}
			$property->setValue( null, $value );
		}
	}

	/**
	 * Sets up a store with a default language and one more language.
	 *
	 * @param string $default_code   WPML code of the default language
	 * @param string $default_locale its locale
	 * @param string $other_code     WPML code of the second language
	 * @param string $other_locale   its locale
	 */
	private function set_store_languages( string $default_code, string $default_locale, string $other_code, string $other_locale ): void {
		$this->default_code     = $default_code;
		$this->active_languages = array(
			$default_code => $default_locale,
			$other_code   => $other_locale,
		);
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public function provide_language_pairs(): array {
		return array(
			'plain two-letter codes'                => array( 'en', 'en_US', 'sr', 'sr_RS' ),
			'WPML regional code (pt-br for pt_BR)'  => array( 'pt-br', 'pt_BR', 'sr', 'sr_RS' ),
			'custom code with a hyphen (en-us)'     => array( 'en-us', 'en_US', 'sr', 'sr_RS' ),
			'code unrelated to the locale (no)'     => array( 'no', 'nb_NO', 'sr', 'sr_RS' ),
			'two variants of one language (pt)'     => array( 'pt-br', 'pt_BR', 'pt-pt', 'pt_PT' ),
			'two scripts of one language (zh)'      => array( 'zh-hans', 'zh_CN', 'zh-hant', 'zh_TW' ),
			'regional variant of the default (en)'  => array( 'en', 'en_US', 'en-gb', 'en_GB' ),
			'default is the regional variant (en)'  => array( 'en-gb', 'en_GB', 'en', 'en_US' ),
		);
	}

	/**
	 * @dataProvider provide_language_pairs
	 */
	public function test_default_language_product_syncs_and_its_translation_does_not( string $default_code, string $default_locale, string $other_code, string $other_locale ): void {
		$this->set_store_languages( $default_code, $default_locale, $other_code, $other_locale );

		$original    = WC_Helper_Product::create_simple_product();
		$translation = WC_Helper_Product::create_simple_product();

		$this->product_languages[ $original->get_id() ]    = $default_code;
		$this->product_languages[ $translation->get_id() ] = $other_code;

		$this->assertTrue(
			facebook_for_woocommerce()->get_product_sync_validator( $original )->passes_all_checks(),
			"A product in the default language \"{$default_code}\" ({$default_locale}) belongs in the main catalog."
		);
		$this->assertFalse(
			facebook_for_woocommerce()->get_product_sync_validator( $translation )->passes_all_checks(),
			"A \"{$other_code}\" translation stays out of the main catalog of a \"{$default_code}\" store."
		);
	}

	public function test_exclusion_message_names_the_product_language_and_the_default_locale(): void {
		$this->set_store_languages( 'pt-br', 'pt_BR', 'pt-pt', 'pt_PT' );

		$translation                                       = WC_Helper_Product::create_simple_product();
		$this->product_languages[ $translation->get_id() ] = 'pt-pt';

		try {
			facebook_for_woocommerce()->get_product_sync_validator( $translation )->validate();
			$this->fail( 'A translation must be excluded.' );
		} catch ( ProductExcludedException $e ) {
			$this->assertStringContainsString( 'pt-pt', $e->getMessage() );
			$this->assertStringContainsString( 'pt_BR', $e->getMessage() );
		}
	}

	public function test_language_codes_are_compared_without_regard_to_case(): void {
		$this->set_store_languages( 'en-US', 'en_US', 'sr', 'sr_RS' );

		$product                                       = WC_Helper_Product::create_simple_product();
		$this->product_languages[ $product->get_id() ] = 'en-us';

		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $product )->passes_all_checks() );
	}

	public function test_product_without_a_language_syncs(): void {
		$this->set_store_languages( 'pt-br', 'pt_BR', 'sr', 'sr_RS' );

		$product = WC_Helper_Product::create_simple_product();

		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $product )->passes_all_checks() );
	}

	public function test_variation_follows_the_language_of_its_parent(): void {
		$this->set_store_languages( 'pt-br', 'pt_BR', 'sr', 'sr_RS' );

		$original    = WC_Helper_Product::create_variation_product();
		$translation = WC_Helper_Product::create_variation_product();

		$this->product_languages[ $original->get_id() ]    = 'pt-br';
		$this->product_languages[ $translation->get_id() ] = 'sr';

		$original_variation    = wc_get_product( $original->get_children()[0] );
		$translation_variation = wc_get_product( $translation->get_children()[0] );

		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $original_variation )->passes_all_checks() );
		$this->assertFalse( facebook_for_woocommerce()->get_product_sync_validator( $translation_variation )->passes_all_checks() );
	}

	public function test_nothing_is_excluded_while_wpml_has_no_default_language(): void {
		$this->active_languages = array(
			'en' => 'en_US',
			'sr' => 'sr_RS',
		);

		$product                                       = WC_Helper_Product::create_simple_product();
		$this->product_languages[ $product->get_id() ] = 'sr';

		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $product )->passes_all_checks() );
	}

	public function test_nothing_is_excluded_while_language_override_feeds_are_off(): void {
		$this->set_store_languages( 'en', 'en_US', 'sr', 'sr_RS' );
		$this->mock_set_option(
			'wc_facebook_for_woocommerce_rollout_switches',
			array( RolloutSwitches::SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED => 'no' )
		);

		$product                                       = WC_Helper_Product::create_simple_product();
		$this->product_languages[ $product->get_id() ] = 'sr';

		$this->assertTrue( facebook_for_woocommerce()->get_product_sync_validator( $product )->passes_all_checks() );
	}

	public function test_integration_without_its_own_identifier_maps_the_default_locale(): void {
		$integration = new class() extends \WooCommerce\Facebook\Integrations\Abstract_Localization_Integration {
			public function get_plugin_file_name(): string {
				return 'example/example.php';
			}
			public function get_plugin_name(): string {
				return 'Example';
			}
			public function is_plugin_active(): bool {
				return true;
			}
			public function get_available_languages(): array {
				return array( 'pt_BR' );
			}
			public function get_default_language(): ?string {
				return 'pt_BR';
			}
			protected function get_plugin_language_identifier( string $locale ): ?string {
				return 'pt_BR' === $locale ? 'brazil' : null;
			}
			protected function is_product_in_language( int $product_id, string $language_identifier ): bool {
				return false;
			}
			public function switch_to_language( string $locale ): ?string {
				return null;
			}
			public function restore_language( string $language_code ): void {}
		};

		$this->assertSame( 'brazil', $integration->get_default_language_identifier() );
	}
}
