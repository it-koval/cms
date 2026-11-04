#!/bin/sh
# Prepares the app on container start: dependencies, database wait, migrations, ITK CMS install.
# Set SKIP_ENTRYPOINT=1 to bypass (the worker and one-off commands do).
set -e

if [ "${SKIP_ENTRYPOINT:-0}" = "1" ] || [ "$1" != "php-fpm" ]; then
    exec "$@"
fi

# composer runs on the first start, and again when itk/cms is in composer.json but not installed (a vendor/ made on the host has no
# usable bundle: the path repository points to /itk) or when composer.json is newer than the installed set
if [ -f composer.json ] && { [ ! -f vendor/autoload_runtime.php ] \
    || { grep -q '"itk/cms"' composer.json && [ ! -e vendor/itk/cms ]; } \
    || [ composer.json -nt vendor/composer/installed.json ]; }; then
    echo "[entrypoint] composer install"
    # a site with itk/cms as a path repository has no usable lock until it is resolved against that path
    if [ -f composer.lock ] && grep -q '"name": "itk/cms"' composer.lock; then
        composer install --prefer-dist --no-progress --no-interaction
    else
        composer update --prefer-dist --no-progress --no-interaction
    fi
fi

# crittr is a site dependency (README, "Критический CSS"); the entrypoint installs it only when package.json already lists it
if [ -f package.json ] && [ ! -d node_modules/crittr ] && command -v npm >/dev/null 2>&1 && grep -q '"crittr"' package.json; then
    echo "[entrypoint] npm install"
    if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; else npm install --no-audit --no-fund; fi
fi

# the admin of the bundle is served from public/bundles/itkcms (copied, not symlinked: nginx sees only /app)
if [ ! -f public/bundles/itkcms/admin/index.html ] && [ -f vendor/autoload_runtime.php ]; then
    php bin/console assets:install public --no-interaction || echo "[entrypoint] assets:install failed, run it by hand" >&2
fi

echo "[entrypoint] waiting for database"
ATTEMPTS=60
until php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1; do
    ATTEMPTS=$((ATTEMPTS - 1))
    if [ "$ATTEMPTS" -le 0 ]; then
        echo "[entrypoint] database not reachable" >&2
        php bin/console dbal:run-sql "SELECT 1" || true
        break
    fi
    sleep 2
done

if [ "${AUTO_MIGRATE:-1}" = "1" ]; then
    # a failed migration or install stops the container: it must not take traffic with a half-made database
    if ! php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
        echo "[entrypoint] migrations failed, see output above; container stops" >&2
        exit 1
    fi
    # roles, menu areas from the schema, default settings, home page; the administrator is created when ADMIN_EMAIL and ADMIN_PASSWORD are set
    if ! php bin/console itk:install --no-interaction \
        ${SITE_NAME:+--site-name="$SITE_NAME"} ${SITE_DOMAIN:+--domain="$SITE_DOMAIN"} \
        ${ADMIN_EMAIL:+--email="$ADMIN_EMAIL"} ${ADMIN_PASSWORD:+--password="$ADMIN_PASSWORD"}; then
        echo "[entrypoint] itk:install failed, see output above; container stops" >&2
        exit 1
    fi
fi

exec "$@"
