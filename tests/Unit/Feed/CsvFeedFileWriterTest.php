<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Tests\Unit\Feed;

use WooCommerce\Facebook\Feed\CsvFeedFileWriter;
use WooCommerce\Facebook\Feed\FeedManager;
use WooCommerce\Facebook\Feed\PromotionsFeed;
use WooCommerce\Facebook\Feed\ShippingProfilesFeed;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Tests the files written by CsvFeedFileWriter.
 *
 * @covers \WooCommerce\Facebook\Feed\CsvFeedFileWriter
 * @covers \WooCommerce\Facebook\Feed\AbstractFeedFileWriter
 */
class CsvFeedFileWriterTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var CsvFeedFileWriter[] Writers whose feed directories are removed on tear down. */
	private $writers = array();

	public function tearDown(): void {
		foreach ( $this->writers as $writer ) {
			$directory = $writer->get_file_directory();
			if ( is_dir( $directory ) ) {
				foreach ( glob( $directory . '/{,.}*', GLOB_BRACE ) as $file ) {
					if ( is_file( $file ) ) {
						unlink( $file );
					}
				}
				rmdir( $directory );
			}
		}
		$this->writers = array();

		parent::tearDown();
	}

	/**
	 * The shipping profiles header constant is comma-separated but the feed file is
	 * tab-separated, so the header must be written with tabs to match the data rows.
	 */
	public function test_shipping_profiles_feed_header_uses_tab_delimiter(): void {
		$row = array(
			'shipping_profile_id'      => '1-all_products',
			'name'                     => 'United States',
			'shipping_zones'           => array(
				array(
					'country'                   => 'US',
					'states'                    => array(),
					'applies_to_entire_country' => true,
				),
			),
			'shipping_rates'           => array(
				array(
					'name'                           => 'Free shipping',
					'has_free_shipping'              => 'true',
					'cart_minimum_for_free_shipping' => '75 USD',
				),
			),
			'applies_to_all_products'  => 'true',
			'applies_to_rest_of_world' => 'false',
		);

		$rows = $this->write_and_parse_feed( FeedManager::SHIPPING_PROFILES, ShippingProfilesFeed::SHIPPING_PROFILES_FEED_HEADER, "\t", array( $row ) );

		$header = array_shift( $rows );
		$this->assertSame(
			array(
				'shipping_profile_id',
				'name',
				'shipping_zones',
				'shipping_rates',
				'applies_to_all_products',
				'applicable_products_filter',
				'applies_to_rest_of_world',
			),
			$header
		);
		$this->assertCount( 1, $rows );
		$this->assertCount( count( $header ), $rows[0] );

		$shipping_rates = json_decode( $rows[0][ array_search( 'shipping_rates', $header, true ) ], true );
		$this->assertSame( '75 USD', $shipping_rates[0]['cart_minimum_for_free_shipping'] );
	}

	/**
	 * Comma-delimited feeds keep writing the header columns unchanged.
	 */
	public function test_comma_feed_header_is_unchanged(): void {
		$rows = $this->write_and_parse_feed(
			FeedManager::PROMOTIONS,
			PromotionsFeed::PROMOTIONS_FEED_HEADER,
			',',
			array(
				array(
					'offer_id' => '1',
					'title'    => 'Sale',
				),
			)
		);

		$header = array_shift( $rows );
		$this->assertSame( str_getcsv( trim( PromotionsFeed::PROMOTIONS_FEED_HEADER ), ',', '"', '\\' ), $header );
		$this->assertCount( 1, $rows );
		$this->assertCount( count( $header ), $rows[0] );
		$this->assertSame( '1', $rows[0][0] );
		$this->assertSame( 'Sale', $rows[0][1] );
	}

	/**
	 * Writes a feed file and parses it back with RFC 4180 quoting and no escape character.
	 *
	 * @param string $feed_name  Feed name.
	 * @param string $header_row Header row constant.
	 * @param string $delimiter  Feed file delimiter.
	 * @param array  $data       Feed rows keyed by header column.
	 * @return array Parsed rows, header first.
	 */
	private function write_and_parse_feed( string $feed_name, string $header_row, string $delimiter, array $data ): array {
		$writer          = new CsvFeedFileWriter( $feed_name, $header_row, $delimiter );
		$this->writers[] = $writer;

		$writer->write_feed_file( $data );

		$file_path = $writer->get_file_path();
		$this->assertFileExists( $file_path );

		$rows   = array();
		$handle = fopen( $file_path, 'r' );
		while ( ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) !== false ) {
			$rows[] = $row;
		}
		fclose( $handle );

		return $rows;
	}
}
