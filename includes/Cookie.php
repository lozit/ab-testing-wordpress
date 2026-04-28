<?php
/**
 * Cookie helpers for variant assignment persistence.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Cookie {

	private const PREFIX = 'abtest_';

	public static function name( int $experiment_id ): string {
		return self::PREFIX . $experiment_id;
	}

	public static function get_variant( int $experiment_id ): ?string {
		$key = self::name( $experiment_id );
		if ( ! isset( $_COOKIE[ $key ] ) ) {
			return null;
		}
		$value = sanitize_key( wp_unslash( $_COOKIE[ $key ] ) );
		return ( 'a' === $value || 'b' === $value ) ? strtoupper( $value ) : null;
	}

	public static function set_variant( int $experiment_id, string $variant, int $days = 30 ): void {
		if ( headers_sent() ) {
			return;
		}
		$variant = strtoupper( $variant );
		if ( 'A' !== $variant && 'B' !== $variant ) {
			return;
		}
		setcookie(
			self::name( $experiment_id ),
			strtolower( $variant ),
			[
				'expires'  => time() + DAY_IN_SECONDS * $days,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			]
		);
		// Make the value readable later in the same request.
		$_COOKIE[ self::name( $experiment_id ) ] = strtolower( $variant );
	}

	/**
	 * Stable visitor hash for dedup. Salted with wp_salt so it can't be reversed across sites.
	 */
	public static function visitor_hash(): string {
		$ip = '';
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = (string) $_SERVER['REMOTE_ADDR']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		$ua = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$ua = (string) $_SERVER['HTTP_USER_AGENT']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt( 'auth' ) );
	}

	/**
	 * Pick a variant deterministically from a seed (used in tests) or randomly.
	 */
	public static function pick_variant( ?int $seed = null ): string {
		if ( null !== $seed ) {
			mt_srand( $seed );
		}
		$choice = ( mt_rand( 0, 1 ) === 0 ) ? 'A' : 'B';
		if ( null !== $seed ) {
			mt_srand();
		}
		return $choice;
	}
}
