# Todo — AB Testing WordPress Plugin

## ✅ Livré

### v0.1.0 — MVP
- Bootstrap plugin (PSR-4, autoload fallback, activation/deactivation hooks)
- CPT `ab_experiment`, table custom `wp_abtest_events`
- Cookie persistant 50/50, Router via `template_redirect`, content swap
- Tracker (impressions + conversions, dédup), REST endpoint `/abtest/v1/convert`, `tracker.js`
- Stats : taux, lift, z-test 2 proportions
- Admin : list + edit form + actions start/pause/end/delete + nonces + capabilities
- CacheNotice (détection WP Rocket / W3TC / LiteSpeed)
- Tests unitaires PHPUnit : Stats + Cookie
- Hook `abtest_event_logged` exposé pour intégrations futures

### v0.2.0 — URL indépendante des pages (refactor majeur)
- Champ `test_url` au niveau experiment, dissocié du control_id
- Schema migration v1.1.0 : colonne `test_url` + index, backfill auto
- Router refactor : `parse_request` au lieu de `template_redirect`, match par URL
- Hook `pre_get_posts` + `posts_results` pour servir des pages `private`
- Pages A/B forcées en `private` automatiquement à l'activation running
- Bouton "View original" dans l'admin bar
- Tests intégration manuels e2e via wp-env

