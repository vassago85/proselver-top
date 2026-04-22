#!/bin/sh
set -e

echo "Ensuring storage directories exist..."
mkdir -p /var/www/html/storage/framework/cache/data
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# Defensive: if public/hot ever slips into the image (bind mount, stray
# commit, manual exec running `npm run dev`), strip it now. Its presence
# forces @vite to resolve every asset to the host's localhost:5173 dev
# server, breaking all CSS/JS in production.
if [ -f /var/www/html/public/hot ]; then
    echo "Removing stray public/hot (Vite dev-server marker)..."
    rm -f /var/www/html/public/hot
fi

echo "Setting permissions..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Wait for Postgres so migrations don't fail on a cold start if the DB is
# still coming up (service_healthy handles most cases; this is belt-and-braces).
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-5432}"
echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
for i in $(seq 1 30); do
    if nc -z "$DB_HOST" "$DB_PORT" 2>/dev/null; then
        echo "Database reachable."
        break
    fi
    if [ "$i" = "30" ]; then
        echo "Database not reachable after 30s; aborting boot." >&2
        exit 1
    fi
    sleep 1
done

echo "Running migrations..."
php artisan migrate --force

echo "Clearing caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear

echo "Caching config and routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Publishing Livewire assets..."
php artisan livewire:publish --assets 2>/dev/null || true

echo "Creating storage link..."
php artisan storage:link --force 2>/dev/null || true

echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
