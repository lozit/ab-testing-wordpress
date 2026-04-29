<?php
/**
 * Integration tests : Schema installation + raw event insert/query round-trip.
 *
 * @package Abtest\Tests\Integration
 */

declare( strict_types=1 );

namespace Abtest\Tests\Integration;

use Abtest\Cookie;
use Abtest\Schema;
use Abtest\Tracker;
use WP_UnitTestCase;

final class SchemaTest extends WP_UnitTestCase {

	public function test_events_table_was_created_by_activation(): void {
		global $wpdb;
		$table = Schema::events_table();
		$found = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ); // phpcs:ignore
		$this->assertSame( $table, $found, 'wp_abtest_events table should exist after Plugin::activate().' );
	}

	public function test_events_table_has_expected_columns(): void {
		global $wpdb;
		$table   = Schema::events_table();
		$columns = $wpdb->get_col( "DESCRIBE {$table}" ); // phpcs:ignore

		foreach ( [ 'id', 'experiment_id', 'variant', 'test_url', 'event_type', 'visitor_hash', 'created_at' ] as $expected ) {
			$this->assertContains( $expected, $columns, "Column {$expected} should exist." );
		}
	}

	public function test_can_insert_and_count_events(): void {
		global $wpdb;
		$table = Schema::events_table();

		$wpdb->insert(
			$table,
			[
				'experiment_id' => 999,
				'variant'       => 'A',
				'test_url'      => '/integ-test/',
				'event_type'    => Tracker::EVENT_IMPRESSION,
				'visitor_hash'  => str_repeat( '0', Cookie::HASH_LENGTH ),
				'created_at'    => current_time( 'mysql', true ),
			]
		);

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE experiment_id = %d", // phpcs:ignore
				999
			)
		);
		$this->assertGreaterThanOrEqual( 1, $count );
	}
}
