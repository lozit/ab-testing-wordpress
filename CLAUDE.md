# AB Testing WordPress Plugin

Plugin WordPress standalone pour faire de l'A/B testing de pages/articles entiers, tracking interne (DB), split 50/50 par cookie persistant.

## Stack
- WordPress 6.x, PHP 8.1+
- Composer + autoload PSR-4 (namespace `Abtest\`)
- PHPUnit (WP test suite) + PHPCS (ruleset WordPress)
- Pas de build JS (vanilla)

## Commandes essentielles
- `composer install` — install deps
- `composer run test` ou `./vendor/bin/phpunit` — lancer les tests
- `composer run lint` ou `./vendor/bin/phpcs` — vérifier le code
- `composer run lint:fix` ou `./vendor/bin/phpcbf` — auto-fix
- Env local : **wp-env** (officiel WordPress, Docker)
  - `npx wp-env start` — démarre le WP local (port 8888 par défaut)
  - `npx wp-env stop` — arrête
  - `npx wp-env clean all` — reset complet (DB + uploads)
  - `npx wp-env run cli wp <cmd>` — wp-cli dans le container
  - `npx wp-env run tests-cli wp <cmd>` — wp-cli sur l'instance de tests
  - Config dans `.wp-env.json` (à créer : monte le plugin local + WP version cible)

## Structure (au fil du dev)
```
ab-testing-wordpress.php   # bootstrap
includes/                  # classes plugin (PSR-4)
includes/admin/            # UI wp-admin
assets/js/                 # tracker.js frontend
tests/                     # PHPUnit
```

## Règles importantes (lazy-loadées)
- Code PHP touchant la DB ou input utilisateur → **lire** `@./.claude/rules/wp-security.md`
- Nouveau hook / nouveau nom / i18n → **lire** `@./.claude/rules/wp-conventions.md`
- Modif schéma DB / activation hook → **lire** `@./.claude/rules/db-migrations.md`

## Workflow
1. **Plan mode par défaut** pour toute tâche non-triviale (3+ étapes ou décision archi).
2. Subagents pour l'exploration parallèle, garder le contexte principal propre.
3. Après correction utilisateur → mettre à jour `tasks/lessons.md`.
4. Marquer une tâche complète UNIQUEMENT après preuve qu'elle marche (test vert + démo).
5. Simplicité d'abord — pas de couche d'abstraction "au cas où". Trois lignes similaires valent mieux qu'une mauvaise abstraction.

## Tracking
- `tasks/todo.md` — tâches en cours / faites
- `tasks/lessons.md` — leçons apprises des corrections utilisateur

<important if="touching DB writes, $_POST/$_GET, REST endpoints, or admin forms">
SÉCURITÉ NON-NÉGOCIABLE :
- Toute requête SQL custom passe par `$wpdb->prepare()` — jamais d'interpolation directe.
- Toute sortie HTML est échappée (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Toute action état-modifiante vérifie un nonce (`check_admin_referer`, `wp_verify_nonce`) ET une capability (`current_user_can`).
- Tout input est sanitizé (`sanitize_text_field`, `absint`, `sanitize_key`, etc.).
Voir `@./.claude/rules/wp-security.md` pour les patterns détaillés.
</important>
