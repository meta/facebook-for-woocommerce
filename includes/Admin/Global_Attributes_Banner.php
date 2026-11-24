<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Admin;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\ProductAttributeMapper;

/**
 * Global Attributes Banner handler.
 *
 * Shows informational banners when users create global attributes
 * that don't have direct mappings to Meta catalog fields.
 *
 * @since 3.5.4
 */
class Global_Attributes_Banner {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Hook into WooCommerce attribute creation (better than generic term creation)
		add_action( 'woocommerce_attribute_added', array( $this, 'check_new_woocommerce_attribute' ), 10, 2 );

		// Also hook into generic term creation as fallback
		add_action( 'created_term', array( $this, 'check_new_attribute_mapping' ), 20, 3 );

		// Hook into attribute page display
		add_action( 'admin_notices', array( $this, 'display_unmapped_attribute_banner' ) );

		// AJAX handler for dismissing banner
		add_action( 'wp_ajax_dismiss_fb_unmapped_attribute_banner', array( $this, 'dismiss_banner' ) );

		// Add test action for debugging (remove in production)
		add_action( 'wp_ajax_test_fb_banner', array( $this, 'ajax_test_banner' ) );

		// Add URL parameter trigger for testing
		add_action( 'admin_init', array( $this, 'maybe_trigger_test_banner' ) );
	}

	/**
	 * Check for URL parameter to trigger test banner.
	 */
	public function maybe_trigger_test_banner() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['test_fb_banner'] ) && current_user_can( 'manage_woocommerce' ) ) {
			$attribute_name = sanitize_text_field( wp_unslash( $_GET['test_fb_banner'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( empty( $attribute_name ) ) {
				$attribute_name = 'escobar';
			}
			$this->test_banner( $attribute_name );

			// Add an admin notice to confirm
			add_action(
				'admin_notices',
				function () use ( $attribute_name ) {
					echo '<div class="notice notice-success"><p>Test banner triggered for: ' . esc_html( $attribute_name ) . '</p></div>';
				}
			);
		}
	}

	/**
	 * Check if a newly created WooCommerce attribute has a direct mapping to Meta.
	 *
	 * @param int   $id   Attribute ID.
	 * @param array $data Attribute data.
	 */
	public function check_new_woocommerce_attribute( $id, $data ) {
		// Only show to users who can manage WooCommerce
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Get the attribute name from the data
		$attribute_name = isset( $data['attribute_name'] ) ? $data['attribute_name'] : '';

		if ( empty( $attribute_name ) ) {
			return;
		}

		// Check if this attribute maps to any Meta field
		$maps_to_meta = $this->attribute_maps_to_meta( $attribute_name );

		if ( ! $maps_to_meta ) {
			$this->queue_unmapped_attribute_banner( $attribute_name );
		}
	}

	/**
	 * Check if a newly created attribute has a direct mapping to Meta.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function check_new_attribute_mapping( $term_id, $tt_id, $taxonomy ) {
		// Only check for attribute taxonomies
		if ( ! $this->is_attribute_taxonomy( $taxonomy ) ) {
			return;
		}

		// Only show to users who can manage WooCommerce
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Get the attribute name from taxonomy
		$attribute_name = str_replace( 'pa_', '', $taxonomy );

		// Check if this attribute maps to any Meta field
		$maps_to_meta = $this->attribute_maps_to_meta( $attribute_name );

		if ( ! $maps_to_meta ) {
			$this->queue_unmapped_attribute_banner( $attribute_name );
		}
	}

	/**
	 * Check if a taxonomy is an attribute taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private function is_attribute_taxonomy( $taxonomy ) {
		return strpos( $taxonomy, 'pa_' ) === 0;
	}

	/**
	 * Check if a WooCommerce taxonomy attribute still exists.
	 *
	 * @param string $attribute_name Attribute name (without pa_ prefix).
	 * @return bool True if the taxonomy attribute exists, false otherwise.
	 */
	private function taxonomy_attribute_exists( $attribute_name ) {
		// Check if the taxonomy exists (attributes use pa_{name} taxonomy)
		$taxonomy_name = 'pa_' . $attribute_name;

		if ( ! taxonomy_exists( $taxonomy_name ) ) {
			return false;
		}

		// Also verify it's a registered WooCommerce attribute
		global $wpdb;
		$attribute_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s LIMIT 1",
				$attribute_name
			)
		);

		return ! empty( $attribute_exists );
	}

	/**
	 * Check if an attribute name maps to a Meta field.
	 *
	 * @param string $attribute_name Attribute name (without pa_ prefix).
	 * @return bool
	 */
	private function attribute_maps_to_meta( $attribute_name ) {
		if ( ! class_exists( 'WooCommerce\Facebook\ProductAttributeMapper' ) ) {
			return false;
		}

		// Use the same comprehensive logic as the ProductAttributeMapper
		$mapped_field = ProductAttributeMapper::check_attribute_mapping( 'pa_' . $attribute_name );

		// If we get a mapping result, the attribute is mapped
		return false !== $mapped_field;
	}

	/**
	 * Queue a banner for an unmapped attribute.
	 *
	 * @param string $attribute_name The attribute name.
	 */
	private function queue_unmapped_attribute_banner( $attribute_name ) {
		$banner_data = array(
			'attribute_name' => $attribute_name,
			'timestamp'      => time(),
		);

		// Increase duration to 30 minutes to account for page redirects
		set_transient( 'fb_new_unmapped_attribute_banner', $banner_data, 1800 );

		// Also store a flag to show the banner immediately on the current page
		set_transient( 'fb_show_banner_now', true, 300 );
	}

	/**
	 * Display the unmapped attribute banner.
	 */
	public function display_unmapped_attribute_banner() {
		// Check if we should force show the banner now (for immediate display)
		$show_now = get_transient( 'fb_show_banner_now' );

		$should_show = $this->should_show_banner();

		if ( ! $should_show && ! $show_now ) {
			return;
		}

		$banner_data = get_transient( 'fb_new_unmapped_attribute_banner' );

		if ( ! $banner_data || ! isset( $banner_data['attribute_name'] ) ) {
			return;
		}

		// Clear the immediate show flag if it was set
		if ( $show_now ) {
			delete_transient( 'fb_show_banner_now' );
		}

		$attribute_name = $banner_data['attribute_name'];

		// Check if the attribute still exists - if deleted, clear transient and return early
		if ( ! $this->taxonomy_attribute_exists( $attribute_name ) ) {
			delete_transient( 'fb_new_unmapped_attribute_banner' );
			delete_transient( 'fb_show_banner_now' );
			return;
		}

		$display_name = ucfirst( str_replace( array( '_', '-' ), ' ', $attribute_name ) );

		// Build the mapper URL
		$mapper_url = add_query_arg(
			array(
				'page' => 'wc-facebook',
				'tab'  => 'product-attributes',
			),
			admin_url( 'admin.php' )
		);

		?>
		<div class="notice notice-info is-dismissible fb-unmapped-attribute-banner" style="position: relative;">
			<p>
				<strong><?php esc_html_e( 'Meta for WooCommerce', 'facebook-for-woocommerce' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %1$s - attribute name, %2$s - link start, %3$s - link end */
					esc_html__( 'Your new "%1$s" attribute doesn\'t directly map to a Meta catalog field. %2$sMap it to Facebook%3$s to improve product visibility in Meta ads and help customers find your products more easily.', 'facebook-for-woocommerce' ),
					esc_html( $display_name ),
					'<a href="' . esc_url( $mapper_url ) . '">',
					'</a>'
				);
				?>
			</p>
			<button type="button" class="notice-dismiss" data-attribute="<?php echo esc_attr( $attribute_name ); ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice', 'facebook-for-woocommerce' ); ?></span>
			</button>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.fb-unmapped-attribute-banner .notice-dismiss').on('click', function() {
				var attributeName = $(this).data('attribute');
				$.post(ajaxurl, {
					action: 'dismiss_fb_unmapped_attribute_banner',
					attribute: attributeName,
					nonce: '<?php echo esc_attr( wp_create_nonce( 'dismiss_fb_banner' ) ); ?>'
				});
				$(this).closest('.notice').fadeOut();
			});
		});
		</script>
		<?php
	}

	/**
	 * Check if we should show the banner.
	 *
	 * @return bool
	 */
	private function should_show_banner() {
		// Only show to users who can manage WooCommerce
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		// Show only on the attributes page & Facebook settings page to reduce noise
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		// Show only on: attributes page & Facebook settings page
		$allowed_screens = array(
			'product_page_product_attributes',  // Global attributes page
			'woocommerce_page_wc-facebook',     // Facebook settings page
		);

		return in_array( $screen->id, $allowed_screens, true );
	}

	/**
	 * Dismiss the banner via AJAX.
	 */
	public function dismiss_banner() {
		check_ajax_referer( 'dismiss_fb_banner', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		delete_transient( 'fb_new_unmapped_attribute_banner' );
		wp_die();
	}

	/**
	 * Manual method to test the banner (for debugging).
	 *
	 * @param string $attribute_name The attribute name to test.
	 */
	public function test_banner( $attribute_name = 'escobar' ) {
		$this->queue_unmapped_attribute_banner( $attribute_name );
	}

	/**
	 * AJAX handler to test the banner.
	 */
	public function ajax_test_banner() {
		check_ajax_referer( 'test_fb_banner', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( -1 );
		}

		$attribute_name = isset( $_POST['attribute'] ) ? sanitize_text_field( wp_unslash( $_POST['attribute'] ) ) : 'escobar';
		$this->test_banner( $attribute_name );

		wp_send_json_success( 'Banner queued for: ' . $attribute_name );
	}
}
