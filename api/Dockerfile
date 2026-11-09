FROM node:24-alpine AS assets

WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-fpm-alpine AS php-base

RUN apk add --no-cache ca-certificates-bundle gettext-envsubst libpq libxml2 libsodium oniguruma nginx supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev libxml2-dev libsodium-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_pgsql sodium xml xmlwriter \
    && apk del .build-deps \
    && php -r '$required = ["mbstring", "pdo_pgsql", "sodium", "xmlwriter"]; $missing = array_diff($required, get_loaded_extensions()); if ($missing) { fwrite(STDERR, "Missing required PHP extensions: ".implode(", ", $missing).PHP_EOL); exit(1); }' \
    && test -r /etc/ssl/cert.pem

FROM php-base AS vendor

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

FROM php-base AS app

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /src/public/build ./public/build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --no-dev --no-interaction --no-scripts --classmap-authoritative \
    && php artisan package:discover --ansi \
    && rm /usr/local/bin/composer \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chown -R www-data:www-data /tmp/nginx \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chmod 0755 docker/entrypoint.sh

ENV PORT=10000
EXPOSE 10000
USER www-data
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
