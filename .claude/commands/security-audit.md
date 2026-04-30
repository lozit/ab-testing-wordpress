---
description: Audit sécurité complet — situé (surfaces du plugin) + grille OWASP + rapport persisté + todo auto-mis-à-jour
---

Lance un audit de sécurité complet et **spécifique à ce plugin**. À déclencher avant un tag de release, après une feature qui touche DB / REST / upload, après un merge de PR sensible, ou en routine périodique.

L'audit produit :
- un **rapport persisté** dans `docs/security/audit-YYYY-MM-DD-vX.Y.Z.md` (archive) + `docs/security/latest.md` (overwrite),
- une **mise à jour automatique** de `tasks/todo.md` (section "Sécurité — backlog audit"),
- un **résumé chat** avec score 1-10, verdict Go/No-Go, et top 3 urgences.

---

## Étape 0 — outils automatiques d'abord

Lancer dans cet ordre. Si l'un échoue, fixer avant de passer à l'audit manuel (PHPCS WP standard remonte déjà escaping manquant, nonce oublié, sanitization absente — ne pas dupliquer ce travail à la main).

- `composer run lint` — PHPCS ruleset WordPress.
- `composer run test` puis `composer run test:integration` — couvrent les régressions sur consent gate, dedup, state machine, batch stats.
- `composer audit` — CVE sur dépendances composer.
- `gh api repos/lozit/ab-testing-wordpress/dependabot/alerts --jq '.[] | select(.state == "open") | {state, severity, package: .security_advisory.summary}'` — alertes Dependabot ouvertes (ignore les "fixed" / "dismissed"). Ignorer si Dependabot est désactivé sur le repo.

Si tout passe : continuer. Si quelque chose casse : reporter d'abord les findings outils dans le rapport, fixer, relancer, puis passer à l'étape suivante.

---

## Étape 1 — règles de référence

Charger et appliquer (ne pas dupliquer) :

- `@./.claude/rules/wp-security.md` — SQL prepare, escape output, sanitize input, nonces + capabilities, REST endpoints, cookies, asset loading.
- `@./.claude/rules/wp-conventions.md` — préfixes `abtest_*`, defensive boot `defined('ABSPATH') || exit;`, activation/deactivation hooks.
- `@./.claude/rules/db-migrations.md` — dbDelta strict format, versioning idempotent, uninstall.

---

