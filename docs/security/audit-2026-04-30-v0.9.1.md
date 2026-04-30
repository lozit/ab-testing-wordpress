# Security Audit Report — `ab-testing-wordpress` v0.9.1

**Date** : 2026-04-30
**Commit** : `5eff481`
**Branch** : `main`
**Auditor** : `/security-audit` slash command (Claude Opus 4.7)

This is the consolidated report from the first two audit runs (v0.9.0 and post-v0.9.1) collapsed into one — quick wins applied in v0.9.1 are reflected as fixed and removed from the open findings list.

---

## Étape 0 — automated tooling

| Tool | Result |
| ---- | ------ |
| `composer run lint` (PHPCS WP) | ❌ exit 2 — 1083 findings, of which **22 are in Security/PreparedSQL category**. All 22 are false positives on plugin-controlled string interpolation (table names from `Schema::events_table()`, dynamically-built `IN (%d,%d,...)` placeholders fed by `(int)` casts). All already annotated with `// phpcs:ignore` at call site. The remaining 1061 are cosmetic (short array syntax `[]`, missing docblocks, alignment). |
| `composer run test` | ✅ 82/82 unit tests, 467 assertions |
| `composer run test:integration` | ✅ 15/15 integration tests, 51 assertions |
| `composer audit` | ✅ No security vulnerability advisories found |
| Dependabot alerts | ⚠️ Disabled on the repo (HTTP 403 — see Low finding below) |

---

## 🔴 Critical

**Aucune vulnérabilité Critical identifiée.**

- **SQL Injections** : tous les `$wpdb->` (Stats.php, Tracker.php, Experiment.php, Schema.php, Plugin.php migrations, Watcher) utilisent `$wpdb->prepare()` avec placeholders typés (`%d`, `%s`).
- **Dynamic File Inclusion** : 2 `require_once` dynamiques (Autoload PSR-4 + `vendor/autoload.php`), tous deux plugin-controlled. Aucun `$_GET` / `$_POST` / `$_SERVER` n'atteint un `include`/`require`.
- **Arbitrary Code Execution** : `eval` / `system` / `exec` / `passthru` / `shell_exec` / `popen` / `proc_open` / `assert` / `call_user_func($_GET[...])` → **zéro hit** dans tout le plugin.
- **Secrets en clair commités** : `grep API_KEY|SECRET|PASSWORD|TOKEN` sur `git ls-files` (hors vendor/composer.lock/docs/CLAUDE.md/.claude) → zéro hit.

---

## 🟠 High

**Aucune vulnérabilité High identifiée.**

- **XSS** : tous les outputs HTML passent par `esc_html()`, `esc_attr()`, `esc_url()`, `esc_js()`. Les 4 `onclick=` trouvés (`ExperimentsList.php:257, 268, 273` + `Settings.php:140`) sont soit statiques, soit échappés par `esc_js()`.
- **CSRF** : 10 occurrences de `check_admin_referer()` + 15 de `current_user_can()`. Chaque handler `admin_post_*` (9 hooks) a les deux AVANT toute mutation. Vérifié sur tous : `handle_save:172-175`, `handle_status_change:344-347`, `handle_resume:419-422`, `handle_replace_running:470-473`, `handle_delete:523-526`, `HtmlImport::handle_upload:208-211`, `HtmlImport::handle_scan_now:172-175`, `Settings::handle_save`, `Settings::handle_test_webhook`.
- **Access Control** : `ConvertController::permission_callback = '__return_true'` est intentionnel (endpoint public de tracking, doc OK) ; `StatsController::permission_callback = [check_permission]` requiert `manage_options`. Pas de `wp_ajax_nopriv_*`. Tous les fichiers ont `defined('ABSPATH') || exit;`.

---

## 🟡 Medium

### [MEDIUM] File upload : pas de vérification MIME réelle
- **File** : `includes/Admin/HtmlImport.php`
- **Line** : 240-251
- **Surface** : C — File upload + extraction zip
- **Problematic code** :
  ```php
  $ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
  if ( ! in_array( $ext, self::ALLOWED_EXTS, true ) ) {
      self::redirect_error( ... );
  }
  ```
