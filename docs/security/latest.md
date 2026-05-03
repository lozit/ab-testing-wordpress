# Security Audit Report — `uplift-ab-testing` v0.12.0

**Date** : 2026-05-03
**Branch** : `main`
**Auditor** : carry-forward from v0.11.3 (no security delta)
**Previous** : [`audit-2026-05-03-v0.11.3.md`](./audit-2026-05-03-v0.11.3.md)

> **No security delta from v0.11.3.**
> v0.12.0 is the trademark rename : `AB Testing WordPress` → `Uplift – A/B Testing` and slug `ab-testing-wordpress` → `uplift-ab-testing`. WordPress.org trademark guidelines forbid "WordPress" in both the name and the slug — this closes the last wp.org submission blocker.
>
> Pure cosmetic change : text domain replaced in every `__()` / `_e()`, main file renamed via `git mv`, Composer + npm package names updated, CI build path + zip filename updated.
>
> **Internal names deliberately preserved** (security-relevant — a rename here would invalidate the audit baseline AND break existing installs) : PHP namespace `Abtest\`, hook prefixes `abtest_*`, cookies, REST namespace `abtest/v1`, custom table `wp_abtest_events`, option keys, `visitor_hash` salting, HMAC webhook signing, consent gate.
>
> All 9 surfaces from v0.9.3 / v0.10.0 / v0.11.0 reports remain valid. PHPCS still 0 findings. Plugin Check on built artifact : **0 errors, 0 warnings**.

## 📊 Summary (carry-forward from v0.11.3)

| Severity | Count |
|----------|-------|
| 🔴 Critical | **0** |
| 🟠 High | **0** |
| 🟡 Medium | **0** |
| 🔵 Low | **0** |

## 🏆 Overall Score : **10 / 10**

## 🚦 Verdict

✅ **GO release — wp.org-submission ready.** All wp.org pre-flight blockers resolved : Plugin Check green on built artifact, Chart.js vendored locally, trademark rename complete. Ready to submit via the wordpress.org Add Your Plugin form. SVN deployment automation via `10up/action-wordpress-plugin-deploy` is the next operational step (separate task).

---

For the full v0.9.3 audit content (verified surfaces, sniff annotations, etc.), see [`audit-2026-04-30-v0.9.3.md`](./audit-2026-04-30-v0.9.3.md).
