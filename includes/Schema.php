<?php
/**
 * Database schema management (custom tables via dbDelta).
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function events_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'abtest_events';
	}

	public static function install(): void {
		global $wpdb;

		$table   = self::events_table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky: 2 spaces after PRIMARY KEY, types in CAPS, no backticks.
		// test_url is stored per-event so future stats can aggregate by URL across experiments.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			experiment_id BIGINT UNSIGNED NOT NULL,
			variant CHAR(1) NOT NULL,
			test_url VARCHAR(2048) NULL,
			event_type VARCHAR(20) NOT NULL,
			visitor_hash CHAR(16) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY exp_var_type (experiment_id, variant, event_type),
			KEY visitor_exp (visitor_hash, experiment_id),
			KEY test_url_idx (test_url(191))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public static function drop(): void {
		global $wpdb;
		$table = self::events_table();
		// $table from self::events_table() is plugin-controlled — safe interpolation.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
