<?php
/**
 * Integration tests : batched stats fetcher.
 *
 * Locks in the contract for Stats::raw_counts_for_experiments() — single SQL
 * returns counts keyed by experiment_id, with zero-filled variant slots for
 * experiments that have no events in the requested window.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Cookie;
use Abtest\Schema;
use Abtest\Stats;
use Abtest\Tracker;
use WP_UnitTestCase;

final class StatsBatchTest extends WP_UnitTestCase {

	private function insert_event( int $exp_id, string $variant, string $type, string $when = '2026-04-15 12:00:00' ): void {
		global $wpdb;
		$wpdb->insert(
			Schema::events_table(),
			[
				'experiment_id' => $exp_id,
				'variant'       => $variant,
				'test_url'      => '/promo/',
				'event_type'    => $type,
				'visitor_hash'  => str_repeat( 'a', Cookie::HASH_LENGTH ),
				'created_at'    => $when,
			]
		);
	}

	public function test_returns_empty_for_empty_id_list(): void {
		$this->assertSame( [], Stats::raw_counts_for_experiments( [] ) );
	}

	public function test_zero_fills_experiments_with_no_events(): void {
		$out = Stats::raw_counts_for_experiments( [ 99991, 99992 ] );
		$this->assertArrayHasKey( 99991, $out );
		$this->assertArrayHasKey( 99992, $out );
		foreach ( [ 'A', 'B', 'C', 'D' ] as $variant ) {
			$this->assertSame( 0, $out[ 99991 ][ $variant ]['impressions'] );
			$this->assertSame( 0, $out[ 99991 ][ $variant ]['conversions'] );
		}
	}

	public function test_batches_counts_for_multiple_experiments(): void {
		// Exp 100: 2 A imps, 1 A conv. Exp 101: 3 B imps, 0 conv.
		$this->insert_event( 100, 'a', Tracker::EVENT_IMPRESSION );
		$this->insert_event( 100, 'a', Tracker::EVENT_IMPRESSION );
		$this->insert_event( 100, 'a', Tracker::EVENT_CONVERSION );
		$this->insert_event( 101, 'b', Tracker::EVENT_IMPRESSION );
		$this->insert_event( 101, 'b', Tracker::EVENT_IMPRESSION );
		$this->insert_event( 101, 'b', Tracker::EVENT_IMPRESSION );

		$out = Stats::raw_counts_for_experiments( [ 100, 101 ] );

		$this->assertSame( 2, $out[100]['A']['impressions'] );
		$this->assertSame( 1, $out[100]['A']['conversions'] );
		$this->assertSame( 0, $out[100]['B']['impressions'] );
		$this->assertSame( 3, $out[101]['B']['impressions'] );
		$this->assertSame( 0, $out[101]['B']['conversions'] );
		$this->assertSame( 0, $out[101]['A']['impressions'] );
	}

	public function test_date_range_filters_events(): void {
		$this->insert_event( 200, 'a', Tracker::EVENT_IMPRESSION, '2026-03-01 10:00:00' );
		$this->insert_event( 200, 'a', Tracker::EVENT_IMPRESSION, '2026-04-15 10:00:00' );
		$this->insert_event( 200, 'a', Tracker::EVENT_IMPRESSION, '2026-05-01 10:00:00' );

		$out = Stats::raw_counts_for_experiments( [ 200 ], '2026-04-01', '2026-04-30' );
		$this->assertSame( 1, $out[200]['A']['impressions'], 'Only the April event should be counted.' );
	}

	public function test_dedupes_and_filters_invalid_ids(): void {
		// Pass duplicates and zero/negative values — they should be normalized.
		$out = Stats::raw_counts_for_experiments( [ 100, 100, 0, -5, 101 ] );
		$this->assertCount( 2, $out );
		$this->assertArrayHasKey( 100, $out );
		$this->assertArrayHasKey( 101, $out );
	}
}
