#!/bin/sh
set -eu

cd /var/www/html
mkdir -p /data storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache
php docker/initialize.php
export APP_KEY="$(cat /data/app-key)"
php artisan migrate --force --no-interaction
if [ "${SEED_DEMO:-true}" = "true" ]; then
    php artisan db:seed --class=NetworkDemoSeeder --force --no-interaction
fi
chown -R www-data:www-data /data storage bootstrap/cache
chown www-data:www-data .env
chmod 640 .env
exec "$@"