## Étape 2 — checklist par surface du plugin (situé)

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
- Vérification MIME via `wp_check_filetype_and_ext()` (pas seulement l'extension du nom) ?
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
- `'sslverify' => true` explicite (pas confiance au défaut WP — un filtre tiers peut le désactiver) ?
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

## Étape 2bis — grille OWASP (transverse)

Compléter l'inspection situé par une passe attaquant-orientée. Pour chaque catégorie, grep + lecture, puis classer chaque finding réel selon la sévérité standard ci-dessous.

### 🔴 Critical
- **SQL Injections** : `$wpdb->query` / `get_results` / `get_row` / `get_var` contenant une variable sans `prepare()`, ou `prepare()` avec `%s` autour d'un int.
- **Dynamic File Inclusion** : `include` / `require` avec une variable issue de `$_GET` / `$_POST` / `$_SERVER`. Vérifier aussi que les autoloaders refusent `..` dans les noms de classe.
- **Arbitrary Code Execution** : `eval`, `system`, `exec`, `shell_exec`, `passthru`, `popen`, `proc_open`, `assert`, `call_user_func($_GET[...])`.
- **Secrets en clair commitées** : `git ls-files | xargs grep -lEn '(API_KEY|SECRET|PASSWORD|TOKEN|PRIVATE_KEY|sk_|pk_|AIza|AKIA)\s*='`. Patterns clés vendor (Stripe, AWS, Google, Slack).

### 🟠 High
- **XSS** : tout `echo` / `print` / `_e` / `printf` avec une variable non échappée selon le contexte (`esc_html`, `esc_attr`, `esc_url`, `esc_js`, `wp_kses_post`).
- **CSRF** : forms sans `wp_nonce_field()` + `check_admin_referer()` ; AJAX sans `wp_verify_nonce()` ; actions état-modifiantes via GET sans nonce.
- **Access Control** : menus admin sans `current_user_can()` ; `wp_ajax_nopriv_*` qui exécute du sensible ; REST sans `permission_callback` ou avec `__return_true` sur action sensitive ; fichier PHP sans `defined('ABSPATH') || exit;`.

### 🟡 Medium
- **Input Sanitization** : `$_GET` / `$_POST` écrits en DB sans `sanitize_text_field` / `sanitize_email` / `absint` / `esc_url_raw` / `wp_kses_post` ; `update_option` / `update_post_meta` sans sanitize.
- **File Uploads** : pas de vérification MIME réelle (`wp_check_filetype_and_ext`), destination publique sans `.htaccess` PHP-block, taille non bornée.
- **Sensitive Information Disclosure** : messages d'erreur qui leak chemins / SQL / version PHP, fichiers `debug.log` dans le plugin, secrets dans réponses REST.
- **Headers / Configuration** : pas de `defined('ABSPATH') || exit;` (déjà dans High mais à recroiser), absence de headers no-store sur pages cachables.

### 🔵 Low
- Nonces avec scope trop large ou TTL trop long, queries SQL sans `LIMIT` qui peuvent renvoyer 100k rows, absence de rate-limit sur endpoints publics, transients qui stockent du PII.

---

## Étape 3 — secrets + git hygiène

- `git ls-files | xargs grep -lEn '(API_KEY|SECRET|PASSWORD|TOKEN|PRIVATE_KEY)\s*=' 2>/dev/null` — inspecter chaque hit (les vrais usages doivent venir de `get_option()`, jamais hardcodés).
- Vérifier que `.env`, `wp-tests-config.php` (s'il contient des creds), `*.local.php`, `*.key`, `*.pem` sont bien dans `.gitignore`.
- `git log --oneline -p -- composer.json composer.lock | head -200` — pas de leak accidentel récent dans la lockfile.

---

## Étape 4 — composer le rapport

Format de chaque finding (issu de la grille OWASP) :

```markdown
**[SEVERITY] Issue Title**
- File: `relative/path/to/file.php`
- Line: NN
- Surface: <A-I lettre + nom>
- Problematic code: `[code excerpt 1-3 lignes]`
- Risk: [description concrète du risque]
- Fix: [corrected code ou approche recommandée]
```

Conclure par :
1. **Compteur par sévérité** (table 4 lignes)
2. **Top 3 urgences** à fixer en premier
3. **Score global 1-10** (10 = irréprochable, 8+ = production-ready, < 5 = ne pas releaser)
4. **Verdict** : Go release / Don't release until fix (basé sur présence de Critical ou High)

---

## Étape 5 — persister le rapport sur disque

Récupérer la version courante :
```bash
grep "ABTEST_VERSION" ab-testing-wordpress.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+"
```

Écrire le rapport dans **DEUX** fichiers :

1. `docs/security/audit-YYYY-MM-DD-vX.Y.Z.md` — archive immutable. Si le fichier existe déjà (audit le même jour sur la même version), suffixer `-2`, `-3`, etc.
2. `docs/security/latest.md` — copie identique, overwrite. C'est ce fichier qui est linké depuis `README.md` et `SECURITY.md`.

Créer le dossier `docs/security/` s'il n'existe pas. Le dossier est exclu du `.zip` distribué via `--exclude='docs'` dans `release.yml`.

---

## Étape 6 — synchroniser `tasks/todo.md`

Localiser (ou créer si absente) la section `### Sécurité — backlog audit (auto-géré)` dans `tasks/todo.md`.

Pour chaque finding **Critical / High / Medium** du nouveau rapport :
- S'il n'existe pas déjà dans la section (matcher par `file:line` + sévérité) → ajouter au format :
  ```
  - [ ] [SÉV] Surface — `file.php:NN` — action recommandée (audit YYYY-MM-DD)
  ```
- S'il existe déjà → ne rien faire (pas de duplication).

Pour chaque item **non-coché** déjà présent qui n'apparaît PLUS dans le nouveau rapport (= fixé entre 2 audits) → cocher `[x]` et ajouter `(fixed YYYY-MM-DD)`.

Les findings **Low** restent dans le rapport mais **ne polluent pas** `tasks/todo.md`.

---

## Étape 7 — résumé chat

Imprimer en sortie :
- Score global X/10 + verdict Go/No-Go.
- Compteur par sévérité.
- Top 3 urgences avec leur file:line.
- Lien vers le rapport persisté (`docs/security/latest.md`).
- Diff du `tasks/todo.md` (combien de items ajoutés, combien cochés comme fixés).

---

Aucun warning de sécurité ne doit être ignoré sans justification écrite dans le rapport. En cas de doute → conservateur (refus / sanitize / capability).
