# AGENTS.md

## Cursor Cloud specific instructions

TRIDENT is a single Laravel 12 / PHP 8.3 monolith (vehicle-transport logistics
platform). It uses PostgreSQL 16 (primary DB), Redis (cache/session/queue),
Livewire + Volt + Tailwind on the frontend, and Vite for assets. Standard
commands are documented in `README.md`; this section only covers the
non-obvious cloud-VM specifics.

### Runtime layout (native, not Docker)
The cloud VM runs the app natively rather than via `docker compose` (the VM has
no Docker). System packages (PHP 8.3 from `ppa:ondrej/php`, PostgreSQL 16,
Redis, Composer) are baked into the VM snapshot. The `.env` is already created
and configured for native local dev (`DB_HOST=127.0.0.1`, `DB_PORT=5432`,
`REDIS_HOST=127.0.0.1`, DB name/user/pass all `proselver`, `MAIL_MAILER=log`).

### Starting services (do this at the start of a session)
PostgreSQL and Redis are NOT auto-started on boot. Start them before running the
app, tests-that-need-services, migrations, or seeders:

```bash
sudo pg_ctlcluster 16 main start   # PostgreSQL 16 (data persists in the snapshot)
sudo redis-server --daemonize yes  # Redis
```

Run the dev app (two long-running processes — use tmux):

```bash
npm run dev                                        # Vite dev server (writes public/hot; port 5173)
php artisan serve --host=0.0.0.0 --port=8000       # app on http://localhost:8000
```

### Demo login
Seeded users (see `README.md`) all use password `changeme`. Super admin is
username `admin`. The DB is already migrated + seeded in the snapshot; only run
`php artisan migrate --force` / `php artisan db:seed --force` if you need a
fresh/rebuilt database.

### Lint / test / build
- Lint: `./vendor/bin/pint --test` (report) or `./vendor/bin/pint` (fix). The
  repo currently has many pre-existing Pint style findings in `tests/` — don't
  treat those as regressions you introduced.
- Tests: `php artisan test` (Pest/PHPUnit). Tests use an in-memory SQLite DB
  (`phpunit.xml`), so they do NOT need Postgres/Redis running — but they DO need
  `public/build/manifest.json` to exist, because feature tests render Blade
  views that use the `@vite` directive. If you see `Vite manifest not found`
  failures, run `npm run build` once to generate the manifest.
  Note: `tests/Feature/PettyCash/PettyCashTransferTest.php` has one
  occasionally-flaky assertion under full-suite ordering; it passes in isolation
  and on re-run.
- Build: `npm run build` (only needed to (re)generate the asset manifest for the
  test suite; the dev server uses `public/hot` instead).

### Gotchas
- Composer's post-autoload-dump runs `php artisan package:discover`, which needs
  the view cache dir to exist. If you ever hit "Please provide a valid cache
  path", create the framework dirs:
  `mkdir -p storage/framework/{views,cache/data,sessions} bootstrap/cache`.
- All external integrations (Google Maps, TFN fuel API, TrackSolid GPS,
  Cloudflare R2 / S3, real mail) are optional and default to local/demo/log
  fallbacks — no keys are required to run or test core flows.
