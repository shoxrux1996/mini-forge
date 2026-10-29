# syntax=docker/dockerfile:1.7
# Default production image for a Laravel app, used when the repository has
# no Dockerfile of its own. FrankenPHP serves HTTP on :8080; the same image
# runs the queue worker and scheduler. Mini Forge copies the files this
# refers to into .mini-forge/ in the build context.

ARG PHP_VERSION=8.4

FROM dunglas/frankenphp:1-php${PHP_VERSION}-bookworm AS base
# Headless Chromium for PDF rendering (spatie/laravel-pdf, Browsershot).
ARG INSTALL_CHROMIUM=false
RUN install-php-extensions pdo_pgsql redis intl zip bcmath gd exif pcntl opcache sockets \
 && apt-get update \
 && apt-get install -y --no-install-recommends curl \
 && if [ "$INSTALL_CHROMIUM" = "true" ]; then \
      apt-get install -y --no-install-recommends chromium-headless-shell fonts-dejavu-core fonts-liberation; \
    fi \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

FROM base AS vendor
# composer.lock* : an app without a lock file still builds (more slowly).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress

# Filament themes import CSS from vendor/, so assets build after Composer.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; \
    elif [ -f package.json ]; then npm install --no-audit --no-fund; fi
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN if [ -f package.json ]; then npm run build; fi && mkdir -p public/build

FROM base AS app
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev \
 && php artisan storage:link \
 && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
 && mkdir -p /data/caddy /config/caddy \
 && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy

COPY .mini-forge/Caddyfile /etc/frankenphp/Caddyfile.mini-forge
COPY .mini-forge/php.ini /usr/local/etc/php/conf.d/zz-mini-forge.ini
COPY --chmod=755 .mini-forge/entrypoint.sh /usr/local/bin/mini-forge-entrypoint

USER www-data
EXPOSE 8080
ENTRYPOINT ["mini-forge-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile.mini-forge"]
