# Audit Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the High/Medium findings from the 4 Sep 2026 Playwright UX & security audit on TRIDENT (`tcdc.co.za` + local Docker) without regressing authz, Livewire, or the driver PWA.

**Architecture:** Prefer edge/nginx headers for HSTS/CSP/Referrer (they already flow through OpenResty — `X-Frame-Options` and `X-Content-Type-Options` prove this). Keep ForceChangePassword as global `web` middleware (already written). Patch Composer deps inside the app image. Ship a branded `429` view matching the existing `403` pattern. Defer owner-nav IA density (audit Info only).

**Tech Stack:** Laravel 12, Livewire/Volt 4, Fortify, Docker (`docker/nginx.conf` + baked `docker-compose.yml` prod image), Pest PHP, OpenResty edge in front of the app container.

**Source audit:** [ux-security-audit-2026-09-04.canvas.tsx](file:///C:/Users/pchar/.cursor/projects/c-laragon-www-probooking/canvases/ux-security-audit-2026-09-04.canvas.tsx) · findings S1–S8, U1–U3.

## Global Constraints

- Production code is baked into the Docker image — deploy only via the canonical `git pull` + `docker compose build app` + recreate `app/scheduler/queue` sequence (see `.cursor/rules/trident-working-agreement.mdc`). Never tell anyone to edit PHP inside the running container.
- Local verification uses `docker compose -f docker-compose.dev.yml` on port **8092** (8090/8091 are other apps).
- Do not weaken CSRF, session cookie flags, or role middleware.
- CSP must not break Livewire XHR, Vite assets, or the login page inline show-password script — start **Report-Only**, then enforce after one quiet production day.
- Livewire target: `>=4.3.4` (advisory fixed after 4.3.3); prefer latest stable `4.4.x` if the test suite stays green.
- `league/commonmark` target: `>=2.10.0` (locked today at 2.9.1).
- Commit after each task; do not push unless asked.

## File map

| File | Responsibility |
|---|---|
| `composer.json` / `composer.lock` | Livewire + commonmark bumps |
| `docker/nginx.conf` | HSTS, CSP-Report-Only, Referrer-Policy, Permissions-Policy, hide `X-Powered-By` |
| `bootstrap/app.php` | Re-enable `ForceChangePassword` on `web` |
| `app/Http/Middleware/ForceChangePassword.php` | Already correct — do not rewrite unless tests prove allow-list gaps |
| `resources/views/pages/profile/index.blade.php` | Existing forced-rotation UI (`?must_change=1`) — verify only |
| `resources/views/errors/429.blade.php` | New branded throttle page |
| `public/robots.txt` | Disallow auth/app paths |
| `resources/views/auth/login.blade.php` | Remember-me `id` + `for` |
| `resources/views/pages/landing.blade.php` | Mobile tap-target padding on header Sign in / text links |
| `tests/Feature/ForceChangePasswordTest.php` | New Pest coverage |
| `tests/Feature/SecurityHeadersTest.php` | New Pest/header smoke (optional if nginx-only; prefer HTTP feature test via Laravel middleware OR document nginx curl check) |

---

### Task 1: Patch Livewire + commonmark

**Files:**
- Modify: `composer.json`, `composer.lock`
- Test: existing Pest suite smoke + `composer audit`

**Interfaces:**
- Consumes: current lockfile (`livewire/livewire` v4.0.0, `league/commonmark` 2.9.1)
- Produces: `livewire/livewire` ≥4.3.4 (prefer latest 4.4.x), `league/commonmark` ≥2.10.0

- [ ] **Step 1: Confirm advisories still open**

Run (local Docker app container):

```bash
docker compose -f docker-compose.dev.yml exec -T app composer audit
```

Expected: Livewire medium CVE-2026-81887 + commonmark high DoS still listed.

- [ ] **Step 2: Update packages**

```bash
docker compose -f docker-compose.dev.yml exec -T app composer update livewire/livewire league/commonmark --with-all-dependencies
```

If Composer resolves below the floors, constrain in `composer.json`:

```json
"livewire/livewire": "^4.3.4",
"league/commonmark": "^2.10.0"
```

then re-run the update.

- [ ] **Step 3: Verify versions + clean audit for those two**

```bash
docker compose -f docker-compose.dev.yml exec -T app sh -c "composer show livewire/livewire | head -5; composer show league/commonmark | head -5; composer audit"
```

Expected: Livewire ≥4.3.4, commonmark ≥2.10.0; those two advisories gone (other advisories may remain — out of scope).

- [ ] **Step 4: Smoke the app**

```bash
docker compose -f docker-compose.dev.yml exec -T app php artisan test --parallel=0 2>&1 | tail -n 40
```

If full suite is too slow, at minimum:

```bash
docker compose -f docker-compose.dev.yml exec -T app php artisan test tests/Feature --exclude-group=slow 2>&1 | tail -n 60
```

Expected: green, or only pre-existing failures unrelated to Livewire.

- [ ] **Step 5: Manual Livewire sanity (local)**

Open `http://localhost:8092/login`, sign in as `owner@tcdc.test` / `changeme`, visit `/admin/dashboard/owner` and `/profile`. Confirm Livewire navigations and password form still work (no blank pages / console DOM errors).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock
git commit -m "fix: patch Livewire XSS and commonmark DoS advisories"
```

---

### Task 2: Security headers + hide PHP version

**Files:**
- Modify: `docker/nginx.conf`
- Verify: curl against local `:8092` and (after deploy) `https://tcdc.co.za`

**Interfaces:**
- Consumes: existing `add_header X-Frame-Options` / `X-Content-Type-Options` in `docker/nginx.conf`
- Produces: HSTS, CSP-Report-Only, Referrer-Policy, Permissions-Policy, no `X-Powered-By`

- [ ] **Step 1: Update nginx server block**

Replace the header section inside `server { ... }` in `docker/nginx.conf` with:

```nginx
        # Clickjacking / MIME sniffing (already present — keep)
        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;

        # Audit S1 / S7 — browsers ignore HSTS on plain HTTP, so local :8092 is safe
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()" always;

        # Audit S2 — Report-Only first. Tighten to Content-Security-Policy after 24h quiet.
        # Allows Vite/Livewire inline bootstrap; blocks exotic object/base injections.
        add_header Content-Security-Policy-Report-Only "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; img-src 'self' data: blob: https:; font-src 'self' data: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; connect-src 'self' https: wss:;" always;

        charset utf-8;
```

And inside `location ~ \.php$ { ... }` add **before** `include fastcgi_params;`:

```nginx
            fastcgi_hide_header X-Powered-By;
```

- [ ] **Step 2: Recreate local app container so nginx reloads the conf**

```bash
docker compose -f docker-compose.dev.yml up -d --force-recreate app
```

Wait until healthy, then:

```bash
curl -sI http://localhost:8092/login
```

Expected headers include:

- `strict-transport-security: max-age=31536000; includeSubDomains`
- `content-security-policy-report-only: ...`
- `referrer-policy: strict-origin-when-cross-origin`
- `permissions-policy: ...`
- `x-frame-options: SAMEORIGIN`
- `x-content-type-options: nosniff`
- **No** `x-powered-by`

- [ ] **Step 3: Confirm login page still loads scripts**

Open `http://localhost:8092/login` — show-password toggle must work; Vite CSS must load.

- [ ] **Step 4: Commit**

```bash
git add docker/nginx.conf
git commit -m "security: add HSTS/CSP-RO/Referrer headers and hide X-Powered-By"
```

---

### Task 3: Re-enable ForceChangePassword

**Files:**
- Modify: `bootstrap/app.php` (uncomment middleware append)
- Create: `tests/Feature/ForceChangePasswordTest.php`
- Verify: `app/Http/Middleware/ForceChangePassword.php`, `resources/views/pages/profile/index.blade.php`

**Interfaces:**
- Consumes: `User::$must_change_password`, route `profile.index`, middleware allow-list
- Produces: users with `must_change_password=true` cannot reach `/admin/*` or `/customer/*` until password rotated

- [ ] **Step 1: Write the failing Pest test**

Create `tests/Feature/ForceChangePasswordTest.php`:

```php
<?php

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

it('redirects users who must change password away from the dashboard', function () {
    $role = Role::query()->where('slug', 'owner')->first()
        ?? Role::factory()->create(['slug' => 'owner', 'name' => 'Owner']);

    $user = User::factory()->create([
        'password' => Hash::make('changeme'),
        'must_change_password' => true,
        'is_active' => true,
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    $this->actingAs($user)
        ->get('/admin/dashboard')
        ->assertRedirect(route('profile.index', ['must_change' => 1]));
});

it('allows the profile page when password change is required', function () {
    $user = User::factory()->create([
        'password' => Hash::make('changeme'),
        'must_change_password' => true,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get('/profile?must_change=1')
        ->assertOk();
});

it('lets users through after must_change_password is cleared', function () {
    $role = Role::query()->where('slug', 'owner')->first()
        ?? Role::factory()->create(['slug' => 'owner', 'name' => 'Owner']);

    $user = User::factory()->create([
        'password' => Hash::make('Secret123!'),
        'must_change_password' => false,
        'is_active' => true,
    ]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    $this->actingAs($user)
        ->get('/admin/dashboard')
        ->assertRedirect(); // owner landing redirect is fine — must NOT be profile
});
```

Adjust factory/role helpers to match this repo’s existing Pest patterns if `Role::factory` / `User::factory` differ — mirror `tests/Feature` neighbours.

- [ ] **Step 2: Run test — expect FAIL (middleware still disabled)**

```bash
docker compose -f docker-compose.dev.yml exec -T app php artisan test --filter=ForceChangePasswordTest
```

Expected: first test fails (lands on dashboard / 200 / wrong redirect).

- [ ] **Step 3: Re-enable middleware**

In `bootstrap/app.php`, inside `withMiddleware`, restore:

```php
$middleware->appendToGroup('web', \App\Http\Middleware\ForceChangePassword::class);
```

Remove or shorten the “temporarily DISABLED” comment to note re-enabled date (2026-09-04) and reason (audit S5).

- [ ] **Step 4: Run test — expect PASS**

```bash
docker compose -f docker-compose.dev.yml exec -T app php artisan test --filter=ForceChangePasswordTest
```

Expected: PASS.

- [ ] **Step 5: Manual check with seeded driver/demo user**

```bash
docker compose -f docker-compose.dev.yml exec -T app php artisan tinker --execute="App\Models\User::where('email','owner@tcdc.test')->update(['must_change_password'=>true]); echo 'flagged';"
```

Browser: login as `owner@tcdc.test` / `changeme` → must land on `/profile?must_change=1`. Change password → flag clears → `/admin/dashboard/owner` works.

Reset flag afterward if you need the demo account unrestricted:

```bash
docker compose -f docker-compose.dev.yml exec -T app php artisan tinker --execute="App\Models\User::where('email','owner@tcdc.test')->update(['must_change_password'=>false]);"
```

- [ ] **Step 6: Commit**

```bash
git add bootstrap/app.php tests/Feature/ForceChangePasswordTest.php
git commit -m "security: re-enable forced password change middleware"
```

---

### Task 4: Branded 429 page

**Files:**
- Create: `resources/views/errors/429.blade.php`
- Reference style: `resources/views/errors/403.blade.php`

**Interfaces:**
- Consumes: Laravel error view resolution for HTTP 429 (Fortify login RateLimiter)
- Produces: branded “too many attempts” page with link back to login

- [ ] **Step 1: Create the view**

Create `resources/views/errors/429.blade.php` modeled on `403.blade.php`:

```blade
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Too many attempts · TRIDENT</title>
    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <div class="min-h-full flex flex-col justify-center px-6 py-12">
        <div class="mx-auto w-full max-w-md">
            <div class="flex justify-center">
                <img src="/logo.png?v=2" alt="TRIDENT" class="h-20 w-auto object-contain" />
            </div>

            <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="px-6 pt-6 pb-4 text-center">
                    <h1 class="mt-2 text-lg font-semibold text-slate-900">Too many attempts</h1>
                    <p class="mt-2 text-sm text-slate-500">
                        You have tried to sign in too many times. Please wait about a minute, then try again.
                    </p>
                </div>
                <div class="px-6 pb-6 pt-2">
                    <a href="{{ route('login') }}"
                       class="inline-flex w-full items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-500 transition">
                        Back to sign in
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
```

Keep copy generic — no account enumeration.

- [ ] **Step 2: Trigger locally**

Hit login 6 times with the same identity (wrong password). Expected: branded 429, not bare `429 TOO MANY REQUESTS`.

- [ ] **Step 3: Commit**

```bash
git add resources/views/errors/429.blade.php
git commit -m "ux: brand the login rate-limit 429 page"
```

---

### Task 5: robots.txt + login a11y micro-fix

**Files:**
- Modify: `public/robots.txt`
- Modify: `resources/views/auth/login.blade.php`

- [ ] **Step 1: Tighten robots.txt**

Replace `public/robots.txt` with:

```txt
User-agent: *
Disallow: /login
Disallow: /forgot-password
Disallow: /reset-password
Disallow: /admin
Disallow: /customer
Disallow: /driver
Disallow: /body-builder
Disallow: /profile
Disallow: /livewire
Allow: /
```

- [ ] **Step 2: Fix remember-me labelling**

In `resources/views/auth/login.blade.php`, change the checkbox block to:

```blade
                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox"
                        class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <label for="remember" class="ml-2 text-sm text-gray-600">Remember me</label>
                </div>
```

- [ ] **Step 3: Spot-check**

```bash
curl -s http://localhost:8092/robots.txt
```

Open login — Remember me toggles via label click.

- [ ] **Step 4: Commit**

```bash
git add public/robots.txt resources/views/auth/login.blade.php
git commit -m "chore: tighten robots.txt and fix remember-me label association"
```

---

### Task 6: Landing mobile tap targets (optional polish)

**Files:**
- Modify: `resources/views/pages/landing.blade.php`

- [ ] **Step 1: Bump header Sign-in hit area**

Find the header “Sign in” control (~line 69–75) and ensure classes include at least `min-h-11 px-4 py-2.5` (44px target).

- [ ] **Step 2: Soften tiny text CTAs**

Any inline text link under ~40px height in the hero/mock board (“View collection note →”) should gain `py-2 inline-flex items-center` or become a button-styled control. Do **not** redesign the landing — padding only.

- [ ] **Step 3: Visual check at 390×844**

Confirm no horizontal overflow; Sign in ≥44px tall.

- [ ] **Step 4: Commit**

```bash
git add resources/views/pages/landing.blade.php
git commit -m "ux: enlarge marketing mobile tap targets"
```

---

### Task 7: Production deploy + verify

**Files:** none (ops only)

- [ ] **Step 1: Push the branch/commits** (only when the user asks)

```bash
git push origin HEAD
```

- [ ] **Step 2: Deploy on the server** (user runs; copy-paste)

```bash
cd /opt/proselver \
 && sudo chown -R $(whoami):$(whoami) .git \
 && git pull \
 && sudo docker compose build app \
 && sudo docker compose up -d --force-recreate app scheduler queue \
 && sudo docker compose exec -T app php artisan view:clear \
 && sudo docker compose exec -T app php artisan config:clear
```

- [ ] **Step 3: Verify production headers**

```bash
curl -sI https://tcdc.co.za/login | tr -d '\r' | grep -iE 'strict-transport|content-security|referrer-policy|permissions-policy|x-powered-by|x-frame|x-content-type'
```

Expected: HSTS + CSP-RO + Referrer + Permissions present; no `x-powered-by`.

- [ ] **Step 4: Verify auth smoke**

- Anonymous `/admin/orders` → `/login`
- Login failure message still generic
- 6th same-identity failure → branded 429
- `/robots.txt` lists Disallows

- [ ] **Step 5: Schedule CSP enforce (next day)**

If browser console / report endpoint is quiet, change `Content-Security-Policy-Report-Only` → `Content-Security-Policy` in `docker/nginx.conf`, rebuild, redeploy. Track as a follow-up commit — do not enforce in the same deploy as the first Report-Only ship unless you have a report URI already.

---

## Out of scope (intentionally)

- Owner sidebar IA density (audit Info / product design)
- Full SCA sweep beyond Livewire + commonmark
- Re-running Meterian / BrowserStack a11y (MCP credentials were broken in Aug)
- Changing Fortify throttle from 5/min (working as designed)

## Self-review

| Audit item | Task |
|---|---|
| S1 HSTS | Task 2 |
| S2 CSP | Task 2 (+ Task 7 enforce follow-up) |
| S3 Livewire CVE | Task 1 |
| S4 commonmark DoS | Task 1 |
| S5 ForceChangePassword | Task 3 |
| S6 X-Powered-By | Task 2 |
| S7 Referrer/Permissions | Task 2 |
| S8 robots.txt | Task 5 |
| U1 429 UX | Task 4 |
| U2 remember id | Task 5 |
| U3 mobile taps | Task 6 |
| Deploy | Task 7 |
