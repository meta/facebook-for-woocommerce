<?php
/**
 * Copyright (c) Meta, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Admin\Settings_Screens;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Admin\Abstract_Settings_Screen;
use WooCommerce\Facebook\Events\POS\POS_Integration_Registry;
use WooCommerce\Facebook\RolloutSwitches;

// Include the localization trait
require_once __DIR__ . '/Localization_Settings_Trait.php';

/**
 * Configuration settings screen object.
 *
 * Holds the manual sync controls and plugin-wide options that previously lived in
 * the collapsed Troubleshooting drawer on the Shops tab, where they were hard to
 * find. The Shops tab is now solely the Meta-hosted connection iframe.
 */
class Configuration extends Abstract_Settings_Screen {

	use Localization_Settings_Trait;

	/** @var string */
	const ID = 'configuration';

	/** @var string */
	const ACTION_SYNC_PRODUCTS = 'wc_facebook_sync_products';

	/** @var string */
	const ACTION_SYNC_COUPONS = 'wc_facebook_sync_coupons';

	/** @var string */
	const ACTION_SYNC_SHIPPING_PROFILES = 'wc_facebook_sync_shipping_profiles';

	/** @var string */
	const ACTION_SYNC_NAVIGATION_MENU = 'wc_facebook_sync_navigation_menu';

	/**
	 * Offline events documentation.
	 *
	 * Points at the plugin's own doc rather than Meta's, so the merchant first sees
	 * how this plugin detects in-store orders and what it sends. That page links on
	 * to Meta's Conversions API documentation.
	 *
	 * @var string
	 */
	const OFFLINE_EVENTS_DOC_URL = 'https://github.com/facebook/facebook-for-woocommerce/blob/main/docs/offline-events.md';

	/**
	 * Configuration constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'initHook' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Only register this action once across all settings screens that use the trait
		if ( ! has_action( 'woocommerce_admin_field_localization_plugin_status' ) ) {
			add_action( 'woocommerce_admin_field_localization_plugin_status', array( $this, 'render_localization_plugin_status' ) );
		}
	}

	/**
	 * Initializes this settings page's properties.
	 */
	public function initHook(): void {
		$this->id    = self::ID;
		$this->label = __( 'Configuration', 'facebook-for-woocommerce' );
		$this->title = __( 'Configuration', 'facebook-for-woocommerce' );
	}

	/**
	 * Enqueues the assets.
	 *
	 * @internal
	 */
	public function enqueue_assets() {
		if ( ! $this->is_current_screen_page() ) {
			return;
		}

		wp_enqueue_style(
			'wc-facebook-admin-shops-settings',
			facebook_for_woocommerce()->get_plugin_url() . '/assets/css/admin/facebook-for-woocommerce-shops.css',
			array(),
			\WC_Facebookcommerce::VERSION
		);

		wp_enqueue_script(
			'wc-facebook-enhanced-settings-sync',
			facebook_for_woocommerce()->get_asset_build_dir_url() . '/admin/enhanced-settings-sync.js',
			array( 'jquery' ),
			\WC_Facebookcommerce::PLUGIN_VERSION,
			true
		);

		wp_localize_script(
			'wc-facebook-enhanced-settings-sync',
			'wc_facebook_enhanced_settings_sync',
			array(
				'ajax_url'                     => admin_url( 'admin-ajax.php' ),
				'sync_products_nonce'          => wp_create_nonce( self::ACTION_SYNC_PRODUCTS ),
				'sync_coupons_nonce'           => wp_create_nonce( self::ACTION_SYNC_COUPONS ),
				'sync_shipping_profiles_nonce' => wp_create_nonce( self::ACTION_SYNC_SHIPPING_PROFILES ),
				'sync_navigation_menu_nonce'   => wp_create_nonce( self::ACTION_SYNC_NAVIGATION_MENU ),
			)
		);
	}

	/**
	 * Renders the screen.
	 */
	public function render() {
		$this->render_manual_sync_controls();

		parent::render();
	}