### v0.3.0 — Workflow CRO complet
- Mode **Baseline** : Variant B optionnel (visiteurs voient tous A jusqu'à ajout de B)
- Vue **Stats by URL** fusionnée dans la liste principale (plus de sous-menu)
- Bouton **"+ Add experiment to this URL"** par section, pré-remplit le form
- **Auto-downgrade en draft** quand URL conflict (au save ou au Start)
- Bouton **"Replace running"** (swap atomique : pause l'actuel + start le nouveau)
- **State machine stricte** :
  - DRAFT → RUNNING
  - RUNNING → PAUSED | ENDED
  - PAUSED → ENDED (via End) ou DUPLICATE-RESUME
  - ENDED = terminal
- **Resume** = duplicate l'experiment + passe l'original à ENDED (chaque période a ses dates propres)
- **Durée affichée** (`human_time_diff`) : "Since X (3 days)" ou "X → Y (2 weeks)"
- **Boutons inline** au lieu de dropdown status (Save & Start / Save & Pause / Save & End)

### Import HTML → Blank Canvas
- Sous-page wp-admin "Import HTML" + upload form (.html / .htm, 5 MiB max)
- Page template **Blank Canvas** : rend `post_content` brut, zéro wrapper WP
- Mode "Create new" / "Replace existing"
- `wp_slash()` sur les inputs (fix backslash JSON)

### Bugs critiques fixés (loggés dans `tasks/lessons.md`)
- `register_post_type` doit fire sur `init`, pas `plugins_loaded`
- WP auto-désactive un plugin qui fatal au load (réactiver après fix)
- Block themes ne déclenchent pas `the_post` action → muter directement les globals
- WP filtre les pages `private` côté front → combo `pre_get_posts` + `posts_results`
- wp-env mod_rewrite pas chargé sans `service apache2 reload`
- `wp_insert_post` mange un niveau d'antislash via `wp_unslash()` interne → `wp_slash()` obligatoire

---

## 🟢 Backlog priorisé

### Top 3 livré
- [x] **Graph timeline** Chart.js par URL (line chart conversion rate par jour)
- [x] **GA4 integration** via Measurement Protocol + Settings page
- [x] ~~**Inject admin bar dans Blank Canvas**~~ — testé puis abandonné : injecter `wp_head/wp_footer` casse les bundlers SPA, et déplacer hors body casse le CSS de l'admin bar. Trade-off accepté : preview admin via `?abtest_preview=a|b|original` dans l'URL.

### Tracking scripts par URL livré
- [x] **UrlScripts** helper + option `abtest_url_scripts`
- [x] Éditeur dynamique dans le form édition (add/remove rows + JS vanilla)
- [x] Injection `after_body_open` / `before_body_close` :
  - via `wp_body_open` + `wp_footer` sur pages WP normales (override)
  - via `stripos`+`substr_replace` dans le template Blank Canvas
- [x] Partagé entre toutes les expériences sur l'URL

### Workflow / UX
- [x] **Export CSV** des experiments + stats — bouton dans la liste, respecte filtres date + show, BOM UTF-8 (Excel)
- [x] **Scheduling auto** via WP-Cron (hourly tick) — `_abtest_schedule_start_at` / `_abtest_schedule_end_at` meta + UI datetime-local + soft-conflict skip
- [x] **Confidence interval** 95% (Wald) affiché à côté du lift
- [x] **Preview HTML avant upload** (iframe sandbox srcdoc rendu en live à la sélection)
- [x] **Drag & drop file picker** (drop zone visuelle avec hover state, taille + extension validées côté client)
- [x] **Filtre période** (from/to + presets 7/30 jours/all-time) sur stats + chart
- [x] **Filtre default "running only"** sur les URLs (cache les URLs sans exp running, toggle "Show all")

### Intégrations externes
- [x] **Webhook custom** générique (Zapier, Mixpanel, Segment, Slack, n8n) — liste de webhooks dans Settings + HMAC SHA256 optionnel + filtre fire_on (all / conversion-only) + bouton "Send test event"
- [x] **REST API stats endpoint** `GET /wp-json/abtest/v1/stats` — auth Application Password, query params (url, experiment_id, from, to, status, breakdown), pour pull depuis n8n / Make / dashboards
- [ ] WooCommerce (variantes prix / descriptions produit)

### Capacités produit
- [x] **Multi-variantes A/B/C/D** — split équitable, pairwise vs baseline + Bonferroni, UI dynamique add/remove, migration auto v1.2.0, REST + CSV étendus
- [ ] Block-level testing (bloc Gutenberg unique au lieu de page entière)
- [x] **Targeting** (devices mobile/tablet/desktop + ISO countries via Cloudflare/Kinsta CF-IPCountry header ou filter `abtest_visitor_country`) — Router gate, admin/bot bypass exempt, 9 unit tests sur le UA classifier
- [ ] Multi-langue (WPML / Polylang)

### Qualité technique
- [x] **Tests d'intégration wp-phpunit** — bootstrap + wp-tests-config.php, 10 tests (SchemaTest, ExperimentTest, SchedulerTest), runs in wp-env tests-cli
- [x] **CI GitHub Actions** — `.github/workflows/ci.yml` avec matrix PHP 8.1/8.2/8.3 (syntax check + PHPUnit gating), PHPCS en continue-on-error, concurrency cancel-in-progress, badges README
- [x] **Release workflow** + **Dependabot** (composer/npm/actions weekly)
- [x] **Cache bypass complet** : headers no-store universels + WP Rocket + LiteSpeed + Kinsta detection (notice avec lien Cache Bypass MyKinsta) + doc readme.txt
- [ ] Refactor `Stats::for_experiment` → batch query (1 SQL pour N experiments)
- [ ] Bump WP-env vers 6.9 (et dropper le pin `~6.5.0` sur wp-phpunit)

### RGPD / conformité (v0.8.0)
- [x] **Option "respecter consentement"** — toggle "Require consent" dans Settings + filtre `abtest_visitor_has_consent` (true/false/null) + chemin silent baseline (zéro cookie, zéro impression) quand pas de consent. Off par défaut, no breaking change. Helper `Consent::is_blocked()` + 5 tests unitaires.
- [x] **Doc cookie pour politique de confidentialité** — 3 surfaces : (a) `wp_add_privacy_policy_content()` natif WP via `includes/PrivacyPolicy.php` (visible dans Settings → Privacy → Policy Guide), (b) section `## Privacy & GDPR` dans README.md avec snippets Complianz/CookieYes/Cookiebot, (c) section `== Privacy ==` dans readme.txt.

### HTML import — limites mineures (v0.7.0)
- [x] **Format zip avec assets** (CSS, JS, images) — extraction sécurisée vers `wp-content/uploads/abtest-templates/{slug}/` (extension allowlist + path-traversal guard), réécriture des URL relatives href/src/srcset/url() en absolues dans le HTML stocké
- [x] **Watch directory disque** (sync IDE → reload) — `Watcher.php` + WP-Cron 5 min + bouton "Scan now" dans Import HTML, détection de changement par hash SHA-256 sur `index.html`, additif uniquement (jamais de delete), pages zip taggées avec `_abtest_watcher_slug` pour éviter les doublons
- [x] **URL match avec query string** (`?campaign=fb`) — sémantique subset (les params du `test_url` doivent tous être présents dans la requête, mais celle-ci peut en avoir d'autres comme `utm_*`), normalisation par `ksort` pour canonisation
- [x] **URLs unicode dans `test_url`** — `rawurldecode` + `mb_strtolower`, regex `\p{Ll}\p{N}`, attribut HTML `pattern=` retiré du form
