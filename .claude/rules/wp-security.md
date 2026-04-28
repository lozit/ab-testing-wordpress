# Règles sécurité WordPress

S'applique à : tout fichier `.php` du plugin manipulant input utilisateur, DB, ou sortie HTML.

## SQL — toujours préparer
```php
// ❌ JAMAIS
$wpdb->query( "SELECT * FROM $table WHERE id = $user_input" );

// ✅ TOUJOURS
$wpdb->get_results(
  $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}abtest_events WHERE experiment_id = %d", $exp_id )
);
```
Placeholders : `%d` (int), `%f` (float), `%s` (string). Pour `IN (?)` : construire les `%d,%d,%d` dynamiquement.

## Échappement sortie (toujours au dernier moment, à l'output)
| Contexte | Fonction |
|---|---|
| Texte HTML | `esc_html()` / `esc_html__()` |
| Attribut HTML | `esc_attr()` / `esc_attr__()` |
| URL | `esc_url()` |
| Bloc HTML autorisé | `wp_kses_post()` |
| JSON inline JS | `wp_json_encode()` |
| Textarea | `esc_textarea()` |

Exemple admin :
```php
<input type="text" name="abtest_goal" value="<?php echo esc_attr( $goal ); ?>" />
<p><?php echo esc_html__( 'Goal URL', 'ab-testing-wordpress' ); ?></p>
```

## Sanitization input (à l'entrée)
| Type | Fonction |
|---|---|
| Texte single-ligne | `sanitize_text_field()` |
| Email | `sanitize_email()` |
| URL | `esc_url_raw()` (stockage) ou `sanitize_url()` |
| Slug / clé | `sanitize_key()` |
| Int positif | `absint()` |
| HTML autorisé | `wp_kses_post()` |
| Array via `$_POST` | itérer + sanitizer chaque champ |

## Nonces + capabilities (toute action état-modifiante)
```php
// Form admin :
wp_nonce_field( 'abtest_save_experiment', '_abtest_nonce' );

// Handler :
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Forbidden', 'ab-testing-wordpress' ), 403 );
}
check_admin_referer( 'abtest_save_experiment', '_abtest_nonce' );
```

REST API : utiliser `permission_callback` (jamais `__return_true` sauf endpoints publics intentionnels comme conversion tracking — qui doit alors avoir rate-limiting + dédup).

## REST endpoints — checklist
- `permission_callback` défini (même si `__return_true`, c'est explicite)
- `args` schema avec `validate_callback` + `sanitize_callback`
- Pas de retour direct d'objets DB sans filtrage des champs sensibles
- Rate limiting par IP/visitor pour endpoints publics (ex : conversion)

## Capabilities standard
- `manage_options` : admin du plugin (créer/éditer expériences)
- `edit_posts` : si on autorise éditeurs à voir les stats
- Custom cap `manage_abtests` : à envisager v2 pour rôle dédié

## Cookies
- Préfixer `abtest_` (notre namespace)
- `httponly` true par défaut, `secure` si HTTPS
- `samesite=Lax`
- Durée raisonnable (30j max pour assignment)

## Chargement assets
- `wp_enqueue_script`/`_style` UNIQUEMENT — jamais `<script>` direct.
- Versionner avec `filemtime()` ou la version plugin pour cache busting.
- `wp_localize_script` ou `wp_add_inline_script` pour passer données PHP→JS.
