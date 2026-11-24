[English](DEPLOY.md) | Русский

# Выкладка и откат сайта

Выполняйте команды из корня сайта. Перед изменениями в продакшене проверьте обновление и восстановление на тестовой копии.

## Подготовка

1. Храните Composer lock-файл и комплект Docker вместе с релизом сайта. Скопируйте комплект в `docker/`, а `vendor/itk/cms/docker/compose.prod.yaml` в корень сайта как `compose.prod.yaml`. При обновлении бандла проверьте изменения комплекта.
2. Задайте `COMPOSE_PROJECT_NAME` для сайта. Сохраняйте его при обновлении и откате, чтобы Compose использовал те же тома базы и загрузок. В `DEPLOY_PORT` укажите локальный порт nginx. В `DEPLOY_IMAGE_TAG` укажите коммит релиза или другой уникальный идентификатор. По умолчанию `DEPLOY_DB_NAME` равен `app`.
3. Загрузите `DEPLOY_DB_PASSWORD` из хранилища секретов. Передайте `APP_SECRET`, `DATABASE_URL` и `MAILER_DSN` через окружение или Symfony Vault. Для Vault передайте `SYMFONY_DECRYPTION_SECRET`. Укажите хост базы `database` и имя из `DEPLOY_DB_NAME`. Закодируйте пароль в `DATABASE_URL` по правилам URL. Сохраняйте `APP_SECRET` между релизами. Укажите публичный HTTPS-адрес в `DEFAULT_URI`. Настройте доверенные хосты и прокси в Symfony. PHP видит запросы с адреса шлюза сети Docker, а не с 127.0.0.1, поэтому укажите в `framework.trusted_proxies` подсеть сети Compose или `private_ranges`. Иначе все посетители получат один IP для лимитов форм и входа, а HTTPS не определится. Если сайт использует другие бандлы с публичными файлами, добавьте в сборку сайта `php bin/console assets:install public --no-interaction`: образ копирует только файлы ITK CMS. См. [настройки безопасности](../packages/cms/README.ru.md#безопасность-securityyaml).
4. Направьте HTTPS-прокси на `127.0.0.1:$DEPLOY_PORT`. Передавайте исходный хост и схему. PostgreSQL и PHP-FPM работают в сети Compose. Для каждого обновления укажите в `BACKUP_DIR` новый защищённый каталог вне контекста сборки.

## Первая выкладка

```sh
docker compose -f compose.prod.yaml config --quiet
docker compose -f compose.prod.yaml build php nginx
docker compose -f compose.prod.yaml up -d --wait database
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console doctrine:migrations:migrate --no-interaction
docker compose -f compose.prod.yaml run --rm --no-deps php php bin/console itk:install --email=you@example.com --domain=example.com
docker compose -f compose.prod.yaml up -d --wait --no-build php nginx worker
```

Введите пароль администратора по запросу. Войдите в админку и опубликуйте главную страницу. Выполните проверки ниже до открытия сайта.

## Обновление

1. Сохраните прежний тег образов, файл Compose и настройки окружения. Задайте новый `DEPLOY_IMAGE_TAG`. Соберите оба образа командой `docker compose -f compose.prod.yaml build php nginx`.
2. Включите режим обслуживания на HTTPS-прокси. Остановите `nginx`, `php` и `worker`, чтобы база и загрузки не менялись при копировании.
3. Сделайте резервную копию обоих томов. Проверьте, что архивы читаются.

   ```sh
   docker compose -f compose.prod.yaml exec -T database sh -c 'pg_dump -U postgres -Fc "$POSTGRES_DB"' > "$BACKUP_DIR/before-deploy.dump"
   docker compose -f compose.prod.yaml run --rm -T --no-deps --entrypoint tar php -C /app/public/uploads -cf - . > "$BACKUP_DIR/before-deploy-uploads.tar"
   docker compose -f compose.prod.yaml exec -T database pg_restore --list < "$BACKUP_DIR/before-deploy.dump" > /dev/null
   tar -tf "$BACKUP_DIR/before-deploy-uploads.tar" > /dev/null
   ```

4. Запустите команду миграции из первой выкладки. Тем же способом запустите `itk:install --no-interaction`, чтобы обновить базовые настройки CMS. Запустите `php`, `nginx` и `worker` командой `up -d --wait --no-build`.
5. Проверьте релиз до отключения режима обслуживания.

## Проверка

```sh
curl --fail --show-error https://example.com/healthz
docker compose -f compose.prod.yaml exec -T php php bin/console itk:doctor
docker compose -f compose.prod.yaml ps php nginx worker
docker compose -f compose.prod.yaml logs --tail=50 worker
```

Убедитесь, что `/healthz` отвечает успешно и воркер работает. Откройте главную страницу и войдите в админку. Проверьте, что загруженное изображение открывается, а тестовое сообщение уходит из очереди. При необходимости разрешите проверяющим доступ через прокси на время обслуживания.

## Откат

1. Включите режим обслуживания. Остановите `nginx`, `php` и `worker`. Верните прежний `DEPLOY_IMAGE_TAG`. Используйте файл Compose и настройки окружения прежнего релиза.
2. Если старый код работает с обновлённой базой, пропустите восстановление. Если миграции несовместимы, сперва сохраните текущие данные.

   ```sh
   docker compose -f compose.prod.yaml exec -T database sh -c 'pg_dump -U postgres -Fc "$POSTGRES_DB"' > "$BACKUP_DIR/before-rollback.dump"
   docker compose -f compose.prod.yaml run --rm -T --no-deps --entrypoint tar php -C /app/public/uploads -cf - . > "$BACKUP_DIR/before-rollback-uploads.tar"
   ```

   Пишите эту копию в файлы `before-rollback`. Не перезаписывайте `before-deploy.dump` и `before-deploy-uploads.tar`: восстановление ниже читает их. Затем восстановите базу и загрузки из одной резервной копии. Это заменит данные, изменённые после копирования.

   ```sh
   docker compose -f compose.prod.yaml exec -T database pg_restore --clean --if-exists --create --exit-on-error -U postgres -d postgres < "$BACKUP_DIR/before-deploy.dump"
   docker compose -f compose.prod.yaml run --rm -T --no-deps --entrypoint sh php -ec 'cd /app/public/uploads && find . -mindepth 1 -delete && tar -xf -' < "$BACKUP_DIR/before-deploy-uploads.tar"
   ```

3. Выполните `docker compose -f compose.prod.yaml up -d --wait --no-build php nginx worker`. Повторите проверки перед отключением режима обслуживания.
