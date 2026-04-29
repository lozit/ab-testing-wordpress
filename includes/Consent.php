<?php
/**
 * Consent gate helper.
 *
 * Resolves whether the current visitor should be blocked from tracking based on:
 *   - The "Require consent" admin setting (off by default)
 *   - The `abtest_visitor_has_consent` filter (returns true / false / null)
 *
 * Kept as a tiny standalone class so it can be unit-tested without bootstrapping
 * the full WordPress request lifecycle.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Consent {

	public const FILTER = 'abtest_visitor_has_consent';

	/**
	 * Returns true when tracking should be blocked because the admin enabled
	 * "Require consent" but no positive consent signal was received.
	 *
	 * Filter return values :
	 *   - true  → consent given, never block.
	 *   - false → consent explicitly denied, block.
	 *   - null  → no consent system wired (filter not implemented), block by safe default.
	 *
	 * When the setting is OFF the function always returns false (= never block),
	 * preserving the historical no-banner behavior.
	 */
	public static function is_blocked(): bool {
		$settings = (array) get_option( 'abtest_settings', [] );
		if ( empty( $settings['require_consent'] ) ) {
			return false;
		}
		$consent = apply_filters( self::FILTER, null );
		return true !== $consent;
	}
}
