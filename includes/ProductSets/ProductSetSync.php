<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license fo§und in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\ProductSets;

defined( 'ABSPATH' ) || exit;

use WooCommerce\Facebook\Integrations\WPML;
use WooCommerce\Facebook\RolloutSwitches;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WC_Facebookcommerce_Utils;

/**
 * The product set sync handler.
 *
 * @since 3.4.9
 */
class ProductSetSync {

	// Product category taxonomy used by WooCommerce
	const WC_PRODUCT_CATEGORY_TAXONOMY = 'product_cat';

	/** @var string Action Scheduler hook a queued full sync runs under */
	const SYNC_ALL_ACTION = 'facebook_for_woocommerce_sync_all_product_sets';

	/** @var string Action Scheduler group the queued full sync belongs to */
	const SYNC_ALL_ACTION_GROUP = 'facebook-for-woocommerce';

	/** @var string transient that rations the full sync to one run a day */
	const SYNC_ALL_FLAG = '_wc_facebook_for_woocommerce_product_sets_sync_flag';

	/**
	 * ProductSetSync constructor.
	 */
	public function __construct() {
		$this->add_hooks();
	}


	/**
	 * Adds needed hooks to support product set sync.
	 */
	private function add_hooks() {
		/**
		 * Sets up hooks to synchronize WooCommerce category mutations (create, update, delete) with Meta catalog's product sets in real-time.
		 */
		add_action( 'create_' . self::WC_PRODUCT_CATEGORY_TAXONOMY, array( $this, 'on_create_or_update_product_wc_category_callback' ), 99, 3 );
		add_action( 'edited_' . self::WC_PRODUCT_CATEGORY_TAXONOMY, array( $this, 'on_create_or_update_product_wc_category_callback' ), 99, 3 );
		add_action( 'delete_' . self::WC_PRODUCT_CATEGORY_TAXONOMY, array( $this, 'on_delete_wc_product_category_callback' ), 99, 4 );

		/**
		 * Schedules a daily sync of all WooCommerce categories to ensure any missed real-time updates are captured.
		 */
		add_action( Heartbeat::DAILY, array( $this, 'sync_all_product_sets' ), 10, 0 );

		/**
		 * Runs a full sync that an earlier request queued, such as the one a newly connected
		 * catalog asks for.
		 */
		add_action( self::SYNC_ALL_ACTION, array( $this, 'run_queued_sync_all_product_sets' ), 10, 0 );
	}

