<?php
/**
 * WordPress test suite config — discovered automatically by wp-phpunit's bootstrap.
 *
 * Defaults match @wordpress/env's tests-cli container conventions; overridable via
 * environment variables for CI runners.
 *
 * @package Abtest
 */

define( 'DB_NAME',     getenv( 'WORDPRESS_DB_NAME' )     ?: 'tests-wordpress' );
define( 'DB_USER',     getenv( 'WORDPRESS_DB_USER' )     ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WORDPRESS_DB_PASSWORD' ) ?: 'password' );
define( 'DB_HOST',     getenv( 'WORDPRESS_DB_HOST' )     ?: 'tests-mysql' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

// wp-phpunit needs this so it can run install.php as a sub-process.
define( 'ABSPATH', getenv( 'WP_TESTS_ABSPATH' ) ?: '/var/www/html/' );

define( 'WP_TESTS_DOMAIN',       'example.org' );
define( 'WP_TESTS_EMAIL',        'admin@example.org' );
define( 'WP_TESTS_TITLE',        'AB Testing WP — integration tests' );
define( 'WP_PHP_BINARY',         'php' );
define( 'WP_DEFAULT_THEME',      'default' );
define( 'WP_DEBUG',              true );
