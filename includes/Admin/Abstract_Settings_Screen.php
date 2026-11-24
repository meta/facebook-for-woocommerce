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

use WooCommerce\Facebook\Framework\Helper;
use WooCommerce\Facebook\Framework\Plugin\Exception as PluginException;

defined( 'ABSPATH' ) || exit;

/**
 * The base settings screen object.
 */
abstract class Abstract_Settings_Screen {


	/** @var string screen ID */
	protected $id;

	/** @var string screen label, for display */
	protected $label;

	/** @var string screen title, for display */
	protected $title;

	/** @var string screen description, for display */
	protected $description;

	/** @var string documentation URL for the more information link */
	protected $documentation_url;


	/**
	 * Renders the screen.
	 *
	 * @since 2.0.0
	 */
	public function render() {

		/**
		 * Filters the screen settings.
		 *
		 * @since 2.0.0
		 *
		 * @param array $settings settings
		 */
		$settings = (array) apply_filters( 'wc_facebook_admin_' . $this->get_id() . '_settings', $this->get_settings(), $this );

		if ( empty( $settings ) ) {
			return;
		}

		$connection_handler = facebook_for_woocommerce()->get_connection_handler();
		$is_connected       = $connection_handler->is_connected();

		?>

		<?php if ( ! $is_connected && ! $connection_handler->has_previously_connected_fbe_1() && $this->get_disconnected_message() ) : ?>
			<div class="notice notice-info"><p><?php echo wp_kses_post( $this->get_disconnected_message() ); ?></p></div>
		<?php endif; ?>

		<form class="wc-facebook-settings-BROKEN <?php echo $is_connected ? 'connected' : 'disconnected'; ?>" method="post" id="mainform" action="" enctype="multipart/form-data">

			<?php woocommerce_admin_fields( $settings ); ?>

			<?php if ( $is_connected ) : ?>
				<div class="actions">
					<input type="hidden" name="screen_id" value="<?php echo esc_attr( $this->get_id() ); ?>">
					<?php wp_nonce_field( 'wc_facebook_admin_save_' . $this->get_id() . '_settings' ); ?>
					<?php submit_button( __( 'Save changes', 'facebook-for-woocommerce' ), 'primary', 'save_' . $this->get_id() . '_settings' ); ?>
					<?php $this->maybe_render_learn_more_link( $this->get_label() ); ?>
				</div>
			<?php endif; ?>

		</form>

		<?php
	}

	/**
	 * Renders the learn more link if the documentation URL is set.
	 *
	 * @param string $screen_label The screen label/title, translated.
	 *
	 * @since 3.3.0
	 */
	protected function maybe_render_learn_more_link( $screen_label ) {
		if ( $this->documentation_url ) :
			?>
			<span class="learn-more-link"><a href="<?php echo esc_url( $this->documentation_url ); ?>" class="" target="_blank">
				<?php
				/*
				 * Translators: %s Settings screen label/title, in lowercase.
				 */
				echo esc_html( sprintf( __( 'Learn more about %s', 'facebook-for-woocommerce' ), strtolower( $screen_label ) ) );
				?>
				</a></span>
			<?php
		endif;
	}


	/**
	 * Saves the settings.
	 *
	 * @since 2.0.0
	 */
	public function save() {
		woocommerce_update_options( $this->get_settings() );
	}


	/**
	 * Determines whether the current screen is the same as identified by the current class.
	 *
	 * @since 2.2.0
	 *
	 * @return bool
	 */
	protected function is_current_screen_page() {
		if ( Enhanced_Settings::PAGE_ID !== Helper::get_requested_value( 'page' ) ) {
			return false;
		}
		$tab = Helper::get_requested_value( 'tab', Settings_Screens\Shops::ID );

		return ! empty( $tab ) && $tab === $this->get_id();
	}


	/** Getter methods ************************************************************************************************/


	/**
	 * Gets the settings.
	 *
	 * Should return a multi-dimensional array of settings in the format expected by \WC_Admin_Settings
	 *
	 * @return array
	 */
	abstract public function get_settings(): array;


	/**
	 * Gets the message to display when the plugin is disconnected.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_disconnected_message() {
		return '';
	}


	/**
	 * Gets the screen ID.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}


	/**
	 * Gets the screen label.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_label() {
		/**
		 * Filters the screen label.
		 *
		 * @since 2.0.0
		 *
		 * @param string $label screen label, for display
		 */
		return (string) apply_filters( 'wc_facebook_admin_settings_' . $this->get_id() . '_screen_label', $this->label, $this );
	}


	/**
	 * Gets the screen title.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_title() {
		/**
		 * Filters the screen title.
		 *
		 * @since 2.0.0
		 *
		 * @param string $title screen title, for display
		 */
		return (string) apply_filters( 'wc_facebook_admin_settings_' . $this->get_id() . '_screen_title', $this->title, $this );
	}


	/**
	 * Gets the screen description.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public function get_description() {
		/**
		 * Filters the screen description.
		 *
		 * @since 2.0.0
		 *
		 * @param string $description screen description, for display
		 */
		return (string) apply_filters( 'wc_facebook_admin_settings_' . $this->get_id() . '_screen_description', $this->description, $this );
	}
}
