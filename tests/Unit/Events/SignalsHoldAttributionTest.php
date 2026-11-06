<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Events;

use ReflectionClass;
use RuntimeException;
use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Events\FacebookSignalsState;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * While a visitor's signals are held, or the hold state cannot be read, the plugin must not
 * persist Meta attribution identifiers: no _fbp/_fbc cookies from the CAPI Parameter Builder
 * and no _fbp in the PHP session.
 *
 * Unlike EventSignalsStateTest, these run the real ParamBuilder, so the browser id comes from
 * the ParamBuilder lookup rather than the cookie fallback, and they also cover a hold-state
 * read that throws and a denial reported by a consent platform through the
 * facebook_signals_held filter.
 */
class SignalsHoldAttributionTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** A returning visitor's Meta browser id cookie. */
	const BROWSER_ID = 'fb.1.1000000.999888777';

	/** @var array Superglobals as they were before the test. */
	private $original_globals = array();

	/** @var mixed The tracker's ParamBuilder before the test. */
	private $original_param_builder;

	/** @var int Number of times the hold state was read. */
	private $hold_reads = 0;

	public function setUp(): void {
		parent::setUp();

		if ( ! isset( $_SESSION ) ) {
			$_SESSION = array();
		}
		$this->original_globals = array( $_SESSION, $_COOKIE, $_GET, $_SERVER['HTTP_USER_AGENT'] ?? null );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test)';
		unset( $_SESSION['_fbc'], $_SESSION['_fbp'], $_COOKIE['_fbc'], $_COOKIE['_fbp'], $_GET['fbclid'] );

		FacebookSignalsState::release();
		$this->original_param_builder = $this->reset_param_builder( null );
		$this->hold_reads             = 0;
	}

	public function tearDown(): void {
		list( $_SESSION, $_COOKIE, $_GET, $user_agent ) = $this->original_globals;
		if ( null === $user_agent ) {
			unset( $_SERVER['HTTP_USER_AGENT'] );
		} else {
			$_SERVER['HTTP_USER_AGENT'] = $user_agent;
		}

		FacebookSignalsState::release();
		$this->reset_param_builder( $this->original_param_builder );

		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// Attribution cookies: param_builder_server_setup()
	// ------------------------------------------------------------------

	/**
	 * Control for the cookie tests below: a visitor landing from an ad gets both cookies.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::param_builder_server_setup
	 */
	public function test_released_signals_set_attribution_cookies(): void {
		$this->watch_hold_state( 'active' );
		$_GET['fbclid'] = 'testclickid123';

		$tracker = $this->create_cookie_recording_tracker();
		$tracker->param_builder_server_setup();

		$this->assertEqualsCanonicalizing( array( '_fbp', '_fbc' ), $tracker->written_cookies );
	}

	/**
	 * @covers WC_Facebookcommerce_EventsTracker::param_builder_server_setup
	 */
	public function test_held_signals_set_no_attribution_cookies(): void {
		$this->watch_hold_state( 'held' );
		$_GET['fbclid'] = 'testclickid123';

		$tracker = $this->create_cookie_recording_tracker();
		$tracker->param_builder_server_setup();

		$this->assertSame( array(), $tracker->written_cookies, 'Attribution cookies were set while the visitor\'s signals were held.' );
		$this->assertGreaterThanOrEqual( 1, $this->hold_reads );
	}

	/**
	 * A failed hold-state read must not be treated as permission to set cookies.
	 *
	 * @covers WC_Facebookcommerce_EventsTracker::param_builder_server_setup
	 */
	public function test_unreadable_hold_state_sets_no_attribution_cookies(): void {
		$this->watch_hold_state( 'unreadable' );
		$_GET['fbclid'] = 'testclickid123';

		$tracker = $this->create_cookie_recording_tracker();
		$this->run_allowing_unreadable_hold_state(
			function () use ( $tracker ) {
				$tracker->param_builder_server_setup();
			}
		);

		$this->assertSame( array(), $tracker->written_cookies, 'Attribution cookies were set when the hold state could not be read.' );
		// The absence above only counts if the failing read actually happened.
		$this->assertGreaterThanOrEqual( 1, $this->hold_reads );
	}

	// ------------------------------------------------------------------
	// Browser id in the session: Event::get_browser_id()
	// ------------------------------------------------------------------

	/**
	 * Control for the session tests below.
	 *
	 * @covers \WooCommerce\Facebook\Events\Event::get_browser_id
	 */
	public function test_released_signals_store_browser_id_in_session(): void {
		$this->watch_hold_state( 'active' );
		$_COOKIE['_fbp'] = self::BROWSER_ID;

		new Event( array( 'event_name' => 'PageView' ) );

		$this->assertArrayHasKey( '_fbp', $_SESSION );
	}

	/**
	 * @covers \WooCommerce\Facebook\Events\Event::get_browser_id
	 */
	public function test_held_signals_keep_browser_id_out_of_session(): void {
		$this->watch_hold_state( 'held' );
		$_COOKIE['_fbp'] = self::BROWSER_ID;

		new Event( array( 'event_name' => 'PageView' ) );

		$this->assertArrayNotHasKey( '_fbp', $_SESSION, 'The browser id was stored in the session while the visitor\'s signals were held.' );
		$this->assertGreaterThanOrEqual( 1, $this->hold_reads );
	}

	/**
	 * A failed hold-state read must not be treated as permission to store the browser id. With
	 * no click id cookie, get_browser_id() makes the first hold-state read.
	 *
	 * @covers \WooCommerce\Facebook\Events\Event::get_browser_id
	 */
	public function test_unreadable_hold_state_keeps_browser_id_out_of_session(): void {
		$this->watch_hold_state( 'unreadable' );
		$_COOKIE['_fbp'] = self::BROWSER_ID;

		$this->run_allowing_unreadable_hold_state(
			function () {
				new Event( array( 'event_name' => 'PageView' ) );
			}
		);

		$this->assertArrayNotHasKey( '_fbp', $_SESSION, 'The browser id was stored in the session when the hold state could not be read.' );
		// The absence above only counts if the failing read actually happened.
		$this->assertGreaterThanOrEqual( 1, $this->hold_reads );
	}

	/**
	 * A denial reported by a consent platform through the facebook_signals_held filter must be
	 * honoured even when the plugin's own signal state is unset.
	 *
	 * @covers \WooCommerce\Facebook\Events\Event::get_browser_id
	 */
	public function test_consent_platform_denial_keeps_browser_id_out_of_session(): void {
		$this->watch_hold_state( 'platform_denied' );
		$_COOKIE['_fbp'] = self::BROWSER_ID;

		new Event( array( 'event_name' => 'PageView' ) );

		$this->assertArrayNotHasKey( '_fbp', $_SESSION, 'The browser id was stored in the session although the visitor\'s consent platform reported a denial.' );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Puts signals in the given state and counts every hold-state read.
	 *
	 * @param string $state active, held, unreadable (the read throws) or platform_denied (only
	 *                      the facebook_signals_held filter reports held).
	 */
	private function watch_hold_state( string $state ): void {
		$this->add_filter_with_safe_teardown(
			'facebook_signals_held',
			function ( $held ) use ( $state ) {
				++$this->hold_reads;
				if ( 'platform_denied' === $state ) {
					return true;
				}
				if ( 'unreadable' === $state ) {
					throw new RuntimeException( 'consent state lookup failed' );
				}
				return $held;
			}
		);

		if ( 'held' === $state ) {
			FacebookSignalsState::hold();
		}
	}

	/**
	 * Runs $callback, letting the unreadable hold state's exception escape it.
	 *
	 * @param callable $callback code under test.
	 */
	private function run_allowing_unreadable_hold_state( callable $callback ): void {
		try {
			$callback();
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'consent state lookup failed', $exception->getMessage() );
		}
	}

	/**
	 * Creates a tracker that records the cookies param_builder_server_setup() writes, without
	 * running the tracker's constructor and its hooks.
	 *
	 * @return \WC_Facebookcommerce_EventsTracker
	 */
	private function create_cookie_recording_tracker() {
		return new class() extends \WC_Facebookcommerce_EventsTracker {
			/** @var string[] Names of the cookies written. */
			public $written_cookies = array();

			public function __construct() {
			}

			protected function set_param_builder_cookie( $cookie ) {
				$this->written_cookies[] = $cookie->name;
			}
		};
	}

	/**
	 * Replaces the tracker's ParamBuilder and clears its cached ids. Null makes the next lookup
	 * build a real ParamBuilder from the current request.
	 *
	 * @param mixed $param_builder the ParamBuilder to install.
	 * @return mixed the ParamBuilder it replaced.
	 */
	private function reset_param_builder( $param_builder ) {
		$ref  = new ReflectionClass( \WC_Facebookcommerce_EventsTracker::class );
		$prop = $ref->getProperty( 'param_builder' );
		$prop->setAccessible( true );
		$original = $prop->getValue();
		$prop->setValue( null, $param_builder );

		foreach ( array( 'cached_fbc', 'cached_fbp' ) as $cache ) {
			$cached = $ref->getProperty( $cache );
			$cached->setAccessible( true );
			$cached->setValue( null, null );
		}

		return $original;
	}
}
