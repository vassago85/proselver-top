#!/bin/sh
set -e

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

echo "Setting permissions..."
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
