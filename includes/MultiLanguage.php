<?php
/**
 * MultiLanguage — strip WPML / Polylang URL prefixes so a single experiment
 * applies across translations.
 *
 * Without this, a `test_url = /promo/` experiment would never match the WPML
 * URL `/fr/promo/` or the Polylang URL `/de/promo/`. The filter strips the
 * leading `/{lang}/` segment when the slug matches an active site language.
 *
 * Wiring is automatic when WPML or Polylang is detected. Site owners who
 * want different behavior (e.g., only test on French pages) can :
 *
 *   remove_filter( 'abtest_request_path', [ \Abtest\MultiLanguage::class, 'strip_language_prefix' ] );
 *
 * …and replace it with their own callback. Custom multilingual stacks can
 * just hook the filter directly without any auto-detection.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class MultiLanguage {

	public const FILTER = 'abtest_request_path';

	/**
	 * Cached list of language slugs detected on the site (lowercase).
	 *
	 * @var string[]|null
	 */
	private static ?array $languages_cache = null;

	public static function register(): void {
		// Hook on init so WPML/Polylang have time to bootstrap their globals.
		// Priority 20 keeps us after most plugins' own init hooks.
		add_action( 'init', [ self::class, 'maybe_register_filter' ], 20 );
	}

	/**
	 * Self-register the path-stripper filter when a multilang plugin is detected.
	 * Idempotent — safe to call multiple times.
	 */
	public static function maybe_register_filter(): void {
		if ( ! self::is_multilang_active() ) {
			return;
		}
		if ( ! has_filter( self::FILTER, [ self::class, 'strip_language_prefix' ] ) ) {
			add_filter( self::FILTER, [ self::class, 'strip_language_prefix' ] );
		}
	}

	/**
	 * Strip a leading `/{lang}/` segment if `{lang}` matches an active
	 * site language slug. Idempotent on already-stripped paths.
	 *
	 * @param mixed $path
	 */
	public static function strip_language_prefix( $path ): string {
		if ( ! is_string( $path ) || '' === $path ) {
			return (string) $path;
		}

		$languages = self::active_languages();
		if ( empty( $languages ) ) {
			return $path;
		}

		// Match `/xx/...` or `/xx-yy/...` — Polylang allows compound slugs like `pt-br`.
		if ( ! preg_match( '#^/([a-z]{2,3}(?:-[a-z]{2,3})?)(/|$|\?)#', $path, $matches ) ) {
			return $path;
		}

		$candidate = $matches[1];
		if ( ! in_array( $candidate, $languages, true ) ) {
			return $path;
		}

		// Strip the prefix. If the result is empty (root URL was `/fr/`), keep `/`.
		$stripped = (string) preg_replace( '#^/' . preg_quote( $candidate, '#' ) . '#', '', $path );
		if ( '' === $stripped || '/' !== $stripped[0] ) {
			$stripped = '/' . ltrim( $stripped, '/' );
		}
		return $stripped;
	}

	/**
	 * Detect whether WPML or Polylang is active and configured with languages.
	 */
	private static function is_multilang_active(): bool {
		return ! empty( self::active_languages() );
	}

	/**
	 * Build the list of active language slugs from WPML or Polylang. Cached
	 * per request to avoid repeated plugin-API calls inside the Router hot path.
	 *
	 * @return string[]
	 */
	public static function active_languages(): array {
		if ( null !== self::$languages_cache ) {
			return self::$languages_cache;
		}

		$languages = [];

		// WPML — apply_filters('wpml_active_languages') returns an array keyed
		// by language code, each entry has 'language_code' and 'url' fields.
		if ( has_filter( 'wpml_active_languages' ) || function_exists( 'icl_get_languages' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook, called from outside their plugin
			$wpml = apply_filters( 'wpml_active_languages', null );
			if ( is_array( $wpml ) ) {
				foreach ( $wpml as $lang ) {
					if ( is_array( $lang ) && ! empty( $lang['language_code'] ) ) {
						$languages[] = strtolower( (string) $lang['language_code'] );
					}
				}
			}
		}

		// Polylang — pll_languages_list() returns array of slugs.
		if ( empty( $languages ) && function_exists( 'pll_languages_list' ) ) {
			$pll = pll_languages_list( [ 'fields' => 'slug' ] );
			if ( is_array( $pll ) ) {
				foreach ( $pll as $slug ) {
					$languages[] = strtolower( (string) $slug );
				}
			}
		}

		self::$languages_cache = array_values( array_unique( $languages ) );
		return self::$languages_cache;
	}

	/**
	 * Reset the per-request language cache. Used by tests.
	 */
	public static function reset_cache(): void {
		self::$languages_cache = null;
	}
}
