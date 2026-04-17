# Deployment Guide

## Docker Deployment

### Prerequisites
- Docker and Docker Compose installed on host
- Domain configured to point to server IP
- Reverse proxy (Nginx Proxy Manager) configured

### Environment Variables
Copy `.env.example` to `.env` and configure:
- `APP_KEY` — generate with `php artisan key:generate --show`
- `DB_PASSWORD` — PostgreSQL password
- `R2_*` — Cloudflare R2 operational bucket credentials
- `R2_BACKUP_*` — Separate R2 backup bucket credentials
- `MAIL_*` — SMTP or Mailgun credentials
- `SUPER_ADMIN_USERNAME`, `SUPER_ADMIN_PASSWORD` — initial admin credentials

### Deploy
```bash
cd /opt/proselver
git pull origin main
docker compose build --no-cache app
docker compose up -d --force-recreate app scheduler queue
```

### First Run
After first deployment, seed the database:
```bash
docker exec proselver-app php artisan db:seed
```

### Port Allocation
Default: port 8090. Configure via `APP_PORT` in `.env`.

### Driver PWA — session lifetime

The driver PWA is same-origin and shares the Laravel session cookie. To avoid
drivers being logged out mid-day (especially when the phone is offline and the
app can't refresh the session), set a long session lifetime in `.env`:

```
SESSION_LIFETIME=43200   # 30 days, in minutes
SESSION_EXPIRE_ON_CLOSE=false
```

Notes:
- The login page already supports "Remember me", which issues a persistent
  cookie beyond `SESSION_LIFETIME` once a browser sends it back.
- Upload requests are queued in IndexedDB and retried with exponential backoff
  (`resources/js/driver/sync.js`); they only go to the wire once the driver is
  online **and** still has a valid session cookie. A 401/419 response from an
  offline-then-online flush stops the run and surfaces "please sign in again"
  to the driver. A 30-day session comfortably covers weekly shift patterns.
- The driver service worker (`public/driver-sw.js`) is scoped to `/driver/`
  and does NOT cache authenticated HTML, so changing `SESSION_LIFETIME` takes
  effect on the next request without needing an SW version bump.

## Local Development (Laragon)

### Setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# Configure .env for local PostgreSQL
php artisan migrate
php artisan db:seed
npm run dev
```

### Run locally
```bash
# Terminal 1: Vite dev server
npm run dev

# Terminal 2: Laravel server
php artisan serve

# Terminal 3: Queue worker
php artisan queue:work

# Terminal 4: Scheduler
php artisan schedule:work
```

### Demo Credentials
| Role | Username | Password |
|------|----------|----------|
| Super Admin | admin | changeme |
| Ops Manager | ops | changeme |
| Dispatcher | dispatch | changeme |
| Accounts | accounts | changeme |
| Dealer Admin | dealer | changeme |
| Driver | driver | changeme |
