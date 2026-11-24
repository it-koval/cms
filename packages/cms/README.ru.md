[English](README.md) | Русский

# ITK CMS (`itk/cms`)

`itk/cms` это Symfony-бандл, который добавляет сайту систему управления содержимым. В нём есть страницы из секций, шаблоны страниц, коллекции, меню, настройки, медиатека, SEO, переадресации, заявки с уведомлениями в Telegram, MAX и на почту, пользователи и роли, JSON API, админка на Vue и сервер MCP для ИИ-ассистентов.

Сайт описывает свою структуру в PHP (файл `config/itk/schema.php` или классы-ресурсы) и выводит её своими Twig-шаблонами. Бандл не знает ничего о конкретном сайте. Собранная админка лежит в пакете, поэтому сайту не нужен Node.

Версия 0.2.2, лицензия MIT. Исходный код: [github.com/it-koval/cms](https://github.com/it-koval/cms).

## Содержание

- [Требования](#требования)
- [Установка](#установка)
- [Основные понятия](#основные-понятия)
- [Вёрстка сайта](#вёрстка-сайта)
- [Поля](#поля)
- [Классы-ресурсы](#классы-ресурсы)
- [Админка](#админка)
- [SEO](#seo)
- [Заявки и уведомления](#заявки-и-уведомления)
- [База знаний](#база-знаний)
- [Производительность](#производительность)
- [MCP: ИИ-ассистенты](#mcp-ии-ассистенты)
- [Состояние сайта и itk:doctor](#состояние-сайта-и-itkdoctor)
- [Консольные команды](#консольные-команды)
- [Справочник конфигурации](#справочник-конфигурации)
- [Расширения и генераторы](#расширения-и-генераторы)
- [Docker](#docker)
- [ИИ-агент в проекте сайта](#ии-агент-в-проекте-сайта)
- [Публичный API и совместимость](#публичный-api-и-совместимость)
- [Разработка и тесты бандла](#разработка-и-тесты-бандла)
- [Другие документы](#другие-документы)

## Требования

| Что | Версия |
|---|---|
| PHP | 8.3 или новее. Расширения `ctype`, `dom`, `gd` с WebP, `json`, `mbstring`, `pdo_pgsql`, `sodium`. Необязательные: `exif` (поворот JPEG по EXIF) и `intl` (кириллические домены в кэше страниц) |
| Symfony | 7.4 или 8 (Symfony 8 требует PHP 8.4) |
| Doctrine | ORM 3, DBAL 4 |
| PostgreSQL | 16 или новее с расширением pgvector 0.8 или новее |
| Node | 22 или новее, только для необязательного критического CSS. Админка поставляется собранной. Для сборки из исходников нужен Node 24 (см. [CONTRIBUTING.ru.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.ru.md)) |

Composer требует только PHP 8.3. Экран «Состояние сайта» предупреждает, если патч-версия PHP ниже версии с известными исправлениями CVE.

**pgvector обязателен.** Миграция ядра выполняет `CREATE EXTENSION IF NOT EXISTS vector` и останавливается с объяснением, если расширения нет или его версия ниже 0.8. Образ `pgvector/pgvector:pg17` содержит расширение. На другом сервере его ставят по [инструкции pgvector](https://github.com/pgvector/pgvector#installation).

**Поддерживается только PostgreSQL.** MySQL, MariaDB и SQLite не поддерживаются. На другой базе и на PostgreSQL ниже 16 миграция ядра останавливается и ничего не создаёт. Создайте базу с кодировкой `UTF8` и UTF-8-локалью: поиск без учёта регистра опирается на `lower()`, которая правильно складывает кириллицу только в такой локали.

## Установка

### Быстрый старт

Команды ниже ставят бандл в новый проект Symfony 7.4. Нужны PHP с расширениями из раздела «Требования», Composer и PostgreSQL с pgvector.

1. Создайте проект и поставьте пакет:

   ```bash
   composer create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   composer config extra.symfony.docker false
   composer require itk/cms
   ```

   Symfony Flex впишет бандл в `config/bundles.php` и поставит обязательные зависимости: Doctrine, Messenger, Mailer, Security, Twig. Строка `extra.symfony.docker false` не даёт Flex добавлять свои Docker-файлы. Готовый Docker-комплект лежит в пакете, см. раздел [Docker](#docker).

2. Задайте базу и секрет в `.env.local`:

   ```dotenv
   DATABASE_URL="postgresql://user:password@127.0.0.1:5432/mysite?serverVersion=17&charset=utf8"
   APP_SECRET=a-long-random-string
   ```

3. Подключите маршруты в `config/routes/itk.yaml`:

   ```yaml
   itk_admin:
       resource: '@ItkCmsBundle/config/routes/admin.php'
   itk_site:
       resource: '@ItkCmsBundle/config/routes/site.php'
   ```

4. Замените `config/packages/security.yaml` файлом из раздела [Безопасность](#безопасность-securityyaml).

5. Задайте язык содержимого в `config/packages/itk_cms.yaml`. По умолчанию `ru`. Язык админки задаётся отдельно (раздел [Язык админки](#язык-админки)).

   ```yaml
   itk_cms:
       main_locale: en
   ```

6. Создайте таблицы и первого администратора. Сначала миграции, потом `itk:install`. Команда спросит пароль.

   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   php bin/console assets:install
   ```

7. Запустите сервер и воркер очереди:

   ```bash
   symfony server:start
   php bin/console messenger:consume async critical_css knowledge_base
   ```

   Без воркера не уходят письма и сообщения в мессенджеры. Встроенный сервер PHP (`php -S`) не передаёт в `index.php` адрес с расширением, если файла нет. Тогда копии картинок `/uploads/media/_v/…webp` отвечают 404, пока их не создал воркер. Чтобы такие адреса доходили до PHP, запускайте `php -S 127.0.0.1:8000 -t public router.php` с таким файлом в корне сайта:

   ```php
   <?php // router.php: существующий файл отдаётся как есть, остальное идёт в index.php
   $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
   if (is_string($path) && is_file(__DIR__ . '/public' . $path)) {
       return false;
   }
   $_SERVER['SCRIPT_NAME'] = '/index.php';
   require __DIR__ . '/public/index.php';
   ```

8. Откройте `/admin/login` и войдите. `itk:install` создаёт главную страницу черновиком, поэтому `/` отвечает 404, пока её не опубликуют («Страницы» → «Главная» → «Опубликовать»). Проверьте установку командой `php bin/console itk:doctor`.

Дальше опишите структуру сайта в `config/itk/schema.php` (раздел [Схема сайта](#схема-сайта)) и положите шаблоны в `templates/`. Заготовки классов и шаблонов делают команды `itk:make:*` (раздел [Генераторы](#генераторы-кода)).

### Установка в существующий проект

`composer require itk/cms` ставит обязательные пакеты. Остальное ставится по желанию:

| Пакет | Зачем |
|---|---|
| `symfony/notifier`, `symfony/telegram-notifier`, `symfony/http-client` | Заявки в Telegram |
| `yuri-kovalev/max-bot-symfony-bundle` | Заявки в MAX |
| `symfony/http-client` | Проверка обновлений, IndexNow, Яндекс Вебмастер, база знаний |
| `monolog/monolog` 3.5 или новее | Прячет токен бота из журнала, даёт журналу MCP свой канал |

В проекте без Symfony Flex добавьте в `config/bundles.php` строку `Itk\Cms\ItkCmsBundle::class => ['all' => true]`. Там же должны быть FrameworkBundle, TwigBundle, SecurityBundle, DoctrineBundle и DoctrineMigrationsBundle.

Маршруты подключаются так же, как в быстром старте. `site.php` объявляет публичные маршруты: `POST /request/{form}`, `/sitemap.xml`, `/robots.txt`, `/llms.txt`, файл ключа IndexNow, копии картинок, `POST /mcp`, `/healthz` и маршрут страниц с приоритетом -1000. Любой маршрут сайта поэтому важнее маршрута страниц.

#### Безопасность (`security.yaml`)

```yaml
security:
    password_hashers:
        Itk\Cms\Entity\User: auto
    providers:
        itk_users:
            id: Itk\Cms\Security\AdminUserProvider
    firewalls:
        dev:
            pattern: ^/(_profiler(/|$)|(_wdt|assets|build)/)
            security: false
        itk_mcp:
            pattern: ^/mcp$
            stateless: true
            provider: itk_users
            user_checker: Itk\Cms\Security\UserChecker
            custom_authenticators:
                - Itk\Cms\Mcp\ApiTokenAuthenticator
        itk_admin:
            pattern: ^/admin(/|$)
            provider: itk_users
            user_checker: Itk\Cms\Security\UserChecker
            form_login:
                login_path: itk_admin_login
                check_path: itk_admin_login
                enable_csrf: true
                default_target_path: itk_admin_app
            login_throttling:
                max_attempts: 5
                interval: '15 minutes'
            logout:
                path: itk_admin_logout
                target: itk_admin_login
                enable_csrf: true
    access_control:
        - { path: ^/mcp$, roles: ROLE_ITK_USER }
        - { path: '^/admin/(login|forgot-password|reset-password)(/|$)', roles: PUBLIC_ACCESS }
        - { path: '^/admin(/|$)', roles: ROLE_ITK_USER }
```

- Файрвол `itk_mcp` обязателен. Без него включённый MCP отвечает 500 с кодом `mcp_firewall_not_configured`, а `itk:doctor` и «Состояние сайта» предупреждают об этом заранее.
- `(/|$)` в конце шаблона не пускает под правило другие адреса вроде `/administrator`.
- `login_throttling` ограничивает подбор пароля. `enable_csrf` у выхода не даёт чужой странице разлогинить администратора.
- Если админка стоит не на `/admin`, поменяйте `^/admin` в `pattern` файрвола и в обеих строках `access_control`. Бандл этот файл не меняет. **Без этих правил вход в админку не откроется, а действия коллекций (`POST {admin}/api/resources/{коллекция}/actions/{действие}`) будут выполняться без проверки входа.** Пример: при `itk_cms.admin_path: /cp` пишите `^/cp`, при импорте маршрутов с `prefix: /cp` пишите `^/cp/admin`.
- `APP_SECRET` в боевом окружении должен быть уникальным и непустым. От него зависят CSRF-токен админки, шифрование секретов (секреты двухэтапного входа, токены ботов, пароль SMTP, токен Яндекс Вебмастера), ключ IndexNow и хеши адресов в заявках. Flex кладёт в `.env` пустую строку `APP_SECRET=`, а рабочее значение для разработки в `.env.dev`. С пустым секретом `/admin` отвечает 500 с текстом `APP_SECRET is empty`, а `itk:install` печатает предупреждение. Как держать его в хранилище секретов Symfony, описано в разделе [Секреты в продакшене](#секреты-в-продакшене-symfony-vault).
- За прокси или CDN задайте `framework.trusted_proxies`. Иначе все посетители окажутся «с одного адреса» для лимитов форм и входа.
- В боевом окружении задайте `framework.trusted_hosts` или опцию `itk_cms.public_url`. От них зависят ссылки в письмах и кэш страниц.

#### Секреты в продакшене (Symfony vault)

Бандл читает настройки через `%env()%`, поэтому значение может прийти и из окружения, и из [хранилища секретов Symfony](https://symfony.com/doc/current/configuration/secrets.html). Бандлу для этого ничего не нужно. Какие переменные класть в хранилище и как ключ расшифровки попадает на сервер, решает сайт.

- Обычно сайт держит в хранилище `APP_SECRET`, `DATABASE_URL` и `MAILER_DSN`, а также `TELEGRAM_DSN`, если Telegram настроен на сервере. Токен бота, введённый в «Настройки → Боты», главнее `TELEGRAM_DSN`, и тогда переменная не нужна. Токены ботов, пароль SMTP и токен Яндекс Вебмастера из админки хранятся в базе, зашифрованные через `APP_SECRET`. В хранилище они не кладутся.
- Ключи окружения создаёт `php bin/console secrets:generate-keys --env=prod`, значения добавляет `php bin/console secrets:set DATABASE_URL --env=prod`. Закоммитьте `config/secrets/prod/` без приватного ключа `prod.decrypt.private.php`. Flex исключает из git только ключ `prod`. Для другого окружения, например `stage`, добавьте в `.gitignore` строку `*.decrypt.private.php`. Комплект Docker не пускает приватный ключ в образ.
- На сервер приватный ключ передают в переменной `SYMFONY_DECRYPTION_SECRET`. Её значение даёт `php -r 'echo base64_encode(require "config/secrets/prod/prod.decrypt.private.php");'`.
- Настоящая переменная окружения и непустая строка в `.env` или `.env.*.local` главнее хранилища. Пустая строка, например `APP_SECRET=`, которую Flex кладёт в `.env`, хранилище не перекрывает, и Symfony тогда читает его.
- Если в хранилище нет `APP_SECRET`, Symfony выводит его из ключа расшифровки. Сайт работает, но `APP_SECRET` тогда меняется вместе с ключом. После `secrets:generate-keys --rotate` токены ботов, пароль SMTP, токен Вебмастера и секреты двухэтапного входа перестают читаться. Чтобы `APP_SECRET` не зависел от ключа, положите его в хранилище до первого запуска командой `php bin/console secrets:set APP_SECRET --random=32 --env=prod`. Переход с выведенного значения на сохранённое позже так же меняет `APP_SECRET`.
- `php bin/console secrets:list --env=prod` показывает имена в хранилище. «Состояние сайта» и `itk:doctor` предупреждают, если `APP_SECRET` пустой.

#### Очередь Messenger

Бандл направляет свои сообщения в три транспорта:

| Транспорт | Сообщения |
|---|---|
| `async` | `Request\SendRequestNotification` (заявка в Telegram или MAX), `Mail\SendMail` и `Security\RequestPasswordLink` (письма), `Media\GenerateImageVariants` (копии картинок), `IndexNow\Model\PingIndexNow` |
| `critical_css` | `CriticalCss\Model\GenerateCriticalCss` |
| `knowledge_base` | `KnowledgeBase\Model\SyncKnowledge` |

Если сайт не объявил эти транспорты, бандл добавляет их сам на `doctrine://default` (таблица `messenger_messages` той же базы), а также транспорт ошибок `failed` с `failure_transport: failed`. Транспорт, который сайт объявил сам, и его `failure_transport` бандл не трогает. Для боевого сайта обычно задают повторы:

```yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy: { max_retries: 5, delay: 60000, multiplier: 5, max_delay: 36000000 }
            failed: 'doctrine://default?queue_name=failed&auto_setup=0'
```

Не ставьте `sync://` вместо `async`. Тогда письмо «Забыли пароль?» собиралось бы прямо в запросе, и время ответа выдавало бы, есть ли такой адрес.

Воркер запускают под supervisor или systemd: `php bin/console messenger:consume async critical_css knowledge_base`. Без воркера сайт и админка работают. Заявки сохраняются, страницы отдаются, копию картинки делает маршрут при первом запросе. Не уходят только письма и сообщения в мессенджеры. Они ждут в `messenger_messages`, и «Состояние сайта» показывает, сколько их. На хостинге без фоновых процессов запускайте воркер из cron раз в минуту:

```
* * * * * cd /path/to/site && php bin/console messenger:consume async critical_css knowledge_base --time-limit=55 --memory-limit=128M
```

#### Конфигурация, миграции и первый запуск

1. Настройте бандл в `config/packages/itk_cms.yaml`. Все опции необязательны, полный список в разделе [Справочник конфигурации](#справочник-конфигурации).
2. Выполните `php bin/console doctrine:migrations:migrate`. Бандл сам подключает свои миграции `Itk\Cms\Migrations`.
3. Выполните `php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com`. Команду можно запускать повторно. Она создаёт встроенные роли, области меню из схемы, настройки по умолчанию, таблицу очереди (для транспортов, которые умеют подготовить себя, например `doctrine://`), главную страницу и первого администратора. Пароль команда спрашивает. Его можно передать `--password` или переменной `ITK_ADMIN_PASSWORD`. `--name` задаёт имя администратора, `--no-home` не создаёт главную.
4. Выполните `php bin/console assets:install`. Файлы админки станут доступны как `/bundles/itkcms/admin/`. Flex делает это сам при `composer install` и `composer update`.
5. Только после `itk:install` запускайте `doctrine:schema:validate` и `doctrine:migrations:diff`. Таблицу `messenger_messages` создаёт `itk:install`. До этого шага `validate` покажет расхождение, а `diff` запишет создание этой таблицы в миграцию сайта.

### Миграции сайта

Миграции бандла (`Itk\Cms\Migrations`) и сайта выполняются по дате в имени класса (`Version20261030120000` значит 2026-10-30 12:00:00). При равной дате первым идёт пространство имён по алфавиту. Этот порядок бандл задаёт своим компаратором версий в `doctrine_migrations.services`. Компаратор, заданный сайтом, его заменяет.

Пространство имён миграций сайта задаёт `config/packages/doctrine_migrations.yaml`. В новом проекте это `App\Migrations` в `src/Migrations/`, в более старых `DoctrineMigrations` в `migrations/`.

- Бандл ставит свой путь миграций после путей сайта. Поэтому `doctrine:migrations:diff` и `:generate` без `--namespace` пишут новую миграцию в каталог сайта, а не в `vendor`. Сайт с несколькими путями выбирает нужный через `--namespace`. Свой путь для `Itk\Cms\Migrations` сайт не задаёт.
- Миграцию сайта, которая трогает данные бандла, называйте текущей датой (так делает `diff`). Тогда она выполнится после миграций бандла, которые были до неё.
- Миграция, которая трогает таблицы бандла, не должна падать на пустой базе. Проверяйте таблицу прямым запросом:

  ```php
  $this->skipIf(null === $this->connection->fetchOne("SELECT to_regclass('itk_setting')"), 'itk_setting is not created yet');
  ```

  `$schema->hasTable()` здесь не подходит: при фильтре из следующего пункта он всегда отвечает `false`.

- Закройте таблицы `itk_*` от `doctrine:migrations:diff` сайта. Иначе любая разница между версией бандла и базой попадёт в миграцию сайта. Таблицы бандла меняют только миграции бандла.

  ```yaml
  # config/packages/doctrine.yaml
  doctrine:
      dbal:
          schema_filter: '~^(?!itk_)~'
  ```

  Если у сайта есть свои таблицы с префиксом `itk_`, уточните выражение. Фильтр действует только на `diff`. `doctrine:schema:update` и `doctrine:schema:validate` таблицы `itk_*` всё равно видят. Если в миграциях сайта нельзя заменить `hasTable()` на `to_regclass`, оставьте `schema_filter` пустым и фильтруйте при создании: `doctrine:migrations:diff --filter-expression='~^(?!itk_)~'`.

## Основные понятия

### Схема сайта

Структуру сайта описывает `config/itk/schema.php` (путь меняет опция `schema_file`) или классы-ресурсы (раздел [Классы-ресурсы](#классы-ресурсы)). Оба способа попадают в одну схему.

```php
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Schema\SectionType;

return static function (SchemaBuilder $s): void {
    $s->design(headlineMarks: ['gradient']);
    $s->menuArea('header', 'Header menu', depth: 2);
    $s->layout(top: ['header'], bottom: ['footer']);

    $s->section('hero', 'Hero')->group(SectionType::FIRST_SCREEN)->short('Hero')->tone('dark')->silhouette('hero')->about('Top of the page')->fields(
        Field::headline('headline', 'Headline')->max(48)->required(),
        Field::description('description', 'Description')->max(90),
        Field::link('buttonLink', 'Where the button leads'),
        Field::image('image', 'Photo on the right'),
    );

    $s->template('catalog', 'Catalog')->single()->topLevel()->sections('all')->listing(of: ['product'], label: 'Products');
    $s->template('product', 'Product')->parents('catalog')->leaf()->shortcut('Products')->card(image: 'img', title: 'title', text: 'desc')->fields(/* … */);

    $s->collection('ambassadors', 'Ambassadors')->view('cards')->sortable()->titleField('name')->fields(/* … */);
    $s->settings('contacts', 'Contacts')->fields(Field::text('phone', 'Phone')->required());
    $s->form('call', 'Call request')->fields(Field::text('phone', 'Phone')->required())->telegram('Request #{id}: {phone}');
};
```

| Вызов | Что описывает |
|---|---|
| `design(headlineMarks: [...])` | Оформление, которое умеет дизайн сайта (пометки в заголовках) |
| `menuArea(key, title, depth:)` | Область меню. `depth: 2` разрешает пункт и один уровень вложенных |
| `layout(top:, bottom:)` | Области меню в макете. По умолчанию `header` сверху, остальные снизу |
| `section(key, title)` | Тип секции: `group()`, `short()`, `tone()`, `silhouette()`, `about()`, `h1()`, `template()`, `fields()` |
| `template(key, title)` | Шаблон страницы: `single()`, `root()`, `topLevel()`, `parents()`, `anyParent()`, `leaf()`, `sections()`, `listing()`, `shortcut()`, `card()`, `h1()`, `areas()`, `about()`, `fields()` |
| `collection(key, title)` | Коллекция записей, раздел [Коллекции](#коллекции) |
| `settings(key, title)` | Группа настроек, раздел [Настройки](#настройки) |
| `form(key, title)` | Форма сайта, раздел [Приём заявок](#приём-заявок) |
| `withoutBlog()` | Сайт без блога |

**Проверка схемы.** `Schema::validate()` возвращает список ошибок на языке админки. Она находит повторный ключ поля (в секции, шаблоне, коллекции с её вкладками, форме, повторителе), повторный ключ настройки в двух группах, неверный ключ, `parents()` и `listing(of:)` с несуществующим шаблоном, форму без полей, `titleField()` на несуществующее поле, зарезервированный ключ поля `version` у коллекции и область меню, описанную дважды. Ключ состоит из латинских букв, цифр, `_` и `-` и начинается с буквы или цифры. В debug схема проверяется при загрузке, и ошибка бросает исключение со всем списком. В prod проверки на каждый запрос нет.

`php bin/console itk:schema:lint` показывает те же ошибки и проверяет шаблоны Twig. Секция без `sections/<ключ>.html.twig` (или своего `template()`) считается ошибкой. Шаблон страницы без `pages/<ключ>.html.twig` даёт предупреждение: такие страницы выводит шаблон `default`. Код выхода 1 при ошибке, предупреждения его не меняют.

### Страницы, шаблоны и секции

Страница состоит из полей своего шаблона и списка секций. Шаблон задаёт, где страница может стоять в дереве и какие поля у неё есть. Секция это блок страницы со своими полями и своим Twig-шаблоном `templates/sections/<ключ>.html.twig`.

Шаблоны ядра: `home`, `default`, `blog`, `blog_category`, `blog_article`. Сайт меняет шаблон ядра повторным `$s->template('blog_article')`. `$s->withoutBlog()` в начале `schema.php` или опция `itk_cms.blog: false` убирает три шаблона блога и ссылку «Статьи блога» в меню админки. Шаблон, который ссылается на убранный `blog` в `parents()` или `listing(of:)`, `itk:schema:lint` покажет ошибкой.

**Заголовок H1.** Первая видимая секция группы `SectionType::FIRST_SCREEN` получает в шаблоне переменную `is_h1`. H1 этой секции это поле из `->h1('поле')` или первое поле `headline`. Группа это строка, переданная в `group()`, и админка показывает её как есть. Для группы первого экрана используйте `SectionType::FIRST_SCREEN`. Эту группу и группу по умолчанию админка показывает на языке интерфейса. Шаблон страницы с `->h1('поле')` владеет H1 сам. Подпись «Заголовок страницы (H1)» получает только это поле. Если H1-поле имеет тип `headline`, название страницы равно его первой строке простым текстом.

**Языки содержимого.** Задайте основной язык содержимого в `main_locale`, а список языков для редакторов в `locales`. См. разделы [Настройка языков содержимого](#настройка-языков-содержимого) и [Язык админки](#язык-админки).

### Коллекции

Коллекция это список записей с полями: отзывы, туры, сотрудники. Записи публикуются так же, как страницы. Параметры коллекции:

| Метод | Что делает |
|---|---|
| `fields(...)`, `tab(key, label, ...fields)` | Поля и вкладки формы записи |
| `titleField()`, `thumbField()`, `subField()`, `toggleField()` | Поле-заголовок, миниатюра, вторая строка и переключатель в списке |
| `view('table'\|'cards')`, `views([...])` | Вид списка, раздел [Виды списка](#виды-списка) |
| `sortable()` | Порядок списка задают вручную, и он же действует на сайте |
| `defaultSort([...])`, `perPage(int)` | Сортировка по умолчанию и размер страницы списка (50) |
| `filters(...)`, `actions(...)`, `metrics(...)` | Фильтры, действия и метрики |
| `tree()` | Записи вкладываются друг в друга |
| `singleton('main')` | Коллекция из одной записи с этим id. До явного сохранения чтение возвращает начальные значения |
| `view('matrix')`, `matrix([...])` | Табличный редактор одной записи: `colFields`, `rowKinds`, `rowLabel` |
| `singular()`, `newLabel()`, `icon()`, `describe()`, `emptyState()` | Подписи и значок в админке |
| `menu(...)`, `visibleWhen(fn)` | Место в меню админки и видимость, раздел [Меню админки](#меню-админки) |

Для колонки канбана используйте поле вроде `stage`. Поля коллекций не могут использовать ключи метаданных из `Collection::RESERVED_KEYS`. Админка, PHP, MCP и импорт коллекций проверяют значения одинаково. Свой тип поля может реализовать `Itk\Cms\Field\ValueValidatingFieldInterface` и вернуть переведённые ошибки из `validationErrors()`.

### Меню

Области меню объявляет `menuArea()`. Пункты правят в «Настройки → Меню» (право `set:menus`). Пункт ведёт на страницу или на адрес. Пункты перетаскиваются за значок слева, глубину ограничивает `depth` области. В шаблоне меню выводит `menu('header')`. В меню попадают только опубликованные страницы.

### Настройки

Группа настроек это форма в «Настройках» со своими полями. Ключи настроек общие для всех групп. Встроенные группы:

| Группа | Поля |
|---|---|
| `site` («Сайт») | `siteName`, `domain` (адрес сайта), поля компании для schema.org: `organizationType`, `organizationName`, `organizationAddress`, `phone`, `email`, `organizationLogo`, `organizationLinks`, `schemaOrgOff` |
| `analytics` («Аналитика и вебмастера») | `yandexMetrikaId`, `yandexMetrikaWebvisor`, `googleAnalyticsId`, `yandexVerification`, `googleVerification`, `indexNow` |

Сайт дополняет встроенную группу вызовом `$s->settings('site')->fields(...)`. Поле сайта с ключом встроенного поля заменяет поле ядра, в какой бы группе сайт его ни объявил.

В `domain` укажите хост или адрес HTTP(S). Путь, параметры и фрагмент недопустимы. Для карты сайта, IndexNow и Вебмастера достаточно `itk_cms.public_url`. Международные имена хостов в ссылках записываются в ASCII. Внутренние настройки SMTP и токенов ботов хранятся в пространстве `itk:`.

Код сайта читает настройки сервисом `Itk\Cms\Settings\Settings`. Значение приходит в типе поля:

```php
$settings->get('phone');                  // toggle → bool, number → int|float, text/select → string, image/link/repeater → array
$settings->string('phone', 'none');
$settings->int('seats', 10);
$settings->bool('maintenance');
$settings->array('socials');
$settings->media('logo');                 // {src, alt} или null
$settings->get('siteName', locale: 'en'); // для полей с translatable()
$settings->all();                         // все настройки языка
```

Значение по умолчанию вызова подставляется, если настройки нет в схеме или она пуста. `false` и `0` считаются настоящими значениями. Пока настройку не сохранили, читается `default()` поля. Twig-функция `settings()` работает через тот же сервис. Ограничения `canSee()` на этот сервис не действуют.

### Медиатека и картинки

Оригиналы лежат в `{upload_url}/Y/m/name.jpg` (по умолчанию `/uploads/media`). Уменьшенные копии лежат рядом: `{upload_url}/_v/w{ширина}/Y/m/name.webp` и `.avif`.

- После загрузки картинки воркер создаёт WebP и, если GD умеет `imageavif`, AVIF для каждой ширины. Если очереди нет, копию создаёт маршрут `itk_image_variant` при первом запросе и кладёт файл по публичному пути. Дальше его отдаёт веб-сервер. Для этого веб-сервер передаёт в `index.php` запрос на отсутствующий файл (`try_files $uri /index.php$is_args$args`).
- Разрешены только ширины из `itk_cms.images.widths`. Другой адрес отвечает 404. Оригинал ищется только внутри `upload_dir`.
- Картинка не увеличивается. Пропорции и прозрачность сохраняются, поворот EXIF у JPEG применяется.
- Преобразуются JPG, PNG, неанимированный WebP и AVIF. GIF, SVG, PDF, анимированный WebP и оригиналы больше `images.max_pixels` (50 Мпикс) отдаются как есть.
- В полях `article` и `description` теги `<img src="/uploads/…">` при выводе получают `src` копии 960, `srcset` до 1920, `sizes`, `loading="lazy"` и `decoding="async"`. В базе остаётся исходный HTML.
- `php bin/console itk:media:warm [--width=640 --width=1280]` заранее создаёт копии всех картинок, например после импорта.

**Кадры и точка фокуса.** У файла медиатеки есть точка фокуса и сохранённые кадры для пропорций («Медиатека → файл → Кадр и точка фокуса»). Адрес кадра: `{upload_url}/_v/w{ширина}-r{A}x{B}/Y/m/name.webp`, его даёт `media_url(image, 960, '16:9')`. Без сохранённого кадра берётся самая большая рамка пропорции вокруг точки фокуса. Разрешены пропорции из `images.ratios` и те, для которых у файла сохранён кадр. Пропорция пишется несокращённой (`16x9`, не `32x18`), иначе 404. Кадр кэшируется браузером на сутки, а не на год, потому что его можно перерисовать.

**Папки.** Папки вкладываются до 6 уровней, имя уникально среди соседей. Папку с содержимым удаляют только с переносом содержимого в другую папку или наверх. API (область `media`): `GET|POST media-folders`, `PATCH|DELETE media-folders/{id}` (`?moveTo=<id>|root`), `GET media?folder=<id>|none`, `PATCH media/{id} {folderId}`, `POST media/move {ids, folderId}`.

## Вёрстка сайта

### Шаблоны Twig

Размещайте переопределения в `templates/bundles/ItkCmsBundle/`. Для наследования оригинального шаблона используйте `@!ItkCms/…`. Ассеты админки и предпросмотра используют основной пакет Symfony Asset, если он доступен. Учитываются базовый путь и CDN.

Сайт кладёт шаблоны в `templates/`:

- `layout.html.twig` это макет. Он объявляет `{% block content %}`. Для `<title>` удобен `{% block title %}`.
- `pages/<шаблон>.html.twig` выводит страницу своего шаблона. Без него работает `pages/default.html.twig`.
- `sections/<секция>.html.twig` выводит секцию. Переменные: `section`, `page`, `is_h1`.

В шаблоне страницы доступны `page` (`id`, `parentId`, `template`, `locale`, `html_lang`, `og_locale`, `translations`, `title`, `url`, `seo`, `fields`, `sections`, `h1Id`) и `site` (настройки, а также `name`, `domain`, `locale`).

### Функции Twig

| Функция | Что делает |
|---|---|
| `render_sections(page)` | Выводит видимые секции через `sections/<тип>.html.twig` |
| `itk_field(section\|page, 'key')` | Значение поля. `headline` и текст с оформлением приходят безопасным HTML, `text` экранированным, остальное данными. В предпросмотре оборачивает значение в разметку для выделения |
| `itk_field_attrs(section\|page, 'key')` | Атрибуты предпросмотра для нетекстовых полей |
| `itk_section_attrs(section)`, `itk_section_class(section)` | Атрибуты корня секции и класс `chg` для изменённой секции, только в предпросмотре |
| `itk_is_preview()` | `true`, пока админка показывает черновик |
| `menu('header')` | Пункты области: `label`, `kind`, `url`, `active`, `open`, `children` |
| `settings('phone')`, `settings('phone', 'none')`, `settings()` | Настройки |
| `page_url(link)` | Безопасный адрес по значению поля «Ссылка», странице или id страницы |
| `listing(page)` | Опубликованные страницы или записи, которые собирает страница. У строки есть `parentId` |
| `itk_pages({...})`, `itk_page(id)`, `itk_collection(key, {...})` | Публичное чтение данных, раздел ниже |
| `media_url(image[, width[, ratio]])` | Адрес картинки, её WebP-копии ближайшей разрешённой ширины не меньше заданной или кадра пропорции |
| `media_srcset(image[, maxWidth[, ratio]])` | Значение `srcset` до оригинала или до первой копии, которая покрывает `maxWidth`. Для GIF, SVG и чужих адресов пусто |
| `media_width(image)` | Ширина оригинала в пикселях или `null` |
| `media_ratio(width, height)` | Ключ пропорции места в вёрстке: `media_ratio(700, 250)` даёт `'14:5'` |
| `itk_img(image, {...})` | Готовый `<img>` с `srcset`, `sizes`, `alt`, `loading="lazy"`, `decoding="async"`. При поддержке AVIF обёрнут в `<picture>` |
| `itk_seo({...})` | Мета-теги текущего адреса, раздел [SEO-правила адресов](#seo-правила-адресов) |
| `itk_cache(300)`, `itk_cache(false)` | Срок записи в кэше страниц, раздел [Кэш страниц](#кэш-страниц) |
| `itk_stylesheets({nonce: x})` | Таблицы стилей сайта, раздел [Критический CSS](#критический-css) |
| `itk_asset('preview.js')` | URL файла в `bundles/itkcms/`. Использует пакет Symfony Asset сайта или базовый путь запроса |
| `itk_t('area.key', {...})`, `itk_locale()` | Текст и язык интерфейса админки. Для своих страниц входа и писем |
| `itk_admin_font()` | Правила `@font-face` шрифта админки для своих страниц входа |

Параметры `itk_img`: `sizes`, `class`, `width`, `height`, `loading`, `alt`, `ratio`, `src_width` (ширина `src`, по умолчанию `width`, иначе 960), `max_width` (самая широкая картинка в пикселях устройства, по умолчанию `2 × width`), `attrs` (массив атрибутов или `itk_field_attrs(...)`). `sizes` по умолчанию `(max-width: {width}px) 100vw, {width}px`.

Пропорция, которой нет в `images.ratios` и в кадрах файла, не ломает страницу. Тогда берётся вся картинка, а в журнал пишется предупреждение. Чтобы картинка приходила в форму своего места, пишите `media_url(img, 700, media_ratio(700, 250))`.

### Публичное чтение данных

`itk_pages`, `itk_page` и `itk_collection` читают только опубликованное и одинаково для всех. Гость и вошедший администратор видят одно и то же. `itk_pages` и `itk_collection` делают по одному запросу к базе на вызов. Все вызовы `itk_page` используют один запрос к базе на язык за время обработки запроса посетителя. Из кода сайта то же читает `Itk\Cms\Content\ContentReader`.

```twig
{% for c in itk_pages({template: 'blog_category'}) %}<a href="{{ c.url }}">{{ c.title }}</a>{% endfor %}
{% for a in itk_pages({template: 'blog_article', parent: category.id, sort: '-fields.date', limit: 6}) %}…{% endfor %}
{{ itk_page(article.parentId).title }}
{% for t in itk_collection('tours', {where: {state: 'open', seats: {from: 5}, level: ['easy', 'mid']}, sort: '-start', limit: 10, offset: 20}) %}…{% endfor %}
```

- `itk_pages` возвращает строки `{id, parentId, template, locale, title, url, seo, fields, position, publishedAt}`. Параметры: `template` (ключ или список), `parent` (id страницы, только прямые дети; пустое значение считается ошибкой), `limit`, `sort`. `sort` принимает `position` (по умолчанию), `createdAt`, `publishedAt`, `title`, `url` и `fields.<ключ>`. Знак `-` означает убывание, несколько ключей пишут списком или через запятую. Числовое поле сортируется как число. Секций в строке нет, их даёт `listing()`.
- `itk_page(id)` возвращает такую же строку или `null`. Все страницы языка читаются одним запросом на весь запрос посетителя, поэтому цикл с `itk_page(a.parentId)` не делает запрос на каждую статью.
- `itk_collection(key)` возвращает записи по порядку. Параметры: `where` (`{поле: значение}`, список значит любое из значений, `true` и `false` для переключателя, `{from, to}` для числа или даты `2026-11-30`), `sort` (`position`, `createdAt` или поле), `limit`, `offset`. Запись приходит как `{id, ...поля}`.
- В PHP `ContentReader` бросает `\InvalidArgumentException` при неверном параметре, поле или шаблоне. В Twig неверный вызов `itk_pages` или `itk_collection(key, options)` бросает `\LogicException` в строгом режиме. В мягком режиме возвращается `[]` и в журнал пишется предупреждение. Передайте `{}` вторым аргументом, чтобы проверить ключ коллекции без фильтров.

### Строгий режим

Опция `itk_cms.strict` (по умолчанию равна `kernel.debug`) делает ошибки шаблонов громкими. Тогда исключение бросают неизвестный ключ в `itk_field` (с подсказкой допустимых ключей), неизвестный ключ внутри повторителя, тип секции, которого нет в схеме, секция без шаблона и неверный вызов `itk_pages` или `itk_collection(key, options)`. При `strict: false` страница выводится, а в журнал уходит одно предупреждение на ошибку за запрос. Путь внутрь значения без подполей (`image.src`) ошибкой не считается. В предпросмотре админки проверки полей и секций смягчены, секция без шаблона показывается серой рамкой. Неверные вызовы чтения контента по-прежнему подчиняются `strict`.

### Безопасные адреса

`page_url()` отдаёт только безопасный адрес: путь сайта (`/blog`, но не `//host`), адрес страницы по id, `http(s)://`, `mailto:`, `tel:` и `#якорь`. Адрес с другой схемой (`javascript:`, `data:`) становится пустой строкой. Поле «Ссылка» проверяется тем же правилом при сохранении. Правило одно для страниц, записей, настроек и пунктов меню.

Класс `Itk\Cms\Support\Url` даёт эти проверки коду сайта: `safeLocalPath()`, `isLocalPath()`, `cleanPageRef()`, `cleanLinkUrl()`, `safeHref()`. `Url::safeLocalPath($path)` возвращает путь, который можно положить в `Location` или `href`. Он оставляет ровно один `/` в начале, читает `\` как `/`, убирает табы и переводы строк. Пропускайте через него любой кусок адреса из запроса, который попадает в переадресацию:

```php
return new RedirectResponse(Url::safeLocalPath($request->getPathInfo()) . '?' . $query, 301);
```

## Поля

Поле это объект из `Itk\Cms\Field\` с цепочкой методов. Подпись идёт первой, ключ вторым: `Text::make('Title', 'title')`. Ключ обязателен у любого поля с данными, без ключа бывает только `Note`. В `schema.php` то же поле создаёт короткая запись `Itk\Cms\Schema\Field` с ключом первым: `Field::text('title', 'Title')`. Она возвращает тот же объект.

### Типы полей

| Класс | `Field::` | Что это | Значение |
|---|---|---|---|
| `Text`, `Textarea` | `text`, `textarea` | Строка, многострочный текст | строка |
| `Headline` | `headline` | Заголовок | безопасный HTML |
| `Description` | `description` | Абзацы с оформлением | безопасный HTML |
| `Article` | `article` | Текст статьи: подзаголовки, цитата, линия, фото, простая таблица | безопасный HTML |
| `Number` | `number` | Число. `->grouped()` показывает разделители разрядов | число |
| `Toggle` | `toggle` | Переключатель | `bool` |
| `Select` | `select` | Один вариант: `->options(['open' => 'Open'])` | строка |
| `Date`, `DateTime` | `date`, `dateTime` | `2026-11-30`, `2026-11-30T18:00` | строка |
| `Image` | `image` | Картинка | `{src, alt}` |
| `Photos` | `photos` | Список фото. Элемент это адрес строкой или `{src, alt}`. В шаблоне пишите `{{ p.src ?? p }}` | список |
| `Link` | `link` | Ссылка на страницу или адрес | `{kind, page, url}` |
| `Repeater` | `repeater` | Список элементов: `->fields(...)` | список, у элемента `_id` |
| `Relation` | `relation` | Связь с записью коллекции: `->to('destinations')`, `->multiple()` | id или список id |
| `Note` | `note` | Подсказка редактору, не данные: `->text()`, `->linkTo()` | нет |

Таблица в `Article` содержит `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`. В ячейке разрешены текст, ссылки, `<strong>`, `<em>` и `<br>`, из атрибутов только числовые `colspan` и `rowspan`.

### Методы полей

Все методы возвращают поле, порядок вызовов любой.

| Метод | Что делает |
|---|---|
| `required()`, `max(int)`, `rows(int)` | Обязательность, длина, высота поля ввода |
| `hint(string)` | Пояснение под полем. Синоним `help()` |
| `default($v)` | Значение для новой записи. Синоним `initial()` |
| `pattern($regex, $message)` | Проверка значения формы сайта на сервере |
| `inputType(string)` | Тип поля ввода HTML, например `email` |
| `translatable()` | Настройка хранится отдельно для каждого языка |
| `readonly()` | Редактор видит значение, но не меняет. Сервер игнорирует его значение, код и хуки писать могут |
| `canSee('set:users' \| 'col:tours:edit' \| fn (Viewer $v): bool)` | Кому видно поле. Остальным сервер не отдаёт поле в схеме, записях и истории и не принимает его значение |
| `raw()` | Многострочный текст выводится как сырой HTML со скриптами. Добавлять и менять значение может только человек с правом управлять пользователями, через MCP не может никто |
| `half()` | Поле занимает половину строки формы. Два соседних поля с `half()` стоят рядом |
| `section('Заголовок')` | Заголовок раздела формы перед полем |
| `sortable()`, `searchable()`, `filterable(?FilterKind)` | Сортировка, поиск и фильтр в списке коллекции |
| `showOnIndex()` (`column()`), `hideOnIndex()`, `hideOnForm()`, `hideOnDetail()`, `width(int)` | Колонка списка, форма, просмотр, ширина колонки в пикселях |
| `variant()` | Поле `Select` становится параметром «Вид секции» |
| `noun()`, `addLabel()` | Существительное элемента повторителя и подпись кнопки добавления |

`readonly()` и `canSee()` действуют в записях коллекций, полях шаблонов, полях секций (верхнего уровня) и настройках. В настройках значения группы, на которую у человека нет права `set:<группа>` «Смотреть», тоже не отдаются.

### Строка повторителя

```php
Repeater::make('Variants', 'variants')
    ->noun('variant')
    ->itemTitle('{bike} · {yearFrom}–{yearTo}', '{title}')
    ->itemSummary('{sku} · {price}')
    ->duplicable()
    ->fields(
        Relation::make('Bike', 'bike')->to('bikes')->section('Fitment'),
        Number::make('Year from', 'yearFrom')->half(),
        Number::make('Year to', 'yearTo')->half(),
        Text::make('Title', 'title'),
        Photos::make('Photos', 'photos'),
    );
```

- `itemTitle()` задаёт заголовок свёрнутой строки, `itemSummary()` строку под ним. `{ключ}` подставляет значение подполя так, как его читает человек: `Relation` названием записи, `Select` названием варианта, `Toggle` подписью поля, если он включён, `Image` описанием картинки, `Number` с `grouped()` с разделителями разрядов. `Link`, `Photos` и вложенный `Repeater` дают пустое значение.
- Текст между двумя значениями печатается, только если оба значения есть. Текст до первого и после последнего значения печатается, только если рядом есть значение. Скобки вокруг необязательного значения не поддерживаются. `0` считается значением, пустая строка нет.
- Следующие аргументы `itemTitle()` это запасные шаблоны. Побеждает первый, который дал непустой текст. Если пусто всё, строка называется по `noun()` с номером.
- В истории `Relation` в заголовке пуст: история пишется без запросов к другим коллекциям.
- `duplicable()` добавляет кнопку «Дублировать». Копия встаёт под исходной и получает новые `_id`.
- С `itemTitle()` или `itemSummary()` миниатюра строки берётся из первого поля `Image`, а без него из первого фото первого поля `Photos`.

### Свой тип поля

Свой тип поля наследует `Itk\Cms\Field\AbstractField`. Метод `wireType()` возвращает тип, который понимает админка. Компонент админки для него подключает расширение (раздел [Расширения](#расширения)). Заготовку делает `itk:make:field`.

## Классы-ресурсы

Классы-ресурсы это второй способ описать сайт: один PHP-класс на коллекцию, шаблон, секцию или группу настроек. Сайт кладёт их в `src/Itk/{Collection,Template,Section,Settings}/`, и они подхватываются сами через тег `itk.resource`, если `services.yaml` загружает `src/`. В конструктор можно внедрить сервисы.

```php
namespace App\Itk\Collection;

use Itk\Cms\Attribute\{Group, Icon, Order};
use Itk\Cms\Field\{Date, Number, Select, Text};
use Itk\Cms\Resource\{CollectionResource, Metric};

#[Group('Catalog', icon: 'box', order: 20)]
#[Order(10)]
#[Icon('map')]
final class ToursCollection extends CollectionResource
{
    public function key(): string { return 'tours'; }
    public function title(): string { return 'Tours'; }

    public function fields(): iterable
    {
        yield Text::make('Title', 'title')->required()->searchable()->sortable();
        yield Date::make('Start', 'start')->sortable()->filterable();
        yield Number::make('Seats', 'seats')->sortable()->filterable();
        yield Select::make('Status', 'state')->options(['open' => 'Open for booking', 'full' => 'Sold out'])->filterable();
    }

    public function defaultSort(): array { return ['start' => 'asc']; }
    public function metrics(): iterable { yield Metric::count('Total'); yield Metric::sum('Seats', 'seats'); }
}
```

| Класс | Обязательно | Необязательно |
|---|---|---|
| `CollectionResource` | `key()`, `title()`, `fields()` | `singular()`, `newLabel()`, `icon()`, `description()`, `emptyState()`, `views()`, `titleField()`, `thumbField()`, `subField()`, `toggleField()`, `filters()`, `actions()`, `metrics()`, `tree()`, `sortable()`, `defaultSort()`, `perPage()`, `canSee(Viewer)`, хуки |
| `TemplateResource` | `key()`, `title()`, `fields()` | `about()`, `parents()`, `topLevel()`, `single()`, `root()`, `leaf()`, `sections()`, `listing()`, `h1()`, `shortcut()`, `card()`, `areas()`, `configure(Template $t)`, хуки |
| `SectionResource` | `key()`, `title()`, `fields()` | `group()`, `template()` (по умолчанию `sections/<key>.html.twig`), `short()`, `tone()`, `silhouette()`, `about()`, `h1()` |
| `SettingsResource` | `key()`, `title()`, `fields()` | `description()` |

Класс с ключом шаблона ядра (`blog_article`) меняет этот шаблон. Если у класса нет полей, поля ядра остаются. Любой другой ключ, описанный дважды (в `schema.php` и в классе или в двух классах), считается ошибкой. Не внедряйте в ресурс `Schema`, `PageService` и `CollectionService`: схема строится из ресурсов, и получится кольцо зависимостей. Для работы с данными в хуках берите события и `Context`.

### Хуки и события

Каждая операция жизненного цикла записи коллекции или страницы проходит так: сначала событие Symfony, затем метод класса-ресурса. Для страницы ресурс это класс её шаблона, `kind` равен `page`. Для записи ресурс это класс коллекции, `kind` равен `item`.

| Момент | Событие (`Itk\Cms\Event\`) | Метод класса |
|---|---|---|
| Первое чтение раздела за запрос | | `onLoad(Context)` |
| Создание | `RecordCreating`, `RecordCreated` | `beforeCreating(array $data, Context): array`, `afterCreated(Record, Context)` |
| Изменение, в том числе автосохранение | `RecordUpdating`, `RecordUpdated` | `beforeUpdating(Record, array $data, Context): array`, `afterUpdated(Record, Context)` |
| Удаление | `RecordDeleting`, `RecordDeleted` | `beforeDeleting(Record, Context)`, `afterDeleted(Record, Context)` |
| Публикация | `RecordPublishing`, `RecordPublished` | `beforePublishing(Record, Context)`, `afterPublished(Record, Context)` |
| Снятие с публикации | `RecordUnpublishing`, `RecordUnpublished` | `beforeUnpublishing(Record, Context)`, `afterUnpublished(Record, Context)` |
| Перевод добавлен или удалён (после commit) | `RecordTranslationAdded`, `RecordTranslationRemoved` | |
| Смена шаблона страницы | `PageTemplateChanged` (`from` прежний шаблон, `resourceKey` новый) | |
| Несколько записей сразу | `MassAction` (`action`: `delete`, `publish`, `unpublish`; `phase`: `before`, `after`) | `beforeMassDeleting`, `afterMassDeleted`, `beforeMassPublishing`, `afterMassPublished`, `beforeMassUnpublishing`, `afterMassUnpublished` (`array $records, Context`) |

- У события есть `resourceKey`, `kind`, `record` (`null` у `RecordCreating`), `data` и `viewer`. `data` у `RecordCreating` и `RecordUpdating` можно менять, и сохранится изменённое.
- `RecordTranslationAdded` и `RecordTranslationRemoved` наследуют `RecordEvent` и передают язык операции в `locale` (и в `record->locale`). Они отправляются только после успешного commit транзакции. Добавление и удаление перевода не вызывают события создания/удаления объекта и хуки жизненного цикла ресурса. При удалении опубликованного перевода по-прежнему вызываются `RecordUnpublishing` / `RecordUnpublished` и соответствующие хуки снятия с публикации.
- `Record` это `{id, kind, resourceKey, locale, fields, status, meta}` с методом `get($key)`. У страницы `fields` содержит поля шаблона, а `meta` содержит `title`, `url`, `parentId`, `template`.
- `Context` содержит `viewer`, `locale`, `kind`, `resourceKey`. `Viewer` это человек с правами (`can('col:tours', 'publish')`, `allows('set:users')`, `user()`, `role()`) или система (консоль, импорт, очередь: `isSystem()`, разрешено всё).
- Любой `before…` может бросить `Itk\Cms\Content\ContentException`. Изменение отменяется, редактор видит текст исключения. `after…` выполняется после сохранения, исключение в нём ничего не откатит.
- Массовая операция сначала вызывает общий `before` один раз. Затем каждая запись проходит свои `before…` и запись в базу, всё в одной транзакции. `after…` каждой записи и общий `afterMass…` выполняются после транзакции, и только если она не откатилась.
- Поля `readonly()` и закрытые `canSee()` ядро убирает из данных человека до событий. Значения, которые добавил хук, сохраняются.
- Данные страницы в `beforeCreating` и `beforeUpdating` вложенные: `{title, url, seo, parentId, template, sections, fields: {...}}`. При изменении приходят только меняющиеся ключи. Неизвестный ключ верхнего уровня игнорируется.
- Перенос по дереву проходит через `beforeUpdating` с данными `{parentId}`. Хук может отменить перенос исключением, но не изменить его. «Отменить правки» хуков изменения не вызывает.
- Возврат снятой страницы на сайт вызывает `RecordPublished` и `afterPublished` без `before…`.

```php
// CollectionResource: данные плоские
public function beforeCreating(array $data, Context $ctx): array
{
    $data['slug'] = Slug::slugify((string) ($data['title'] ?? ''));

    return $data;
}

// TemplateResource: поля шаблона внутри 'fields'
public function beforeCreating(array $data, Context $ctx): array
{
    $data['fields']['slug'] = Slug::slugify((string) ($data['title'] ?? ''));

    return $data;
}
```

### Действия

Действие это кнопка над записями коллекции. Класс описывает действия в `actions()`, `schema.php` в `->actions(...)`.

```php
Action::make('Duplicate', 'duplicate')->row()->bulk()->icon('copy')
    ->modal('How many copies?', Number::make('Copies', 'times')->required())
    ->handle(static function (ActionContext $c): ActionResult {
        // $c->records() выбранные записи, $c->input() значения окна, $c->viewer()
        return ActionResult::message('Done')->reload();
    });
```

| Метод | Что делает |
|---|---|
| `row()`, `bulk()`, `top()`, `form()` | Место кнопки: меню строки, отмеченные записи, над списком, страница записи. Можно несколько, по умолчанию `row()` |
| `icon()`, `danger()`, `confirm('Question')` | Значок, опасное действие, вопрос перед запуском |
| `modal($title, ...$fields)` | Окно с полями. Значения проверяются по полям |
| `canSee(string\|callable)` | Кому видна кнопка |
| `requires('publish')` | Право на коллекцию для запуска, по умолчанию «Править» |
| `permission()` | Отдельная строка `action:<коллекция>:<действие>` в матрице ролей. По умолчанию она есть только у администратора |
| `handle(callable)` | Обработчик |

Результат: `ActionResult::message('…')->reload()`, `::redirect('/pages/42')` (адрес админки или `http(s)://`), `::download('/path/file.csv', 'name.csv', delete: true)`, `::download(StreamedResponse)`, `::error('…')`.

Каждая коллекция, кроме коллекций из одной записи, получает встроенные действия `publish-selected`, `unpublish-selected` и `delete-selected`. Они идут через массовые хуки. Своё действие с таким ключом заменяет встроенное.

API: `POST {admin}/api/resources/{коллекция}/actions/{действие}` с телом `{ids?: [], input?: {}, locale?}` отвечает `{ok, message?, reload?, redirect?, download?}`. Действию без места `top()` нужны `ids`, не больше 500. Ошибка действия отвечает `422 {ok: false, error, message}`. Файл действия отдаётся один раз тому, кто запустил действие, по адресу `GET {admin}/api/downloads/{token}` в течение 5 минут.

### Фильтры, сортировка и метрики

Фильтр это поле с `filterable()`. Вид фильтра выбирается по полю или задаётся явно: `FilterKind::Text`, `Select`, `Multi`, `Toggle`, `DateRange`, `NumberRange`, `Relation`. `filters()` ресурса заменяет набор полей с `filterable()`. Фильтр `status` (`draft`, `published`) есть у всех коллекций.

```
GET {admin}/api/collections/tours?q=altai&filter[state]=open&filter[start][from]=2026-11-01&filter[seats][from]=5
    &filter[level]=mid,hard&sort=-start,title&page=2&perPage=50
→ { items, total, page, perPage, facets: { state: { open: 4, full: 3 } }, metrics: [{ label, kind, value }] }
```

- Ответ всегда имеет вид `{ items, total, page, perPage, facets, metrics }`, в том числе у запроса без параметров. См. «Списки коллекций» в разделе JSON API ниже.
- `sort` принимает ключи через запятую, `-` значит убывание. Сортировать можно поля с `sortable()`, а также `status`, `updatedAt`, `createdAt`, `position`. Без `sort` действует `defaultSort()`, иначе ручной порядок.
- При наличии `filter` параметр `q` ищет по видимым полям с `searchable()`. Если таких полей в схеме нет, поиск идёт по заголовку и второй строке. Поле, закрытое `canSee()`, нельзя ни фильтровать, ни сортировать (422).
- `perPage` по умолчанию 50, не больше 200. `facets` считает записи по значениям фильтров `Select`, `Multi`, `Toggle` и `status` по всей коллекции.
- Метрики (`Metric::count('Total')`, `Metric::count('Published', filter: ['status' => 'published'])`, `Metric::sum('Seats', 'seats')`) считаются по всей коллекции, а не по текущему фильтру.
- Фильтры и вид списка запоминаются на сервере для человека: `GET|PUT|DELETE {admin}/api/prefs/{ключ}`. Ключ до 100 знаков из букв, цифр и `. _ : -`, значение до 16 КБ JSON.

### Виды списка

`views()` ресурса или `->views([...])` перечисляет виды, между которыми человек переключается. Первый вид действует по умолчанию.

```php
public function views(): array
{
    return [
        'table',
        'cards',
        'calendar' => ['start' => 'dateStart', 'end' => 'dateEnd', 'title' => 'city'],
        'kanban'   => ['field' => 'state', 'columns' => ['open' => 'Open', 'full' => 'Sold out']],
    ];
}
```

- **Календарь.** `start` это поле `Date` или `DateTime`, обязательно. `end` необязательно. `title` задаёт подпись, по умолчанию заголовок записи. Клик по дню создаёт запись с этой датой. Перетаскивание и Alt + стрелки сдвигают обе даты.
- **Доска.** `field` это поле `Select`, колонки это его значения или `columns`. Перенос карточки меняет значение поля. Записи с неназванным значением попадают в колонку «Без значения».
- Сдвиг дат и перенос по доске это обычное обновление записи: работают права на поля, хуки, события и история. Хук может отменить перенос исключением.
- Календарь, доска и дерево показывают все записи без страниц, до 2000.
- Неверное описание вида (календарь без `start`, несуществующее поле, доска не по `Select`) бросает `InvalidArgumentException` при сборке схемы.

### Дерево

Записи коллекции с `tree()` вкладываются друг в друга. В списке появляется вид «Структура» (первый по умолчанию) с перетаскиванием и клавишами: Alt + ↑ и ↓ двигают запись, Alt + → вкладывает в соседа выше, Alt + ← выносит наверх.

```
PUT {admin}/api/collections/{col}/tree   [{ "id": "…", "parentId": "…"|null, "position": 0 }, …]   → { ok, moved }
PUT {admin}/api/pages/tree               то же для страниц
```

- `position` это место среди записей того же родителя, с нуля. Названные узлы переезжают, соседи затронутых родителей нумеруются заново.
- Нельзя вложить узел в себя или в свою ветку (422), назвать узел дважды, указать неизвестный узел или родителя. Всё выполняется в одной транзакции.
- Запись, у которой сменился родитель, проходит как обновление с хуками. Запись с `parentId` создаётся обычным `POST collections/{col}`. Удаление записи поднимает её вложенные записи на её место. Нужно право «Править» на `col:<ключ>`.
- Для страниц действуют правила шаблонов (`parents()`, `single`, `leaf`, главная всегда наверху) и право «Править» на шаблон каждой перемещаемой страницы. В админке: «Страницы» → «Изменить структуру».

### Меню админки

Левую колонку админки строит сервер: `GET {admin}/api/schema` отдаёт `nav`, только с тем, что доступно человеку. Встроенные пункты: «Сегодня» (0), «Страницы» (10) с быстрыми ссылками шаблонов с `shortcut()`, «Заявки» (20), «Медиатека» (30), группа «Настройки» (900). Класс коллекции появляется в меню сам.

| Атрибут на классе | Что делает |
|---|---|
| `#[Group('Catalog', icon: 'box', order: 20)]` | Группа меню. Без атрибута группа «Коллекции» с `order` 50 |
| `#[Order(10)]` | Место пункта в группе |
| `#[Icon('map')]` | Значок пункта |
| `#[CanSee('col:tours:edit')]` | Кому показывать пункт, сверх права «Смотреть» |
| `#[HideFromMenu]` | Раздел работает, пункта в меню нет |

В `schema.php`: `$s->collection('tours')->menu(group: 'Catalog', order: 10, icon: 'map', hidden: false, canSee: 'col:tours', groupIcon: 'box', groupOrder: 20)`. `canSee(Viewer)` класса скрывает коллекцию совсем: из схемы и из API.

## Админка

### Адрес, вход и API

Админка стоит на `/admin`. Другой адрес задаёт опция `itk_cms.admin_path` (один или несколько сегментов, с `/` в начале и без `/` в конце, например `/cp`). У импорта маршрутов можно задать и `prefix`. Тогда админка окажется на `prefix` + `admin_path`. После смены адреса поправьте `security.yaml` (раздел [Безопасность](#безопасность-securityyaml)).

Бандл узнаёт админку по именам маршрутов `itk_admin_*` и `itk_api_*`, а не по строке адреса. Поэтому CSRF API, заголовки безопасности, двухэтапный вход и JSON-ошибки работают при любом адресе. Адреса ниже даны для `/admin`.

| Адрес | Что это |
|---|---|
| `/admin` (`itk_admin_app`) | Оболочка админки. Файлы берутся из `/bundles/itkcms/admin/` по манифесту Vite. Настройки клиента лежат в `window.ITK_ADMIN` |
| `/admin/login` (`itk_admin_login`), `/admin/logout` (`itk_admin_logout`) | Вход (`form_login`, поля `_username`, `_password`, `_csrf_token`) и выход |
| `/admin/login/otp` | Второй шаг входа |
| `/admin/forgot-password`, `/admin/reset-password/{token}` | «Забыли пароль?» и новый пароль по ссылке из письма |
| `/admin/preview/{id}?locale=` (`itk_admin_preview`) | Черновик страницы настоящими шаблонами сайта |
| `/admin/api/*` | JSON API админки |

- Каждый изменяющий вызов API несёт заголовок `X-CSRF-Token` со значением из `window.ITK_ADMIN.csrf`.
- Ошибка API приходит как `{ "error": "…", "details"?: … }` с кодом 401, 403, 404, 409 или 422. Текст `error` идёт на языке человека и может меняться. Интеграции опираются на HTTP-код и на `details.code`: `conflict`, `csrf`, `no_translation`, `link_gone`, `password_locked`, `mail_not_configured`, `mail_failed`, `mail_queue`, `mail_test_limit`, `no_public_url`, `2fa_code`, `2fa_enrol`, `2fa_locked`. Ошибка поля формы лежит в `details.<поле>`.
- Права проверяются на сервере в каждом вызове.
- Все ответы админки получают `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'`, `X-Content-Type-Options: nosniff` и `Referrer-Policy: same-origin`. Заголовок, который выставил контроллер, не перезаписывается.

Сервис `Itk\Cms\Admin\AdminPath` отвечает, где стоит админка (`base()`, `to('/api/me')`), с учётом `prefix` импорта. Через него строятся ссылки в письмах.

#### Списки коллекций

`GET /admin/api/collections/{col}` всегда возвращает `{ items, total, page, perPage, facets, metrics }`. В `items` находятся строки текущей страницы, в `total` — число подходящих строк до пагинации. Читайте `items` и запрашивайте остальные страницы до `ceil(total / perPage)`.

| Параметр запроса | Значение |
|---|---|
| `q` | Поиск подстроки без учёта регистра. Без `filter` поиск идёт по видимому заголовку и второй строке (`titleField()` и `subField()`). При наличии `filter` используются видимые поля поиска коллекции |
| `status` | Пустой параметр включает все статусы. `draft` включает черновики и опубликованные версии с неопубликованными правками. `published` включает опубликованные версии без правок. `missing` включает записи без версии на выбранном языке. Остальные значения дают `422` |
| `onlyTranslated` | Логическое значение, например `1` или `true`. Исключает записи без версии на выбранном языке до подсчёта и пагинации |
| `filter` | Фильтры полей, например `filter[state]=open`, `filter[level]=mid,hard` или `filter[start][from]=2026-11-01`. `Multi` и `Relation` принимают массивы. Диапазоны дат и чисел включают обе границы, любую можно опустить. `filter[status]` проверяет сохранённый статус |
| `sort` | Ключи через запятую, например `-start,title`. Знак `-` означает убывание. Допустимы видимые поля с `sortable()`, а также `status`, `updatedAt`, `createdAt` и `position`. По умолчанию действует `defaultSort()`, если задан, или ручной порядок |
| `page` | Номер страницы от 1. По умолчанию 1 |
| `perPage` | Размер страницы. По умолчанию действует настройка коллекции `perPage()` (50). Значения выше 200 обрезаются до 200 |
| `locale` | Язык содержимого. По умолчанию основной язык. Код вне явно заданного списка возвращает `422` |
| `envelope` | Принимается для совместимости. Во всех случаях возвращается объект |

Фильтры, поиск, статус и отбор переводов действуют до подсчёта и пагинации. `facets` содержит счётчики фильтров `Select`, `Multi`, `Toggle` и `status`, `metrics` — настроенные метрики. Оба значения считаются по всей коллекции.

#### Переводы страниц и записей

Все переводы относятся к одной странице или записи коллекции. Создание перевода сохраняет её `id`, шаблон, родителя и позицию. При создании целевой язык передаётся в query-параметре `locale`, при удалении в последнем сегменте пути.

| Запрос | Тело JSON | Успешный ответ |
|---|---|---|
| `POST /admin/api/pages/{id}/translations?locale=kk` | `{"from":"ru"}` или `{"from":null}` | `201`, плоский черновик страницы с тем же `id` и `locale: "kk"` |
| `DELETE /admin/api/pages/{id}/translations/{locale}` | Нет | `200 {"ok":true}` |
| `POST /admin/api/collections/{col}/{id}/translations?locale=kk` | `{"from":"ru"}` или `{"from":null}` | `201`, плоский черновик записи с тем же `id` и `locale: "kk"` |
| `DELETE /admin/api/collections/{col}/{id}/translations/{locale}` | Нет | `200 {"ok":true}` |

`from` задаёт существующий черновик для копирования с учётом прав полей. При `null` поля и секции заполняются значениями из схемы; у страницы название и основа адреса берутся из основного перевода. Новая версия создаётся черновиком без публикации или заявки на согласование. Новый адрес страницы получает префикс целевого языка. Если путь занят, добавляется свободный суффикс. Для занятого адреса главной API возвращает `409`.

Для создания нужно право `edit`, для удаления `delete`, с теми же ограничениями шаблона или коллекции, что и при обычном редактировании. Для обоих запросов нужны вход и заголовок CSRF, описанный выше. Удаление затрагивает только выбранный перевод и записывает его снимок в историю с действием `delete_locale`. Это запись аудита: она не считается текущей версией, не попадает в корзину и не восстанавливается; вместо этого создайте новый перевод. Основную и последнюю оставшуюся версии удалить нельзя. Чтобы убрать последнюю версию, удалите страницу или запись целиком.

- `404`, если нет страницы, записи или удаляемого перевода.
- `409`, если целевой перевод уже есть, адрес занят или запрос удаляет основную либо последнюю версию.
- `422`, если целевой язык пропущен или отключён, `from` неверен или указывает на отсутствующий черновик либо адрес некорректен.
- `401`, если нужно войти. `403`, если не хватает прав.

Ошибки имеют формат `{error, details?}`. Прежние ответы об отсутствующем переводе сохраняют `details.code: "no_translation"`. Чтение черновика на языке без перевода по-прежнему возвращает оболочку ответа с `translated: false` и `page: null` либо `item: null`.

Доверенный PHP-код сайта может вызывать те же публичные операции `Itk\Cms\Content\PageService` и `Itk\Cms\Content\CollectionService`. `addTranslation()` принимает код исходного языка или `null` и возвращает плоский черновик в виде массива. `removeTranslation()` возвращает `void`.

```php
$pages->addTranslation($id, 'kk', 'ru');
// Или: $pages->addTranslation($id, 'kk', null);
$pages->removeTranslation($id, 'kk');

$collections->addTranslation('tours', $id, 'kk', 'ru');
// Или: $collections->addTranslation('tours', $id, 'kk', null);
$collections->removeTranslation('tours', $id, 'kk');
```

### Роли и права

Право это пара «область и действие». Действия: `view` («Смотреть»), `edit` («Править»), `publish` («Публиковать»), `delete` («Удалять»). `publish` и `delete` включают `edit`, а `edit` включает `view`. Матрица ролей в «Настройки → Пользователи и роли → Роли» строится из областей схемы и подхватывает новые строки сама.

| Область | Что даёт |
|---|---|
| `pages` | Страницы. Роль можно ограничить списком шаблонов |
| `col:<ключ>` | Коллекция |
| `requests` | Заявки (`view`, `edit`) |
| `media` | Медиатека (`view`, `edit`, `delete`) |
| `set:<группа>` | Группа настроек, в том числе `set:site` и `set:analytics` |
| `set:menus`, `set:redirects`, `set:seo` | Меню, переадресации, SEO-правила, `robots.txt` и `llms.txt` |
| `set:notifications`, `set:mail`, `set:bots` | Получатели уведомлений, почтовый сервер, токены ботов |
| `set:theme`, `set:languages`, `set:users`, `set:mcp` | Оформление админки, языки содержимого из `itk_cms.locales` (экран управления запланирован), пользователи и роли, ИИ-ассистенты |
| `templates` | Просмотр шаблонов |
| `dev` | Раздел «Разработчику»: «Состояние сайта» |
| `action:<коллекция>:<действие>` | Запуск действия с `permission()` |

Встроенные роли:

| Роль | Права |
|---|---|
| `admin` («Администратор») | Все области |
| `editor` («Редактор») | Страницы и коллекции полностью, медиатека полностью, просмотр заявок |
| `author` («Автор») | Просмотр и правка страниц шаблонов статей (шаблоны с `card(date: …)`), просмотр и правка медиатеки |
| `requests` («Заявки») | Просмотр и правка заявок |

Каждому вошедшему Symfony даёт роль `ROLE_ITK_USER`. В коде права проверяют `Itk\Cms\Security\Access` и `PermissionVoter` (атрибут `itk`).

### Пароли

- **Свой пароль.** «Мой профиль → Сменить пароль»: текущий пароль, новый не короче 10 знаков и повтор. `POST {admin}/api/me/password {current, password, repeat?}`. Больше пяти попыток за 15 минут дают 429 (`itk_password_change`). Открытые входы с других устройств заканчиваются.
- **Приглашение и сброс администратором** (`POST users`, `POST users/{id}/reset-password`). При настроенной почте человеку уходит ссылка: приглашение живёт 7 дней, сброс 24 часа. Ответ `emailed: true` приходит после того, как письмо принято очередью. Без почты сервер возвращает одноразовый пароль один раз в поле `password`, админка показывает его с кнопкой «Скопировать». Сбросить пароль себе этим вызовом нельзя (403).
- **Забыли пароль.** Ответ одинаковый, есть такой адрес или нет. Страница только ставит сообщение в очередь, а человека ищет и письмо отправляет воркер. Поэтому адрес нельзя узнать и по времени ответа. Ссылка живёт час и срабатывает один раз. В базе хранится только её sha256. Лимиты: 10 запросов в час с адреса посетителя, 3 письма в час на один адрес почты, 10 неверных ссылок за 15 минут (`itk_password_reset_ip`, `itk_password_reset_email`, `itk_password_reset_guess`). Без почты страница предлагает обратиться к администратору.
- Все неиспользованные ссылки человека гаснут, когда ему задают пароль любым путём, меняют почту или блокируют его.
- **Консоль.** `php bin/console itk:user:password name@example.com` спросит пароль скрытым вводом. `--password=…` оставит пароль в истории оболочки.

Ссылки в письмах строятся от доверенного адреса сайта (`Mail\PublicUrl`) в таком порядке: опция `itk_cms.public_url`; адрес запроса, если задан `framework.trusted_hosts`; настройка «Адрес сайта» (`domain`, `https://` по умолчанию); адрес запроса как есть, только в debug. Сырой заголовок `Host` в боевом окружении не используется. Без доверенного адреса ссылка не создаётся: «Забыли пароль?» пишет ошибку в журнал, а приглашение и сброс отдают одноразовый пароль. В боевом окружении задавайте `public_url` или `trusted_hosts`: настройку «Адрес сайта» может поменять любой, у кого есть право `set:site`.

### Двухэтапный вход

Второй шаг это одноразовый код из приложения (TOTP по RFC 6238: SHA-1, 6 цифр, 30 секунд). Подходят Google Authenticator, 1Password, Authy и другие. Менять `security.yaml` не нужно.

- **Включение.** «Мой профиль → Двухэтапный вход»: QR-код и ключ для ручного ввода, подтверждение первым кодом, 10 резервных кодов. Коды показываются один раз, каждый действует один раз. Отключение и новые резервные коды требуют пароль.
- **Вход.** После пароля открывается `/admin/login/otp`. Пока код не введён, в админке работают только эта страница и выход.
- **Обязательно для роли.** Флажок «Требовать двухэтапный вход» в «Настройки → Пользователи и роли → Роли» (`Role::require2fa`). Человек такой роли без фактора видит только окно настройки, остальное отвечает 403 с `details.code = '2fa_enrol'`. Отключить фактор он не может (409).
- **Попытки.** Пять неверных кодов подряд блокируют фактор на 15 минут (429, `details.code = '2fa_locked'`). Код действует один раз, окно ±1 шаг.
- **Хранение.** Секрет зашифрован `sodium_crypto_secretbox` ключом из `APP_SECRET`. Резервные коды хранятся как HMAC-SHA256.
- **Смена `APP_SECRET`** делает секреты и резервные коды нечитаемыми. Люди с включённым фактором не смогут войти, пока им не сбросят фактор.
- **Сброс.** Администратор нажимает «Настройки → Пользователи и роли → Пользователи → Сбросить двухэтапный вход» (`POST users/{id}/reset-2fa`, право `set:users` «Править», не для себя). Если войти некому: `php bin/console itk:user:reset-2fa name@example.com`.
- **API.** `GET 2fa`, `POST 2fa/setup`, `POST 2fa/enable {code}`, `POST 2fa/recovery-codes {password}`, `POST 2fa/disable {password}`. Только про себя, особых прав не нужно.

### История изменений и корзина

Каждая сохранённая правка страницы, записи, группы настроек и меню пишется в `itk_revision`: кто, когда, что изменилось и вся версия целиком. Запись делают сервисы ядра в той же транзакции, что и правку. Правки из консоли, импорта и очереди подписаны «Система», правки через MCP подписаны названием ключа.

- Автосохранения одного человека с перерывом меньше 10 минут склеиваются в одну запись. Правка без изменений записи не оставляет.
- Действия: `create`, `edit`, `publish`, `unpublish`, `revert`, `delete`, `restore`.
- Глубину задают опции `history.keep` (записей на объект и язык, 100) и `history.days` (365). Остальное удаляет `php bin/console itk:history:prune [--keep=N] [--days=N] [--dry-run]`. Расписания в ядре нет, ставьте команду в cron раз в сутки.
- **Вернуть версию** (`POST history/revision/{id}/restore`) значит сделать черновик этой версией. Место в дереве и шаблон не меняются, на сайте ничего не меняется до публикации. Поля, закрытые `canSee()` или `readonly()`, остаются как есть.
- **Корзина.** Удалённая страница или запись хранится в истории целиком. `GET history/trash` отдаёт удалённое. Возврат создаёт объект заново с тем же id, черновиком. Если родителя нет, объект встаёт наверх, если адрес занят, ответ 409.
- **API.** `GET history/{page|item|settings|menu}/{id}?locale=&limit=&offset=`, `GET history/revision/{id}`, `POST history/revision/{id}/restore`, `GET history/trash?kind=&key=`.
- Подпись изменений записывается на языке автора правки и не переводится.

### Одновременные правки

Страница и запись коллекции отдают поле `version`, ключевой хеш черновика. Автосохранение админки присылает его как `baseVersion`. Если черновик тем временем изменили (другой человек, ассистент через MCP, перенос в дереве), сервер ничего не записывает и отвечает `409` с `details.code: "conflict"` и `details.current` (`version`, `by`, `at`, `via`, `viaName`). Редактор останавливает автосохранение и спрашивает: «Показать новую версию» или «Оставить мои правки».

Публикация тоже проверяет `baseVersion` и возвращает `409` при устаревшем черновике. Запрос без `baseVersion` не проверяется: инструменты MCP, консоль, код сайта и `PATCH` работают по правилу «последняя запись побеждает»; публикация тоже, если версия не передана. Инструменты MCP, которые меняют страницу или запись, выполняются по одному на объект под рекомендательной блокировкой PostgreSQL.

Ответ записи коллекции всегда несёт служебные ключи `id`, `locale`, `translated`, `status`, `updatedAt`, `awaiting`, `returned`, `changed`, `changes` и `version`. Не называйте так поля коллекции. Ключ `version` `itk:schema:lint` и `itk:doctor` считают ошибкой.

### Оформление админки

Разработчик задаёт цвета и знак в конфигурации, администратор меняет цвета в «Настройки → Оформление админки» (право `set:theme`).

```yaml
itk_cms:
    admin:
        theme:
            brand: '#0B5D80'        # основной цвет: левая колонка, главные кнопки, ссылки
            accent: '#C8217A'       # акцент: пометки несохранённого, счётчики
            radius: 6               # скругление кнопок и полей, px, от 0 до 16
            logo: /build/logo.svg   # знак в левой колонке и на странице входа; null значит знак ITK CMS
            name: My Studio         # название рядом со знаком, до 40 знаков; null значит «ITK CMS»
```

- Из цвета строится палитра от 50 до 950 в OKLCH. Цвет, на котором не читается ни белый, ни тёмный текст (контраст ниже 4,5:1), сохранить нельзя (422).
- Сохранённый вид хранится в настройке `adminTheme`. «Сбросить» возвращает конфигурацию разработчика.
- Переменные для стилей плагинов: `--brand`, `--brand-ink`, `--brand-text`, `--brand-50` … `--brand-950`, `--accent`, `--accent-ink`, `--accent-text`, `--accent-50` … `--accent-950`, `--r`, `--r-lg`.
- API: `GET admin-theme`, `POST admin-theme/preview`, `PUT admin-theme`, `DELETE admin-theme`.

### Язык админки

Админка, её письма, консоль и сообщения API переведены на английский (`en`), русский (`ru`) и казахский (`kk`). Это язык интерфейса, он не связан с языком содержимого.

```yaml
itk_cms:
    main_locale: en              # язык содержимого, по умолчанию ru
    admin_locale: en             # язык админки по умолчанию, по умолчанию en
    admin_locales: [en, ru, kk]  # из чего человек выбирает; admin_locale должен быть в списке
```

Каждый человек выбирает язык в «Мой профиль» (`PUT {admin}/api/me/locale`). Пустой выбор значит `admin_locale`.

| Где | Язык |
|---|---|
| Админка, её API, MCP | Язык человека, иначе `admin_locale` |
| Страницы входа и восстановления пароля | `admin_locale` |
| Письмо конкретному человеку | Язык этого человека |
| Письма о заявках, сообщения в Telegram и MAX | `admin_locale` |
| Консоль и воркер | `admin_locale` |
| Ответы форм сайта | `main_locale`, если он есть в `admin_locales`, иначе `admin_locale` |
| Холст предпросмотра | Язык содержимого. Панель над секцией на языке человека |

Бандл включает `framework.translator` и дописывает `en` в конец `fallbacks`. В консоли переводчик работает на `admin_locale`. Если код сайта вызывает там `trans()`, передайте язык явно.

**Свои формулировки.** Серверные тексты лежат в `translations/itk_<область>.<язык>.yaml`, области: `content`, `http`, `i18n`, `model`, `ops`, `preview`. Чтобы поменять фразу, положите в `translations/` сайта файл с тем же именем и перечислите в нём только изменённые ключи. Тексты Vue-части админки этим файлом не меняются. Плагин меняет их вызовом `window.ITK.i18n.add('ru', {...})`.

В PHP текст интерфейса даёт `Itk\Cms\I18n\I18n::t('area.key', ['name' => $x])`. Множественное число задаёт параметр `count` и формы через `|` в порядке Symfony. Ключ, которого нет в каталоге, возвращается как есть, а в журнал пишется предупреждение. В Twig есть `itk_t()` и `itk_locale()`.

Сайт не регистрирует новые языки интерфейса. Язык добавляется в сам бандл, порядок описан в [CONTRIBUTING.ru.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.ru.md#как-добавить-язык-интерфейса).

## SEO

### SEO-правила адресов

Правило адреса задаёт заголовок, описание, индексацию (`robots`), канонический адрес и картинку для соцсетей для любого адреса сайта: страницы CMS или маршрута самого сайта. Правила правят в «Настройки → SEO → Правила адресов» (область `set:seo`). Правило важнее SEO самой страницы, но меняет только заполненные поля.

- Адрес правила это путь с необязательной строкой запроса (`/catalog`, `/catalog?page=2`). Правило с параметрами подходит, если те же параметры есть в запросе. Лишние параметры не мешают.
- `match: exact` действует только на этот адрес. `match: prefix` действует на адрес и всё внутри по целым сегментам: `/blog` покрывает `/blog/a`, но не `/blogger`.
- Из подходящих правил выигрывает самое точное: больше параметров, `exact` раньше `prefix`, длиннее путь, правило своего языка, более новое.
- Значение тега берётся из правила, затем из SEO страницы, затем из названия страницы (только `title`), затем из аргумента `itk_seo()`. Относительные `canonical` и `ogImage` становятся полными адресами. На странице ошибки правила не применяются.

Макет выводит теги функцией `itk_seo()`. Она работает в любом шаблоне и возвращает `{title, description, robots, canonical, ogImage, rule, alternates}`:

```twig
{% set seo = itk_seo({title: 'My site', robots: 'index,follow'}) %}
<title>{{ seo.title }}</title>
{% if seo.canonical != '' %}<link rel="canonical" href="{{ seo.canonical }}">{% endif %}
```

API: `GET seo-rules?q=&locale=`, `POST seo-rules`, `PATCH|DELETE seo-rules/{id}`. `GET seo-rules/check?path=&locale=` показывает, что отвечает адрес (маршрут сайта, переадресация, страница CMS или 404), какие правила подходят и откуда берётся каждый тег.

### sitemap.xml

Ядро отдаёт `GET /sitemap.xml` из опубликованных страниц. Писать карту в сайте не нужно.

- В карту входят опубликованные страницы, у каждой `<loc>` и `<lastmod>`. Страницы с закрытой индексацией (в SEO страницы или правилом адреса) не входят. Записи коллекций в карту не входят.
- На одноязычном сайте ссылки строятся из настройки «Адрес сайта» (`domain`). Если схема не указана, используется `https://`. Многоязычный сайт использует одну доверенную базу для ссылок в HTML и карте сайта. Без базы маршрут генерируемой карты отвечает 404. [Многоязычная индексация и эксплуатация](#многоязычная-индексация-и-эксплуатация)
- Карта хранится в кэше час и сбрасывается при публикации, снятии с публикации, изменении или удалении страницы и при изменении правила адреса.
- Файл `public/sitemap.xml` сайта главнее: веб-сервер отдаёт его раньше PHP.

### robots.txt и llms.txt

Ядро отдаёт `GET /robots.txt` и `GET /llms.txt` (описание сайта для ИИ-сервисов в формате [llmstxt.org](https://llmstxt.org)). Текст правят в «Настройки → SEO → robots.txt и llms.txt» (область `set:seo`), правки попадают в историю.

- Отдаётся по порядку: файл в `public/` сайта, если он есть; свой текст из админки; стандартный текст.
- Стандартный `robots.txt` открывает сайт и закрывает адрес админки, `/request/` и `/mcp`. Строка `Sitemap:` появляется, когда задана база карты сайта.
- Стандартный `llms.txt` содержит название сайта, SEO-описание главной, если оно задано, и строку `- [Заголовок](адрес): описание` на каждую индексируемую страницу. На одноязычном сайте используется раздел `## Pages`. На многоязычном сайте каждый язык получает свой раздел.
- Свой текст до 64 КБ. Пустое поле, кнопка «Вернуть стандартный» и текст, равный стандартному, возвращают стандартный текст.
- Страницу с адресом `/robots.txt` или `/llms.txt` создать нельзя.
- Строка «ИИ-поиск» в «Состоянии сайта» предупреждает, если `robots.txt` закрывает хотя бы одного бота ИИ: OAI-SearchBot, Claude-SearchBot, PerplexityBot, Googlebot, Bingbot, YandexBot, YandexAdditional, YandexAdditionalBot, ChatGPT-User, Claude-User, Perplexity-User, GPTBot, ClaudeBot, Google-Extended.
- API: `GET {admin}/api/seo-files` отвечает `{robots: {text, default, custom, file}, llms: {...}}`. `PUT {admin}/api/seo-files {robots?, llms?}`, где `null` или `''` возвращает стандартный текст.

### Разметка schema.org

Ядро кладёт в каждую опубликованную страницу блок `<script type="application/ld+json">` перед `</head>`. Шаблоны менять не нужно. В блоке `@graph`:

- `WebSite`: адрес, название (`siteName`), язык.
- `Organization` или `LocalBusiness`: название, адрес сайта, логотип, телефон, email, адрес (`PostalAddress`), ссылки на соцсети (`sameAs`).
- `BreadcrumbList`: цепочка опубликованных родителей страницы.
- `BlogPosting` у статьи блога: заголовок, дата из поля «Дата» или дата публикации, обложка, описание, автор и издатель.

Данные компании лежат в группе «Сайт» (раздел [Настройки](#настройки)). `organizationLinks` содержит по одной ссылке `http(s)` на строку. `schemaOrgOff` выключает разметку.

- Без «Адреса сайта» блока нет.
- Тип, который сайт уже выводит в своём блоке `application/ld+json`, ядро не повторяет. `Organization` и `LocalBusiness` считаются одним типом.
- В предпросмотре блока нет.

Свои типы добавляет слушатель события `Itk\Cms\Event\SchemaOrgGraph`. В событии есть `page` (`id`, `template`, `locale`, `title`, `url`, `fields`), `base` и методы `add()`, `remove()`, `nodes()`.

```php
#[AsEventListener]
final class ProductMarkup
{
    public function __invoke(SchemaOrgGraph $graph): void
    {
        if ('product' !== $graph->page['template']) {
            return;
        }
        $graph->add([
            '@type' => 'Product',
            'name' => $graph->page['title'],
            'offers' => ['@type' => 'Offer', 'price' => $graph->page['fields']['price'] ?? null, 'priceCurrency' => 'USD'],
        ]);
    }
}
```

### Аналитика и коды подтверждения

Владелец вводит номера счётчиков и коды подтверждения в «Настройки → Основное → Аналитика и вебмастера» (область `set:analytics`, по умолчанию только у администратора). Ядро само ставит их на страницы.

| Поле | Формат |
|---|---|
| Яндекс Метрика: номер счётчика (`yandexMetrikaId`) | От 4 до 12 цифр |
| Вебвизор (`yandexMetrikaWebvisor`) | Переключатель, действует при заданном счётчике |
| Google Analytics 4 (`googleAnalyticsId`) | `G-XXXXXXXXXX` |
| Яндекс Вебмастер, Google Search Console (`yandexVerification`, `googleVerification`) | Код из метатега или метатег целиком. Латиница, цифры, `-` и `_`, от 8 до 120 знаков |

- Метатеги и скрипты встают перед первым `</head>`, `<noscript>` Метрики после `<body>`.
- Теги получают страницы CMS и страницы контроллеров сайта: главный запрос, статус 200 или 404, `text/html`, обычный `Response`. Предпросмотр, админка, API, MCP, профилировщик, 304, переадресации, потоковые и сжатые ответы не меняются.
- Ядро не добавляет счётчик или метатег, который уже есть в HTML с тем же номером или кодом.
- Неверное значение на сайт не выводится. «Состояние сайта» показывает его с пометкой «внимание».
- Согласие на cookie остаётся делом сайта: счётчики загружаются сразу. Если закон требует согласия, не заполняйте эти поля и подключите счётчики своим кодом. Строгий CSP сайта должен разрешать `mc.yandex.ru` и `googletagmanager.com`. Встроенные скрипты ядро не помечает `nonce`.

### Яндекс Вебмастер

Владелец один раз вставляет OAuth-токен Яндекса на экране «Сегодня». Дальше экран показывает, подтверждены ли права, ИКС, число страниц в поиске и популярные запросы.

**Токен.** Администратор сервера регистрирует приложение на [oauth.yandex.ru](https://oauth.yandex.ru/): платформа «Веб-сервисы», Redirect URI `https://oauth.yandex.ru/verification_code`, права `webmaster:hostinfo` и `webmaster:verify`. ID приложения кладут в переменную `ITK_YANDEX_CLIENT_ID` (опция `yandex_webmaster.client_id`). Тогда «Сегодня» показывает ссылку на получение токена. Без ID владелец приносит токен сам. Токен живёт 6 месяцев и не продлевается.

**Подключение.** Ядро проверяет токен, находит сайт с «Адресом сайта» среди сайтов владельца или добавляет его. Код проверки ядро ставит на страницы метатегом `yandex-verification` и просит Яндекс проверить его. Когда права подтверждены, ядро один раз добавляет `/sitemap.xml`. Адрес без публичного хоста (`localhost`, IP, `.test` и другие служебные зоны) подключить нельзя.

- Сводка хранится в кэше `cache.app` и обновляется при открытии «Сегодня», если ей больше 6 часов.
- Состояние (`state` в `GET yandex-webmaster`): `not_connected`, `no_address`, `no_http_client`, `token_invalid`, `verifying`, `verification_failed`, `ready`. За 14 дней до конца срока токена приходит `tokenExpiresSoon`.
- Токен хранится зашифрованным ключом из `APP_SECRET` и не попадает в ответы API, журнал и экспорт настроек. После смены `APP_SECRET` токен вводят заново.
- API (область `set:analytics`): `GET yandex-webmaster` («Смотреть»), `POST yandex-webmaster/connect {token}` («Править»; 422 для неверного токена или адреса, 502, если Яндекс не отвечает), `POST yandex-webmaster/refresh` («Смотреть»), `DELETE yandex-webmaster` («Править»; сайт остаётся в Вебмастере).

### IndexNow

Переключатель «IndexNow: сообщать поисковикам» (`indexNow`) в группе «Аналитика и вебмастера». Когда он включён, ядро по протоколу [IndexNow](https://www.indexnow.org/) сообщает Яндексу и Bing изменённый адрес страницы: публикация, новый адрес (уходят старый и новый), снятие с публикации, удаление, возврат.

- Сообщаются адреса страниц из карты сайта. Записи коллекций не сообщаются.
- Воркер собирает до 100 адресов и отправляет их одним запросом. Полный адрес строится из «Адреса сайта» в момент отправки.
- Ответы `200` и `202` считаются успехом. `429`, `5xx` и обрыв повторяются по `retry_strategy` транспорта `async`. Остальные `4xx` сразу уходят в транспорт ошибок.
- Ключ вычисляется из адреса сайта и `APP_SECRET`, ничего не хранится. Файл ключа `GET /{ключ}.txt` отвечает, пока переключатель включён.
- Ничего не отправляется, пока «Адрес сайта» пуст или не публичный, и без `symfony/http-client`.
- Адрес приёмника задаёт опция `indexnow.endpoint` (по умолчанию `https://yandex.com/indexnow`). Поисковики IndexNow передают сообщения друг другу. Google IndexNow не поддерживает.

## Заявки и уведомления

### Приём заявок

Форма описывается в схеме: `$s->form('call', 'Call request')->fields(...)->telegram('Request #{id}: {phone}')`. Шаблон `telegram()` задаёт текст сообщения в Telegram и MAX.

Сайт отправляет форму на `POST /request/{form}`. Скрытое поле `itk_hp` (ловушка для роботов) должно быть пустым. Ответ `{"success": true}` или `{"success": false, "message": "…", "errors": {...}}`. Лимит 10 заявок за 15 минут с одного адреса (`framework.rate_limiter.itk_request_form`, сайт может переопределить).

Заявка сохраняется и видна в разделе «Заявки» (область `requests`). Затем уведомление `request.new` уходит в каналы: колокольчик админки, Telegram, MAX и почта. У заявки свой статус по каждому мессенджеру (`channels`). Общий статус `delivery` равен `failed`, если заявка не дошла хотя бы в один мессенджер, иначе `sent`, если дошла хотя бы в один. Не подключённый мессенджер оставляет статус `pending`, это не сбой. Повтор недоставленных: кнопка в админке или `php bin/console itk:requests:resend`. В JSON заявки есть и `sendable`: мессенджеры, куда заявка не уходила и куда её можно отправить сейчас (подключены, включены, есть чат для формы). Для каждого карточка показывает «Отправить в MAX» или «Отправить в Telegram» и вызывает `POST requests/{id}/resend {channel}`.

**Длинные поля.** Длина значения считается в символах после обрезки пробелов по краям и удаления тегов. Перевод строки из формы браузера считается за два (`\r\n`). Значение длиннее `max()` своего поля отклоняется с ошибкой этого поля, заявка не создаётся. Поле без `max()` оставляет первые 1001 символ без ошибки, поэтому задавайте `max()` каждому полю, которое бывает длинным. Удаляется только HTML-тег: имя элемента строчными буквами с атрибутами вида `name="value"`, `name='value'` или `name=value`. Любой другой `<` считается текстом, после него ставится пробел (`<500` сохраняется как `< 500`), поэтому в значении не бывает начала тега. Пробел входит в `max()`. Карточка заявки и письмо показывают значение целиком. В колокольчике одна короткая строка. Инструмент MCP `request_get` показывает до 500 символов каждого значения в `data` и до 2000 в `message`, обрезку отмечает знаком `…`.

Текст, который не влезает в одно сообщение (4096 символов в Telegram после разбора HTML, 4000 символов HTML в MAX), уходит несколькими сообщениями по порядку, с разрезом по переводам строк. Между ними воркер ждёт 1 секунду, а в Telegram при более чем 20 сообщениях 3,1 секунды (группа принимает 20 в минуту). Пауза считается внутри одной заявки, поэтому несколько длинных заявок подряд могут превысить этот лимит, тогда отправьте недоставленные через минуту. «Отправить ещё раз» в админке ждёт так же. При более чем 20 сообщениях страница может не дождаться ответа, хотя доставка идёт, поэтому перед вторым нажатием откройте заявку заново. Если одно из них отклонено, следующие в этот чат не отправляются, заявка помечается недоставленной, а ошибка называет номер части. Повтор отправляет все сообщения с первого во все чаты этого мессенджера, поэтому часть из них может прийти в чат дважды.

У экрана «Заявки» есть вид «Доска» с колонками «Новая», «В работе», «Закрыта».

### Telegram

Telegram необязателен. Без него заявка сохраняется, а строка «Telegram» в «Состоянии сайта» пишет «Telegram не подключён».

1. Установите пакеты: `composer require symfony/notifier symfony/telegram-notifier symfony/http-client`.
2. Задайте токен бота в «Настройки → Боты» (раздел ниже). Токен из админки применяется сразу. Запасной вариант задаётся на сервере:

   ```yaml
   framework:
       notifier:
           chatter_transports:
               telegram: '%env(TELEGRAM_DSN)%'   # telegram://BOT_TOKEN@default
   ```

   `null://null` и незаданная переменная значат «не подключён».
3. Укажите чаты в «Настройки → Уведомления» (`tgRoutes`). Запасные чаты задаёт опция `itk_cms.telegram.chat_ids`.

Если Telegram подключён, а для формы нет чата, заявки сохраняются без отправки, и «Состояние сайта» предупреждает. Опция `itk_cms.telegram.enabled: false` выключает отправку. Сбоем считается только сообщение, которое подключённый Telegram не принял. Такая заявка получает «не доставлена» и повторяется по `retry_strategy`.

### MAX

MAX (max.ru) работает рядом с Telegram и независимо от него.

1. Установите бандл: `composer require yuri-kovalev/max-bot-symfony-bundle`.
2. Задайте токен в «Настройки → Боты». Запасной токен сервера:

   ```yaml
   # config/packages/max_bot.yaml
   max_bot:
       access_token: '%env(MAX_BOT_ACCESS_TOKEN)%'
       retry_attempts: []   # повторы делает Messenger
   ```

3. Добавьте бота в чат. ID чата покажет `php bin/console max-bot:chats`. Впишите его в «Настройки → Уведомления → MAX» (`maxRoutes`, целые числа через запятую, у группы число отрицательное). Запасные чаты задаёт `itk_cms.max.chat_ids`.

Выключатель «MAX» (`maxOn`) и опция `itk_cms.max.enabled: false` работают так же, как у Telegram. Кнопка «Проверить» шлёт пробное сообщение. Отказ MAX не меняет статус Telegram, и наоборот.

Получателей хранят настройки `tgOn`, `tgRoutes`, `maxOn`, `maxRoutes` и `mailRoutes` (область `set:notifications`). `GET settings` отдаёт их вместе с признаками `tgFallback`, `maxFallback` (чат задан в конфигурации), `tgReady`, `maxReady` и `mailReady`. Пробное сообщение отправляют `POST settings/telegram-test` и `POST settings/max-test {form?, chatId?}`.

### Боты

В «Настройки → Боты» (область `set:bots`, по умолчанию только у администратора) задают токены Telegram и MAX без правки файлов и перезапуска. Токен из админки главнее серверного. Без обоих вариантов мессенджер не подключён.

- Токены хранятся зашифрованными ключом из `APP_SECRET` в настройках `telegramBot` и `maxBot`. Не объявляйте поля схемы с этими ключами. Токены не попадают в `GET settings`, историю, экспорт и MCP. После смены `APP_SECRET` токен из админки считается незаданным.
- `GET {admin}/api/settings/bots` возвращает для `telegram` и `max` поля `tokenSet`, `source` (`admin`, `server` или `null`) и `ready`; `problem` появляется только при наличии проблемы.
- `PUT {admin}/api/settings/bots {telegram?: {token?, clear?}, max?: {token?, clear?}}`. Пустой `token` оставляет прежний, `clear: true` удаляет токен админки. Токен Telegram должен соответствовать `^\d{5,20}:[A-Za-z0-9_-]{30,}$`, токен MAX без пробелов и до 512 знаков. Токен никогда не возвращается.

Токен Telegram выдаёт `@BotFather`. Токен MAX выдаёт платформа для партнёров business.max.ru или dev.max.ru.

### Почта

Сайт отправляет письма: ссылки «задать пароль» и письма о новых заявках. Письма уходят через очередь `async`, поэтому нужен воркер.

Почтовый сервер выбирается в момент отправки:

1. «Настройки → Почта» включена и содержит сервер и адрес отправителя.
2. Иначе переменная `MAILER_DSN`, если в ней настоящий транспорт. `null://null` значит «почта не настроена».
3. Иначе почта не настроена: письма не уходят, «Забыли пароль?» советует обратиться к администратору, приглашение и сброс показывают одноразовый пароль.

Адрес отправителя берётся из «Настройки → Почта». С одним `MAILER_DSN` без него берётся `noreply@<домен сайта>`.

**Настройки «Почта»** (область `set:mail`, по умолчанию только у администратора). Поля: `enabled`, `host`, `port`, `encryption` (`none`, `ssl`, `starttls`), `username`, `password`, `fromAddress`, `fromName`.

- Пароль хранится зашифрованным ключом из `APP_SECRET` и не возвращается. Ответ содержит только `passwordSet`. Пустой `password` оставляет прежний, `clearPassword: true` стирает его.
- Если меняются `host` или `username`, новый пароль должен прийти в том же запросе, иначе 422. Так старый пароль нельзя отправить на чужой сервер.
- `GET {admin}/api/settings/mail` возвращает поля, `passwordSet`, `configured`, `source` (`settings`, `env`, `none`), `problem` и `last` (итог последней отправки).
- `PUT {admin}/api/settings/mail` сохраняет настройки.
- `POST {admin}/api/settings/mail-test {to?}` отправляет тестовое письмо сразу, без очереди. Причина отказа сервера приходит в ответе (502). Не больше 10 писем в час на человека (429).
- `ssl` использует `smtps://`, `starttls` требует TLS, `none` выключает его. Журнал SMTP-диалога не пишется.

**Ошибки.** Письмо, которое не ушло после всех повторов, лежит в транспорте ошибок, а в колокольчик приходит уведомление `mail.failed`. Письмо со ссылкой «задать пароль» лежит там целиком, вместе со ссылкой. Чистите транспорт ошибок (`messenger:failed:remove`) и не давайте к нему доступ посторонним.

**Письма о заявках.** «Настройки → Уведомления → Письма о новых заявках» (`mailRoutes`, область `set:notifications`): адреса через запятую для всех форм и свой список для каждой формы, до 10 адресов. На каждый адрес уходит письмо с полями формы и ссылкой на заявку.

Для своего периода заявок задавайте даты в формате `ГГГГ-ММ-ДД`. События доставки в Telegram и MAX вызываются после того, как CMS сохранит итог.

**Шаблоны писем.** `@ItkCms/email/layout.html.twig` и пары `reset_password`, `invite`, `request`, `test` (`.html.twig` и `.txt.twig`). Сайт переопределяет любой файл своим файлом в `templates/email/`, например `templates/email/layout.html.twig`. Внутри можно расширить шаблон бандла: `{% extends '@ItkCms/email/reset_password.html.twig' %}`.

### Уведомления в админке и свои каналы

Сообщения для тех, кто ведёт сайт, отправляет `Itk\Cms\Notification\NotifierInterface::notify()`. Его вызывают ядро и код сайта.

```php
$notifier->notify(new Notification(
    type: 'order.paid',
    title: 'Order #15 paid',
    body: '3,200 USD',
    link: '/collections/orders',      // маршрут админки или полный http(s)-адрес
    level: Notification::WARNING,     // INFO, WARNING, ERROR
    audience: 'col:orders',           // null всем вошедшим; право ('requests', 'col:tours:edit'); 'role:editor'
    payload: ['orderId' => 15],       // данные для каналов, без секретов
    key: 'order.paid:15',             // одно уведомление на ключ
));
```

- **Каналы** реализуют `NotificationChannelInterface` (`name()`, `supports()`, `send()`), тег `itk.notification_channel` ставится сам. Класс, который не сервис, называют в `Registry::notificationChannels()`. Ошибка канала пишется в журнал и не мешает остальным.
- **`DatabaseChannel`** хранит уведомление для колокольчика. Человек видит уведомления по своим правам и роли, отправленные после его регистрации. Старые удаляет `php bin/console itk:notifications:prune [--days=90] [--dry-run]`, ставьте её в cron.
- **`TelegramChannel`, `MaxChannel`, `EmailChannel`** доставляют только `request.new`.
- **События ядра:** `request.new` (новая заявка, для права `requests`), `telegram.failed` и `max.failed` (заявка не дошла), `mail.failed` (письмо не ушло). Уведомление о сбое убирается, когда доставка удалась.
- **Исход доставки** приходит событием Symfony `Itk\Cms\Event\RequestDelivery { request, delivered, error, cancelled, channel }`. `channel` равен `telegram` или `max`.
- **Язык.** `new Notification(title: …)` хранит готовый текст. `Notification::translated(type: …, titleKey: 'area.key', titleParams: [...])` хранит ключ, и каждый читатель видит заголовок на своём языке.
- **API.** `GET notifications?unread=1&limit=&offset=`, `POST notifications/read { ids } | { all: true }`. Каждый читает только своё.

## База знаний

База знаний это таблица pgvector с текстом опубликованных страниц и записей, разбитым на фрагменты с эмбеддингами OpenAI. Сервис `KnowledgeBase\KnowledgeSearch` ищет по смыслу. Воркер обновляет индекс после публикации и снятия с публикации, удаление убирает строки само. Выключено по умолчанию.

**Что уходит наружу.** Опубликованный текст сайта отправляется в API OpenAI. Это только то, что видит любой посетитель, но решение об отправке принимает владелец сайта. Черновики, скрытые секции, поля `raw()`, поля с `canSee()` и страницы с `noindex` не отправляются.

**Как включить.** Нужны пакет `symfony/http-client` и ключ OpenAI.

1. `composer require symfony/http-client`. Положите ключ в `.env.local`: `OPENAI_API_KEY=sk-...`.
2. Включите опцию:

   ```yaml
   itk_cms:
       knowledge_base:
           enabled: true
   ```

3. Заполните индекс: `php bin/console itk:knowledge-base:sync --now`. Команда печатает число отправленных текстов и потраченных токенов.
4. Запустите воркер для транспорта `knowledge_base`.
5. Проверьте поиск: `php bin/console itk:knowledge-base:search "how to return an item"`.

В сценарий выкладки добавьте `itk:knowledge-base:sync`. Команда ставит в очередь только то, что устарело после выкладки (схема, конфигурация, модель).

**Что индексируется.** Страницы из карты сайта (опубликованные, без `noindex`) и опубликованные записи коллекций, кроме `exclude_templates` и `exclude_collections`. Из каждой версии берётся текст, который видит посетитель: заголовок, SEO-описание, поля шаблона, видимые секции по порядку. `Text`, `Textarea`, `Headline`, `Description` и `Article` дают текст. `Select` даёт «подпись: вариант», `Image` и `Photos` дают `alt`, каждая строка `Repeater` даёт отдельный блок. `Number`, `Toggle`, `Date`, `Link`, `Relation`, `Note`, поля `variant()`, `raw()` и `canSee()` не индексируются.

**Фрагменты.** Блоки одной секции складываются во фрагменты примерно по 1200 знаков, не больше 2000. Фрагмент не пересекает границу секции, поэтому у попадания точный `sectionId`. Перед текстом фрагмента в эмбеддер уходят заголовок страницы и заголовок блока.

**Обновление.** Эмбеддинг строится только для текста, которого ещё нет в индексе. Воркер собирает до 32 сообщений в один запрос к API. Снятая с публикации страница пропадает из результатов сразу. Автосохранение черновика ничего не запускает. Индекс догоняет публикацию за время до минуты: транспорт Doctrine опрашивает очереди раз в `check_delayed_interval` (60 секунд). Быстрее работает отдельный воркер `messenger:consume knowledge_base`.

**Смена модели.** После смены `model` поиск ничего не находит, пока `itk:knowledge-base:sync --now` не перестроит индекс. «Состояние сайта» показывает, сколько документов осталось в старой модели.

**Поиск из кода.**

```php
$result = $knowledge->search($request->query->getString('q'), new SearchOptions(limit: 20));
foreach ($result->bySource() as $hit) {
    // $hit->source->title, $hit->source->url, $hit->text
}
```

- `SearchOptions`: `locale`, `limit` (от 1 до 50, по умолчанию 8), `templates` и `collections` (`null` все, `[]` ни одного), `minScore`.
- `SearchResult::$hits` это список `Hit` по убыванию `score`. `bySource()` оставляет лучшее попадание на страницу или запись.
- `Hit`: `score`, `text`, `heading`, `source` (`kind`, `id`, `locale`, `resourceKey`, `title`, `url`), `sectionId`, `sectionType`, `field`.
- Выключенная база или отказ API бросает `KnowledgeBaseUnavailable`. Неизвестный шаблон или коллекция бросает `\InvalidArgumentException`.

**Команды.** `itk:knowledge-base:sync` ставит в очередь отсутствующие, устаревшие и лишние документы. `--now` делает это в процессе и выходит с кодом 1 при неудаче. `--dry-run` печатает счётчики и примерную стоимость. `--source=page:<id>[:<locale>]` или `item:<id>[:<locale>]` ограничивает одним источником. `itk:knowledge-base:search "<запрос>" [--locale=] [--limit=8] [--template=] [--collection=]` печатает попадания таблицей.

**Неудачи.** 429, ошибка сервера и обрыв сети повторяются через 10, 30, 90, 270 и 810 секунд, потом сообщение уходит в транспорт ошибок. Неверный ключ, неизвестная модель и отсутствие `symfony/http-client` сразу уходят в транспорт ошибок. После исправления их возвращает `messenger:failed:retry`.

Сайт может заменить эмбеддер своей реализацией `KnowledgeBase\Embedding\Embedder` с векторами размерности 1536.

## Производительность

### Заголовки и ответ 304

- **Заголовки безопасности.** Публичные маршруты бандла (страницы, `sitemap.xml`, `robots.txt`, `llms.txt`, копии картинок, `POST /request/{form}`, `/healthz`) получают `X-Content-Type-Options: nosniff` и `Referrer-Policy: strict-origin-when-cross-origin`. Опция `security_headers: false` выключает их. Заголовки страниц своих контроллеров сайт ставит сам.
- **ETag и 304.** Посетитель без сессии и без `Authorization` получает у страницы `ETag`, `Last-Modified` и `Cache-Control: no-cache, private`. Если он присылает тот же `ETag` в `If-None-Match`, ответ 304 приходит без отрисовки.
- Метка зависит от всего, что страница может показать: самой страницы, опубликованных страниц и их порядка, настроек, меню, опубликованных записей, кадров картинок, SEO-правил, строки запроса, сборки контейнера и текущей даты по UTC. Черновики на метку не влияют. Поэтому страница, которая зависит от времени суток, обновляется не реже раза в сутки. Если страницы должны обновляться чаще, выключите `conditional_responses`.
- Для метки выполняется один запрос с суммами по таблицам, в том числе по всем записям коллекций. На 30 000 записей это около 0,1 секунды. Тот же запрос строит ключ кэша страниц. У сайта с очень большими коллекциями выключите и `conditional_responses`, и `page_cache.enabled`.
- В режиме debug метка не отправляется. В боевом окружении выполняйте `cache:clear` после выкладки новых шаблонов. Иначе посетители с сохранённой страницей получат 304 со старой вёрсткой.

### Кэш страниц

Готовый HTML опубликованной страницы хранится в кэше, и повторный показ идёт без отрисовки. Правки видны посетителям сразу: ключ записи строится из того же отпечатка, что и `ETag`, и любая правка даёт новый ключ.

- **Ключ.** Схема, хост, базовый путь и отпечаток содержимого. Куки, `User-Agent`, `Accept-Language` и другие заголовки в ключ не входят.
- **Что хранится.** Только ответ `200` на `GET` без `Set-Cookie`, если сессия не стартовала. 404, переадресации, предпросмотр, `sitemap.xml`, `robots.txt`, `llms.txt` и формы не хранятся. `HEAD` берёт запись, которую сделал `GET`.
- **Мимо кэша** идут запросы при выключенной опции и в debug, с `Authorization` или сессией, с методом не `GET` и не `HEAD`, со строкой запроса, с заголовком `Forwarded` или `X-Forwarded-Prefix`, по написанию адреса, которое отличается от адреса страницы (`/ABOUT`, `//about`), и к хосту или порту, которым нет доверия (их нет в `framework.trusted_hosts`, а без доверенных хостов они не совпадают с хостом и портом адреса сайта). Так посторонний не наполнит диск записями и не подменит страницу для всех.
- **Свой хост.** Хост кэшируется, если задан `framework.trusted_hosts` или хост совпадает с «Адресом сайта». Порт должен быть портом по умолчанию или портом из «Адреса сайта». Без этого кэш не работает. Сайт за прокси задаёт `trusted_proxies`.
- **`X-ITK-Cache`.** Каждый ответ страницы несёт `HIT`, `MISS` или `BYPASS`. Проверить кэш можно командой `curl -I`. 304 отвечает `HIT`.
- **Что отпечаток не видит.** Данные в собственных таблицах сайта и время внутри суток. Для них есть срок записи `page_cache.ttl` (3600 секунд) и функция `itk_cache()`. `{% do itk_cache(300) %}` хранит отрисовку не дольше 300 секунд, `{% do itk_cache(false) %}` не хранит совсем. Из нескольких вызовов побеждает `false`, иначе меньшее число. **Шаблон, вывод которого зависит от куки, `User-Agent` или `Accept-Language`, обязан вызвать `{% do itk_cache(false) %}`.** `itk_cache()` не влияет на 304.
- **Ручной сброс.** `PageCache::clear()` из кода сайта (возвращает `false`, если пул отказал), команда `itk:cache:clear` и кнопка «Сбросить кэш страниц» в «Состоянии сайта» (`POST {admin}/api/health/page-cache/clear`, область `dev` «Править»). Сбрасывается только пул кэша страниц.
- **Хранилище.** Пул `itk_cms.page_cache` на адаптере `cache.app`, на одном сервере это файлы в `var/cache/<env>/pools`. Для нескольких серверов задайте пулу общий адаптер в `framework.cache.pools`. Просроченные записи удаляются не чаще раза в 10 минут. Поставьте в cron `bin/console cache:pool:prune`.

### Критический CSS

Правила, которые нужны первому экрану, лежат прямо в `<head>`, а полный файл стилей подключается без блокировки отрисовки. Правила вырезает [crittr](https://github.com/philipp-winterle/crittr) в воркере после каждой публикации. Выключено по умолчанию.

**Как включить.** Нужны Node 22 или новее и Chrome или Chromium на сервере.

1. В корне проекта выполните `npm install crittr`. Puppeteer скачает Chrome для пользователя, который запустил команду. Чтобы взять системный Chrome, задайте опцию `chrome`.
2. Включите опцию и перечислите таблицы стилей:

   ```yaml
   itk_cms:
       critical_css:
           enabled: true
           stylesheets: ['/css/site.css']
   ```

3. В макете замените `<link rel="stylesheet" href="/css/site.css">` на `{{ itk_stylesheets() }}`.
4. Проверьте одну страницу: `php bin/console itk:critical-css:generate --page=<id> --now`. Команда печатает причину каждой неудачи.
5. Запустите воркер для транспорта `critical_css`.
6. Поставьте в очередь остальные страницы: `php bin/console itk:critical-css:generate` (`--all` для всех опубликованных).

**Что получает посетитель.** На странице с готовым критическим CSS `itk_stylesheets()` печатает:

```html
<style>…</style>
<link rel="stylesheet" href="/css/site.css?v=3f9a1c2e" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="/css/site.css?v=3f9a1c2e"></noscript>
```

В остальных случаях (выключено, ещё не готово, не получилось, предпросмотр, контроллер сайта, страница ошибки) функция печатает обычную ссылку. `?v=` меняется вместе с файлом стилей. Пока опция выключена, `?v=` нет.

- В `<style>` лежит объединение правил всех окон из `viewports` с сохранением каскада. Если окна не удаётся согласовать, выводится по одному `<style media="…">` на диапазон ширин.
- Критический CSS устаревает при новой публикации страницы, новой сборке контейнера и изменении таблиц стилей. Устаревший CSS показывается, пока воркер делает новый. Первый показ просит воркер пересчитать страницу. После выкладки нового CSS делать ничего не нужно, `itk:critical-css:generate` в сценарии выкладки только ускорит пересчёт.
- Настройки, меню и записи коллекций на критический CSS не влияют. Если меню изменило первый экран, выполните `itk:critical-css:generate --all`.
- Страница, для которой crittr не справился, больше не пересчитывается сама. Её повторяют `itk:critical-css:generate`, новая публикация или новый CSS.
- Результат больше `max_kb` считается неудачей. Если в CSS есть `!important` или `@layer`, воркер пишет предупреждение: crittr их теряет.
- Генератор не работает с транспортом `sync`.

**CSP.** Вызов `{{ itk_stylesheets({nonce: nonce}) }}` добавляет nonce к каждому критическому `<style>` и выводит обычные ссылки для полных файлов стилей. Разрешите их источник в CSP сайта.

**В Docker** образ собирается с Node и Chromium аргументом `CRITICAL_CSS=1` (раздел [Критический CSS в Docker](#критический-css-в-docker)).

## MCP: ИИ-ассистенты

ITK CMS работает как сервер [Model Context Protocol](https://modelcontextprotocol.io). Claude, Cursor, VS Code и другие клиенты читают сайт и готовят правки в черновиках. Ассистент действует от имени сотрудника, который выдал ключ, и никогда не получает больше его прав. Каждый вызов проходит те же права, правила полей, хуки и историю, что и API админки.

### Включение и ключи

1. **Переключатель.** «Настройки → ИИ-ассистенты» (область `set:mcp`, по умолчанию только у администратора). Пока MCP выключен, `/mcp` отвечает 404, `itk:mcp` не запускается и ключи не создаются. На этом экране есть адрес сервера, примеры подключения и список ключей всех сотрудников с кнопкой «Отозвать».
2. **Ключ.** «Мой профиль → Мои токены для ИИ-ассистентов»: название, доступы и срок (30 или 90 дней, год, без срока; по умолчанию 90 дней). Ключ вида `itk_` и 43 знака показывается один раз, в базе хранится его sha256. У человека не больше 20 действующих ключей и 10 новых в час. Ключ создаётся только из сессии, прошедшей двухэтапный вход, если он включён.
3. **Доступы.** Доступ сужает права человека. Инструменту нужны и доступ ключа, и право человека.

   | Доступ | Что даёт | По умолчанию |
   |---|---|---|
   | `read` | Смотреть страницы, записи, медиатеку, меню, настройки, SEO, историю | да |
   | `write` | Создавать и менять черновики, загружать файлы | нет |
   | `publish` | Публиковать и снимать с публикации (нужно право «Публиковать») | нет |
   | `requests` | Читать и обрабатывать заявки, в том числе телефоны клиентов | нет |

   **`requests` нельзя соединять с `write` и `publish` в одном ключе.** Заявки пишут посетители, и ключ, который читает их и меняет страницы, мог бы выполнить чужую инструкцию из заявки. Сервер отвечает на такой ключ 422. Нужны оба доступа, значит нужны два ключа.
4. **Что останавливает ключ.** Отзыв, конец срока, блокировка владельца, выключенный переключатель, роль, которой теперь нужен двухэтапный вход. Новый пароль владельца ключи не останавливает. Права берутся в момент вызова. Ключ проверяется на каждом сообщении, поэтому отзыв останавливает и уже запущенного ассистента.

### Подключение

HTTP: каждый вызов это `POST /mcp` с заголовком `Authorization: Bearer itk_…`, ответ `application/json`, без сессий и потока событий.

```bash
# Claude Code
claude mcp add --transport http mysite https://example.com/mcp --header "Authorization: Bearer itk_…"
```

```jsonc
// Claude Desktop, claude_desktop_config.json (нужен Node.js, mcp-remote соединяет приложение с сайтом)
{ "mcpServers": { "mysite": {
    "command": "npx",
    "args": ["-y", "mcp-remote", "https://example.com/mcp", "--header", "Authorization:${AUTH_HEADER}"],
    "env": { "AUTH_HEADER": "Bearer itk_…" } } } }
```

```jsonc
// Cursor: ~/.cursor/mcp.json или .cursor/mcp.json в проекте; ключ в переменной окружения
{ "mcpServers": { "mysite": {
    "url": "https://example.com/mcp",
    "headers": { "Authorization": "Bearer ${env:ITK_MCP_TOKEN}" } } } }
```

```jsonc
// VS Code (GitHub Copilot, режим агента): .vscode/mcp.json; ключ хранится в защищённом хранилище
{ "servers": { "mysite": {
    "type": "http",
    "url": "https://example.com/mcp",
    "headers": { "Authorization": "Bearer ${input:itk-key}" } } },
  "inputs": [ { "type": "promptString", "id": "itk-key", "description": "ITK CMS key", "password": true } ] }
```

**claude.ai.** В Customize → Connectors добавьте свой коннектор с адресом `https://example.com/mcp`, способом входа «Без входа» и заголовком `Authorization` со значением `Bearer itk_…` в разделе «Заголовки запросов». Заголовки запросов доступны не всем организациям. Ключ один на всех пользователей коннектора, и после сохранения его не изменить. Сайт должен быть доступен из интернета по HTTPS.

stdio: ассистент сам запускает `itk:mcp`. Ключ передавайте переменной `ITK_MCP_TOKEN`, а не `--token`: аргументы видны другим пользователям машины в списке процессов.

```jsonc
{ "mcpServers": { "mysite": { "command": "php", "args": ["/var/www/site/bin/console", "itk:mcp"], "env": { "ITK_MCP_TOKEN": "itk_…" } } } }
// в Docker
{ "mcpServers": { "mysite": { "command": "docker",
    "args": ["compose", "--project-directory", "/path/to/site", "exec", "-T", "-e", "ITK_MCP_TOKEN", "php", "bin/console", "itk:mcp"],
    "env": { "ITK_MCP_TOKEN": "itk_…" } } } }
```

Сервер поддерживает версии протокола `2025-11-25`, `2025-06-18`, `2025-03-26` и `2024-11-05`. Ревизию `2026-07-28` без рукопожатия `initialize` сервер не поддерживает: на неизвестный заголовок `MCP-Protocol-Version` он отвечает 400. Авторизация OAuth не предлагается.

### Инструменты

| Доступ | Инструменты |
|---|---|
| `read` | `site_schema`, `pages_list`, `page_get`, `collection_list`, `collection_item_get`, `media_search`, `media_get`, `menus_get`, `settings_get`, `seo_rules_list`, `history_list` |
| `write` | `page_create`, `page_update`, `page_section_add`, `page_section_update`, `page_section_move`, `page_section_remove`, `collection_item_create`, `collection_item_update`, `media_upload` |
| `publish` | `page_publish`, `page_unpublish`, `collection_item_publish` |
| `requests` | `requests_list`, `request_get`, `request_update` |

- `site_schema` без аргументов даёт обзор, с `kind` и `key` полное описание одного раздела. То же отдаёт ресурс `itk://schema`.
- `settings_get` не отдаёт пароли, токены, почтовый сервер, чаты и адреса уведомлений.
- `media_upload` принимает файл в base64 до 5 МБ. Файлы по адресу сервер не скачивает.
- `page_unpublish` оставляет страницу черновиком. Главную снять нельзя.
- Удалять страницы, записи, файлы и правила, менять пользователей, роли, ключи, настройки, переадресации и возвращать версии через MCP нельзя.
- Поля `raw()` через MCP не меняет никто, даже администратор. Убрать или переместить секцию или элемент списка с таким полем можно.
- В поле-картинку ассистент кладёт только файл из медиатеки.
- Ошибка, которую может исправить модель, приходит результатом с `isError: true` и текстом. Ошибки протокола приходят кодами JSON-RPC.
- Подсказка `destructiveHint: true` стоит у инструментов, которые заменяют значения, публикуют или снимают с публикации.
- Правки ассистента пишутся в историю с пометкой «через ИИ-ассистента» и названием ключа.

### Защита

- Запрос с заголовком `Origin` принимается, только если это адрес сайта, адрес из `mcp.allowed_origins` или localhost в debug. Остальным ответ 403. Программы-ассистенты `Origin` не присылают. Это не CORS: на `OPTIONS` сервер отвечает 405.
- Лимит 120 вызовов в минуту на ключ (`itk_mcp_token`), каждое сообщение пакета считается вызовом. 20 неверных ключей за 15 минут с одного адреса дают 429 (`itk_mcp_failed_auth`). Лимиты используют `framework.lock`. На нескольких серверах задайте общее хранилище блокировок.
- Тело запроса не больше `mcp.max_body_mb` (8 МБ). Для `media_upload` поднимите `client_max_body_size` веб-сервера и `post_max_size` PHP.
- Заголовок `Authorization` должен доходить до PHP. На Apache добавьте `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
- Адрес `/mcp` занят ядром, страницу с таким адресом создать нельзя.
- Тексты посетителей из заявок приходят модели в рамке `<untrusted-data>…</untrusted-data>`. Рамка это подсказка модели, а не защита. Защищают раздельные ключи для заявок и правок и запрет на запись `raw()`.
- Давайте ключу минимальные доступы и срок. Ключ равен паролю на своих правах: храните его в менеджере паролей, а при утечке отзовите.

### Журнал

Канал Monolog `itk_mcp` получает каждый вызов инструмента и чтение ресурса строкой `info` и каждый отказ строкой `warning`: отказ входа (`missing`, `invalid`, `revoked`, `expired`, `blocked`, `two_factor`, `too_many_wrong_keys`), неизвестный инструмент, неверные аргументы, лимит. Аргументы, ответы и ключ не пишутся никогда. Обработчик `fingers_crossed` теряет эти строки, если в запросе нет ошибки. Дайте каналу свой обработчик:

```yaml
# config/packages/monolog.yaml
when@prod:
    monolog:
        handlers:
            main:
                type: fingers_crossed
                action_level: error
                handler: nested
                channels: ["!itk_mcp"]
            mcp_audit:
                type: stream
                path: php://stderr
                level: info
                channels: [itk_mcp]
                formatter: monolog.formatter.json
```

### Свой инструмент

```php
final class ToursCountTool extends AbstractMcpTool
{
    public function __construct(private readonly TourRepository $tours) {}

    public function name(): string { return 'tours_count'; }
    public function description(): string { return 'Count tours by state.'; }
    public function inputSchema(): array { return self::schema(['state' => self::string('draft or published', 20)], ['state']); }
    public function requiredScope(): string { return McpScopes::READ; }
    public function annotations(): array { return ['readOnly' => true, 'destructive' => false, 'idempotent' => true]; }

    public function call(array $args, McpContext $context): array
    {
        $context->require('col:tours', 'view');
        return ['count' => $this->tours->countByState((string) $args['state'])];
    }
}
```

- Сервис с `McpToolInterface` подхватывается сам (тег `itk.mcp_tool`). Класс без аргументов конструктора можно назвать в расширении: `$registry->mcpTools(ToursCountTool::class)`.
- Описание для модели пишите по-английски. `requiredScope()` возвращает `READ`, `WRITE`, `PUBLISH` или `REQUESTS`.
- `annotations()` обязателен и должен содержать булевы `readOnly` и `destructive`. Значения по умолчанию нет.
- Набор инструментов проверяется при первой сборке. Два класса с одним именем, неизвестная область или неполные `annotations()` бросают `McpToolListError` с именами классов. Клиент получает `Internal error`, текст пишется в канал `itk_mcp`.
- Аргументы проверяются по `inputSchema()` до вызова: `type`, `enum`, `required`, `properties`, `additionalProperties`, `items`, `minimum`, `maximum`, `minLength`, `maxLength`, `minItems`, `maxItems`.
- Если ввод нужно поправить, бросайте `McpToolException` с понятным текстом. Инструмент, доступный не всем, реализует `McpConditionalTool::isAvailableTo()`.
- Пишущий инструмент вызывает сервисы ядра (`PageService`, `CollectionService`), а не базу. Тогда права, хуки и история работают сами.

## Состояние сайта и itk:doctor

### Экран «Состояние сайта»

«Разработчику → Состояние сайта» (`{admin}#/health`) это экран для того, кто следит за сайтом. Область `dev`: «Смотреть» открывает экран, «Править» разрешает повторять и удалять упавшие задачи, отправлять заявки снова и сбрасывать кэш страниц. По умолчанию область есть только у администратора.

- **Фоновые задачи.** Сколько сообщений ждёт в каждой очереди и до 50 последних упавших из транспорта ошибок: задача, причина, число попыток. «Повторить» возвращает сообщение в его очередь, «Удалить» убирает. Транспорт, который не удалось собрать, показывается с причиной.
- **Заявки без доставки.** Последние 20 недоставленных заявок без имён и телефонов. «Отправить снова» шлёт заявку в мессенджер, куда она не дошла.
- **Расписание.** Задачи Symfony Scheduler (`#[AsSchedule]`) и время следующего запуска, если они есть.
- **Диск.** Свободное место для `upload_dir` и `var/`. Меньше 2 ГБ даёт «внимание», меньше 512 МБ даёт «ошибку».
- **Версии.** PHP, Symfony и `itk/cms`. Проверка обновлений работает, когда задана опция `update_source`. Подходит адрес, который отдаёт `{"version": "…"}`, GitHub `releases/latest` или метаданные Packagist, например `https://repo.packagist.org/p2/itk/cms.json`. Ответ кэшируется на 12 часов.
- **Проверки ядра.** База данных, PHP, `APP_SECRET`, папка загрузок, почта, Telegram, MAX, файрвол MCP, аналитика, Яндекс Вебмастер, IndexNow, ИИ-поиск (боты ИИ, закрытые в `robots.txt`), критический CSS, база знаний. Каждая проверка показывает `ok`, «Не подключено», «внимание» или «ошибку». «Не подключено» (`off`) это жёлтая метка модуля, который выключен или не настроен, например Яндекс Вебмастера, Telegram или IndexNow. Это не проблема: общий статус экрана и код выхода `itk:doctor --ci` не меняются.
- **API** (область `dev`): `GET health`, `POST health/messenger/{id}/retry|delete`, `POST health/requests/{id}/resend {channel?}`, `POST health/updates/refresh`, `POST health/page-cache/clear`.

**Свои проверки.** Класс реализует `Itk\Cms\Health\HealthCheckInterface`: `name()` и `check(): HealthResult` (`HealthResult::ok|off|warn|fail('text')`). `off` возвращают, когда модуль выключен или не подключён: он считается как `ok` и не делает отчёт предупреждением. В папке, которую сканирует контейнер сайта, класс подхватывается сам (тег `itk.health_check`). Из пакета его называют в `Registry::healthChecks()`. Проверка должна быть быстрой. Исключение показывается строкой «ошибка» и не ломает экран.

### itk:doctor

`php bin/console itk:doctor` проверяет сайт за один проход. Команда подходит для выкладки и CI.

| Группа | Что проверяет |
|---|---|
| Окружение | Расширения PHP, WebP, AVIF, `APP_SECRET`, права на `upload_dir`, база |
| Сайт | Доверенный адрес сайта, применённые миграции, опубликованная главная, файрволы админки и `/mcp`, очередь и воркер (сообщения старше 5 минут), собранная админка |
| Состояние сайта | Всё, что показывает экран «Состояние сайта» |
| Схема | То же, что `itk:schema:lint` |

Каждая строка это `ok`, `выкл`, `внимание` или `ОШИБКА`. `выкл` отмечает модуль, который не подключён, и только считается в итоге. С `--ci` команда выходит с кодом 1, если есть хотя бы одна ошибка.

### /healthz

`GET /healthz` это открытый адрес для балансировщика: `{"status":"ok"}` (200) или `{"status":"fail"}` (503). Он проверяет, что база отвечает и что в `upload_dir` можно записать файл. Страницу с адресом `/healthz` создать нельзя.

## Консольные команды

| Команда | Что делает |
|---|---|
| `itk:install` | Готовит роли, области меню, настройки, очереди, главную страницу и первого администратора. Первый пароль содержит не менее 10 символов. Опции `--email`, `--password`, `--name`, `--site-name`, `--domain`, `--no-home` |
| `itk:doctor [--ci]` | Проверяет сайт |
| `itk:schema:lint` | Проверяет схему и шаблоны Twig |
| `itk:user:password <email> [--password=]` | Задаёт пароль |
| `itk:user:reset-2fa <email>` | Сбрасывает двухэтапный вход |
| `itk:requests:resend` | Ставит в очередь все недоставленные заявки |
| `itk:media:warm [--width=]` | Создаёт копии картинок медиатеки |
| `itk:cache:clear` | Сбрасывает кэш страниц |
| `itk:critical-css:generate` | Ставит в очередь страницы без готового критического CSS. `--all` все страницы, `--page=<id>`, `--locale=`, `--dry-run`, `--now` в этом процессе с отчётом о неудачах |
| `itk:knowledge-base:sync` | Обновляет базу знаний. `--now`, `--dry-run`, `--source=` |
| `itk:knowledge-base:search <запрос>` | Ищет в базе знаний. `--locale`, `--limit`, `--template`, `--collection` |
| `itk:history:prune` | Удаляет старую историю. `--keep`, `--days`, `--dry-run` |
| `itk:notifications:prune` | Удаляет старые уведомления. `--days` (90), `--dry-run` |
| `itk:mcp` | Сервер MCP через stdio. Ключ в `ITK_MCP_TOKEN` |
| `itk:make:template`, `itk:make:section`, `itk:make:collection`, `itk:make:settings`, `itk:make:field`, `itk:make:plugin` | Генераторы кода, раздел [Генераторы кода](#генераторы-кода) |

В cron обычно ставят `itk:history:prune`, `itk:notifications:prune` и `cache:pool:prune` раз в сутки.

## Справочник конфигурации

Все опции задаются в `config/packages/itk_cms.yaml` и необязательны.

| Опция | По умолчанию | Что делает |
|---|---|---|
| `schema_file` | `%kernel.project_dir%/config/itk/schema.php` | Файл схемы сайта |
| `main_locale` | `ru` | Основной язык содержимого. При заданном `locales` пробелы по краям удаляются, буквы приводятся к нижнему регистру |
| `locales` | Не задан | Языки содержимого и их названия по кодам. См. [Настройка языков содержимого](#настройка-языков-содержимого) |
| `admin_locale` | `en` | Язык админки по умолчанию. Должен входить в `admin_locales` |
| `admin_locales` | `[en, ru, kk]` | Языки, из которых выбирает человек |
| `admin_path` | `/admin` | Адрес админки. Формат проверяется при старте |
| `public_url` | `null` | Адрес сайта для ссылок в письмах, например `https://example.com`. Можно `%env(PUBLIC_URL)%` |
| `strict` | `null` (как `kernel.debug`) | Строгий режим шаблонов |
| `blog` | `true` | `false` убирает шаблоны блога |
| `security_headers` | `true` | Заголовки безопасности на публичных маршрутах |
| `conditional_responses` | `true` | `ETag`, `Last-Modified` и 304 для страниц |
| `page_cache.enabled` | `true` | Кэш страниц |
| `page_cache.ttl` | `3600` | Срок записи в секундах, не меньше 1 |
| `upload_dir` | `%kernel.project_dir%/public/uploads/media` | Папка медиатеки |
| `upload_url` | `/uploads/media` | Адрес папки медиатеки |
| `max_upload_mb` | `20` | Предел файла медиатеки |
| `images.widths` | `[320, 640, 960, 1280, 1920, 2560]` | Ширины копий |
| `images.quality` | `82` | Качество WebP |
| `images.avif_quality` | `60` | Качество AVIF |
| `images.max_pixels` | `50000000` | Оригинал больше этого не преобразуется |
| `images.ratios` | `['16:9', '4:3', '1:1', '3:4']` | Пропорции кадров |
| `history.keep` | `100` | Записей истории на объект и язык |
| `history.days` | `365` | Возраст записей истории |
| `admin.theme.brand`, `admin.theme.accent` | `#0B5D80`, `#C8217A` | Цвета админки |
| `admin.theme.radius` | `6` | Скругление, от 0 до 16 px |
| `admin.theme.logo`, `admin.theme.name` | `null` | Знак и название в админке |
| `critical_css.enabled` | `false` | Критический CSS |
| `critical_css.stylesheets` | `[]` | Публичные пути таблиц стилей в порядке каскада. Нужна хотя бы одна, когда включено |
| `critical_css.viewports` | `390×844` (телефон), `1366×900` | Окна `{width, height, mobile}` |
| `critical_css.keep_selectors`, `critical_css.remove_selectors` | `[]` | Селекторы, которые crittr всегда оставляет или выбрасывает |
| `critical_css.node` | `node` | Программа Node |
| `critical_css.chrome` | `null` | Путь к Chrome. `null` значит `PUPPETEER_EXECUTABLE_PATH` или Chrome от puppeteer |
| `critical_css.timeout` | `60` | Секунд на страницу в одном окне, не меньше 10 |
| `critical_css.max_kb` | `75` | Предел критического CSS в КБ |
| `knowledge_base.enabled` | `false` | База знаний |
| `knowledge_base.model` | `text-embedding-3-small` | Модель `text-embedding-3-*`, векторы размерности 1536 |
| `knowledge_base.base_url` | `https://api.openai.com/v1` | API, совместимый с OpenAI |
| `knowledge_base.timeout` | `30` | Секунд на запрос, не меньше 5 |
| `knowledge_base.exclude_templates`, `knowledge_base.exclude_collections` | `[]` | Что не индексируется |
| `indexnow.endpoint` | `https://yandex.com/indexnow` | Приёмник IndexNow |
| `yandex_webmaster.client_id` | `%env(string:default::ITK_YANDEX_CLIENT_ID)%` | ID приложения OAuth Яндекса |
| `yandex_webmaster.api_base` | `https://api.webmaster.yandex.net/v4` | Адрес API Вебмастера |
| `mcp.allowed_origins` | `[]` | Разрешённые значения `Origin` для `/mcp` |
| `mcp.max_body_mb` | `8` | Предел тела запроса `/mcp`, от 1 до 64 |
| `telegram.enabled` | `true` | Отправка заявок в Telegram |
| `telegram.chat_ids` | `''` | Запасные чаты через запятую. Можно `%env(...)%` |
| `max.enabled` | `true` | Отправка заявок в MAX |
| `max.chat_ids` | `''` | Запасные чаты MAX, целые числа через запятую |
| `update_source` | `null` | Адрес для проверки новой версии |

### Настройка языков содержимого

```yaml
itk_cms:
  main_locale: ru
  locales:
    ru: { name: Русский }
    kk: { name: Қазақша }
    tr: { name: Türkçe }
    zh: { name: 中文, html_lang: zh-CN, og_locale: zh_CN }
    en: { name: English }
```

- Код языка состоит из 2 или 3 строчных латинских букв. У каждого языка должно быть непустое название `name`. Явно заданный список должен быть непустым и содержать `main_locale`. Код не должен совпадать с зарезервированным системным сегментом пути (например, `mcp`) или первым сегментом `admin_path`, в том числе когда совпавший код относится к основному языку.
- По умолчанию `html_lang` совпадает с кодом языка. Допустимы 2 или 3 строчные латинские буквы, после которых можно добавить дефис и от 2 до 8 букв или цифр, как в `zh-CN`. Итоговый тег у каждого языка должен быть уникальным.
- `og_locale` состоит из 2 или 3 строчных латинских букв, подчёркивания и 2 заглавных латинских букв, как в `zh_CN`. Сервис может получить его из региона в `html_lang`. Если регион и `og_locale` не заданы, сервис возвращает `null`.
- Админка показывает основной язык первым, а остальные в порядке конфига. Если `locales` не задан, админка показывает только основной язык, а API по-прежнему принимает прежние коды языков. Явный список ограничивает запросы к API перечисленными языками. Явный пустой список отклоняется; для прежнего поведения не задавайте `locales`.
- Когда в конфиге несколько языков, у адресов основного языка нет языкового префикса. Адреса остальных версий начинаются с `/{код}`. Например, адрес главной страницы на казахском языке будет `/kk`, а одной из других страниц — `/kk/about`. Сервер проверяет языковой префикс при создании перевода, изменении его адреса (в том числе при восстановлении с другим адресом) и публикации. У старой страницы с несовместимым префиксом можно сохранить остальные поля, не меняя адрес.

Подключите `Itk\Cms\I18n\ContentLocales`, чтобы читать коды, названия, префиксы адресов и метаданные языков сайта.

#### Публичные страницы и формы

Публичный маршрут отдаёт каждую опубликованную версию по адресу её языка. Язык определяет первый сегмент целиком. Адрес `/kk/about` открывает казахскую версию, а `/kk-news` остаётся в основном языке. Черновики по публичному адресу возвращают `404`.

В Twig `page.locale` содержит код языка версии, `page.html_lang` содержит тег для `<html lang>`, а `page.og_locale` содержит локаль Open Graph или `null`. При заданном `itk_cms.locales` фильтр `|trans` использует язык версии. В `page.translations` перечислены опубликованные версии той же страницы в формате `{code, name, url}`, включая текущую, если она опубликована. Основной язык идёт первым, остальные следуют порядку в конфиге. Переключатель языков может использовать эти адреса напрямую.

```twig
{% if page.translations|length > 1 %}
    <ul>
        {% for version in page.translations %}
            <li><a href="{{ version.url }}"{% if version.code == page.locale %} aria-current="page"{% endif %}>{{ version.name }}</a></li>
        {% endfor %}
    </ul>
{% endif %}
```

Меню, `site`, `settings()`, `listing()`, `itk_pages()`, `itk_collection()` и `page_url()` используют язык страницы. Ссылка меню на страницу появляется, если её версия на этом языке опубликована. Пункты с явным URL сохраняют заданный адрес. Переводимые настройки читаются в текущем языке без отката на основной. У общих настроек одно значение для всех языков. Для несохранённой настройки берётся значение по умолчанию из схемы.

На сайте с `itk_cms.locales` запрос `POST /request/{form}` сохраняет язык из `?locale=`, если параметр указан. Код вне списка даёт `422`. Без параметра язык определяется по адресу страницы из `Referer` с теми же схемой, хостом и портом. Если такого заголовка нет, берётся основной язык. Чтобы передать язык явно, задайте форме `action="/request/callback?locale={{ page.locale }}"`. Для AJAX отправьте данные формы по тому же адресу.

```twig
<form id="callback" action="/request/callback?locale={{ page.locale }}" method="post">
    {# Поля формы #}
</form>
```

```js
const form = document.getElementById('callback');
const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
const result = await response.json();
```

#### Многоязычная индексация и эксплуатация

При двух и более настроенных языках `itk_seo().alternates` содержит `{hreflang, href}` индексируемых опубликованных версий той же страницы, включая текущую. Тег языка берётся из `html_lang`, например `zh-CN` для `zh`. Основная версия идёт первой. Остальные идут в порядке настройки. Блок появляется при наличии хотя бы двух индексируемых версий. `x-default` ведёт на основную версию, если она опубликована и индексируется. В предпросмотре и на странице с `noindex` список SEO-альтернатив пуст. Переключатель языков может использовать `page.translations` для ссылки на опубликованные версии с `noindex`.

Абсолютные ссылки берут базу из `PublicUrl::base()` в порядке приоритета от `itk_cms.public_url`, запроса с хостом из `framework.trusted_hosts` или настройки `domain`. В debug-режиме можно использовать адрес текущего запроса для разработки. Настройте `public_url` или адрес сайта, чтобы HTTP-запросы и воркеры строили одинаковые ссылки. Без базы переключатель языков сохраняет относительные ссылки, а список SEO-альтернатив остаётся пустым. Опубликованная многоязычная страница подставляет собственный абсолютный canonical, если он не задан в SEO страницы, правиле или аргументе `itk_seo()`.

Штатный layout печатает ссылки после canonical. В собственном layout можно использовать тот же цикл.

```twig
{% set seo = itk_seo() %}
{% if seo.canonical != '' %}<link rel="canonical" href="{{ seo.canonical }}">{% endif %}
{% for alternate in seo.alternates %}
    <link rel="alternate" hreflang="{{ alternate.hreflang }}" href="{{ alternate.href }}">
{% endfor %}
```

Один `/sitemap.xml` перечисляет все включённые индексируемые опубликованные версии и добавляет `xhtml:link` с теми же альтернативами, что в HTML. Генерируемый `/llms.txt` группирует названия, описания и ссылки под названиями языков в порядке настройки, пропуская пустые языки. Публикация, перенос, снятие и удаление версии сбрасывают оба файла после фиксации транзакции. Генерируемые многоязычные ответы перепроверяются по ETag содержимого, в том числе при изменениях в одну секунду. Запрос только с датой получает актуальное тело. Файлы сайта `public/sitemap.xml` и `public/llms.txt` сохраняют приоритет. Ручной SEO-текст имеет приоритет перед генерируемым `llms.txt`.

IndexNow использует полный путь затронутого языка. При переносе отправляются старый и новый адреса. При снятии и удалении отправляется последний опубликованный адрес. Удаление страницы охватывает индексируемые опубликованные версии всех включённых языков. Критический CSS тоже использует полный путь каждой опубликованной версии включённого языка, в том числе для страниц с `noindex`.

Команда `php bin/console itk:doctor --ci` проверяет основной язык и префиксы, главные страницы, конфликтующие пути, базу SEO, ручные файлы для поисковых роботов, пустые настройки и области меню по языкам. Отсутствие главной основного языка считается ошибкой. Отсутствие главной дополнительного языка даёт предупреждение.

### Ограничения частоты запросов

Лимиты бандл задаёт в `framework.rate_limiter`, сайт может переопределить их под теми же именами:

| Лимитер | Значение |
|---|---|
| `itk_request_form` | 10 заявок за 15 минут с адреса |
| `itk_password_change` | 5 попыток за 15 минут на человека |
| `itk_password_reset_ip`, `itk_password_reset_email`, `itk_password_reset_guess` | 10 в час с адреса, 3 в час на почту, 10 неверных ссылок за 15 минут |
| `itk_mail_test` | 10 тестовых писем в час на человека |
| `itk_mcp_token`, `itk_mcp_failed_auth`, `itk_mcp_token_create` | 120 вызовов в минуту на ключ, 20 неверных ключей за 15 минут с адреса, 10 новых ключей в час |

## Расширения и генераторы

### Расширения

Пакет или сам сайт подключается к ядру классом, который реализует `ItkExtensionInterface`. Тег `itk.extension` ставится сам.

```php
final class ToursExtension implements ItkExtensionInterface
{
    public function register(Registry $r): void
    {
        $r->resources(ToursCollection::class, TourTemplate::class)        // ещё sections(), templates(), collections(), settings()
            ->twigPaths(__DIR__ . '/../templates', 'Tours')                   // {% include '@Tours/block.html.twig' %}
            ->menu(NavItem::make('Reports', '/x/reports')->group('Analytics', icon: 'chart', order: 60)->icon('chart')->canSee('col:tours'))
            ->adminAssets(js: ['/bundles/tours/admin.js'], css: ['/bundles/tours/admin.css'])
            ->notificationChannels(SmsChannel::class)
            ->healthChecks(DiskCheck::class)
            ->mcpTools(ToursCountTool::class);
    }
}
```

- Каждое расширение регистрируется при первом обращении к `Registry`, один раз на экземпляр реестра. Классы берутся из контейнера, если они там есть, иначе создаются без аргументов.
- `twigPaths()` добавляет папки в Twig, в том числе для консоли и `lint:twig`.
- `NavItem` это пункт меню админки, который не коллекция. `to` это маршрут клиента админки. Группа по умолчанию «Плагины» (`order` 800).
- `adminAssets()` добавляет CSS после основных стилей админки в `<head>`; модульные скрипты запускаются после загрузки языка интерфейса и готовности `window.ITK`, до монтирования основного приложения админки. Принимаются адреса `/…` и `http(s)://…`. Плагин вызывает `window.ITK.registerPlugin({ fields, panels, actions, previews })` и получает `window.ITK.vue`, `http`, `toast`, `confirm`, `i18n`, `ui` (в том числе `SortableTree` и `KanbanBoard`). Подробности в [admin/src/README.md](https://github.com/it-koval/cms/blob/main/admin/src/README.md).

### Генераторы кода

Команды создают в сайте готовый класс и, где нужно, заготовку Twig или JS. Классы лежат в `src/Itk/…` и подхватываются сами. В боевом окружении после генерации класса выполните `cache:clear`.

| Команда | Создаёт |
|---|---|
| `itk:make:template <ключ> [название]` | `src/Itk/Template/<Ключ>Template.php` и `templates/pages/<ключ>.html.twig` |
| `itk:make:section <ключ> [название] [--group=]` | `src/Itk/Section/<Ключ>Section.php` и `templates/sections/<ключ>.html.twig`. Без `--group` генератор пишет `->group('Тексты и картинки')`, это группа по умолчанию, админка показывает её на языке интерфейса |
| `itk:make:collection <ключ> [название] [--group=] [--icon=]` | `src/Itk/Collection/<Ключ>Collection.php` |
| `itk:make:settings <ключ> [название]` | `src/Itk/Settings/<Ключ>Settings.php` |
| `itk:make:field <тип> [название]` | `src/Itk/Field/<Тип>Field.php` и компонент админки `public/itk/fields/<тип>.js` |
| `itk:make:plugin <ключ> [название]` | `src/Itk/Plugin/<Ключ>Extension.php` и экран админки `public/itk/plugins/<ключ>/admin.js` |

- Без ключа или названия команда спрашивает. С `-n` ключ обязателен.
- Существующий класс не перезаписывается без `--force` (код выхода 1). Существующие заготовки Twig и JS заменяются только с `--force`.
- `--dry-run` показывает файлы и класс без записи. `--dir=src/Other/Folder` меняет папку класса в пределах `autoload.psr-4`.
- Ключ, занятый другим классом того же вида или `schema.php`, не принимается.
- Скрипт поля подключается расширением: `$registry->adminAssets(js: ['/itk/fields/rating.js'])`.

### Коллекция из описания или таблицы

```
php bin/console itk:make:collection --from=form.json
php bin/console itk:make:collection --from=table:table_name [--import] [--publish] [--map=…]
```

Описание JSON: `{"key": "tours", "title": "Tours", "singular": "tour", "icon": "map", "fields": [{"key": "city", "label": "City", "type": "text", "required": true, "max": 80}], "rows": [{"id": 1, "city": "Perm"}]}`. Типы: `text`, `textarea`, `description`, `headline`, `article`, `number`, `toggle`, `select` (с `"options"`), `date`, `datetime`, `image`, `photos`, `link`. `rows` нужны только для `--import`. Повторитель и связь дописывают в классе вручную.

Из таблицы PostgreSQL колонки становятся полями. Таблица в другой схеме пишется как `table:схема.таблица`, другое соединение Doctrine задаёт `--connection=имя`.

| Колонка | Поле |
|---|---|
| `character varying`, `character` | `Text` (длина до 1000 становится `max`) |
| `text`, `json`, `jsonb` | `Textarea` |
| `smallint`, `integer`, `bigint`, `numeric`, `real`, `double precision` | `Number` |
| `boolean` | `Toggle` |
| `date` | `Date` |
| `timestamp` | `DateTime` |
| `time` | `Text` |
| тип-перечисление (`CREATE TYPE … AS ENUM`) | `Select` |

- Остальные типы пропускаются с пояснением. `NOT NULL` без значения по умолчанию даёт `required()`. Подпись берётся из комментария колонки или из имени. Первичный ключ в поля не попадает.
- `--map=колонка:поле[:тип],…` переименовывает колонку или меняет тип. `колонка:-` пропускает колонку.
- `--strip-prefix=old_` отбрасывает начало имени таблицы при выборе ключа. `--id=column` задаёт ключ строки, если первичного ключа нет или он составной.
- `--import` переносит строки в записи черновиками, с обычными проверками и хуками. Повторный запуск обновляет свои записи, а не создаёт новые. `--publish` публикует записи сразу. Строка без обязательного поля остаётся черновиком и попадает в отчёт.

## Docker

Готовый Docker-комплект лежит в пакете в `vendor/itk/cms/docker`. Вы копируете его в сайт и дальше правите как свои файлы.

| Файл | Что в нём |
|---|---|
| `compose.yaml` | Сервисы `php` (php-fpm), `nginx`, `worker`, `database` (PostgreSQL 17 с pgvector) и `mailer` (Mailpit). Порты открыты только на `127.0.0.1` |
| `Dockerfile` | Стадии `php_base`, `php_dev`, `php_prod`, расширения PHP, Composer, проверка здоровья |
| `Dockerfile.dockerignore` | Что не попадает в образ: `.git`, `vendor`, `node_modules`, `var`, `public/uploads`, `public/bundles`, `.env.local`, `.env.*.local`, `.env.local.php`, ключ расшифровки секретов, `compose.override.yaml` |
| `nginx/default.conf` | Статика, кэш файлов сборки, заголовки, передача в `index.php` |
| `php/conf.d/10-app.ini`, `php/php-fpm.d/zz-app.conf` | Настройки PHP и php-fpm |
| `php/docker-entrypoint.sh` | При старте `php`: `composer install`, `assets:install`, ожидание базы, миграции, `itk:install` |

Аргументы сборки: `PHP_VERSION` (по умолчанию `8.4`), `CRITICAL_CSS` (`0` или `1`), `UID` и `GID` (пользователь `www-data` получает ваши идентификаторы).

### Первый запуск

На хосте нужен только Docker.

1. Создайте сайт и поставьте пакет образом Composer. `--ignore-platform-reqs` нужен потому, что в образе Composer нет расширений PHP, которые требует бандл. В контейнере `php` они есть.

   ```bash
   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 \
       sh -c 'composer config extra.symfony.docker false && composer require itk/cms --ignore-platform-reqs --no-scripts'
   ```

2. Скопируйте комплект в корень сайта:

   ```bash
   cp -R vendor/itk/cms/docker docker
   mv docker/compose.yaml compose.yaml
   ```

3. Выполните шаги с 3 по 5 быстрого старта (`config/routes/itk.yaml`, `security.yaml`, `itk_cms.yaml`) и положите `APP_SECRET` в `.env.local`. `DATABASE_URL` задаёт `compose.yaml`.
4. Задайте переменные, если нужно. Compose читает их из окружения оболочки и из `.env` рядом с `compose.yaml`. Это `.env` сайта, который лежит в git, поэтому пишите в него только безопасные значения.

   | Переменная | По умолчанию | Что делает |
   |---|---|---|
   | `HTTP_PORT`, `DB_PORT`, `MAILPIT_PORT` | `8080`, `5433`, `8025` | Порты на хосте |
   | `DB_NAME` | `app` | Имя базы |
   | `DOCKER_APP_ENV` | `dev` | `APP_ENV` в контейнерах |
   | `COMPOSE_PROJECT_NAME` | имя каталога | Имя проекта. У каждого сайта своё |
   | `PHP_VERSION` | `8.4` | Версия PHP |
   | `CRITICAL_CSS` | `0` | `1` ставит в образ Node 22 и Chromium |
   | `ADMIN_EMAIL`, `ADMIN_PASSWORD` | пусто | Если заданы обе, администратор создаётся при старте |
   | `SITE_NAME`, `SITE_DOMAIN` | пусто | Название и домен для `itk:install` |
   | `UID`, `GID` | `1000` | Пользователь контейнера. На Linux задайте свои значения в `.env` (`id -u`, `id -g`) |

   Не пишите пароль администратора в `.env`: он попадёт в git и в образ `php_prod`. Передайте его один раз из оболочки или создайте администратора шагом 6.

5. Запустите:

   ```bash
   docker compose up -d --build
   docker compose logs -f php        # дождитесь строки «ready to handle connections»
   ```

   Миграции и `itk:install` при старте выключает `AUTO_MIGRATE: "0"` в `environment` сервиса `php`.

6. Создайте администратора, если не задали `ADMIN_EMAIL` и `ADMIN_PASSWORD`:

   ```bash
   docker compose exec php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   ```

Сайт открывается на `http://localhost:8080`, админка на `http://localhost:8080/admin/login`. Опубликуйте главную страницу в админке.

**Если не запускается.**

- Строки `[entrypoint] ... failed` в `docker compose logs php` называют шаг, на котором контейнер остановился.
- На первом старте `nginx` и `worker` ждут, пока `php` закончит `composer install`. Подождите и повторите `docker compose up -d`.
- Порт занят: задайте другие `HTTP_PORT`, `DB_PORT`, `MAILPIT_PORT`.
- `docker compose down -v` удаляет тома, в том числе базу `postgres_data`.

### Почта и воркер

Все письма собирает Mailpit: `http://localhost:8025`. В контейнерах заданы `MAILER_DSN=smtp://mailer:1025` и `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`.

Сервис `worker` собран из того же образа и выполняет `messenger:consume async critical_css knowledge_base --time-limit=3600 --memory-limit=256M`. Через час он завершается, а `restart: unless-stopped` поднимает его снова. Воркер держит код в памяти: после правки PHP, конфигурации или шаблонов выполните `docker compose restart worker`. Если сайт задал свои транспорты, поправьте `command`.

### Критический CSS в Docker

Node, crittr и Chrome должны быть в том же образе, что и PHP: проверку выполняет php-fpm, а страницы считает воркер.

1. Соберите образ с Node и Chromium:

   ```bash
   echo 'CRITICAL_CSS=1' >> .env
   docker compose up -d --build
   ```

   В образе заданы `PUPPETEER_SKIP_DOWNLOAD=1` и `PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium`, поэтому опцию `chrome` задавать не нужно.

2. Поставьте crittr внутри контейнера: `docker compose exec php npm install 'crittr@^5'`. Добавьте `node_modules` в `.gitignore`, а `package.json` и `package-lock.json` закоммитьте. Для `php_prod` crittr должен быть в `dependencies`.
3. Включите опцию и `itk_stylesheets()` (раздел [Критический CSS](#критический-css)), затем выполните `docker compose exec php bin/console cache:clear` и `docker compose restart worker`.
4. Проверьте одну страницу: `docker compose exec php bin/console itk:critical-css:generate --page=<id> --now`.

### Сборка для продакшена

```bash
docker build -f docker/Dockerfile --target php_prod --build-arg CRITICAL_CSS=1 -t mysite:1.0 .
```

- Стадия `php_prod` ставит зависимости без dev-пакетов (`composer install --no-dev`, при `CRITICAL_CSS=1` ещё `npm ci --omit=dev`) и включает `php.ini-production`.
- `APP_SECRET`, `DATABASE_URL` и `MAILER_DSN` передавайте окружением контейнера или держите в хранилище секретов Symfony и передавайте только `SYMFONY_DECRYPTION_SECRET` (раздел [Секреты в продакшене](#секреты-в-продакшене-symfony-vault)). `.env` из git попадает в образ, держите в нём только значения по умолчанию.
- Файлов админки (`public/bundles`) в образе нет, их кладёт entrypoint при старте. Дайте nginx общий том или выполните `assets:install public` в своей стадии сборки.
- Миграции и `itk:install` идут при каждом старте `php`. При нескольких репликах задайте `AUTO_MIGRATE=0` и запускайте миграции отдельной задачей.
- Воркер запускается из того же образа с `SKIP_ENTRYPOINT=1` и командой воркера.
- `compose.yaml` из комплекта рассчитан на разработку. Оркестрацию продакшена сайт описывает сам.

### Обновление комплекта

Комплект принадлежит сайту, `composer update` его не меняет. После обновления пакета сравните файлы и перенесите нужные изменения:

```bash
docker compose exec php composer update itk/cms
diff -r -x compose.yaml vendor/itk/cms/docker docker && diff vendor/itk/cms/docker/compose.yaml compose.yaml
docker compose up -d --build
```

## ИИ-агент в проекте сайта

Правила для ИИ-агента, который делает сайт на ITK CMS, лежат в пакете: [SITE-AGENTS.md](SITE-AGENTS.md) (у сайта `vendor/itk/cms/SITE-AGENTS.md`). Главное правило: возможность, которой не хватает ядру и которая пригодится другим сайтам, агент предлагает pull request'ом в [it-koval/cms](https://github.com/it-koval/cms), а не дорабатывает копию ядра внутри сайта.

Агенты читают инструкции из корня проекта, поэтому подключите файл в инструкциях сайта:

- Claude Code, строка в `CLAUDE.md`: `@vendor/itk/cms/SITE-AGENTS.md`.
- Codex, Cursor и другие агенты, которые читают `AGENTS.md`, строка в `AGENTS.md`: `Перед работой прочитай vendor/itk/cms/SITE-AGENTS.md: это правила ядра ITK CMS, они обязательны.`

## Публичный API и совместимость

Публичный API и правила обновления между версиями описаны в [UPGRADE.ru.md](https://github.com/it-koval/cms/blob/main/UPGRADE.ru.md). Классы с пометкой `@internal` в докблоке не являются публичными и могут измениться в любом минорном релизе. PHPStan и IDE предупреждают об их использовании. Страницы и коллекции сайт читает через `ContentReader` и Twig-функции, а не через репозитории.

## Разработка и тесты бандла

Монорепозиторий [it-koval/cms](https://github.com/it-koval/cms) содержит бандл (`packages/cms`) и исходники админки (`admin/`). Как настроить окружение, запустить тесты, собрать админку и добавить язык интерфейса, описано в [CONTRIBUTING.ru.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.ru.md).

## Другие документы

- [CHANGELOG.ru.md](https://github.com/it-koval/cms/blob/main/CHANGELOG.ru.md): изменения по версиям.
- [UPGRADE.ru.md](https://github.com/it-koval/cms/blob/main/UPGRADE.ru.md): обновление между версиями и граница публичного API.
- [CONTRIBUTING.ru.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.ru.md): как участвовать в разработке.
- [SECURITY.ru.md](https://github.com/it-koval/cms/blob/main/SECURITY.ru.md): как сообщить об уязвимости.
- [SITE-AGENTS.ru.md](SITE-AGENTS.ru.md): правила для ИИ-агента в проекте сайта.
- [admin/src/README.ru.md](https://github.com/it-koval/cms/blob/main/admin/src/README.ru.md): устройство админки и плагины.
