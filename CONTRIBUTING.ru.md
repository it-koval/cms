[English](CONTRIBUTING.md) | Русский

# Участие в разработке ITK CMS

Полезнее всего сообщения об ошибках с шагами воспроизведения, исправления с тестом и поправки в документации.

Соблюдайте [кодекс поведения](CODE_OF_CONDUCT.ru.md). Сообщайте о проблемах безопасности в закрытом порядке по правилам [SECURITY.ru.md](SECURITY.ru.md).

## Устройство репозитория

- `packages/cms` это Composer-пакет `itk/cms`: бандл Symfony, его тесты и собранная админка.
- `admin` содержит исходники админки (Vue 3 и Vite). Сборка попадает в `packages/cms/public/admin`.

## Настройка окружения

Установите Docker с Compose. Выполните команды настройки PHP из корня репозитория. Compose запускает PostgreSQL с pgvector и Mailpit.

```bash
test_compose() {
  docker compose --project-directory packages/cms \
    -f packages/cms/docker/compose.yaml -f packages/cms/tests/docker/compose.ci.yaml "$@"
}

export ITK_CI_UID="$(id -u)" ITK_CI_GID="$(id -g)"
test_compose up -d --wait database mailer
test_compose build php
test_compose run --rm --no-deps php composer install
```

## Запуск проверок

### PHP

Запустите проверки из корня репозитория с теми же настройками Compose.

```bash
test_compose run --rm --no-deps php composer test
test_compose run --rm --no-deps php composer stan
test_compose run --rm --no-deps php composer cs
```

`composer test` проверяет SQL, пересоздаёт тестовую базу и запускает PHPUnit. `composer stan` проверяет `src` и `tests` на уровне 6 PHPStan. `composer cs` проверяет форматирование.

Для одного класса сначала выполните `composer test:prepare` в сервисе PHP, затем `php vendor/bin/phpunit --filter ИмяКласса`. Для случайного порядка после подготовки выполните `composer test:run -- --order-by=random --random-order-seed=30301`. CI также использует seed `30302`.

Тесты из `tests/Core` используют сайт в `tests/App`. Для отдельного тестового стенда задайте своё имя проекта Compose. Если указываете собственный `DATABASE_URL`, сохраните в нём `serverVersion`. Для отдельного кэша задайте `ITK_TEST_CACHE_DIR`. Тесты подменяют Telegram и MAX. Почтовым тестам нужен `ITK_TEST_MAILPIT_URL`.

### Админка

Команды запускаются из `admin`:

```bash
docker compose run --rm node npm ci
docker compose run --rm node npm audit --include=dev
docker compose run --rm node npm audit --omit=dev --audit-level=high
docker compose run --rm node npm run lint
docker compose run --rm node npm run check:i18n  # каталоги интерфейса и кириллица в коде
docker compose run --rm node npm test
docker compose run --rm node npm run build        # записывает сборку в packages/cms/public/admin
```

`docker compose up node` запускает Vite на `http://127.0.0.1:5174/bundles/itkcms/admin/`. По умолчанию контейнер обращается к API сайта по адресу `host.docker.internal:8080`. Другой адрес задаётся переменной `ITK_SITE_URL`. Чтобы запустить админку без PHP, на выдуманных данных, выполните `docker compose run --rm --service-ports node npm run dev:mock -- --host 0.0.0.0`.

`admin/compose.yaml` монтирует корень репозитория в `/workspace` и хранит Linux-зависимости в отдельном named volume. Контейнер не использует и не перезаписывает `node_modules` хоста. `npm ci` ставит ровно то, что записано в lock-файле. Версия Node в `admin/compose.yaml` и `admin/.nvmrc` должна совпадать.

## Коммитьте собранную админку

`npm run build` создаёт `packages/cms/public/admin`, и этот каталог хранится в git, чтобы сайту не требовался Node. Если вы меняете что-то в `admin/`, пересоберите админку и закоммитьте сборку вместе с исходниками. CI пересобирает админку и падает, если результат отличается от закоммиченного. Не правьте собранные файлы вручную.

Сайт, который подключает бандл из локального клона (Composer-репозиторий `path` с `symlink`), увидит новую сборку после `bin/console assets:install`.

## Новые системные зависимости меняют комплект Docker

Возможность, которой нужна новая системная зависимость (пакет ОС, бинарник, расширение PHP, ещё один транспорт очереди или сервис), меняет `packages/cms/docker/` и раздел «Docker» в `packages/cms/README.md` в том же pull request.

Job `Docker kit` в CI собирает образ и проверяет:

- расширения PHP из `require` в `composer.json`;
- поддержку WebP в `gd` и `pcntl`;
- Node 22 и `crittr` с браузером для критического CSS;
- что команда воркера в `docker/compose.yaml` разбирает каждый транспорт, который бандл добавляет по умолчанию.

Проверку нового пакета ОС или бинарника добавьте в `packages/cms/tests/docker/smoke.sh`. Новый сервис и текст README проверяет ревью.

## Стиль кода

