# TRIDENT Production Security Audit — 22 April 2026

**Scope:** Full Laravel 12 / Livewire 4 / Fortify stack, PWA, Docker production deploy.
**Source:** Repository `c:/laragon/www/probooking` at commit `51f2c4f` (main).
**Method:** Four parallel read-only code sweeps (config & hardening, auth & demo leftovers, authz & tenant isolation, public endpoints & dependencies) + `composer audit`.

This audit is evidence-based — every finding below includes file:line citations so the fix can be verified. Severity tiers are assigned from a production-risk perspective: **Critical** = can cause breach or privilege escalation with a few clicks today. **High** = requires some knowledge of the app but exploitable by any authenticated user. **Medium** = defence-in-depth / regulatory / PII hygiene. **Info** = documented posture, no change required.

---

## Executive summary

| Tier | Count | One-line takeaway |
|---|---|---|
| **Critical** | 7 | Role escalation via admin user form, IDOR on collection-note PDF, customer-team IDOR + role escalation, dealer/OEM team IDOR, default-password super admin likely in prod DB, shared driver password, unrate-limited password reset. |
| **High** | 9 | Livewire admin actions bypass `JobPolicy`, weak password policy, Trust-all proxies, session cookie not forced secure, AWS SDK CVE, SW caches root HTML, role middleware defined but not used, Maps key exposed without evidence of GCP lockdown, driver API no throttle. |
| **Medium** | 10 | No CSP/HSTS/Referrer-Policy, 403 leaks exception message, audit trail gaps on password/email/role changes, `league/commonmark` CVEs, dev compose key committed, verify page leaks VIN+addresses, PII in geocoding logs, 403 view, no 2FA, `dompdf 3.0.0` stale. |
| **Info / Clean** | 12+ | `.env` gitignored, `APP_DEBUG=false` & `LOG_LEVEL=warning` in prod compose, no `$guarded=[]`, driver API scopes to `driver_user_id`, `DemoSeeder` env-gated, login rate-limited, Livewire uploads mime-validated, no raw-SQL interpolation, prod compose does not expose Postgres/Redis to host, nginx denies dotfiles. |

### What needs to happen today (recommended sequence)

