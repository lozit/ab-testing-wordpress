=== AB Testing WordPress ===
Contributors: guillaumeferrari
Tags: ab testing, split testing, conversion, analytics
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight A/B testing for WordPress pages with internal tracking and a 50/50 cookie-based split.

== Description ==

Run A/B tests by pointing the plugin at two existing pages — one as the control (Variant A), one as the variant (Variant B). Visitors are split 50/50 via a persistent cookie; once assigned, they always see the same variant.

Tracking is fully internal: impressions and conversions land in a custom database table, and the wp-admin dashboard shows conversion rates, lift, and a basic statistical significance indicator (two-proportion z-test).

= Features =
* Page-level A/B tests (entire page as variant — no Gutenberg surgery needed)
* Persistent cookie split (httponly, samesite=Lax)
* Internal tracking — no third-party dependency, no data leaving your site
* Conversion goals: URL visited or CSS selector clicked
* Auto bypass for logged-in editors and bots
* Two-proportion z-test for statistical significance
* `abtest_event_logged` action hook ready for v2 GA4/webhook integrations

== Caching ==

A/B testing breaks under page caching: the first variant served gets cached for everyone, all subsequent visitors get that same response, the 50/50 split dies. The plugin handles most cases automatically.

= What the plugin does automatically =

1. **Sends `Cache-Control: no-store` headers** on every page response under A/B test. Respected by Cloudflare, Varnish, Kinsta edge cache, nginx page cache, and most server-level caches.
2. **Hooks WP Rocket's `rocket_cache_reject_uri` filter** when WP Rocket is detected — your test URLs are auto-added to the never-cache list.
3. **Hooks LiteSpeed Cache's `litespeed_force_nocache_url` filter** when LiteSpeed is detected — same idea.
4. **Surfaces an admin notice** when a cache plugin or known host (like Kinsta) is detected, with what to verify.

= Hosting on Kinsta =

Kinsta uses a two-layer cache (nginx server-cache + Cloudflare Enterprise edge cache). The plugin's no-store headers bypass both — but for 100% safety, also add your test URLs to **MyKinsta → Tools → Cache → Cache Bypass** as URL Patterns (e.g. `^/promo/$`). After publishing a new test, **purge the Kinsta cache** to flush any version cached before the experiment started.

Verify it works by inspecting headers on your test URL:

`curl -I https://yoursite.com/promo/`

Look for `X-Kinsta-Cache: BYPASS` (or `MISS`). If you see `HIT`, you're getting the cached version and the split is broken — purge the cache.

= Hosting on other CDNs / hosts =

* **Cloudflare APO**: Cache-Control headers from origin override the cache. Should work out of the box. Verify with `curl -I` looking for `cf-cache-status: BYPASS` or `DYNAMIC`.
* **WP Engine**: Add the test URLs to the "Cache Exclusions" list in your User Portal.
* **Pagely / Pantheon / Pressable**: Cache-Control headers respected. Add manual URL exclusion in the host's panel for safety.

= Plugins not auto-supported =

For W3 Total Cache, WP Super Cache, WP Fastest Cache, and Cache Enabler — the plugin shows a notice but does not automatically exclude URLs (no clean public API). Manually add your test URLs to that plugin's cache exclusion list.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **AB Testing WordPress** through the Plugins menu.
3. Go to **A/B Tests** in the admin sidebar to create your first experiment.

== REST API ==

Pull stats programmatically from external tools (n8n, Make, Pipedream, dashboards).

* Endpoint: `GET /wp-json/abtest/v1/stats`
* Auth: WP Application Passwords (Basic Auth). The user must have `manage_options`.
* Generate one in your WP profile → Application Passwords.

Optional query params:

* `url=/promo/` — filter to a single test URL.
* `experiment_id=38` — fetch a single experiment by ID.
* `status=running|paused|ended|draft` — filter by status.
* `from=YYYY-MM-DD&to=YYYY-MM-DD` — restrict event date range for the stats computation.
* `breakdown=daily` — include per-day time series (for charting).

Example:

`curl -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' 'https://yoursite.com/wp-json/abtest/v1/stats?status=running'`

The response includes for each experiment: id, title, test_url, status, dates, control/variant IDs, goal, and a stats block with A/B impressions/conversions/rate, lift, p-value, significance, and 95% confidence interval bounds for both lift and absolute difference.

== Privacy ==

The plugin stores no raw IP, no User-Agent, no email, no name, and no cross-site tracking identifier. The events table contains: experiment_id, variant, test_url, event_type, created_at, and a `visitor_hash` = `sha256(IP + UA + wp_salt('auth'))` — non-reversible, single-site, salt-rotated. Cookies are httponly, samesite=Lax, secure on HTTPS, value = a single letter (a/b/c/d), 30-day TTL.

A native WordPress privacy guide snippet is registered automatically — find it under Settings → Privacy → Policy Guide → AB Testing WordPress to paste into your privacy policy.

For consent-banner sites: enable "Require consent" in the plugin settings and wire your banner to the `abtest_visitor_has_consent` filter (return true to track, false/null to block). Snippets for Complianz, CookieYes, and Cookiebot are in the README on GitHub.

Right to erasure: because no reversible identifier is stored, individual deletion isn't possible. Use `TRUNCATE wp_abtest_events` to erase all A/B testing data.

== Frequently Asked Questions ==

= How is a visitor assigned to a variant? =
On their first visit to the control page, a cookie `abtest_{experiment_id}` is set with value `a` or `b`. Subsequent visits read that cookie — the visitor always sees the same variant.

