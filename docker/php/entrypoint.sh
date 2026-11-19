#!/bin/sh
# Build caches at start, not at build time: env is only known here (D-09).
set -e
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec "$@"
