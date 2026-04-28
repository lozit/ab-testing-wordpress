<?php
/**
 * REST controller — POST /abtest/v1/convert.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Rest;

use Abtest\Cookie;
use Abtest\Experiment;
use Abtest\Tracker;

defined( 'ABSPATH' ) || exit;

final class ConvertController {

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
			'/convert',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'experiment_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					],
				],
			]
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$experiment_id = (int) $request->get_param( 'experiment_id' );

		// Experiment must exist and be running.
		$experiment = get_post( $experiment_id );
		if ( ! $experiment instanceof \WP_Post || Experiment::POST_TYPE !== $experiment->post_type ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'unknown_experiment' ], 404 );
		}
		if ( Experiment::STATUS_RUNNING !== Experiment::get_status( $experiment_id ) ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'not_running' ], 409 );
		}

		// Variant comes from the cookie set during impression — never trusted from the client.
		$variant = Cookie::get_variant( $experiment_id );
		if ( null === $variant ) {
			return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'no_variant_cookie' ], 400 );
		}

		$visitor = Cookie::visitor_hash();
		$logged  = Tracker::instance()->log_conversion( $experiment_id, $variant, $visitor );

		return new \WP_REST_Response(
			[
				'logged'  => $logged,
				'variant' => $variant,
			],
			$logged ? 201 : 200
		);
	}
}
