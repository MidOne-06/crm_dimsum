#!/usr/bin/env sh
set -eu

mkdir -p public storage/app storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
cp -a /opt/public/. public/
chown -R www-data:www-data storage bootstrap/cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
    php artisan storage:link --force || true
    php artisan optimize:clear
fi

if [ "${RUN_AS_WWW_DATA:-false}" = "true" ]; then
    exec su-exec www-data:www-data "$@"
fi

exec "$@"