- **Risk** : seule l'extension du nom est vérifiée. Un attaquant `manage_options` peut uploader un `.php` renommé en `.html` — fichier PHP exécutable atterrit dans `wp-content/uploads/abtest-templates/{slug}/`. Mitigé par : (a) capability admin requise, (b) `.htaccess` WP par défaut bloque l'exécution PHP sous `/wp-content/uploads/`, (c) iframe preview sandboxée. Mais le `.htaccess` n'est PAS toujours en place (Nginx, certains hosts).
- **Fix** :
  ```php
  $checked = wp_check_filetype_and_ext( $tmp_name, $name, [
      'html' => 'text/html',
      'htm'  => 'text/html',
      'zip'  => 'application/zip',
  ] );
  if ( ! $checked['ext'] || ! $checked['type'] ) {
      self::redirect_error( __( 'File MIME type does not match its extension.', 'ab-testing-wordpress' ) );
  }
  ```

### [MEDIUM] Webhook URL accepte des protocoles non-HTTP
- **File** : `includes/Integrations/Webhook.php`
- **Line** : 73 (`set_all`)
- **Surface** : F — Outbound HTTP
- **Problematic code** :
  ```php
  $url = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
  ```
- **Risk** : `esc_url_raw()` accepte par défaut `http`, `https`, `ftp`, `ftps`, `gopher`, `mailto`, `sms`, `webcal`, `xmpp`, … Un admin compromis (ou en interne malveillant) pourrait rentrer `gopher://internal-service:11211/...` pour pivoter vers des services internes (memcached, redis, xmlrpc) → SSRF basique. Mitigé par capability admin.
- **Fix** : forcer http(s) seulement.
  ```php
  $url = isset( $entry['url'] ) ? esc_url_raw( (string) $entry['url'] ) : '';
  if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
      continue; // Reject non-HTTP(S) schemes (anti-SSRF basic).
  }
  ```

---

## 🔵 Low

### [LOW] Autoload PSR-4 ne rejette pas `..` dans le nom de classe
- **File** : `includes/Autoload.php`
- **Line** : 26-30
- **Surface** : H — Direct file access + bootstrap
- **Problematic code** :
  ```php
  $relative = substr( $class, strlen( $prefix ) );
  $path     = ABTEST_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
  if ( is_readable( $path ) ) {
      require_once $path;
  }
  ```
