# Servana app image (PHP-FPM 8.3). One image serves app / worker / scheduler with
# different commands (Plan §26.1). Multi-stage: `dev` (tooling + dev deps) and
# `prod` (optimized, no dev deps, opcache preload).

# ---------- base: PHP + extensions + composer + non-root user ----------
FROM php:8.3-fpm-alpine AS base

# System libraries needed to build the PHP extensions below.
RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        postgresql-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        gd \
        bcmath \
        pcntl \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    # Drop build toolchain to keep the image lean; keep runtime libs.
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Composer (pinned major) from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP configuration.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-servana.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# The bind-mounted repo is owned by the host user, not the container's uid 1000;
# mark it safe so git-based tooling (Pint/Larastan file discovery) is quiet.
RUN git config --system --add safe.directory /var/www/html

# Non-root runtime user (CLAUDE.md §6; Plan §26.1). HOME is /home/servana — NOT
# the project dir — so composer/psysh dotfiles never leak into the bind-mounted
# repo. uid/gid pinned to 1000.
RUN addgroup -g 1000 servana \
    && adduser -G servana -u 1000 -h /home/servana -s /bin/bash -D servana \
    && mkdir -p /var/www/html /home/servana \
    && chown -R servana:servana /var/www/html /var/local /home/servana

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# ---------- dev: includes dev dependencies; source is bind-mounted ----------
FROM base AS dev

ENV APP_ENV=local
# Revalidate opcache on every request so code edits are picked up live.
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# Empty disables preloading: dev bind-mounts the source, so preloaded (and therefore frozen)
# classes would mask live edits. Phase 24, PH24-OPCACHE-001.
ENV PHP_OPCACHE_PRELOAD=""

# Seed vendor/ into the image so the named volume is populated on first run.
COPY --chown=servana:servana composer.json composer.lock ./
USER servana
RUN composer install --no-interaction --no-scripts --no-progress --prefer-dist

USER root
ENTRYPOINT ["entrypoint"]
USER servana
CMD ["php-fpm", "-F"]

# ---------- SPA manifest source (Phase UI-02) ----------
# The SPA bundle itself is built into, and served from, the NGINX image. The application
# image needs exactly one file from that build: the Vite manifest, so SpaShellController can
# name the fingerprinted entry chunk in the HTML shell.
#
# `public/spa` is in .dockerignore and the prod topology shares no volume between app and
# nginx, so without this stage the manifest is simply absent from the app image and every
# browser route 500s — which is what a UI-02 rehearsal against the built images proved.
# Only the manifest is copied; the chunks stay owned by the edge.
FROM node:20-alpine AS spa-manifest
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---------- prod: optimized, no dev deps, opcache preload on ----------
FROM base AS prod

ENV APP_ENV=production
ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
# Phase 24 (PH24-OPCACHE-001): the image is immutable and validate_timestamps is 0, so preloading
# the application + framework class files at pool start is safe and removes their parse cost from
# the first request that touches them. The script only ever calls opcache_compile_file(), so no
# top-level code runs at boot. Readable by the non-root `servana` runtime via the COPY --chown below.
ENV PHP_OPCACHE_PRELOAD=/var/www/html/docker/php/preload.php

COPY --chown=servana:servana . /var/www/html
# Phase UI-02: the Vite manifest only — the bundle is served by the nginx image.
COPY --from=spa-manifest --chown=servana:servana /app/public/spa/.vite/manifest.json /var/www/html/public/spa/.vite/manifest.json
RUN composer install --no-interaction --no-dev --optimize-autoloader --no-progress --prefer-dist \
    && chown -R servana:servana /var/www/html/storage /var/www/html/bootstrap/cache

USER root
ENTRYPOINT ["entrypoint"]
USER servana
CMD ["php-fpm", "-F"]
