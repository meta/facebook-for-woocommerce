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

use WooCommerce\Facebook\Integrations\IntegrationRegistry;
use WooCommerce\Facebook\Feed\Localization\LanguageFeedData;

defined( 'ABSPATH' ) || exit;

/**
 * Trait for rendering localization settings across different settings screens.
 *
 * @since 3.6.0
 */
trait Localization_Settings_Trait {

	/**
	 * Gets the localization settings array for WooCommerce settings API.
	 *
	 * @since 3.6.0
	 *
	 * @return array
	 */
	protected function get_localization_settings(): array {
		// Hide settings if rollout switch is disabled
		if ( ! facebook_for_woocommerce()->get_rollout_switches()->is_switch_enabled( \WooCommerce\Facebook\RolloutSwitches::SWITCH_LANGUAGE_OVERRIDE_FEED_ENABLED ) ) {
			return array();
		}

		// Get active localization integration to determine setting state
		$integration  = IntegrationRegistry::get_active_localization_integration();
		$is_available = $integration && $integration->is_available();
		$is_eligible  = $is_available && method_exists( $integration, 'is_eligible_for_language_override_feeds' ) && $integration->is_eligible_for_language_override_feeds();

		// Hide the entire section for ineligible sites (legacy multi-language setups)
		if ( ! $is_eligible ) {
			return array();
		}

		// Get current setting value or set default intelligently
		$current_value = get_option( \WC_Facebookcommerce_Integration::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED );

		// Initialize the setting on first load based on availability
		if ( false === $current_value ) {
			// First time - set default based on whether we have an available AND eligible integration
			$default_value = $is_eligible ? 'yes' : 'no';
			update_option( \WC_Facebookcommerce_Integration::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED, $default_value );
			$current_value = $default_value;
		}

		// Build description based on integration status
		$description = __( 'Generate and sync language override feeds to Facebook for multilingual product catalogs.', 'facebook-for-woocommerce' );

		if ( ! $is_available ) {
			$description .= '<br><strong style="color: #dc3232;">' . __( 'No localization plugin is active and properly configured. Install and activate WPML or Polylang with a default language set.', 'facebook-for-woocommerce' ) . '</strong>';
		}

		$settings = array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'wc_facebook_language_override_feed_settings',
			),
			array(
				'title'   => __( 'Enable language override feeds', 'facebook-for-woocommerce' ),
				'desc'    => $description,
				'id'      => \WC_Facebookcommerce_Integration::OPTION_LANGUAGE_OVERRIDE_FEED_GENERATION_ENABLED,
				'default' => 'yes',
				'type'    => 'checkbox',
			),
			array(
				'type'  => 'localization_plugin_status',
				'title' => __( 'Detected Localization Plugin', 'facebook-for-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wc_facebook_language_override_feed_settings',
			),
		);

