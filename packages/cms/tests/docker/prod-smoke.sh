#!/usr/bin/env bash
# Check the production kit with the fixture populated by prepare-site.sh on a CI runner.
set -euo pipefail

cd "${1:?Pass the directory of the clean Symfony site}"
export COMPOSE_PROJECT_NAME="itk-ci-prod"
export DEPLOY_IMAGE_TAG=smoke
export DEPLOY_PORT=18080
export DEPLOY_DB_PASSWORD=itk-ci-db
export DATABASE_URL='postgresql://postgres:itk-ci-db@database:5432/app?serverVersion=17&charset=utf8'
export APP_SECRET=itk-ci-secret-for-temporary-site
export MAILER_DSN=null://null

compose() {
    docker compose --env-file /dev/null -f compose.prod.yaml "$@"
}

cleanup() {
    local result=$?
    if [ "$result" -ne 0 ]; then
        compose ps
        compose logs --no-color
    fi
    # The GitHub-hosted runner is ephemeral, including the named test volumes.
    compose down --remove-orphans
}

trap cleanup EXIT

compose config --quiet
compose build php nginx
compose up -d --wait --wait-timeout 120 database
compose run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction
compose run --rm --no-deps php php bin/console itk:install --no-interaction
compose run --rm --no-deps php php bin/console lint:container
compose run --rm --no-deps php php bin/console app:verify-install
compose run --rm --no-deps php php bin/console itk:doctor --ci
compose up -d --no-build --wait --wait-timeout 120 php nginx worker
compose exec -T nginx nginx -t

url="http://127.0.0.1:$DEPLOY_PORT"
[[ "$(curl --fail --silent --show-error --max-time 10 "$url/healthz")" == '{"status":"ok"}' ]]
[[ "$(curl --fail --silent --show-error --max-time 10 "$url/")" == *'ITK CMS install smoke'* ]]
curl --fail --silent --show-error --max-time 10 "$url/bundles/itkcms/admin/index.html" > /dev/null

for attempt in {1..20}; do
    if [[ "$(curl --fail --silent --max-time 3 "$url/uploads/worker-probe.txt")" == 'worker received the queued message' ]]; then
        exit 0
    fi
    sleep 1
done

printf 'The worker did not consume the probe message.\n' >&2
exit 1
