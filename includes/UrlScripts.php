<?php
/**
 * Per-URL tracking scripts (Adwords / pixels / Lemlist beacons).
 *
 * Storage: a single WP option `abtest_url_scripts` keyed by URL path.
 * Each URL maps to a list of { position, code } entries.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class UrlScripts {

	public const OPTION_KEY = 'abtest_url_scripts';

	public const POSITION_AFTER_BODY_OPEN   = 'after_body_open';
	public const POSITION_BEFORE_BODY_CLOSE = 'before_body_close';

	public static function valid_positions(): array {
		return [ self::POSITION_AFTER_BODY_OPEN, self::POSITION_BEFORE_BODY_CLOSE ];
	}

	/**
	 * Get the list of scripts configured for a given URL path.
	 *
	 * @return array<int, array{position:string, code:string}>
	 */
	public static function get( string $url ): array {
		$url = Experiment::normalize_path( $url );
		if ( '' === $url ) {
			return [];
		}
		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) || ! isset( $all[ $url ] ) || ! is_array( $all[ $url ] ) ) {
			return [];
		}
		// Defensive normalization.
		$out = [];
		foreach ( $all[ $url ] as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$position = isset( $entry['position'] ) ? (string) $entry['position'] : '';
			$code     = isset( $entry['code'] ) ? (string) $entry['code'] : '';
			if ( ! in_array( $position, self::valid_positions(), true ) || '' === trim( $code ) ) {
				continue;
			}
			$out[] = [ 'position' => $position, 'code' => $code ];
		}
		return $out;
	}

	/**
	 * Replace the full list of scripts for a URL. Empty list deletes the entry.
	 *
	 * @param array<int, array{position:string, code:string}> $scripts
	 */
	public static function set( string $url, array $scripts ): void {
		$url = Experiment::normalize_path( $url );
		if ( '' === $url ) {
			return;
		}

		$clean = [];
		foreach ( $scripts as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$position = isset( $entry['position'] ) ? (string) $entry['position'] : '';
			$code     = isset( $entry['code'] ) ? (string) $entry['code'] : '';
			if ( ! in_array( $position, self::valid_positions(), true ) ) {
				continue;
			}
			if ( '' === trim( $code ) ) {
				continue;
			}
			$clean[] = [ 'position' => $position, 'code' => $code ];
		}

		$all = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}

		if ( empty( $clean ) ) {
			unset( $all[ $url ] );
		} else {
			$all[ $url ] = $clean;
		}

		// wp_slash() the values so wp_unslash inside update_option doesn't strip backslashes
		// from JS regex / JSON payloads in the script bodies.
		update_option( self::OPTION_KEY, wp_slash( $all ) );
	}

	/**
	 * Concatenate all scripts at a given position into a single HTML string.
	 */
	public static function render_for_position( string $url, string $position ): string {
		$scripts = self::get( $url );
		$out     = '';
		foreach ( $scripts as $s ) {
			if ( $s['position'] === $position ) {
				$out .= "\n" . $s['code'] . "\n";
			}
		}
		return $out;
	}
}
