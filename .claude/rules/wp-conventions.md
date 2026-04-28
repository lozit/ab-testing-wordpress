# Conventions WordPress du plugin

S'applique à : tout fichier `.php` du plugin.

## Préfixes (anti-collision globale)
- Fonctions globales : `abtest_xxx()`
- Constantes : `ABTEST_XXX`
- Options : `abtest_xxx`
- Meta keys : `_abtest_xxx` (underscore prefix = caché de l'UI custom-fields)
- Hooks custom : `abtest_xxx` (action) / `abtest_filter_xxx` (filter)
- CSS/JS handles : `abtest-xxx`
- Tables : `{$wpdb->prefix}abtest_xxx`
- Cookies : `abtest_xxx`
- Text domain : `ab-testing-wordpress` (= slug plugin)

## PSR-4 namespace
Namespace racine : `Abtest\` → mappé sur `includes/`.
Sous-namespaces : `Abtest\Admin\`, `Abtest\Rest\`, `Abtest\Stats\`.
Nom de classe = nom de fichier (ex : `Abtest\Router` → `includes/Router.php`).
> Note : on quitte la convention legacy `class-xxx.php` au profit de PSR-4 propre.

## Hooks WordPress
- Actions plugin : `add_action( 'init', [ $this, 'register' ] )`
- Toujours utiliser priority explicite si ordre critique (default 10)
- Préférer méthodes de classe à closures (testable + désinscriptible)

## i18n
- Toute chaîne user-facing : `__( 'Texte', 'ab-testing-wordpress' )`
- Echo direct : `esc_html_e( 'Texte', 'ab-testing-wordpress' )`
- Avec variables : `sprintf( __( '%d conversions', 'ab-testing-wordpress' ), $n )`
- Charger : `load_plugin_textdomain( 'ab-testing-wordpress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' )` au hook `init`

## Headers fichier principal
```php
<?php
/**
 * Plugin Name: AB Testing WordPress
 * Description: A/B testing simple, tracking interne, split cookie 50/50.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Guillaume Ferrari
 * Text Domain: ab-testing-wordpress
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
```

## Defensive boot
Tout fichier PHP commence par `defined( 'ABSPATH' ) || exit;` pour empêcher l'accès direct.

## Activation / désactivation / uninstall
- `register_activation_hook( __FILE__, [ Abtest\Plugin::class, 'activate' ] )` — création tables, options par défaut.
- `register_deactivation_hook( __FILE__, [ Abtest\Plugin::class, 'deactivate' ] )` — clean transients, garder les données.
- `uninstall.php` à la racine — drop tables, delete options (uniquement si désinstallation).

## Pas de side-effect au require
Le fichier principal enregistre des hooks, ne fait RIEN au load (évite de casser l'activation d'autres plugins).
