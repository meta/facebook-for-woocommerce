<?php
/**
 * Copyright (c) Meta, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Events;

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
 */
class ReleaseSignalsAjaxStateTest extends WP_UnitTestCase {

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
}
