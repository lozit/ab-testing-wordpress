<?php
/**
 * CSV export — admin button that streams a CSV of experiment stats matching
 * the current dashboard filters (date range, running-only toggle).
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Experiment;
use Abtest\Schema;
use Abtest\Stats;
use Abtest\Tracker;

defined( 'ABSPATH' ) || exit;

final class CsvExport {

	public const NONCE = 'abtest_export_csv';

	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ab-testing-wordpress' ), 403 );
		}
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$show = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'running';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$show_all = ( 'all' === $show );

		$experiments = get_posts(
			[
				'post_type'      => Experiment::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		// Apply running-only filter (mirror the dashboard logic).
		if ( ! $show_all ) {
			$running_urls = self::running_urls( $experiments );
			$experiments  = array_filter(
				$experiments,
				static function ( $exp ) use ( $running_urls ) {
					$url = (string) get_post_meta( (int) $exp->ID, Experiment::META_TEST_URL, true );
					return isset( $running_urls[ $url ] );
				}
			);
		}

		$counts = self::aggregate_event_counts( wp_list_pluck( $experiments, 'ID' ), $from, $to );

		$filename = sprintf(
			'abtest-export-%s%s.csv',
			gmdate( 'Y-m-d' ),
			'' !== $from || '' !== $to ? '-filtered' : ''
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel renders accents correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		// Header row.
		fputcsv(
			$out,
			[
				'experiment_id',
				'title',
				'test_url',
				'status',
				'started_at',
				'ended_at',
				'control_id',
				'control_title',
				'variant_id',
				'variant_title',
				'goal_type',
				'goal_value',
				'A_impressions',
				'A_conversions',
				'A_rate',
				'B_impressions',
				'B_conversions',
				'B_rate',
				'lift',
				'p_value',
				'significant',
				'lift_ci_low',
				'lift_ci_high',
				'period_from',
				'period_to',
			]
		);

		foreach ( $experiments as $exp ) {
			$exp_id = (int) $exp->ID;
			$row_counts = $counts[ $exp_id ] ?? [
				'A' => [ 'impressions' => 0, 'conversions' => 0 ],
				'B' => [ 'impressions' => 0, 'conversions' => 0 ],
			];
			$stats      = Stats::compute( $row_counts );
			$control_id = Experiment::get_control_id( $exp_id );
			$variant_id = Experiment::get_variant_id( $exp_id );
			$goal       = Experiment::get_goal( $exp_id );

			fputcsv(
				$out,
				[
					$exp_id,
					(string) get_the_title( $exp ),
					(string) get_post_meta( $exp_id, Experiment::META_TEST_URL, true ),
					Experiment::get_status( $exp_id ),
					(string) get_post_meta( $exp_id, Experiment::META_STARTED_AT, true ),
					(string) get_post_meta( $exp_id, Experiment::META_ENDED_AT, true ),
					$control_id,
					$control_id > 0 ? (string) get_the_title( $control_id ) : '',
					$variant_id,
					$variant_id > 0 ? (string) get_the_title( $variant_id ) : '',
					(string) $goal['type'],
					(string) $goal['value'],
					(int) $stats['A']['impressions'],
					(int) $stats['A']['conversions'],
					self::format_float( (float) $stats['A']['rate'] ),
					(int) $stats['B']['impressions'],
					(int) $stats['B']['conversions'],
					self::format_float( (float) $stats['B']['rate'] ),
					self::format_float( (float) $stats['lift'] ),
					self::format_float( (float) $stats['p_value'] ),
					$stats['significant'] ? '1' : '0',
					self::format_float( (float) $stats['lift_ci_low'] ),
					self::format_float( (float) $stats['lift_ci_high'] ),
					$from,
					$to,
				]
			);
		}

		fclose( $out );
		exit;
	}

	private static function format_float( float $v ): string {
		// 6 decimals — enough for rates and probabilities, no scientific notation.
		return number_format( $v, 6, '.', '' );
	}

	/**
	 * @param \WP_Post[] $experiments
	 * @return array<string, true>
	 */
	private static function running_urls( array $experiments ): array {
		$out = [];
		foreach ( $experiments as $exp ) {
			if ( Experiment::STATUS_RUNNING !== Experiment::get_status( (int) $exp->ID ) ) {
				continue;
			}
			$url = (string) get_post_meta( (int) $exp->ID, Experiment::META_TEST_URL, true );
			if ( '' !== $url ) {
				$out[ $url ] = true;
			}
		}
		return $out;
	}

	/**
	 * Same as ExperimentsList::aggregate_event_counts but local to avoid coupling.
	 *
	 * @param int[] $ids
	 */
	private static function aggregate_event_counts( array $ids, string $from, string $to ): array {
		$ids = array_map( 'intval', $ids );
		$out = [];
		if ( empty( $ids ) ) {
			return $out;
		}

		global $wpdb;
		$table        = Schema::events_table();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		[ $date_sql, $date_params ] = Stats::date_range_clause( $from, $to );
		$params = array_merge( $ids, $date_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT experiment_id, variant, event_type, COUNT(*) AS n
				   FROM {$table}
				  WHERE experiment_id IN ({$placeholders}) {$date_sql}
				  GROUP BY experiment_id, variant, event_type",
				...$params
			),
			ARRAY_A
		);

		foreach ( $ids as $id ) {
			$out[ $id ] = [
				'A' => [ 'impressions' => 0, 'conversions' => 0 ],
				'B' => [ 'impressions' => 0, 'conversions' => 0 ],
			];
		}
		foreach ( (array) $rows as $row ) {
			$exp_id  = (int) $row['experiment_id'];
			$variant = strtoupper( (string) $row['variant'] );
			$type    = (string) $row['event_type'];
			$n       = (int) $row['n'];
			if ( ! isset( $out[ $exp_id ][ $variant ] ) ) {
				continue;
			}
			if ( Tracker::EVENT_IMPRESSION === $type ) {
				$out[ $exp_id ][ $variant ]['impressions'] = $n;
			} elseif ( Tracker::EVENT_CONVERSION === $type ) {
				$out[ $exp_id ][ $variant ]['conversions'] = $n;
			}
		}
		return $out;
	}

	/**
	 * Returns the URL the "Download CSV" button should point to (with current filters preserved).
	 */
	public static function download_url( string $from, string $to, string $show ): string {
		$args = [
			'action' => 'abtest_export_csv',
		];
		if ( '' !== $from ) { $args['from'] = $from; }
		if ( '' !== $to ) { $args['to'] = $to; }
		if ( '' !== $show ) { $args['show'] = $show; }
		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			self::NONCE
		);
	}
}