	/**
	 * Renders the manual sync controls.
	 *
	 * These sit outside the settings form: each is an AJAX action rather than a
	 * stored option, so they must not be submitted with the form.
	 */
	private function render_manual_sync_controls() {
		$controls = array(
			array(
				'title'       => __( 'Product data sync', 'facebook-for-woocommerce' ),
				'button_id'   => 'wc-facebook-enhanced-settings-sync-products',
				'description' => __( 'Manually sync your products from WooCommerce to your shop. It may take a couple of minutes for the changes to populate.', 'facebook-for-woocommerce' ),
			),
			array(
				'title'       => __( 'Coupon codes sync', 'facebook-for-woocommerce' ),
				'button_id'   => 'wc-facebook-enhanced-settings-sync-coupons',
				'description' => __( 'Manually sync your coupons from WooCommerce to your shop. It may take a couple of minutes for the changes to populate.', 'facebook-for-woocommerce' ),
			),
			array(
				'title'       => __( 'Shipping profiles sync', 'facebook-for-woocommerce' ),
				'button_id'   => 'wc-facebook-enhanced-settings-sync-shipping-profiles',
				'description' => __( 'Manually sync your shipping profiles from WooCommerce to your shop. It may take a couple of minutes for the changes to populate.', 'facebook-for-woocommerce' ),
			),
			array(
				'title'       => __( 'Navigation menu sync', 'facebook-for-woocommerce' ),
				'button_id'   => 'wc-facebook-enhanced-settings-sync-navigation-menu',
				'description' => __( 'Manually sync your category navigation menu from WooCommerce to your shop. It may take a couple of minutes for the changes to populate.', 'facebook-for-woocommerce' ),
			),
		);
		?>
		<table class="form-table">
			<tbody>
			<?php foreach ( $controls as $control ) : ?>
				<tr valign="top" class="wc-facebook-shops-sample">
					<th scope="row" class="titledesc">
						<?php echo esc_html( $control['title'] ); ?>
					</th>
					<td class="forminp">
						<button
							id="<?php echo esc_attr( $control['button_id'] ); ?>"
							class="button"
							type="button">
							<?php esc_html_e( 'Sync now', 'facebook-for-woocommerce' ); ?>
						</button>
						<p class="sync-description">
							<?php echo esc_html( $control['description'] ); ?>
						</p>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Gets the screen settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return array_merge(
			self::get_plugin_settings(),
			$this->get_localization_settings()
		);
	}

	/**
	 * Saves the settings, leaving fields that could not be edited untouched.
	 *
	 * A disabled field is not submitted by the browser, and WooCommerce reads a
	 * missing checkbox as unchecked. Saving it would silently switch it off: with
	 * the POS plugin deactivated, Save changes turned offline events off and cleared
	 * the opt-in time, even though nothing was changed. Disabled fields keep their
	 * stored value instead.
	 *
	 * The settings are rebuilt here, so whether a field is disabled reflects the
	 * state at save time. That also means a forged request cannot switch offline
	 * events on while no POS plugin is active — the same rule the AJAX action applies.
	 */
	public function save() {
		$editable_settings = array_filter(
			$this->get_settings(),
			static function ( $field ) {
				return empty( $field['disabled'] );
			}
		);

		woocommerce_update_options( $editable_settings );
	}

