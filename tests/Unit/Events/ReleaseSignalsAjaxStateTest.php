<?php
/**
 * Copyright (c) Meta, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Events;

use WooCommerce\Facebook\Events\Event;
use WooCommerce\Facebook\Events\ReleaseSignalsAjax;
use WooCommerce\Facebook\Signals;
use WP_UnitTestCase;

/**
 * Tests the signal-state rule the release endpoint applies before it sends
 * queued events.
 *
 * The browser writes `active` into the state cookie before it posts the queue.
 * A visitor who withdraws while that request is still being prepared flips the
 * cookie to `held`, and the endpoint must then refuse to send anything.
 *
 * @covers \WooCommerce\Facebook\Events\ReleaseSignalsAjax::is_releasable_state
 * @covers \WooCommerce\Facebook\Events\ReleaseSignalsAjax::get_event_ids
 */
class ReleaseSignalsAjaxStateTest extends WP_UnitTestCase {

	/** @dataProvider visit_times */
	public function test_elapsed_age_is_anchored_to_server_time( array $event, ?int $expected_time, bool $valid ): void {
		$class = new \ReflectionClass( ReleaseSignalsAjax::class );
		$handler = $class->newInstanceWithoutConstructor();
		$normalize = $class->getMethod( 'normalize_visit_time' );
		$normalize->setAccessible( true );
		$validate = $class->getMethod( 'validate_event' );
		$validate->setAccessible( true );
		$normalized = $normalize->invoke( $handler, $event, 2000000000 );
		$this->assertSame( $valid, $validate->invoke( $handler, $normalized, 2000000000 ) );
		if ( null === $expected_time ) {
			$this->assertNull( $normalized );
		} else {
			$this->assertSame( $expected_time, $normalized['event_time'] );
			$this->assertArrayNotHasKey( 'visit_age_seconds', $normalized );
		}
	}

	public function visit_times(): array {
		$cases = array();
		foreach ( array( 'PageView', 'ViewContent', 'ViewCategory', 'Search' ) as $name ) {
			foreach ( array( -1860, 1860 ) as $offset ) {
				$cases[] = array( array( 'event_name' => $name, 'event_time' => 2000000000 + $offset, 'visit_age_seconds' => 10 ), 1999999990, true );
			}
		}
		foreach ( array( -1, 1801, '10', null, array(), INF, NAN ) as $age ) {
			$cases[] = array( array( 'event_name' => 'PageView', 'event_time' => 2000000000, 'visit_age_seconds' => $age ), null, false );
		}
		$cases[] = array( array( 'event_name' => 'PageView', 'visit_age_seconds' => 1800 ), 1999998200, true );
		$cases[] = array( array( 'event_name' => 'PageView', 'event_time' => 1999996400 ), 1999996400, false );
		$cases[] = array( array( 'event_name' => 'PageView', 'event_time' => 2000000000 ), 2000000000, true );
		// An action cannot use an elapsed visit age to make an old purchase look recent.
		$cases[] = array( array( 'event_name' => 'Purchase', 'event_time' => 1999996400, 'visit_age_seconds' => 0 ), 1999996400, false );
		$cases[] = array( array( 'event_name' => 'Purchase', 'event_time' => 1999999990 ), 1999999990, true );
		return $cases;
	}

	public function test_active_state_allows_release(): void {
		$this->assertTrue( ReleaseSignalsAjax::is_releasable_state( Signals::STATE_ACTIVE ) );
	}

	public function test_held_state_refuses_release(): void {
		$this->assertFalse( ReleaseSignalsAjax::is_releasable_state( Signals::STATE_HELD ) );
	}

	public function test_missing_state_refuses_release(): void {
		$this->assertFalse( ReleaseSignalsAjax::is_releasable_state( '' ) );
	}

	public function test_unknown_state_is_treated_as_present(): void {
		// Any non-empty value other than `held` keeps today's behaviour: the
		// endpoint only ever rejected a missing cookie.
		$this->assertTrue( ReleaseSignalsAjax::is_releasable_state( 'something-else' ) );
	}

	public function test_sent_event_ids_lists_the_ids_handed_to_the_api(): void {
		$events = array(
			new Event( array( 'event_name' => 'ViewContent', 'event_id' => 'id-one' ) ),
			new Event( array( 'event_name' => 'Purchase', 'event_id' => 'id-two' ) ),
		);

		$this->assertSame( array( 'id-one', 'id-two' ), ReleaseSignalsAjax::get_event_ids( $events ) );
	}

	public function test_sent_event_ids_skips_non_events_and_blank_ids(): void {
		$event = new Event( array( 'event_name' => 'ViewContent', 'event_id' => 'id-one' ) );

		$this->assertSame( array( 'id-one' ), ReleaseSignalsAjax::get_event_ids( array( $event, 'not-an-event', null ) ) );
	}
}
