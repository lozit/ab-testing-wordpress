<?php
/**
 * Integration tests : Scheduler auto-start + auto-end transitions.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Experiment;
use Abtest\Scheduler;
use WP_UnitTestCase;

final class SchedulerTest extends WP_UnitTestCase {

	public function test_auto_start_transitions_due_drafts_to_running(): void {
		do_action( 'init' );

		$id = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $id, Experiment::META_TEST_URL, '/sched-start/' );
		update_post_meta( $id, Experiment::META_STATUS, Experiment::STATUS_DRAFT );
		update_post_meta( $id, Experiment::META_SCHEDULE_START_AT, gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

		Scheduler::tick();

		$this->assertSame( Experiment::STATUS_RUNNING, Experiment::get_status( $id ) );
		$this->assertNotEmpty( get_post_meta( $id, Experiment::META_STARTED_AT, true ) );
		$this->assertEmpty(
			get_post_meta( $id, Experiment::META_SCHEDULE_START_AT, true ),
			'schedule_start_at should be cleared after firing.'
		);
	}

	public function test_auto_end_transitions_due_running_to_ended(): void {
		do_action( 'init' );

		$id = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $id, Experiment::META_TEST_URL, '/sched-end/' );
		update_post_meta( $id, Experiment::META_STATUS, Experiment::STATUS_RUNNING );
		update_post_meta( $id, Experiment::META_STARTED_AT, gmdate( 'Y-m-d H:i:s', time() - 86400 ) );
		update_post_meta( $id, Experiment::META_SCHEDULE_END_AT, gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

		Scheduler::tick();

		$this->assertSame( Experiment::STATUS_ENDED, Experiment::get_status( $id ) );
		$this->assertNotEmpty( get_post_meta( $id, Experiment::META_ENDED_AT, true ) );
		$this->assertEmpty(
			get_post_meta( $id, Experiment::META_SCHEDULE_END_AT, true ),
			'schedule_end_at should be cleared after firing.'
		);
	}

	public function test_auto_start_skips_when_url_conflict(): void {
		do_action( 'init' );

		// Existing running experiment on the URL.
		$existing = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $existing, Experiment::META_TEST_URL, '/sched-conflict/' );
		update_post_meta( $existing, Experiment::META_STATUS, Experiment::STATUS_RUNNING );

		// Draft scheduled to start, same URL.
		$draft = self::factory()->post->create(
			[ 'post_type' => Experiment::POST_TYPE, 'post_status' => 'publish' ]
		);
		update_post_meta( $draft, Experiment::META_TEST_URL, '/sched-conflict/' );
		update_post_meta( $draft, Experiment::META_STATUS, Experiment::STATUS_DRAFT );
		update_post_meta( $draft, Experiment::META_SCHEDULE_START_AT, gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

		Scheduler::tick();

		$this->assertSame(
			Experiment::STATUS_DRAFT,
			Experiment::get_status( $draft ),
			'Draft should remain DRAFT when conflict.'
		);
		// schedule_start_at preserved so a future cron retries when the conflict clears.
		$this->assertNotEmpty( get_post_meta( $draft, Experiment::META_SCHEDULE_START_AT, true ) );
	}
}
