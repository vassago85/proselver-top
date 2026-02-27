#!/bin/sh
set -e

cd /var/www/html

echo "==> Installing Composer dependencies..."
composer install --no-interaction --prefer-dist

echo "==> Installing NPM dependencies..."
npm install

echo "==> Waiting for database..."
until pg_isready -h db -p 5432 -U proselver -q 2>/dev/null; do
    echo "    Postgres not ready, retrying in 2s..."
    sleep 2
done

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Seeding database..."
php artisan db:seed --force 2>/dev/null || true

echo "==> Creating storage link..."
php artisan storage:link --force 2>/dev/null || true

echo "==> Setting permissions..."
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache

echo ""
echo "============================================"
echo "  Proselver TOP is starting up!"
echo "  App:  http://localhost:8090"
echo "  Vite: http://localhost:5173"
echo "============================================"
echo ""

exec /usr/bin/supervisord -c /etc/supervisord.conf