	/**
	 * Returns the plugin-wide settings array.
	 *
	 * @return array
	 */
	public static function get_plugin_settings(): array {
		$offer_management_enabled_by_fb = facebook_for_woocommerce()->get_rollout_switches()->is_switch_enabled(
			RolloutSwitches::SWITCH_OFFER_MANAGEMENT_ENABLED
		);

		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'wc_facebook_configuration_settings',
			),
			array(
				'id'       => \WC_Facebookcommerce_Integration::SETTING_ENABLE_META_DIAGNOSIS,
				'title'    => __( 'Enable meta diagnosis', 'facebook-for-woocommerce' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Upload plugin events to Meta', 'facebook-for-woocommerce' ),
				'desc_tip' => __( 'Allow Meta to monitor event and error logs to help fix issues.', 'facebook-for-woocommerce' ),
				'default'  => 'yes',
			),
			array(
				'id'       => \WC_Facebookcommerce_Integration::SETTING_ENABLE_DEBUG_MODE,
				'title'    => __( 'Enable debug mode', 'facebook-for-woocommerce' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Log plugin events for debugging.', 'facebook-for-woocommerce' ),
				/* translators: %s URL to the documentation page. */
				'desc_tip' => sprintf( __( 'Only enable this if you are experiencing problems with the plugin. <a href="%s" target="_blank">Learn more</a>.', 'facebook-for-woocommerce' ), 'https://woocommerce.com/document/facebook-for-woocommerce/#debug-tools' ),
				'default'  => 'no',
			),
		);

		if ( $offer_management_enabled_by_fb ) {
			$settings[] = array(
				'id'       => \WC_Facebookcommerce_Integration::SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS,
				'title'    => __( 'Enable Meta-managed coupons', 'facebook-for-woocommerce' ),
				'type'     => 'checkbox',
				'desc'     => __( 'Allow Meta to create and manage coupons based on your offer setup on Meta business tools', 'facebook-for-woocommerce' ),
				'desc_tip' => __( 'If this is disabled, some promotional features in Meta business tools may not be available.', 'facebook-for-woocommerce' ),
				'default'  => \WC_Facebookcommerce_Integration::SETTING_ENABLE_FACEBOOK_MANAGED_COUPONS_DEFAULT_VALUE,
			);
		}

		$settings[] = array(
			'id'      => \WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG,
			'title'   => __( 'Opt in to a Meta-enabled Conversions API integration', 'facebook-for-woocommerce' ),
			'type'    => 'checkbox',
			'desc'    => sprintf(
				/* translators: %1$s and %2$s open and close the Platform Terms link; %3$s and %4$s open and close the Business Tools Terms link. */
				__( 'You hereby authorize and instruct Meta to set up a Meta-enabled Conversions API integration on your behalf, and you agree that your use of the integration will be subject to Meta\'s %1$sPlatform Terms%2$s and %3$sBusiness Tools Terms%4$s.', 'facebook-for-woocommerce' ),
				'<a href="https://www.facebook.com/legal/terms" target="_blank" rel="noopener noreferrer">',
				'</a>',
				'<a href="https://www.facebook.com/legal/technology_terms" target="_blank" rel="noopener noreferrer">',
				'</a>'
			),
			'default' => \WC_Facebookcommerce_Integration::SETTING_ENABLE_CAPIG_DEFAULT,
		);

		// This setting, its option and filter, and how it behaves while no POS plugin is
		// active are documented in docs/offline-events.md ("Turning it on"). Keep that
		// section in step when changing them.
		$settings[] = array(
			'id'       => \WC_Facebookcommerce_Integration::SETTING_ENABLE_OFFLINE_PURCHASE_EVENTS,
			'title'    => __( 'Enable Offline Events', 'facebook-for-woocommerce' ),
			'type'     => 'checkbox',
			'desc'     => self::get_offline_events_description(),
			'default'  => 'no',
			// Without a point of sale plugin nothing can produce these events, so the
			// control is shown but not operable rather than hidden, which would leave
			// the feature undiscoverable. WooCommerce also adds a `disabled` class to
			// the row, so the whole setting reads as inactive.
			'disabled' => ! facebook_for_woocommerce()->get_integration()->can_enable_offline_purchase_events(),
		);

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'wc_facebook_configuration_settings',
		);

		return $settings;
	}

	/**
	 * Builds the description shown beneath the offline events checkbox.
	 *
	 * The detected-plugin line is part of this description rather than its own
	 * settings row so it reads as context for the checkbox: it explains why the
	 * control is or is not operable, right where the merchant is looking.
	 *
	 * @return string HTML, sanitized by WooCommerce with wp_kses_post() on output.
	 */
	private static function get_offline_events_description(): string {
		$registry  = new POS_Integration_Registry();
		$supported = $registry->get_supported_integrations();

		if ( ! empty( $supported ) ) {
			$status = sprintf(
				/* translators: %s comma-separated list of detected point of sale plugin names. */
				__( 'Detected supported POS plugins: %s', 'facebook-for-woocommerce' ),
				implode( ', ', self::get_integration_names( $supported ) )
			);
		} else {
			$status = sprintf(
				/* translators: %s comma-separated list of the point of sale plugins this feature supports. */
				__( 'No supported POS plugin detected. Works with: %s', 'facebook-for-woocommerce' ),
				implode( ', ', self::get_integration_names( $registry->get_integrations() ) )
			);
		}

		// The second sentence is what makes the POS status line below make sense:
		// without it, the merchant has no idea why point of sale plugins are relevant
		// to a setting whose title and first sentence never mention them.
		$description = sprintf(
			/* translators: %s URL to the plugin's offline events documentation. */
			__( 'Send offline and physical store events to Meta for use in Omni-channel Ads. Orders taken through a supported point of sale (POS) plugin are detected and reported automatically. <a href="%s" target="_blank">Learn more</a>.', 'facebook-for-woocommerce' ),
			self::OFFLINE_EVENTS_DOC_URL
		);

		return $description . '<span class="wc-facebook-pos-status">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Gets the display names of the given point-of-sale integrations.
	 *
	 * @param \WooCommerce\Facebook\Events\POS\POS_Integration_Interface[] $integrations the integrations.
	 * @return string[]
	 */
	private static function get_integration_names( array $integrations ): array {
		return array_map(
			static function ( $integration ) {
				return $integration->get_name();
			},
			$integrations
		);
	}
}
