# Migrations DB & schéma

S'applique à : création/modification de tables custom, activation/désactivation, options de schéma.

## Création de table avec dbDelta
`dbDelta()` est tatillon : respecter strictement le format ou il ne détecte pas les diffs.

```php
function abtest_install_schema(): void {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table   = $wpdb->prefix . 'abtest_events';

    // Règles dbDelta non négociables :
    // - 2 espaces après PRIMARY KEY
    // - Une seule clé par ligne
    // - Pas de backticks autour des noms de colonnes
    // - Type en MAJUSCULES
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

## Versioning du schéma (idempotent)
Stocker la version courante en option et n'exécuter `dbDelta` que si elle change. Permet d'évoluer le schéma sans toucher aux données existantes.

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
- Appel de `abtest_install_schema()` ici aussi (couvre l'install initial)
- Set options par défaut via `add_option` (pas `update_option` — évite d'écraser une réactivation)
- Flush rewrite rules SI on a enregistré CPT custom : `flush_rewrite_rules()`

```php
register_activation_hook( __FILE__, function () {
    abtest_install_schema();
    add_option( 'abtest_db_version', ABTEST_DB_VERSION );
    add_option( 'abtest_settings', [ 'cookie_days' => 30, 'bypass_admins' => true ] );
    // CPT enregistré dans 'init' → fire init avant flush
    \Abtest\Experiment::register();
    flush_rewrite_rules();
} );
```

## Désactivation
Garder les données. Juste : `flush_rewrite_rules()`, clear transients/cron.

## Uninstall
Dans `uninstall.php` à la racine du plugin (exécuté une seule fois à la suppression) :
```php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}abtest_events" );
delete_option( 'abtest_db_version' );
delete_option( 'abtest_settings' );
// Optionnel : delete_post_meta_by_key + posts du CPT
```

## Multisite
Si on supporte multisite plus tard : itérer `get_sites()` + `switch_to_blog()` + appliquer schéma par site. Pas en MVP.

## Migrations destructives
Pour ALTER incompatibles avec dbDelta (renommage, drop colonne) → SQL manuel conditionné par version :
```php
if ( version_compare( $installed, '1.1.0', '<' ) ) {
    $wpdb->query( "ALTER TABLE {$wpdb->prefix}abtest_events DROP COLUMN obsolete_col" );
}
```
