<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Utilities;

use WooCommerce\Facebook\API;
use WooCommerce\Facebook\Handlers\PluginRender;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;
use WooCommerce\Facebook\Utilities\Heartbeat;
use WooCommerce\Facebook\Utilities\PluginConfigTelemetry;

/**
 * Unit tests for the plugin configuration telemetry.
 */
class PluginConfigTelemetryTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The telemetry is logged once per throttle window as the plugin_updates flow.
	 */
	public function test_logs_plugin_config_once_per_throttle_window(): void {
		delete_transient( '_wc_facebook_for_woocommerce_send_plugin_config_flag' );
		update_option( PluginRender::MASTER_SYNC_OPT_OUT_TIME, 'opt-out-time' );
		set_transient( PluginConfigTelemetry::TRANSIENT_LANGUAGE_FEED_STATS, array( 'fr_FR' => 3 ) );

		$plugin   = facebook_for_woocommerce();
		$property = new \ReflectionProperty( $plugin, 'api' );
		$property->setAccessible( true );
		$original = $property->getValue( $plugin );

		$sent = array();
		$api  = $this->createMock( API::class );
		$api->method( 'log_to_meta' )->willReturnCallback(
			function ( $context ) use ( &$sent ) {
				$sent[] = $context;
				return new API\Response( wp_json_encode( array( 'success' => true ) ) );
			}
		);
		$property->setValue( $plugin, $api );

		try {
			$telemetry = new PluginConfigTelemetry();
			$this->assertSame( 10, has_action( Heartbeat::HOURLY, array( $telemetry, 'send_plugin_config_to_facebook_server' ) ) );

			$telemetry->send_plugin_config_to_facebook_server();
			$telemetry->send_plugin_config_to_facebook_server();
		} finally {
			$property->setValue( $plugin, $original );
		}

		$this->assertCount( 1, $sent, 'The second call inside the throttle window must not log again.' );
		$this->assertSame( 'persist_meta_logs', $sent[0]['event'] );

		$logs = json_decode( $sent[0]['extra_data']['meta_logs'], true );
		$this->assertSame( 'plugin_updates', $logs[0]['flow_name'] );
		$this->assertSame( 'opt-out-time', $logs[0]['extra_data']['opted_out_woo_all_products'] );
		$this->assertSame( wp_json_encode( array( 'fr_FR' => 3 ) ), $logs[0]['extra_data']['language_feed_stats'] );
	}
}
