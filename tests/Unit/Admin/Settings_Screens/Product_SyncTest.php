<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

namespace WooCommerce\Facebook\Tests\Admin\Settings_Screens;

use PHPUnit\Framework\TestCase;
use WooCommerce\Facebook\Admin\Settings_Screens\Product_Sync;
use WooCommerce\Facebook\RolloutSwitches;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Class Product_SyncTest
 *
 * @package WooCommerce\Facebook\Tests\Unit\Admin\Settings_Screens
 */
class Product_SyncTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

    /**
     * @var Product_Sync
     */
    private $product_sync;

    /**
     * Set up the test environment
     */
    public function setUp(): void {
        parent::setUp();

        // Instantiate the Product_Sync class for each test
        $this->product_sync = new Product_Sync();
    }

    /**
     * Test that the constructor hooks actions for init, enqueue, and custom fields
     */
    public function test_constructor_adds_hooks() {
        global $wp_filter;

        // Check that all expected hooks are present
        $this->assertArrayHasKey('init', $wp_filter);
        $this->assertArrayHasKey('admin_enqueue_scripts', $wp_filter);
        $this->assertArrayHasKey('woocommerce_admin_field_product_sync_title', $wp_filter);
        $this->assertArrayHasKey('woocommerce_admin_field_product_sync_google_product_categories', $wp_filter);
    }

    /**
     * Test that initHook sets the id, label, title, and documentation_url properties
     */
    public function test_initHook_sets_properties() {
        $this->product_sync->initHook();

        $reflection = new \ReflectionClass($this->product_sync);
        $id = $reflection->getProperty('id');
        $id->setAccessible(true);
        $label = $reflection->getProperty('label');
        $label->setAccessible(true);
        $title = $reflection->getProperty('title');
        $title->setAccessible(true);
        $doc_url = $reflection->getProperty('documentation_url');
        $doc_url->setAccessible(true);

        $this->assertEquals(Product_Sync::ID, $id->getValue($this->product_sync));
        $this->assertEquals(__('Product sync', 'facebook-for-woocommerce'), $label->getValue($this->product_sync));
        $this->assertEquals(__('Product sync', 'facebook-for-woocommerce'), $title->getValue($this->product_sync));
        $this->assertEquals('https://woocommerce.com/document/facebook-for-woocommerce/#product-sync-settings', $doc_url->getValue($this->product_sync));
    }

    /**
     * Test that get_settings returns an array
     */
    public function test_get_settings_returns_array() {
        $settings = $this->product_sync->get_settings();

        $this->assertIsArray($settings);
        $this->assertNotEmpty($settings);
    }

    /**
     * Test that get_id returns the expected value
     */
    public function test_get_id_returns_expected_value() {
        $this->product_sync->initHook();

        $this->assertEquals(Product_Sync::ID, $this->product_sync->get_id());
    }

    /**
     * Test that get_label returns the expected value and applies the filter
     */
    public function test_get_label_returns_expected_value_and_applies_filter() {
        $this->product_sync->initHook();

        $filter = 'wc_facebook_admin_settings_' . Product_Sync::ID . '_screen_label';
        add_filter($filter, function($label) { return 'Filtered Label'; });

        $this->assertEquals('Filtered Label', $this->product_sync->get_label());

        remove_all_filters($filter);
    }

    /**
     * Test that get_title returns the expected value and applies the filter
     */
    public function test_get_title_returns_expected_value_and_applies_filter() {
        $this->product_sync->initHook();

        $filter = 'wc_facebook_admin_settings_' . Product_Sync::ID . '_screen_title';
        add_filter($filter, function($title) { return 'Filtered Title'; });

        $this->assertEquals('Filtered Title', $this->product_sync->get_title());

        remove_all_filters($filter);
    }

    /**
     * Test that get_description returns the expected value and applies the filter
     */
    public function test_get_description_returns_expected_value_and_applies_filter() {
        $this->product_sync->initHook();

        $filter = 'wc_facebook_admin_settings_' . Product_Sync::ID . '_screen_description';
        add_filter($filter, function($desc) { return 'Filtered Description'; });

        $this->assertEquals('Filtered Description', $this->product_sync->get_description());

        remove_all_filters($filter);
    }

    /**
     * Test that get_disconnected_message links to the current settings page.
     */
    public function test_get_disconnected_message_links_to_settings_page() {
        $msg = $this->product_sync->get_disconnected_message();

        $this->assertIsString($msg);
        $this->assertStringContainsString('connect to Facebook', $msg);
        $this->assertStringContainsString('admin.php?page=wc-facebook', $msg);
        $this->assertStringNotContainsString('api.woocommerce.com', $msg);
        $this->assertStringNotContainsString('facebook.com/dialog/oauth', $msg);
        $this->assertStringContainsString('</a>', $msg);
    }

    /**
     * Test that render_title is callable and outputs HTML
     */
    public function test_render_title_is_callable() {
        ob_start();
        $this->product_sync->render_title(['title' => 'Product sync']);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('<h2>', $output);
    }

    /**
     * Test that render_google_product_category_field is callable and outputs HTML
     */
    public function test_render_google_product_category_field_is_callable() {
        ob_start();
        $this->product_sync->render_google_product_category_field([
            'id' => 'test_google_cat',
            'title' => 'Google Cat',
            'desc_tip' => 'desc',
            'type' => 'product_sync_google_product_categories',
            'value' => 'val',
        ]);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('<tr', $output);
    }

    /**
     * Test that enqueue_assets is callable and does not throw (integration test would be needed for full coverage)
     */
    public function test_enqueue_assets_is_callable() {
        try {
            // Should not throw even if dependencies are not fully mocked
            $this->product_sync->enqueue_assets();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('enqueue_assets() should not throw, got: ' . $e->getMessage());
        }
    }

    /**
     * Test the private get_default_google_product_category_modal_message method
     */
    public function test_get_default_google_product_category_modal_message() {
        $reflection = new \ReflectionClass($this->product_sync);
        $method = $reflection->getMethod('get_default_google_product_category_modal_message');
        $method->setAccessible(true);

        // Call the private method
        $msg = $method->invoke($this->product_sync);

        $this->assertIsString($msg);
        $this->assertStringContainsString('Products and categories that inherit this global setting', $msg);
    }

    /**
     * Test the private get_default_google_product_category_modal_message_empty method
     */
    public function test_get_default_google_product_category_modal_message_empty() {
        $reflection = new \ReflectionClass($this->product_sync);
        $method = $reflection->getMethod('get_default_google_product_category_modal_message_empty');
        $method->setAccessible(true);

        // Call the private method
        $msg = $method->invoke($this->product_sync);

        $this->assertIsString($msg);
        $this->assertStringContainsString('If you have cleared the Google Product Category', $msg);
    }

    /**
     * Test the private get_default_google_product_category_modal_buttons method
     */
    public function test_get_default_google_product_category_modal_buttons() {
        $reflection = new \ReflectionClass($this->product_sync);
        $method = $reflection->getMethod('get_default_google_product_category_modal_buttons');
        $method->setAccessible(true);

        // Call the private method
        $html = $method->invoke($this->product_sync);

        $this->assertIsString($html);
        $this->assertStringContainsString('button', $html);
        $this->assertStringContainsString('Update default Google product category', $html);
    }

    /**
     * Test that save is callable (integration test would be needed for full effect)
     */
    public function test_save_is_callable() {
        try {
            // Should not throw even if dependencies are not fully mocked
            $this->product_sync->save();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail('save() should not throw, got: ' . $e->getMessage());
        }
    }

    /**
     * Finds a settings field by its option id.
     */
    private function find_field( array $settings, string $id ): array {
        foreach ( $settings as $field ) {
            if ( isset( $field['id'] ) && $id === $field['id'] ) {
                return $field;
            }
        }
        $this->fail( "Field {$id} not found" );
    }

    /**
     * Saved exclusions must be rendered even when a term query filter (a language plugin, for
     * example) hides the terms, otherwise the multiselect drops them on the next save.
     */
    public function test_get_settings_keeps_saved_exclusions_hidden_by_a_term_query_filter() {
        $category_id = wp_insert_term( 'Hidden category', 'product_cat' )['term_id'];
        $tag_id      = wp_insert_term( 'Hidden tag', 'product_tag' )['term_id'];
        $this->mock_set_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, array( (string) $category_id ) );
        $this->mock_set_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_TAG_IDS, array( (string) $tag_id ) );

        // Hide both terms from every term query, the way a language plugin narrows results.
        $this->add_filter_with_safe_teardown(
            'get_terms',
            static function ( $terms ) use ( $category_id, $tag_id ) {
                if ( is_array( $terms ) ) {
                    unset( $terms[ $category_id ], $terms[ $tag_id ] );
                }
                return $terms;
            }
        );

        $settings = $this->product_sync->get_settings();

        $categories = $this->find_field( $settings, \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS )['options'];
        $tags       = $this->find_field( $settings, \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_TAG_IDS )['options'];
        $this->assertSame( 'Hidden category', $categories[ $category_id ] ?? null );
        $this->assertSame( 'Hidden tag', $tags[ $tag_id ] ?? null );
    }

    /** Polylang lifts its language filter when the query passes an empty lang. */
    public function test_get_settings_queries_terms_without_a_polylang_language_filter() {
        $seen_lang = array();
        $this->add_filter_with_safe_teardown(
            'get_terms_args',
            static function ( $args ) use ( &$seen_lang ) {
                $seen_lang[] = $args['lang'] ?? '(missing)';
                return $args;
            }
        );

        $this->product_sync->get_settings();

        $this->assertNotEmpty( $seen_lang );
        $this->assertSame( array( '' ), array_unique( $seen_lang ), 'Every term query must pass an empty lang so Polylang returns all languages.' );
    }

    /**
     * Under WPML both term queries run with the language switched to all, inside a single switch
     * that is undone afterwards.
     */
    public function test_get_settings_queries_all_languages_under_wpml() {
        $this->add_filter_with_safe_teardown( 'wpml_current_language', static function () { return 'de'; } );
        $language = 'de';
        $switches = array();
        $this->add_filter_with_safe_teardown(
            'wpml_switch_language',
            static function ( $switched_to ) use ( &$language, &$switches ) {
                $language   = $switched_to;
                $switches[] = $switched_to;
            }
        );
        $query_languages = array();
        $this->add_filter_with_safe_teardown(
            'get_terms_args',
            static function ( $args, $taxonomies ) use ( &$query_languages, &$language ) {
                $query_languages[ implode( ',', (array) $taxonomies ) ] = $language;
                return $args;
            },
            10,
            2
        );

        $this->product_sync->get_settings();

        $this->assertSame( array( 'product_cat' => 'all', 'product_tag' => 'all' ), $query_languages, 'Both term queries must run while WPML is on all languages.' );
        $this->assertSame( array( 'all', 'de' ), $switches, 'One switch to all languages, undone once afterwards.' );
        $this->assertSame( 'de', $language, 'The admin language is restored.' );
    }

    /**
     * The fallback has to be seeded from the raw option. The integration getters return the IDs
     * in effect, which is empty under the all-products rollout switch, while WooCommerce marks the
     * multiselect's selection from the raw option, so seeding from the getters left those IDs unrendered.
     */
    public function test_get_settings_keeps_saved_exclusions_while_the_all_products_switch_is_on() {
        $category_id = wp_insert_term( 'Hidden category', 'product_cat' )['term_id'];
        $this->mock_set_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, array( (string) $category_id ) );
        $this->mock_set_option( 'wc_facebook_for_woocommerce_rollout_switches', array( RolloutSwitches::SWITCH_WOO_ALL_PRODUCTS_SYNC_ENABLED => 'yes' ) );
        $this->add_filter_with_safe_teardown(
            'get_terms',
            static function ( $terms ) use ( $category_id ) {
                if ( is_array( $terms ) ) {
                    unset( $terms[ $category_id ] );
                }
                return $terms;
            }
        );
        $this->assertSame( array(), facebook_for_woocommerce()->get_integration()->get_excluded_product_category_ids(), 'Precondition: the switch makes the getter return nothing.' );

        $categories = $this->find_field( $this->product_sync->get_settings(), \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS )['options'];

        $this->assertSame( 'Hidden category', $categories[ $category_id ] ?? null );
    }

    /**
     * Under WPML the saved-ID lookup has to run while the language is still 'all': WPML's
     * "adjust IDs" option swaps a term for its current-language translation in AJAX and
     * front-end requests, which would leave the saved ID unrendered.
     */
    public function test_get_settings_looks_up_missing_saved_terms_while_wpml_is_on_all_languages() {
        $category_id = wp_insert_term( 'Hidden category', 'product_cat' )['term_id'];
        $this->mock_set_option( \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS, array( (string) $category_id ) );
        $this->add_filter_with_safe_teardown(
            'get_terms',
            static function ( $terms ) use ( $category_id ) {
                if ( is_array( $terms ) ) {
                    unset( $terms[ $category_id ] );
                }
                return $terms;
            }
        );
        $this->add_filter_with_safe_teardown( 'wpml_current_language', static function () { return 'de'; } );
        $language = 'de';
        $this->add_filter_with_safe_teardown(
            'wpml_switch_language',
            static function ( $switched_to ) use ( &$language ) {
                $language = $switched_to;
            }
        );
        $lookups = array();
        $this->add_filter_with_safe_teardown(
            'get_term',
            static function ( $term ) use ( &$lookups, &$language, $category_id ) {
                if ( $term instanceof \WP_Term && $term->term_id === $category_id ) {
                    $lookups[] = $language;
                }
                return $term;
            }
        );

        $this->product_sync->get_settings();

        $this->assertNotEmpty( $lookups, 'The hidden saved term must be looked up by ID.' );
        $this->assertSame( array( 'all' ), array_unique( $lookups ), 'The lookup must run while WPML is switched to all languages.' );
    }

    /**
     * A saved ID whose term no longer exists, or that belongs to the other taxonomy, is skipped:
     * get_term() returns null or a WP_Error for it and neither may end up in the options.
     */
    public function test_get_settings_skips_saved_exclusions_that_are_not_terms_of_the_taxonomy() {
        $deleted_id = wp_insert_term( 'Deleted category', 'product_cat' )['term_id'];
        wp_delete_term( $deleted_id, 'product_cat' );
        $tag_id = wp_insert_term( 'A tag', 'product_tag' )['term_id'];
        $this->mock_set_option(
            \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS,
            array( (string) $deleted_id, (string) $tag_id, '0', 'not-an-id' )
        );

        $categories = $this->find_field( $this->product_sync->get_settings(), \WC_Facebookcommerce_Integration::SETTING_EXCLUDED_PRODUCT_CATEGORY_IDS )['options'];

        $this->assertArrayNotHasKey( $deleted_id, $categories );
        $this->assertArrayNotHasKey( $tag_id, $categories );
        $this->assertContainsOnly( 'string', $categories, true, 'Options must hold term names only, never a WP_Error.' );
    }
}
