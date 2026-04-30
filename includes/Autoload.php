<?php
/**
 * Minimal PSR-4 autoloader fallback (used when Composer autoload is missing).
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Autoload {

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class_name ): void {
		$prefix = 'Abtest\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		// Defensive : refuse any class name carrying `..` so a (hypothetical) caller
		// passing user-controlled strings to `class_exists()` can't traverse out of
		// `includes/`. PHP itself rejects `..` in class names but spl_autoload_register
		// receives the raw lookup string.
		if ( str_contains( $relative, '..' ) ) {
			return;
		}
		$path = ABTEST_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