= Will admins see the test? =
No. Logged-in users with `edit_posts` capability are bypassed and always see the control. The admin bar shows a marker indicating which experiment is running on the page.

= Does it work with WooCommerce / Gutenberg blocks? =
v1 only swaps the entire page (the variant must be a separate post). Block-level and product-level testing are on the roadmap.

== Changelog ==

= 0.8.1 =
* Tested up to WordPress 6.9 (was 6.5). Local dev env (wp-env) and the wp-phpunit test suite both bumped to 6.9.4.
* Fixed PHP notice on WP 6.7+ ("_load_textdomain_just_in_time was called incorrectly") — load_plugin_textdomain now runs on `init` priority 0 instead of `plugins_loaded`.
* Performance: `GET /wp-json/abtest/v1/stats` now runs a single batched SQL query for N experiments instead of N individual queries (N+1 → 1). New public `Stats::raw_counts_for_experiments()` powers both the REST endpoint and the admin list — same SQL path everywhere.

= 0.8.0 =
* Privacy & consent gating (GDPR): new "Require consent" toggle in Settings — when on, the plugin sets no cookie and logs no event until the `abtest_visitor_has_consent` filter returns true. Without consent, visitors silently see Variant A (same path as out-of-target). Off by default, no breaking change.
* Native WordPress privacy guide content registered via `wp_add_privacy_policy_content()` — find it under Settings → Privacy → Policy Guide → AB Testing WordPress, ready to paste into your privacy policy.
* README now has a Privacy & GDPR section with copy-paste filter snippets for Complianz, CookieYes, and Cookiebot.
* New `Consent` helper class + 5 unit tests covering the 4 gate states (off, on+true, on+false, on+null/missing filter).

= 0.7.0 =
* HTML import accepts `.zip` archives — extracts CSS/JS/images to `wp-content/uploads/abtest-templates/{slug}/`, rewrites relative asset URLs in the HTML so the page renders with full styling (security: extension allowlist + path-traversal guard).
* Watch directory: drop or edit `index.html` files in `wp-content/uploads/abtest-templates/{slug}/` from your IDE, SFTP, or cloud sync — WP-Cron syncs changed files into pages every 5 minutes (or click "Scan now" in the Import HTML page). Hash-based change detection skips unchanged files.
* URL targeting now matches query strings (subset semantics): `test_url = /promo/?campaign=fb` matches visitor URL `/promo/?campaign=fb&utm_source=email`. Param order is canonicalized.
* URL targeting accepts Unicode paths: `test_url = /promotion-été/` matches both the raw and percent-encoded request paths.
* Validation regex updated to accept Unicode lowercase letters/digits (was ASCII-only). HTML form `pattern=` constraint removed accordingly.

= 0.6.1 =
* Targeting refinement: out-of-target visitors now silently see the baseline (Variant A) instead of getting a 404 on custom URLs. They are NOT tracked — no cookie set, no impression logged, no conversion script enqueued. Out-of-target visitors on URLs that override an existing public page still fall through to that original page (unchanged).
* The point: ad-paid traffic from outside your target audience (geo or device) doesn't waste clicks on 404s and doesn't pollute your test stats either.

= 0.6.0 =
* Targeting by device (mobile / tablet / desktop) and country (ISO codes).
* HTML import: drag-and-drop dropzone + sandboxed iframe preview before submit.
* Visitor device classified from User-Agent; country pulled from Cloudflare/Kinsta `CF-IPCountry` header (and similar X-* headers), with a `abtest_visitor_country` filter for custom geo plugins.
* Targeting check happens server-side before any cookie is set or impression logged — out-of-target visitors fall through (no variant assigned).
* Admin/bot bypass mode is exempt from targeting so preview is independent of the previewer's device/country.

= 0.5.0 =
* Multi-variant tests up to 4 variants (A/B/C/D) with equal split (1/N each).
* Stats engine supports pairwise comparisons vs baseline + Bonferroni-corrected alpha.
* Schema migration v1.2.0 — auto-backfills `_abtest_variants` from legacy control_id/variant_id pair.
* Admin form: dynamic variants list (add/remove rows up to MAX_VARIANTS).
* Experiments list: variants stacked vertically per row with lift + 95% CI vs baseline.
* CSV export extended with per-variant + pairwise columns.
* REST API stats response now includes `variants`, `comparisons`, `baseline`, `best`, `alpha`.
* Back-compat: legacy `control_id`/`variant_id` accessors and meta still work; legacy A/B keys still in compute() output.

= 0.4.0 =
* URL-decoupled experiments — `test_url` independent from variant pages.
* State machine (DRAFT → RUNNING → PAUSED/ENDED) with Resume = duplicate semantics.
* Baseline mode (Variant B optional) and auto-downgrade on URL conflict.
* Replace running atomic swap action.
* HTML import → Blank Canvas template (zero WP wrapper).
* Per-URL tracking scripts (Adwords, FB Pixel, Lemlist, etc.).
* Cache bypass (universal Cache-Control headers + WP Rocket + LiteSpeed + Kinsta detection).
* Google Analytics 4 integration (Measurement Protocol).
* Generic webhook integration (Zapier, Mixpanel, Segment, Slack, n8n) with HMAC.
* REST API GET /wp-json/abtest/v1/stats with Application Password auth.
* 95% confidence interval for the lift, date range filter, Chart.js timeline.
* GitHub Actions CI (PHP 8.1/8.2/8.3 matrix) + release workflow + Dependabot.

= 0.1.0 =
* Initial MVP — page-level A/B tests, internal tracking, cookie split, basic stats.
