# Proselver TOP — Transport Operations Platform

End-to-end booking, scheduling, execution & governance system for vehicle transport operations.

## Stack
- Laravel 12, PHP 8.3+
- PostgreSQL 16
- Redis (queues, cache, sessions)
- Livewire 4 + Volt (frontend)
- Tailwind CSS
- DomPDF (invoice generation)
- PWA (driver offline-first)

## Local Development (Docker Desktop)

The recommended way to run locally. Mirrors production exactly.

### Prerequisites
- Docker Desktop installed and running

### Start
```bash
docker compose up -d
```

First boot takes ~2 minutes (installs Composer + NPM deps, runs migrations, seeds DB).

### Access
| Service | URL |
|---------|-----|
| App | http://localhost:8090 |
| Vite HMR | http://localhost:5173 |
| PostgreSQL | localhost:5433 |
| Redis | localhost:6380 |

### Demo Login Credentials

| Role | Username | Password |
|------|----------|----------|
| Super Admin | admin | changeme |
| Ops Manager | ops | changeme |
| Dispatcher | dispatch | changeme |
| Accounts | accounts | changeme |
| Dealer Admin | dealer | changeme |
| Driver | driver | changeme |

### Common Commands
```bash
# View logs
docker logs -f proselver-app

# Run artisan commands
docker exec proselver-app php artisan tinker
docker exec proselver-app php artisan migrate

# Run tests
docker exec proselver-app php artisan test

# Stop everything
docker compose down

# Full rebuild
docker compose build --no-cache app && docker compose up -d

# Nuke DB and start fresh
docker compose down -v && docker compose up -d
```

## Local Development (Laragon / Manual)

### Prerequisites
- PHP 8.3+, Composer, Node.js 18+
- PostgreSQL, Redis

### Setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
npm run dev
```

### Run
```bash
php artisan serve
php artisan queue:work
php artisan schedule:work
npm run dev
```

## Production Deployment
See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for Docker deployment instructions.

Use `docker-compose.prod.yml` for production:
```bash
docker compose -f docker-compose.prod.yml up -d
```

## Documentation
- [Assumptions](docs/ASSUMPTIONS.md)
- [RBAC](docs/RBAC.md)
- [Data Model](docs/DATA_MODEL.md)
- [Workflows](docs/WORKFLOWS.md)
- [Deployment](docs/DEPLOYMENT.md)
- [Backups](docs/BACKUPS.md)