- **Risk** : si jamais un autre code du site fait `class_exists($user_controlled)` avec un namespace `Abtest\`, le `str_replace('\\', '/')` convertirait `Abtest\..\..\evil` en chemin traversal. Risque actuel : nul (le plugin n'expose aucun `class_exists($user_input)`). Hardening 1-ligne.
- **Fix** :
  ```php
  if ( str_contains( $relative, '..' ) ) {
      return;
  }
  ```

### [LOW] REST `/abtest/v1/convert` sans rate-limit IP
- **File** : `includes/Rest/ConvertController.php`
- **Line** : 33-50
- **Surface** : B — REST endpoints
- **Problematic code** : endpoint public, dédup par `visitor_hash` empêche le double-count d'un même visiteur, MAIS un attaquant avec N IPs peut flooder pour biaiser les stats d'une variante.
- **Risk** : pollution stats / fausse significance statistique. Pas un vol, pas une exécution.
- **Fix** : transient bucket par IP.
  ```php
  $ip_hash = wp_hash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
  $key     = 'abtest_rate_' . $ip_hash;
  $count   = (int) get_transient( $key );
  if ( $count >= 60 ) { // 60 hits / minute
      return new \WP_REST_Response( [ 'logged' => false, 'reason' => 'rate_limited' ], 429 );
  }
  set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
  ```

### [LOW] Secret HMAC webhook stocké en clair dans `wp_options`
- **File** : `includes/Integrations/Webhook.php`
- **Line** : 80 (Webhook::set_all)
- **Surface** : E — Cookies + hashing (par extension : secrets)
- **Problematic code** : `$clean[] = [ ..., 'secret' => (string) $entry['secret'], ... ]` puis `update_option(self::OPTION_KEY, $clean)`.
- **Risk** : tout admin avec accès lecture aux options (donc tout admin) peut lire le secret HMAC. Standard WP, pas chiffrable sans key management externe. Limite par modèle de menace WP (admin = trusted).
- **Fix** : documenter dans README "secret stored in DB, accessible to any admin" — c'est le modèle WP. Solutions plus robustes (chiffrement at-rest via vault externe) hors-scope plugin.

### [LOW] PHPCS warning `WordPress.WP.CronInterval` sur Watcher (5 min < 15 min seuil)
- **File** : `includes/Watcher.php`
- **Line** : 36
- **Surface** : G — WP-Cron + filesystem
- **Risk** : aucun risque sécurité — choix de design (sync IDE rapide). PHPCS le remonte parce qu'un cron trop fréquent peut peser sur les sites à fort trafic. Le hook ne fait que des opérations DB légères bornées.
- **Fix** : annotation explicite.
  ```php
  // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- intentional 5-min for IDE-sync UX
  ```

### [LOW] PHPCS warnings `file_get_contents() is discouraged` (faux positifs)
- **Files** : `includes/Watcher.php:120`, `includes/Admin/HtmlImport.php:263, 443, 459`
- **Surface** : G — WP-Cron + filesystem
- **Risk** : aucun — PHPCS suggère `wp_remote_get()` qui est pour des URLs distantes ; ici ce sont des paths disque locaux (uploaded file, fichier dans uploads/abtest-templates/).
- **Fix** : annoter avec `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file path, wp_remote_get does not apply`.

### [LOW] `.gitignore` n'exclut pas explicitement les fichiers de creds
- **File** : `.gitignore`
- **Surface** : H — Direct file access + bootstrap
- **Risk** : aucun fichier `.env` / `wp-tests-config.php` (avec creds) / `*.local.php` n'existe aujourd'hui, mais filet préventif absent. Si un dev en crée un sans réfléchir, il pourrait être commité.
- **Fix** : ajouter à `.gitignore` :
  ```
  .env
  .env.*
  wp-tests-config.php
  *.local.php
  *.key
  *.pem
  ```

### [LOW] GitHub Dependabot Alerts désactivé
- **File** : repo settings (hors-code)
- **Surface** : Étape 0 — automated tooling
- **Risk** : `composer audit` est lancé manuellement et en CI à chaque push. Les CVE composer évoluent quotidiennement → fenêtre de découverte plus longue sans Dependabot continu. Aussi : les CVE npm (workflow scripts) ne sont pas surveillées.
- **Fix** : activer dans **Settings → Code security → Dependabot alerts** (gratuit). Idem **Private vulnerability reporting** dans la même section pour activer l'onglet Security disclosure.

---

## ✅ Already Fixed in v0.9.1

| Finding | Fixed in commit |
| ------- | --------------- |
| Webhook outbound : `'sslverify' => true` non explicite | `Webhook.php:160` (commit `5eff481`) |
| Message d'erreur upload mentait sur `.zip` | `HtmlImport.php:241` (commit `5eff481`) |

---

## 📊 Summary

| Severity | Count |
|----------|-------|
| 🔴 Critical | **0** |
| 🟠 High | **0** |
| 🟡 Medium | **2** |
| 🔵 Low | **7** |

---

## 🎯 Top 3 priorities

1. **MIME check sur upload** (`HtmlImport.php:240`) — défense en profondeur ; un `.htaccess` mal configuré + un `.php` déguisé = RCE silencieuse. ~5 LOC.
2. **Webhook URL → http(s) only** (`Webhook.php:73`) — anti-SSRF en 2 lignes.
3. **Activer Dependabot + Private vulnerability reporting** (GitHub Settings → Code security) — coût zéro, signal CVE continu + canal de disclosure activé.

---

## 🏆 Overall Score : **8.5 / 10**

**Forces vérifiées** :
- Toutes actions état-modifiantes : nonce + capability AVANT mutation (10 + 15 occurrences).
- 100 % des `$wpdb->` utilisent `prepare()` ; placeholders dynamiques alimentés par `(int)`.
- Inputs systématiquement `wp_unslash()` puis sanitized par fonction adaptée.
- Outputs systématiquement échappés (`esc_html`, `esc_attr`, `esc_url`, `esc_js`).
- Cookies parfaitement configurés (`httponly`, `samesite=Lax`, `secure=is_ssl()`, TTL 30j).
- `visitor_hash` salé + tronqué à 64 bits (anti-rainbow + RGPD data minimization).
- Path traversal bloqué dans extraction zip (4 patterns rejetés).
- Defensive boot `defined('ABSPATH') || exit;` partout (28 fichiers).
- Outbound HTTP : `sslverify => true` explicite (Ga4 + Webhook depuis v0.9.1), timeout court, fire-and-forget.
- Consent gate RGPD avec chemin silent baseline (zéro fuite si pas de consentement).
- Aucun `eval`, `system`, `exec`, `wp_ajax_nopriv` dans tout le code.
- Aucun secret hardcodé (vérifié sur `git ls-files`).

**Pour atteindre 10/10** : appliquer les 2 Medium + le hardening Autoload + activer Dependabot. ~30 min cumulé.

---

## 🚦 Verdict

✅ **GO release.** Aucun blocker (Critical ou High). Les 2 Medium restantes sont des hardenings recommandés mais non-urgents (admin-only attack surface ou défense en profondeur).
