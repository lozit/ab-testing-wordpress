---
description: Audit sécurité ciblé du plugin (handlers admin, REST, file upload, cookies, outbound HTTP, cron)
---

Lance un audit de sécurité complet et **spécifique à ce plugin**. À déclencher avant un tag de release, après une feature qui touche DB / REST / upload, après un merge de PR sensible, ou en routine périodique.

L'audit produit un rapport priorisé (Critical / High / Medium / Low) avec citations `file:line` — pas un essai littéraire.

---

## Étape 0 — outils automatiques d'abord

Lancer dans cet ordre. Si l'un échoue, fixer avant de passer à l'audit manuel (PHPCS WP standard remonte déjà escaping manquant, nonce oublié, sanitization absente — ne pas dupliquer ce travail à la main).

- `composer run lint` — PHPCS ruleset WordPress.
- `composer run test` puis `composer run test:integration` — couvrent les régressions sur consent gate, dedup, state machine, batch stats.
- `composer audit` — CVE sur dépendances composer.
- `gh api repos/lozit/ab-testing-wordpress/dependabot/alerts --jq '.[] | {state, severity, package: .security_advisory.summary}'` — alertes Dependabot ouvertes (ignore les "fixed" / "dismissed").

Si tout passe : continuer. Si quelque chose casse : reporter d'abord les findings outils dans le rapport, fixer, relancer, puis passer à l'étape suivante.

---

## Étape 1 — règles de référence

Charger et appliquer (ne pas dupliquer) :

- `@./.claude/rules/wp-security.md` — SQL prepare, escape output, sanitize input, nonces + capabilities, REST endpoints, cookies, asset loading.
- `@./.claude/rules/wp-conventions.md` — préfixes `abtest_*`, defensive boot `defined('ABSPATH') || exit;`, activation/deactivation hooks.
- `@./.claude/rules/db-migrations.md` — dbDelta strict format, versioning idempotent, uninstall.

---

## Étape 2 — checklist par surface d'attaque

Pour chaque surface : inspecter les fichiers listés, vérifier chaque point, citer la ligne dans le rapport.

### A. Handlers `admin_post_*`
Fichiers : `includes/Admin/Admin.php`, `includes/Admin/HtmlImport.php`, `includes/Admin/Settings.php`. Liste des hooks dans `Admin::register()`.

- Chaque handler appelle `current_user_can('manage_options')` ET `check_admin_referer(<nonce>, <field>)` AVANT toute mutation ?
- Inputs `$_POST` / `$_GET` passés par `wp_unslash()` puis sanitized (`sanitize_text_field`, `absint`, `sanitize_key`, `esc_url_raw`, `wp_kses_post`) ?
- Pas de `wp_die()` qui leak de chemin / SQL en mode debug ?
- Redirects via `wp_safe_redirect()` (pas `wp_redirect`) avec arg validé ?

### B. REST endpoints
Fichiers : `includes/Rest/ConvertController.php`, `includes/Rest/StatsController.php`.

- `permission_callback` défini ET cohérent : `__return_true` pour `convert` (intentionnel — tracking public) ; `current_user_can('manage_options')` pour `stats` ?
- `args` schema avec `validate_callback` + `sanitize_callback` sur chaque paramètre ?
- Réponse ne leake pas : `wp_salt`, secrets webhook, App Passwords, IP brute, hash visiteur d'autrui ?
- Endpoint public `convert` : la dédup par `visitor_hash` empêche-t-elle le flood ? Rate-limit envisagé pour les sites à fort trafic ?

### C. File upload + extraction zip
Fichier : `includes/Admin/HtmlImport.php`.

