# Lessons learned

Patterns appris des corrections utilisateur ou des bugs rencontrés. Relire au début de chaque session.

## Format
```
## YYYY-MM-DD — [Catégorie] Titre court
**Contexte** : ce que je faisais
**Erreur** : ce que j'ai fait de mal
**Correction** : ce qui marche
**Règle pour moi** : pattern à appliquer désormais
```

---

## 2026-04-29 — [Hosting / Cache] Kinsta = double cache (nginx + Cloudflare edge), bypass via `Cache-Control` + Cache Bypass UI

**Contexte** : Identifier comment l'A/B testing peut survivre à un environnement Kinsta (qui combine un cache nginx au niveau serveur et un cache Cloudflare Enterprise au niveau edge).

**Comportement Kinsta** :
- Le cache nginx Kinsta respecte les headers HTTP `Cache-Control: no-store, no-cache, private, max-age=0`.
- L'edge Cloudflare aussi (Cache-Control est propagé depuis l'origine).
- Le header `X-Kinsta-Cache` côté response indique l'état : `HIT` (servi du cache), `MISS` (généré, mis en cache), `BYPASS` (jamais en cache).
- Détection plugin-side : constante `KINSTA_CACHE_ZONE` ou `KINSTAMU_VERSION`, ou présence du dossier `wp-content/mu-plugins/kinsta-mu-plugins`.
- Cache Bypass UI dans MyKinsta → Tools → Cache permet d'exclure des URL Patterns en regex.

**Stratégie d'A/B test sur Kinsta** :
1. Code-side : envoyer `nocache_headers()` + `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` depuis le Router sur chaque page d'A/B test (avant tout output, donc dès `parse_request`).
2. Belt-and-suspenders : l'utilisateur ajoute les URLs de test dans MyKinsta Cache Bypass.
3. Au lancement d'un nouveau test, purger le cache Kinsta (manuel via MyKinsta UI, ou via leur API REST si on automatise).

**Règle pour moi** : Pour tout site sous CDN/edge cache, **ne jamais compter sur les filters d'un cache plugin local seulement** — toujours envoyer aussi les headers `Cache-Control: no-store` côté response, c'est l'API universelle. Les filtres plugin (rocket, litespeed) ne fonctionnent que pour leur cache à eux ; les CDNs externes ne les voient pas.

---

## 2026-04-28 — [WordPress / Slashing] `wp_insert_post`/`wp_update_post` mangent un niveau d'antislash via `wp_unslash()`

**Contexte** : Feature d'import HTML vers une page WP. Le user a uploadé un fichier `.html` contenant un bundler JS qui fait `JSON.parse()` sur un payload inline avec des séquences échappées (`/`, `\n`, `\t`, `\"`).

**Erreur** : Le bundler crashait avec "JSON.parse: unexpected non-whitespace character at line 2 column 30". Inspection du contenu stocké : `<u002Fscript>` au lieu de `</script>`, `n<script>` au lieu de `\n<script>`. Tous les antislashes du fichier original avaient disparu en DB.

**Cause** : `wp_insert_post()` et `wp_update_post()` appellent **`wp_unslash()`** sur leurs entrées (parce qu'ils s'attendent à recevoir des données provenant de `$_POST`, qui sont automatiquement slashées par WP via magic_quotes-like). Notre contenu vient de `file_get_contents()` (pas de slashes ajoutés), donc `wp_unslash()` strippe les **vrais** backslashes du contenu.

**Correction** : Pré-slasher avec `wp_slash()` toutes les valeurs scalaires passées à `wp_insert_post`/`wp_update_post` quand la source n'est pas `$_POST` :
```php
wp_insert_post([
    'post_title'   => wp_slash( $title ),
    'post_content' => wp_slash( $html ),
])
```