		return $settings;
	}

	/**
	 * Renders the localization plugin status table.
	 *
	 * @since 3.6.0
	 *
	 * @param array $field field data
	 */
	public function render_localization_plugin_status( $field ) {
		try {
			// Get the active localization integration (only one detected plugin)
			$active_integration = IntegrationRegistry::get_active_localization_integration();
			$feed_data          = new LanguageFeedData();
		} catch ( \Exception $e ) {
			?>
			<tr valign="top">
				<th scope="row" class="titledesc">
					<?php echo esc_html( $field['title'] ); ?>
				</th>
				<td class="forminp">
					<div class="notice notice-error inline">
						<p><?php echo esc_html__( 'Error loading localization integrations: ', 'facebook-for-woocommerce' ) . esc_html( $e->getMessage() ); ?></p>
					</div>
				</td>
			</tr>
			<?php
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Meta for WooCommerce - Localization Integrations Error: ' . $e->getMessage() );
			return;
		}

		// Get languages if integration is active
		$languages = [];
		if ( $active_integration && $feed_data ) {
			try {
				$languages = $feed_data->get_available_languages();
			} catch ( \Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Meta for WooCommerce - Error getting available languages: ' . $e->getMessage() );
			}
		}
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php echo esc_html( $field['title'] ); ?>
			</th>
			<td class="forminp">
				<table class="wc-facebook-localization-status-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plugin', 'facebook-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Status', 'facebook-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Version', 'facebook-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Default Language', 'facebook-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Language Override Feeds', 'facebook-for-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $active_integration ) : ?>
							<tr>
								<td colspan="5" style="text-align: center; padding: 20px; color: #666; font-style: italic;">
									<?php esc_html_e( 'No active localization plugin detected. Install and activate WPML or Polylang to enable multilingual product catalogs.', 'facebook-for-woocommerce' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php
							try {
								$status           = $this->get_integration_status( $active_integration );
								$version          = $active_integration->get_plugin_version();
								$default_language = $active_integration->get_default_language();
							} catch ( \Exception $e ) {
								$status           = 'error';
								$version          = '';
								$default_language = '';
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
								error_log( 'Meta for WooCommerce - Error getting integration data: ' . $e->getMessage() );
							}
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $active_integration->get_plugin_name() ); ?></strong>
									<div style="font-size: 12px; color: #999; margin-top: 2px;">
										<?php echo esc_html( $active_integration->get_plugin_file_name() ); ?>
									</div>
								</td>
								<td><?php echo wp_kses_post( $this->render_status_badge( $status ) ); ?></td>
								<td><?php echo $version ? esc_html( $version ) : '<span style="color: #999; font-style: italic;">—</span>'; ?></td>
								<td><?php echo $default_language ? esc_html( $default_language ) : '<span style="color: #999; font-style: italic;">—</span>'; ?></td>
								<td>
									<?php if ( ! empty( $languages ) ) : ?>
										<?php echo esc_html( implode( ', ', $languages ) ); ?>
									<?php else : ?>
										<span style="color: #999; font-style: italic;"><?php esc_html_e( 'No additional languages configured', 'facebook-for-woocommerce' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<style>
					.wc-facebook-localization-status-table {
						margin-top: 10px;
						border: 1px solid #ddd;
					}
					.wc-facebook-localization-status-table thead th {
						background-color: #f9f9f9;
						padding: 10px;
						font-weight: 600;
						border-bottom: 1px solid #ddd;
					}
					.wc-facebook-localization-status-table tbody td {
						padding: 12px 10px;
						border-bottom: 1px solid #f0f0f0;
					}
					.wc-facebook-localization-status-table .status-badge {
						display: inline-block;
						padding: 3px 8px;
						border-radius: 3px;
						font-size: 11px;
						font-weight: 600;
						text-transform: uppercase;
					}
					.wc-facebook-localization-status-table .status-active {
						background-color: #d4edda;
						color: #155724;
						border: 1px solid #c3e6cb;
					}
					.wc-facebook-localization-status-table .status-active-ineligible {
						background-color: #fff3cd;
						color: #856404;
						border: 1px solid #ffc107;
					}
					.wc-facebook-localization-status-table .status-misconfigured {
						background-color: #ffeaa7;
						color: #856404;
						border: 1px solid #ffd32a;
					}
					.wc-facebook-localization-status-table .status-installed {
						background-color: #fff3cd;
						color: #856404;
						border: 1px solid #ffeaa7;
					}
					.wc-facebook-localization-status-table .status-not-available {
						background-color: #f8d7da;
						color: #721c24;
						border: 1px solid #f5c6cb;
					}
				</style>
			</td>
		</tr>
		<?php
	}

	/**
	 * Gets the integration status.
	 *
	 * @since 3.6.0
	 *
	 * @param \WooCommerce\Facebook\Integrations\Abstract_Localization_Integration $integration
	 * @return string
	 */
	private function get_integration_status( $integration ) {
		// Use the integration's own status method if available (includes "Active - Ineligible" for legacy WPML)
		if ( method_exists( $integration, 'get_integration_status' ) ) {
			$status = $integration->get_integration_status();
			// Convert to lowercase with dashes for CSS class compatibility
			return strtolower( str_replace( ' ', '-', $status ) );
		}

		// Fallback to basic status detection
		if ( $integration->is_available() ) {
			return 'active';
		} elseif ( $integration->is_plugin_active() ) {
			// Plugin is active but not properly configured (no default language)
			return 'misconfigured';
		} elseif ( $integration->is_plugin_installed() ) {
			return 'installed';
		} else {
			return 'not-available';
		}
	}

	/**
	 * Renders a status badge.
	 *
	 * @since 3.6.0
	 *
	 * @param string $status
	 * @return string
	 */
	private function render_status_badge( $status ) {
		$labels = array(
			'active'            => __( 'Active', 'facebook-for-woocommerce' ),
			'active-ineligible' => __( 'Active - Ineligible', 'facebook-for-woocommerce' ),
			'misconfigured'     => __( 'Misconfigured', 'facebook-for-woocommerce' ),
			'installed'         => __( 'Installed', 'facebook-for-woocommerce' ),
			'not-available'     => __( 'Not Available', 'facebook-for-woocommerce' ),
		);

		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		$class = 'status-badge status-' . esc_attr( $status );

		return sprintf( '<span class="%s">%s</span>', $class, esc_html( $label ) );
	}
}