- Allowlist d'extensions (`.html` / `.htm` / `.zip`) appliquée AVANT lecture ?
- `is_uploaded_file()` vérifié sur le tmp_name ?
- Taille bornée par `max_bytes()` ET capée par `wp_max_upload_size()` ?
- Extraction zip : `../`, paths absolus, dotfiles, `__MACOSX/` rejetés ?
- Allowlist d'extensions des entrées du zip (html/css/js/images/fonts) appliquée ?
- Slug d'extraction passé par `sanitize_title()` ?
- HTML stocké via `wp_slash()` (sinon `wp_insert_post()` mange un niveau d'antislash) ?
- Iframe preview : `sandbox="allow-scripts"` (ou plus restrictif), pas `allow-same-origin` ?

### D. SQL
Fichiers : `includes/Stats.php`, `includes/Tracker.php`, `includes/Experiment.php`, `includes/Schema.php`, et migrations dans `includes/Plugin.php`.

- `grep -n '$wpdb->' includes/` puis pour chaque hit : `$wpdb->prepare()` utilisé avec placeholders (`%d`, `%s`, `%f`) ?
- Les `IN (?)` construits dynamiquement (`implode(',', array_fill(...))`) sont-ils alimentés uniquement par des `(int)` casts ?
- Migrations DB (`Plugin::migrate_to_*`, `Plugin::pre_install_truncate_visitor_hash`) idempotentes (rejouables sans corruption) ?
- Pas d'interpolation directe `{$user_input}` dans une string SQL ?

### E. Cookies + hashing
Fichier : `includes/Cookie.php`.

- Flags du cookie : `httponly=true`, `samesite=Lax`, `secure=is_ssl()`, TTL ≤ 30 jours ?
- `visitor_hash` toujours salé avec `wp_salt('auth')`, jamais d'IP / UA brut stocké ?
- Longueur tronquée à `Cookie::HASH_LENGTH` (16 chars / 64 bits) côté runtime ET côté schema (`CHAR(16)`) ?
- HMAC webhook (`includes/Integrations/Webhook.php`) : doc côté receveur recommande `hash_equals()` (comparaison timing-safe) ?

### F. Outbound HTTP
Fichiers : `includes/Integrations/Webhook.php`, `includes/Integrations/Ga4.php`.

- `wp_remote_post()` avec `timeout` raisonnable (5-10 s) — ne pas bloquer la page user ?
- SSL verify ON par défaut (rechercher `'sslverify' => false` → red flag) ?
- Secrets (API key GA4, secret HMAC webhook) jamais loggés via `error_log` / `WP_DEBUG_LOG` / réponses REST ?
- URLs webhook user-supplied validées avec `esc_url_raw()` + check de protocole `http(s)://` (anti-SSRF basique : refus `file://`, `gopher://`, IP privées si on veut être paranoïaque) ?
- Méthode `Webhook::send()` est `blocking=false` par défaut (sauf "Send test event") pour ne pas figer le hit ?

### G. WP-Cron + filesystem
Fichiers : `includes/Scheduler.php`, `includes/Watcher.php`.

- Watcher scanne uniquement sous `wp_upload_dir()['basedir'] . '/abtest-templates/'` — pas de path traversal via slug ?
- `RecursiveDirectoryIterator` borné par cette racine (pas de symlink follow non controlé) ?
- Hashes SHA-256 utilisés pour dédup (pas MD5/SHA-1) ?
- Cron handlers `tick()` / `scan()` ne font confiance à aucun input externe — tout vient de la DB ou du filesystem qu'on contrôle ?

### H. Direct file access + bootstrap
Fichiers : `includes/*.php`, `ab-testing-wordpress.php`.

- Tous les fichiers PHP commencent par `defined('ABSPATH') || exit;` ?
- Pas de side-effect au require dans le fichier principal (uniquement enregistrement de hooks) ?
- `register_activation_hook` et `register_deactivation_hook` correctement déclarés et idempotents ?

### I. Consent gate (RGPD)
Fichiers : `includes/Consent.php`, `includes/Router.php` (autour du `should_bypass`).

- Quand setting `require_consent` ON ET filtre `abtest_visitor_has_consent` retourne `null` → vraiment **aucun** cookie posé, **aucune** impression loggée, **aucun** script de conversion enqueué ?
- Bypass admin / bot exempté du consent (sinon les previews cassent) ?
- Default OFF préservé ? (pas de breaking change silencieux pour les sites sans bandeau)

---

## Étape 3 — secrets dans le repo

- `git ls-files | xargs grep -lEn '(API_KEY|SECRET|PASSWORD|TOKEN)\s*=' 2>/dev/null` — inspecter chaque hit (les vrais usages doivent venir de `get_option()`, jamais hardcodés).
- Vérifier que `.env`, `wp-tests-config.php` (s'il contient des creds), et fichiers `*.local.php` sont bien dans `.gitignore`.
- `git log --oneline -p -- composer.json composer.lock | head -200` — pas de leak accidentel récent dans la lockfile.

---

## Étape 4 — format du rapport final

Table markdown avec colonnes : `Sévérité | Surface | Fichier:ligne | Constat | Action recommandée`.

Échelle de sévérité :

- **Critical** — SQLi exploitable, RCE, escalation de privilèges, secret en clair commité dans git.
- **High** — XSS stockée, CSRF sans nonce sur action état-modifiante, path traversal sur upload, leak de PII.
- **Medium** — input non sanitizé sans impact direct exploitable, cookie sans flag, timeout HTTP infini, secret loggé en debug.
- **Low** — code mort sensible, message d'erreur trop verbeux, doc privacy manquante, hash faible (MD5) pour usage non crypto.

Conclure par : nombre de findings par sévérité, et un verdict "Go release / Don't release until fix" basé sur la présence de Critical ou High.

---

Aucun warning de sécurité ne doit être ignoré sans justification écrite dans le rapport. En cas de doute → conservateur (refus / sanitize / capability).
