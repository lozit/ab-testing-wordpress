<?php
/**
 * Integration tests : CPT registration, meta accessors, find_running_for_url().
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Experiment;
use WP_UnitTestCase;

final class ExperimentTest extends WP_UnitTestCase {

	public function test_cpt_is_registered(): void {
		// CPT registration runs on `init` action; ensure it has fired.
		do_action( 'init' );
		$this->assertTrue( post_type_exists( Experiment::POST_TYPE ), 'ab_experiment CPT should be registered.' );
	}

	public function test_get_test_url_returns_stored_value(): void {
		$id = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'T' ]
		);
		update_post_meta( $id, Experiment::META_TEST_URL, '/promo/' );

		$this->assertSame( '/promo/', Experiment::get_test_url( $id ) );
	}

	public function test_find_running_for_url_returns_only_running_match(): void {
		// Three experiments on /promo/ : two ended + one running.
		$ended_a = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $ended_a, Experiment::META_TEST_URL, '/promo/' );
		update_post_meta( $ended_a, Experiment::META_STATUS, Experiment::STATUS_ENDED );

		$ended_b = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $ended_b, Experiment::META_TEST_URL, '/promo/' );
		update_post_meta( $ended_b, Experiment::META_STATUS, Experiment::STATUS_ENDED );

		$running = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $running, Experiment::META_TEST_URL, '/promo/' );
		update_post_meta( $running, Experiment::META_STATUS, Experiment::STATUS_RUNNING );

		$found = Experiment::find_running_for_url( '/promo/' );
		$this->assertNotNull( $found );
		$this->assertSame( $running, (int) $found->ID );
	}

	public function test_state_machine_transitions(): void {
		$this->assertTrue( Experiment::is_transition_allowed( 'draft', 'running' ) );
		$this->assertTrue( Experiment::is_transition_allowed( 'running', 'paused' ) );
		$this->assertTrue( Experiment::is_transition_allowed( 'running', 'ended' ) );
		$this->assertTrue( Experiment::is_transition_allowed( 'paused', 'ended' ) );

		// PAUSED → RUNNING is forbidden (must use Resume = duplicate).
		$this->assertFalse( Experiment::is_transition_allowed( 'paused', 'running' ) );
		// ENDED is terminal.
		$this->assertFalse( Experiment::is_transition_allowed( 'ended', 'running' ) );
		$this->assertFalse( Experiment::is_transition_allowed( 'ended', 'paused' ) );
		$this->assertFalse( Experiment::is_transition_allowed( 'ended', 'draft' ) );
	}
}
