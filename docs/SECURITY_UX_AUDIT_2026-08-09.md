# TRIDENT Security & UX Audit — 9 August 2026

**Scope:** Dependency SCA (composer/npm) + live UX/accessibility pass on production (`https://tcdc.co.za`).  
**Repo:** `c:\laragon\www\probooking`  
**Companion canvas:** Cursor canvas `security-ux-audit`  
**Prior app-security review:** `docs/SECURITY_AUDIT_2026-04-22.md` (authz/IDOR — still the source of truth for application bugs; this pass focuses on SCA + UX).

---

## Tooling status (MCPs)

| Tool | Status | Notes |
|---|---|---|
| **Meterian MCP** | Failed | Auth present, but live tool discovery errors — no Meterian scan possible |
| **BrowserStack Accessibility** | Failed | MCP auth succeeded; scan/expert APIs return `Invalid credentials` |
| **composer audit** | OK | 46 advisories across 15 packages |
| **npm audit --omit=dev** | OK | 0 production vulnerabilities (`axios` is a devDependency only) |
| **Cursor browser + CDP** | OK | Login, forgot-password, marketing home (desktop + 390×844) |

**Action for you:** re-enter valid BrowserStack Accessibility Automate credentials in the MCP config; repair/reinstall Meterian MCP so discovery succeeds.

---

## Executive summary

1. **SCA is noisy but actionable.** Locked versions of Laravel, Guzzle, Dompdf, CommonMark, AWS SDK, and PhpSpreadsheet are behind known advisories. A focused `composer update` of those packages should clear most of the 46 findings.
2. **UX P0:** Password reset exists at `/forgot-password` but is **not linked from `/login`** — the highest-friction support issue for fleet users.
3. **A11y:** Login page lacks landmarks/heading structure; marketing nav hit targets are undersized; app shell is comparatively stronger.
4. **Do not treat SCA as a substitute** for the April critical authz findings — verify those patches are in the baked production image.

---

## SCA findings (composer)

`composer audit` reported **46 security vulnerability advisories affecting 15 packages** (12 high · 26 medium · 6 low · 2 unspecified). Top package counts: guzzle 9, commonmark 8, dompdf 6, psr7 4, laravel 3, phpspreadsheet 3.

### Patch priority (locked → target)

| Package | Locked | Why it matters |
|---|---|---|
| `laravel/framework` | v12.53.0 | CRLF in default email rule; temporary signed URL path confusion → update to ≥12.61.1 |
| `guzzlehttp/guzzle` | 7.10.0 | Host bypass + cookie/proxy/Referer issues → ≥7.15.2 |
| `guzzlehttp/psr7` | (via guzzle) | Host confusion / CRLF → comes with guzzle bump |
| `dompdf/dompdf` | v3.0.0 | SVG local-file / DoS family → ≥3.1.6 |
| `league/commonmark` | 2.8.0 | Markdown/DoS + filter bypasses → ≥2.9.0 |
| `aws/aws-sdk-php` | 3.371.2 | CloudFront policy injection → ≥3.371.4 |
| `phpoffice/phpspreadsheet` | 5.7.0 | Memory exhaustion + WEBSERVICE SSRF → ≥5.8.1 |
| `symfony/http-foundation`, `mime`, `mailer`, `http-kernel` | 7.4.x family | SSRF/header/SMTP issues — usually cleared by Laravel framework update |
| `mtdowling/jmespath.php` | via AWS SDK | Code injection class issue — cleared with AWS SDK bump |

### Suggested command (local)

```bash
composer update laravel/framework guzzlehttp/guzzle guzzlehttp/psr7 dompdf/dompdf league/commonmark aws/aws-sdk-php phpoffice/phpspreadsheet --with-all-dependencies
```

Then rebuild the production Docker image (code is baked in — `git pull` alone is not enough).

### npm

- `axios@1.13.6` is listed under `devDependencies` only.
- `npm audit --omit=dev` → **0 vulnerabilities**.
- Optional: bump axios for local/build hygiene; not a production runtime path today.

---

## UX & accessibility findings

### P0 — Login recovery discovery

- **Evidence:** `/forgot-password` returns “Reset Password · TRIDENT” with email field + “Send Reset Link” + “Back to login”.
- **Gap:** `resources/views/auth/login.blade.php` has no forgot-password link (and `justify-between` on the remember row has nothing on the right).
- **Fortify:** `Features::resetPasswords()` is already enabled in `config/fortify.php`.
- **Fix:** Add a link to `route('password.request')` next to “Remember me”.

### P1 — Login accessibility / usability

| Issue | Evidence |
|---|---|
| No landmark regions | CDP: `main=false`, `landmarkCount=0` |
| No page heading | CDP: `h1=[]` — only a `<p>` for “Sign in to your account” |
| Tiny checkbox target | Remember-me input 16×16 (WCAG 2.2 target size) |
| No password reveal | Yard / shared tablets benefit from a show/hide control |
| Error region | Error banner exists in Blade but is not `role="alert"` / `aria-live` |

Labels, `autocomplete`, `lang="en"`, and logo `alt` are already correct.

### P1 — Marketing site

| Issue | Evidence |
|---|---|
| Undersized nav hit targets | Desktop nav links ~20px tall |
| No skip link | CDP: `skip=false` |
| CTA wording overlap | Header “Book a walkthrough” vs hero “Request a live demo” — clarify if same destination |

**Strengths:** `nav` + `main` present, clear H1, images have alt, mobile hamburger + stacked CTAs work at 390×844, brand is unambiguous in the first viewport.

### P2 — Authenticated shell / driver

**Strengths (code review):**
- `components/layouts/app.blade.php`: `aria-label` on open/close nav, Home, dismissals; `<main>`; PWA safe-area + bottom nav.
- Driver dashboard: install prompt, offline queue status, clear job list patterns.

**Nits:**
- Driver secondary copy at `text-[11px]` — hard outdoors.
- Login is the weak a11y outlier relative to the app shell.

---

## Recommended sequence

1. **Dependency bump** of the packages in the table above + Docker rebuild/redeploy.
2. **Add Forgot password link** on login (one-line UX fix; reset already live).
3. **Login a11y pass:** `<main>`, `h1`, larger remember target, optional password reveal, `role="alert"` on errors.
4. **Fix MCP credentials** (BrowserStack a11y keys + Meterian) and re-run automated scans.
5. **Confirm April criticals** (role escalation, collection-note IDOR, team IDORs, default passwords) are closed in the current production image — SCA does not cover those.

---

## Out of scope / blockers

- Authenticated admin/driver flows were not exercised (no credentials in this session).
- BrowserStack WCAG CSV reports were not generated (API 401).
- Meterian project score unavailable (MCP discovery failure).
