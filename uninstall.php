<?php
/**
 * Uninstall handler — runs once when the plugin is deleted from wp-admin.
 *
 * @package Abtest
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}abtest_events" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'abtest_db_version' );
delete_option( 'abtest_settings' );

$abtest_experiments = get_posts(
	[
		'post_type'      => 'ab_experiment',
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
	]
);
foreach ( $abtest_experiments as $abtest_experiment_id ) {
	wp_delete_post( (int) $abtest_experiment_id, true );
}
