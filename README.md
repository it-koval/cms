# ITK CMS

Бандл для Symfony: добавляет сайту на PHP систему управления содержимым и админку для редакторов.

Вы описываете структуру сайта в PHP и пишете Twig-шаблоны. Редакторы собирают страницы из секций, смотрят черновик и публикуют в админке. В комплекте коллекции, меню, медиатека, SEO, переадресации, заявки, пользователи и роли, история изменений и необязательный MCP-сервер для ИИ-ассистентов.

**Статус: alpha.** Релизов ещё не было, API может меняться до версии 1.0.0.

## Что нужно

- PHP 8.3+ с расширениями `ctype`, `dom`, `gd`, `json`, `mbstring`, `pdo_pgsql`, `sodium`
- Composer
- PostgreSQL 16+ (другие базы не поддерживаются; образ `pgvector/pgvector:pg17` подходит сразу)

## Установка

Пакета пока нет на Packagist, поэтому он ставится из локального клона этого репозитория.

1. Создайте сайт и подключите бандл:

   ```bash
   composer create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   composer config repositories.itk path /path/to/this/repository/packages/cms
   composer require itk/cms:'*@dev'
   ```

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

4. Замените `config/packages/security.yaml`:

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

5. Создайте таблицы и первого администратора. `itk:install` спросит пароль:

   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   php bin/console assets:install
   ```

6. Запустите сайт и войдите на `http://127.0.0.1:8000/admin/login`:

   ```bash
   php -S 127.0.0.1:8000 -t public
   ```

   Главная страница создаётся черновиком. Опубликуйте её в админке, иначе `/` отвечает 404.

## Что дальше

- Опишите сайт в `config/itk/schema.php`: шаблоны страниц, секции, коллекции, меню, настройки и формы.
- Выберите язык админки в `config/packages/itk_cms.yaml`: `admin_locale: en` (по умолчанию), `ru` или `kk`; каждый человек может выбрать свой язык в «Мой профиль». Это язык интерфейса, он не связан с языком содержимого (`main_locale`). Чтобы админка осталась русской, задайте `admin_locale: ru`. Подробнее: раздел «Язык админки» в [справочнике](packages/cms/README.md).
- Запустите воркер очереди для писем и Telegram. Telegram необязателен.

  ```bash
  php bin/console messenger:consume async
  ```

- Проверьте настройку:

  ```bash
  php bin/console itk:doctor
  ```

## Подробнее

- [Полный справочник](packages/cms/README.md)
- [Как участвовать в разработке](CONTRIBUTING.md)

## Лицензия

[MIT](LICENSE). У npm-пакетов, собранных в админку, свои лицензии: см. [THIRD_PARTY_NOTICES.txt](packages/cms/public/admin/THIRD_PARTY_NOTICES.txt).
