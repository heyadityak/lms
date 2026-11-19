# syntax=docker/dockerfile:1
# Targets: app-base (FrankenPHP), app-dev, app-prod, nginx (SYSTEM_DESIGN §3, D-09, D-27)

FROM dunglas/frankenphp:1.13.5-php8.4-alpine AS app-base
ARG UID=1000
ARG GID=1000
RUN install-php-extensions pdo_pgsql redis intl zip opcache pcntl bcmath \
 && apk add --no-cache curl \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 # Reuse an existing group when GID is taken (e.g. macOS gid 20 = dialout).
 && (getent group "$GID" >/dev/null || addgroup -g "$GID" app) \
 && adduser -D -u "$UID" -G "$(getent group "$GID" | cut -d: -f1)" app \
 && mkdir -p /app && chown -R app /app /data /config
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/entrypoint
# SERVER_NAME=:8000 turns off Caddy auto-HTTPS; nginx (or the platform LB) terminates TLS.
ENV SERVER_NAME=:8000 OCTANE_SERVER=frankenphp APP_SERVER_MODE=octane OCTANE_WORKERS=auto OCTANE_MAX_REQUESTS=500
WORKDIR /app
USER app
EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8000/healthz || exit 1
ENTRYPOINT ["entrypoint"]

# Dev: code is bind-mounted at /app; composer available for installs.
FROM app-base AS app-dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/tmp/composer

FROM app-base AS app-prod
ENV APP_ENV=production APP_DEBUG=false
USER root
RUN printf 'opcache.validate_timestamps=0\n' > "$PHP_INI_DIR/conf.d/zz-prod.ini"
USER app
COPY --chown=app composer.json composer.lock ./
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer install --no-dev -o --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY --chown=app . .
RUN --mount=type=bind,from=composer:2,source=/usr/bin/composer,target=/usr/bin/composer \
    composer dump-autoload --no-dev -o --no-interaction \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

FROM nginx:1.30-alpine AS nginx
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY public/ /usr/share/nginx/html/
RUN rm -f /usr/share/nginx/html/index.php /usr/share/nginx/html/frankenphp-worker.php
ENV APP_UPSTREAM=app:8000 NGINX_RESOLVER=127.0.0.11
