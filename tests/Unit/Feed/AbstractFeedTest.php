<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package MetaCommerce
 */

namespace WooCommerce\Facebook\Feed;

use WP_UnitTestCase;
use WooCommerce\Facebook\API;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

class TestFeed extends AbstractFeed {
	public function __construct(AbstractFeedFileWriter $file_writer, AbstractFeedHandler $feed_handler, FeedGenerator $feed_generator) {
		$this->init(
			$file_writer,
			$feed_handler,
			$feed_generator,
		);
	}

	protected static function get_data_stream_name(): string {
		return 'test';
	}

	protected static function get_feed_type(): string {
		return 'TEST_FEED';
	}

	protected static function get_feed_gen_interval(): int {
		return HOUR_IN_SECONDS;
	}

	protected static function get_feed_gen_scheduling_interval(): string {
		return Heartbeat::EVERY_5_MINUTES;
	}
}

class AbstractFeedTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {
	/**
	 * The test feed class.
	 *
	 * @var AbstractFeed
	 * @since 3.5.0
	 */
	protected AbstractFeed $feed;

	public function setUp(): void {
		parent::setUp();
		$file_writer    = $this->createMock( AbstractFeedFileWriter::class );
		$feed_handler   = $this->createMock( AbstractFeedHandler::class );
		$feed_generator = $this->createMock( FeedGenerator::class );
		$this->feed = new TestFeed($file_writer, $feed_handler, $feed_generator);
	}

	public function testShouldSkipFeed() {
		update_option( 'wc_facebook_access_token', 'access-token' );
		update_option( 'wc_facebook_commerce_partner_integration_id', '1841465350002849' );
		update_option( 'wc_facebook_commerce_merchant_settings_id', '1352794439398752' );
		$this->assertFalse( $this->feed->should_skip_feed(), 'Feed should not be skipped when CPI ID and CMS ID are set.' );
		update_option( 'wc_facebook_commerce_partner_integration_id', '' );
		update_option( 'wc_facebook_commerce_merchant_settings_id', '1352794439398752' );
		$this->assertTrue( $this->feed->should_skip_feed(), 'Feed should be skipped when CPI ID is empty.' );
		update_option( 'wc_facebook_commerce_partner_integration_id', '1841465350002849' );
		update_option( 'wc_facebook_commerce_merchant_settings_id', '' );
		$this->assertTrue( $this->feed->should_skip_feed(), 'Feed should be skipped when CMS ID is empty.' );
		update_option( 'wc_facebook_commerce_partner_integration_id', '' );
		update_option( 'wc_facebook_commerce_merchant_settings_id', '' );
		$this->assertTrue( $this->feed->should_skip_feed(), 'Feed should be skipped when both CPI ID and CMS ID are empty.' );
		update_option( 'wc_facebook_commerce_partner_integration_id', '1841465350002849' );
		update_option( 'wc_facebook_commerce_merchant_settings_id', '1352794439398752' );
		update_option( 'wc_facebook_access_token', '' );
		$this->assertTrue( $this->feed->should_skip_feed(), 'Feed should be skipped when the store has no access token.' );
	}

	public function testUploadGraphErrorIsLogged() {
		update_option( 'wc_facebook_access_token', 'access-token' );
		update_option( 'wc_facebook_commerce_partner_integration_id', '1841465350002849' );
		update_option( Logger::SETTING_ENABLE_META_DIAGNOSIS, 'yes' );
		delete_transient( Logger::LOGGING_MESSAGE_QUEUE );

		$plugin   = facebook_for_woocommerce();
		$property = new \ReflectionProperty( $plugin, 'api' );
		$property->setAccessible( true );
		$original = $property->getValue( $plugin );

		$api = $this->createMock( API::class );
		$api->method( 'create_common_data_feed_upload' )->willReturn(
			new API\Response(
				wp_json_encode(
					array(
						'error' => array(
							'message' => 'Unsupported post request.',
							'type'    => 'GraphMethodException',
							'code'    => 100,
						),
					)
				)
			)
		);
		$property->setValue( $plugin, $api );

		try {
			$this->feed->send_request_to_upload_feed();
		} finally {
			$property->setValue( $plugin, $original );
		}

		$logs = (array) get_transient( Logger::LOGGING_MESSAGE_QUEUE );
		$last = end( $logs );
		$this->assertSame( 'Abstract feed upload failed.', $last['extra_data']['message'] ?? null, 'A rejected upload should be logged.' );
		$this->assertSame( 100, $last['exception_code'] ?? null );
	}

	public function testGetFeedSecret() {
		$secret_option_name = 'wc_facebook_feed_url_secret_test';
		$this->assertEmpty(get_option($secret_option_name, ''), 'Secret should not be set yet.');
		$secret = $this->feed->get_feed_secret();
		$this->assertNotEmpty($secret, 'When secret is not set yet one should be generated.');
		$this->assertEquals($secret, get_option($secret_option_name, ''), 'Secret should be set.');
	}

	public function testGetDataStreamName() {
		$reflection = new \ReflectionClass($this->feed);
		$method = $reflection->getMethod('get_data_stream_name');
		$method->setAccessible(true);

		$data_stream_name = $method->invoke($this->feed);
		$this->assertEquals('test', $data_stream_name, 'The data stream name should be "test".');
	}

	public function testGetFeedType() {
		$reflection = new \ReflectionClass($this->feed);
		$method = $reflection->getMethod('get_feed_type');
		$method->setAccessible(true);

		$feed_type = $method->invoke($this->feed);
		$this->assertEquals('TEST_FEED', $feed_type, 'The feed type should be "TEST_FEED".');
	}

	public function testGetFeedGenInterval() {
		$reflection = new \ReflectionClass($this->feed);
		$method = $reflection->getMethod('get_feed_gen_interval');
		$method->setAccessible(true);

		$feed_gen_interval = $method->invoke($this->feed);
		$this->assertEquals(HOUR_IN_SECONDS, $feed_gen_interval, 'The feed gen interval should be HOUR_IN_SECONDS.');
	}

	public function testGetFeedGenSchedulingInterval() {
		$reflection = new \ReflectionClass($this->feed);
		$method = $reflection->getMethod('get_feed_gen_scheduling_interval');
		$method->setAccessible(true);

		$feed_gen_scheduling_interval = $method->invoke($this->feed);
		$this->assertEquals(Heartbeat::EVERY_5_MINUTES, $feed_gen_scheduling_interval, 'The feed gen scheduling interval should be Heartbeat::EVERY_5_MINUTES.');
	}
}