1. **Rotate secrets + confirm there is no `admin` / `changeme` user on production DB** (Critical #5).
2. **Force every seeded driver to change password on first login** (Critical #6).
3. **Patch the role-assignment form, IDOR on collection-note download, and team-management IDORs** (Critical #1–4). Patches below.
4. **Add throttle middleware to password reset** (Critical #7).
5. **Update `aws/aws-sdk-php` and `league/commonmark`** via `composer update` (High #5, Medium).
6. **Add authorization calls to admin order/dispatch actions** (High #1).
7. **Tighten the Google Maps key in Google Cloud Console to the TRIDENT domain** (High #8 — must be done in the GCP console; not a code change).

The rest can be scheduled into a follow-up hardening sprint.

---

## Critical findings

### C-1 · Role escalation on `/admin/users`

Any internal user who can reach `/admin/users/create` or `/admin/users/{user}/edit` can assign **any** non-driver role — including `super_admin`, `developer`, and `ops_manager` — to any account. The role select is populated directly from `Role::where('slug', '!=', 'driver')->get()` and the `save()` handler calls `roles()->sync($this->selectedRoles)` with no server-side filter on which roles the current actor is allowed to grant.

- `resources/views/pages/admin/users/create.blade.php:44-48, 68, 80-83`
- `resources/views/pages/admin/users/edit.blade.php:69-70, 85-86`

**Impact:** A dispatcher or accountant who somehow reaches these routes (or is made to reach them via an open browser tab) can self-promote or promote another account to `super_admin`. The routes are under the `internal` role-gate only.

**Fix sketch:**
- Restrict the routes at the middleware level to `super_admin`/`ops_manager` only.
- In the component, filter `selectedRoles` against an allowlist based on `auth()->user()->canManageRole($role)`.
- Wire `JobPolicy`-style role assignment policy.

### C-2 · IDOR on `/collection-note/{job}/download`

The collection-note PDF route is behind `auth` only, with no `authorize('generateCollectionNote', $job)` call. Any authenticated user (dealer, OEM customer, driver, etc.) can iterate the numeric `Job::id` and download another tenant's PDF — which contains the **driver SA ID number, cellphone, VIN, customer contact names/phones, and customer notes**.

- `routes/web.php:42-48` (route registration, `->middleware('auth')` only)
- `resources/views/documents/collection-note.blade.php:264-268, 312-329, 335-339` (sensitive PII in template)
- `app/Policies/JobPolicy.php:~170` (`generateCollectionNote` ability already exists but isn't called)

**Impact:** Trivial cross-tenant PII leak. Regulatory implication (POPIA / GDPR equivalent).

**Fix (applied in patch below):** change the route closure to a controller or invoke `Gate::authorize('generateCollectionNote', $job)` before streaming.

### C-3 · Customer-team IDOR + role escalation

`resources/views/pages/customer/team/index.blade.php` lets any authenticated customer user hit the Livewire `save()` method, which does `User::findOrFail($this->editingId)` with **no** `whereHas('companies', ...)` check. The method then syncs roles from an `in:customer_owner,customer_admin,...` allowlist — meaning a low-privilege `customer_user` can:

1. Set `$editingId` to another company's user id (Livewire property tampering).
2. Assign the target the `customer_owner` role.

- `resources/views/pages/customer/team/index.blade.php:56-112` (save handler, unguarded `findOrFail`)
- Same file lines 26-29 (`mount()` reads `auth()->user()->company` but does not gate `save()`)

**Impact:** Full customer-tenant takeover by any low-privilege customer user in that tenant (and potentially across tenants).

### C-4 · Dealer / OEM team IDOR

Same pattern as C-3 — `User::findOrFail($userId)` in `toggleActive`, `saveEdit`, etc., without confirming the target belongs to the current company via the `company_users` pivot.

- `resources/views/pages/dealer/team/index.blade.php:77-80, 88-90, 117-120`
- `resources/views/pages/oem/team/index.blade.php` (mirrors dealer page)

### C-5 · Likely `admin` / `changeme` super-admin on production DB

`SuperAdminSeeder` is called **unconditionally** on every `php artisan db:seed` run (unlike `DemoSeeder`, which is env-gated). Defaults: username `admin`, password `changeme`.

- `database/seeders/DatabaseSeeder.php:21` (unconditional call)
- `database/seeders/SuperAdminSeeder.php:13-26` (defaults)
- `.env.example:74-75` (ships `SUPER_ADMIN_PASSWORD=changeme` in the template)

**Impact:** If the production DB was ever seeded without `SUPER_ADMIN_PASSWORD` being set in the server environment, **an `admin` user exists right now with password `changeme`**. Must be verified and rotated immediately.

### C-6 · All fleet drivers share `Trident@2026`

`FleetDriverSeeder` hashes one shared password for all ~38 drivers, and there's no `force_password_change` / `password_changed_at` flow.

- `database/seeders/FleetDriverSeeder.php:31-42, 49-60`

**Impact:** One driver shares or leaks the password → every driver account is compromised. Stolen phones / shared laptops in the yard can cross-read each other's jobs.

### C-7 · Password reset has no rate limit

Fortify registers `POST /forgot-password` and `POST /reset-password` without any app-defined throttle. The project defines the `login` limiter only.

- `app/Providers/FortifyServiceProvider.php:55-58` (login limiter only)
- `vendor/laravel/fortify/routes/routes.php:60-66` (reset routes, no throttle middleware)

**Impact:** Email-bomb an address, enumerate valid addresses, or token-brute during the 60-minute expiry window.

---

## High findings

### H-1 · Admin Livewire actions bypass `JobPolicy`

`admin/orders/show` and `admin/dispatch` mutate jobs via direct `$this->job->transitionTo(...)` calls with no `$this->authorize(...)`. `JobPolicy::assignDriver`, `cancel`, etc. exist but are not called.

- `resources/views/pages/admin/orders/show.blade.php:90-108` (`assignDriver`), and 31-179 (most other transitions). Only `verifyBooking` (lines 50-) uses `authorize`.
- `resources/views/pages/admin/dispatch.blade.php:32-55`
- `resources/views/pages/admin/planning.blade.php:36-47` (`planJob`)

**Impact:** Any user with the `internal` role (dispatcher, accounts, developer) can drive every job transition on every job. Currently mitigated only by the route middleware; defence in depth is missing.

### H-2 · Weak password policy

- Profile change (self-service) uses `min:8`, no complexity rules.
- Admin user and driver create also `min:8` only.
- Customer team uses **`min:6`**.
- No `Password::defaults()` registered in `AppServiceProvider`, so Fortify falls back to `Password::min(8)` without mixed case / numbers / symbols.

Evidence: `app/Actions/Fortify/PasswordValidationRules.php:14-17`, `app/Providers/AppServiceProvider.php:20-31`, `resources/views/pages/profile/index.blade.php:63-66`, `resources/views/pages/customer/team/index.blade.php:66-70`.

### H-3 · Trust all proxy headers

`$middleware->trustProxies(at: '*')` in `bootstrap/app.php:40-41`. If anything that can speak HTTP to the container is compromised (sidecar, dev service on the same network, misconfigured ingress), `X-Forwarded-*` headers are honoured — including `X-Forwarded-For` (IP spoofing, rate-limit bypass) and `X-Forwarded-Proto` (session/cookie secure flag confusion).

Should be set to the specific proxy CIDR(s), typically `'10.0.0.0/8'` or the specific docker network.

### H-4 · `SESSION_SECURE_COOKIE` not enforced

`config/session.php:172` reads `env('SESSION_SECURE_COOKIE')` with **no default**. If the prod `.env` doesn't set it to `true`, Laravel emits the session cookie **without** the `Secure` flag — i.e. it's transmittable over plain HTTP in the unlikely event of HTTPS misconfiguration or downgrade.

### H-5 · `aws/aws-sdk-php` — HIGH-severity CVE

`composer audit` reports `PKSA-4t1p-xpk2-nsss` (CloudFront Policy Document Injection via Special Characters) affecting `aws/aws-sdk-php` versions `>=3.11.7,<=3.371.3`. You're within that range. Resolved by `composer update aws/aws-sdk-php`.

### H-6 · PWA service worker caches root `/` HTML

`public/sw.js:23-35` uses a network-first strategy that `cache.put`s the HTML response for **any** GET, and `/` is pre-cached in `STATIC_ASSETS` (line 3). On a shared device, after Alice logs out Bob may see Alice's `/`-routed dashboard snapshot from the Cache API. Main-app SW is **not** scoped to exclude authenticated paths, unlike `driver-sw.js` which explicitly skips `/driver/api/` and `/livewire/`.

### H-7 · `RoleMiddleware` defined but never applied

`app/Http/Middleware/RoleMiddleware.php:19-24` logs out inactive users — but the middleware alias `role:` is not used on any route. The area-specific middlewares (`EnsureInternalAccess`, `EnsureDealerAccess`, etc.) don't check `is_active`, so a user who is deactivated mid-session retains access until session expiry.

### H-8 · Google Maps JS API key exposed client-side

`resources/views/components/layouts/app.blade.php:296-299, 359` renders the key into the page for any authenticated user. The key is resolved from `SystemSetting` → `env('GOOGLE_MAPS_API_KEY')`. This is inherent to the Google Maps JS API — the mitigation is **HTTP referrer restriction** and **Google Cloud Armor / API Keys restriction** on the GCP console.

**Action:** Lock the production key to `https://tcdc.co.za/*` and `https://*.tcdc.co.za/*` in GCP console. Rotate if it has ever been committed or shared.

### H-9 · Driver sync API has no rate limit

Routes in `routes/driver.php:18-22` (prefix `/api`) are behind `web` + `auth` + `driver.access` but there's **no** `throttle` middleware. A compromised driver session can spam `POST /driver/api/jobs/{job}/events` or `/documents` indefinitely.

---

## Medium findings

### M-1 · No CSP / HSTS / Referrer-Policy / Permissions-Policy headers

`docker/nginx.conf:33-34` sets only `X-Frame-Options: SAMEORIGIN` and `X-Content-Type-Options: nosniff`. Add at least:

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(self), camera=(self), microphone=()" always;
# CSP — start in report-only, tighten once Livewire/Volt inline scripts are audited
```

### M-2 · 403 view leaks exception message

`resources/views/errors/403.blade.php:22-24` prints `$exception?->getMessage()` directly. Policy `authorize` calls often throw with a human phrase like `"You do not have permission to verify jobs outside your company."` — meaningful reconnaissance information.

### M-3 · Audit trail gaps

Several sensitive mutations have no `AuditService::log` nor `Auditable` coverage (User is not `Auditable`):

- Profile password / email change (`resources/views/pages/profile/index.blade.php:35-90`)
- Admin user role sync (`resources/views/pages/admin/users/create.blade.php`, `edit.blade.php`)
- Customer / dealer / OEM team create and update
- Driver PWA sync (events, documents) — by design but worth capturing

### M-4 · `league/commonmark` — 2 medium CVEs

`PKSA-21fb-n1x5-5nf7` (embed extension allowed_domains bypass) and `PKSA-2cx9-ynrq-qdk3` (DisallowedRawHtml extension bypass). Fix via `composer update league/commonmark`.

### M-5 · `docker-compose.dev.yml` commits `APP_KEY` + DB password

`docker-compose.dev.yml:28-34` has a hardcoded `APP_KEY=base64:z1Vx...` and `DB_PASSWORD: proselver`. Not production, but committed to git history. Anyone with repo access can decrypt local dev sessions cookies — and if this key was ever reused in production early on, any sessions encrypted with it are decryptable.

**Action:** Rotate the committed dev `APP_KEY` (`docker compose -f docker-compose.dev.yml exec app php artisan key:generate`), confirm prod uses a different key (inspect server `.env`).

### M-6 · Public `/verify/{uuid}` page leaks moderately sensitive data

Exposes VIN, full pickup/delivery addresses, job number, company names, driver name, brand/model. Does **not** expose PO amounts or SA ID numbers. UUID discovery is practical only for people who have seen the QR. No rate limit. Consider adding a 60 req/min throttle.

- `routes/web.php:35-38`
- `resources/views/verify/collection-note.blade.php:35-90`

### M-7 · Geocoding logs full address as structured context

`app/Services/GeocodingService.php:33, 88` — `Log::warning('Geocoding failed', ['address' => $address, ...])`. If log level ever flips to `debug`, addresses (PII) end up in structured logs with long retention.

### M-8 · No 2FA available

Fortify supports it via `Features::twoFactorAuthentication()` but it's not enabled in `config/fortify.php`. For a system with `super_admin` + `developer` accounts controlling every tenant, enabling TOTP for privileged accounts is worth the time.

### M-9 · `dompdf 3.0.0` is dated (April 2024)

No current CVE in `composer audit`, but worth upgrading as part of the sweep. The service already sets `isRemoteEnabled=false` which closes off the common SSRF vector.

### M-10 · Driver PWA `driver-pwa.js` stores `token` in IndexedDB

`resources/js/driver-pwa.js:118-121` saves a `token` field in `pendingEvents`. Confirm this is never actually populated (the live code path uses CSRF + session per `driver/sync.js`). If dead code, delete it to avoid future confusion.

---

## Info / clean (documented posture — no change required)

- `.env`, `.env.backup`, `.env.production` are in `.gitignore` (`c:/laragon/www/probooking/.gitignore:16-18`).
- Production `docker-compose.yml` overrides `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=warning` and injects `APP_KEY` from host env (not baked in the image).
- Prod compose does **not** map Postgres `5432` or Redis `6379` to the host — only `app:80` is published.
- `docker/nginx.conf:54-56` denies dotfiles (except `.well-known`).
- `DemoSeeder` is env-gated: only runs when `APP_ENV ∈ {local, development, testing}` OR `SEED_DEMO_USERS=true` (`database/seeders/DatabaseSeeder.php:30-32`). Good.
- No `dd()` / `dump()` / `ddd()` left anywhere in `app/`, `resources/views/`, or `routes/`.
- No public registration route.
- Login is rate-limited to 5/min keyed by identity + IP (`FortifyServiceProvider.php:55-58`).
- Inactive users are blocked from **new** logins (`FortifyServiceProvider.php:36-37`).
- Driver-sync API endpoints correctly scope by `driver_user_id === auth()->id()` before mutation (`app/Http/Controllers/Api/DriverSyncController.php`).
- `/po/{po}/preview` correctly checks `company_id === user's company` OR `isInternal()` before streaming (`routes/web.php:8-32`).
- Livewire file uploads all have `mimes:` + `max:` validation server-side.
- No Eloquent model has `$guarded = []`.
- `JobPolicy::view` correctly scopes cross-tenant visibility.
- No raw-SQL string interpolation (all `whereRaw` / `selectRaw` use static SQL or bind parameters).
- `routes/api.php` exposes no endpoints.
- Nginx bucket visibility on R2 / S3 is private by default (`config/filesystems.php:50-82` don't set `visibility => public`).
- Password reset tokens have Fortify's default 60-minute lifetime and 60s token throttle.
- `CollectionNoteService` renders PDFs with Dompdf `isRemoteEnabled=false` — no SSRF via user-supplied image URLs.
- Documents template uses only `{{ $var }}` (escaped), never `{!! $var !!}`.

---

## Remediation plan

### Immediate (deploy today)

All of these are isolated changes; I can apply the code patches for the starred ones in one commit as soon as you say go.

| Finding | Action | Who |
|---|---|---|
| C-5 | On prod: `docker compose exec -T app php artisan tinker` and verify `User::where('username','admin')->first()`. If exists, force password rotation or delete. | **You** |
| C-6 | Add `password_changed_at` + `must_change_password` column; force driver to change on first PWA login. | **Code patch** ⭐ |
| C-2 | Wrap `/collection-note/{job}/download` in `Gate::authorize('generateCollectionNote', $job)`. | **Code patch** ⭐ |
| C-1 | Server-side allowlist for role assignment on `admin/users` based on actor's privileges. | **Code patch** ⭐ |
| C-3, C-4 | Add company-scoped `whereHas` on every customer/dealer/OEM team `findOrFail`. | **Code patch** ⭐ |
| C-7 | Add `throttle:5,1` to `password.email` and `throttle:5,1` to `password.update`. | **Code patch** ⭐ |
| H-1 | Add `$this->authorize('assignDriver', $this->job)` etc. across admin order/dispatch/planning actions. | **Code patch** ⭐ |
| H-5 / M-4 | `composer update aws/aws-sdk-php league/commonmark` and redeploy. | **You** |
| H-8 | Lock Google Maps key to `tcdc.co.za` referrer in GCP console. Rotate key. | **You** |

### This week

- H-2: Register `Password::defaults(Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised())` in `AppServiceProvider`; update all `min:6`/`min:8` form rules to `Password::defaults()`.
- H-3: Replace `trustProxies(at: '*')` with the specific proxy network CIDR.
- H-4: Set `SESSION_SECURE_COOKIE=true` in prod `.env` and make it default in `config/session.php:172`.
- H-7: Apply `role:` middleware to routes that must re-check `is_active`, or extend each `Ensure*Access` middleware to check it.
- H-9: Add `throttle:60,1` to `routes/driver.php` API group.
- M-1: Add CSP (report-only first), HSTS, Referrer-Policy, Permissions-Policy to `docker/nginx.conf`.
- M-2: Strip `$exception?->getMessage()` from `errors/403.blade.php`.
- M-3: Wire `AuditService::log` into profile/team/role mutations.
- M-5: Rotate committed dev `APP_KEY`. Confirm prod uses a different key.

### This month

- M-8: Enable Fortify 2FA for `super_admin` / `developer` / `ops_manager`.
- M-9: Plan `dompdf` upgrade review.
- M-10: Delete dead token-storage path in `driver-pwa.js`.
- Add a password-history table so drivers can't reset to `Trident@2026`.
- Run automated weekly `composer audit` + `npm audit` in CI; fail the build on HIGH/CRITICAL.
- Penetration test on the production domain once the above are in.

---

## Dependency CVE summary (from `composer audit`)

| Package | Severity | Advisory | Fix |
|---|---|---|---|
| `aws/aws-sdk-php` | **HIGH** | PKSA-4t1p-xpk2-nsss — CloudFront Policy Document Injection | Upgrade past 3.371.3 |
| `league/commonmark` | Medium | PKSA-21fb-n1x5-5nf7 — embed extension allowed_domains bypass (CVE-2026-33347) | Upgrade past 2.8.1 |
| `league/commonmark` | Medium | PKSA-2cx9-ynrq-qdk3 — DisallowedRawHtml bypass (CVE-2026-30838) | Upgrade past 2.8.0 |

`npm audit` was not run in this pass (no direct JS runtime dependencies declared in `package.json`; only build-time). Worth running nonetheless.

---

*Report compiled from four parallel read-only audits. No production data or logs were accessed; findings are purely from static analysis of the repo.*
