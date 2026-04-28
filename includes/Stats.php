<?php
/**
 * Stats — aggregates events and computes conversion rate, lift, and z-test significance.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Stats {

	/**
	 * @return array{
	 *     A: array{impressions:int,conversions:int,rate:float},
	 *     B: array{impressions:int,conversions:int,rate:float},
	 *     lift: float,
	 *     significant: bool,
	 *     p_value: float
	 * }
	 */
	public static function for_experiment( int $experiment_id ): array {
		$counts = self::raw_counts( $experiment_id );
		return self::compute( $counts );
	}

	/**
	 * Daily breakdown for a given test URL, across all experiments that ran on it.
	 *
	 * Returns:
	 *   [
	 *     'days'    => ['2026-01-15', '2026-01-16', ...]   // unique sorted day labels
	 *     'series'  => [
	 *       'exp_id|variant' => [
	 *         'experiment_id' => 36,
	 *         'variant'       => 'A',
	 *         'rates'         => [0.05, 0.048, null, ...]   // conversion rate per day, null if 0 impressions
	 *         'impressions'   => [120, 135, 0, ...]         // for tooltips
	 *         'conversions'   => [6, 7, 0, ...]
	 *       ],
	 *       ...
	 *     ]
	 *   ]
	 */
	public static function daily_breakdown_for_url( string $test_url, string $from = '', string $to = '' ): array {
		global $wpdb;
		$table = self::events_table();

		[ $where_extra, $params ] = self::date_range_clause( $from, $to );
		$params = array_merge( [ $test_url ], $params );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT DATE(created_at) AS day, experiment_id, variant, event_type, COUNT(*) AS n
				   FROM {$table}
				  WHERE test_url = %s {$where_extra}
				  GROUP BY day, experiment_id, variant, event_type
				  ORDER BY day ASC",
				...$params
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [ 'days' => [], 'series' => [] ];
		}

		// Collect unique days + raw counts per (day, exp, variant, type).
		$days_set = [];
		$counts   = [];
		foreach ( $rows as $row ) {
			$day     = (string) $row['day'];
			$exp_id  = (int) $row['experiment_id'];
			$variant = strtoupper( (string) $row['variant'] );
			$type    = (string) $row['event_type'];
			$n       = (int) $row['n'];
			$days_set[ $day ] = true;
			$counts[ $exp_id ][ $variant ][ $day ][ $type ] = $n;
		}

		$days = array_keys( $days_set );
		sort( $days );

		// Build a series per (experiment_id, variant) actually present in the data.
		$series = [];
		foreach ( $counts as $exp_id => $by_variant ) {
			foreach ( $by_variant as $variant => $by_day ) {
				$rates       = [];
				$impressions = [];
				$conversions = [];
				foreach ( $days as $day ) {
					$imp = isset( $by_day[ $day ][ Tracker::EVENT_IMPRESSION ] ) ? (int) $by_day[ $day ][ Tracker::EVENT_IMPRESSION ] : 0;
					$cv  = isset( $by_day[ $day ][ Tracker::EVENT_CONVERSION ] ) ? (int) $by_day[ $day ][ Tracker::EVENT_CONVERSION ] : 0;
					$impressions[] = $imp;
					$conversions[] = $cv;
					$rates[]       = $imp > 0 ? round( ( $cv / $imp ) * 100, 2 ) : null;
				}
				$series[ $exp_id . '|' . $variant ] = [
					'experiment_id' => $exp_id,
					'variant'       => $variant,
					'rates'         => $rates,
					'impressions'   => $impressions,
					'conversions'   => $conversions,
				];
			}
		}

		return [ 'days' => $days, 'series' => $series ];
	}

	private static function events_table(): string {
		return Schema::events_table();
	}

	/**
	 * Build a `AND created_at >= %s AND created_at <= %s` SQL fragment + params,
	 * skipping bounds that are empty/invalid.
	 *
	 * @return array{0:string, 1:array<string>} [sql_fragment, params_to_append]
	 */
	public static function date_range_clause( string $from, string $to ): array {
		$sql = '';
		$params = [];
		if ( '' !== $from && self::is_valid_date( $from ) ) {
			$sql .= ' AND created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( '' !== $to && self::is_valid_date( $to ) ) {
			$sql .= ' AND created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		return [ $sql, $params ];
	}

	private static function is_valid_date( string $d ): bool {
		// Strict YYYY-MM-DD.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
			return false;
		}
		[ $y, $m, $day ] = array_map( 'intval', explode( '-', $d ) );
		return checkdate( $m, $day, $y );
	}

	public static function raw_counts( int $experiment_id ): array {
		global $wpdb;
		$table = Schema::events_table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT variant, event_type, COUNT(*) AS n FROM {$table} WHERE experiment_id = %d GROUP BY variant, event_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$experiment_id
			),
			ARRAY_A
		);

		$out = [
			'A' => [ 'impressions' => 0, 'conversions' => 0 ],
			'B' => [ 'impressions' => 0, 'conversions' => 0 ],
		];
		foreach ( (array) $rows as $row ) {
			$variant = strtoupper( (string) $row['variant'] );
			if ( ! isset( $out[ $variant ] ) ) {
				continue;
			}
			$type = (string) $row['event_type'];
			$n    = (int) $row['n'];
			if ( Tracker::EVENT_IMPRESSION === $type ) {
				$out[ $variant ]['impressions'] = $n;
			} elseif ( Tracker::EVENT_CONVERSION === $type ) {
				$out[ $variant ]['conversions'] = $n;
			}
		}
		return $out;
	}

	/**
	 * Pure function — easy to unit-test.
	 *
	 * @param array{A:array{impressions:int,conversions:int},B:array{impressions:int,conversions:int}} $counts
	 */
	public static function compute( array $counts ): array {
		$a_imp = max( 0, (int) ( $counts['A']['impressions'] ?? 0 ) );
		$a_cv  = max( 0, (int) ( $counts['A']['conversions'] ?? 0 ) );
		$b_imp = max( 0, (int) ( $counts['B']['impressions'] ?? 0 ) );
		$b_cv  = max( 0, (int) ( $counts['B']['conversions'] ?? 0 ) );

		$a_rate = $a_imp > 0 ? $a_cv / $a_imp : 0.0;
		$b_rate = $b_imp > 0 ? $b_cv / $b_imp : 0.0;

		$lift = $a_rate > 0 ? ( $b_rate - $a_rate ) / $a_rate : 0.0;

		[ $z, $p ] = self::z_test_two_proportions( $a_cv, $a_imp, $b_cv, $b_imp );
		unset( $z );

		// 95% confidence interval for the absolute difference (B rate − A rate),
		// using the unpooled standard error (Wald interval).
		[ $diff_low, $diff_high ] = self::diff_confidence_interval_95( $a_cv, $a_imp, $b_cv, $b_imp );

		// Express the CI as a relative lift range when A rate is meaningful.
		// lift = (B - A) / A → CI bounds for lift are diff_bounds / A.
		$lift_low  = $a_rate > 0 ? $diff_low / $a_rate : 0.0;
		$lift_high = $a_rate > 0 ? $diff_high / $a_rate : 0.0;

		return [
			'A'           => [
				'impressions' => $a_imp,
				'conversions' => $a_cv,
				'rate'        => $a_rate,
			],
			'B'           => [
				'impressions' => $b_imp,
				'conversions' => $b_cv,
				'rate'        => $b_rate,
			],
			'lift'        => $lift,
			'p_value'     => $p,
			'significant' => $p < 0.05 && $a_imp > 0 && $b_imp > 0,
			// 95% confidence interval for the absolute conversion-rate difference (B − A).
			'diff_ci_low'  => $diff_low,
			'diff_ci_high' => $diff_high,
			// Same CI expressed as a relative lift range (e.g. "+15% to +60%").
			'lift_ci_low'  => $lift_low,
			'lift_ci_high' => $lift_high,
		];
	}

	/**
	 * 95% Wald confidence interval for the difference of two proportions (B − A).
	 *
	 * @return array{0:float,1:float} [low, high] — bounds in absolute proportion units.
	 */
	private static function diff_confidence_interval_95( int $x1, int $n1, int $x2, int $n2 ): array {
		if ( $n1 <= 0 || $n2 <= 0 ) {
			return [ 0.0, 0.0 ];
		}
		$p1   = $x1 / $n1;
		$p2   = $x2 / $n2;
		$diff = $p2 - $p1;
		$se   = sqrt( ( $p1 * ( 1 - $p1 ) / $n1 ) + ( $p2 * ( 1 - $p2 ) / $n2 ) );
		$z    = 1.959964; // 95% two-tailed critical value
		return [ $diff - $z * $se, $diff + $z * $se ];
	}

	/**
	 * Two-proportion z-test, returns [z, two-sided p-value].
	 *
	 * @return array{0:float,1:float}
	 */
	private static function z_test_two_proportions( int $x1, int $n1, int $x2, int $n2 ): array {
		if ( $n1 <= 0 || $n2 <= 0 ) {
			return [ 0.0, 1.0 ];
		}
		$p1     = $x1 / $n1;
		$p2     = $x2 / $n2;
		$pooled = ( $x1 + $x2 ) / ( $n1 + $n2 );
		$denom  = sqrt( $pooled * ( 1 - $pooled ) * ( ( 1 / $n1 ) + ( 1 / $n2 ) ) );
		if ( $denom <= 0 ) {
			return [ 0.0, 1.0 ];
		}
		$z = ( $p2 - $p1 ) / $denom;
		$p = 2 * ( 1 - self::normal_cdf( abs( $z ) ) );
		return [ $z, $p ];
	}

	/**
	 * Standard-normal CDF using Abramowitz & Stegun 26.2.17 (≈ 7.5e-8 max error).
	 */
	private static function normal_cdf( float $x ): float {
		$t   = 1.0 / ( 1.0 + 0.2316419 * abs( $x ) );
		$d   = 0.3989422804014327 * exp( - $x * $x / 2.0 );
		$prob = $d * $t * (
			0.319381530 + $t * (
				-0.356563782 + $t * (
					1.781477937 + $t * (
						-1.821255978 + $t * 1.330274429
					)
				)
			)
		);
		return $x > 0 ? ( 1.0 - $prob ) : $prob;
	}
}
