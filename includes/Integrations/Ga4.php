<?php
/**
 * GA4 integration via Measurement Protocol.
 *
 * Listens to the `abtest_event_logged` action and forwards each impression /
 * conversion to GA4 server-side. Fire-and-forget (non-blocking) to keep the
 * page render fast.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Integrations;

use Abtest\Tracker;

defined( 'ABSPATH' ) || exit;

final class Ga4 {

	public const OPTION_KEY = 'abtest_ga4_settings';

	private const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'abtest_event_logged', [ $this, 'on_event' ], 10, 5 );
	}

	/**
	 * @return array{enabled:bool,measurement_id:string,api_secret:string}
	 */
	public static function get_settings(): array {
		$defaults = [ 'enabled' => false, 'measurement_id' => '', 'api_secret' => '' ];
		$stored   = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}
		return array_merge( $defaults, $stored );
	}

	public function on_event( int $experiment_id, string $variant, string $event_type, string $visitor, string $test_url ): void {
		$cfg = self::get_settings();
		if ( empty( $cfg['enabled'] ) || '' === $cfg['measurement_id'] || '' === $cfg['api_secret'] ) {
			return;
		}

		// GA4 event names must be snake_case, ≤40 chars, [a-zA-Z_].
		$event_name = Tracker::EVENT_CONVERSION === $event_type ? 'abtest_conversion' : 'abtest_impression';

		$payload = [
			// client_id is required; we use the (already-hashed) visitor as it's stable and pseudonymous.
			'client_id' => $visitor,
			'events'    => [
				[
					'name'   => $event_name,
					'params' => [
						'experiment_id' => $experiment_id,
						'variant'       => $variant,
						'test_url'      => $test_url,
					],
				],
			],
		];

		$url = add_query_arg(
			[
				'measurement_id' => rawurlencode( $cfg['measurement_id'] ),
				'api_secret'     => rawurlencode( $cfg['api_secret'] ),
			],
			self::ENDPOINT
		);

		// Fire-and-forget: short timeout, non-blocking. Don't slow down the page.
		wp_remote_post(
			$url,
			[
				'method'   => 'POST',
				'timeout'  => 1,
				'blocking' => false,
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => wp_json_encode( $payload ),
				'sslverify' => true,
			]
		);
	}
}
