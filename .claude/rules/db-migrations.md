# DB & schema migrations

Applies to: creation/modification of custom tables, activation/deactivation, schema options.

## Creating a table with dbDelta
`dbDelta()` is picky: respect the format strictly or it won't detect diffs.

```php
function abtest_install_schema(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'abtest_events';

    // Non-negotiable dbDelta rules:
    // - 2 spaces after PRIMARY KEY
    // - One key per line
    // - No backticks around column names
    // - Type in UPPERCASE
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        experiment_id BIGINT UNSIGNED NOT NULL,
        variant CHAR(1) NOT NULL,
        event_type VARCHAR(20) NOT NULL,
        visitor_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY exp_var_type (experiment_id, variant, event_type),
        KEY visitor_exp (visitor_hash, experiment_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}
```

## Schema versioning (idempotent)
Store the current version as an option and only run `dbDelta` when it changes. Lets the schema evolve without touching existing data.

```php
const ABTEST_DB_VERSION = '1.0.0';

function abtest_maybe_upgrade(): void {
    $installed = get_option( 'abtest_db_version' );
    if ( $installed === ABTEST_DB_VERSION ) {
        return;
    }
    abtest_install_schema();
    update_option( 'abtest_db_version', ABTEST_DB_VERSION );
}
add_action( 'plugins_loaded', 'abtest_maybe_upgrade' );
```

## Activation hook
- Call `abtest_install_schema()` here too (covers the first install)
- Set default options via `add_option` (not `update_option` — avoids overwriting on reactivation)
- Flush rewrite rules IF we registered a custom CPT: `flush_rewrite_rules()`

```php
register_activation_hook( __FILE__, function () {
    abtest_install_schema();
    add_option( 'abtest_db_version', ABTEST_DB_VERSION );
    add_option( 'abtest_settings', [ 'cookie_days' => 30, 'bypass_admins' => true ] );
    // CPT registered on 'init' → fire init before flush
    \Abtest\Experiment::register();
    flush_rewrite_rules();
} );
```

## Deactivation
Keep the data. Just: `flush_rewrite_rules()`, clear transients/cron.

## Uninstall
In `uninstall.php` at the plugin root (run once on delete):
```php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}abtest_events" );
delete_option( 'abtest_db_version' );
delete_option( 'abtest_settings' );
// Optional: delete_post_meta_by_key + CPT posts
```

## Multisite
If we support multisite later: iterate `get_sites()` + `switch_to_blog()` + apply the schema per site. Not in MVP.

## Destructive migrations
For ALTERs incompatible with dbDelta (rename, drop column) → manual SQL gated by version:
```php
if ( version_compare( $installed, '1.1.0', '<' ) ) {
    $wpdb->query( "ALTER TABLE {$wpdb->prefix}abtest_events DROP COLUMN obsolete_col" );
}
```
