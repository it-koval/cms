# itk/cms — ITK CMS

Symfony-бандл: страницы из секций, шаблоны страниц, коллекции, меню, настройки, медиатека, SEO, редиректы, заявки с Telegram, пользователи и роли, JSON API и админка (Vue). Symfony 7.4 LTS и 8.x, PHP 8.3 (Symfony 8 требует 8.4), Doctrine ORM 3, MySQL 8.

Сайт описывает свою структуру одним файлом `config/itk/schema.php` и своими Twig-шаблонами. Бандл ничего не знает о конкретном сайте.

## Оглавление

- [Первый сайт за 10 минут](#первый-сайт-за-10-минут)
- [Установка в сайт](#установка-в-сайт)
- [Docker](#docker)
  - [Первый запуск](#первый-запуск)
  - [Если не запускается](#если-не-запускается)
  - [Почта в Docker](#почта-в-docker)
  - [Воркер](#воркер)
  - [Критический CSS в Docker](#критический-css-в-docker)
  - [Сборка для продакшена](#сборка-для-продакшена)
  - [Обновление](#обновление)
  - [Проверка](#проверка)
- [ИИ-агент в проекте сайта](#ии-агент-в-проекте-сайта)
- [Схема сайта (публичный контракт)](#схема-сайта-публичный-контракт)
  - [Проверка схемы: Schema::validate() и itk:schema:lint](#проверка-схемы-schemavalidate-и-itkschemalint)
  - [Twig](#twig)
  - [Публичное чтение: itk_pages, itk_page, itk_collection](#публичное-чтение-itk_pages-itk_page-itk_collection)
  - [Строгий режим: itk_cms.strict](#строгий-режим-itk_cmsstrict)
  - [Публичные страницы: заголовки и 304](#публичные-страницы-заголовки-и-304)
  - [Кэш страниц](#кэш-страниц)
  - [База знаний](#база-знаний)
  - [Картинки](#картинки)
  - [Заявки](#заявки)
  - [SEO для любого адреса](#seo-для-любого-адреса)
  - [Адреса и перенаправления: Support\Url](#адреса-и-перенаправления-supporturl)
  - [sitemap.xml](#sitemapxml)
  - [robots.txt и llms.txt](#robotstxt-и-llmstxt)
  - [Миграции сайта](#миграции-сайта)
  - [Команды](#команды)
- [Поля](#поля)
- [Разделы-классы](#разделы-классы)
- [Хуки и события](#хуки-и-события)
- [Действия](#действия)
- [Фильтры](#фильтры)
- [Виды списка](#виды-списка)
- [Дерево с перетаскиванием](#дерево-с-перетаскиванием)
- [Меню админки](#меню-админки)
- [Расширения](#расширения)
- [Генераторы](#генераторы)
  - [Коллекция из описания или таблицы](#коллекция-из-описания-или-таблицы)
- [История изменений и корзина](#история-изменений-и-корзина)
- [Уведомления](#уведомления)
- [Состояние сайта](#состояние-сайта)
- [Настройки в коде](#настройки-в-коде)
- [Двухэтапный вход](#двухэтапный-вход)
- [Пароли](#пароли)
- [Почта](#почта)
- [MCP: ИИ-ассистенты](#mcp-ии-ассистенты)
  - [Включение и ключи](#включение-и-ключи)
  - [Подключение](#подключение)
  - [Инструменты](#инструменты)
  - [Транспорт и защита](#транспорт-и-защита)
  - [Своя библиотека или mcp/sdk](#своя-библиотека-или-mcpsdk)
  - [Свой инструмент](#свой-инструмент)
  - [Безопасность: что помнить](#безопасность-что-помнить)
- [Заголовки безопасности админки](#заголовки-безопасности-админки)
- [Оформление админки](#оформление-админки)
- [Язык админки](#язык-админки)
- [Разработка самой CMS](#разработка-самой-cms)
- [Тесты бандла](#тесты-бандла)
- [Что в бандле](#что-в-бандле)
- [Админка: вход, API, предпросмотр](#админка-вход-api-предпросмотр)

## Первый сайт за 10 минут

Команды ниже проверены на чистом Symfony 7.4 skeleton (PHP 8.4, PostgreSQL 17). Пакет пока не опубликован на Packagist, поэтому он подключается как `path`-репозиторий; после первого релиза вместо `composer config` хватит `composer require itk/cms`.

Нужны PHP 8.3+ с расширениями из раздела «Установка в сайт», Composer и PostgreSQL 16+ (другие базы не поддерживаются).

1. **Новый сайт.**
   ```bash
   composer create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   ```
2. **Пакет.** Путь ведёт на каталог `packages/cms` клона этого репозитория:
   ```bash
   composer config repositories.itk path /path/to/the/repository/packages/cms
   composer require itk/cms:'*@dev'
   ```
   Symfony Flex сам впишет бандл в `config/bundles.php` и поставит зависимости (Doctrine, Messenger, Mailer, Security, Twig). Если Flex спросит про конфигурацию Docker, откажитесь: готовый комплект для CMS лежит в пакете, см. раздел «Docker». Чтобы Flex не спрашивал и не добавлял свои файлы, выполните до `composer require`: `composer config extra.symfony.docker false`.
3. **База и секрет**, файл `.env.local` (Flex кладёт в `.env` строку `DATABASE_URL` с примером для PostgreSQL, `.env.local` её перекрывает):
   ```dotenv
   DATABASE_URL="postgresql://user:password@127.0.0.1:5432/mysite?serverVersion=17&charset=utf8"
   APP_SECRET=a-long-random-string
   ```
4. **Маршруты**, `config/routes/itk.yaml`:
   ```yaml
   itk_admin:
       resource: '@ItkCmsBundle/config/routes/admin.php'
   itk_site:
       resource: '@ItkCmsBundle/config/routes/site.php'
   ```
5. **Безопасность**, `config/packages/security.yaml` целиком (сокращённый вариант шага 4 из «Установка в сайт», там же объяснения):
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
6. **Таблицы и первый администратор.** Порядок важен: сначала миграции, потом `itk:install` (он спросит пароль):
   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   php bin/console assets:install
   ```
7. **Запуск и вход.**
   ```bash
   php -S 127.0.0.1:8000 -t public    # или symfony server:start
   ```
   Встроенный сервер PHP не отдаёт `index.php` на адрес с расширением, если такого файла нет: `/uploads/media/_v/…webp` отвечает 404, и пока воркер не создал копию, в медиатеке видна битая миниатюра. Чтобы такой адрес дошёл до маршрута `itk_image_variant`, запускайте сервер с файлом-маршрутизатором (`symfony server:start` и веб-сервер с `try_files` его не требуют):
   ```bash
   php -S 127.0.0.1:8000 -t public router.php
   ```
   ```php
   <?php // router.php (в корне сайта): существующий файл отдаётся как есть, остальное идёт в index.php
   $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
   if (is_string($path) && is_file(__DIR__ . '/public' . $path)) {
       return false;
   }
   $_SERVER['SCRIPT_NAME'] = '/index.php';
   require __DIR__ . '/public/index.php';
   ```
   Откройте `http://127.0.0.1:8000/admin/login` и войдите. `itk:install` создаёт главную страницу **черновиком**: пока её не опубликовали (в админке «Страницы» → «Главная» → «Опубликовать»), адрес `/` отвечает 404. Опубликованная главная пока пустая: у сайта нет секций и своих шаблонов. Проверить установку можно командой `php bin/console itk:doctor`.
8. **Дальше.** Опишите структуру сайта в `config/itk/schema.php` (раздел «Схема сайта»), положите шаблоны в `templates/` и запустите воркер очереди (`bin/console messenger:consume async`, шаг 5 установки): без него не уходят письма и сообщения в Telegram. Заготовки делают команды `itk:make:*` (раздел «Генераторы»). Если сайт делает ИИ-агент, подключите ему правила ядра (раздел «ИИ-агент в проекте сайта»).

## Установка в сайт

**Требования.** PHP 8.3+ с расширениями `ctype`, `dom`, `gd` (с WebP), `json`, `mbstring`, `pdo_pgsql`, `sodium` (Composer проверит их сам); желательно `exif` (поворот JPEG по EXIF). **База данных — только PostgreSQL 16+** (тесты и CI идут на 17). MySQL, MariaDB и SQLite не поддерживаются: JSON-колонки хранятся как `jsonb`, запросы по ним, отпечаток страницы и блокировка правок написаны под PostgreSQL, а миграция на другой базе (и на PostgreSQL старше 16) останавливается с понятным объяснением, ничего не создав. Создайте базу с кодировкой `UTF8` и локалью UTF-8 (так делает официальный образ): поиск без учёта регистра опирается на `lower()`, которая складывает кириллицу только в такой локали. Образ `pgvector/pgvector:pg17` отличается от `postgres:17` только расширением `vector`; оно понадобится базе знаний бандла, поэтому выбирайте его.

1. **Composer.** Пакет `itk/cms` пока не опубликован на Packagist, поэтому подключается так (**временный способ**, до выхода пакета):
   - `path` на каталог `packages/cms` клона этого репозитория (composer.json пакета лежит не в корне репозитория, поэтому `vcs` на сам репозиторий не подходит):
     ```json
     "repositories": [{ "type": "path", "url": "../it-koval-cms/packages/cms", "options": { "symlink": true } }],
     "require": { "itk/cms": "*@dev" }
     ```
     Путь — любой, откуда PHP-контейнер видит каталог (при Docker смонтируйте его в контейнер и укажите путь внутри контейнера).
   - Если пакет выложен в отдельный git-репозиторий (composer.json в корне), вместо `path` годится `{ "type": "vcs", "url": "git@…:itk/cms.git" }` и тот же `"itk/cms": "*@dev"` (или тег: `"^0.1"`).

   Когда пакет попадёт на Packagist, `repositories` не понадобится: `composer require itk/cms`.
   Затем `composer update itk/cms` (в контейнере: `docker compose exec php composer update itk/cms`). Обязательные пакеты (Doctrine, Messenger с транспортом Doctrine, Mailer, Security, Twig) придут сами. **Необязательные**, их ставят по желанию: `symfony/notifier` + `symfony/telegram-notifier` (заявки в Telegram, шаг 6), `symfony/http-client` (нужен Telegram и проверке обновлений), `monolog/monolog` (прячет токен бота из логов; версия не ниже 3.5).
2. **`config/bundles.php`**: `Itk\Cms\ItkCmsBundle::class => ['all' => true],`. Symfony Flex добавляет бандл сам при `composer require`; шаг нужен только проекту без Flex (там же должны быть FrameworkBundle, TwigBundle, SecurityBundle, DoctrineBundle и DoctrineMigrationsBundle).
3. **Маршруты**, `config/routes/itk.yaml`:
   ```yaml
   itk_admin:
       resource: '@ItkCmsBundle/config/routes/admin.php'
   itk_site:
       resource: '@ItkCmsBundle/config/routes/site.php'   # заявки + catch-all страниц (приоритет -1000)
   ```
   Админка стоит на `/admin`. Другой адрес задаёт опция `itk_cms.admin_path` (шаг 7, например `/cp`); у импорта можно добавить и свой `prefix: /cp` (тогда админка на `/cp/admin`, а при обеих настройках сразу — на `/cp` + `admin_path`). Защита админки (CSRF API, заголовки безопасности, второй фактор, JSON-ошибки) определяет админку **по маршруту** (имена `itk_admin_*` и `itk_api_*`), а не по строке адреса, поэтому остаётся включённой при любом адресе.
4. **Безопасность**, `config/packages/security.yaml`:
   ```yaml
   security:
       password_hashers:
           Itk\Cms\Entity\User: auto
       providers:
           itk_users:
               id: Itk\Cms\Security\AdminUserProvider
       firewalls:
           itk_mcp:                      # ИИ-ассистенты (раздел «MCP»): ключ в заголовке, без сессии и куки
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
           - { path: ^/mcp$, roles: ROLE_ITK_USER }                                           # проходит только владелец действующего ключа
           - { path: '^/admin/(login|forgot-password|reset-password)(/|$)', roles: PUBLIC_ACCESS }   # вход, «Забыли пароль?», ссылка из письма
           - { path: '^/admin(/|$)', roles: ROLE_ITK_USER }
   ```
   `(/|$)` в конце не пускает под правило чужой адрес вроде `/administrator` и не оставляет `/admin` без правила. **`pattern` файрвола `itk_admin` и строки `access_control` с адресом админки сайт меняет сам**: бандл их не трогает и не может узнать. Поставили админку на `/cp` (`itk_cms.admin_path: /cp`) — замените `^/admin` на `^/cp` в файрволе и в обеих строках `access_control`; импортировали маршруты с `prefix: /cp` — на `^/cp/admin`. Если этого не сделать, у адреса админки не будет файрвола (вход не откроется), а CSRF, заголовки и второй фактор всё равно работают, потому что идут по маршруту.
   `login_throttling` ограничивает подбор пароля (нужен `symfony/rate-limiter`), `enable_csrf` у выхода не даёт чужой странице разлогинить администратора. `APP_SECRET` в боевом `.env.local` обязателен и должен быть уникальным и непустым: от него зависит CSRF-токен админки, шифрование ключей двухфакторной защиты и хеши адресов в заявках. Пустой `APP_SECRET` бывает как раз на боевом сервере: в `.env` Flex кладёт `APP_SECRET=`, а рабочее значение для разработки лежит в `.env.dev`. Заметить его можно по `itk:install`, который печатает предупреждение, и по журналу ошибок: вход в админку открывается, но `/admin` и «Забыли пароль?» отвечают 500 с текстом `APP_SECRET is empty: set a unique value in .env.local of this environment.`. Экран «Состояние сайта» при этом не откроется, строка «APP_SECRET» со статусом «внимание» есть только в ответе `/admin/api/health`. Хеш адреса посетителя в заявке при пустом ключе не считается. За прокси или CDN задайте `framework.trusted_proxies`, иначе все посетители окажутся «с одного адреса» для лимита заявок и входа. Имена маршрутов `itk_admin_app` (`/admin`), `itk_admin_login` (`/admin/login`), `itk_admin_logout` (`/admin/logout`), `itk_admin_preview` (`/admin/preview/{id}`) объявляют контроллеры админки. Права проверяются на сервере: `PermissionVoter` (атрибут `itk`), `Itk\Cms\Security\Access`.
5. **Messenger.** Бандл направляет в транспорт `async` четыре сообщения: заявку в Telegram (`Itk\Cms\Request\SendRequestNotification`), письма (`Itk\Cms\Mail\SendMail`, `Itk\Cms\Security\RequestPasswordLink`) и копии картинок (`Itk\Cms\Media\GenerateImageVariants`), шина — `messenger.bus.default`. В чистом проекте транспортов нет, поэтому бандл сам дописывает в конфигурацию `framework.messenger`: транспорт `async` на `doctrine://default` (очередь в таблице `messenger_messages` той же базы; таблицу делает `itk:install`, иначе первое сообщение) и транспорт ошибок `failed` с `failure_transport: failed`. **Что сайт задал сам, остаётся как есть**: свой `async` (любой DSN, свой `retry_strategy`) бандл не трогает, свой `failure_transport` тоже (второго транспорта ошибок рядом он не заводит). Для боевого сайта обычно переопределяют `async` с повторами и `failed` с `auto_setup=0`:
   ```yaml
   framework:
       messenger:
           failure_transport: failed
           transports:
               async:
                   dsn: '%env(MESSENGER_TRANSPORT_DSN)%'     # doctrine://default, amqp://…, redis://…
                   retry_strategy: { max_retries: 5, delay: 60000, multiplier: 5, max_delay: 36000000 }
               failed: 'doctrine://default?queue_name=failed&auto_setup=0'
   ```
   `sync://` вместо `async` не годится: письмо «Забыли пароль?» тогда составлялось бы прямо в запросе, и время ответа выдавало бы, есть ли такой адрес (сейчас запрос только ставит сообщение в очередь, и время одинаково).
   **Воркер:** `bin/console messenger:consume async -vv` (под supervisor или systemd).
   **Без воркера** сайт и админка работают полностью: заявки сохраняются и видны в разделе «Заявки», страницы и картинки отдаются (копию картинки в этом случае делает маршрут при первом запросе). Не доставляется только то, что идёт через очередь: письма (в том числе «Забыли пароль?») и сообщения в Telegram стоят в `messenger_messages`, пока воркер не заработает; «Состояние сайта» показывает, сколько их ждёт. Если держать воркер негде (общий хостинг), запускайте его из cron раз в минуту: `* * * * * cd /path/to/site && php bin/console messenger:consume async --time-limit=55 --memory-limit=128M`. Команда разбирает очередь 55 секунд и сама завершается, поэтому запуски не накладываются.
6. **Telegram (необязательно).** Сайт и админка полностью работают без Telegram: `TELEGRAM_DSN` не нужен, пакеты `symfony/notifier` и `symfony/telegram-notifier` можно не ставить. Без Telegram заявка сохраняется, колокольчик админки сообщает о ней, а доставка заявки остаётся нейтральной («В Telegram не отправлялось»): это не сбой, заявка не повторяется и не попадает в «не доставлена»; в «Состоянии сайта» строка «Telegram» пишет «Telegram не подключён». То же, если переменная `TELEGRAM_DSN` указана в `framework.notifier.chatter_transports`, но нигде не задана, или равна `null://null` («ничего не отправлять», так в `.env` нового сайта). Чтобы подключить: `composer require symfony/notifier symfony/telegram-notifier symfony/http-client`, затем
   ```yaml
   framework:
       notifier:
           chatter_transports:
               telegram: '%env(TELEGRAM_DSN)%'     # telegram://BOT_ID:BOT_TOKEN@default; токен только в .env.local
   ```
   Чаты берутся из «Настройки → Уведомления» (`tgRoutes`), запасной вариант — опция `itk_cms.telegram.chat_ids`. Telegram подключён, а чата для формы нет: заявки сохраняются, но не отправляются (строка «Telegram» в «Состоянии сайта» предупреждает). Опция `itk_cms.telegram.enabled: false` выключает отправку целиком: строка остаётся спокойной и называет эту опцию. Только сообщение, которое подключённый Telegram отказался принять, считается сбоем: заявка получает «не доставлена» и повторяется по `retry_strategy`.
7. **Конфигурация бандла** (всё необязательно), `config/packages/itk_cms.yaml`:
   ```yaml
   itk_cms:
       schema_file: '%kernel.project_dir%/config/itk/schema.php'
       main_locale: en            # язык содержимого: страниц, коллекций, настроек сайта (по умолчанию ru, это не язык админки)
       admin_locale: en           # язык админки, её писем и консоли для тех, кто не выбрал свой в «Мой профиль»
       admin_locales: [en, ru, kk]  # из чего можно выбирать: подмножество встроенных языков, admin_locale должен быть в списке
       upload_dir: '%kernel.project_dir%/public/uploads/media'
       upload_url: /uploads/media
       max_upload_mb: 20
       admin_path: /admin         # где стоит админка: один или несколько сегментов (/cp, /site/panel), с «/» в начале и без «/» в конце; формат проверяется при старте. Файрвол и access_control в security.yaml сайт меняет сам (шаг 4)
       images:                    # уменьшенные WebP-копии, см. «Картинки»
           widths: [320, 640, 960, 1280, 1920, 2560]
           quality: 82
           max_pixels: 50000000
           avif_quality: 60
           ratios: ['16:9', '4:3', '1:1', '3:4']   # пропорции кадров
       public_url: ~              # https://example.com — адрес сайта для ссылок в письмах (ссылка «задать пароль» ведёт на него); можно '%env(PUBLIC_URL)%': формат проверяется, когда значение прочитано (неверное — исключение с текстом, пустое — как будто опции нет); без него берутся framework.trusted_hosts, затем настройка «Домен»; заголовок Host запроса сам по себе не годится (см. «Почта»)
       strict: ~                  # строгий режим шаблонов: по умолчанию как kernel.debug (раздел «Строгий режим»)
       blog: true                 # false — сайт без блога: шаблонов blog, blog_category, blog_article и ссылки «Статьи блога» нет (то же, что $s->withoutBlog())
       security_headers: true     # X-Content-Type-Options и Referrer-Policy на публичных маршрутах бандла (раздел «Публичные страницы: заголовки и 304»)
       conditional_responses: true   # ETag, Last-Modified и 304 для живых страниц посетителю без сессии; в debug не работает
       page_cache:
           enabled: true          # готовый HTML живых страниц хранится в кэше и отдаётся без отрисовки (раздел «Кэш страниц»); в debug не работает
           ttl: 3600              # секунд, не меньше 1: дольше запись не живёт, даже если ключ тот же
       update_source: ~           # адрес, который отдаёт JSON с последней версией itk/cms (раздел «Состояние сайта»); по умолчанию выключено
       telegram:
           enabled: true
           chat_ids: '%env(default::ITK_TELEGRAM_CHAT_IDS)%'
   ```
8. **Миграции**: бандл сам добавляет `Itk\Cms\Migrations`. `bin/console doctrine:migrations:migrate`. Своё пространство имён миграций сайта задаёт `config/packages/doctrine_migrations.yaml`, который создаёт рецепт `doctrine/doctrine-migrations-bundle`. В новом проекте (skeleton 7.4) это `App\Migrations` в `src/Migrations/`; в сайтах постарше встречается `DoctrineMigrations` в `migrations/`. Откройте файл и посмотрите, что у вас: ниже это пространство называется `<ваше пространство>`. Порядок миграций сайта и бандла, `diff` и `schema_filter` — раздел «Миграции сайта».
9. **Первый запуск**: `bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com` (пароль спросит, либо `--password` / переменная `ITK_ADMIN_PASSWORD`; `--no-home` не создаёт главную страницу, если вы заведёте её сами). Команда безопасна для повторного запуска: роли, области меню из схемы, настройки по умолчанию, таблица очереди сообщений (транспорты Messenger, которые умеют подготовить себя, например `doctrine://`), главная страница, первый администратор. Пустой `APP_SECRET` она отмечает предупреждением. **Порядок на новом сайте:** `doctrine:migrations:migrate`, затем `itk:install`, и только потом `doctrine:schema:validate` и `doctrine:migrations:diff`. Таблицу `messenger_messages` создаёт `itk:install`, а транспорт Doctrine добавляет её в ожидаемую схему: раньше этого шага `validate` красный, а `diff` запишет `CREATE TABLE messenger_messages` в миграцию сайта.
10. **Админка**: собранная админка уже лежит в пакете (`public/admin`); достаточно `bin/console assets:install` (Flex делает это сам при `composer install` и `update`) — файлы доступны как `/bundles/itkcms/admin/`. Собирать её самим нужно только при разработке CMS (раздел «Разработка самой CMS»).
11. **Схема сайта** `config/itk/schema.php` и шаблоны `templates/layout.html.twig`, `templates/pages/<шаблон>.html.twig`, `templates/sections/<секция>.html.twig`.

## Docker

Сайт на ITK CMS целиком запускается в Docker. Готовый комплект лежит в пакете, в каталоге `docker/` (в `vendor/itk/cms/docker` у сайта, в `packages/cms/docker` у клона репозитория). Вы копируете его в сайт и правите как свои файлы.

**Что в комплекте.**

| Файл | Зачем |
|---|---|
| `compose.yaml` | Сервисы `php` (php-fpm), `nginx`, `worker` (очередь), `database` (PostgreSQL 17 с pgvector) и `mailer` (Mailpit). Порты открыты только на `127.0.0.1` |
| `Dockerfile` | Образ PHP: стадии `php_base`, `php_dev`, `php_prod`; расширения бандла, Composer, проверка здоровья через `php-fpm ping` |
| `Dockerfile.dockerignore` | Что не попадает в сборку: `.git`, `vendor`, `node_modules`, `var`, `public/uploads`, `public/bundles`, `.env.local`, `.env.*.local`, `.env.local.php`, ключ `config/secrets/*/*.decrypt.private.php`, `compose.override.yaml` |
| `nginx/default.conf` | Статика, кэш файлов сборки, заголовки безопасности, отдача в `index.php` |
| `php/conf.d/10-app.ini`, `php/php-fpm.d/zz-app.conf` | Настройки PHP и пула php-fpm |
| `php/docker-entrypoint.sh` | При старте `php`: `composer install`, ожидание базы, миграции, `itk:install` |

**Как собирается образ.** Пути `COPY` в `Dockerfile` начинаются с `docker/`, поэтому один файл работает в двух раскладках: из корня сайта (комплект лежит в `docker/`, контекст `.`) и из `packages/cms` (так собирает CI). Команда в обоих случаях одна: `docker build -f docker/Dockerfile --target php_dev .`.

**Сборочные аргументы.** `PHP_VERSION` (по умолчанию `8.4`; бандл работает с 8.3), `CRITICAL_CSS` (`0` или `1`, см. «Критический CSS в Docker»), `UID` и `GID` (пользователь `www-data` в контейнере получает ваши идентификаторы, чтобы файлы в смонтированном каталоге не принадлежали root).

### Первый запуск

На хосте нужен только Docker. PHP и Composer ставить не нужно.

1. **Сайт.** Если сайта ещё нет, создайте Symfony skeleton Composer'ом из образа и перейдите в него. Если сайт есть, пропустите шаг.
   ```bash
   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   ```
2. **Комплект.** Скопируйте его в корень сайта.
   ```bash
   cp -R vendor/itk/cms/docker docker                      # пакет уже в vendor сайта
   cp -R /path/to/the/repository/packages/cms/docker docker   # или из клона
   mv docker/compose.yaml compose.yaml
   ```
3. **Пакет.** Пока он не на Packagist, подключите его как `path`-репозиторий. В `compose.yaml` у сервисов `php`, `nginx` и `worker` раскомментируйте строки с пометкой `# bundle` и поправьте левую часть пути на ваш клон (`../itkcms:/itk`). В `composer.json` сайта добавьте путь внутри контейнера и отключите Docker-рецепты Symfony Flex:
   ```json
   "repositories": [{ "type": "path", "url": "/itk/packages/cms", "options": { "symlink": true } }],
   "require": { "itk/cms": "*@dev" },
   "extra": { "symfony": { "docker": false } }
   ```
   Запись `docker: false` нужна до первого запуска Composer. В контейнере Flex не спрашивает, а молча применяет рецепты Docker для Doctrine и Mailer: они дописывают второй `database` в `compose.yaml` и создают `compose.override.yaml`.
4. **Маршруты, безопасность и секрет.** Выполните шаги 4 и 5 раздела «Первый сайт за 10 минут» (`config/routes/itk.yaml`, `security.yaml`) и положите `APP_SECRET` в `.env.local`. `DATABASE_URL` в `.env.local` не нужен: его задаёт `compose.yaml`. Каталог `vendor` с хоста контейнеру бесполезен (ссылка на `/itk` в нём висит в пустоте), `composer` внутри контейнера поставит свой.
5. **Переменные** (необязательно). Compose берёт их из окружения оболочки и из файла `.env` рядом с `compose.yaml`. Это тот самый `.env` сайта, который лежит в git: пишите в него только безопасные значения по умолчанию. `.env.local` Compose не читает; свой файл подключается ключом `--env-file`.

   | Переменная | По умолчанию | Что делает |
   |---|---|---|
   | `HTTP_PORT`, `DB_PORT`, `MAILPIT_PORT` | `8080`, `5433`, `8025` | Порты на хосте: сайт, PostgreSQL, Mailpit |
   | `DB_NAME` | `app` | Имя базы данных |
   | `COMPOSE_PROJECT_NAME` | имя каталога | Имя проекта Compose; у каждого сайта своё, иначе базы и контейнеры двух сайтов смешаются |
   | `PHP_VERSION` | `8.4` | Версия PHP |
   | `CRITICAL_CSS` | `0` | `1` ставит Node 22 и Chromium в образ |
   | `ADMIN_EMAIL`, `ADMIN_PASSWORD` | пусто | Если заданы обе, первый администратор создаётся при старте |
   | `SITE_NAME`, `SITE_DOMAIN` | пусто | Название и домен для `itk:install` |
   | `UID`, `GID` | `1000` | Пользователь контейнера |

   Пароль администратора в `.env` не пишите: он попадёт в git и в образ `php_prod`. Передайте его один раз из оболочки (`ADMIN_EMAIL=you@example.com ADMIN_PASSWORD=... docker compose up -d`) или создайте администратора шагом 7. `UID` и `GID` на Linux задайте своими значениями строками `UID=1000` и `GID=1000` в `.env` (узнать: `id -u`, `id -g`). Командой `export UID=...` задать их нельзя: в bash и zsh `UID` служебная переменная. Docker Desktop на macOS работает и с `1000`.
6. **Запуск.**
   ```bash
   docker compose up -d --build
   docker compose logs -f php        # дождитесь строки «ready to handle connections»
   ```
   Для `php` entrypoint выполняет `composer install` (при первом запуске и когда `itk/cms` указан в `composer.json`, но не установлен), ждёт базу, применяет миграции и запускает `itk:install`. Файлы админки (`assets:install`) он тоже кладёт сам. Миграции и `itk:install` отключает `AUTO_MIGRATE: "0"` в `environment` сервиса `php` в `compose.yaml` (из `.env` эта переменная не читается).
7. **Администратор.** Если он не создан (не заданы `ADMIN_EMAIL` и `ADMIN_PASSWORD`), создайте его:
   ```bash
   docker compose exec php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   ```
   При `AUTO_MIGRATE: "0"` сначала примените миграции: `docker compose exec php bin/console doctrine:migrations:migrate -n`. Порядок важен, как и в «Первом сайте».

Сайт: `http://localhost:8080`, админка: `http://localhost:8080/admin/login`. Главная создаётся черновиком, опубликуйте её в админке (шаг 7 раздела «Первый сайт за 10 минут»). Шаблоны страниц и макет сайт пишет сам, как и в «Первом сайте».

### Если не запускается

- **Смотрите журнал `php`:** `docker compose logs php`. Строки `[entrypoint] ... failed` называют шаг, на котором контейнер остановился: миграции или `itk:install`. Исправьте причину и выполните `docker compose up -d`.
- **`php` ещё не здоров.** На первом старте php-fpm поднимается после `composer install`, а `nginx` и `worker` ждут здоровья `php`. Подождите несколько минут и повторите `docker compose up -d`.
- **Порт занят.** Задайте другие `HTTP_PORT`, `DB_PORT`, `MAILPIT_PORT` в `.env`.
- **Воркер молчит.** Он ждёт `vendor/autoload_runtime.php`, который создаёт `php`. Смотрите журнал `php`.

### Почта в Docker

Все письма сайта (в том числе «Забыли пароль?») собирает Mailpit: `http://localhost:8025`. Бандл отправляет их через очередь `async`, поэтому письма приходят, только когда работает `worker`. В контейнерах заданы `MAILER_DSN=smtp://mailer:1025` и `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`; значения из `.env` сайта они перекрывают.

### Воркер

Сервис `worker` собран из того же образа, что и `php`, и выполняет `messenger:consume async critical_css --time-limit=3600 --memory-limit=256M`. Транспорт `critical_css` бандл регистрирует всегда, даже когда критический CSS выключен: очередь остаётся пустой. Через час или при превышении памяти воркер завершается, а `restart: unless-stopped` его поднимает. По `docker compose stop` воркер доделывает текущее задание (до двух минут), потому что в образе есть `pcntl`.

Воркер держит ядро Symfony в памяти. После правки PHP-кода, конфигурации, шаблонов и CSS выполните `docker compose restart worker`. Журнал: `docker compose logs -f worker`. Если сайт задал свои транспорты Messenger, поправьте их имена в `command`.

### Критический CSS в Docker

Критическому CSS нужны Node 22, пакет `crittr` и Chrome в том же контейнере, где работает PHP. Проверку «Критический CSS» в «Состоянии сайта» выполняет php-fpm во время веб-запроса, а страницы считает воркер. Поэтому `php` и `worker` собираются из одного образа, а Node и Chromium включаются сборочным аргументом.

1. **Соберите образ с Node и Chromium.** Строка `CRITICAL_CSS=1` пишется в `.env` рядом с `compose.yaml` (это `.env` сайта) или задаётся из оболочки. Лучше сделать это до первого `docker compose up`: тогда образ не пересобирается.
   ```bash
   echo 'CRITICAL_CSS=1' >> .env
   docker compose up -d --build
   ```
   В образ попадают Node 22 с npm (из официального образа `node:22-bookworm-slim`), пакет Debian `chromium` и шрифты с кириллицей и латиницей. Переменные `PUPPETEER_SKIP_DOWNLOAD=1` и `PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium` заданы в образе: puppeteer не качает свой браузер и берёт системный. Опцию `itk_cms.critical_css.chrome` задавать не нужно.
2. **Поставьте crittr в проект** внутри контейнера, чтобы Node был той версии, что и у воркера:
   ```bash
   docker compose exec php npm install 'crittr@^5'
   ```
   Каталог `node_modules` окажется на хосте, добавьте его в `.gitignore`; `package.json` и `package-lock.json` закоммитьте. Для сборки `php_prod` crittr должен быть в `dependencies`, а не в `devDependencies`: `npm install` пишет именно туда.
3. **Включите опцию** в `config/packages/itk_cms.yaml` и замените `<link>` на `{{ itk_stylesheets() }}` (шаги 2 и 3 раздела «Критический CSS»). Минимальный макет:
   ```twig
   <!doctype html>
   <html lang="ru">
   <head>
       <meta charset="utf-8">
       <title>{{ page.title }}</title>
       {{ itk_stylesheets() }}
   </head>
   <body>{% block body %}{% endblock %}</body>
   </html>
   ```
   Затем сбросьте кэш и перезапустите воркер: новый файл конфигурации сначала не виден ни `php`, ни воркеру, а воркер без перезапуска считает страницы по старой конфигурации и записывает их как `failed`.
   ```bash
   docker compose exec php bin/console cache:clear
   docker compose restart worker
   ```
4. **Проверьте на одной странице.** Команда печатает причину каждой неудачи:
   ```bash
   docker compose exec php bin/console itk:critical-css:generate --page=<id> --now
   docker compose exec php bin/console itk:critical-css:generate     # поставить в очередь страницы без готового CSS (--all: все)
   ```
   Если у всех окон получились одинаковые правила, посетитель получит один `<style>` без `media`.

### Сборка для продакшена

Стадия `php_prod` копирует сайт в образ, ставит зависимости без dev-пакетов (`composer install --no-dev`, а при `CRITICAL_CSS=1` ещё `npm ci --omit=dev`) и включает `php.ini-production`:

```bash
docker build -f docker/Dockerfile --target php_prod --build-arg CRITICAL_CSS=1 -t mysite:1.0 .
```

- **В образ не попадают** `.env.local`, `.env.*.local`, `.env.local.php` и ключ расшифровки секретов `config/secrets/*/*.decrypt.private.php`. `.env` из git копируется: держите в нём только значения по умолчанию. `APP_SECRET`, `DATABASE_URL` и `MAILER_DSN` передавайте окружением контейнера при запуске.
- **Файлов админки (`public/bundles`) в образе нет.** Их кладёт entrypoint при старте `php`. Чтобы nginx их отдавал, дайте ему общий том или выполните `assets:install public` в своей стадии сборки.
- **Миграции и `itk:install` идут при каждом старте `php`.** При нескольких репликах задайте `AUTO_MIGRATE=0` и запускайте миграции отдельной задачей.
- **Пакет должен ставиться без монтирования:** `path`-репозиторий на `/itk` в образе не работает, нужны Packagist или `vcs`-репозиторий.
- **Воркер** запускается из того же образа с `SKIP_ENTRYPOINT=1` и командой `php bin/console messenger:consume async critical_css --time-limit=3600 --memory-limit=256M`.
- **`compose.yaml` из комплекта рассчитан на разработку:** код монтируется с хоста, образ `php_dev`. Оркестрацию продакшена, в том числе образ nginx с каталогом `public/`, сайт описывает сам.

### Обновление

Комплект копируется в сайт и дальше принадлежит сайту, поэтому обновление пакета его не меняет. После `composer update itk/cms` сравните каталоги и перенесите изменения Dockerfile, команды воркера и сборочных аргументов.

```bash
docker compose exec php composer update itk/cms
# пакет на Packagist:
diff -r -x compose.yaml vendor/itk/cms/docker docker && diff vendor/itk/cms/docker/compose.yaml compose.yaml
# path-репозиторий: ссылка vendor/itk/cms на хосте висит в пустоте, сравнивайте с клоном
diff -r -x compose.yaml /path/to/the/repository/packages/cms/docker docker
docker compose up -d --build
```

Что менялось в комплекте, записано в [UPGRADE.md](../../UPGRADE.md) и [CHANGELOG.md](../../CHANGELOG.md).

### Проверка

```bash
docker compose exec php bin/console itk:doctor
```

Команда проверяет окружение (расширения PHP), настройки, миграции, файрволы, очередь, сборку админки и схему сайта. Группа «Состояние сайта» включает проверку «Критический CSS» (Node, `crittr`, свежесть строк). В контейнере `php` она видит тот же Node, что и воркер. С ключом `--ci` команда завершается кодом 1, если проверка не прошла, и годится для скрипта выкладки.

## ИИ-агент в проекте сайта

Правила для ИИ-агента, который делает сайт на ITK CMS, лежат в пакете: [SITE-AGENTS.md](SITE-AGENTS.md) (у сайта это `vendor/itk/cms/SITE-AGENTS.md`). Главное в них такое. Возможность, которой не хватает ядру и которая пригодится другим сайтам, агент предлагает pull request'ом в [it-koval/cms](https://github.com/it-koval/cms), а не копирует и не дорабатывает ядро внутри сайта. Мейнтейнеры принимают PR, и доработку получают все сайты на CMS.

Файл не подхватывается сам: агенты читают инструкции из корня проекта. Подключите его в файле инструкций сайта, и правила будут обновляться вместе с пакетом.

- **Claude Code**, строка в `CLAUDE.md` сайта:
  ```markdown
  @vendor/itk/cms/SITE-AGENTS.md
  ```
- **Codex, Cursor и другие агенты, которые читают `AGENTS.md`**, строка в `AGENTS.md` сайта:
  ```markdown
  Перед работой прочитай vendor/itk/cms/SITE-AGENTS.md: это правила ядра ITK CMS, они обязательны.
  ```

Пока пакет подключён `path`-репозиторием, ссылка `vendor/itk/cms` на хосте может вести в путь контейнера (`/itk/packages/cms`, раздел «Docker»). Тогда укажите путь к клону репозитория, например `@../itkcms/packages/cms/SITE-AGENTS.md`.

## Схема сайта (публичный контракт)

```php
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\SchemaBuilder;

return static function (SchemaBuilder $s): void {
    $s->design(headlineMarks: ['gradient']);                       // что умеет дизайн
    $s->menuArea('header', 'Header menu', depth: 2);
    $s->layout(top: ['header'], bottom: ['footer']);              // области меню в макете (по умолчанию: header сверху, остальные снизу)
    $s->section('hero', 'Hero')->group('Первый экран')->short('Hero')->tone('dark')->silhouette('hero')->about('…')->fields(   // группа 'Первый экран' служебная: секция из неё несёт H1, админка показывает её имя на языке интерфейса
        Field::headline('headline', 'Headline')->max(48)->required(),
        Field::description('description', 'Description')->max(90),
        Field::link('buttonLink', 'Where the button leads'),
        Field::image('image', 'Photo on the right'),
    );
    $s->template('catalog', 'Catalog')->single()->topLevel()->sections('all')->listing(of: ['product'], label: 'Products', onlyIf: 'catalog');
    $s->template('product', 'Product')->parents('catalog')->leaf()->shortcut('Products')->card(image: 'img', title: 'title', text: 'desc')->fields(/* … */);
    $s->collection('ambassadors', 'Ambassadors')->view('cards')->sortable()->titleField('name')->fields(/* … */);
    $s->settings('contacts', 'Contacts and social networks')->fields(Field::text('phone', 'Phone')->required());
    $s->form('call', 'Call request')->fields(Field::text('phone', 'Phone')->required())->telegram('Request #{id}: {phone}');
};
```

Поля: `text`, `textarea`, `description` (rich), `headline`, `article`, `number`, `toggle`, `select`, `date`, `image`, `link`, `repeater`, `photos`, `note`. Методы поля: `max`, `required`, `help`, `rows`, `variant`, `lineBreak`, `addLabel`, `noun`, `inputType`, `translatable` (настройки), `initial`, `pattern` (формы сайта), `half`, `section`, у `number` ещё `grouped`; у `repeater` ещё `itemTitle`, `itemSummary`, `duplicable` (раздел «Поля»).

Шаблоны ядра: `home`, `default`, `blog`, `blog_category`, `blog_article`. Сайт меняет такой шаблон повторным `$s->template('blog_article')`. **Сайт без блога:** `$s->withoutBlog()` в начале `schema.php` (или `itk_cms.blog: false`) убирает три шаблона блога, а с ними и ссылку «Статьи блога» в меню админки; своё описание с ключом `blog` после этого вызова остаётся обычным шаблоном сайта. Шаблон, который называет убранный `blog` в `parents()` или `listing(of:)`, `itk:schema:lint` покажет ошибкой.

### Проверка схемы: `Schema::validate()` и `itk:schema:lint`

`Schema::validate()` возвращает список ошибок схемы на языке админки (в консоли это `admin_locale`), с именем раздела («Секция «hero»: ключ поля «title» повторяется …»). Она ловит: повторный ключ поля (в секции, шаблоне, коллекции с её вкладками, форме, поле внутри повторителя) и повторный ключ настройки в двух группах; пустой или неверный ключ (латинские буквы, цифры, `_` и `-`, первый знак буква или цифра: один формат `Schema\Key` для `schema.php` и для классов-ресурсов); `parents()` и `listing(of:)`, которые называют шаблон, которого нет; форму без полей; `titleField()` коллекции на поле, которого нет; ключ поля `version` у коллекции (так называется версия черновика в ответе записи); область меню, описанную дважды. В debug схема проверяется при загрузке: ошибка — исключение со всем списком; в prod проверки на каждый запрос нет. `bin/console itk:schema:lint` показывает те же ошибки и ещё проверяет, что у каждой секции есть её Twig-шаблон (`sections/<ключ>.html.twig` или `template()` секции; нет — ошибка) и что у каждого шаблона страницы есть `pages/<ключ>.html.twig` (нет — предупреждение: страницы выведет шаблон `default`). Код выхода 1 при ошибке, 0 без ошибок (предупреждения его не меняют).

### Twig

| Функция | Что делает |
|---|---|
| `render_sections(page)` | выводит видимые секции через `sections/<тип>.html.twig` (переменные `section`, `page`, `is_h1`); секция неизвестного типа и секция без шаблона в строгом режиме — исключение, иначе предупреждение в журнале |
| `itk_field(section\|page, 'headline')` | значение поля: headline/rich — безопасный HTML, text — экранированный, image/link/toggle/repeater — данные как есть. В предпросмотре оборачивает в `<span data-f data-sec>` |
| `itk_field_attrs(section\|page, 'image')` | атрибуты `data-f`/`data-sec` для не текстовых полей (только предпросмотр) |
| `itk_section_attrs(section)` | атрибуты корня секции (`data-sec`, `data-type`, `data-label`, `data-chg`), только предпросмотр |
| `itk_section_class(section)` | `'chg'`, если секция отличается от опубликованной (только предпросмотр), иначе пусто |
| `itk_is_preview()` | `true`, пока холст админки рисует черновик |
| `itk_cache(300)`, `itk_cache(false)` | `{% do itk_cache(300) %}` — кэш страниц хранит эту отрисовку не дольше 300 секунд, `{% do itk_cache(false) %}` — не хранит совсем. Страница, которая показывает данные из таблиц сайта или время суток, ставит это сама (раздел «Кэш страниц»). Вызов вне отрисовки живой страницы (предпросмотр, письма) ничего не меняет |
| `menu('header')` | пункты области: `label, kind, url, active, open, children` (только опубликованные страницы) |
| `settings('phone')`, `settings()` | настройки языка страницы |
| `media_url(image)`, `media_url(image, 1280)`, `media_url(image, 960, '16:9')` | адрес картинки; с шириной — адрес WebP-копии (ближайшая разрешённая ширина не меньше заданной); с пропорцией — адрес кадра этой пропорции (см. «Картинки») |
| `media_width(image)` | ширина оригинала файла медиатеки в пикселях (`null` для чужих адресов): для картинок, которые вёрстка показывает в натуральный размер |
| `page_url(link)` | безопасный адрес по значению поля «Ссылка», странице или id страницы (см. ниже) |
| `media_srcset(image[, maxWidth[, ratio]])` | значение `srcset`: копии разрешённых ширин до оригинала (его размер всегда последний) и до первой, которая покрывает `maxWidth` (в пикселях устройства, с допуском 10%); для GIF, SVG и чужих адресов — пусто |
| `media_ratio(width, height)` | ключ пропорции фиксированного места в вёрстке: `media_ratio(700, 250)` → `'14:5'` (дробь сокращается; если стороны больше 99 — ближайшая пропорция, `1095×515` → `17:8`). Чтобы файл приходил в форму своего места: `media_url(img, 700, media_ratio(700, 250))`, то же для `media_srcset` и `itk_img({ratio})`. Пропорция, которой нет в `itk_cms.images.ratios` и в сохранённых кадрах файла, не ломает страницу: берётся вся картинка, в лог пишется предупреждение (добавьте пропорцию в `itk_cms.images.ratios`) |
| `itk_img(image, {sizes, class, width, height, loading, alt, src_width, max_width, attrs, ratio})` | готовый `<img src srcset sizes alt class width height loading="lazy" decoding="async">` с `alt` картинки; если сервер умеет AVIF, `<img>` обёрнут в `<picture><source type="image/avif" srcset sizes>…</picture>`. `ratio: '4:3'` — кадр этой пропорции (при `width` высота считается сама). `src_width` — ширина `src` (по умолчанию `width`, иначе 960), `max_width` — самая широкая картинка, которую может показать вёрстка, в пикселях устройства (по умолчанию `2×width`), `sizes` по умолчанию `(max-width: {width}px) 100vw, {width}px`, `attrs` — массив дополнительных атрибутов (экранируется) или `itk_field_attrs(...)` |
| `listing(page)` | опубликованные страницы (или записи коллекции), которые собирает страница |
| `itk_collection('ambassadors')` | опубликованные записи коллекции по порядку |
| `itk_collection('tours', {where, sort, limit, offset})` | то же с отбором, порядком и срезом силами базы; только опубликованное, одинаково для всех (раздел «Публичное чтение») |
| `itk_pages({template, parent, limit, sort})` | опубликованные страницы шаблона / под страницей: `{id, parentId, template, locale, title, url, seo, fields, position, publishedAt}` |
| `itk_page(id)` | опубликованная страница по id (та же строка) или `null`; много вызовов подряд — один запрос на язык |
| `itk_seo({title, description, robots, canonical, ogImage})` | мета-теги текущего адреса: `{title, description, robots, canonical, ogImage, rule}`; аргумент — что сказать, если не сказали ни правило, ни страница (раздел «SEO для любого адреса») |
| `itk_t('area.key', {name: x})` | текст интерфейса из каталогов бандла (`translations/itk_<area>.<locale>.yaml`) на текущем языке админки, как `Itk\Cms\I18n\I18n::t()`; сайт переопределяет его своим файлом с тем же именем в `translations/` |
| `itk_locale()` | текущий язык интерфейса админки (`en`, `ru`, `kk`) для `<html lang>` страниц входа и писем. Это не язык содержимого |
| `itk_stylesheets()`, `itk_stylesheets({nonce: x})` | таблицы стилей сайта из `itk_cms.critical_css.stylesheets` для `<head>` макета: обычный `<link rel="stylesheet">`, а на живой странице с готовым критическим CSS — `<style media>` на каждый диапазон экранов, ссылка с `media="print" onload` и `<noscript>` (раздел «Критический CSS»). `nonce` попадает в `<style>` и в ссылку с `onload` |
| `itk_admin_font()` | правила `@font-face` шрифта админки (Golos Text) для страниц входа и восстановления пароля: адреса файлов берутся из манифеста сборки, с чужих сайтов шрифт не грузится; без сборки пусто. Нужна только своим шаблонам таких страниц |

`page_url(link)` отдаёт только безопасный адрес: путь сайта (`/blog`, не `//host`), адрес страницы по её id, `http(s)://`, `mailto:`, `tel:`, `#якорь`. Адрес с другой схемой (`javascript:`, `data:`) становится пустой строкой, даже если такой лежит в старых или импортированных данных. Поле «Ссылка» при сохранении проверяется тем же правилом: у вида «страница» `page` — путь сайта или id страницы (иначе `/`), у вида «адрес» `url` — `http(s)://`, `mailto:`, `tel:`, путь сайта или `#якорь` (иначе пусто). Правило одно для полей страниц, записей коллекций, настроек и пунктов меню: `Itk\Cms\Support\Url::cleanPageRef()`, `cleanLinkUrl()`, `safeHref()`.

Макет сайта (`templates/layout.html.twig`) обязан объявить `{% block content %}`; для `<title>` удобно `{% block title %}`. Переменные в шаблоне страницы: `page` (`id, parentId, template, locale, title, url, seo, fields, sections, h1Id`), `site` (настройки + `name`, `domain`, `locale`). Первый видимый раздел группы «Первый экран» получает `is_h1`; его H1-полем считается `->h1('поле')` секции (по умолчанию — первое поле-заголовок). Шаблон с `->h1('поле')` владеет H1 сам; метку и пояснение «Заголовок страницы (H1)» получает только это одно поле, остальные заголовки сохраняют свои. Если H1-поле — `headline`, название страницы = его первая строка простым текстом.

### Публичное чтение: `itk_pages`, `itk_page`, `itk_collection`

Класс `Itk\Cms\Content\ContentReader` (его вызывают и Twig-функции) читает для сайта **только опубликованное** и одинаково для всех: гость и вошедший администратор видят одно. Черновик, снятая с публикации страница и черновой текст опубликованной в ответ не попадают. Это не `CollectionQuery`: тот работает с черновиком и скрывает поля от смотрящего. Каждый вызов — один запрос, который берёт только нужные столбцы (страницы, версии и записи объектами не загружаются).

```twig
{% for c in itk_pages({template: 'blog_category'}) %}<a href="{{ c.url }}">{{ c.title }}</a>{% endfor %}
{% for a in itk_pages({template: 'blog_article', parent: category.id, sort: '-fields.date', limit: 6}) %}…{% endfor %}
{{ itk_page(article.parentId).title }}
{% for t in itk_collection('tours', {where: {state: 'open', seats: {from: 5}, level: ['easy', 'mid']}, sort: '-start', limit: 10, offset: 20}) %}…{% endfor %}
```

- `itk_pages`: `template` — ключ или список ключей, `parent` — id страницы (только прямые дети; пустое значение — ошибка, чтобы `parent: page.parentId` у страницы верхнего уровня не выбрал всё), `limit` ≥ 1, `sort` — `position` (по умолчанию: порядок среди соседей, затем по дате создания), `createdAt`, `publishedAt`, `title`, `url`, `fields.<ключ поля>`; `-` в начале — по убыванию, несколько — списком или через запятую. Числовое поле шаблона сортируется как число. В строке нет `sections` (они тяжёлые): секции целой страницы даёт `listing()`.
- `itk_page(id)`: все опубликованные страницы языка читаются одним запросом на весь запрос посетителя, поэтому цикл по статьям с `itk_page(a.parentId)` не даёт запрос на статью.
- `itk_collection(key)` без второго аргумента работает как раньше. С параметрами: `where` — `{поле: значение}` (список — любое из значений, `true`/`false` — переключатель, `{from, to}` — границы числа или даты `2026-11-30`; поле должно быть в коллекции), `sort` — `position`, `createdAt` или поле (`-` по убыванию), `limit`, `offset`. Запись приходит как `{id, …опубликованные поля}`.
- Неверный параметр, поле или шаблон — `\InvalidArgumentException` со списком допустимого; в Twig это ошибка строгого режима (исключение в debug, пустой список и предупреждение в журнале в prod).
- Сценарий `BlogExtension` сайта (рубрики блога и заголовок родителя статьи): `itk_pages({template: 'blog_category'})`, `itk_pages({template: 'blog', limit: 1})` и `itk_page(article.parentId).title`.

### Строгий режим: `itk_cms.strict`

Ошибки шаблонов сайта по умолчанию не видны: чужой ключ в `itk_field` отдаёт пустоту, секция неизвестного типа или без шаблона просто не выводится. Опция `itk_cms.strict` (по умолчанию как `kernel.debug`; `null` — так и есть) делает их громкими: `itk_field(section, 'headlin')` бросает исключение «у секции «hero» нет поля «headlin». Допустимые ключи: headline, description, …», то же для ключа внутри повторителя, для типа секции, которого нет в схеме, для секции без Twig-шаблона и для неверного вызова `itk_pages`/`itk_collection`. При `strict: false` (так в prod) страница рисуется как раньше, а в журнал уходит одно предупреждение на каждую ошибку за запрос. Путь внутрь значения поля без подполей (`image.src`) ошибкой не считается. Холст админки (предпросмотр) не бросает исключений никогда: серая рамка «шаблона секции нет» осталась, чтобы редактор мог убрать лишнюю секцию.

### Публичные страницы: заголовки и 304

- **Заголовки безопасности.** Публичные маршруты бандла (живая страница, `sitemap.xml`, `robots.txt`, `llms.txt`, копии картинок, `POST /request/{form}`, `/healthz`) получают `X-Content-Type-Options: nosniff` и `Referrer-Policy: strict-origin-when-cross-origin` (`Security\PublicHeadersListener`); заголовок, который выставил контроллер, остаётся. `itk_cms.security_headers: false` отключает. Страницы собственных контроллеров сайта — дело сайта.
- **ETag и 304.** Посетитель без сессии и без `Authorization` получает у живой страницы `ETag`, `Last-Modified` и `Cache-Control: no-cache, private`; если он присылает свой `ETag` в `If-None-Match` и страница та же, ответ — 304 без отрисовки (на проверку идёт один запрос из нескольких сумм по таблицам; он выполняется при каждом запросе живой страницы посетителя без сессии, даже без `If-None-Match`, и читает записи всех коллекций целиком: на 30 000 записей по 13 КБ в JSON это около 0,1 с, на сотнях записей — единицы миллисекунд. Тот же запрос нужен и кэшу страниц: из его результата строится ключ записи, поэтому `conditional_responses: false` один его не убирает. У сайта с очень большими коллекциями выключите и `conditional_responses`, и `page_cache.enabled`). Метка зависит от того, что страница может показать: самой страницы (дата публикации и снимок), опубликованных страниц языка (меню и списки показывают только живые), места этих страниц в дереве (списки идут в этом порядке), настроек, меню, опубликованных записей коллекций, сохранённых рамок картинок (они определяют адрес картинки), SEO-правил, строки запроса, сборки контейнера (деплой с `cache:clear` её меняет) и текущей даты по UTC. Черновики на метку не влияют: она публичная и не должна выдавать момент правки, а автосохранение не должно лишать посетителей 304. Дата нужна страницам, которые показывают то, что происходит «сейчас» (например, поездки, которые ещё не начались): такая страница устаревает сама по себе, поэтому метка живёт не дольше суток. Точнее зависимость от времени (до часа или минуты) метка не отражает: если сайту нужно именно это, отключите условные ответы (`conditional_responses: false`). `Last-Modified` — дата публикации страницы или самой свежей живой страницы; один `If-Modified-Since` без метки всегда получает полный ответ, потому что по дате нельзя заметить правку настройки или меню. Дата публикации хранится с точностью до секунды: две публикации разных страниц в одну секунду метку второй могут не сменить. В debug метка не ставится (изменённый шаблон должен быть виден сразу), `itk_cms.conditional_responses: false` отключает совсем. После правки шаблонов без очистки кеша контейнера (`cache:clear`) посетители, у которых страница уже сохранена, получат 304 со старой вёрсткой, пока страница или настройки не изменятся.
- **`GET /healthz`.** Открытый адрес для балансировщика или монитора: `{"status":"ok"}` (200) или `{"status":"fail"}` (503), больше ничего. Проверяет, что база отвечает и что в папку `upload_dir` можно записать файл (если папки ещё нет — что можно записать в ближайшую существующую над ней). Подробности для сотрудников — в «Состояние сайта» и `itk:doctor`.

### Кэш страниц

Готовый HTML живой страницы хранится в кэше (`Rendering\PageCache`), и повторный показ идёт без отрисовки: не открывается шаблон Twig, не читаются настройки, меню и записи коллекций. Правки видны посетителям сразу, ничего сбрасывать не нужно.

- **Ключ.** `схема | домен | базовый путь | отпечаток`. Отпечаток тот же, из которого строится `ETag` (раздел выше): страница и её снимок, опубликованные страницы языка и их порядок в дереве, настройки, меню, рамки картинок, опубликованные записи коллекций, SEO-правила, критический CSS страницы и отметка таблиц стилей (раздел «Критический CSS»), строка запроса, сборка контейнера и текущая дата по UTC. Любая правка, которую видит отпечаток (публикация, настройка, меню, картинка, запись, правило и правка строки прямо в базе), даёт другой ключ, и старая запись больше не находится: событий и сброса для этого нет. Схема, домен и базовый путь (`getBaseUrl()`: сайт лежит в подкаталоге; его задаёт сервер, а не заголовок запроса) входят в ключ потому, что `canonical`, `og:url` и ссылки шаблоны строят из запроса. Куки, `User-Agent`, `Accept-Language` и другие заголовки запроса ключ не знает (см. «Чего ключ не видит»). Сайт за прокси должен задать `trusted_proxies`, иначе схема и домен будут прокси; то же нужно ограничителю форм.
- **Что хранится.** HTML, статус, `Content-Type` и время создания. Другие заголовки не хранятся (шаблоны Twig их не ставят). Хранится только ответ `200` без `Set-Cookie`, если сессия при отрисовке не стартовала и шаблон не запретил кэш. 404, редиректы, предпросмотр, `sitemap.xml`, `robots.txt`, `llms.txt` и `POST /request/{form}` в кэш страниц не попадают. Ответ на `HEAD` не хранится, но берёт запись, которую сделал `GET`.
- **Мимо кэша.** Кэш не читается и не пишется, если опция выключена, включён `kernel.debug` (изменённый шаблон должен быть виден сразу), запрос с заголовком `Authorization`, с прежней сессией (вошедший в админку видит свежую страницу, и его отрисовка в кэш не попадает) или метод не `GET` и не `HEAD`. А ещё запрос со строкой запроса (`/page?x=1`), к хосту или порту, за которые никто не поручился, по другому написанию адреса, чем у страницы, и с заголовком `Forwarded` или `X-Forwarded-Prefix` (пункты ниже). Ответ на такой запрос рисуется как обычно, `ETag` и 304 работают, как раньше, заголовок `X-ITK-Cache: BYPASS`.
- **Зачем эти ограничения.** Число записей не должно зависеть от того, что способен выдумать посторонний, и общий HTML не должен зависеть от того, что он подсунул. Иначе любой наполнит диск сервера (пул лежит в файлах и не ограничен по размеру) или отравит страницу для всех: достаточно гонять `?x=<случайное>`, подделывать `Host:`, порт или `X-Forwarded-Prefix`, писать адрес страницы то так, то иначе. Поэтому в кэше остаётся одна запись на страницу, схему, свой хост и порт.
- **Какой хост и порт считаются своими.** Хост кэшируется, только если за него поручились. Либо задан `framework.trusted_hosts` (`Request::getTrustedHosts() !== []`): Symfony уже отказал всем остальным хостам до контроллера. Либо хост запроса (без учёта регистра) совпадает с хостом настройки «Настройки → Сайт → Адрес сайта» (`domain`) и порт тот же. Имя в кириллице сравнивается в ASCII (`xn--…`, как его присылает браузер; нужно расширение `intl`, без него имена сравниваются как есть), порт по умолчанию схемы (`:443` у `https`, `:80` у `http`) считается отсутствием порта. Кроме хоста поручиться нужно и за порт, при любом из двух способов: порт запроса (`Host: example.com:1111` или `X-Forwarded-Port` от доверенного прокси) должен быть портом по умолчанию его схемы (80, 443) или портом из «Адреса сайта». Иначе `trusted_hosts`, который пропускает хост с любым портом, оставил бы число записей в руках постороннего. Если не задано ни то ни другое, страницы отдаются без кэша (`BYPASS`). На проде задайте `trusted_hosts` (рекомендуется: он закрывает и другие подделки `Host`, например в ссылках писем) или «Адрес сайта».
- **Какой адрес считается каноническим.** Кэш работает, только если путь запроса (`getPathInfo()`) в точности равен адресу найденной живой страницы: так, как он сохранён (регистр тоже), с одним ведущим слэшем и без конечного, русские буквы закодированы, как в `Url::encodePath()` (так браузер идёт по ссылке); главная — `/`. Страницу находят и по `/ABOUT`, `//about`, `/%61bout` и `/about%09`, но шаблон строит `canonical` и `og:url` из запроса: ответ на такое написание, попав в общую запись, показал бы всем чужой адрес. Эти запросы идут мимо кэша.
- **Прокси и префикс.** Запрос с заголовком `Forwarded` или `X-Forwarded-Prefix` идёт мимо кэша: префикс меняет базу всех ссылок страницы, а значение придумывает тот, кто шлёт заголовок. Базовый путь самого сервера (`getBaseUrl()`) задан сервером и входит в ключ.
- **`X-ITK-Cache`.** Заголовок `HIT`, `MISS` или `BYPASS` на каждом ответе живой страницы, чтобы проверить кэш из `curl -I`. 304 по `If-None-Match` идёт до кэша, никто его не рисовал, и он отвечает `HIT` (`BYPASS`, если кэш выключен). Публичные заголовки не меняются: `Cache-Control: no-cache, private`, `ETag`, `Last-Modified`, как раньше. Кэш nginx и CDN, `public` и `s-maxage` в этот раздел не входят.
- **Чего ключ не видит.** Куки, `User-Agent`, `Accept-Language` и любые другие заголовки запроса в ключ не входят. Шаблон сайта, вывод которого от них зависит (язык по `Accept-Language`, вёрстка для телефона, имя из куки), обязан вызвать `{% do itk_cache(false) %}`: иначе страницу, нарисованную для первого посетителя, получат все.
- **Чего отпечаток не видит.** Данные сайта в собственных таблицах (расширения Twig, свои сущности) и содержимое, которое зависит от времени внутри суток. Закрыто тремя способами. Срок записи не больше `itk_cms.page_cache.ttl` (по умолчанию 3600 секунд). Шаблон сокращает срок: `{% do itk_cache(300) %}` оставляет запись не дольше 300 секунд, `{% do itk_cache(false) %}` запрещает хранить эту отрисовку; если вызовов несколько (макет и секция), побеждает `false`, иначе меньшее число, число меньше 1 считается как `false`. И, наконец, ручной сброс. Правка шаблона Twig без `cache:clear` кэш не обновляет, как и `ETag`: в prod шаблоны выкладывают с очисткой кэша, сборка контейнера входит в отпечаток.
- **`itk_cache()` и 304.** `itk_cache()` и `ttl` управляют только записью в кэше страниц. На ответ 304 они не влияют: `ETag` живёт, пока не изменится отпечаток содержимого или дата по UTC, и посетитель, у которого страница уже есть, получит 304 и со страницы с `itk_cache(false)`, и после истечения `ttl`. Если страница должна обновляться чаще, выключите `conditional_responses`.
- **Ручной сброс.** `Itk\Cms\Rendering\PageCache::clear()` для кода сайта (например, после собственного импорта), команда `itk:cache:clear` и кнопка «Сбросить кэш страниц» в «Разработчику → Состояние сайта» (право `dev`, «Править»; `POST {admin}/api/health/page-cache/clear`). Сбрасывается только пул кэша страниц. `clear()` возвращает `bool`: `false`, если пул отказал; тогда команда печатает ошибку и выходит с кодом 1, а API отвечает 500 `{error}`.
- **Где хранится.** Пул `itk_cms.page_cache` (`framework.cache.pools`) с адаптером `cache.app` сайта: сброс страниц не трогает карту сайта, `llms.txt` и ограничители. На одном сервере это файлы в `var/cache/<env>/pools`; новых таблиц нет. Сайт, которому нужно общее хранилище (Redis, несколько серверов), задаёт этому пулу свой адаптер в `framework.cache.pools`.
- **Чистка пула.** Ключ меняется при каждой правке, старые записи больше никто не просит, а файловый пул сам просроченные файлы не удаляет: без чистки диск рос бы до `cache:clear`. Поэтому `PageCache::put()` не чаще раза в 10 минут удаляет из пула просроченные записи (если пул умеет, `Symfony\Component\Cache\PruneableInterface`; время последней чистки хранит служебная запись того же пула, проверка стоит одного чтения, а не обхода файлов). Дополнительно поставьте в cron `bin/console cache:pool:prune`: команда чистит все пулы сайта, которые это умеют, в том числе при сайте без трафика.
- **Опции.** `itk_cms.page_cache.enabled` (по умолчанию `true`) и `itk_cms.page_cache.ttl` (секунды, по умолчанию `3600`, не меньше 1).

### Критический CSS

Первый экран живой страницы рисуется до того, как браузер скачает всю таблицу стилей: правила, которые нужны верхней части страницы, лежат прямо в `<head>`, а полный файл подключается без блокировки. Правила вырезает [crittr](https://github.com/philipp-winterle/crittr) в воркере после каждой публикации. Редактор ничего не делает. Выключено по умолчанию.

**Как включить.** Нужны Node 22 или новее и Chrome (или Chromium) на сервере сайта.

1. В корне проекта выполните `npm install crittr`. Puppeteer скачает Chrome для пользователя, который запустил команду; чтобы взять системный Chrome, задайте `chrome` в опциях ниже.
2. Включите опцию и перечислите таблицы стилей сайта:

   ```yaml
   # config/packages/itk_cms.yaml
   itk_cms:
       critical_css:
           enabled: true
           stylesheets: ['/css/site.css']
   ```

3. В макете замените `<link rel="stylesheet" href="/css/site.css">` на `{{ itk_stylesheets() }}`.
4. Примените миграцию (`bin/console doctrine:migrations:migrate`) и проверьте установку на одной странице: `bin/console itk:critical-css:generate --page=<id> --now`. Команда печатает причину каждой неудачи.
5. Запустите воркер для транспорта `critical_css`: `bin/console messenger:consume async critical_css` (один воркер, письма первыми) или отдельный `messenger:consume critical_css`.
6. Поставьте в очередь страницы без готового CSS: `bin/console itk:critical-css:generate` (`--all`: все опубликованные).

**В Docker.** Образ из комплекта собирается с Node 22 и Chromium аргументом `CRITICAL_CSS=1` (раздел «Docker», «Критический CSS в Docker»). Браузер, который puppeteer скачивает при `npm install`, в контейнере не годится. Он ложится в домашний каталог того контейнера, где выполнили `npm install`: воркер его не видит, а после пересоздания контейнера его нет. При установке на хосте он ещё и собран под систему хоста (сборка под macOS в Linux-контейнере не запустится). Поэтому в образе заданы `PUPPETEER_SKIP_DOWNLOAD=1` и `PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium`, и опцию `chrome` задавать не нужно.

**Что получает посетитель.** На живой странице, чей критический CSS готов и актуален, `itk_stylesheets()` печатает по одному `<style media="…">` на диапазон экранов, ссылку на полный файл с `media="print" onload="this.media='all'"` и `<noscript>` с обычной ссылкой:

```html
<style media="(max-width:1365.98px)">…</style>
<style media="(min-width:1366px)">…</style>
<link rel="stylesheet" href="/css/site.css?v=3f9a1c2e" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="/css/site.css?v=3f9a1c2e"></noscript>
```

Во всех остальных случаях функция печатает обычный `<link rel="stylesheet" href="/css/site.css?v=3f9a1c2e">`: выключено, ещё не сделано, устарело, не получилось, предпросмотр в админке, маршрут собственного контроллера сайта, страница ошибки. Страница никогда не хуже, чем без этой возможности. `?v=` следует за файлом (путь, время изменения, размер), поэтому новый файл стилей никогда не сочетается со старым кэшем браузера. Пока опция выключена, `?v=` нет: это ровно сегодняшняя ссылка.

**Когда критический CSS устаревает.** Строка таблицы `itk_critical_css` (одна на языковую версию страницы) хранит, для какой основы она сделана. Основа складывается из опубликованного снимка страницы, сборки контейнера и отметки таблиц стилей (их stat плюс конфигурация генератора). Изменилось любое из трёх, и строка устарела: страница снова отдаётся с обычной ссылкой, а первый показ один раз просит воркер пересчитать (не чаще раза в час на страницу и основу). Поэтому после выкладки нового CSS делать ничего не нужно, страницы сами заживут по мере посещений; `itk:critical-css:generate` в сценарии деплоя только ускорит это. Настройки, меню и записи коллекций в основу не входят: иначе каждая правка настройки пересчитывала бы все страницы. Если меню изменило первый экран, запустите `itk:critical-css:generate --all`.

**Как это сделано.** Воркер отрисовывает опубликованную страницу самим PHP (`PageRenderer::renderSource()`), без HTTP-запроса к сайту, и передаёт HTML вместе с подготовленным CSS скрипту `node/critical-css.mjs` из бандла. Скрипт поднимает сервер на `127.0.0.1`, отдаёт на нём HTML страницы по её адресу и файлы из `public/`, запускает один Chrome и прогоняет crittr по каждому окну из `viewports`. Исходный CSS готовит PHP: файлы из `stylesheets` по порядку, локальные `@import` подставлены на место (один уровень), относительные `url()` переписаны от корня, пустые правила убраны. Если отрисованный HTML и CSS не изменились с прошлого раза (например, выкладка скопировала тот же файл), Chrome не запускается, а старые правила получают новую основу. Пустой результат и результат больше `max_kb` считаются неудачей и не вставляются. Если в CSS есть `!important` или `@layer`, воркер пишет предупреждение в журнал: crittr их теряет, и первый экран до загрузки полного файла может отличаться.

**Неудачи.** Страница, для которой crittr не справился (Chrome не запустился, скрипт превысил `timeout`, результат пустой), помечается как неудачная для своей основы и больше не пересчитывается сама по себе: иначе Chrome запускался бы на каждый показ. Её повторяют `itk:critical-css:generate` (без опций берёт страницы без CSS, с устаревшим и с неудачным), новая публикация или новая выкладка CSS. Воркер никогда не запускается внутри веб-запроса: с транспортом `sync` генератор остановится с `LogicException`.

**CSP.** `<style>` в `<head>` и `onload` у ссылки нужны `style-src 'unsafe-inline'` и `script-src 'unsafe-hashes'` или политика с nonce. Передайте значение своей политики: `{{ itk_stylesheets({nonce: nonce}) }}`, и оно попадёт атрибутом `nonce` в каждый `<style>` и в ссылку с `onload`.

**Опции** `itk_cms.critical_css`: `enabled` (`false`); `stylesheets` (публичные пути с ведущим `/`, без схемы и `..`, в порядке каскада; обязательна хотя бы одна, когда включено); `viewports` (список `{width, height, mobile}`, по умолчанию `390×844` как телефон и `1366×900`; CSS каждого окна вставляется для экранов от его ширины до ширины следующего, одинаковые блоки сливаются); `keep_selectors` и `remove_selectors` (списки селекторов, которые crittr всегда оставляет или всегда выбрасывает); `node` (`node`); `chrome` (`null`, то есть Chrome, который скачал puppeteer); `timeout` (секунды на одну страницу в одном окне, по умолчанию 60, не меньше 10); `max_kb` (предел CSS одного окна, по умолчанию 75).

**Публичный API** здесь: опции `itk_cms.critical_css`, функция `itk_stylesheets()`, команда `itk:critical-css:generate`, имя транспорта `critical_css` и класс сообщения `CriticalCss\Model\GenerateCriticalCss` (чтобы направить его в свой транспорт в `messenger.yaml`). Остальное в `CriticalCss\` помечено `@internal`.

### База знаний

База знаний: таблица pgvector с текстом опубликованных страниц и записей, разбитым на фрагменты с эмбеддингами OpenAI. Воркер обновляет её после каждой публикации и снятия с публикации, а удаление убирает строки само, внешними ключами. Редактор ничего не делает. Сервис `KnowledgeBase\KnowledgeSearch` ищет по смыслу; на нём позже строятся инструмент MCP, поиск по сайту и чат-бот. Выключено по умолчанию.

**Что уходит наружу.** Опубликованный текст сайта отправляется в API OpenAI для построения эмбеддингов. В индекс попадает только то, что и так видит любой посетитель, но решение, можно ли отдавать этот текст стороннему сервису, принимает владелец сайта. Черновики, скрытые секции, поля `raw()`, поля с `canSee()` и страницы с `noindex` никуда не уходят.

**Как включить.** Нужны расширение `vector` в PostgreSQL (образ `pgvector/pgvector:pg17` содержит его; на другом сервере суперпользователь один раз выполняет `CREATE EXTENSION vector`), пакет `symfony/http-client` и ключ OpenAI.

1. Установите клиент: `composer require symfony/http-client`. Положите ключ в `.env.local`: `OPENAI_API_KEY=sk-...`.
2. Включите опцию:

   ```yaml
   # config/packages/itk_cms.yaml
   itk_cms:
       knowledge_base:
           enabled: true
   ```

3. Примените миграцию: `bin/console doctrine:migrations:migrate`. Она создаёт таблицы `itk_kb_document` и `itk_kb_chunk` и выполняет `CREATE EXTENSION IF NOT EXISTS vector`.
4. Заполните индекс: `bin/console itk:knowledge-base:sync --now`. Команда печатает, сколько текстов отправлено и сколько токенов потрачено.
5. Запустите воркер для транспорта `knowledge_base`: `bin/console messenger:consume async critical_css knowledge_base`.
6. Проверьте на одном вопросе: `bin/console itk:knowledge-base:search "как вернуть товар"`.

В сценарий деплоя добавьте `bin/console itk:knowledge-base:sync`: команда ставит в очередь только то, что выкладка сделала устаревшим (схема, конфигурация, модель), и ничего не стоит, когда менять нечего.

**Что индексируется.** Страницы, которые `Content\IndexablePages` отбирает для карты сайта и `llms.txt`: основной язык, опубликованные, без `noindex` в SEO страницы или в правиле SEO её адреса. Записи коллекций: основной язык, опубликованные, кроме коллекций из `exclude_collections`. Другие языковые версии не индексируются, пока публичный маршрут их не отдаёт. Из каждой версии берётся текст, который видит посетитель: заголовок, описание SEO, поля шаблона, видимые секции в порядке вывода. Поле отдаёт текст по своему типу: `Text` и `Textarea` как есть, `Headline` одной строкой, `Description` и `Article` абзацами (статья делится на блоки по подзаголовкам `h2`–`h4`), `Select` как «Подпись: вариант», `Image` и `Photos` своим `alt`, каждая строка `Repeater` отдельным блоком с заголовком из `itemTitle()` или первого текстового поля. `Number`, `Toggle`, `Date`, `Link`, `Relation`, `Note`, список `variant()`, поля `raw()` и поля с `canSee()` не индексируются никогда, поэтому каждый фрагмент можно показать любому.

**Фрагменты.** Блоки одной секции складываются в фрагменты примерно по 1200 символов (не больше 2000). Фрагмент никогда не пересекает границу секции, поэтому у попадания точный `sectionId`. Слишком длинный блок режется по абзацам, затем по предложениям с одним предложением внахлёст. В эмбеддер уходит фрагмент с контекстом: заголовок страницы и заголовок блока перед текстом, так что короткий ответ FAQ ищется вместе со своим вопросом.

**Как индекс остаётся актуальным.** Публикация и снятие с публикации ставят в очередь `knowledge_base` одно сообщение `SyncKnowledge` с идентификатором версии. Обработчик собирает до 32 сообщений и один раз обращается к эмбеддеру, поэтому массовая публикация 200 страниц делает около 7 запросов. Воркер заново читает опубликованный снимок, считает основу документа (хеш модели, заголовка, адреса и каждого фрагмента) и сравнивает с сохранённой. Совпала, значит делать нечего. Эмбеддинг строится только для текста, которого ещё нет в таблице: при правке одной секции в API уходит только она. Документ перезаписывается целиком в одной транзакции под `pg_advisory_xact_lock`, поэтому два воркера на одной странице не мешают друг другу. Снятая с публикации страница пропадает из результатов сразу: поиск присоединяет текущий статус версии, а воркер потом только убирает строки. Удалённая страница или запись уносит свои строки внешними ключами, без сообщения. Автосохранение черновика ничего не запускает.

Индекс догоняет публикацию за секунды или до минуты: транспорт Doctrine на PostgreSQL будит по `NOTIFY` только одну очередь, а остальные опрашивает раз в `check_delayed_interval` (по умолчанию 60 секунд). Сайту, которому нужно быстрее, достаточно отдельного воркера `messenger:consume knowledge_base` или меньшего значения этой опции.

**Смена модели.** Опция `model` входит в основу каждого документа. После смены модели поиск не находит ничего, пока `itk:knowledge-base:sync --now` не перестроит индекс, а строка «База знаний» в «Состоянии сайта» показывает, сколько документов ещё в старом пространстве. Векторы разных моделей никогда не сравниваются.

**Поиск из кода.** `KnowledgeSearch::search(string $query, SearchOptions $options = new SearchOptions()): SearchResult`. `SearchOptions`: `locale` (по умолчанию основной), `limit` (1–50, по умолчанию 8), `templates` и `collections` (`null` — все, `[]` — ни одного), `minScore` (порог косинусной близости). `SearchResult::$hits` — список `Hit` по убыванию `score`; `bySource()` оставляет по одному лучшему попаданию на страницу или запись. `Hit`: `score`, `text`, `heading`, `source` (`kind`, `id`, `locale`, `resourceKey`, `title`, `url`), `sectionId`, `sectionType`, `field`. Выключенная база или отказ эмбеддера дают `KnowledgeBaseUnavailable`, неизвестный шаблон или коллекция `\InvalidArgumentException`.

```php
$result = $knowledge->search($request->query->getString('q'), new SearchOptions(locale: 'ru', limit: 20));
foreach ($result->bySource() as $hit) {
    // $hit->source->title, $hit->source->url, $hit->text (фрагмент как сниппет)
}
```

**Команды.** `itk:knowledge-base:sync` ставит в очередь документы, которых нет, которые устарели или осиротели. `--now` делает это в процессе и завершается с кодом 1 при неудаче хотя бы одного источника или отказе API. `--dry-run` печатает счётчики и примерную стоимость в токенах, ничего не отправляя. `--source=page:<id>[:<locale>]` или `item:<id>[:<locale>]` ограничивает одним источником. `itk:knowledge-base:search "<запрос>" [--locale=ru] [--limit=8] [--template=x] [--collection=y]` печатает попадания таблицей.

**Неудачи.** Ограничение частоты (429), ошибка сервера и обрыв сети временны: сообщение повторяется через 10 секунд, затем через 30, 90, 270 и 810 (не раньше `Retry-After`, если API его назвал), после чего попадает в транспорт неудач. Неверный ключ, неизвестная модель и отсутствие `symfony/http-client` постоянны: сообщения сразу уходят в транспорт неудач, а после исправления их возвращает `messenger:failed:retry`. Строка «База знаний» в «Состоянии сайта» показывает выключенную базу, отсутствие клиента, ключа или расширения, документы другой модели, сообщения в транспорте неудач, застрявшую очередь и число проиндексированных версий. API эмбеддингов она не вызывает.

**Опции** `itk_cms.knowledge_base`: `enabled` (`false`); `model` (`text-embedding-3-small`; любая модель `text-embedding-3-*`, векторы хранятся в 1536 измерениях); `base_url` (`https://api.openai.com/v1`; совместимый API или прокси); `timeout` (секунды на один запрос, по умолчанию 30, не меньше 5); `exclude_templates` и `exclude_collections` (ключи, которые не индексируются никогда).

**Публичный API** здесь: опции `itk_cms.knowledge_base`, сервис `KnowledgeBase\KnowledgeSearch` и типы `KnowledgeBase\Model\{SearchOptions, SearchResult, Hit, Source, SourceKind, KnowledgeBaseUnavailable}`, граница эмбеддера `KnowledgeBase\Embedding\{Embedder, Embeddings, Vector, Purpose, Space, EmbeddingFailed}` (сайт может подменить `Embedder` своей реализацией с 1536 измерениями), класс сообщения `KnowledgeBase\Model\SyncKnowledge`, команды `itk:knowledge-base:sync` и `itk:knowledge-base:search`, имя транспорта `knowledge_base`. Остальное в `KnowledgeBase\` помечено `@internal`.

### Картинки
Оригиналы лежат в `{upload_url}/Y/m/name.jpg`, копии — `{upload_url}/_v/w{ширина}/Y/m/name.webp`. Копию создаёт маршрут `itk_image_variant` (`Controller\Site\ImageVariantController`) при первом обращении и кладёт файл рядом с публичным путём, поэтому дальше её отдаёт веб-сервер, а не PHP (ответ PHP: `Cache-Control: public, max-age=31536000, immutable`). Нужны расширение GD с WebP и, чтобы запрос на отсутствующий файл доходил до PHP, fallback веб-сервера на `index.php` (`try_files $uri /index.php$is_args$args`).
- Разрешены только ширины из `itk_cms.images.widths`, иной адрес даёт 404 (диск не забить). Оригинал ищется строго внутри `upload_dir`; `..` и чужие пути — 404.
- Увеличения нет: если оригинал уже заданной ширины, получается WebP размера оригинала (всё равно выигрыш по формату), а `media_url(image, w)` и `srcset` не выходят за ближайшую к оригиналу разрешённую ширину. Пропорции сохраняются, прозрачность PNG/WebP тоже, поворот EXIF у JPEG применяется.
- Преобразуются JPG, PNG, WebP (неанимированный) и AVIF. GIF, SVG, PDF, анимированный WebP, чужие адреса и оригиналы больше `itk_cms.images.max_pixels` (50 Мпикс, защита от «бомб») отдаются как есть (для последних двух запрос копии — временный редирект на оригинал, в лог пишется предупреждение).
- В тексте полей `article` и `description` теги `<img src="/uploads/…">` при выводе (и в предпросмотре) получают `src` копии 960, `srcset` до 1920, `sizes="(max-width: 800px) 100vw, 800px"`, `loading="lazy"`, `decoding="async"`; в базе остаётся исходный HTML.
- `MediaService::remove` удаляет и копии файла. В выдаче медиатеки админки у картинки есть `thumb` (копия 320).
- `bin/console itk:media:warm [--width=640 --width=1280]` заранее создаёт копии всех картинок медиатеки (после импорта или выкладки), с индикатором прогресса.

#### WebP и AVIF при загрузке
После загрузки картинки `MediaService` отправляет сообщение `Media\GenerateImageVariants` (шина `messenger.bus.default`, транспорт `async`, его берёт воркер `messenger:consume async`); обработчик создаёт WebP и, если GD умеет `imageavif`, AVIF для каждой ширины `srcset` картинки — до первого показа. Если очередь недоступна, загрузка всё равно проходит, а копии создаёт маршрут при первом запросе. AVIF лежит рядом: `{upload_url}/_v/w{ширина}/Y/m/name.avif`; `itk_cms.images.avif_quality` (по умолчанию 60) — его качество. `media_url` по-прежнему даёт WebP.

#### Кадры и точка фокуса
У файла медиатеки есть `focal` (`{x, y}`, доли 0..1) и `crops` (`{"16:9": {x, y, w, h}}`, доли картинки). Админка: «Медиатека → файл → Кадр и точка фокуса». Адрес кадра — `{upload_url}/_v/w{ширина}-r{A}x{B}/Y/m/name.webp` (и `.avif`), его даёт `media_url(image, 960, '16:9')`.
- Кадр берётся из `crops` для этой пропорции (рамка, нарисованная человеком, подрезается до точной пропорции вокруг своего центра); без него — самая большая рамка пропорции, поставленная вокруг `focal` (по умолчанию центр) и не выходящая за картинку. «Своя рамка» в редакторе сохраняется под ближайшей простой пропорцией (например `7:5`).
- Разрешены пропорции из `itk_cms.images.ratios` (по умолчанию `16:9, 4:3, 1:1, 3:4`; редактор предлагает их же) и те, для которых у файла сохранён кадр; запись должна быть несокращённой (`16x9`, не `32x18`), иначе 404. У ответа `max-age` сутки, а не год: кадр файла можно перерисовать. При смене точки фокуса или кадров старые копии-кадры файла удаляются.
- Кадр работает с JPG, PNG, WebP и AVIF; GIF, SVG и PDF отдаются как есть.

#### Папки
`MediaFolder` (`itk_media_folder`: `id, parent_id, name, position`), `MediaFile.folder_id`. Вложенность до 6 уровней, имя уникально среди соседей без учёта регистра. Папку с файлами или вложенными папками можно удалить только с переносом их в другую папку или наверх. API (право `media`): `GET|POST media-folders`, `PATCH|DELETE media-folders/{id}` (`?moveTo=<id>|root`), `GET media?folder=<id>|none`, `PATCH media/{id} {folderId}`, массово `POST media/move {ids, folderId}`; в `POST media` можно передать `folderId`.

### Заявки
`POST /request/{form}` (форма из `$s->form()`): поля формы, скрытое поле `itk_hp` (приманка для роботов) должно быть пустым. Ответ `{"success": true}` или `{"success": false, "message": "…", "errors": {…}}`. Ограничение 10 заявок / 15 минут с адреса (`framework.rate_limiter.itk_request_form`, переопределяется в сайте). Дальше `RequestService` → уведомление `request.new` (раздел «Уведомления») → Messenger → Telegram. Если Telegram не подключён (необязателен, шаг 6 установки), заявка остаётся в админке, а её доставка — `pending` (не сбой). Если подключённый Telegram не принял сообщение, доставка помечается `failed`; повтор: админка или `bin/console itk:requests:resend`.

### SEO для любого адреса
Правило адреса (`Настройки → SEO → Правила адресов`, область `set:seo`, таблица `itk_seo_rule`) задаёт заголовок, описание, индексацию (`robots`), канонический адрес и картинку для соцсетей для любого адреса сайта: для страницы CMS и для маршрута самого сайта, где страницы CMS нет. Правило важнее SEO самой страницы, но меняет только заполненные поля.
- Адрес правила — путь с необязательной строкой запроса (`/catalog`, `/catalog?page=2`; правило с параметрами подходит, если те же параметры есть в запросе, лишние игнорируются). `match`: `exact` — только этот адрес, `prefix` — адрес и всё внутри по целым сегментам (`/blog` покрывает `/blog/a`, но не `/blogger`; для `prefix` строка запроса не нужна). `locale` пустой — любой язык. Из подходящих выигрывает самое точное: больше параметров, `exact` перед `prefix`, длиннее путь, правило своего языка, более новое.
- `SeoResolver` — `matches()`, `best()`, `forRequest()`, `apply()`, `meta()`. `PageRenderer` кладёт правило в `page.seo` (там появляются `robots`, `canonical`, `ogImage`, `rule`), `itk_seo()` собирает итоговые теги для текущего запроса и работает в любом шаблоне; на странице ошибки правила не применяются. Порядок значения: правило, затем SEO страницы, затем название страницы (только `title`), затем аргумент `itk_seo()`. Относительные `canonical` и `ogImage` становятся полными адресами. Макет сайта печатает теги из `itk_seo()`:
```twig
{% set seo = itk_seo({title: 'My site', robots: 'index,follow'}) %}
<title>{{ seo.title }}</title>
{% if seo.canonical != '' %}<link rel="canonical" href="{{ seo.canonical }}">{% endif %}
```
- API (`/admin/api`): `GET seo-rules?q=&locale=`, `POST seo-rules`, `PATCH|DELETE seo-rules/{id}` (права `view|edit|delete` области `set:seo`), `GET seo-rules/check?path=&locale=` — «проверить адрес»: что отвечает адрес (маршрут сайта, перенаправление, страница CMS, 404), какие правила подходят и откуда берётся каждый тег (`rule|page|title|none`).

### Адреса и перенаправления: `Support\Url`
- `Url::safeLocalPath($path)` — путь, который можно безопасно положить в заголовок `Location` или `href`: ровно один `/` в начале, `\` читается как `/` (браузер делает так же, поэтому `/\evil.example` превращается в `//evil.example`, то есть в чужой сайт), табы и переводы строк убираются (браузер их тоже убирает), `//host` и `\host` становятся `/host`. Строка запроса и `#якорь` остаются как были. **Любой перенаправляющий код сайта, в который попадает кусок адреса из запроса, пропускает его через `Url::safeLocalPath()`:**
  ```php
  return new RedirectResponse(Url::safeLocalPath($request->getPathInfo()) . '?' . $query, 301);
  ```
  Перенаправление «лишний слэш в конце» в ядре (`PageController`) использует `Url::fromPath()`, который строится на том же правиле: `/\evil.example/` и `//evil.example/` ведут на `/evil.example` этого же сайта.
- `Url::isLocalPath($v)`, `Url::cleanPageRef($v)`, `Url::cleanLinkUrl($v)`, `Url::safeHref($v)` — проверки ссылок (см. «Twig», `page_url`).

### sitemap.xml
Ядро отдаёт `GET /sitemap.xml`, собранный из опубликованных страниц (`Content\SitemapBuilder`); писать его в сайте не нужно.
- В карту входят все опубликованные страницы всех языков (сначала основной), по одному `<url>` с `<loc>` и `<lastmod>` (дата публикации). Страницы, у которых индексация (`robots`) закрыта — в SEO страницы или правилом адреса — не попадают.
- Адреса абсолютные и строятся из настройки «Адрес сайта» (`domain`, «Настройки → Сайт»; без схемы — берётся `https://`), **а не из заголовка `Host` запроса**. Пока адрес сайта не задан, `/sitemap.xml` отвечает 404.
- Карта лежит в кеше (`cache.app`) час и сбрасывается, когда страница публикуется, снимается с публикации, меняется или удаляется, а также когда меняется правило адреса.
- Если у сайта есть свой `public/sitemap.xml`, он остаётся главным: веб-сервер отдаёт файл раньше PHP, а если запрос всё же дошёл до маршрута, ядро отдаёт этот же файл.
- Веб-серверу достаточно передавать `/sitemap.xml` в `index.php`, как любой другой адрес. Строку `Sitemap: …` стандартный `robots.txt` ядра добавляет сам (ниже).

### robots.txt и llms.txt
Ядро отдаёт `GET /robots.txt` (правила для поисковых роботов) и `GET /llms.txt` (краткое описание сайта для ИИ-сервисов в формате [llmstxt.org](https://llmstxt.org)), оба `text/plain; charset=UTF-8`. Текст правится в админке: «Настройки → SEO → robots.txt и llms.txt» (право `set:seo`), правки попадают в историю.
- Что отдаётся, по порядку: файл `public/robots.txt` (`public/llms.txt`) сайта, если он есть (веб-сервер отдаёт его раньше PHP, админка предупреждает, что правка там не действует); свой текст из админки; стандартный текст.
- Стандартный `robots.txt` открывает сайт и закрывает служебные адреса ядра: `Disallow:` для адреса админки (с учётом `admin_path` и `prefix`), `/request/` и `/mcp`; строка `Sitemap: https://домен/sitemap.xml` — только когда задан «Адрес сайта» (`domain`).
- Стандартный `llms.txt` собирается из опубликованных страниц основного языка по тем же правилам, что `sitemap.xml` (страницы с закрытой индексацией не попадают): `# Название сайта` (настройка `siteName`, иначе домен), `> SEO-описание главной`, затем `## Pages` и по строке `- [Заголовок](адрес): описание` на страницу. Без «Адреса сайта» адреса относительные. Текст кешируется и сбрасывается вместе с картой сайта.
- Свой текст до 64 КБ. Пустое поле, «Вернуть стандартный» и текст, совпадающий со стандартным, возвращают стандартный: он и дальше будет следовать за страницами, доменом и адресом админки.
- Адреса `/robots.txt` и `/llms.txt` заняты ядром: страницу с таким адресом создать нельзя.
- API админки: `GET {admin}/api/seo-files` → `{robots: {text, default, custom, file}, llms: {…}}`, `PUT {admin}/api/seo-files` `{robots?, llms?}` (`null` или `''` — стандартный текст). Общие настройки (`/settings`) эти ключи не отдают и не принимают.

### Миграции сайта
Миграции бандла (`Itk\Cms\Migrations`) и сайта (`<ваше пространство>`: `App\Migrations` в `src/Migrations/` у нового проекта, `DoctrineMigrations` в `migrations/` у старых сайтов, см. `config/packages/doctrine_migrations.yaml` сайта) выполняются **по дате в имени класса** (`Version20261030120000` = 2026-10-30 12:00:00); при равной дате первым идёт пространство имён по алфавиту. Бандл подключает для этого `Itk\Cms\Doctrine\MigrationVersionComparator` в `doctrine_migrations.services` каждого сайта. Без него Doctrine сортирует по полному имени класса и все миграции сайта идут раньше всех миграций бандла: миграция сайта, которая читает таблицу бандла (`itk_page_translation`, `itk_setting`), на пустой базе падала с «Table … doesn't exist».
- **Путь сайта стоит первым.** `doctrine:migrations:diff` и `:generate` без `--namespace` пишут в первый из путей `migrations_paths`. Бандл ставит свой путь (`vendor/itk/cms/src/Migrations`) после путей сайта, в том порядке, в котором сайт их записал, так что новая миграция попадает в каталог миграций сайта (`src/Migrations/` или `migrations/`, смотря какой путь записан в его `doctrine_migrations.yaml`), а не в `vendor`. Сайт с несколькими путями выбирает нужный через `--namespace`. Миграции бандла при этом выполняются как раньше. Свой путь для `Itk\Cms\Migrations` сайт задавать не должен (если задал, его значение главнее).
- Миграцию сайта с данными бандла называйте по текущей дате (`doctrine:migrations:diff --namespace=<ваше пространство>` делает это сам): она окажется позже миграций бандла, которые были до неё. Уже выполненные миграции переименовывать не нужно: порядок меняет только то, какая из ещё не выполненных пойдёт раньше.
- Свой компаратор сайта (`doctrine_migrations.services: { 'Doctrine\Migrations\Version\Comparator': … }` в `config/packages/doctrine_migrations.yaml`) важнее: сайт берёт на себя порядок.
- Миграция, которая трогает таблицы бандла, не должна падать на пустой базе, если таблицы ещё нет. Проверяйте таблицу прямым запросом:
  ```php
  $this->skipIf(0 === (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'itk_setting'"), '…');
  ```
  `$schema->hasTable('itk_setting')` здесь не подходит: при `schema_filter` из следующего пункта он всегда даёт `false`.
- **`schema_filter`: `diff` сайта не должен трогать `itk_*`.** Сущности бандла видны ORM сайта, поэтому `doctrine:migrations:diff` сравнивает с базой и таблицы `itk_*`: любая разница между версией бандла и базой попала бы в миграцию сайта, и она бы вмешалась в таблицы бандла (их меняют только миграции бандла). Закройте их фильтром в `config/packages/doctrine.yaml`:
  ```yaml
  doctrine:
      dbal:
          schema_filter: '~^(?!itk_)~'     # таблицы itk_* ведут миграции бандла; остальные таблицы сайта фильтр не трогает
  ```
  Если у сайта есть свои таблицы с префиксом `itk_`, уточните выражение (например, исключайте имена по списку). Фильтр защищает только `doctrine:migrations:diff`. `doctrine:schema:update` и `doctrine:schema:validate` таблицы `itk_*` всё равно видят: таблицы из маппинга они сравнивают и при фильтре, поэтому `schema:update --dump-sql` предложит убрать лишнюю колонку из `itk_setting`. Схему меняют только миграции.

  **Фильтр прячет таблицы `itk_*` и от миграций сайта.** `$schema->hasTable('itk_…')` и `createSchemaManager()->tablesExist(…)` при нём всегда дают `false`, хотя таблица есть: миграция с такой проверкой молча пропускается, `migrate` пишет `[OK]`, а данных нет. Сначала замените такие проверки на запрос к `information_schema` (см. пункт выше). Если менять миграции нельзя, оставьте `schema_filter` пустым и фильтруйте только при создании миграции: `doctrine:migrations:diff --filter-expression='~^(?!itk_)~'`.

### Команды
`itk:install`, `itk:requests:resend`, `itk:media:warm`, `itk:critical-css:generate`, `itk:user:password`, `itk:user:reset-2fa`, `itk:history:prune`, `itk:notifications:prune`, `itk:cache:clear`, `itk:mcp` (раздел «MCP»), `itk:schema:lint`, `itk:doctor` и генераторы кода сайта `itk:make:template|section|collection|field|settings|plugin` (раздел «Генераторы»).

- **`itk:cache:clear`** сбрасывает кэш страниц (раздел «Кэш страниц»); сбрасывает только его пул.
- **`itk:critical-css:generate`** ставит в очередь `critical_css` живые языковые версии страниц, у которых критического CSS нет, он устарел или не получился (раздел «Критический CSS»). `--all` берёт и актуальные; `--page=<id>` и `--locale=<xx>` сужают выбор; `--dry-run` только перечисляет; `--now` делает всё в этом процессе, по пять страниц на запуск Node, печатает каждую неудачу и выходит с кодом 1, если хоть одна страница не получилась. Можно запускать сколько угодно раз.
- **`itk:schema:lint`** проверяет схему и Twig-шаблоны секций и страниц (раздел «Проверка схемы»); код выхода 1 при ошибке.
- **`itk:doctor [--ci]`** — проверка сайта за один проход, для деплоя и CI. Группы: «Окружение» (расширения PHP, WebP, AVIF, `APP_SECRET`, права на `upload_dir`, база), «Сайт» (`public_url`/доверенный адрес, применены ли миграции, опубликована ли главная, файрвол админки и файрвол `/mcp` в `security.yaml` с их ключами, очередь Messenger и воркер: сообщения, которые должны были уйти больше 5 минут назад, но лежат в таблице очереди, значат, что `messenger:consume` не запущен (отложенное сообщение, срок которого ещё не пришёл, не считается), манифест собранной админки и все его файлы), «Состояние сайта» (то, что говорит `HealthService::report()`: проверки, диски, упавшие задачи, недоставленные заявки), «Схема» (`itk:schema:lint`). Каждая строка — `ok`, `внимание` или `ОШИБКА`. Без `--ci` код выхода всегда 0; с `--ci` он 1, если есть хотя бы одна ошибка (предупреждения его не меняют). Схема с ошибками не мешает остальным проверкам: она отмечается своей строкой.

## Поля

Поле — объект из `Itk\Cms\Field\` с цепочкой методов. Подпись первой, ключ второй (как в MoonShine): `Text::make('Title', 'title')`. Ключ обязателен у любого поля с данными (без него — `InvalidArgumentException`), опустить его можно только у `Note`.

| Класс | Что это | Значение |
|---|---|---|
| `Text`, `Textarea` | строка, многострочный текст | строка |
| `Description`, `Headline`, `Article` | абзацы с оформлением, заголовок, длинный текст статьи (`Article` ещё: подзаголовки, цитата, линия, фото и простая таблица `<table>` с `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`: в ячейке только текст, ссылки, `<strong>`, `<em>`, `<br>`; атрибутов нет, кроме числовых `colspan` и `rowspan`; вне статьи таблица теряется, остаётся текст) | безопасный HTML |
| `Number`, `Toggle` | число, переключатель | число, `bool` |
| `Select` | один вариант из списка: `->options(['open' => 'Open', …])` | строка |
| `Date`, `DateTime` | дата `2026-11-30`, дата и время `2026-11-30T18:00` | строка |
| `Image`, `Photos`, `Link` | картинка, список фото, ссылка | `{src, alt}`, список фото: элемент — адрес строкой (так добавляет админка) или `{src, alt}` (так может прислать MCP), форма элемента при сохранении не меняется, в шаблоне `{{ p.src ?? p }}`, `{kind, page, url}` |
| `Repeater` | список элементов: `->fields(Text::make('Name', 'name'), …)`; заголовок строки `->itemTitle()`, `->itemSummary()`, кнопка `->duplicable()` (ниже) | список с `_id` у каждого |
| `Relation` | связь с записью другой коллекции: `->to('destinations')`, `->multiple()` | id записи (список id) |
| `Note` | подсказка редактору, не данные: `->text('…')`, `->linkTo()` | — |

Общие методы (все возвращают поле, их можно ставить в любом порядке):

| Метод | Что делает |
|---|---|
| `required()`, `max(int)`, `rows(int)`, `pattern($regex, $msg)` | обязательность, длина, высота, проверка формы сайта |
| `hint(string)` | строка пояснения под полем (`help()` — прежнее имя, то же самое) |
| `default($v)` | значение для новой записи (`initial()` — прежнее имя) |
| `translatable()` | настройка хранится отдельно для каждого языка |
| `readonly()` | редактор видит значение, но менять не может: сервер игнорирует его значение (код и хуки писать могут). Действует в записях коллекций, полях шаблонов страниц, полях секций (верхнего уровня; внутри повторителя — нет) и настройках |
| `canSee('set:users' \| 'col:tours:edit' \| fn (Viewer $v): bool)` | кому видно поле: область прав, право `область:действие` или замыкание. Остальным сервер не отдаёт поле в схеме, в записях, в истории и не принимает его значение. Действует в тех же местах, что `readonly()`; в настройках сверх того значения группы, на которую у человека нет права `set:<группа>` «Смотреть», тоже не отдаются. Сайт читает настройки через `Settings` и Twig `settings()` без этих ограничений |
| `sortable()` | колонку списка можно сортировать |
| `searchable()` | строка поиска ищет и в этом поле |
| `filterable(?FilterKind)` | поле становится фильтром списка (см. «Фильтры») |
| `column()` / `showOnIndex()`, `hideOnIndex()` | показывать / не показывать колонкой списка (по умолчанию решает админка) |
| `hideOnForm()`, `hideOnDetail()`, `width(int)` | скрыть в форме, скрыть в просмотре, ширина колонки в пикселях |
| `half()` | поле занимает половину строки формы: два соседа с `half()` стоят рядом (годы «с» и «по»), на телефоне друг под другом. Одиночное поле `half()` и третье подряд остаются на всю ширину. Работает и внутри элемента `Repeater` |
| `section('Заголовок')` | заголовок раздела формы перед этим полем; поле начинает новую строку, раздел длится до следующего `section()`. Пояснение под заголовком: `Note` следом. Работает в формах страницы, секции, записи, настроек и в элементах `Repeater` |

**Строка списка (`Repeater`).** Три метода, которые включают явно; без них список выглядит и работает как раньше.

```php
Repeater::make('Варианты товара', 'variants')
    ->noun('вариант')
    ->itemTitle('{bike} · {yearFrom}–{yearTo}', '{title}')  // заголовок свёрнутой строки
    ->itemSummary('{sku} · {price}')                         // строка под заголовком
    ->duplicable()                                           // кнопка «Дублировать» в строке
    ->fields(
        Relation::make('Мотоцикл', 'bike')->to('bikes')->section('Подбор по мотоциклу'),
        Number::make('Год с', 'yearFrom')->half(),
        Number::make('Год по', 'yearTo')->half(),
        Text::make('Название', 'title'),
        Photos::make('Фото варианта', 'photos'),
    );
```

- `itemTitle(string $template, string ...$fallbacks)` и `itemSummary(...)`: `{ключ}` — значение подполя с этим ключом, как его читает человек: `Relation` — названием записи, `Select` — названием варианта, `Textarea` и `Headline` — простым текстом, `Toggle` — подписью поля (если включён), `Image` — описанием картинки; `Number` с `->grouped()` — с разделителями разрядов по языку админки («110 000», в английской «110,000»); `Link`, `Photos` и вложенный `Repeater` дают пустое значение. Всё остальное в шаблоне — обычный текст.
- Пустое значение уносит с собой соседний текст, поэтому заголовок не бывает вида « · –»: текст *между* двумя значениями печатается только между двумя значениями, которые есть (если пусто несколько подряд, берётся текст, стоявший перед первым пустым); текст *до первого* и *после последнего* значения остаётся, только если рядом с ним значение есть (`'№ {sku}'`, `'{price} ₽'`). Шаблон без `{ключа}` — постоянный текст. Скобки вокруг необязательного значения не поддерживаются: пишите `'{title} · {sku}'`, а не `'{title} ({sku})'`. `0` — значение, пустая строка и пробелы — нет.
- `$fallbacks` — запасные шаблоны: пробуются по порядку, побеждает первый, который что-то дал. `itemTitle('{bike} · {yearFrom}–{yearTo}', '{title}')`: у варианта с мотоциклом это «Beta RR 2T · 2020–2022», у варианта без мотоцикла — его название. Дал что-то первый шаблон — запасной не смотрится (поставьте `'{title}'` первым, если название важнее).
- Всё пусто — прежний заголовок («Вариант 1» при `noun('вариант')`); у `itemSummary` — пустая строка. Без `itemSummary` админка показывает начало первого другого текстового поля, как раньше.
- В истории и списке «Что будет опубликовано» строка называется так же. Историю пишет сервер без запросов к другим коллекциям, поэтому там `Relation` в шаблоне даёт пустое значение: у варианта с мотоциклом без `title` и годов заголовок в истории будет «Вариант 3», а не название мотоцикла.
- `duplicable()` добавляет в строку кнопку «Дублировать»: копия встаёт сразу под исходной, раскрыта, у неё новые `_id` (у вложенных элементов тоже); на «отменить» в уведомлении копия исчезает.
- Если задан `itemTitle()` или `itemSummary()`, длинный заголовок строки переносится, а не обрезается многоточием, на телефоне кнопки строки уходят на отдельную строку (так же при `duplicable()`), а миниатюра берётся из первого поля `Image`, если оно пусто или его нет — из первого фото первого поля `Photos`; строка без фото сохраняет пустую рамку. Без этих методов строка выглядит как раньше: одна строка заголовка и миниатюра только из `Image`.
- В списках изменений поле `Photos` описывается числом фото («2 фото»).

Одно описание работает в форме, колонке, фильтре и просмотре: `toArray()` отдаёт админке прежнюю форму `{k, t, label, max?, req?, help?, …}` и добавляет `list: {show, sortable, width}`, `filter: {kind, options} | null`, `detail: bool` (и `ro`, `form: false`, `search` — когда включены). `Date` и `DateTime` по-прежнему `t: 'text'` с `inputType`, `Relation` — новый `t: 'relation'`.

`Itk\Cms\Schema\Field` остаётся короткой записью для `config/itk/schema.php` (ключ первым): `Field::text('title', 'Title')` — то же самое, что `Text::make('Title', 'title')`, и возвращает те же объекты. Старые файлы схемы работают без правок. Свой тип поля — наследник `Itk\Cms\Field\AbstractField` (`wireType()` — значение `t`, которое понимает админка; компонент для админки приносит расширение, см. «Расширения»).

## Разделы-классы

Второй способ описать сайт (рядом с `config/itk/schema.php`, оба попадают в один `Schema`): один PHP-класс на раздел. Сайт кладёт классы в `src/Itk/{Collection,Template,Section,Settings}/`; они подхватываются сами (интерфейс `Itk\Cms\Resource\ResourceInterface` помечен `#[AutoconfigureTag('itk.resource')]`, нужно, чтобы `services.yaml` сайта загружал `src/`). Конструктор можно оставить пустым или внедрить сервисы.

```php
namespace App\Itk\Collection;

use Itk\Cms\Attribute\{Group, Icon, Order};
use Itk\Cms\Field\{Date, Number, Select, Text};
use Itk\Cms\Resource\{Action, ActionContext, ActionResult, CollectionResource, Metric};

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
| `CollectionResource` | `key()`, `title()`, `fields()` | `singular()`, `newLabel()`, `icon()`, `description()`, `emptyState()` (`['title' => …, 'text' => …]`), `views()`, `titleField()`, `thumbField()`, `subField()`, `toggleField()`, `filters()`, `actions()`, `metrics()`, `tree()`, `sortable()`, `defaultSort()`, `perPage()`, `canSee(Viewer)` и хуки |
| `TemplateResource` | `key()`, `title()`, `fields()` | `about()`, `parents()`, `topLevel()`, `single()`, `root()`, `leaf()`, `sections()`, `listing()`, `h1()`, `shortcut()`, `card()`, `areas()`, `configure(Template $t)` и хуки |
| `SectionResource` | `key()`, `title()`, `fields()` | `group()`, `template()` (путь Twig, по умолчанию `sections/<key>.html.twig`), `short()`, `tone()`, `silhouette()`, `about()`, `h1()` |
| `SettingsResource` | `key()`, `title()`, `fields()` | `description()` |

Ключ шаблона ядра (`blog_article`) меняет этот шаблон, как повторный `$s->template('blog_article')`; если у класса нет полей, поля ядра остаются. Любой другой ключ, описанный дважды (в `schema.php` и в классе или в двух классах), — ошибка: `The collection "tours" is described twice: in …/schema.php and in App\Itk\Collection\ToursCollection. Keep one description.` Параметры коллекции `views`, `filters`, `actions`, `metrics`, `tree`, `defaultSort`, `perPage` есть и у `$s->collection()` в `schema.php` (`->views([...])`, `->actions(...)`, …). Не внедряйте в ресурс `Schema`, `PageService` и `CollectionService`: схема строится из ресурсов, получится кольцо; для работы с данными в хуках берите события и `Context`.

`views()` — виды списка, `tree()` — вложенность записей: подробно в разделах «Виды списка» и «Дерево с перетаскиванием» ниже.

## Хуки и события

Каждое действие с записью коллекции или страницей (по её шаблону) проходит так: **событие Symfony → метод класса-ресурса** (класс видит данные уже после слушателей). Страницы: `resourceKey` — ключ шаблона, `kind` — `page`; записи: ключ коллекции, `kind` — `item`.

| Момент | Событие (`Itk\Cms\Event\`) | Метод класса |
|---|---|---|
| первое чтение раздела за запрос | — | `onLoad(Context)` |
| создание | `RecordCreating` → `RecordCreated` | `beforeCreating(array $data, Context): array` → `afterCreated(Record, Context)` |
| изменение (автосохранение редактора тоже) | `RecordUpdating` → `RecordUpdated` | `beforeUpdating(Record, array $data, Context): array` → `afterUpdated(Record, Context)` |
| удаление | `RecordDeleting` → `RecordDeleted` | `beforeDeleting(Record, Context)` → `afterDeleted(Record, Context)` |
| публикация | `RecordPublishing` → `RecordPublished` | `beforePublishing(Record, Context)` → `afterPublished(Record, Context)` |
| снятие с публикации | `RecordUnpublishing` → `RecordUnpublished` | `beforeUnpublishing(Record, Context)` → `afterUnpublished(Record, Context)` |
| несколько записей сразу | `MassAction` (`action`: `delete`/`publish`/`unpublish`, `phase`: `before`/`after`) | `beforeMassDeleting` / `afterMassDeleted`, `beforeMassPublishing` / `afterMassPublished`, `beforeMassUnpublishing` / `afterMassUnpublished` (`array $records, Context`) |

События: `resourceKey`, `kind`, `record` (`null` у `RecordCreating`), `data` (у `RecordCreating`/`RecordUpdating` его можно менять — это и сохранится), `viewer`. `Record` — `{id, kind, resourceKey, locale, fields, status, meta}` (`get($key)`; у страницы `fields` — поля шаблона, `meta` — `title`, `url`, `parentId`, `template`). `Context` — `viewer`, `locale`, `kind`, `resourceKey`. `Viewer` — кто действует: человек с правами (`can('col:tours', 'publish')`, `allows('set:users')`, `user()`, `role()`) или система (консоль, импорт, очередь: `isSystem()`, разрешено всё).

- Всё `before…` (событие и метод) может бросить `Itk\Cms\Content\ContentException` — изменение отменяется, редактор видит текст. Всё `after…` выполняется после сохранения: исключение уже ничего не откатит.
- Массовая операция: сначала `before` для всех записей один раз (отказ останавливает всё), затем каждая запись проходит обычный путь со своими `before…` и записью в базу, всё в одной транзакции. `after…` каждой записи (события и методы классов) ждут конца транзакции: если откатилась хоть одна запись, ни один из них не вызывается; если откатов не было, они идут по порядку записей, а за ними общий `afterMass…`. Ошибка в `after…` уже ничего не откатит.
- Поля `readonly()` и закрытые `canSee()` ядро выбрасывает из данных человека до событий; значения, которые добавил хук или код, сохраняются.
- Данные страницы в `beforeCreating` / `beforeUpdating` не плоские, как у записи коллекции: это то, что прислал редактор, `{title, url, seo, parentId, template, sections, fields: {…}}` (при изменении — только те ключи, что меняются), а поля шаблона лежат внутри `fields`. У `Record` страницы `fields` — поля шаблона, `meta` — `title`, `url`, `parentId`, `template`. Ключ верхнего уровня, которого нет в этом списке (например `slug`), игнорируется.
- Перенос страницы или записи по дереву проходит через `beforeUpdating` с данными `{parentId}`: хук может его отменить исключением, но не изменить (возвращённые данные при переносе не применяются). «Отменить правки» (`revert`) хуков изменения не вызывает.
- Код ядра: `PageService` (`create`, `saveDraft`/`update`, `remove`, `publish`, `massRemove`, `massPublish`) и `CollectionService` (то же + `unpublish`, `massUnpublish`).

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

## Действия

Действие — кнопка над записями коллекции. Класс описывает их в `actions()`, `schema.php` — `->actions(...)`:

```php
Action::make('Duplicate', 'duplicate')->row()->bulk()->icon('copy')
    ->modal('How many copies?', Number::make('Copies', 'times')->required())
    ->handle(static function (ActionContext $c): ActionResult {
        // $c->records() — выбранные Record, $c->input() — значения окна, $c->viewer()
        return ActionResult::message('Done')->reload();
    });
```

`row()` (меню строки), `bulk()` (для отмеченных), `top()` (над списком), `form()` (на странице записи) — можно несколько, без них `row()`; `icon()`, `danger()`, `confirm('Question text')`, `modal($title, ...$fields)` (значения проверяются по полям, обязательные должны быть заполнены), `canSee(string|fn (Viewer $v))`, `requires('publish')` (право на коллекцию, нужное для запуска; по умолчанию «Править»), `permission()` (отдельная строка `action:<коллекция>:<действие>` в матрице ролей, по умолчанию её есть только у администратора), `handle(callable)`. Результат: `ActionResult::message('…')->reload()`, `::redirect('/pages/42')` (адрес админки или http(s)), `::download('/path/to/file.csv', 'name.csv', delete: true)`, `::download(StreamedResponse)`, `::error('…')`.

Встроенные действия каждой коллекции (кроме «одна запись» и матрицы): «Опубликовать выбранные» (`publish-selected`), «Снять с публикации» (`unpublish-selected`), «Удалить выбранные» (`delete-selected`) — через массовые хуки; собственное действие с таким ключом их заменяет.

API: `POST /admin/api/resources/{коллекция}/actions/{действие}` с телом `{ids?: [], input?: {}, locale?}` → `{ok, message?, reload?, redirect?, download?}`. Сервер проверяет, что действие видно человеку (`canSee`), что у него есть право `col:<коллекция>` (то, что назвал `requires()`) и строка матрицы (если `permission()`). Нужны `ids` (до 500), если у действия нет места `top()`. Ошибка действия — `422 {ok: false, error, message}`. Файл, который сделало действие, отдаётся один раз и только тому, кто его запустил: `download` — адрес `GET /admin/api/downloads/{token}` (5 минут); `StreamedResponse` уходит как сам ответ. Действия каждой коллекции и их места приходят в схеме (`collections[].actions`, только доступные человеку).

## Фильтры

Фильтр — это поле с `filterable()`; вид выбирается по полю или задаётся: `FilterKind::Text` (LIKE без учёта регистра), `Select`, `Multi` (несколько значений), `Toggle`, `DateRange`, `NumberRange`, `Relation`. `filters()` ресурса, если вернуть список полей, заменяет «поля с `filterable()`». Всем коллекциям доступен фильтр `status` (`draft`/`published`).

```
GET /admin/api/collections/tours?q=altai&filter[state]=open&filter[start][from]=2026-11-01&filter[seats][from]=5
                                &filter[level]=mid,hard&sort=-start,title&page=2&perPage=50&locale=ru
→ { items, total, page, perPage, facets: { state: { open: 4, full: 3 } }, metrics: [{ label, kind, value }] }
```

- Ответ-конверт включается, как только в запросе есть `page`, `perPage`, `sort`, `filter` или `envelope=1`. Запрос без них отвечает как раньше — простым списком строк (`status`, `q`, `onlyTranslated` работают как прежде), чтобы не ломать старый клиент.
- Фильтр и сортировка работают в базе по JSON: `JSON_UNQUOTE(JSON_EXTRACT(i.translations, '$."ru".draft."seats"'))`. Бандл регистрирует DQL-функции `JSON_EXTRACT`, `JSON_UNQUOTE` и `JSON_NUMBER` (число из JSON; по нему сортируются и сравниваются `Number`). Путь собирается только из проверенных слов `[A-Za-z0-9_-]`, значения всегда параметры.
- `sort` — ключи через запятую, `-` = по убыванию. Можно сортировать по полям с `sortable()` и по `status`, `updatedAt`, `createdAt`, `position`; без `sort` берётся `defaultSort()`, иначе ручной порядок. Поиск `q` ищет по полям с `searchable()` (если их нет — по заголовку и второй строке). Чужое поле, закрытое `canSee()`, нельзя ни фильтровать, ни сортировать (`422`).
- `perPage` по умолчанию `perPage()` ресурса (50), не больше 200. `facets` — число записей на значение для фильтров `Select`/`Multi`/`Toggle` и `status` по всей коллекции.
- Метрики (`Metric::count('Total')`, `Metric::count('Published', filter: ['status' => 'published'])`, `Metric::sum('Seats', 'seats')`) считаются по всей коллекции, а не по текущему фильтру. Значение фильтра метрики — как у фильтра списка: значение, список значений или диапазон `['dateStart' => ['from' => '2026-11-01']]` (`from`, `to`; для дат и чисел).
- Состояние фильтров и вида запоминается на сервере для человека (работает между устройствами): `GET|PUT|DELETE /admin/api/prefs/{ключ}` с телом `{value}` (таблица `itk_user_pref`). Ключ — буквы, цифры и `. _ : -` (до 100 знаков), значение — любой JSON до 16 КБ, чужие настройки прочитать нельзя. PostgreSQL хранит ключи JSON-объекта в своём порядке (`jsonb`).

## Виды списка

`views()` ресурса (или `->views([...])` у `$s->collection()`) перечисляет виды, между которыми человек переключается над списком; выбранный вид запоминается для него на сервере вместе с фильтрами (`itk_user_pref`, ключ `list.<коллекция>`). Первый в списке — вид по умолчанию.

```php
public function views(): array
{
    return [
        'table',                                                               // таблица
        'cards',                                                               // карточки
        'calendar' => ['start' => 'dateStart', 'end' => 'dateEnd', 'title' => 'city'],
        'kanban'   => ['field' => 'state', 'columns' => ['open' => 'Open', 'full' => 'Sold out']],
    ];
}
```

- **Календарь.** `start` — ключ поля-даты (обязателен), `end` — необязательный (тогда запись тянется по дням от начала до конца), `title` — поле для подписи (по умолчанию — заголовок записи). Поля могут быть `Date` и `DateTime`. Админка показывает месяц или неделю; клик по дню создаёт запись с этой датой (и с датой окончания того же дня), перетаскивание записи на другой день и Alt + стрелки сдвигают обе даты на то же число дней. Записи без даты начала лежат под сеткой.
- **Доска.** `field` — ключ поля `Select`; колонки — его значения (или `columns`: `значение => подпись`). Перетаскивание карточки в другую колонку (и кнопки «←» «→» на карточке) меняет значение поля. Записи, значение которых не названо в колонках, собираются в колонке «Без значения».
- Сдвиг дат и перенос по доске — обычный `update` записи (`PUT collections/{col}/{id}` с одним-двумя полями): работают права на поля, `beforeUpdating`/`afterUpdated`, события `RecordUpdating`/`RecordUpdated`, история. Хук может отменить перенос исключением `ContentException` — админка вернёт карточку на место и покажет текст.
- Календарь, доска и дерево показывают все записи сразу (без страниц; админка читает список порциями по 200, до 2000), поиск и фильтры списка работают и в них.
- Схема проверяется при сборке: календарь без `start`, поле, которого нет в коллекции, доска не по `Select` — `InvalidArgumentException` с понятным текстом, а не пустой экран. Админка не знает видов, кроме `table`, `cards`, `calendar`, `kanban` (и автоматического `tree`).
- Экран «Заявки» (не коллекция) имеет свой вид «Доска»: колонки «Новая / В работе / Закрыта» по полю `work`, перенос меняет статус так же, как в карточке заявки; вид запоминается (`requests.view`).

## Дерево с перетаскиванием

Записи коллекции с `tree()` (`public function tree(): bool { return true; }`, в `schema.php` — `->tree()`) вкладываются друг в друга: у `itk_collection_item` есть `parent_id` (миграция бандла `Version20261030130000`), общий для всех языков. В списке появляется вид «Структура» (первый, по умолчанию): `SortableTree` — перетаскивание за значок слева, клавиатурная альтернатива (Alt + ↑ ↓ — выше/ниже, Alt + → — вложить в соседа выше, Alt + ← — вынести), кнопка «+» добавляет вложенную запись.

```
PUT /admin/api/collections/{col}/tree      [{ "id": "…", "parentId": "…"|null, "position": 0 }, …]   → { ok, moved }
PUT /admin/api/pages/tree                  то же для страниц сайта
```

- Узел — `{id, parentId, position}`: место среди записей того же родителя (с нуля). Названные узлы переезжают, остальные остаются; «братья» каждого затронутого родителя нумеруются заново (названный узел при равенстве идёт раньше неназванного). Админка присылает всех «братьев» старого и нового родителя, поэтому порядок точный.
- Запрещено: вложить узел в себя или в свою ветку (`422`), назвать узел дважды, неизвестный узел или родитель. Всё в одной транзакции, при ошибке ничего не меняется.
- **Записи.** Узел, у которого сменился родитель, проходит как обновление: `beforeUpdating($record, ['parentId' => …], $ctx)` (исключение отменяет перенос), события `RecordUpdating`/`RecordUpdated`, затем `afterUpdated`. Запись с `parentId` создаётся обычным `POST collections/{col}` (`{"title": "…", "parentId": "…"}`); запись приходит в списке (`parentId` в строке) и в `GET collections/{col}/{id}` (`item.parentId`). Удаление записи поднимает её вложенные записи на её место. Нужно право «Править» на `col:<ключ>`.
- **Страницы.** Те же правила размещения, что у «Настройки страницы» (`parents()` шаблонов, `single`, `leaf`, главная всегда наверху и не двигается), и проверка права «Править» для шаблона каждой страницы, которая переезжает или меняет место; пока не прошли все, ничего не меняется. Перенос проходит как обновление страницы (хуки по шаблону). В админке: «Страницы» → «Изменить структуру».
- **Пункты меню** («Настройки → Меню») двигаются тем же `SortableTree` (за значок слева), глубина — `depth` области меню (2 = пункт и один уровень вложенных); перенос сохраняется вместе с остальными правками меню.
- Для разработчика плагина: `SortableTree.vue` и чистая арифметика `treeOps.js` лежат в `admin/src/components/ui/` и доступны плагинам админки через `ITK.ui` (`SortableTree`, `KanbanBoard`).

## Меню админки

Рельсу админки строит сервер: `GET /admin/api/schema` отдаёт `nav` — `[{key, group, icon, order, items: [{key, label, to, icon, order, badge?, sub?}]}]`, отсортированный и только с тем, что доступно человеку. Встроенные пункты ядра — такие же элементы: «Сегодня» (0), «Страницы» (10) и быстрые ссылки шаблонов с `shortcut()` (`sub: true`), «Заявки» (20, `badge` — число новых), «Медиатека» (30) в группе без заголовка, «Настройки» (группа с `order` 900). Класс коллекции появляется в меню сам; атрибуты на классе:

| Атрибут | Что делает |
|---|---|
| `#[Group('Catalog', icon: 'box', order: 20)]` | заголовок группы, её значок и место (без атрибута — группа «Коллекции», `order` 50) |
| `#[Order(10)]` | место пункта внутри группы (у класса шаблона страницы — место шаблона в списке шаблонов) |
| `#[Icon('map')]` | значок пункта |
| `#[CanSee('col:tours:edit')]` | кому показывать (поверх прав роли, право «Смотреть» нужно в любом случае) |
| `#[HideFromMenu]` | раздел работает, пункта в меню нет |

В `schema.php` то же: `$s->collection('tours')->menu(group: 'Catalog', order: 10, icon: 'map', hidden: false, canSee: 'col:tours', groupIcon: 'box', groupOrder: 20)`. `canSee(Viewer)` класса скрывает коллекцию совсем (и из схемы, и из API).

Права. Области прав генерируются из всех ресурсов: `col:<ключ>` — на каждую коллекцию, `pages` (с ограничением по шаблонам), `set:<группа>` — на каждую группу настроек, и `action:<коллекция>:<действие>` на каждое действие с `permission()` (группа `actions`, одно действие «Смотреть» = «Запускать»). Матрица ролей строится из `permissionAreas` схемы и подхватывает новые строки сама.

## Расширения

Пакет (или сам сайт) подключается к ядру одним вызовом:

```php
final class ToursExtension implements ItkExtensionInterface   // тег itk.extension ставится сам
{
    public function register(Registry $r): void
    {
        $r->resources(ToursCollection::class, TourTemplate::class)      // ещё: sections(), templates(), collections(), settings()
            ->twigPaths(__DIR__ . '/../templates', 'Tours')                 // {% include '@Tours/block.html.twig' %}
            ->menu(NavItem::make('Reports', '/x/reports')->group('Analytics', icon: 'chart', order: 60)->icon('chart')->canSee('col:tours'))
            ->adminAssets(js: ['/bundles/tours/admin.js'], css: ['/bundles/tours/admin.css'])
            ->notificationChannels(SmsChannel::class)                       // каналы уведомлений (раздел «Уведомления»)
            ->healthChecks(DiskCheck::class);                               // проверки раздела «Состояние сайта»
    }
}
```

- `Registry` вызывается один раз за запрос, при первом вопросе. Классы из `resources()` берутся из контейнера, если они там есть (с автоконфигурацией), иначе создаются через `new` без аргументов. Один класс считается один раз.
- `twigPaths()` добавляет папки в Twig до первого шаблона (в запросе и в консоли, так что работает и `lint:twig`).
- `NavItem` — пункт меню, который не коллекция: `to` — маршрут админки (то, что после `#`), группа по умолчанию «Плагины» (`order` 800). Показывается человеку, прошедшему `canSee`.
- `adminAssets()` подключает файлы после основного бандла админки: скрипты как `<script type="module">`, стили `<link>` (принимаются адреса `/…` и `http(s)://…`, остальное отбрасывается). Оболочка до бандла объявляет `window.ITK = { plugins: [], registerPlugin(plugin) }`; плагин вызывает `window.ITK.registerPlugin({ fields: { type: Component }, panels: { … }, actions: { … } })`. Админка при старте забирает накопленные `plugins`, сама заменяет `registerPlugin` и запускает скрипты плагинов, когда `window.ITK` готов. Плагину доступны `window.ITK.vue`, `http`, `toast`, `confirm`, `i18n`, `ui`; плагин даёт свои типы полей (`fields`), экраны (`panels`), клиентскую часть действий (`actions`) и карточки записей (`previews`); подробности — `admin/src/README.md`.

## Генераторы

Шесть команд создают в сайте готовый класс (и заготовку Twig или JS, где она нужна) по шаблонам из `packages/cms/skeleton/`. Классы лежат в `src/Itk/…` и подхватываются сами; в боевом режиме после новой команды нужен `bin/console cache:clear`.

| Команда | Создаёт |
|---|---|
| `itk:make:template <ключ> [название]` | `src/Itk/Template/<Ключ>Template.php` и `templates/pages/<ключ>.html.twig` |
| `itk:make:section <ключ> [название] [--group=…]` | `src/Itk/Section/<Ключ>Section.php` (заголовок, текст, картинка) и `templates/sections/<ключ>.html.twig`. Без `--group` секция попадает в группу «Тексты и картинки», и заголовок выводится как H2; H1 получает группа «Первый экран» |
| `itk:make:collection <ключ> [название] [--group=…] [--icon=…]` | `src/Itk/Collection/<Ключ>Collection.php`; ещё умеет `--from` и `--import` (ниже) |
| `itk:make:settings <ключ> [название]` | `src/Itk/Settings/<Ключ>Settings.php` с примером чтения значения в коде и в Twig |
| `itk:make:field <тип> [название]` | свой тип поля: `src/Itk/Field/<Тип>Field.php` (наследник `AbstractField`) и компонент админки `public/itk/fields/<тип>.js` |
| `itk:make:plugin <ключ> [название]` | расширение: `src/Itk/Plugin/<Ключ>Extension.php` (пункт меню, `adminAssets`) и экран админки `public/itk/plugins/<ключ>/admin.js` |

Общие правила:
- Не хватает ключа или названия — команда спрашивает (в неинтерактивном режиме `-n` ключ обязателен, название — по ключу). У каждого вопроса есть флаг или аргумент.
- Файл с классом, который уже есть, **не перезаписывается** без `--force` (ничего не меняется, код возврата 1). Заготовки Twig и JS, которые уже есть, не трогаются и с `--force` заменяются.
- `--dry-run` показывает, какие файлы будут созданы, и сам класс, ничего не записывая.
- `--dir=src/Other/Folder` меняет папку класса; пространство имён берётся из `autoload.psr-4` файла `composer.json`, папка должна в него входить.
- Ключ, который уже занят другим классом или `config/itk/schema.php`, не принимается (тот же ключ у ресурса другого вида — можно: у шаблона `tours` и коллекции `tours` разные адреса).
- Скрипт поля или плагина подключается расширением: `$registry->adminAssets(js: ['/itk/fields/rating.js'])`; `itk:make:plugin` делает такое расширение сразу.

### Коллекция из описания или таблицы

```
bin/console itk:make:collection --from=form.json                                  # класс по описанию
bin/console itk:make:collection --from=table:table_name [--import] [--map=…]      # класс по таблице и перенос строк
```

JSON: `{"key": "tours", "title": "Tours", "singular": "tour", "icon": "map", "fields": [{"key": "city", "label": "City", "type": "text", "required": true, "max": 80}], "rows": [{"id": 1, "city": "Perm"}]}`.
Типы: `text, textarea, description, headline, article, number, toggle, select (с "options": ["a", "b"]), date, datetime, image, photos, link`; `rows` нужны только для `--import`. Остальное (повторитель, связь) дописывается в классе вручную.

Из таблицы (PostgreSQL; таблица в другой схеме — `table:схема.таблица`, другое соединение Doctrine — `--connection=имя`) колонки превращаются в поля:

| Колонка | Поле |
|---|---|
| `varchar`, `char` | `Text` (длина → `max`) |
| `text`, `mediumtext`, `longtext`, `json` | `Textarea` |
| `int`, `decimal`, `float`, `year` … | `Number` |
| `tinyint(1)` | `Toggle` |
| `date` | `Date` |
| `datetime`, `timestamp` | `DateTime` |
| `enum` | `Select` (значения из `enum`) |

Остальные типы (`blob`, `geometry` …) пропускаются с пояснением. `NOT NULL` без значения по умолчанию → `required()`. Подпись берётся из комментария колонки, иначе из короткого словаря («city» → «Город») или из самого имени — её правят в классе. Первичный ключ в поля не попадает, он нужен переносу строк. Текстовые поля получают `searchable()->sortable()`, числа и даты — `sortable()->filterable()`, переключатели и списки — `filterable()`.
- `--map=колонка:поле[:тип],…` переименовывает колонку в поле и/или меняет тип (`dateStart:dateStart:date` делает из `datetime` просто дату), `колонка:-` пропускает колонку.
- `--strip-prefix=modx_` отбрасывает начало имени таблицы при выборе ключа (`modx_tour_list` → `tour_list`), `--id=column` — если первичного ключа нет или он составной.
- `--import` переносит строки в записи коллекции. Идентификатор строки источника лежит в записи (`translations.<язык>.meta.sourceId`, откуда — в `meta.source`), поэтому повторный запуск **обновляет** свои записи, а не создаёт новые; строки, которых в источнике больше нет, не удаляются. Записи создаются обычным путём (проверки, хуки и события те же) черновиками; `--publish` публикует их сразу, строка, у которой не заполнено обязательное поле, остаётся черновиком и попадает в отчёт. Если класс уже есть, он не трогается, и перенос идёт в него. С `--dry-run` печатаются класс, число «будет создано / обновлено» и первые строки в том виде, в каком они лягут в поля.

Пример (таблица старого сайта, поездки):

```
bin/console itk:make:collection tours "Tours" --from=table:modx_legacy.modx_tour_list --map=dateStart:dateStart:date,dateEnd:dateEnd:date --import --dry-run
```
```php
final class ToursCollection extends CollectionResource
{
    // key(), title(), titleField() = 'city' …
    public function fields(): iterable
    {
        yield Text::make('City', 'city')->required()->max(255)->searchable()->sortable();
        yield Date::make('Start date', 'dateStart')->sortable()->filterable();
        yield Date::make('End date', 'dateEnd')->sortable()->filterable();
    }
}
```
Без `--dry-run` и с `--publish` те же команды создают файл и 15 опубликованных записей; второй запуск пишет «создано: 0, обновлено: 15» (при `admin_locale: en` это `created: 0, updated: 15`: консоль говорит на языке админки).

## История изменений и корзина

Каждая сохранённая правка страницы, записи коллекции, группы настроек и меню пишется в `itk_revision`: кто, когда, что изменилось (список в том же виде, что и «что будет опубликовано») и вся версия целиком. Запись делают сами `PageService`, `CollectionService`, `SettingsService` и `MenuService`, в той же транзакции, что и правка; код сайта ничего не вызывает. Правки из консоли, импорта и очереди пишутся как «Система».

- **Склейка.** Автосохранения одного человека подряд (между ними меньше 10 минут) — одна запись. Если человек вернул всё как было, запись исчезает. Правка, ничего не изменившая, записи не оставляет.
- **Перенос в дереве** (`PUT pages/tree`, `PUT collections/{col}/tree`) — обычная запись `edit` в каждой языковой версии: «Расположение в дереве страниц» у страницы, «Расположение в дереве» у записи. Новый порядок среди соседей записи не оставляет.
- **Первая запись.** Для объекта, который жил до истории, перед первой правкой записывается «версия до начала истории», чтобы было куда вернуться.
- **Действия.** `create`, `edit`, `publish`, `unpublish`, `revert` (отмена правок), `delete`, `restore`.
- **Глубина.** Опции `itk_cms.history.keep` (записей на объект и язык, по умолчанию 100) и `itk_cms.history.days` (возраст, по умолчанию 365). При записи лишнее отсекается сразу; остальное чистит `bin/console itk:history:prune [--keep=N] [--days=N] [--dry-run]`. Расписания в ядре нет (компонента Scheduler в бандле нет): команду нужно запускать раз в сутки самому, например cron: `0 4 * * * php bin/console itk:history:prune`. Самая новая запись существующего объекта не удаляется по возрасту, запись об удалении — удаляется.
- **Вернуть версию** (`POST history/revision/{id}/restore`): черновик нужного языка становится этой версией (заголовок, адрес, SEO, секции, поля; место в дереве и шаблон не трогаются), сама правка тоже попадает в историю, на сайте ничего не меняется до публикации. Поля, которые человеку нельзя видеть или менять (`canSee`, `readonly`), остаются как есть. Для настроек и меню возвращается вся группа или меню языка. Права — как на правку: `pages` (по шаблону), `col:<ключ>`, `set:<группа>`, `set:menus`.
- **Корзина.** Удалённая страница или запись пишется в историю целиком (все языки, опубликованная копия тоже). `GET history/trash` отдаёт то, что не вернули; возврат (`POST history/revision/{id}/restore` для записи об удалении) создаёт её заново с тем же id, всеми языками и как черновик; если родителя больше нет, она встаёт на верхний уровень, если адрес занят — ответ 409. Хуки `created` сработают как при создании.
- **API.** `GET history/{page|item|settings|menu}/{id}?locale=&limit=&offset=`, `GET history/revision/{id}` (запись + `against`: что изменится при возврате), `POST history/revision/{id}/restore`, `GET history/trash?kind=&key=`. В записях нет значений полей, которые человеку скрыты правом `canSee`.
- **Админка.** Кнопка «История» в редакторе страницы и в записи коллекции (боковая панель), «Корзина» в «Страницах» и в списке коллекции.

## Уведомления

Одна дверь для сообщений тем, кто ведёт сайт: `Itk\Cms\Notification\NotifierInterface::notify(Notification)`. Ядро и код сайта вызывают её, а что с сообщением делать, решают каналы.

```php
$notifier->notify(new Notification(
    type: 'order.paid',                     // что случилось: короткий код ('request.new', 'telegram.failed'; 'ai.suggestion' зарезервирован)
    title: 'Order #15 paid',
    body: '3,200 RUB',
    link: '/collections/orders',            // маршрут админки или полный http(s)-адрес
    level: Notification::WARNING,           // info | warning | error
    audience: 'col:orders',                 // кому: null — всем вошедшим; право ('requests', 'col:tours:edit', 'set:users'); 'role:editor' — роль
    payload: ['orderId' => 15],             // данные для каналов, без секретов
    key: 'order.paid:15',                    // необязательно: по одному уведомлению на ключ (повтор не копится)
));
```

- **Каналы** — сервисы, реализующие `NotificationChannelInterface` (`name()`, `supports()`, `send()`); тег `itk.notification_channel` ставится сам. Класс, который не сервис, можно назвать в `Registry::notificationChannels()` расширения. Ошибка одного канала пишется в лог и не мешает остальным и тому, кто вызвал `notify()`. Сайт может добавить свой канал (SMS, мессенджер) обычным сервисом или заменить `NotifierInterface` своим.
- **`DatabaseChannel`** (в ядре) хранит уведомление в `itk_notification` для колокольчика админки; «прочитано» — `itk_notification_read` (по человеку). Человек видит только то, что подходит его правам или роли (`audience`) и что отправлено после его регистрации. Старые чистит `bin/console itk:notifications:prune [--days=90] [--dry-run]` (расписания в ядре нет: поставьте её в cron раз в сутки, например `15 4 * * * php bin/console itk:notifications:prune`).
- **`TelegramChannel`** для заявок: уведомление `request.new` ставит в очередь отправку в Telegram — дальше всё как раньше: обработчик Messenger через `TelegramNotifier` шлёт текст в чаты формы, помечает заявку «доставлена» / «не доставлена» (без подключённого Telegram доставка остаётся нейтральной, ничего не повторяется), повторяет по `retry_strategy`; «Отправить снова» и `itk:requests:resend` работают как прежде. Остальные уведомления в Telegram не уходят.
- **`EmailChannel`** для заявок: уведомление `request.new` становится письмом на каждый адрес из «Настройки → Уведомления → Письма о новых заявках» (раздел «Почта»). Если почта не настроена или адресов нет, не делает ничего.
- **События ядра.** Новая заявка: `request.new` для тех, у кого есть право `requests`. Заявка не дошла: `telegram.failed` (одно на заявку) для тех, кто может править заявки; когда доставка наконец удалась или Telegram отключили и заявка вернулась в «ожидает», оно убирается. Письмо не дошло после всех повторов: `mail.failed` (по заявке — одно на заявку, для тех, кто правит заявки; остальные — для тех, кто правит «Почту»; письмо о заявке, дошедшее позже, убирает своё). Сам исход доставки — событие Symfony `Itk\Cms\Event\RequestDelivery { request, delivered, error }`.
- **Язык уведомления.** `new Notification(title: …)` хранит готовый текст, как написан: так сайт сообщает о своих событиях. Уведомление ядра создаётся через `Notification::translated(type: …, titleKey: 'область.ключ', titleParams: [...])`: в базу идёт ключ с параметрами, и каждый читатель видит заголовок на своём языке (в колокольчике и панели), а в Telegram и почту текст уходит на `admin_locale`. Тот же приём годится плагину сайта со своим каталогом `translations/`.
- **API админки.** `GET notifications?unread=1&limit=&offset=` → `{ items: [{ id, type, title, body, link, level, payload, read, createdAt }], total, unread }`; `POST notifications/read { ids } | { all: true }` → `{ ok, unread }`. Доступно любому вошедшему, он читает только своё.
- **Админка.** Колокольчик в рельсе со счётчиком непрочитанного (опрос раз в 60 секунд, пока вкладка открыта) и панель «Уведомления»: список, «Только непрочитанные», «Отметить всё прочитанным», переход по ссылке.

## Состояние сайта

«Разработчику → Состояние сайта» (`/admin#/health`) — экран для того, кто следит за сайтом. Область прав `dev` (группа «Разработчику» в матрице ролей): «Смотреть» открывает экран, «Править» разрешает повторять и удалять упавшие задачи, отправлять заявки снова и сбрасывать кэш страниц. По умолчанию область есть только у «Администратора».

- **Фоновые задачи (Messenger).** Сколько сообщений ждёт в каждом транспорте, который умеет считать (`MessageCountAwareInterface`; транспорт ошибок и `sync` не очередь). Упавшие сообщения берутся из транспорта ошибок (`framework.messenger.failure_transport`, до 50 последних): что за задача, причина (токен бота из текста вырезается, длинный текст обрезается), сколько было попыток. «Повторить» кладёт сообщение обратно в очередь, откуда оно пришло (счётчик попыток начинается заново), «Удалить» убирает. Ядро не знает имён транспортов; без транспорта ошибок блок пустой. Транспорт, который не удалось собрать (DSN, которого никто не понимает, нет пакета, не отвечает база), показывается в списке с причиной (`queues[].error`, `waiting: null`) и даёт статус «внимание», а не 500; то же с хранилищем упавших задач (`failed.error`): оно собирается только при первом обращении.
- **Заявки без доставки** (`delivery=failed`, последние 20): номер, форма, время, причина. Имён и телефонов здесь нет. «Отправить снова» шлёт заявку в Telegram сразу.
- **Расписание.** Если подключён Symfony Scheduler и есть расписания (`#[AsSchedule]`), показываются их задачи и время следующего запуска; иначе экран говорит, что расписаний нет.
- **Диск.** Свободно и занято для `itk_cms.upload_dir` и `var/`; меньше 2 ГБ свободного — «внимание», меньше 512 МБ — «ошибка» (проценты ничего не говорят о диске в полтерабайта).
- **Версии и обновления.** PHP, Symfony, `itk/cms` (из `vendor/composer/installed.json`). Проверка обновлений выключена, пока не задан `itk_cms.update_source`: тогда экран пишет «Пакет пока не опубликован». Источник — адрес, отдающий JSON: `{"version": "1.4.0"}` (или `latest`, `tag_name`: подходит `releases/latest` GitHub) либо метаданные Packagist `{"packages": {"itk/cms": [{"version": …}]}}`. Берётся самая большая версия без `-beta` и `dev-`. Ответ кешируется на 12 часов (неудачная проверка повторяется через 10 минут), кнопка «Проверить сейчас» спрашивает заново.
- **Критический CSS.** Одна строка (раздел «Критический CSS»): «Выключен»; «ошибка», если нет файла стилей из `stylesheets`, `node` не запускается или старше 22, или в `node_modules` проекта нет crittr; «внимание», если есть страницы с неудачей (с последней причиной) или сообщения ждут в `critical_css` дольше пяти минут; иначе «есть у N из M живых языковых версий». Проверка запускает `node --version`, но не Chrome.
- **Проверки.** Свои проверки добавляют так: класс реализует `Itk\Cms\Health\HealthCheckInterface` (`name()` и `check(): HealthResult`; `HealthResult::ok|warn|fail('text for a human')`). В папке, которую сканирует контейнер сайта, он подхватывается сам (тег `itk.health_check`); из пакета его называют в `Registry::healthChecks()`. Проверка должна быть быстрой; исключение показывается как «Ошибка», а не ломает экран. В ядре пять: «База данных», «Папка для загрузок», «Почта» (настроена ли отправка, чем кончилась последняя; см. раздел «Почта»), «Telegram» («Telegram не подключён» — спокойная строка, а не предупреждение; «внимание» только если Telegram подключён, а для какой-то формы нет чата) и «APP_SECRET» (предупреждение, если он пуст). Письма, которые не ушли после всех повторов, видны и в списке упавших задач.
- **API.** `GET health` — весь отчёт (`status` — худший из проверок, дисков и «внимание», если есть упавшие задачи или недоставленные заявки); `POST health/messenger/{id}/retry|delete`; `POST health/requests/{id}/resend` → `{ok, delivery, error}`; `POST health/updates/refresh`; `POST health/page-cache/clear` → `{ok}` или 500 `{error}`, если пул отказал (кнопка «Сбросить кэш страниц», раздел «Кэш страниц»). Всё требует области `dev`.

Для деплоя и мониторинга тот же отчёт есть в консоли (`itk:doctor [--ci]`, раздел «Команды») и в открытом виде «жив / не жив» (`GET /healthz`, раздел «Публичные страницы: заголовки и 304»).

## Настройки в коде

`Itk\Cms\Settings\Settings` (сервис) возвращает значение в типе поля из схемы:

```php
$settings->get('phone');                  // по типу поля: toggle → bool, number → int|float, text/select → string, image/link/repeater → array
$settings->string('phone', 'none');        // string
$settings->int('seats', 10);              // int
$settings->bool('maintenance');           // bool
$settings->array('socials');              // array
$settings->media('logo');                 // {src, alt} или null, пока картинка не выбрана
$settings->get('siteName', locale: 'en'); // язык (по умолчанию основной) — для полей с translatable()
$settings->all();                         // все настройки языка, каждая в своём типе
```

Значение по умолчанию подставляется, если настройки нет в схеме или она пуста (пустой текст, число, список, картинка без адреса); `false` и `0` — настоящие значения. Пока настройка не сохранена, читается `default()` поля, затем значение по умолчанию вызова. Twig `settings('phone')`, `settings('phone', 'none')` и `settings()` работают через тот же сервис. Служебные ключи (`tgOn`, `tgRoutes`, `forms`) читаются как раньше.

## Двухэтапный вход

Одноразовый код из приложения на телефоне (TOTP по RFC 6238: SHA-1, 6 цифр, 30 секунд; подходит Google Authenticator, 1Password, Authy и другие) после пароля. Своя небольшая реализация, без `scheb/2fa-bundle`: ядру не нужна запись в `security.yaml` сайта, а резервные коды, шифрование секрета и «обязательно для роли» всё равно пришлось бы писать самим. В `security.yaml` менять ничего не надо.

- **Человек.** «Мой профиль → Двухэтапный вход»: «Включить» → QR-код (рисует админка, библиотека `qrcode-generator`) и ключ для ручного ввода → подтверждение первым кодом → 10 резервных кодов (показываются один раз, каждый работает один раз) → «Отключить» и «Новые резервные коды» только с паролем.
- **Вход.** Пароль → `/admin/login/otp` (код из приложения или резервный). Пока код не введён, в `/admin` не работает ничего, кроме этой страницы и выхода (`Security\TwoFactor\TwoFactorGate`, на каждый запрос, по базе; страницы и API админки он узнаёт по маршруту, поэтому второй фактор держится и при `itk_cms.admin_path` или `prefix` у импорта). Каждый новый вход по паролю начинается «непроверенным».
- **Обязательно для роли.** `Role::require2fa` (флажок «Требовать двухэтапный вход» в Настройки → Роли; можно и у встроенных ролей — это не право). Человек такой роли без второго фактора видит только окно настройки (доступны оболочка `/admin` и `/admin/api/2fa*`, остальное — 403 с `details.code = '2fa_enrol'`); отключить фактор ему нельзя (409).
- **Попытки.** Пять неверных кодов или паролей подряд блокируют фактор человека на 15 минут (429, `details.code = '2fa_locked'`); код действует один раз (шаг, по которому его приняли, запоминается), окно ±1 шаг. К блокировке пароля формы входа (`login_throttling`) это дополнение, не замена.
- **Хранение.** Секрет — в `itk_user_2fa.secret`, зашифрован `sodium_crypto_secretbox`, ключ выводится из `APP_SECRET` (смена `APP_SECRET` делает секреты нечитаемыми — все включают фактор заново). Резервные коды — только ключевые хеши (HMAC-SHA256). Секрет и коды не попадают в журнал и сессию; секрет отдаёт только ответ `POST 2fa/setup`.
- **Потерян телефон и коды.** Администратор: «Настройки → Пользователи → Сбросить двухэтапный вход» (`POST users/{id}/reset-2fa`, право `set:users`/`edit`, не для себя). Если войти некому: `bin/console itk:user:reset-2fa name@example.com`.
- **API.** `GET 2fa`, `POST 2fa/setup`, `POST 2fa/enable {code}`, `POST 2fa/recovery-codes {password}`, `POST 2fa/disable {password}` — только про себя, особых прав не нужно. `me.twoFactor = { enabled, required, enrol }`, у `users` — `twoFactor`, у `roles` — `require2fa`.

## Пароли

- **Свой пароль.** «Мой профиль → Сменить пароль»: текущий пароль, новый (не короче 10 знаков), повтор. `POST /admin/api/me/password {current, password, repeat?}` → `{ok: true}`; неверный текущий или короткий новый — 422 с `details.current|password|repeat`, больше пяти попыток за 15 минут — 429 (`framework.rate_limiter.itk_password_change`, на человека; успешная смена сбрасывает счётчик). Сессия остаётся открытой, а открытые входы с других устройств заканчиваются: хеш пароля входит в то, что Symfony сверяет на каждый запрос.
- **Забыли пароль / приглашение / сброс администратором.** Если сайт умеет отправлять почту, всё идёт ссылкой из письма, см. «Почта» ниже. Без почты «Пригласить» и «Сбросить пароль» (`POST users` и `POST users/{id}/reset-password`) придумывают одноразовый пароль на сервере и возвращают его администратору один раз в поле `password`; админка показывает его в окне с кнопкой «Скопировать», дальше администратор передаёт пароль человеку сам, а человек меняет его в профиле. Сбросить пароль самому себе этим вызовом нельзя (403).
- **Консоль, если войти некому.** `bin/console itk:user:password name@example.com` спросит пароль (ввод скрыт, дважды); `--password=…` задаёт его параметром, но он останется в истории команд оболочки. Пароль не короче 10 знаков (`UserManager::MIN_PASSWORD_LENGTH`). Это запасной путь для того, кто управляет сервером: посетителям страницы входа про него не говорят.

## Почта

Сайт умеет отправлять письма: ссылки «задать пароль» (забыли пароль, приглашение, сброс администратором) и письма о новых заявках. Почтовый сервер задаёт администратор в «Настройки → Почта», без правки файлов и перезапуска. Нужны пакет `symfony/mailer` (бандл требует его сам) и работающий воркер Messenger: письма уходят через очередь `async`, страница не ждёт почтовый сервер.

**Откуда берётся сервер** (`Mail\MailerFactory`, решение в момент отправки):
1. «Настройки → Почта» включена, указаны сервер и адрес отправителя → этот сервер;
2. иначе переменная окружения `MAILER_DSN`, если там настоящий транспорт (`null://null` значит «ничего не отправлять» и считается «почта не настроена»);
3. иначе почта «не настроена»: письма не уходят, «Забыли пароль?» говорит обратиться к администратору, «Пригласить» и «Сбросить пароль» показывают одноразовый пароль, как раньше.

Адрес отправителя всегда из «Настройки → Почта» (поле «Адрес отправителя»); при одном `MAILER_DSN` без него берётся `noreply@<домен сайта>`, если домен задан в настройках.

**Настройки «Почта»** (область прав `set:mail`, по умолчанию только «Администратор»; «Смотреть» показывает настройки без пароля, «Править» меняет их и шлёт пробное письмо). Хранятся одной настройкой `mail` (общей для языков) вне групп схемы, поэтому обычный `GET settings` их не отдаёт. Поля: `enabled`, `host`, `port` (пусто — обычный порт защиты), `encryption` (`none` | `ssl` | `starttls`), `username`, `password`, `fromAddress`, `fromName`. Пароль хранится зашифрованным (`Security\TwoFactor\SecretBox`: libsodium, ключ из `APP_SECRET`; смена `APP_SECRET` делает его нечитаемым, его вводят заново), в ответах его нет никогда: только `passwordSet: true|false`. Пустой `password` при сохранении оставляет прежний, `clearPassword: true` («Удалить пароль») стирает. Сохранённый пароль привязан к своему серверу: если меняются `host` или `username`, новый пароль (или `clearPassword`) должен прийти в том же запросе, иначе 422 `details.password` «Сервер изменился — введите пароль заново». Так тот, кому дали право править почту, не направит старый пароль на свой сервер и не вытянет его пробным письмом. Без шифрования (`none`) вместе с логином админка предупреждает, что пароль уходит открытым текстом.
- `GET /admin/api/settings/mail` → поля, `passwordSet`, `configured`, `source` (`settings` | `env` | `none`), `envFallback`, `problem` (почему не настроена), `last` (`okAt`, `error`, `errorAt`, `kind`: чем кончилась последняя отправка).
- `PUT /admin/api/settings/mail` — сохраняет сразу; 422 с `details.<поле>`.
- `POST /admin/api/settings/mail-test {to?}` — «Отправить тестовое письмо»: отправляет **сразу, не через очередь**, на `to` или на почту того, кто нажал; настоящая причина отказа сервера приходит в ответе (502), с вырезанными паролем и строкой подключения. Не больше 10 писем в час на человека (ответ 429, `itk_mail_test`).
- Строка подключения строится при отправке (`Mail\DsnBuilder`): логин и пароль кодируются через `rawurlencode`, поэтому `@ : / # %` и пробелы в них безопасны. `none` → `smtp://…?auto_tls=false`, `ssl` → `smtps://…`, `starttls` → `smtp://…?require_tls=1` (без шифрования письмо не уйдёт). Лог SMTP-диалога не пишется: в нём логин и пароль в base64.

**Очередь и ошибки.** Письмо — сообщение `Mail\SendMail` (готовый текст и HTML, без настроек почты) в транспорт `async`. Не вышло — Messenger повторяет по `retry_strategy`; когда попытки кончились, письмо лежит в транспорте ошибок («Состояние сайта» → фоновые задачи: «Письмо о заявке № 15 (i***@example.com)», с причиной) и в колокольчик приходит уведомление `mail.failed` (по заявке — для тех, кто правит заявки, остальные — для тех, кто правит «Почту»). В «Состоянии сайта» есть проверка «Почта»: настроена ли отправка и чем кончилась последняя (предупреждение, если адреса для писем о заявках указаны, а почта не настроена). Тексты ошибок очищаются от пароля до того, как их запишут в журнал, в базу или покажут (логин и пароль короче 4 знаков не вырезаются: иначе исказился бы весь текст). Состояние последней отправки (`mailStatus`) пишется отдельным SQL-запросом, а не через менеджер сущностей: сбой базы не закроет его для остальной работы. В очереди письмо со ссылкой «задать пароль» лежит целиком, как любое сообщение Messenger: ссылка живёт час («забыли пароль»), 24 часа (сброс администратором) или 7 дней (приглашение). Письмо, которое не ушло после всех повторов, остаётся в транспорте ошибок вместе со ссылкой открытым текстом, пока его не удалят (`messenger:failed:remove`), а ссылка к тому времени может ещё жить; чистите транспорт ошибок и не отдавайте к нему доступ посторонним.

**Забыли пароль.** На странице входа ссылка «Забыли пароль?» → `/admin/forgot-password` (адрес почты). Ответ всегда один: «Если такой адрес есть, мы отправили письмо со ссылкой», независимо от того, есть ли такой человек (заблокированному письмо не уходит). Одинакова и работа страницы: для любого правильно написанного адреса она проверяет лимиты и ставит в очередь одно сообщение `Security\RequestPasswordLink` (адрес и доверенный адрес сайта), ничего не читая о человеке, поэтому по времени ответа адрес не узнать; человека ищет, ссылку делает и письмо ставит воркер (`RequestPasswordLinkHandler`). Письмо ведёт на `/admin/reset-password/{token}`:
- ключ — 32 случайных байта (base64url), в базе только sha256-хеш (`itk_password_reset`: `token_hash`, `user_id`, `purpose` = `reset` | `invite`, `expires_at`, `used_at`); ссылка живёт час, срабатывает один раз; старые ссылки чистятся при выдаче новых. Все неиспользованные ссылки человека гаснут, когда ему задают пароль любым путём (по ссылке, в профиле, одноразовый пароль администратора, `itk:user:password`: `UserManager::setPassword`), когда меняют его почту и когда его блокируют, поэтому письмо, ушедшее на адрес с опечаткой, потом ничего не откроет;
- ограничения: 10 запросов в час с одного адреса посетителя (ответ 429), 3 письма в час на один адрес почты (посетитель разницы не видит), 10 неверных ссылок за 15 минут с одного адреса (`itk_password_reset_ip|email|guess`, `framework.rate_limiter`);
- страница ссылки просит новый пароль (не короче 10 знаков) и повтор, ставит пароль, гасит и эту, и все остальные ссылки человека, ведёт на вход с надписью «Пароль сохранён». Открытые входы с других устройств заканчиваются сами. Двухэтапный вход не трогается: после смены пароля нужен и код из приложения;
- страницы не кешируются, не шлют реферер (`Referrer-Policy: no-referrer`) и закрыты от индексации; формы защищены токеном сессии посетителя. В `security.yaml` эти адреса должны быть публичными (см. «Установка в сайт», шаг 4). Адрес для ссылок (`Mail\PublicUrl`) по порядку: опция `itk_cms.public_url`; адрес запроса, но только если у сайта задан `framework.trusted_hosts` (чужой `Host` Symfony тогда отклоняет сам); настройка «Домен» (`https://`, если схема не указана); адрес запроса как пришёл — только при `kernel.debug` (разработка). Сырой заголовок `Host` в боевом окружении не доверяется никогда. Если ни одного доверенного адреса нет, ссылку для пароля не делают: «Забыли пароль?» отвечает как всегда, но ничего не отправляет и пишет ошибку в журнал, а «Пригласить» и «Сбросить пароль» отдают одноразовый пароль с пояснением. Задайте `public_url`, `trusted_hosts` или «Домен»;
- без настроенной почты страница так и говорит: «Обратитесь к администратору сайта». Команду консоли `itk:user:password` посетителям не показывают.

**Приглашение и сброс администратором** (`POST users`, `POST users/{id}/reset-password`): при настроенной почте человеку уходит письмо со ссылкой (приглашение — 7 дней, сброс — 24 часа; тот, кто ещё не входил, получает приглашение заново). Ответ `emailed: true` и без пароля — только после того, как письмо принято очередью; админка пишет «Письмо отправлено на …». При сбросе прежний пароль работает, пока человек не задал новый (так письмо, которое не дошло, не запирает его). Если почты нет или очередь отказала, ответ прежний: `emailed: false`, одноразовый `password` для окна «Скопировать» (и `emailError`, если почту пробовали). Готовность почты для админки отдаёт `GET /admin/api/mail-ready` → `{ready}` (право `set:users`). «Сменить пароль» в профиле не менялся.

**Письма о новых заявках.** «Настройки → Уведомления → Письма о новых заявках»: адреса через запятую для «остальных форм» и свой список для каждой формы (настройка `mailRoutes`, область `set:notifications`, до 10 адресов на форму; пустой список формы — «как у остальных»). Канал `Notification\Channel\EmailChannel` на уведомление `request.new` ставит в очередь по письму на каждый адрес: тема «Новая заявка №12 — Форма звонка», в теле поля формы и ссылка на заявку в панели. Telegram работает как раньше и независимо. `GET settings` отдаёт тем, кто видит уведомления, `mailRoutes` и `mailReady` (да / нет).

**Письма и их шаблоны.** `@ItkCms/email/layout.html.twig` (рамка) и пары `reset_password`, `invite`, `request`, `test` (`.html.twig` и `.txt.twig`; переменные — в самих файлах, имя сайта — `site_name` из настройки `siteName`). Сайт переопределяет любой файл, положив файл с тем же именем в свои `templates/email/` (например, `templates/email/reset_password.html.twig` или `templates/email/layout.html.twig`): письмо сначала ищет шаблон сайта, затем `@ItkCms/email/…`. Внутри своего шаблона можно `{% extends '@ItkCms/email/reset_password.html.twig' %}` и поменять только нужный блок. Текст писем берётся из каталогов (`itk_t('http.mail_…')`) и идёт на языке получателя (письмо о заявке на `admin_locale`): простой язык, без картинок и скриптов.

**Разработка.** В стенде есть Mailpit: SMTP `mailer:1025` (внутри docker-сети), письма смотрят на http://localhost:8025 (API `GET /api/v1/messages`). Для разработки достаточно `MAILER_DSN=smtp://mailer:1025` в окружении php и worker либо те же значения в «Настройки → Почта» (хост `mailer`, порт 1025, без шифрования). В тестах проверяют по API Mailpit, на адреса `@example.com`.

**Если войти некому.** `bin/console itk:user:password name@example.com` (раздел «Пароли»).

## MCP: ИИ-ассистенты

ITK CMS умеет работать как сервер [Model Context Protocol](https://modelcontextprotocol.io): Claude (Claude Code, Claude Desktop) и другие ассистенты читают сайт и готовят правки **в черновиках**. Ассистент действует от имени сотрудника, который выдал ему ключ, и **никогда не получает больше прав, чем у этого сотрудника**: каждый вызов идёт через те же `Access` / `PermissionVoter`, права на поля (`canSee`, `readonly`), `RawFieldGuard`, хуки и историю, что и API админки.

### Включение и ключи

1. **Выключено по умолчанию.** «Настройки → ИИ-ассистенты» (область прав `set:mcp`, по умолчанию только «Администратор») — переключатель для всего сайта. Пока он выключен, `/mcp` отвечает 404, `itk:mcp` не запускается, ключи нельзя создать и ни один инструмент не вызвать. Там же: адрес сервера, готовые примеры подключения и список ключей всех сотрудников с кнопкой «Отозвать».
2. **Ключ.** «Мой профиль → Мои токены для ИИ-ассистентов»: название, что можно делать, срок (30 / 90 дней, год, без срока; в форме по умолчанию 90 дней). Через API `POST /admin/api/me/tokens` без поля `expires` ключ тоже живёт 90 дней: бессрочным он становится только при явном `"expires": null`. Ключ вида `itk_` + 43 знака (32 случайных байта в base64url) показывается **один раз**; в базе только его sha256 (`itk_api_token`: `user_id` с `ON DELETE CASCADE`, `name`, `token_hash`, `hint` = последние 4 знака, `scopes`, `expires_at`, `last_used_at`, `created_at`, `revoked_at`). У человека не больше 20 действующих ключей и 10 новых в час. Создать ключ можно только из сессии, прошедшей двухэтапный вход (если он включён или обязателен для роли).
3. **Доступы (scopes).** Доступ только сужает права человека: инструмент нужен и доступ ключа, и право человека на то, что он трогает.

   | доступ | что даёт | по умолчанию |
   |---|---|---|
   | `read` | смотреть страницы, записи, медиатеку, меню, настройки, SEO, историю | да |
   | `write` | создавать и менять **черновики**, загружать файлы (нужен `read`) | нет |
   | `publish` | публиковать страницы и записи (нужны `write`, `read` и право «Публиковать») | нет |
   | `requests` | читать и обрабатывать заявки: имена и **телефоны клиентов** (нужно право на «Заявки») | нет; в профиле предупреждение «Заявки содержат телефоны клиентов: они уйдут во внешний ИИ-сервис» |

   В профиле предлагаются только доступы, которые что-то значат для роли человека (кому нельзя публиковать, «Публиковать» не предложат).

   **`requests` нельзя соединять с `write` и `publish` в одном ключе.** Заявки пишут посетители сайта, то есть любой человек из интернета; ключ, который читает их тексты и при этом меняет или публикует страницы, мог бы выполнить чужую инструкцию из заявки. При создании такого ключа сервер отвечает 422 с понятным текстом («Доступ «Заявки» нельзя соединять с «Менять черновики»…»), а в профиле несовместимые доступы выключены и подписаны. Нужны оба — делайте два ключа. Проверка стоит только в создании ключа (`McpScopes::conflict()`): **ключи, выданные раньше, продолжают работать со всеми своими доступами** (доступы хранятся в `itk_api_token.scopes`, при вызове проверяется лишь их наличие), поэтому на сайтах, где такие ключи уже есть, отзовите их вручную и выдайте два.
4. **Что останавливает ключ.** Отзыв (человеком или администратором), конец срока, **блокировка владельца** (все его ключи сразу отвечают 403, после разблокировки работают снова), выключенный переключатель, роль, которой теперь нужен двухэтапный вход, а у человека его нет. **Новый пароль владельца ключи не останавливает**: чтобы остановить их, отзовите ключи. Права берутся в момент вызова: сменили роль, и ключ сразу получил права новой роли.
5. `last_used_at` обновляется не чаще раза в минуту. В канал Monolog `itk_mcp` пишется всё, что ассистент делал или пытался (кроме запросов, которые отклоняет `McpGate` до файрвола: неверный `Origin`, метод, размер): вызов инструмента и чтение ресурса (`resources/read`) строкой `info` («MCP tool call: …», «MCP resource read: …»: имя, id ключа и владельца, транспорт, результат), а всё отказанное строкой `warning`: инструмент, которого нет у ключа, вызов без имени инструмента и неподходящие аргументы («MCP tool call refused», с причиной `unknown_or_not_allowed`, `missing_name`, `invalid_arguments`), инструмент, ответивший ошибкой, неудачный вход («MCP sign-in refused»: причина `missing`, `invalid`, `revoked`, `expired`, `blocked`, `two_factor`, `too_many_wrong_keys`, адрес, а у настоящего ключа ещё id ключа и владельца) и отказ после входа («MCP call refused»: `too_many_calls`, `unsupported_protocol_version`, `too_large`). **Аргументы, ответы и сам ключ не пишутся никогда**; имя инструмента, присланное клиентом, обрезается до 80 знаков и очищается от переводов строки. На боевом сайте обычная настройка Monolog (`fingers_crossed` с `action_level: error`) такие строки теряет, пока в том же запросе нет ошибки. Чтобы журнал был настоящим, исключите канал из общего обработчика и дайте ему свой:

   ```yaml
   # config/packages/monolog.yaml
   when@prod:
       monolog:
           handlers:
               main:
                   type: fingers_crossed
                   action_level: error
                   handler: nested
                   channels: ["!itk_mcp"]      # остальное, как было
               mcp_audit:
                   type: stream
                   path: php://stderr
                   level: info
                   channels: [itk_mcp]
                   formatter: monolog.formatter.json
   ```

   Без Monolog строки уходят в обычный `LoggerInterface` сайта.

### Подключение

Проверено по [спецификации MCP, ревизия 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25), последней ревизии с рукопожатием `initialize` (сервер понимает и `2025-06-18`, `2025-03-26`, `2024-11-05`). На 2026-11-01 текущая ревизия MCP — `2026-07-28` ([о версиях](https://modelcontextprotocol.io/specification/versioning)): в ней рукопожатия `initialize` нет, а `2025-11-25` и старше названы устаревшими. Её сервер **не поддерживает**: клиенты, которые умеют обе эпохи, получают `400` на неизвестный `MCP-Protocol-Version` и откатываются на `initialize`, а клиенты только нового поколения с этим сервером не заработают. Поддержка `2026-07-28` — отдельная задача. Что в этой ревизии меняется для сервера без потока событий и сессий, и что из этого сделано: неверный `Origin` — `403` (сделано), неизвестный `MCP-Protocol-Version` — `400` (сделано), ошибки проверки аргументов — результат инструмента с `isError`, а не ошибка протокола (сделано), схемы инструментов — JSON Schema 2020-12 (наши используют только его общую часть), имена инструментов `snake_case` из латиницы подходят под новые рекомендации. Задачи (`tasks`), иконки, URL-режим запросов к человеку и новое в авторизации (OAuth) сервер не предлагает: ключ в заголовке `Authorization` их не требует.

HTTP (Streamable HTTP, без сессий: каждый вызов — `POST /mcp` с `Authorization: Bearer itk_…`, ответ `application/json`):

```bash
# Claude Code
claude mcp add --transport http mysite https://example.com/mcp --header "Authorization: Bearer itk_…"
```

```jsonc
// Claude Desktop, claude_desktop_config.json (нужен Node.js; mcp-remote соединяет приложение с сайтом)
{ "mcpServers": { "mysite": {
    "command": "npx",
    "args": ["-y", "mcp-remote", "https://example.com/mcp", "--header", "Authorization:${AUTH_HEADER}"],
    "env": { "AUTH_HEADER": "Bearer itk_…" } } } }
```

```jsonc
// Cursor: файл ~/.cursor/mcp.json (для всех проектов) или .cursor/mcp.json в проекте; ключ берётся из переменной окружения, в файл его не кладут
{ "mcpServers": { "mysite": {
    "url": "https://example.com/mcp",
    "headers": { "Authorization": "Bearer ${env:ITK_MCP_TOKEN}" } } } }
```

```jsonc
// VS Code (GitHub Copilot, режим агента): файл .vscode/mcp.json в проекте или команда «MCP: Add Server…»; ключ спрашивается один раз и хранится в защищённом хранилище, в файл не попадает
{ "servers": { "mysite": {
    "type": "http",
    "url": "https://example.com/mcp",
    "headers": { "Authorization": "Bearer ${input:itk-key}" } } },
  "inputs": [ { "type": "promptString", "id": "itk-key", "description": "AI assistant key (My profile → My keys)", "password": true } ] }
```

**claude.ai (свой коннектор).** Customize → Connectors, добавьте свой коннектор (на Team и Enterprise коннектор добавляет владелец организации в Organization settings → Connectors): адрес `https://example.com/mcp`, способ входа **«Без входа»**, в разделе **«Заголовки запросов»** добавьте заголовок `Authorization` со значением `Bearer itk_…` (слово `Bearer` и пробел вписываются вручную: Claude отправляет значение как есть). OAuth сервер не предлагает, поэтому вариантов «Войти» выбирать не нужно. Осторожно: заголовки запросов у claude.ai — бета и доступны не всем организациям (если раздела нет, подключиться этим способом нельзя; используйте Claude Code, Claude Desktop или Cursor), ключ вводится одним на всех пользователей коннектора, а после сохранения заголовок не изменить: только удалить коннектор и добавить заново. Адрес сайта должен быть доступен из интернета по HTTPS.

stdio (ассистент сам запускает программу; в выводе только строки JSON-RPC, сообщения для человека идут в stderr):

```jsonc
// ключ в переменной ITK_MCP_TOKEN, не в аргументах: параметр --token (и -e ИМЯ=значение) виден другим пользователям машины в списке процессов.
// Путь к bin/console полный: не все клиенты выставляют рабочую папку (cwd)
{ "mcpServers": { "mysite": { "command": "php", "args": ["/var/www/site/bin/console", "itk:mcp"], "env": { "ITK_MCP_TOKEN": "itk_…" } } } }
// в Docker: -e с одним именем берёт значение из env клиента; --project-directory нужен, потому что клиент запускает команду не из папки сайта (-T: без терминала)
{ "mcpServers": { "mysite": { "command": "docker",
    "args": ["compose", "--project-directory", "/path/to/site", "exec", "-T", "-e", "ITK_MCP_TOKEN", "php", "bin/console", "itk:mcp"],
    "env": { "ITK_MCP_TOKEN": "itk_…" } } } }
```

Ключ проверяется заново на каждое сообщение: отзыв ключа, блокировка владельца или выключение переключателя останавливают уже запущенного ассистента на следующем вызове.

### Инструменты

Имена `snake_case`, описания для модели на английском, результат — текстовый JSON и `structuredContent`, если он небольшой (до 16 КБ); списки постраничные (`limit`, `offset` / `page`), по умолчанию ответ короткий. Ошибка, которую может исправить модель (неверное значение, нет права, правило сайта), приходит обычным результатом с `isError: true` и понятным текстом; ошибки протокола — кодами JSON-RPC (`-32700`, `-32600`, `-32601`, `-32602`, `-32603`; `-32002` — ресурс не найден).

| доступ | инструменты |
|---|---|
| `read` | `site_schema` (шаблоны, типы секций с полями, коллекции, формы, области меню, группы настроек — только то, что видит человек; без аргументов короткий обзор, с `kind` + `key` — полное описание одного), `pages_list`, `page_get` (поля, секции с id, черновик против опубликованного: `state`, `changes`), `collection_list` (поиск, фильтры и сортировка по полям коллекции), `collection_item_get`, `media_search`, `media_get`, `menus_get`, `settings_get` (группы, которые человек может смотреть; пароли, токены, почтовый сервер, чаты Telegram и адреса уведомлений не отдаются), `seo_rules_list`, `history_list` |
| `write` | `page_create`, `page_update` (название, адрес, SEO, поля шаблона), `page_section_add`, `page_section_update`, `page_section_move`, `page_section_remove` (секции — по id, значения проверяются по схеме секции; ошибки объясняются так, чтобы модель могла исправить ввод), `collection_item_create`, `collection_item_update`, `media_upload` (base64, до 5 МБ, тип по содержимому, как в админке; принимаются только байты файла в base64; файл по адресу сервер не скачивает, поэтому SSRF невозможен) |
| `publish` | `page_publish`, `page_unpublish` (снять страницу с сайта: она остаётся черновиком, вернуть можно `page_publish`; главную страницу снять нельзя; нужно право «Публиковать»), `collection_item_publish` |
| `requests` | `requests_list`, `request_get`, `request_update` (статус и заметка; нужно право на «Заявки», на правку для `request_update`) — **единственное место, где ассистент получает телефоны клиентов** |

Подсказки клиенту (`annotations`): `destructiveHint: true` у всего, что заменяет существующие значения или выкладывает на живой сайт или снимает с него: `page_publish`, `page_unpublish`, `collection_item_publish`, `page_update`, `page_section_update`, `page_section_remove`, `collection_item_update`, `request_update`. Остальное, что только добавляет или читает, помечено `false`. У инструмента нет значений по умолчанию: каждый сам объявляет `readOnly`, `destructive` и `idempotent` (раньше `AbstractMcpTool` считал любой инструмент читающим). Клиент может на этом основании просить подтверждения у человека.

**Чего нет совсем:** удаления (страниц, записей, файлов, меню, правил; снять страницу с сайта можно, удалить нельзя), пользователей и ролей, ключей, настроек (запись), настроек почты, переадресаций, возврата версий из истории. `page_section_remove` убирает секцию только из черновика (живая страница не меняется до публикации, секцию можно вернуть из истории).

Правки через ассистента идут обычным путём: `PageService::create / saveDraft / update`, `CollectionService::create / update`, `MediaService::upload`, значит, срабатывают хуки и события ресурсов, `FieldAccess`, `RawFieldGuard` и запись в историю. **Поля `raw()` (сырой HTML и скрипты: секция «html» и такие же поля сайта) через ключ не меняет никто, даже администратор:** `RawFieldGuard` видит, что правка идёт по MCP (`ChangeSource`), и возвращает отказ «Через ИИ-ассистента HTML-код менять нельзя… только в админке». В `site_schema` такое поле помечено `raw` и `readonly`, с пояснением для модели. Сравнивается значение на своём месте (поле секции, шаблона или записи, поле элемента списка по `_id`), а не набор значений страницы: для ключа изменение — это и очистка поля, и копирование кода в другое поле, новую секцию или новый элемент, и перестановка двух кодов местами. Оставить код как есть, переместить, скрыть и убрать целиком секцию или элемент списка с ним можно (убирание — не запись); правит такой код человек в админке. В админке всё как раньше: не администратор может оставить, переместить и удалить код, а новый добавить не может. Список (повторитель) заменяется целиком, поэтому поле `raw()` внутри его элементов ассистент может не присылать: если элемент пришёл со своим `_id`, значение возвращается ему из черновика. Список, собранный заново без `_id`, при котором старые элементы с таким кодом пропали бы без следа, отклоняется с понятной ошибкой («Nothing was saved»), ничего не сохраняется; убрать элемент можно, прислав список без него и без новых элементов в том же вызове. То же для старого элемента с таким кодом, у которого нет своего `_id` (запись мимо сервисов): `page_get` не показывает ему `_id`, модели нечем на него указать, поэтому список с элементами без известного `_id` отклоняется той же ошибкой с номером элемента, а правит такой список человек в админке (любое сохранение списка через сервисы выдаёт элементам `_id`). В `site_schema` для такого поля внутри списка дана отдельная подсказка про `_id`. Причина: модель читает тексты посетителей, и одна удачная фраза не должна превращаться в `<script>` на странице для всех. Записи истории помечены: `itk_revision.via = 'mcp'`, `via_name` = название ключа; панель «История» показывает «Анна через ИИ-ассистента (Claude Desktop)», а правки ассистента и ручные правки одного человека не склеиваются в одну запись. В поле-картинку ассистент может положить только файл из медиатеки (или тот, что уже стоит в содержимом): сначала `media_search` / `media_upload`.

Ресурс `itk://schema` (`resources/list`, `resources/read`) отдаёт обзор схемы, как `site_schema` без аргументов.

### Транспорт и защита

- `POST /mcp` — поддерживаются версии протокола `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`. По спецификации: если клиент в `initialize` просит версию, которую мы знаем, отвечаем ею же; любую другую (неизвестную, пустую, не строку) — самой новой нашей, `2025-11-25`, и клиент сам решает, подходит ли она. Заголовок `MCP-Protocol-Version` с неизвестной версией — `400`; без заголовка запрос принимается. Уведомления получают `202` без тела. Пакеты до 20 сообщений поддерживаются (в версии `2025-06-18` спецификация их уже не знает, а более старые клиенты ими пользуются); **каждое сообщение пакета считается отдельным вызовом** в лимите ключа. `GET`, `DELETE` и остальное — `405` (потока событий и сессий сервер не предлагает).
- Цепочка до инструмента: `McpGate` (запрос ещё до роутера: выключатель → 404; метод; заголовок `Origin`; тип и размер тела) → файрвол `itk_mcp` (`stateless`, `Itk\Cms\Mcp\ApiTokenAuthenticator`: сессии и куки не читаются и не ставятся, поэтому CSRF не нужен) → `McpController` → лимит 120 вызовов в минуту на ключ (429 и `Retry-After`; сообщение пакета — один вызов) → `McpServer`. Двадцать неверных ключей за 15 минут с одного адреса — 429 (лимитеры `itk_mcp_token`, `itk_mcp_failed_auth`, `itk_mcp_token_create` можно переопределить в `framework.rate_limiter` сайта; за прокси задайте `framework.trusted_proxies`). Лимитеры берут блокировку (`symfony/lock`, ядро включает `framework.lock`): без неё параллельные вызовы пролетали бы все разом. Хранилище по умолчанию, `flock`, действует в пределах одного сервера; если приложение работает на нескольких, задайте общее в `framework.lock` (Redis, Memcached, БД).
- **`Origin` (защита от DNS-rebinding).** Программы ассистентов `Origin` не шлют. Запрос с заголовком `Origin` принимается, только если это адрес сайта (`PublicUrl`: `itk_cms.public_url`, иначе доверенный хост запроса и настройка «Домен»), он стоит в `itk_cms.mcp.allowed_origins` или это localhost при `kernel.debug`. Остальным — 403. Это **не CORS**: на `OPTIONS` `/mcp` отвечает 405 и `Access-Control-Allow-*` не шлёт, поэтому страница другого сайта вызвать `/mcp` из браузера всё равно не сможет; `allowed_origins` пропускает только программы, которые сами добавляют `Origin`.
- **Адрес читается так же, как его читают роутер и файрвол: после раскодирования.** `POST /m%63p` — это `/mcp`: выключатель, `Origin`, тип и размер тела проверяются и для такой записи (`McpGate` сравнивает `rawurldecode` пути). Выключатель проверяют ещё и `ApiTokenAuthenticator` (ответ 404 до разбора ключа) и `McpController`: даже если какой-то путь обойдёт `McpGate`, при выключенном MCP ключ не откроет ни одного инструмента.
- **Адрес `/mcp` принадлежит ядру.** `McpGate` отвечает на него до роутера (выключено — 404, включено — не POST даёт 405), поэтому страницу с таким адресом создать нельзя: `PageService` отвечает «адрес занят служебной частью сайта». То же для `/healthz`.
- **Одновременные правки.** Страница меняется инструментом `page_section_*` и `page_update` по одному вызову за раз (рекомендательная блокировка PostgreSQL, `pg_try_advisory_lock`, на страницу, то же для `collection_item_update` на запись): список секций читается и пишется целиком, и два параллельных вызова иначе потеряли бы правку первого. Блокировка лишь выстраивает правки в очередь, поэтому открытый редактор защищает проверка версии: страница и запись отдают поле `version` (ключевой хеш содержимого черновика, HMAC с `APP_SECRET`: по нему нельзя подобрать значение поля, закрытого `canSee`; для страницы ещё место в дереве и шаблон; публикация его не меняет), а автосохранение админки (`PUT pages/{id}/draft`, `PUT collections/{col}/{id}`) присылает его как `baseVersion`. Если черновик тем временем изменили (ассистент по MCP, другой человек, перенос страницы в дереве), сервер ничего не записывает и отвечает `409` `{error, details: {code: "conflict", current: {version, by, at, via, viaName}}}`: кто и когда изменил последним (по истории: публикация и снятие с публикации черновик не меняют и автором не считаются, перенос в дереве записывается в историю каждой языковой версии, а новый порядок среди соседей версию не меняет и записи не оставляет) и текущая версия. Редактор прекращает автосохранение и спрашивает: «Показать новую версию» или «Оставить мои правки» (повтор сохранения с `current.version`); вопрос стоит над боковой панелью и страницей, поэтому виден и на телефоне, и при панели на весь экран. Проверка и запись идут одним шагом под той же блокировкой на свежей копии из базы; удачное сохранение возвращает новый `version`, поэтому мелкие подряд идущие автосохранения одного редактора не спорят друг с другом. **Запрос без `baseVersion` не проверяется и работает как раньше** (инструменты MCP, консоль, код сайта, `PATCH`, действия публикации): в «последней записи побеждает» остаются только они, а также открытый редактор старой сборки админки и одно сохранение при закрытии вкладки: оно уходит без проверки, если в этот момент ещё летит предыдущее автосохранение (после него версия другая, а выбирать уже некому), так что в узком окне закрытия оно может перезаписать чужую правку. Если же в этот момент ничего не летит, сохранение при закрытии проверяется как обычно, и когда сервер его отклонит, спросить уже некого: чужая правка цела, а последние правки закрытой вкладки не сохранятся. Таблица тарифов проверяется так же, как запись коллекции. Из редактора или формы нельзя уйти (в список, к другой записи, в другой раздел, на другой язык) и нельзя опубликовать, пока человек не выбрал, что делать с правками после `409`; при закрытии вкладки или полном переходе браузер в это время спрашивает подтверждение. **Имя поля `version` у записи коллекции зарезервировано:** ответ записи всегда несёт служебный `version`, и одноимённое поле сайта он перекроет (автосохранение записало бы в него хеш). Назовите такое поле иначе: `itk:schema:lint` и `itk:doctor` называют ключ `version` у поля коллекции ошибкой. То же относится к `id`, `locale`, `translated`, `status`, `updatedAt`, `awaiting`, `returned`, `changed`, `changes`.
- Тело не больше `itk_cms.mcp.max_body_mb` (8 МБ); для `media_upload` нужны соответствующие `client_max_body_size` веб-сервера (по умолчанию в nginx 1 МБ) и `post_max_size` в php.ini (по умолчанию 8 МБ): выше любого из них тело может дойти пустым, и ассистент получит ошибку разбора JSON.
- Заголовок `Authorization` должен доходить до PHP (nginx + php-fpm передают его сами; на Apache — `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`).
- **Файрвол `itk_mcp` обязателен** (раздел «Установка в сайт», шаг 4: `security.yaml`). Без него никто не проверил ключ, и `McpController` не делает вид, что ключ неверный: он отвечает `500` с кодом `mcp_firewall_not_configured` и подсказкой про `security.yaml` и пишет `error` в канал `itk_mcp`. Пока MCP включён, строка «ИИ-ассистенты (MCP)» в «Разработчику → Состояние сайта» предупреждает об этом заранее: она ищет файрвол, под который попадает `/mcp` и в котором есть `ApiTokenAuthenticator` (имя файрвола может быть любым).
- Ядро вызывает инструмент только от имени владельца ключа: если в `security.token_storage` не он, `McpServer` отвечает внутренней ошибкой, а не выполняет вызов от имени «системы». Так же проходит и чтение ресурса.
- `itk:mcp` (stdio) живёт долго: если база закрыла соединение (перезапуск, `wait_timeout`), останавливается и отвечает ошибкой (`1053 Server shutdown in progress`; так же считаются `2013` и всё с SQLSTATE класса `08`, DBAL их соединением не называет) или ещё не принимает новых соединений (она поднимается после перезапуска), проверка ключа закрывает соединение у себя и пробует снова (первая повторная попытка сразу, дальше с паузой в секунду, до 20 попыток, то есть около 20 секунд), и только не вернувшаяся база останавливает программу сообщением в stderr и кодом 1. Менеджер сущностей, закрытый неудачной записью, заменяется новым перед следующим сообщением. Отказ ключа в stdio (отозван, истёк, заблокирован) пишется и в stderr, и в канал `itk_mcp` строкой «MCP sign-in refused» с `transport=stdio`.

```yaml
# config/packages/itk_cms.yaml
itk_cms:
    mcp:
        allowed_origins: []     # Origin, которые принимаются от программ, присылающих этот заголовок, например https://inspector.example (это не CORS)
        max_body_mb: 8
```

### Своя библиотека или `mcp/sdk`

Проверено и выбрано собственное небольшое ядро (`Itk\Cms\Mcp\McpServer`, около 300 строк). Что смотрели: `mcp/sdk` 0.9.0 и `symfony/mcp-bundle` 0.15.0 (оба до 1.0, API меняется между минорными версиями) ставятся на PHP 8.3 / Symfony 7.4 без конфликтов и умеют stateless HTTP. Против: на сайт добавляются 12 пакетов (PSR-7/15/17, `php-http/discovery`, `opis/json-schema`, `symfony/psr-http-message-bridge`) и ещё нужна реализация PSR-7; построены вокруг статического списка инструментов, найденных атрибутами, и сессий, а нам нужен набор инструментов, который зависит от ключа и от прав человека на каждый запрос, и бандл, который клиентские сайты обновляют через `composer update` без сюрпризов. Нужная часть протокола мала: `initialize` (с выбором версии), `ping`, `tools/list`, `tools/call`, `resources/list` и `resources/read`, коды ошибок JSON-RPC. Остальное ядро знает только `McpToolInterface`, поэтому заменить реализацию на SDK можно, не трогая инструменты.

### Свой инструмент

```php
final class ToursCountTool extends AbstractMcpTool   // или просто implements McpToolInterface
{
    public function __construct(private readonly TourRepository $tours) {}   // сервис: подхватывается сам (тег itk.mcp_tool)

    public function name(): string { return 'tours_count'; }
    public function description(): string { return 'Count tours by state.'; }          // для модели: по-английски
    public function inputSchema(): array { return self::schema(['state' => self::string('draft or published', 20)], ['state']); }
    public function requiredScope(): string { return McpScopes::READ; }                   // READ | WRITE | PUBLISH | REQUESTS
    public function annotations(): array { return ['readOnly' => true, 'destructive' => false, 'idempotent' => true]; }   // обязательно: значения по умолчанию нет, инструмент без него не попадёт в набор (см. ниже)

    public function call(array $args, McpContext $context): array
    {
        $context->require('col:tours', 'view');                                            // права человека, как в админке
        return ['count' => $this->tours->countByState((string) $args['state'])];
    }
}
```

Класс без аргументов конструктора можно назвать в расширении: `$registry->mcpTools(ToursCountTool::class)`. Набор инструментов проверяется при первой сборке (`McpToolRegistry`) и при ошибке бросает `McpToolListError` (наследник `LogicException`) с именами классов; клиент MCP получает `Internal error`, а текст с именами пишется строкой `error` «MCP tools are misconfigured» в канал `itk_mcp`. Ошибки такие: **два класса с одним именем инструмента** (инструмент сайта не подменяет инструмент ядра, молча как раньше; один класс, подключённый и сервисом, и через расширение, считается одним), **область, которой нет** в `McpScopes::ALL`, и `annotations()` без булевых `readOnly` и `destructive`. Класс, который наследует `AbstractMcpTool` и не объявляет `annotations()`, не загрузится (фатальная ошибка PHP): добавьте метод. Аргументы проверяются по `inputSchema()` до вызова (поддерживаются `type`, `enum`, `required`, `properties`, `additionalProperties`, `items`, `minimum` / `maximum`, `minLength` / `maxLength`, `minItems` / `maxItems`). Бросайте `McpToolException` с понятным текстом, если ввод нужно поправить. Инструмент, который показывает только части сайта, добавляет `McpConditionalTool::isAvailableTo()`. Пишущий инструмент должен ходить через сервисы ядра (`PageService`, `CollectionService` …), а не в базу напрямую: тогда права, хуки и история работают сами, а правка попадёт в историю как «через ИИ-ассистента».

### Безопасность: что помнить

- Всё, что инструмент возвращает из сайта (тексты страниц, названия файлов, сообщения посетителей в заявках), для модели **данные, а не инструкции**. Тексты посетителей (`requests_list`, `request_get`, `request_update`: имя, телефон, город, сообщение, адрес страницы и все значения `data`) приходят в явной рамке `<untrusted-data>…</untrusted-data>` (`Format::untrusted()`); `data` обрезается так же, как остальное (значение до 500 знаков, сообщение до 2000, не больше 50 значений), метка внутри самого текста обезвреживается, поэтому текст не закроет свою рамку. Рамка — подсказка модели, а не защита: настоящая защита в том, что ключ с `requests` не получает `write` и `publish`, а сырой HTML через ключ не пишется. В `instructions` ответа `initialize` это сказано прямо, но защищает только осторожность клиента: давайте ключ с минимальными доступами и срок, не включайте `requests` без нужды, а публикацию — только тем, кто готов отвечать за её результат.
- Черновики сначала: `write` ничего не выкладывает на сайт, а `publish` — отдельный доступ. История подскажет, что сделал ассистент.
- Ключ равен паролю на своих правах: хранить в менеджере паролей, не присылать в чат, при утечке отозвать.

## Заголовки безопасности админки

Все ответы админки (оболочка, вход, `/admin/api`, предпросмотр; админка — это маршруты `itk_admin_*` и `itk_api_*` где бы они ни стояли, плюс всё под адресом `itk_cms.admin_path`) получают `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'`, `X-Content-Type-Options: nosniff` и `Referrer-Policy: same-origin` (`Security\AdminHeadersListener`), чтобы админку нельзя было вставить во фрейм чужого сайта. Предпросмотр лежит на том же адресе, поэтому в админке продолжает работать. Заголовок, который контроллер выставил сам, не перезаписывается. Для публичных маршрутов бандла `nosniff` и `Referrer-Policy` выставляет `Security\PublicHeadersListener` (раздел «Публичные страницы: заголовки и 304»); остальные заголовки сайта (`/`, `/uploads`) — дело веб-сервера.

## Оформление админки

Цвета и скругления панели управления. Разработчик задаёт стандартный вид в конфиге, администратор может изменить его в «Настройки → Оформление админки».

```yaml
# config/packages/itk_cms.yaml
itk_cms:
    admin:
        theme:
            brand: '#0B5D80'     # основной цвет: левая колонка, главные кнопки, ссылки
            accent: '#C8217A'    # акцент: пометки несохранённых изменений, счётчики
            radius: 6            # скругление кнопок и полей, px (у карточек на 4 больше)
            logo: /build/logo.svg  # свой знак в левой колонке и на странице входа; null — знак ITK CMS
            name: Daisy Studio   # название рядом со знаком в левой колонке (до 40 символов, одна строка); null — «ITK CMS»; можно взять из окружения: '%env(ITK_ADMIN_NAME)%' (значение проверяется при чтении, пустое или неподходящее — как будто опции нет)
```

- **Палитра.** Из цвета считается палитра 50–950 в OKLCH (`Admin\Theme\Palette`): оттенок сохраняется, светлота идёт по фиксированной лестнице, насыщенность следует цвету. Ступень, ближайшая по светлоте, — сам выбранный цвет. Стандартные `#0B5D80` и `#C8217A` оставляют вид админки таким, как он нарисован.
- **Контраст.** Цвет, на котором не читается ни белый, ни тёмный текст (лучший контраст ниже 4,5 : 1, уровень AA), сохранить нельзя (422 с пояснением). Цвет текста на нём выбирается сам (`--brand-ink`); если цвет как надпись на белом не читается, для надписей и ссылок берётся его более тёмный вариант (`--brand-text`).
- **Хранение.** Настройка `adminTheme` (`{ brand, accent, radius }`, общая для всех языков, таблица `itk_setting`). Если сохранён вид, равный стандартному, настройка удаляется. «Сбросить» возвращает конфиг разработчика. Право: область `set:theme` («Оформление админки»), у встроенной роли «Администратор» есть.
- **Без мигания.** Оболочка `/admin` и страница входа вставляют `<style>` с переменными `:root` после стилей сборки, до первой отрисовки; те же значения лежат в `window.ITK_ADMIN.theme = { brand, accent, radius, logo, custom, vars, name }`. После сохранения админка выставляет переменные на открытой странице сама, без перезагрузки. Тег `theme-color` и значок вкладки берут основной цвет.
- **Переменные.** Для стилей плагинов: `--brand`, `--brand-ink` (текст на нём), `--brand-text`, `--brand-50 … --brand-950`, `--accent`, `--accent-ink`, `--accent-text`, `--accent-50 … --accent-950`, `--r`, `--r-lg`; короткие имена, с которыми написаны стили админки: `--cy*`, `--mag*`, `--rail-ink*`.
- **API.** `GET admin-theme`, `POST admin-theme/preview {brand?, accent?, radius?}` (ничего не сохраняет), `PUT admin-theme`, `DELETE admin-theme`; ответ — `{ theme, custom, defaults, vars, checks, ladder }`.

## Язык админки

Админка, её письма, консоль и сообщения API переведены на три языка: английский (`en`), русский (`ru`) и казахский (`kk`). Это язык интерфейса. Он не связан с языком содержимого: `main_locale` и языковые версии страниц задают, на каких языках написан сайт, а `admin_locale` задаёт, на каком языке сайтом управляют. Админка на английском спокойно ведёт русский сайт и наоборот.

```yaml
# config/packages/itk_cms.yaml
itk_cms:
    main_locale: en              # язык содержимого сайта (по умолчанию ru, это не язык админки)
    admin_locale: en             # язык админки по умолчанию (если не задать, будет en)
    admin_locales: [en, ru, kk]  # из чего человек может выбирать; по умолчанию все три
```

- `admin_locale` должен входить в `admin_locales`, иначе контейнер не соберётся. Чтобы админка на сайте осталась русской, как было до перевода, задайте `admin_locale: ru`.
- Каждый человек выбирает свой язык в «Мой профиль» (`PUT /admin/api/me/locale`, значение хранится в `itk_user.locale`; миграция бандла добавляет колонку). Пустой выбор означает «как у сайта», то есть `admin_locale`. Язык, который сайт убрал из `admin_locales`, считается пустым выбором. Страница перезагружается при смене языка.
- Кто на каком языке получает текст:

  | Где | Язык |
  |---|---|
  | админка, её API, предпросмотр, MCP | язык человека, иначе `admin_locale` |
  | страницы входа и восстановления пароля | `admin_locale` (человека ещё не знаем) |
  | письмо конкретному человеку (приглашение, смена пароля) | язык этого человека |
  | письма о заявках и сообщения в Telegram | `admin_locale`, их читают многие |
  | консоль и воркер очереди | `admin_locale`, а не `framework.default_locale` |
  | формы сайта (публичные маршруты бандла) | `main_locale`, если он есть в `admin_locales`, иначе `admin_locale` |
  | уведомления ядра, встроенные роли | язык читателя в момент показа |
  | холст предпросмотра страницы | язык сайта и `?locale=`, как её увидит посетитель; слова панели над секцией на языке человека |

- Сообщения API (`{ "error": "…", "details": … }`) идут на языке человека. Не разбирайте текст: он может измениться и зависит от языка. Интеграции ориентируются на HTTP-код и на `details.code`: `conflict`, `csrf`, `no_translation`, `link_gone`, `password_locked`, `mail_not_configured`, `mail_failed`, `mail_queue`, `mail_test_limit`, `no_public_url`, `2fa_code`, `2fa_enrol`, `2fa_locked`. Поле ошибки формы — `details.<поле>`; у переадресаций `details.code` — это ошибка поля `code`, а не код ответа.
- Бандл включает `framework.translator`, если сайт его не выключил, и в конец списка `fallbacks` дописывает `en`. В консоли переводчик работает на `admin_locale`: если код сайта вызывает там `trans()` без языка, передайте язык явно.

**Тексты в PHP.** Каталоги лежат в `translations/itk_<область>.<язык>.yaml`: плоские ключи вида `область.ключ`, значения в одинарных кавычках, параметры `{name}`. Доменом служит `itk_<область>`, он берётся из первого сегмента ключа. Вызов: `Itk\Cms\I18n\I18n::t('i18n.notification_telegram_failed', ['id' => 3])`. Это статический фасад, он работает в статических методах, в исключениях и в классах без контейнера. Множественное число задаётся параметром `count` и формами через `|` в порядке Symfony: русский `{count} страница|{count} страницы|{count} страниц`, английский `{count} page|{count} pages`, казахский повторяет форму (`{count} бет|{count} бет`), потому что после числа стоит единственное число. Ключ, которого нет в каталоге, не ошибка: фасад отвечает самим ключом и пишет предупреждение в журнал. В Twig есть `itk_t('область.ключ', {name: x})` и `itk_locale()` (язык для `<html lang>` своих страниц входа и писем).

**Переопределение с сайта.** Файлом сайта переопределяются только серверные тексты: ответы API и MCP, письма, консоль, страницы входа и восстановления пароля, панель предпросмотра, названия встроенных ролей и уведомлений. Это шесть областей: `content`, `http`, `i18n`, `model`, `ops`, `preview`. Положите в `translations/` сайта файл с тем же именем, например `translations/itk_content.en.yaml`, и перечислите в нём только изменённые ключи: остальные тексты области останутся бандла. Так можно поправить фразу сервера или добавить свою формулировку, не копируя каталог целиком. Если у сайта свой набор `framework.translator.fallbacks`, ключ без перевода берётся из них, потом из английского. Тексты самой админки на Vue этим файлом не меняются: их читает не PHP, они лежат в сборке (следующий абзац).

**Админка и плагины.** Тексты Vue-части лежат в `admin/src/i18n/<язык>/<область>.json` и собираются в сборку (по чанку на язык). Плагин сайта получает `window.ITK.i18n` (`t`, `locale`, `add`) и добавляет свои тексты вызовом `add('ru', { 'reports.title': 'Отчёты' })`; заготовка `itk:make:plugin` уже делает это. Язык админки плагин не выбирает, он читает `ITK.i18n.locale`. Чтобы поправить фразу самой админки, вызовите `ITK.i18n.add` из плагина сайта с тем же ключом: замена действует только для вызовов `t()` после старта админки, а константы модулей (меню, схемы) к этому моменту уже вычислены и остаются прежними. Подробности для разработчика: [admin/src/README.md](https://github.com/it-koval/cms/blob/main/admin/src/README.md).

**Известное ограничение: история.** Подпись изменений в ревизии («что изменилось») записывается в базу на языке человека, который сохранил правку, и показывается как есть, без перевода. Читатель с другим языком админки видит старые ревизии на языке их автора; новые ревизии пишутся на языке нового автора. Сами значения полей, как всегда, остаются на языке содержимого.

**Как добавить язык.** Языки встроены в сборку, сайт сам их не регистрирует:

1. Добавьте язык в `I18n::LOCALES` (`src/I18n/I18n.php`, название языка на нём самом) и в `BUILT_IN` в `admin/src/i18n/index.js` (этот список нужен админке без оболочки сервера: `npm run dev`). Туда же: `LOCALES` в `admin/scripts/check-i18n.mjs` (без этого проверка не сверяет каталоги нового языка), `UI_LOCALES` в `admin/src/mock/handler.js` и тип `locale` в `admin/types/itk.d.ts`.
2. Создайте `admin/src/i18n/<язык>/*.json` для каждой области (с теми же ключами, что в `en`) и `translations/itk_<область>.<язык>.yaml`. Формы множественного числа для языка задают `JS_PLURALS` и `PHP_PLURALS` в `admin/scripts/check-i18n.mjs` и `FORMS` в `tests/Core/I18nCatalogTest.php`.
3. Запустите проверки: `npm run check:i18n` (из `admin/`) и `composer test`.

**Проверки.** `npm run check:i18n` (из `admin/`, режим `--strict`) сверяет все каталоги: набор ключей и параметров `{x}` одинаков в трёх языках, у ключа с числом есть все нужные формы, нет пустых значений, каждый ключ из `t()`, `I18n::t()` и `itk_t()` есть в каталоге, ключ, которого никто не вызывает, считается ошибкой, а кириллицы вне комментариев в коде нет. В `composer test` входят `I18nCatalogTest` (те же правила для YAML) и `I18nCyrillicTest` (кириллица в PHP и Twig бандла). Русский текст, который должен остаться в коде, помечается комментарием `i18n-ignore` в той же строке.

## Разработка самой CMS

Админка (Vue 3) собирается из `admin/` в репозитории и кладётся в пакет: сборка `packages/cms/public/admin` хранится в git, поэтому сайту собирать её не нужно (установка, шаг 10). Меняете админку — пересоберите:

```bash
cd admin
npm install
npm run build          # кладёт сборку в packages/cms/public/admin (Vite, манифест .vite/manifest.json)
```

Сайт, подключённый через `path` с `symlink`, увидит новую сборку после `bin/console assets:install`. Тесты бандла — следующий раздел.

## Тесты бандла

Бандл проверяется сам по себе: в `tests/` лежит тестовый сайт (`tests/App`: ядро `TestKernel`, `config/schema.php`, шаблоны и классы-ресурсы) и тесты ядра (`tests/Core`, пространство имён `Itk\Cms\Tests`). Нужны PHP 8.3+ и PostgreSQL 16+ (тесты идут на 17).

```bash
cd packages/cms
composer install
docker run -d --name itk-test-pg -p 127.0.0.1:33960:5432 -e POSTGRES_PASSWORD=itk-test pgvector/pgvector:pg17
composer test          # lint:sql, затем база itk_test: drop, create, migrate, itk:install, затем phpunit
```

- `DATABASE_URL` берётся из окружения, по умолчанию `postgresql://postgres:itk-test@127.0.0.1:33960/itk?serverVersion=17&charset=utf8` (тесты работают с базой `itk_test`: к имени добавляется `_test`). Своя строка тоже должна содержать `serverVersion`: без него Doctrine подключается к ещё не созданной базе, чтобы узнать версию сервера, и `doctrine:database:create` падает.
- `composer lint:sql` (`scripts/lint-sql.php`) ищет в строковых литералах `src/` конструкции MySQL: `JSON_EXTRACT`, `ON DUPLICATE KEY`, `CRC32`, `IFNULL`, `GET_LOCK`, `ENGINE=`, `utf8mb4`, `AUTO_INCREMENT`, обратные кавычки и другие. Любая находка роняет `composer test`; список правил лежит в самом скрипте.
- `ITK_TEST_CACHE_DIR` переносит кэш контейнеров тестового сайта из `tests/App/var/cache` в свой каталог. Нужен, когда на одном рабочем дереве одновременно идут несколько прогонов: у каждого свой каталог и своя база, и один прогон не снесёт кэш под другим.
- Почтовые тесты идут через Mailpit: `ITK_TEST_MAILPIT_URL=http://127.0.0.1:33952` (веб и API) и, если SMTP не на порту 1025, `ITK_TEST_MAILPIT_SMTP_PORT=33951`. Без `ITK_TEST_MAILPIT_URL` они пропускаются, с ним — выполняются, и недоступный Mailpit считается ошибкой.
- Админка на другом месте проверяется тестами `AdminPathOptionTest` (`itk_cms.admin_path: /cp`) и `AdminRoutePrefixTest` (импорт маршрутов с `prefix: /cp`): `TestKernel` принимает место админки конструктором, файрвол и `access_control` тестового сайта идут за ним.
- `TestKernel` принимает и ключи конфигурации `itk_cms` (`['strict' => false]`, `['schema_file' => …]`, `['blog' => false]`): так проверяются строгий режим, `itk:schema:lint`, блог, `public_url` из переменной окружения. Тестовый сайт ведёт журнал (`Tests\App\RecordingLogger`) и считает запросы к базе (`Tests\App\QueryCounter::during()`), чтобы тест мог сказать «запросов не больше трёх, сколько бы ни было страниц».
- `TestKernel` принимает и блоки конфигурации `framework` (сайт без транспортов Messenger, сайт с `chatter_transports`) и пути `doctrine_migrations`: так проверяются чистая установка, необязательный Telegram и порядок путей миграций.
- **Язык интерфейса.** Тестовый сайт работает с `admin_locale: ru` и `framework.default_locale: en`, поэтому тесты проверяют русские тексты, а язык, оставленный без выбора, виден сразу. `I18nCatalogTest` проверяет каталоги `translations/`, `I18nCyrillicTest` ищет кириллицу в PHP и Twig вне комментариев, `I18nLocaleTest`, `I18nFacadeTest` и `I18nSiteConfigTest` проверяют, кто какой язык получает. Каталоги админки проверяет `npm run check:i18n` (раздел «Язык админки»).
- **Symfony 8.** Ограничения `symfony/*` — `^7.4 || ^8.0`, `doctrine/doctrine-bundle` — `^2.13 || ^3.0`, `doctrine/doctrine-migrations-bundle` — `^3.3 || ^4.0`. Подписи `PermissionVoter::voteOnAttribute()` и `UserChecker::checkPostAuth()` имеют необязательные параметры (`?Vote $vote = null`, `?TokenInterface $token = null`), которые 8-я версия делает частью интерфейса, поэтому классы годятся для обеих. Проверено на Symfony 8.1 в отдельной копии: `composer update` ставит Symfony 8.1, DoctrineBundle 3 и MigrationsBundle 4, все тесты проходят (`TestKernel` сам пропускает опции DoctrineBundle 2, которых нет в 3).
- **Статический анализ.** `composer stan` — PHPStan, уровень 5 (`phpstan.dist.neon`, `treatPhpDocTypesAsCertain: false`: типы в докблоках здесь подсказки, а не факты, потому что данные приходят из JSON-колонок). То, что PHPStan находит по делу, исправлено; два ложных срабатывания лежат в `phpstan-baseline.neon` с объяснением в `phpstan.dist.neon`.
- Порядок тестов не важен: `composer test:run -- --order-by=random` (после `composer test:prepare`). Telegram и `TELEGRAM_DSN` тестам не нужны.
- **Комплект Docker.** `tests/docker/smoke.sh` проверяет образ из `docker/` (расширения PHP из `composer.json`, Node 22, `crittr`, Chromium и `node/critical-css.mjs` на одной странице в двух окнах). Его запускает job `docker` в CI; локально: `docker build -f docker/Dockerfile --target php_dev --build-arg CRITICAL_CSS=1 -t itk-docker:dev .`, затем `docker run --rm -v "$PWD/../..:/repo:ro" itk-docker:dev bash /repo/packages/cms/tests/docker/smoke.sh`.

## Что в бандле

- `Field\` — поля-объекты (`Text::make()` …), `FilterKind`; `Schema\` — билдер и описания схемы, экспорт для админки `Schema::toAdminArray($viewer)`.
- `Admin\Theme\` — `AdminTheme` (вид админки: конфиг, настройка `adminTheme`, CSS-переменные), `Palette`, `Color` (OKLCH, контраст).
- `Resource\` — классы-ресурсы, `Action`, `Metric`, `Record`, `Context`, `HookDispatcher`, `ActionRunner`; `Event\` — события записей; `Attribute\` — атрибуты меню; `Extension\` — `ItkExtensionInterface`, `Registry`, `NavItem`; `Admin\NavBuilder` — меню админки; `Settings\Settings` — настройки для кода; `Doctrine\` — DQL-функции JSON.
- `Entity\`, `Repository\`, `Migrations\` — таблицы `itk_*`.
- `History\` — `HistoryService` (запись истории), `Snapshots` (вид версий и список изменений), `HistoryAdmin` (чтение, возврат версии, корзина для API).
- `Content\` — `PageService`, `CollectionService`, `CollectionQuery` (список с фильтрами), `FieldAccess` (права на поля), `SettingsService`, `MenuService`, `RedirectService`, `SeoResolver`, `SeoRuleService`, `Listing`, `ChangeDetector`, `HtmlSanitizer`, `FieldValues`, `H1Rule`, `Placement`. Ошибки — `ContentException` (HTTP-код в `status()`).
- `Rendering\{PageRenderer, PageValidators}` (ETag и 304), `Rendering\PageCache` (кэш готовых страниц; публичен его метод `clear()`, раздел «Кэш страниц»), `Controller\Site\{PageController, RequestSubmitController, ImageVariantController, SitemapController, CrawlerFilesController, HealthzController}`, `Twig\{ItkExtension, ItkRuntime, SeoRuntime}`.
- `KnowledgeBase\` — база знаний на pgvector (раздел «База знаний»): `KnowledgeSearch` (публичный поиск по смыслу), `Indexer` (сторона воркера, единственный писатель таблиц), `SourceReader` (какие версии индексируются), `Extractor` и `Chunker` (текст из снимка и фрагменты), `QueueOnContentChange` и `SyncKnowledgeHandler` (событие, сообщение `SyncKnowledge`, пакетный обработчик), `Embedding\*` (граница эмбеддера, `OpenAiEmbedder`), `Model\*`, `Doctrine\VectorType`, `Command\{KnowledgeBaseSyncCommand, KnowledgeBaseSearchCommand}`, `Health\KnowledgeBaseHealthCheck`.
- `CriticalCss\` — критический CSS живых страниц (раздел «Критический CSS»): `Stylesheets` (список таблиц стилей и конфигурация генератора), `CriticalCssService` (сторона запроса: основа, свежесть, заказ воркеру), `CriticalCssGenerator` (сторона воркера, единственный писатель таблицы), `NodeCssExtractor` и `node/critical-css.mjs` (граница с Node и crittr), `Model\*` (значения и сообщение `GenerateCriticalCss`), `Command\GenerateCriticalCssCommand`, `Health\CriticalCssHealthCheck`.
- `Content\ContentReader` — публичное чтение для сайта (`itk_pages`, `itk_page`, `itk_collection`); `Schema\{Key, SchemaLint}` — формат ключа и проверка схемы; `Health\{Doctor, LivenessProbe}`, `Command\{DoctorCommand, SchemaLintCommand}`; `Security\PublicHeadersListener`.
- `Request\` — `RequestService`, `TelegramNotifier`, сообщение и обработчик Messenger.
- `Notification\` — `NotifierInterface`/`Notifier`, `Notification`, `NotificationChannelInterface`, каналы `Channel\{DatabaseChannel,TelegramChannel,EmailChannel}`, `NotificationInbox` (колокольчик со стороны читателя), `Audience`.
- `Mail\` — `MailSettings` («Настройки → Почта», пароль зашифрован), `MailerFactory` (откуда берётся сервер, транспорт в момент отправки), `DsnBuilder`, `SiteMailer` (в очередь / сразу), `SendMail` + `SendMailHandler`, `MailComposer` (письма из `@ItkCms/email/`), `PublicUrl`, `MailStatus`, `MailFailureListener`, `MailHealthCheck`.
- `Security\` — `RoleResolver`, `PermissionVoter`, `Access`, `Viewer`, `AdminUserProvider`, `UserChecker`, `RoleSeeder`, `UserManager`, `PasswordRecovery` (ссылки «задать пароль»); `Security\TwoFactor\` — `Totp`, `SecretBox`, `TwoFactorService`, `TwoFactorGate`.
- `Media\MediaService` — загрузка в `public/uploads/media/Y/m/`, размеры, использование файла, папка, точка фокуса и кадры; `Media\MediaFolderService` — папки; `Media\ImageVariants` — адреса и создание уменьшенных WebP- и AVIF-копий (в том числе кадров по пропорции); `Media\GenerateImageVariants` — сообщение Messenger «создать копии после загрузки».
- `Mcp\` — MCP-сервер для ИИ-ассистентов: `McpServer`, `McpToolInterface` и инструменты `Mcp\Tool\*`, `ApiTokenService`, `ApiTokenAuthenticator`, `McpGate`, `McpStdio` (раздел «MCP»); `Controller\Site\McpController` — `POST /mcp`; `Controller\Admin\{McpAdminController, MyTokensController}` — настройки и «Мои токены».
- `I18n\` — язык интерфейса: фасад `I18n`, `AdminLocale` (какой язык у кого), `AdminLocaleListener`; каталоги лежат в `translations/` (раздел «Язык админки»).
- `Command\` — консольные команды (в том числе `itk:mcp`).

Совместимость: публичными считаются API схемы (`Schema\SchemaBuilder`, `Field`, `Template`, `SectionType`, `Collection`, `SettingsGroup`, `FormDef`), поля `Field\*`, классы-ресурсы, `Action`/`Metric`, события, `ItkExtensionInterface`/`Registry`, `Settings`, Twig-функции, имена маршрутов и опции `itk_cms`, имена консольных команд. Остальное может меняться между минорными версиями.

Что бандл **не обещает**, помечено `@internal` в докблоке класса (PHPStan и IDE предупредят при использовании): `Repository\*`, `Controller\*`, `Generator\*`, `Rendering\*` (кроме `PageCache::clear()`), `Mcp\Tool\*` и служебные сервисы (`Admin\NavBuilder`, `Admin\Theme\AdminFont`, `I18n\AdminLocaleListener`; `Content\{ChangeDescriber, ChangeDetector, DraftVersion, EditLock, FieldValues, H1Rule, Placement, SeoAddressCheck, SitemapInvalidator, CrawlerFiles, IndexablePages, TreeMove, LiveUrlIndex}`; `Health\{Doctor, LivenessProbe, MessengerMonitor, UpdateChecker}` и проверки ядра; `History\{HistoryService, Snapshots}`; `Mail\{MailComposer, MailFailureListener, MailHealthCheck, SendMailHandler}`; `Mcp\{McpServer, McpGate, McpStdio, McpToolRegistry, McpContextFactory, McpFirewallHealthCheck, ArgumentChecker, McpProtocolError}`; `Media\{MediaFolderService, GenerateImageVariantsHandler}`; `CriticalCss\*` кроме `Model\GenerateCriticalCss` (его направляют в `messenger.yaml`); `KnowledgeBase\*` кроме `KnowledgeSearch`, типов результата и `Embedding\*` (раздел «База знаний»); слушатели `Security\*` и `Twig\{ItkExtension, ItkRuntime, SeoRuntime}`). Без метки остаются то, что сайт подключает в `security.yaml`, `messenger.yaml` и своём коде: `Entity\User`, `Security\{AdminUserProvider, UserChecker, UserManager, Viewer, PermissionVoter, Access}`, `Mcp\{ApiTokenAuthenticator, McpToolInterface, AbstractMcpTool, McpContext, McpScopes}`, `Request\SendRequestNotification`, сервисы контента (`PageService`, `CollectionService`, `SettingsService`, `MenuService` …). Сайту нужны данные для страниц — берите `ContentReader` и Twig-функции, а не репозитории.

## Админка: вход, API, предпросмотр

Адреса ниже даны для `itk_cms.admin_path: /admin` (по умолчанию). Единый источник места админки — сервис `Admin\AdminPath`: он отвечает, где стоит админка (`base()`, `to('/api/me')` спрашивают у роутера, поэтому учитывают и `prefix` импорта), и признаёт запрос запросом админки по имени маршрута. Письма (ссылка «задать пароль», адрес входа, «Открыть заявку»), адрес скачивания действий и адреса в `window.ITK_ADMIN` строятся им же; клиент админки своих адресов не знает (`adminBase`, `apiBase`, `previewBase`, `loginUrl`, `logoutUrl` приходят с сервера, роутер клиента хэшевый и работает под любым адресом).

- `/admin` — оболочка SPA (`AdminAppController`), собранные файлы берутся из `/bundles/itkcms/admin/` по манифесту Vite (`.vite/manifest.json`); `window.ITK_ADMIN = { adminBase, apiBase, csrf, user, site: { name, domain }, mainLocale, siteTexts: { item, section }, locale, locales: [{ code, name }], previewBase, loginUrl, logoutUrl, assetsBase, images: { uploadUrl, variantDir, widths }, theme }`. `locale` — язык интерфейса этого человека, `locales` — из чего он может выбирать (каждый язык назван на себе самом), а `mainLocale` — язык содержимого сайта; это разные вещи. `siteTexts` — заготовки названий для нового элемента и новой секции, они сохраняются вместе с содержимым и поэтому идут на языке содержимого. `theme` — вид админки (раздел «Оформление админки»). `images` — схема уменьшенных копий (раздел «Картинки»): админка показывает все превью картинок через них (`admin/src/utils/media.js`, `thumbUrl(src, width)`), а сохранённые значения остаются адресами оригиналов.
- Кроме этого API админки: `GET schema` (+ `nav`, поля и действия только для этого человека), `GET collections/{col}` с `page`/`sort`/`filter` (конверт), `POST resources/{col}/actions/{действие}`, `GET downloads/{token}`, `GET|PUT|DELETE prefs/{ключ}` — см. разделы «Действия», «Фильтры», «Меню админки».
- `/admin/forgot-password`, `/admin/reset-password/{token}` — «Забыли пароль?» и новый пароль по ссылке из письма (раздел «Почта»).
- `/admin/login`, `/admin/logout` — форма входа (`form_login` с `enable_csrf`, поля `_username`, `_password`, `_csrf_token`). `/admin/login/otp` — второй шаг входа (код из приложения), см. «Двухэтапный вход».
- `/admin/api/*` — JSON API. Все изменяющие вызовы несут заголовок `X-CSRF-Token` (значение из `window.ITK_ADMIN.csrf`); проверка идёт по маршруту (`itk_api_*`), а не по строке адреса, и работает при любом `itk_cms.admin_path` и `prefix` импорта. Ошибки: `{ "error": "…", "details"?: … }` с кодом 401 / 403 / 404 / 409 / 422. Права проверяются на сервере в каждом вызове (`Access::require(area, action, template)`).
- `/admin/preview/{id}?locale=ru` — черновик страницы настоящими шаблонами сайта; скрипт `preview.js` общается с админкой через `postMessage`: страница шлёт `itk:ready`, `itk:focus {sec, path}`, `itk:action {sec, act}`, админка — `itk:select {sec, path?}`, `itk:reload`.
