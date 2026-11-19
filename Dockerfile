# syntax=docker/dockerfile:1
# Targets: php-base, app-dev, vendor, app-prod, nginx (SYSTEM_DESIGN §3, D-09)

FROM php:8.4-fpm-alpine AS php-base
RUN apk add --no-cache icu-libs libpq libzip fcgi \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath pcntl \
 && pecl install redis-6.2.0 && docker-php-ext-enable redis \
 && rm -rf /tmp/pear && apk del .build-deps \
 && php -m | grep -qi 'zend opcache'
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/zz-fpm.conf /usr/local/etc/php-fpm.d/zz-fpm.conf
WORKDIR /var/www/html
STOPSIGNAL SIGQUIT
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=3s --retries=3 \
  CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong || exit 1
CMD ["php-fpm", "-F"]

FROM php-base AS app-dev
ARG UID=1000
ARG GID=1000
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Remap www-data to the host user so bind-mounted files stay writable.
RUN apk add --no-cache shadow git unzip \
 && groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data \
 && chown www-data:www-data /var/www/html
ENV COMPOSER_HOME=/tmp/composer
USER www-data

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM php-base AS app-prod
ENV APP_ENV=production APP_DEBUG=false
RUN printf 'opcache.validate_timestamps=0\n' > /usr/local/etc/php/conf.d/zz-prod.ini
COPY --chown=www-data:www-data --from=vendor /app /var/www/html
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN rm -rf tests .env && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.27-alpine AS nginx
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
COPY public/ /var/www/html/public/
ENV PHP_FPM_HOST=app:9000 NGINX_RESOLVER=127.0.0.11
