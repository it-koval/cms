# syntax=docker/dockerfile:1.7
# ITK CMS Docker kit. The same file builds from a site root (context ".", the kit lives in docker/) and from packages/cms (context ".",
# CI): in both the COPY paths below start with docker/. Build: docker build -f docker/Dockerfile --target php_dev .
# CRITICAL_CSS=1 adds Node 22 and Chromium for the critical CSS generator (README, "Docker").
ARG PHP_VERSION=8.4
ARG CRITICAL_CSS=0

# ---------- base ----------
FROM php:${PHP_VERSION}-fpm-bookworm AS php_base

# Refuse a cached or overridden image missing the October 2026 critical security fixes.
RUN php -r '$v = PHP_VERSION; exit((version_compare($v, "8.3.36", ">=") && version_compare($v, "8.4.0-dev", "<")) || (version_compare($v, "8.4.27", ">=") && version_compare($v, "8.5.0-dev", "<")) || (version_compare($v, "8.5.12", ">=") && version_compare($v, "8.6.0-dev", "<")) || version_compare($v, "8.6.0", ">=") ? 0 : 1);'

COPY --from=ghcr.io/mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# ctype, dom, json, mbstring and sodium are built into the official image; the rest is installed here.
# gd comes with WebP, JPEG and FreeType; exif is optional for the bundle (EXIF rotation of JPEG), intl/zip/opcache/apcu are what Symfony sites use;
# pcntl lets messenger:consume finish the job in flight on SIGTERM.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip file acl libfcgi-bin postgresql-client; \
    install-php-extensions intl pdo_pgsql zip exif gd opcache apcu pcntl; \
    rm -rf /var/lib/apt/lists/*

# Match the container user to the host user (Linux). Harmless on macOS.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data \
 && mkdir -p /var/www/.composer /var/www/.npm && chown -R www-data:www-data /var/www

ENV COMPOSER_HOME=/var/www/.composer \
    PHP_INI_SCAN_DIR=":/usr/local/etc/php/app.conf.d"

COPY docker/php/conf.d/10-app.ini /usr/local/etc/php/app.conf.d/10-app.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

WORKDIR /app

HEALTHCHECK --interval=10s --timeout=3s --start-period=60s --retries=10 \
  CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]

# ---------- critical CSS: off ----------
FROM php_base AS php_features_0

# ---------- critical CSS: on ----------
# Node comes from the official image (no third-party apt repository); both images are Debian bookworm, so the binary runs as is.
# The browser is the Debian package: the one puppeteer downloads lands in the home directory of whoever ran npm install and is built
# for the OS of that machine (a macOS build on a Mac host does not run here). PUPPETEER_SKIP_DOWNLOAD stops that download, and
# PUPPETEER_EXECUTABLE_PATH tells puppeteer where the browser is.
FROM php_base AS php_features_1
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN set -eux; \
    ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm; \
    ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx; \
    apt-get update; \
    apt-get install -y --no-install-recommends chromium fonts-liberation fonts-dejavu-core; \
    rm -rf /var/lib/apt/lists/*
ENV PUPPETEER_SKIP_DOWNLOAD=1 \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

FROM php_features_${CRITICAL_CSS} AS php_features

# ---------- dev ----------
FROM php_features AS php_dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
USER www-data

# ---------- prod ----------
# The site is copied in. docker/Dockerfile.dockerignore keeps .git, vendor, node_modules, var, .env.local, .env.*.local, .env.local.php and the
# secrets vault decrypt key out of it. The committed .env is copied: keep only defaults in it.
FROM php_features AS php_prod
ARG CRITICAL_CSS
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
ENV APP_ENV=prod
COPY --chown=www-data:www-data . .
RUN set -eux; \
    composer install --no-dev --prefer-dist --no-progress --no-scripts --classmap-authoritative; \
    if [ "${CRITICAL_CSS}" = "1" ]; then npm ci --omit=dev; fi; \
    mkdir -p var public/uploads; \
    chown -R www-data:www-data var public/uploads
USER www-data
