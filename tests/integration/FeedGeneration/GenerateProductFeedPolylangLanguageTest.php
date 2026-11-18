<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Integration\FeedGeneration;

use Automattic\WooCommerce\ActionSchedulerJobFramework\Proxies\ActionScheduler;
use WooCommerce\Facebook\Jobs\GenerateProductFeed;
use WooCommerce\Facebook\Tests\Integration\IntegrationTestCase;
use WooCommerce\Facebook\Tests\Integration\PolylangLanguagesTrait;

/**
 * Tests which products a batch of the feed generation job writes on a real Polylang.
 *
 * Runs only when Polylang is active (FB_TEST_PLUGIN=polylang).
 */
class GenerateProductFeedPolylangLanguageTest extends IntegrationTestCase {

	use PolylangLanguagesTrait;

	/** @var string|null where the job writes its batches during the test */
	private $temp_feed_file;

	public function setUp(): void {
		parent::setUp();
		$this->set_up_polylang_languages();

		$this->temp_feed_file = tempnam( sys_get_temp_dir(), 'fb-feed-' );
		add_filter( 'wc_facebook_product_catalog_temp_feed_file_path', array( $this, 'get_temp_feed_file' ) );
	}

	public function tearDown(): void {
		remove_filter( 'wc_facebook_product_catalog_temp_feed_file_path', array( $this, 'get_temp_feed_file' ) );
		if ( $this->temp_feed_file && file_exists( $this->temp_feed_file ) ) {
			unlink( $this->temp_feed_file );
		}

		parent::tearDown();
		$this->restore_polylang_languages();
	}

	public function get_temp_feed_file(): string {
		return $this->temp_feed_file;
	}

	/**
	 * Runs one batch of the job and returns the feed rows it wrote.
	 *
	 * @param \WC_Product[] $products the products the batch holds
	 */
	private function write_feed_batch( array $products ): string {
		$job           = new GenerateProductFeed( new ActionScheduler() );
		$process_items = new \ReflectionMethod( $job, 'process_items' );
		$process_items->setAccessible( true );
		$process_items->invoke(
			$job,
			array_map(
				static function ( \WC_Product $product ) {
					return $product->get_id();
				},
				$products
			),
			array()
		);

		return (string) file_get_contents( $this->temp_feed_file );
	}

	public function test_batch_writes_the_default_language_products(): void {
		$original    = $this->create_simple_product( array( 'name' => 'Original in English' ) );
		$translation = $this->create_simple_product( array( 'name' => 'Translation in Spanish' ) );
		pll_set_post_language( $original->get_id(), 'en' );
		pll_set_post_language( $translation->get_id(), 'es' );

		$feed = $this->write_feed_batch( array( $original, $translation ) );

		$this->assertStringContainsString( 'Original in English', $feed, 'The batch query must find the default language products.' );
		$this->assertStringNotContainsString( 'Translation in Spanish', $feed );
	}

	/**
	 * Polylang slugs are free text, so 'br' for pt_BR shares nothing with the locale.
	 */
	public function test_batch_writes_the_default_language_products_when_the_slug_is_unlike_the_locale(): void {
		$this->add_language( 'br', 'pt_BR' );
		$this->set_default_language( 'br' );

		$original    = $this->create_simple_product( array( 'name' => 'Original in Portuguese' ) );
		$translation = $this->create_simple_product( array( 'name' => 'Translation in English' ) );
		pll_set_post_language( $original->get_id(), 'br' );
		pll_set_post_language( $translation->get_id(), 'en' );

		$feed = $this->write_feed_batch( array( $original, $translation ) );

		$this->assertStringContainsString( 'Original in Portuguese', $feed, 'The batch query must find the default language products.' );
		$this->assertStringNotContainsString( 'Translation in English', $feed );
	}
}
