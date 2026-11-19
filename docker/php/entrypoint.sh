#!/bin/sh
# Same image runs web, worker, scheduler and one-off jobs (D-09, D-27).
set -e

# Caches are built at start, not build time: env is only known here. Dev/test read .env live.
case "${APP_ENV:-}" in
  "" | local | testing) ;;
  *) php artisan optimize ;;
esac

# No command = web process: Octane worker mode, or plain FrankenPHP for debugging state issues.
if [ $# -eq 0 ]; then
  if [ "${APP_SERVER_MODE:-octane}" = classic ]; then
    set -- frankenphp php-server --listen :8000 --root public/
  else
    # --log-level makes Octane pass every stderr line through raw. Without it Octane re-parses lines as
    # Caddy JSON and prints `msg`, so the app's Monolog JSON (`message`) became "unknown error" (D-40).
    set -- php artisan octane:frankenphp --host=0.0.0.0 --port=8000 \
      --workers="${OCTANE_WORKERS:-auto}" --max-requests="${OCTANE_MAX_REQUESTS:-500}" \
      --log-level="${CADDY_LOG_LEVEL:-WARN}"
  fi
fi

exec "$@"
