<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Admin\Settings_Screens;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Admin\Abstract_Settings_Screen;

/**
 * Shops settings screen object.
 *
 * Renders the Meta-hosted connection iframe. The manual sync controls and
 * plugin-wide options this screen used to carry in a collapsed Troubleshooting
 * drawer now live on the Configuration tab.
 *
 * @since 3.5.0
 */
class Shops extends Abstract_Settings_Screen {

	/** @var string */
	const ID = 'shops';

	/**
	 * Shops constructor.
	 *
	 * @since 3.5.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'initHook' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_footer', array( $this, 'render_message_handler' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueues the wp-api script and the Facebook REST API JavaScript client.
	 *
	 * @since 3.5.0
	 *
	 * @internal
	 */
	public function enqueue_admin_scripts() {
		if ( $this->is_current_screen_page() ) {
			wp_enqueue_script( 'wp-api' );
		}
	}

	/**
	 * Initializes this settings page's properties.
	 *
	 * @since 3.5.0
	 */
	public function initHook(): void {
		$this->id    = self::ID;
		$this->label = __( 'Shops', 'facebook-for-woocommerce' );
		$this->title = __( 'Shops', 'facebook-for-woocommerce' );
	}

	/**
	 * Enqueues the assets.
	 *
	 * @since 3.5.0
	 *
	 * @internal
	 */
	public function enqueue_assets() {
		if ( ! $this->is_current_screen_page() ) {
			return;
		}

		wp_enqueue_style( 'wc-facebook-admin-shops-settings', facebook_for_woocommerce()->get_plugin_url() . '/assets/css/admin/facebook-for-woocommerce-shops.css', array(), \WC_Facebookcommerce::VERSION );

		wp_enqueue_style( 'wc-facebook-admin-whatsapp-banner', facebook_for_woocommerce()->get_plugin_url() . '/assets/css/admin/facebook-for-woocommerce-whatsapp-banner.css', array(), \WC_Facebookcommerce::VERSION );
	}

	/**
	 * Renders the screen.
	 *
	 * @since 3.5.0
	 */
	public function render() {
		$this->render_facebook_iframe();
	}

	/**
	 * Renders the appropriate Facebook iframe based on connection status.
	 *
	 * @since 3.5.0
	 */
	private function render_facebook_iframe() {
		$connection         = facebook_for_woocommerce()->get_connection_handler();
		$is_connected       = $connection->is_connected();
		$connection_invalid = (bool) get_transient( 'wc_facebook_connection_invalid' );

		if ( $is_connected && ! $connection_invalid ) {
			$iframe_url = \WooCommerce\Facebook\Handlers\MetaExtension::generate_iframe_management_url(
				$connection->get_external_business_id(),
				$connection->get_commerce_partner_integration_id()
			);
		}

		// Fall back to the splash/onboarding iframe if the management URL could not be loaded
		// (e.g. due to an invalid token) or if the store was never connected.
		if ( empty( $iframe_url ) ) {
			// When reconnecting after an invalid token, pass is_connected=true
			// so CPH sets repeat=true in the OAuth dialog and reconnects the
			// existing FBE installation rather than creating a new one.
			$iframe_url = \WooCommerce\Facebook\Handlers\MetaExtension::generate_iframe_splash_url(
				$is_connected,
				$connection->get_plugin(),
				$connection->get_external_business_id()
			);
		}

		if ( empty( $iframe_url ) ) {
			return;
		}
		?>
	<div style="display: flex; justify-content: center; max-width: 1200px; margin: 0 auto;">
		<iframe
			id="facebook-commerce-iframe-enhanced"
			src="<?php echo esc_url( $iframe_url ); ?>"
			></iframe>
	</div>
		<?php
	}

	/**
	 * Gets the screen settings.
	 *
	 * Empty by design: this screen renders only the Meta-hosted iframe and has no
	 * settings form. The options it used to carry are on the Configuration tab.
	 * Returning them here would let Abstract_Settings_Screen::save() write fields
	 * that were never rendered.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return array();
	}

	/**
	 * Renders the message handler script in the footer.
	 *
	 * @since 3.5.0
	 */
	public function render_message_handler() {
		if ( ! $this->is_current_screen_page() ) {
			return;
		}

		wp_add_inline_script( 'plugin-api-client', $this->generate_inline_enhanced_onboarding_script(), 'after' );
	}

	/**
	 * Generates the inline script for the enhanced onboarding flow.
	 *
	 * @since 3.5.0
	 *
	 * @return string
	 */
	public function generate_inline_enhanced_onboarding_script() {
		// Generate a fresh nonce for this request
		$nonce = wp_json_encode( wp_create_nonce( 'wp_rest' ) );

		return "
			const fbAPI = GeneratePluginAPIClient({$nonce});
			const ALLOWED_ORIGINS = [
				'https://www.commercepartnerhub.com',
				'https://www.facebook.com',
				'https://business.facebook.com'
			];
			window.addEventListener('message', function(event) {
				if (ALLOWED_ORIGINS.indexOf(event.origin) === -1) {
					return;
				}
				const message = event.data;
				if (!message || typeof message !== 'object') {
					return;
				}
				const messageEvent = message.event;

				if (messageEvent === 'CommerceExtension::INSTALL' && message.success) {
					const installed_features = Array.isArray(message.installed_features) ? message.installed_features : [];
					const cms_id = installed_features.find( ( f ) => 'fb_shop' === f.feature_type )?.connected_assets?.commerce_merchant_settings_id ||
						installed_features.find( ( f ) => 'ig_shopping' === f.feature_type )?.connected_assets?.commerce_merchant_settings_id || '';

					const requestBody = {
						access_token: message.access_token,
						product_catalog_id: message.catalog_id,
						pixel_id: message.pixel_id,
						commerce_merchant_settings_id: cms_id,
						commerce_partner_integration_id: message.commerce_partner_integration_id || '',
						installed_features: installed_features
					};
					Object.keys(requestBody).forEach(function(key) {
						if (requestBody[key] === undefined || requestBody[key] === null) {
							delete requestBody[key];
						}
					});

					fbAPI.finalizeInstall(requestBody)
						.then(function(response) {
							if (response.success) {
								window.location.reload();
							} else {
								console.error('Error finalizing Facebook installation:', response);
							}
						})
						.catch(function(error) {
							console.error('Error during install finalization:', error);
						});
				}

				if (messageEvent === 'CommerceExtension::RESIZE') {
					const iframe = document.getElementById('facebook-commerce-iframe-enhanced');
					if (iframe && message.height) {
						iframe.height = message.height;
					}
				}

				if (messageEvent === 'CommerceExtension::UNINSTALL') {
					fbAPI.uninstallSettings()
						.then(function(response) {
							if (response.success) {
								window.location.reload();
							}
						})
						.catch(function(error) {
							console.error('Error during uninstall:', error);
							window.location.reload();
						});
				}
			});
		";
	}
}