	/**
	 * Queues a full product set sync to run outside the current request.
	 *
	 * The sync makes up to two Graph calls per product category, so a store with a few hundred
	 * categories would hold the caller open for minutes. The caller onboarding uses has already
	 * committed the install on Meta's side by the time it gets here: a host or proxy cutting it
	 * short would leave the store connected with the browser on an error path.
	 *
	 * @since 3.7.7
	 *
	 * @return void
	 */
	public function schedule_sync_all_product_sets() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			// No Action Scheduler, so the daily heartbeat stays the only path to a full sync.
			return;
		}

		as_enqueue_async_action( self::SYNC_ALL_ACTION, array(), self::SYNC_ALL_ACTION_GROUP, true );
	}

	/**
	 * Runs a full product set sync that an earlier request queued.
	 *
	 * Bound to the Action Scheduler hook. A queued sync exists because something asked for it,
	 * a catalog connecting most often, so it does not compete with the daily heartbeat for the
	 * one routine run a day: it goes ahead even if the heartbeat has already had its pass.
	 *
	 * @since 3.7.7
	 *
	 * @return void
	 */
	public function run_queued_sync_all_product_sets() {
		$this->sync_all_product_sets( true );
	}

	/**
	 * Whether the store is in a state where product sets can be synced at all.
	 *
	 * Every sync path needs an access token to build the API client and a catalog to address.
	 * A store that was never connected, or has been disconnected, has neither; a store whose
	 * install failed closed keeps a stale catalog with no token. Calling Graph in any of those
	 * states either throws before the request is made or comes back 400, and in both cases the
	 * only result is a misleading error in the log.
	 *
	 * @since 3.7.7
	 *
	 * @return bool
	 */
	private function can_sync_product_sets() {
		return facebook_for_woocommerce()->get_connection_handler()->is_connected()
			&& ! empty( facebook_for_woocommerce()->get_integration()->get_product_catalog_id() );
	}

	/**
	 * Gets the WooCommerce product categories the sync mirrors.
	 *
	 * Polylang leaves term queries alone outside a language-aware request, but the queued sync
	 * runs in an Action Scheduler async request, an admin-ajax loopback, where it narrows them to
	 * the request's language; on a store with categories in several languages, or in none, that
	 * dropped most of them. An empty 'lang' is Polylang's documented way to lift its filter, and
	 * core ignores the argument, so every category is mirrored as the daily sync always has.
	 *
	 * WPML narrows term queries to the current language in every context. The daily sync runs in
	 * WP-Cron, where that is the default language, so WPML stores have always mirrored the default
	 * language's categories. The async runner inherits the cookies of the admin request that
	 * dispatched it, so there the current language is whatever that admin had selected in WPML's
	 * language switcher. The query is pinned to the default language so every runner mirrors the
	 * same categories the daily sync does. Mirroring every language on WPML is a separate change.
	 *
	 * @since 3.7.7
	 *
	 * @return \WP_Term[]
	 */
	private function get_all_wc_product_categories() {
		$wc_product_categories = WPML::run_in_language(
			apply_filters( 'wpml_default_language', null ),
			static function () {
				return get_terms(
					array(
						'taxonomy'   => self::WC_PRODUCT_CATEGORY_TAXONOMY,
						'hide_empty' => false,
						'orderby'    => 'ID',
						'order'      => 'ASC',
						'lang'       => '',
					)
				);
			}
		);

		return is_array( $wc_product_categories ) ? $wc_product_categories : array();
	}

	/**
	 * @since 3.4.9
	 *
	 * @param int   $term_id Term ID.
	 * @param int   $tt_id Term taxonomy ID.
	 * @param array $args Arguments.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	public function on_create_or_update_product_wc_category_callback( $term_id, $tt_id, $args ) {
		if ( ! $this->can_sync_product_sets() ) {
			return;
		}

		try {
			$wc_category       = get_term( $term_id, self::WC_PRODUCT_CATEGORY_TAXONOMY );
			$fb_product_set_id = $this->get_fb_product_set_id( $wc_category );
			if ( ! empty( $fb_product_set_id ) ) {
				$this->update_fb_product_set( $wc_category, $fb_product_set_id );
			} else {
				$this->create_fb_product_set( $wc_category );
			}
		} catch ( \Exception $exception ) {
			$this->log_exception( $exception );
		}
	}

	/**
	 * @since 3.4.9
	 *
	 * @param int     $term_id Term ID.
	 * @param int     $tt_id Term taxonomy ID.
	 * @param WP_Term $deleted_term Copy of the already-deleted term.
	 * @param array   $object_ids List of term object IDs.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	public function on_delete_wc_product_category_callback( $term_id, $tt_id, $deleted_term, $object_ids ) {
		if ( ! $this->can_sync_product_sets() ) {
			return;
		}

		try {
			$fb_product_set_id = $this->get_fb_product_set_id( $deleted_term );
			if ( ! empty( $fb_product_set_id ) ) {
				$this->delete_fb_product_set( $fb_product_set_id );
			}
		} catch ( \Exception $exception ) {
			$this->log_exception( $exception );
		}
	}

	/**
	 * @since 3.4.9
	 *
	 * @param bool $ignore_daily_limit Whether to run even if the day's sync has already happened.
	 *                                 Set for syncs something explicitly asked for, as opposed to
	 *                                 the heartbeat's routine pass.
	 */
	public function sync_all_product_sets( $ignore_daily_limit = false ) {
		try {
			// Without a connection and a catalog every category below would fail, see
			// can_sync_product_sets(). Returning before the flag is set leaves the day's run
			// available, so the next heartbeat retries once the store is ready rather than
			// waiting out the window.
			if ( ! $this->can_sync_product_sets() ) {
				return;
			}

			if ( ! $ignore_daily_limit && 'yes' === get_transient( self::SYNC_ALL_FLAG ) ) {
				return;
			}
			set_transient( self::SYNC_ALL_FLAG, 'yes', DAY_IN_SECONDS - 1 );

			$this->sync_all_wc_product_categories();
		} catch ( \Exception $exception ) {
			$this->log_exception( $exception );
		}
	}

	private function log_exception( \Exception $exception ) {
		facebook_for_woocommerce()->log(
			'ProductSetSync exception' .
				': exception_code : ' . $exception->getCode() .
				'; exception_class : ' . get_class( $exception ) .
				': exception_message : ' . $exception->getMessage() .
				'; exception_trace : ' . $exception->getTraceAsString(),
			null,
			\WC_Log_Levels::ERROR
		);
	}

	/**
	 * Important. This is ID from the WC category to be used as a retailer ID for the FB product set
	 *
	 * @param WP_Term $wc_category The WooCommerce category object.
	 */
	private function get_retailer_id( $wc_category ) {
		return $wc_category->term_taxonomy_id;
	}

	protected function get_fb_product_set_id( $wc_category ) {
		$retailer_id   = $this->get_retailer_id( $wc_category );
		$fb_catalog_id = facebook_for_woocommerce()->get_integration()->get_product_catalog_id();

		try {
			$response = facebook_for_woocommerce()->get_api()->read_product_set_item( $fb_catalog_id, $retailer_id );
		} catch ( \Exception $e ) {
			$message = sprintf( 'There was an error trying to get product set data in a catalog: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );

			/**
			 * Re-throw the exception to prevent potential issues, such as creating duplicate sets.
			 */
			throw $e;
		}

		return $response->get_product_set_id();
	}

	/**
	 * Reads a category field the way the product feed reads it.
	 *
	 * Term fields read with get_term_field() default to the 'display' context, which runs through
	 * wptexturize() and esc_html(). Those turn a straight apostrophe into &#8217; and a pair of
	 * double quotes into &#8220;/&#8221;, and the entities travel to Meta verbatim, so the set for
	 * "Men's Clothing" shows up in Commerce Manager as "Men&#8217;s Clothing". Decoding the
	 * display value is not enough either — it would leave the curly ’ that wptexturize chose,
	 * which is still not the name the merchant typed.
	 *
	 * Reading the stored value and decoding entities is exactly what the feed does with the same
	 * category name, in WC_Facebookcommerce_Utils::get_product_categories(). The transform has to
	 * stay identical, not merely similar: the set's product_type filter is matched against the
	 * product_type the feed sends, so any divergence leaves the set collecting nothing.
	 *
	 * @since 3.7.7
	 *
	 * @param WP_Term|int $wc_category The WooCommerce category.
	 * @param string      $field       Term field to read.
	 * @return string
	 */
	private function get_decoded_term_field( $wc_category, $field ) {
		$value = get_term_field( $field, $wc_category, self::WC_PRODUCT_CATEGORY_TAXONOMY, 'raw' );

		if ( is_wp_error( $value ) || ! is_string( $value ) ) {
			return '';
		}

		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML401, 'UTF-8' );
	}

	protected function build_fb_product_set_data( $wc_category ) {
		// The name has to match the feed's product_type byte for byte, so it gets the decode and
		// nothing else. The description is only shown as set metadata and never matched against
		// anything, so it can still go through clean_string() to lose tags and shortcodes.
		$wc_category_name          = $this->get_decoded_term_field( $wc_category, 'name' );
		$wc_category_description   = WC_Facebookcommerce_Utils::clean_string( $this->get_decoded_term_field( $wc_category, 'description' ) );
		$wc_category_url           = get_term_link( $wc_category, self::WC_PRODUCT_CATEGORY_TAXONOMY );
		$wc_category_thumbnail_id  = get_term_meta( $wc_category, 'thumbnail_id', true );
		$wc_category_thumbnail_url = wp_get_attachment_image_src( $wc_category_thumbnail_id );

		$fb_product_set_metadata = array();
		if ( ! empty( $wc_category_thumbnail_url ) ) {
			$fb_product_set_metadata['cover_image_url'] = $wc_category_thumbnail_url;
		}
		if ( ! empty( $wc_category_description ) ) {
			$fb_product_set_metadata['description'] = $wc_category_description;
		}
		if ( ! empty( $wc_category_url ) ) {
			$fb_product_set_metadata['external_url'] = $wc_category_url;
		}

		$fb_product_set_data = array(
			'name'        => $wc_category_name,
			'filter'      => wp_json_encode( array( 'and' => array( array( 'product_type' => array( 'i_contains' => $wc_category_name ) ) ) ) ),
			'retailer_id' => $this->get_retailer_id( $wc_category ),
			'metadata'    => wp_json_encode( $fb_product_set_metadata ),
		);

		return $fb_product_set_data;
	}

	protected function create_fb_product_set( $wc_category ) {
		$fb_product_set_data = $this->build_fb_product_set_data( $wc_category );
		$fb_catalog_id       = facebook_for_woocommerce()->get_integration()->get_product_catalog_id();

		try {
			facebook_for_woocommerce()->get_api()->create_product_set_item( $fb_catalog_id, $fb_product_set_data );
		} catch ( \Exception $e ) {
			$message = sprintf( 'There was an error trying to create product set: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );
		}
	}

	protected function update_fb_product_set( $wc_category, $fb_product_set_id ) {
		$fb_product_set_data = $this->build_fb_product_set_data( $wc_category );

		try {
			facebook_for_woocommerce()->get_api()->update_product_set_item( $fb_product_set_id, $fb_product_set_data );
		} catch ( \Exception $e ) {
			$message = sprintf( 'There was an error trying to update product set: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );
		}
	}

	protected function delete_fb_product_set( $fb_product_set_id ) {
		try {
			$allow_live_deletion = true;
			facebook_for_woocommerce()->get_api()->delete_product_set_item( $fb_product_set_id, $allow_live_deletion );
		} catch ( \Exception $e ) {
			$message = sprintf( 'There was an error trying to delete product set in a catalog: %s', $e->getMessage() );
			facebook_for_woocommerce()->log( $message );
		}
	}

	private function sync_all_wc_product_categories() {
		$wc_product_categories = $this->get_all_wc_product_categories();

		foreach ( $wc_product_categories as $wc_category ) {
			try {
				$fb_product_set_id = $this->get_fb_product_set_id( $wc_category );
				if ( ! empty( $fb_product_set_id ) ) {
					$this->update_fb_product_set( $wc_category, $fb_product_set_id );
				} else {
					$this->create_fb_product_set( $wc_category );
				}
			} catch ( \Exception $exception ) {
				$this->log_exception( $exception );
			}
		}
	}
}