**Règle pour moi** : **TOUJOURS `wp_slash()`** avant `wp_insert_post`, `wp_update_post`, `update_post_meta`, `update_option` quand la valeur ne vient PAS de `$_POST`/`$_GET` (qui sont déjà slashés par WP). Source : [Codex](https://developer.wordpress.org/reference/functions/wp_insert_post/) — "All inputs will be passed through `wp_unslash()`". Bug invisible avec du contenu texte normal, dévastateur sur du JSON, du regex, du Babel/JSX, ou tout code source contenant des `\`.

---

## 2026-04-28 — [WordPress / Private posts] Servir une page `private` à un visiteur non-loggé

**Contexte** : Refactor du Router pour intercepter une URL custom et la résoudre vers une page `private` (cachée du public direct).

**Erreur** : `parse_request` réécrivait bien `query_vars['page_id']` vers la variante private, mais WP renvoyait quand même 404 — `WP_Query` filtre les statuts non-publics quand `current_user_can` ne couvre pas `read_private_pages`.

**Correction** : Combiner deux hooks dans `Router::maybe_route()` :
1. `pre_get_posts` priority 1 sur la main query → `$query->set('post_status', ['publish', 'private'])` + `$query->is_404 = false`
2. `posts_results` filet de sécurité → si retour vide ET on attendait notre variant, injecter `$variant_post` dans le tableau de résultats et reset `is_singular`/`is_page`/`queried_object`

**Règle pour moi** : Quand on hijack une URL pour servir un contenu non-public, la réécriture de `query_vars` ne suffit pas. WP filtre les statuts en frontend. Solution standard : `pre_get_posts` pour autoriser le statut + `posts_results` comme garde-fou si la query revient vide. Ne JAMAIS désactiver les checks de capabilities globalement (risque sécu).

---

## 2026-04-28 — [WordPress / wp-env] Pretty permalinks nécessitent un reload Apache après activation

**Contexte** : `wp option update permalink_structure '/%postname%/'` + `wp rewrite flush --hard` ne suffisaient pas. Toutes les URLs avec slug retournaient 404.

**Erreur** : J'ai cherché un bug dans le router, alors que c'était l'environnement. `apache_mod_loaded('mod_rewrite')` retournait `false` même après `a2enmod rewrite` (qui répondait "already enabled").

**Correction** : `npx wp-env run wordpress service apache2 reload` — le module est listé enabled au niveau Apache, mais pas chargé dans le runtime PHP-Apache tant qu'on n'a pas reload.

**Règle pour moi** : Avant de chercher un bug code quand toutes les URLs retournent 404 sur wp-env, vérifier `apache_mod_loaded('mod_rewrite')`. Si false, `wp-env run wordpress service apache2 reload`. Idéalement à automatiser via un mapping `.wp-env.json` qui pose un .conf custom — mais reload manuel est la solution la plus rapide en dev.

---

## 2026-04-28 — [WordPress / Auto-recovery] WP désactive silencieusement un plugin qui fatal pendant le load

**Contexte** : Après avoir corrigé un fatal (CPT capabilities cassées), j'ai testé via curl + browser. Le menu n'apparaissait pas. J'ai cherché dans le code — tout semblait OK.

**Erreur** : J'ai cherché dans le code pendant 20 minutes. La vraie cause : `get_option('active_plugins')` retournait `[]` — le plugin avait été **auto-désactivé par WordPress** lors d'un de ses fatals précédents. `wp plugin list` continuait de l'afficher comme "active" (incohérence entre l'option et l'API), trompeur. L'utilisateur de son côté voyait "Settings réapparaît quand je désactive le plugin" → c'était le symptôme : son plugin alternait entre "actif (et fatale)" et "auto-désactivé".

**Correction** : `wp plugin activate AB-testing-wordpress` après avoir corrigé la cause racine.

**Règle pour moi** : Quand un changement de comportement bizarre apparaît (menu manquant, hook pas branché, classes pas chargées), **toujours vérifier d'abord `get_option('active_plugins')`** — pas `wp plugin list`. WP a un mécanisme d'auto-recovery qui désactive silencieusement les plugins qui jettent un fatal pendant le load admin (depuis WP 5.2 — fatal error protection / recovery mode). C'est un piège classique : on corrige le bug, on teste, ça marche pas, on cherche un autre bug — alors qu'il faut juste réactiver le plugin.

Aussi : `wp plugin list` peut mentir. Source de vérité = l'option `active_plugins`.

---

## 2026-04-28 — [WordPress / Hook timing] `register_post_type` doit être appelé sur `init`, jamais avant

**Contexte** : `Plugin::boot()` est branché sur `plugins_loaded`, et appelait `Experiment::register()` directement, ce qui exécutait `register_post_type('ab_experiment', ...)` immédiatement.

**Erreur** : Fatal sur **toutes** les pages admin (login compris) :
```
Call to a member function add_rewrite_tag() on null in wp-includes/rewrite.php:176
register_post_type → WP_Post_Type->add_rewrite_rules → add_rewrite_tag → $wp_rewrite->add_rewrite_tag
```
La global `$wp_rewrite` est instanciée par WP sur `init` priority 0. Avant `init`, elle est `null`, donc tout `register_post_type` qui touche aux rewrite rules (cas par défaut) plante.

**Correction** : `Plugin::register_components()` ne fait plus l'appel direct, il branche sur `init` :
```php
add_action( 'init', [ Experiment::class, 'register' ] );
```

**Règle pour moi** : Toute fonction WP qui touche au routing/rewrite/query (`register_post_type`, `register_taxonomy`, `add_rewrite_rule`, etc.) doit être appelée **sur `init`** au plus tôt. `plugins_loaded` est trop tôt — réservé à : charger le textdomain, instancier les classes, brancher les hooks. Pas de side-effects sur l'état WP.

Bonus : ce bug ne s'était pas vu via wp-cli (qui boote différemment) ni via mes tests curl front-end (qui ne déclenchent pas la même séquence d'init). Première vraie navigation admin → fatal. **Toujours tester via wp-admin dans un browser**, pas seulement front + cli.

---

## 2026-04-28 — [WordPress / Block Themes] `the_post` action n'est pas appelé par les block themes

**Contexte** : Implémentation du content swap dans `Router::maybe_route()`. J'ai utilisé `add_action('the_post', ...)` pour intercepter chaque post au moment où WordPress l'itère dans la boucle, et y appliquer le swap vers le variant.

**Erreur** : Test e2e sur Twenty Twenty-Four (block theme par défaut depuis WP 6.4) → cookie correctement posé sur "B", mais le rendu affichait toujours le contenu de A. Bug invisible avec un thème classique mais bloquant avec n'importe quel block theme.

**Correction** : Les block themes ne lancent pas `while(have_posts()) { the_post(); }`. Ils utilisent `WP_Block_Template` qui rend des blocs (`core/post-content`, `core/post-title`) lisant directement `global $post` et `$wp_query->queried_object`. Le hook `the_post` ne fire jamais.

Solution : muter en place les objets globaux dans `template_redirect` priority 1 :
- `$wp_query->post`
- `$wp_query->posts[0]`
- `$wp_query->queried_object`
- `$post` (global)

Voir `Router::swap_to_variant()` (commit fixant ce bug).

**Règle pour moi** : Pour tout filtre/action sur le contenu d'une page singulière, **toujours tester sur un block theme**, pas seulement un thème classique. Les hooks de la boucle (`the_post`, `loop_start`, `loop_end`) sont obsolètes pour les rendus block-based — privilégier la mutation directe des objets, ou les filtres `the_content` / `render_block` qui restent universels.
