<?php
/**
 * Bootstrap for unit tests — does NOT load WordPress.
 *
 * For the few WP constants/functions our code touches, we stub them here so
 * pure-logic classes (Stats, Cookie::pick_variant) can be tested in isolation.
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

// Define ABSPATH so plugin files don't exit on load.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WordPress time constants used by Cookie.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'COOKIEPATH' ) ) {
	define( 'COOKIEPATH', '/' );
}
if ( ! defined( 'COOKIE_DOMAIN' ) ) {
	define( 'COOKIE_DOMAIN', '' );
}

if ( ! defined( 'ABTEST_PLUGIN_DIR' ) ) {
	define( 'ABTEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Minimal stubs for the WP functions used by pure-logic paths under test.
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return false;
	}
}

// Composer autoload if available, else fallback.
$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( is_readable( $composer_autoload ) ) {
	require_once $composer_autoload;
} else {
	require_once dirname( __DIR__ ) . '/includes/Autoload.php';
	\Abtest\Autoload::register();
}
