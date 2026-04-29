<?php
/**
 * REST API : pull stats programmatically. Designed for external automation
 * tools (n8n, Make, Pipedream, custom dashboards).
 *
 * GET /wp-json/abtest/v1/stats
 *
 * Auth: WP Application Passwords (Basic Auth) — requires `manage_options`.
 *
 * Query params (all optional):
 *   - url=/promo/             only experiments whose test_url matches
 *   - experiment_id=38        only this experiment
 *   - from=YYYY-MM-DD         restrict events from this date
 *   - to=YYYY-MM-DD           restrict events up to this date
 *   - breakdown=daily         include per-day series for charting
 *   - status=running|...      filter experiments by current status
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Rest;

use Abtest\Experiment;
use Abtest\Stats;

defined( 'ABSPATH' ) || exit;

final class StatsController {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			'abtest/v1',
			'/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'url'           => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'experiment_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'from' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'to' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'breakdown' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'status' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	public function check_permission( \WP_REST_Request $request ): bool {
		unset( $request );
		return current_user_can( 'manage_options' );
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$url           = (string) $request->get_param( 'url' );
		$experiment_id = (int) $request->get_param( 'experiment_id' );
		$from          = (string) $request->get_param( 'from' );
		$to            = (string) $request->get_param( 'to' );
		$breakdown     = (string) $request->get_param( 'breakdown' );
		$status_filter = (string) $request->get_param( 'status' );

		$normalized_url = '' !== $url ? Experiment::normalize_path( $url ) : '';

		// Build the experiment query.
		$query_args = [
			'post_type'      => Experiment::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];
		if ( $experiment_id > 0 ) {
			$query_args['p'] = $experiment_id;
		}

		$meta_query = [];
		if ( '' !== $normalized_url ) {
			$meta_query[] = [
				'key'   => Experiment::META_TEST_URL,
				'value' => $normalized_url,
			];
		}
		if ( '' !== $status_filter ) {
			$meta_query[] = [
				'key'   => Experiment::META_STATUS,
				'value' => $status_filter,
			];
		}
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$experiments = get_posts( $query_args );

		$out = [];
		foreach ( $experiments as $exp ) {
			$exp_id   = (int) $exp->ID;
			$counts   = self::counts_for_experiment( $exp_id, $from, $to );
			$computed = Stats::compute( $counts );

			$variants_meta = Experiment::get_variants( $exp_id );
			$labels        = array_map( static fn( $v ) => (string) $v['label'], $variants_meta );
			if ( empty( $labels ) ) {
				$labels = [ 'A' ];
			}
			foreach ( $labels as $lbl ) {
				if ( ! isset( $counts[ $lbl ] ) ) {
					$counts[ $lbl ] = [ 'impressions' => 0, 'conversions' => 0 ];
				}
			}
			$multi = Stats::compute_multi( $counts, $labels );

			$entry = [
				'id'          => $exp_id,
				'title'       => (string) get_the_title( $exp ),
				'test_url'    => (string) get_post_meta( $exp_id, Experiment::META_TEST_URL, true ),
				'status'      => Experiment::get_status( $exp_id ),
				'started_at'  => (string) get_post_meta( $exp_id, Experiment::META_STARTED_AT, true ),
				'ended_at'    => (string) get_post_meta( $exp_id, Experiment::META_ENDED_AT, true ),
				'control_id'  => Experiment::get_control_id( $exp_id ),     // legacy, == variants[0].post_id
				'variant_id'  => Experiment::get_variant_id( $exp_id ),     // legacy, == variants[1].post_id
				'variants'    => $variants_meta,                             // [{label:'A', post_id:5}, ...]
				'goal'        => Experiment::get_goal( $exp_id ),
				'stats'       => [
					// Multi-variant payload (preferred for new clients).
					'variants'    => $multi['variants'],
					'comparisons' => $multi['comparisons'],
					'baseline'    => $multi['baseline'],
					'best'        => $multi['best'],
					'alpha'       => $multi['alpha'],
					// Legacy A/B keys for back-compat — same numbers as comparisons['B'] when B exists.
					'A'            => $computed['A'],
					'B'            => $computed['B'],
					'lift'         => $computed['lift'],
					'p_value'      => $computed['p_value'],
					'significant'  => $computed['significant'],
					'lift_ci_low'  => $computed['lift_ci_low'],
					'lift_ci_high' => $computed['lift_ci_high'],
					'diff_ci_low'  => $computed['diff_ci_low'],
					'diff_ci_high' => $computed['diff_ci_high'],
				],
			];

			if ( 'daily' === $breakdown && '' !== $entry['test_url'] ) {
				$entry['daily'] = Stats::daily_breakdown_for_url( $entry['test_url'], $from, $to );
			}

			$out[] = $entry;
		}

		return new \WP_REST_Response(
			[
				'filters'     => [
					'url'           => $normalized_url ?: null,
					'experiment_id' => $experiment_id ?: null,
					'from'          => '' !== $from ? $from : null,
					'to'            => '' !== $to ? $to : null,
					'status'        => '' !== $status_filter ? $status_filter : null,
					'breakdown'     => '' !== $breakdown ? $breakdown : null,
				],
				'experiments' => $out,
				'count'       => count( $out ),
				'generated_at' => gmdate( 'c' ),
			],
			200
		);
	}

	/**
	 * Per-experiment counts honoring the optional date range, mirroring what
	 * the admin list view uses but returning a single-experiment shape.
	 */
	private static function counts_for_experiment( int $experiment_id, string $from, string $to ): array {
		global $wpdb;
		$table = \Abtest\Schema::events_table();

		[ $date_sql, $date_params ] = Stats::date_range_clause( $from, $to );
		$params = array_merge( [ $experiment_id ], $date_params );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT variant, event_type, COUNT(*) AS n
				   FROM {$table}
				  WHERE experiment_id = %d {$date_sql}
				  GROUP BY variant, event_type",
				...$params
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
			if ( \Abtest\Tracker::EVENT_IMPRESSION === $type ) {
				$out[ $variant ]['impressions'] = $n;
			} elseif ( \Abtest\Tracker::EVENT_CONVERSION === $type ) {
				$out[ $variant ]['conversions'] = $n;
			}
		}
		return $out;
	}
}
