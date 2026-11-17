<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 */

declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Integrations;

use WooCommerce\Facebook\Integrations\WPML;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for the WPML integration's language scope helper.
 */
class WPMLTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/** @var string[] languages passed to wpml_switch_language, in order */
	private $switches = array();

	/** @var string what the stand-in WPML reports as its current language */
	private $current_language = 'de';

	public function setUp(): void {
		parent::setUp();
		$this->switches         = array();
		$this->current_language = 'de';
		$this->add_filter_with_safe_teardown(
			'wpml_switch_language',
			function ( $language ) {
				$this->switches[]       = $language;
				$this->current_language = $language;
			}
		);
	}

	/** Stands in for an active WPML: its filter reports the current language. */
	private function activate_wpml_stand_in(): void {
		$this->add_filter_with_safe_teardown(
			'wpml_current_language',
			function () {
				return $this->current_language;
			}
		);
	}

	public function test_run_in_language_switches_for_the_callback_and_back() {
		$this->activate_wpml_stand_in();
		$seen = null;

		$result = WPML::run_in_language(
			'all',
			function () use ( &$seen ) {
				$seen = $this->current_language;
				return 'value';
			}
		);

		$this->assertSame( 'value', $result );
		$this->assertSame( 'all', $seen, 'The callback runs in the requested language.' );
		$this->assertSame( array( 'all', 'de' ), $this->switches );
		$this->assertSame( 'de', $this->current_language, 'The previous language is restored.' );
	}

	public function test_run_in_language_restores_the_language_when_the_callback_throws() {
		$this->activate_wpml_stand_in();

		try {
			WPML::run_in_language(
				'en',
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception must propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( array( 'en', 'de' ), $this->switches, 'The language is restored even when the callback throws.' );
	}

	public function test_run_in_language_does_not_switch_when_already_on_that_language() {
		$this->activate_wpml_stand_in();

		WPML::run_in_language( 'de', static function () {} );

		$this->assertSame( array(), $this->switches );
	}

	public function test_run_in_language_does_not_switch_for_an_empty_language() {
		$this->activate_wpml_stand_in();

		WPML::run_in_language( null, static function () {} );
		WPML::run_in_language( '', static function () {} );

		$this->assertSame( array(), $this->switches );
	}

	public function test_run_in_language_just_runs_the_callback_without_wpml() {
		$ran = false;

		$result = WPML::run_in_language(
			'all',
			static function () use ( &$ran ) {
				$ran = true;
				return 42;
			}
		);

		$this->assertTrue( $ran );
		$this->assertSame( 42, $result );
		$this->assertSame( array(), $this->switches, 'Without WPML nothing is switched.' );
	}
}
