English | [Русский](DEPLOY.ru.md)

# Deploy and roll back a site

Run the commands from the site root. Rehearse the update and restore on a staging copy before changing production.

## Prepare

1. Keep the site’s Composer lock file and Docker kit with the release. Copy the kit into `docker/` and copy `vendor/itk/cms/docker/compose.prod.yaml` to `compose.prod.yaml` in the site root. Review changes to the kit when upgrading the bundle.
2. Set `COMPOSE_PROJECT_NAME` once for this site. Keep it on updates and rollbacks so Compose uses the same PostgreSQL and upload volumes. Set `DEPLOY_PORT` for the loopback nginx listener and `DEPLOY_IMAGE_TAG` to the release commit or another unique identifier. `DEPLOY_DB_NAME` defaults to `app`.
3. Load `DEPLOY_DB_PASSWORD` from the deployment secret store. Pass `APP_SECRET`, `DATABASE_URL`, and `MAILER_DSN` through the environment or Symfony vault. For a vault, supply `SYMFONY_DECRYPTION_SECRET`. Use `database` as the database host and the name from `DEPLOY_DB_NAME`. URL-encode the password in `DATABASE_URL`. Keep `APP_SECRET` stable across releases. Set `DEFAULT_URI` to the public HTTPS URL. Configure the trusted hosts and reverse proxy in Symfony. PHP sees requests from the Docker bridge gateway, not from 127.0.0.1, so set `framework.trusted_proxies` to the Compose network subnet or to `private_ranges`; otherwise every visitor shares one IP for the form and login limits and HTTPS is not detected. If the site uses other bundles with public assets, add `php bin/console assets:install public --no-interaction` to the site build stage: the image copies only the ITK CMS assets. See [Runtime settings](../packages/cms/README.md#security-securityyaml).
4. Point the HTTPS reverse proxy at `127.0.0.1:$DEPLOY_PORT`. Preserve the original host and forward the scheme. PostgreSQL and PHP-FPM stay on the Compose network. Set `BACKUP_DIR` to a new protected directory outside the build context for each update.

## First deployment

```sh
docker compose -f compose.prod.yaml config --quiet
docker compose -f compose.prod.yaml build php nginx
docker compose -f compose.prod.yaml up -d --wait database
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console itk:install --email=you@example.com --domain=example.com
docker compose -f compose.prod.yaml up -d --wait --no-build php nginx worker
```

Enter the administrator password when prompted. Sign in and publish the home page. Run the checks below before opening traffic.

## Update

If you are switching an existing 0.2.2 site to this kit, back up the old environment and transfer its uploads before starting nginx. Follow [First adoption of the production kit](../UPGRADE.md#first-adoption-of-the-production-kit).

1. Keep the previous image tag, the Compose file and the runtime settings. Set the new `DEPLOY_IMAGE_TAG`. Build both images with `docker compose -f compose.prod.yaml build php nginx`.
2. Put the site in maintenance at the HTTPS proxy and block form intake. Keep the currently running workers (0.2.2 when upgrading to 0.3.0) processing the queue until it drains, including delayed retries. Use `docker compose -f compose.prod.yaml exec -T php php bin/console messenger:stats` (or the same command in your existing setup) to check the queue and confirm **0** with a check that includes delayed and in-flight messages. For Doctrine, count all rows in `messenger_messages` (or the configured table), grouped by `queue_name`. Resolve any failed messages before proceeding. Then stop `nginx`, `php`, `worker` and any other database/upload writers before backup.
3. Back up both volumes. Check that the archives are readable before proceeding.

   ```sh
   docker compose -f compose.prod.yaml exec -T database sh -c 'pg_dump -U postgres -Fc "$POSTGRES_DB"' > "$BACKUP_DIR/before-deploy.dump"
   docker compose -f compose.prod.yaml run --rm -T --no-deps --entrypoint tar php -C /app/public/uploads -cf - . > "$BACKUP_DIR/before-deploy-uploads.tar"
   docker compose -f compose.prod.yaml exec -T database pg_restore --list < "$BACKUP_DIR/before-deploy.dump" > /dev/null
   tar -tf "$BACKUP_DIR/before-deploy-uploads.tar" > /dev/null
   ```

4. Run the migration command from the first deployment. Run `itk:install --no-interaction` in the same way to refresh CMS defaults. Start `php`, `nginx` and `worker` with `up -d --wait --no-build`.
5. Verify the new release before lifting maintenance.

## Verify

```sh
curl --fail --show-error https://example.com/healthz
docker compose -f compose.prod.yaml exec -T php php bin/console itk:doctor
docker compose -f compose.prod.yaml ps php nginx worker
docker compose -f compose.prod.yaml logs --tail=50 worker
```

Expect a healthy endpoint and a running worker. Open the public home page and sign in at the admin URL. Confirm an uploaded image loads and a test message leaves the queue. Use a restricted proxy path during maintenance if needed.

## Roll back

The 0.3.0 migrations are incompatible with the 0.2.2 code, and some are irreversible. A rollback from 0.3.0 to 0.2.2 requires restoring the pre-upgrade database and uploads and switching to the matching code and configuration. If this was the first adoption of the kit, follow [UPGRADE → Rollback to 0.2.2](../UPGRADE.md#rollback-to-022) to restore the original deployment. The steps below apply to a site that already used this production kit before the update.

1. Put the site in maintenance and stop `nginx`, `php` and `worker`. Set `DEPLOY_IMAGE_TAG` to the previous value. Use the Compose file and runtime settings that match that release.
2. For an incompatible release pair such as 0.3.0 → 0.2.2, take a separate backup of the current database and uploads first. For release pairs with compatible schema and data, proceed to step 3.

   ```sh
   docker compose -f compose.prod.yaml exec -T database sh -c 'pg_dump -U postgres -Fc "$POSTGRES_DB"' > "$BACKUP_DIR/before-rollback.dump"
   docker compose -f compose.prod.yaml run --rm -T --no-deps --entrypoint tar php -C /app/public/uploads -cf - . > "$BACKUP_DIR/before-rollback-uploads.tar"
   ```

   Write this copy to the `before-rollback` files. Do not overwrite `before-deploy.dump` and `before-deploy-uploads.tar`: the restore below reads them. Then restore the pre-update database and uploads together. This replaces data changed after the backup.

   ```sh
   docker compose -f compose.prod.yaml exec -T database pg_restore --clean --if-exists --create --exit-on-error -U postgres -d postgres < "$BACKUP_DIR/before-deploy.dump"
   docker compose -f compose.prod.yaml run --rm -T --no-deps --entrypoint sh php -ec 'cd /app/public/uploads && find . -mindepth 1 -delete && tar -xf -' < "$BACKUP_DIR/before-deploy-uploads.tar"
   ```

3. For 0.3.0 → 0.2.2, validate the restored queue before starting any 0.2.2 worker, including delayed retries and failed messages (`messenger_messages` for Doctrine, or the site's external transport). Match each envelope to the delivery outcomes in the 0.3.0 backup `before-rollback.dump` using the request ID, channel, any job ID and, for mail, the recipient. For a resend without a job ID, verify delivery of that attempt in the provider records. Delete individual envelopes only after confirming that 0.3.0 delivered them. Keep uncertain envelopes out of consumption until their delivery is reconciled, using provider evidence where necessary. Restoring old queue data can otherwise repeat deliveries made after the backup.
4. Run `docker compose -f compose.prod.yaml up -d --wait --no-build php nginx worker`. Verify the site again before lifting maintenance.
