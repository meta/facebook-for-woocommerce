<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

namespace WooCommerce\Facebook\Jobs;

use Automattic\WooCommerce\ActionSchedulerJobFramework\Utilities\BatchQueryOffset;
use Exception;
use WC_Facebookcommerce;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Class ResetAllProductsFBSettings
 *
 * @since 3.0.5
 */
class ResetAllProductsFBSettings extends AbstractChainedJob {

	use BatchQueryOffset;
	use LoggingTrait;

	/**
	 * Called before starting the job.
	 */
	protected function handle_start() {
		$this->log( 'Starting job to reset all product FB data.' );
	}

	/**
	 * Called after the finishing the job.
	 */
	protected function handle_end() {
		$this->log( 'Finished job to reset all product FB data.' );
	}

	/**
	 * Get a set of items for the batch.
	 *
	 * NOTE: when using an OFFSET based query to retrieve items it's recommended to order by the item ID while
	 * ASCENDING. This is so that any newly added items will not disrupt the query offset.
	 *
	 * The job runs in the Action Scheduler runner. When that is the async request, an admin-ajax
	 * loopback, Polylang narrows post queries to the request's language (the default one, or the
	 * one in the cookies forwarded from the admin who started the job), so products in another
	 * language, or in none, were never reset. An empty 'lang' is Polylang's documented way to lift
	 * its filter, and core ignores the argument.
	 *
	 * @param int   $batch_number The batch number increments for each new batch in the job cycle.
	 * @param array $args         The args for the job.
	 *
	 * @throws Exception On error. The failure will be logged by Action Scheduler and the job chain will stop.
	 */
	protected function get_items_for_batch( int $batch_number, array $args ): array {
		$products = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'offset'         => $this->get_query_offset( $batch_number ),
				'posts_per_page' => $this->get_batch_size(),
				'lang'           => '',
			]
		);

		return array_map( 'intval', $products );
	}

	/**
	 * Processes a batch of items.
	 *
	 * @since 3.0.5
	 *
	 * @param array $items The items of the current batch.
	 * @param array $args  The args for the job.
	 *
	 * @throws Exception On error. The failure will be logged by Action Scheduler and the job chain will stop.
	 */
	protected function process_items( array $items, array $args ) {
		foreach ( $items as $product_id ) {
			$integration = facebook_for_woocommerce()->get_integration();
			$integration->reset_single_product( $product_id );
		}
	}

	/**
	 * Empty function to satisfy parent class requirements.
	 * We don't use it because we are processing the whole batch at once in process_items.
	 *
	 * @since 3.0.5
	 *
	 * @param mixed $item The items of the current batch.
	 * @param array $args  The args for the job.
	 *
	 * @return void
	 */
	protected function process_item( $item, array $args ) {}

	/**
	 * Get the name/slug of the job.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'reset_all_catalog_products';
	}

	/**
	 * Get the name/slug of the plugin that owns the job.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return WC_Facebookcommerce::PLUGIN_ID;
	}

	/**
	 * Get the job's batch size.
	 *
	 * @return int
	 */
	public function get_batch_size(): int {
		return 25;
	}
}
