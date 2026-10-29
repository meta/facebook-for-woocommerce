<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests;

use ReflectionClass;

/**
 * Unit test for disable-window escalation by crash guard level.
 *
 * @since 3.6.4
 */
class BootstrapDisableWindowEscalationTest extends AbstractWPUnitTestWithSafeFiltering {

	/** @var \WC_Facebook_Loader */
	private $loader;

	/** @var ReflectionClass */
	private $reflection;

	public function setUp(): void {
		parent::setUp();
		$this->loader     = \WC_Facebook_Loader::instance();
		$this->reflection = new ReflectionClass( \WC_Facebook_Loader::class );
	}

	public function test_disable_window_escalates_and_caps_without_a_permanent_tier(): void {
		$get_disable_window_seconds = $this->reflection->getMethod( 'get_disable_window_seconds' );
		$get_disable_window_seconds->setAccessible( true );

		$this->assertSame( 10 * MINUTE_IN_SECONDS, $get_disable_window_seconds->invoke( $this->loader, 1 ) );
		$this->assertSame( HOUR_IN_SECONDS, $get_disable_window_seconds->invoke( $this->loader, 2 ) );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $get_disable_window_seconds->invoke( $this->loader, 3 ) );
		$this->assertSame( DAY_IN_SECONDS, $get_disable_window_seconds->invoke( $this->loader, 4 ) );
		$this->assertSame( DAY_IN_SECONDS, $get_disable_window_seconds->invoke( $this->loader, 5 ), 'The pause caps at 24 hours; there is no permanent disable.' );
	}
}
