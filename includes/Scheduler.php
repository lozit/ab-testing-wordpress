<?php
/**
 * Scheduler — auto start / auto end experiments at admin-set datetimes via WP-Cron.
 *
 * Two optional meta fields per experiment :
 *   - _abtest_schedule_start_at : DRAFT → RUNNING when this date/time is reached
 *   - _abtest_schedule_end_at   : RUNNING → ENDED when this date/time is reached
 *
 * The cron event runs hourly. WP-Cron is opportunistic (fires on visitor traffic),
 * not real-time — admins should be aware their schedules may delay by up to ~1 hour
 * on low-traffic sites. For real-time precision, set up a system cron hitting
 * wp-cron.php every 5 minutes (DISABLE_WP_CRON pattern).
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const CRON_HOOK = 'abtest_run_scheduler';

	public static function register(): void {
		add_action( self::CRON_HOOK, [ self::class, 'tick' ] );

		// Self-heal: if the cron event isn't scheduled yet (fresh install or admin
		// purged WP-Cron), schedule it on the first admin pageload.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	public static function activate(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback — sweep schedules and apply due transitions.
	 */
	public static function tick(): void {
		$now = current_time( 'mysql', true );
		self::auto_start_due( $now );
		self::auto_end_due( $now );
	}

	/**
	 * DRAFT experiments whose schedule_start_at <= now → try to start.
	 * If the URL is already taken by another running experiment, leave as DRAFT
	 * (user resolves manually) — same soft-conflict logic as the form save.
	 */
	private static function auto_start_due( string $now ): void {
		$candidates = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => Experiment::META_STATUS,
						'value'   => Experiment::STATUS_DRAFT,
					],
					[
						'key'     => Experiment::META_SCHEDULE_START_AT,
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'     => Experiment::META_SCHEDULE_START_AT,
						'value'   => $now,
						'compare' => '<=',
						'type'    => 'DATETIME',
					],
				],
			]
		);

		foreach ( $candidates as $id ) {
			$id       = (int) $id;
			$test_url = (string) get_post_meta( $id, Experiment::META_TEST_URL, true );

			// Skip if URL is already taken by another running experiment.
			if ( '' !== $test_url ) {
				$conflict = Experiment::find_running_for_url( $test_url );
				if ( $conflict instanceof \WP_Post && (int) $conflict->ID !== $id ) {
					continue;
				}
			}

			update_post_meta( $id, Experiment::META_STATUS, Experiment::STATUS_RUNNING );
			update_post_meta( $id, Experiment::META_STARTED_AT, current_time( 'mysql', true ) );

			Plugin::ensure_private_status( Experiment::get_control_id( $id ) );
			$variant_id = Experiment::get_variant_id( $id );
			if ( $variant_id > 0 ) {
				Plugin::ensure_private_status( $variant_id );
			}

			// One-shot trigger : clear the schedule_start_at so we don't re-fire
			// if the user pauses & restarts manually later.
			delete_post_meta( $id, Experiment::META_SCHEDULE_START_AT );

			/**
			 * Fires after an experiment was auto-started by the scheduler.
			 *
			 * @param int $experiment_id
			 */
			do_action( 'abtest_scheduler_started', $id );
		}
	}

	/**
	 * RUNNING experiments whose schedule_end_at <= now → end.
	 */
	private static function auto_end_due( string $now ): void {
		$candidates = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => Experiment::META_STATUS,
						'value'   => Experiment::STATUS_RUNNING,
					],
					[
						'key'     => Experiment::META_SCHEDULE_END_AT,
						'value'   => '',
						'compare' => '!=',
					],
					[
						'key'     => Experiment::META_SCHEDULE_END_AT,
						'value'   => $now,
						'compare' => '<=',
						'type'    => 'DATETIME',
					],
				],
			]
		);

		foreach ( $candidates as $id ) {
			$id = (int) $id;
			update_post_meta( $id, Experiment::META_STATUS, Experiment::STATUS_ENDED );

			$existing_end = (string) get_post_meta( $id, Experiment::META_ENDED_AT, true );
			if ( '' === $existing_end ) {
				update_post_meta( $id, Experiment::META_ENDED_AT, current_time( 'mysql', true ) );
			}
			delete_post_meta( $id, Experiment::META_SCHEDULE_END_AT );

			/**
			 * Fires after an experiment was auto-ended by the scheduler.
			 *
			 * @param int $experiment_id
			 */
			do_action( 'abtest_scheduler_ended', $id );
		}
	}
}
