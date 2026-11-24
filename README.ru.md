[English](README.md) | Русский

# ITK CMS

ITK CMS это бандл для Symfony, который добавляет сайту на PHP систему управления содержимым и админку для редакторов. Вы описываете структуру сайта в PHP и пишете Twig-шаблоны. Редакторы собирают страницы из секций, смотрят черновик и публикуют его в админке. Содержимое хранится в PostgreSQL, админка поставляется собранной, поэтому сайту не нужен Node.

Текущая версия 0.3.0. До версии 1.0.0 минорный релиз может менять публичный API. Его границы описаны в [API.ru.md](packages/cms/API.ru.md), правила совместимости и шаги перехода — в [UPGRADE.ru.md](UPGRADE.ru.md).

## Возможности

- Страницы из секций, шаблоны страниц с правилами размещения, черновики, предпросмотр и публикация.
- Коллекции с фильтрами, видами списка (таблица, карточки, календарь, доска, дерево), действиями и метриками.
- Меню, настройки сайта и медиатека с копиями в WebP и AVIF, кадрированием и точкой фокуса.
- Правила SEO для любого адреса, переадресации, `sitemap.xml`, `robots.txt`, `llms.txt` и разметка schema.org.
- Яндекс Метрика, Google Analytics, Яндекс Вебмастер и IndexNow.
- Заявки из форм с уведомлениями на почту, в Telegram и в MAX. Токены ботов задаются в админке.
- Пользователи, роли с правами по разделам, двухэтапный вход и восстановление пароля.
- История изменений с возвратом версии и корзина для удалённых страниц и записей.
- Кэш страниц и необязательный критический CSS для живых страниц.
- База знаний на pgvector и MCP-сервер для ИИ-ассистентов.
- Экран «Состояние сайта» и команда `itk:doctor`.
- Интерфейс админки на английском, русском и казахском.
- Комплект Docker и генераторы `itk:make:*`.

## Требования

- PHP 8.3 или новее с расширениями `ctype`, `dom`, `gd`, `json`, `mbstring`, `pdo_pgsql` и `sodium`. Расширения `exif` и `intl` необязательны. Ставьте свежий патч-релиз: экран «Состояние сайта» предупреждает, если в версии PHP нет известных исправлений безопасности.
- Symfony 7.4 (7.4.23 или новее) или 8.1 и новее. Symfony 8 требует PHP 8.4 или новее. Symfony 8.0 не поддерживается: срок его поддержки закончился.
- PostgreSQL 16 или новее с расширением [pgvector](https://github.com/pgvector/pgvector) версии 0.8 или новее. Миграция ядра выполняет `CREATE EXTENSION vector`, поэтому расширение нужно, даже если база знаний не используется. В образе Docker `pgvector/pgvector:pg17` оно уже есть.
- Composer.
- Node нужен только для сборки админки из исходников и для критического CSS.

## Быстрый старт

Пакет на Packagist называется `itk/cms`. Эти шаги подключают его к новому проекту на Symfony 7.4. Каждый шаг подробно описан в [быстром старте справочника бандла](packages/cms/README.ru.md#быстрый-старт).

1. Создайте проект и установите пакет:

   ```bash
   composer create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   composer config extra.symfony.docker false
   composer require itk/cms
   ```

   Строка `extra.symfony.docker false` не даёт Flex добавить свои файлы Docker. У бандла есть свой комплект Docker.

2. Задайте базу данных и секрет в `.env.local`:

   ```dotenv
   DATABASE_URL="postgresql://user:password@127.0.0.1:5432/mysite?serverVersion=17&charset=utf8"
   APP_SECRET=a-long-random-string
   ```

3. Подключите маршруты бандла в `config/routes/itk.yaml`:

   ```yaml
   itk_admin:
       resource: '@ItkCmsBundle/config/routes/admin.php'
   itk_site:
       resource: '@ItkCmsBundle/config/routes/site.php'
   ```

4. Замените `config/packages/security.yaml` файлом из раздела [Безопасность](packages/cms/README.ru.md#безопасность-securityyaml) справочника бандла.

5. Задайте язык содержимого в `config/packages/itk_cms.yaml`. По умолчанию `ru`:

   ```yaml
   itk_cms:
       main_locale: en
   ```

   Язык интерфейса админки задаёт отдельная опция `itk_cms.admin_locale`. По умолчанию `en`, можно `ru` или `kk`.

6. Создайте таблицы и первого администратора. `itk:install` спросит пароль.

   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   php bin/console assets:install
   ```

7. Запустите веб-сервер и воркер очереди, как показано в [быстром старте бандла](packages/cms/README.ru.md#быстрый-старт). Затем войдите на `/admin/login` и выполните `php bin/console itk:doctor`. `itk:install` создаёт главную страницу черновиком, поэтому `/` отвечает 404, пока вы её не опубликуете.

Дальше опишите сайт в `config/itk/schema.php` (шаблоны страниц, секции, коллекции, меню, настройки и формы) и добавьте Twig-шаблоны.

## Устройство репозитория

Это монорепозиторий:

- `packages/cms` содержит Composer-пакет `itk/cms` с бандлом, тестами, комплектом Docker и собранной админкой в `packages/cms/public/admin`. После успешного CI для того же коммита в `main` или теге `v*` рабочий процесс копирует каталог в [it-koval/cms-bundle](https://github.com/it-koval/cms-bundle).
- `admin` содержит исходники админки (Vue 3 и Vite). `npm run build` записывает сборку в `packages/cms/public/admin`.

## Разработка

Для работы над бандлом и админкой используйте Docker Compose. PHP, Composer, PostgreSQL, Mailpit и Node работают в контейнерах. См. [настройку окружения и проверки](CONTRIBUTING.ru.md).

## Подробнее

- [Справочник бандла](packages/cms/README.ru.md)
- [Контракт публичного API](packages/cms/API.ru.md)
- [Правила для ИИ-агента, который делает сайт](packages/cms/SITE-AGENTS.ru.md)
- [Участие в разработке](CONTRIBUTING.ru.md)
- [Кодекс поведения](CODE_OF_CONDUCT.ru.md)
- [Политика безопасности](SECURITY.ru.md)
- [Выкладка и откат](docs/DEPLOY.ru.md)
- [Обновление](UPGRADE.ru.md)
- [Журнал изменений](CHANGELOG.ru.md)

## Лицензия

ITK CMS распространяется по [лицензии MIT](LICENSE). У npm-пакетов, собранных в админку, свои лицензии, они перечислены в [THIRD_PARTY_NOTICES.txt](packages/cms/public/admin/THIRD_PARTY_NOTICES.txt).