- Пишите так же, как код вокруг: имена, комментарии на английском и приёмы. Файлы PHP начинаются с `declare(strict_types=1);`. Классы объявляются `final`, если их не предполагается расширять.
- Стиль PHP проверяет PHP CS Fixer по `.php-cs-fixer.dist.php` в корне репозитория.
- Текст, который человек видит в админке, в письмах, в консоли и в ответах API, не пишется в коде строкой. Это ключ каталога на трёх языках, см. [Как добавить строку интерфейса](#как-добавить-строку-интерфейса). Не смешивайте языки на одном экране.
- [UPGRADE.ru.md](UPGRADE.ru.md) перечисляет, что бандл обещает сайтам. Не ломайте это без записи в журнале изменений.
- Не расширяйте изменение. Исправление одного не переписывает соседнее.

## Как добавить строку интерфейса

Интерфейс работает на английском, русском и казахском. Язык интерфейса не связан с языком содержимого сайта (`main_locale`).

1. Назовите ключ `область.ключ`: область (`pages`, `forms`, `content` и другие), точка и имя в snake_case. Одно целое предложение, один ключ. Не собирайте предложение из кусков и не вставляйте условие внутрь фразы: порядок слов в казахском другой, а род и падеж русского подстановкой не передать. Если нужны два варианта, заведите два ключа или ключ с параметром.
2. Добавьте ключ во все три каталога с одинаковыми параметрами `{name}`.
   - Админка (Vue): `admin/src/i18n/{en,ru,kk}/<область>.json`. Вызов `t('area.key', { name: x })` из `@/i18n/index.js`, в шаблонах тоже. Для числа вызывайте `t('area.key', { count: n })`. Значение в JSON тогда объект по категориям `Intl.PluralRules`: `one`, `few`, `many` и `other` для русского, `one` и `other` для английского, `other` для казахского. Даты, числа и сортировку форматируйте через `formatDate`, `formatNumber` и `collator`.
   - Бандл (PHP): `packages/cms/translations/itk_<область>.{en,ru,kk}.yaml`, значения в одинарных кавычках. Вызов `Itk\Cms\I18n\I18n::t('area.key', ['name' => $x])`, в Twig `itk_t('area.key', {name: x})`. Для множественного числа передайте параметр `count` и разделите формы через `|`: `{count} страница|{count} страницы|{count} страниц` для русского, `{count} page|{count} pages` для английского, `{count} бет|{count} бет` для казахского.
3. Не меняйте формулировки существующих русских строк: их сравнивают тесты. Английский текст: короткие подписи, заглавная только первая буква. Казахский: литературный язык интерфейса, кириллица. Держите те же термины, что в соседних строках каталога (бет, жоба, жариялау, өтінім, баптаулар).
4. Не переводите то, что уходит в код или в API: ключи, статусы, адреса, имена полей и значения по умолчанию в данных сайта. Данные сайта, схема сайта и подписи, которые задал разработчик сайта, остаются как есть.
5. Проверьте каталоги. `npm run check:i18n` (из `admin/`) сверяет каталоги и ищет кириллицу в коде вне комментариев. `composer test` включает `I18nCatalogTest` и `I18nCyrillicTest`. Чтобы намеренно оставить кириллицу в коде (например, название языка на нём самом), добавьте комментарий `i18n-ignore` в той же строке.

## Как добавить язык интерфейса

1. Добавьте язык в `I18n::LOCALES` (`packages/cms/src/I18n/I18n.php`), в `BUILT_IN` в `admin/src/i18n/index.js`, в `LOCALES` в `admin/scripts/check-i18n.mjs`, в `UI_LOCALES` в `admin/src/mock/handler.js` и в тип `locale` в `admin/types/itk.d.ts`.
2. Создайте `admin/src/i18n/<язык>/*.json` и `packages/cms/translations/itk_<область>.<язык>.yaml` с теми же ключами, что в `en`. Формы множественного числа задают `JS_PLURALS` и `PHP_PLURALS` в `admin/scripts/check-i18n.mjs` и `FORMS` в `packages/cms/tests/Core/I18nCatalogTest.php`.
3. Запустите `npm run check:i18n` (из `admin/`) и `composer test` (из `packages/cms`).

## Сначала тест

Напишите тест, убедитесь, что он падает, затем меняйте код. Исправление ошибки сопровождается тестом, который без исправления падает.

- Тесты бандла лежат в `packages/cms/tests/Core`. Тестовый сайт лежит в `packages/cms/tests/App`.
- Логику админки, которой не нужен DOM, проверяет Vitest в файле `*.test.js` рядом с кодом.

## Коммиты и pull request

- Одно логическое изменение на коммит. Короткая тема в повелительном наклонении (около 72 символов). Если причина неочевидна, объясните её в теле. Обязательного префикса нет.
- Для каждого изменения, которое заметит пользователь бандла, добавьте запись в раздел `## [Unreleased]` в начале [CHANGELOG.md](CHANGELOG.md). Если раздела нет, создайте его.
- Если изменение ломает публичный API, добавьте заметку в [UPGRADE.md](UPGRADE.md).
- Документация пишется на английском, русская копия лежит рядом с каждым файлом (`README.ru.md`, `CHANGELOG.ru.md` и так далее). Меняя документ, обновите его русскую копию в том же pull request.
- Перед тем как открыть pull request, запустите проверки выше. В описании укажите, что изменилось, почему и как вы это проверили.
