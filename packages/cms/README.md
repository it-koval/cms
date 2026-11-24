English | [Русский](README.ru.md)

# ITK CMS (`itk/cms`)

`itk/cms` is a Symfony bundle that adds a content management system to a site. It provides pages built from sections, page templates, collections, menus, settings, a media library, SEO, redirects, form requests with notifications to Telegram, MAX and e-mail, users and roles, a JSON API, a Vue admin panel and an MCP server for AI assistants.

A site describes its structure in PHP (the `config/itk/schema.php` file or resource classes) and renders it with its own Twig templates. The bundle knows nothing about a specific site. The built admin panel ships inside the package, so the site does not need Node.

Version 0.2.2, MIT license. Source code: [github.com/it-koval/cms](https://github.com/it-koval/cms).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Core concepts](#core-concepts)
- [Building the site](#building-the-site)
- [Fields](#fields)
- [Resource classes](#resource-classes)
- [Admin panel](#admin-panel)
- [SEO](#seo)
- [Requests and notifications](#requests-and-notifications)
- [Knowledge base](#knowledge-base)
- [Performance](#performance)
- [MCP: AI assistants](#mcp-ai-assistants)
- [Site status and itk:doctor](#site-status-and-itkdoctor)
- [Console commands](#console-commands)
- [Configuration reference](#configuration-reference)
- [Extensions and generators](#extensions-and-generators)
- [Docker](#docker)
- [AI agent in the site project](#ai-agent-in-the-site-project)
- [Public API and compatibility](#public-api-and-compatibility)
- [Developing and testing the bundle](#developing-and-testing-the-bundle)
- [Other documents](#other-documents)

## Requirements

| What | Version |
|---|---|
| PHP | 8.3 or newer. Extensions `ctype`, `dom`, `gd` with WebP, `json`, `mbstring`, `pdo_pgsql`, `sodium`. Optional: `exif` (rotates JPEG by EXIF) and `intl` (Cyrillic domains in the page cache) |
| Symfony | 7.4 or 8 (Symfony 8 requires PHP 8.4) |
| Doctrine | ORM 3, DBAL 4 |
| PostgreSQL | 16 or newer with the pgvector extension 0.8 or newer |
| Node | 22 or newer, only for the optional critical CSS. The admin panel ships prebuilt. Building it from source uses Node 24 (see [CONTRIBUTING.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.md)) |

Composer requires only PHP 8.3. The **Site status** screen warns when the PHP patch version is below the version with known CVE fixes.

**pgvector is mandatory.** A core migration runs `CREATE EXTENSION IF NOT EXISTS vector` and stops with an explanation if the extension is missing or older than 0.8. The `pgvector/pgvector:pg17` image includes the extension. On another server, install it by following the [pgvector instructions](https://github.com/pgvector/pgvector#installation).

**Only PostgreSQL 16 or newer is supported.** The core migration checks the database before creating tables. Create the database with `UTF8` encoding and a UTF-8 locale. Case-insensitive search relies on `lower()`, which folds Cyrillic correctly only in such a locale.

## Installation

### Quick start

The commands below install the bundle into a new Symfony 7.4 project. You need PHP with the extensions from [Requirements](#requirements), Composer, and PostgreSQL with pgvector.

1. Create the project and install the package:

   ```bash
   composer create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   composer config extra.symfony.docker false
   composer require itk/cms
   ```

   Symfony Flex adds the bundle to `config/bundles.php` and installs the required dependencies: Doctrine, Messenger, Mailer, Security, Twig. The `extra.symfony.docker false` line stops Flex from adding its own Docker files. The package ships a ready Docker kit, see [Docker](#docker).

2. Set the database and the secret in `.env.local`:

   ```dotenv
   DATABASE_URL="postgresql://user:password@127.0.0.1:5432/mysite?serverVersion=17&charset=utf8"
   APP_SECRET=a-long-random-string
   ```

3. Import the routes in `config/routes/itk.yaml`:

   ```yaml
   itk_admin:
       resource: '@ItkCmsBundle/config/routes/admin.php'
   itk_site:
       resource: '@ItkCmsBundle/config/routes/site.php'
   ```

4. Replace `config/packages/security.yaml` with the file from [Security](#security-securityyaml).

5. Set the content language in `config/packages/itk_cms.yaml`. The default is `ru`. The admin language is a separate option (see [Admin language](#admin-language)).

   ```yaml
   itk_cms:
       main_locale: en
   ```

6. Create the tables and the first administrator. Run the migrations first, then `itk:install`. The command asks for a password.

   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   php bin/console assets:install
   ```

7. Start the server and the queue worker:

   ```bash
   symfony server:start
   php bin/console messenger:consume async critical_css knowledge_base
   ```

   Without the worker, e-mails and messenger messages are not sent. The built-in PHP server (`php -S`) does not pass a URL with a file extension to `index.php` when the file does not exist. Image copies at `/uploads/media/_v/…webp` then return 404 until the worker creates them. To let such URLs reach PHP, run `php -S 127.0.0.1:8000 -t public router.php` with this file in the site root:

   ```php
   <?php // router.php: an existing file is served as is, everything else goes to index.php
   $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
   if (is_string($path) && is_file(__DIR__ . '/public' . $path)) {
       return false;
   }
   $_SERVER['SCRIPT_NAME'] = '/index.php';
   require __DIR__ . '/public/index.php';
   ```

8. Open `/admin/login` and sign in. `itk:install` creates the home page as a draft, so `/` returns 404 until it is published (**Pages** → **Home** → **Publish**). Check the installation with `php bin/console itk:doctor`.

Next, describe the site structure in `config/itk/schema.php` (see [Site schema](#site-schema)) and put the templates in `templates/`. The `itk:make:*` commands create class and template stubs (see [Code generators](#code-generators)).

### Installing into an existing project

`composer require itk/cms` installs the required packages. The rest is optional:

| Package | Purpose |
|---|---|
| `symfony/notifier`, `symfony/telegram-notifier`, `symfony/http-client` | Requests to Telegram |
| `yuri-kovalev/max-bot-symfony-bundle` | Requests to MAX |
| `symfony/http-client` | Update checks, IndexNow, Yandex Webmaster, knowledge base |
| `monolog/monolog` 3.5 or newer | Hides the bot token from the log and gives the MCP log its own channel |

In a project without Symfony Flex, add `Itk\Cms\ItkCmsBundle::class => ['all' => true]` to `config/bundles.php`. FrameworkBundle, TwigBundle, SecurityBundle, DoctrineBundle and DoctrineMigrationsBundle must be there too.

Import the routes as in the quick start. `site.php` registers the site endpoints.

- `POST /request/{form}` submits a form. `POST /mcp` serves authenticated MCP calls when the feature is enabled.
- `GET` and `HEAD` serve `/healthz`, `/sitemap.xml`, `/robots.txt`, `/llms.txt`, the IndexNow key at `/{key}.txt`, and image variants at `{upload_url}/_v/w{width}/{path}` and `{upload_url}/_v/w{width}-r{a}x{b}/{path}`.
- `GET` and `HEAD /{path}` serve pages. This catch-all has priority `-1000`, so site routes with the default priority match first.

#### Security (`security.yaml`)

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

- The `itk_mcp` firewall is mandatory. Without it, an enabled MCP server returns 500 with the code `mcp_firewall_not_configured`, and `itk:doctor` and **Site status** warn about it in advance.
- `(/|$)` at the end of the pattern keeps other paths such as `/administrator` out of the rule.
- `login_throttling` limits password guessing. `enable_csrf` on logout stops another page from signing the administrator out.
- If the admin panel is mounted at another path, update the firewall `pattern` and both `access_control` rules. Use `^/cp` for `itk_cms.admin_path: /cp`. Use `^/cp/admin` when importing the routes with `prefix: /cp`. Collection actions require sign-in and the relevant permission even without those access rules.
- In production, `APP_SECRET` must be unique and non-empty. The admin CSRF token, the encryption of secrets (two-step sign-in secrets, bot tokens, SMTP password, Yandex Webmaster token), the IndexNow key and the URL hashes in requests depend on it. Flex puts an empty `APP_SECRET=` line in `.env` and a working development value in `.env.dev`. With an empty secret, `/admin` returns 500 with the text `APP_SECRET is empty`, and `itk:install` prints a warning. To keep it in the Symfony vault, see [Secrets in production](#secrets-in-production-symfony-vault).
- Behind a proxy or CDN, set `framework.trusted_proxies`. Otherwise all visitors appear to come from one address for the form and sign-in limits.
- Set `itk_cms.public_url` to the full origin for password and invitation links. Set `framework.trusted_hosts` to constrain incoming hosts.

#### Secrets in production (Symfony vault)

The bundle reads its settings through `%env()%`, so a value can come from the [Symfony secrets vault](https://symfony.com/doc/current/configuration/secrets.html) as well as from the environment. The bundle needs nothing extra for this. The site decides which variables go into the vault and how the decryption key reaches the server.

- A site usually keeps `APP_SECRET`, `DATABASE_URL` and `MAILER_DSN` in the vault, and also `TELEGRAM_DSN` if Telegram is set up on the server. A bot token entered in **Settings → Bots** wins over `TELEGRAM_DSN`, and then the variable is not needed. Bot tokens, the SMTP password and the Yandex Webmaster token entered in the admin panel are kept in the database, sealed with `APP_SECRET`. They do not go into the vault.
- Create the keys of an environment with `php bin/console secrets:generate-keys --env=prod` and add values with `php bin/console secrets:set DATABASE_URL --env=prod`. Commit `config/secrets/prod/` without the private key `prod.decrypt.private.php`. Flex ignores only the `prod` key. For another environment, such as `stage`, add `*.decrypt.private.php` to `.gitignore`. The Docker kit keeps the private key out of the image.
- On the server, pass the private key in the `SYMFONY_DECRYPTION_SECRET` variable. Get its value with `php -r 'echo base64_encode(require "config/secrets/prod/prod.decrypt.private.php");'`.
- A real environment variable and a non-empty line in `.env` or `.env.*.local` win over the vault. An empty line, such as the `APP_SECRET=` that Flex puts in `.env`, does not, and Symfony then reads the vault.
- If the vault has no `APP_SECRET`, Symfony derives it from the decryption key. The site works, but `APP_SECRET` then changes together with the key. After `secrets:generate-keys --rotate` the bot tokens, the SMTP password, the Webmaster token and the two-step sign-in secrets can no longer be read. To keep `APP_SECRET` apart from the key, store it in the vault before the first launch with `php bin/console secrets:set APP_SECRET --random=32 --env=prod`. Switching from the derived value to a stored one later changes `APP_SECRET` in the same way.
- `php bin/console secrets:list --env=prod` lists the names in the vault. **Site status** and `itk:doctor` warn when `APP_SECRET` is empty.

#### Messenger queue

The bundle routes its messages to three transports:

| Transport | Messages |
|---|---|
| `async` | `Request\SendRequestNotification` (a request to Telegram or MAX), `Mail\SendMail` and `Security\RequestPasswordLink` (e-mails), `Media\GenerateImageVariants` (image copies), `IndexNow\Model\PingIndexNow` |
| `critical_css` | `CriticalCss\Model\GenerateCriticalCss` |
| `knowledge_base` | `KnowledgeBase\Model\SyncKnowledge` |

If the site does not declare these transports, the bundle adds them on `doctrine://default` (the `messenger_messages` table in the same database), plus the `failed` transport with `failure_transport: failed`. The bundle leaves alone a transport the site declared itself, and its `failure_transport`. A production site usually sets retries:

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

Do not use `sync://` instead of `async`. The **Forgot your password?** e-mail would then be built inside the request, and the response time would reveal whether the address exists.

Run the worker under supervisor or systemd: `php bin/console messenger:consume async critical_css knowledge_base`. The site and the admin panel work without the worker. Requests are saved, pages are served, and the route creates an image copy on the first request. Only e-mails and messenger messages are not sent. They wait in `messenger_messages`, and **Site status** shows how many there are. On hosting without background processes, run the worker from cron once a minute:

```
* * * * * cd /path/to/site && php bin/console messenger:consume async critical_css knowledge_base --time-limit=55 --memory-limit=128M
```

#### Configuration, migrations and first run

1. Configure the bundle in `config/packages/itk_cms.yaml`. All options are optional. The full list is in [Configuration reference](#configuration-reference).
2. Run `php bin/console doctrine:migrations:migrate`. The bundle registers its own migrations, `Itk\Cms\Migrations`.
3. Run `php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com`. The command is safe to run again. It creates the built-in roles, the menu areas from the schema, the default settings, the queue table, the home page and the first administrator. It asks for a password of 10 to 200 characters with hidden input. For automation, pass a readable file with `--password-file`. `--name` sets the administrator's name. `--no-home` skips the home page.
4. Run `php bin/console assets:install`. The admin files become available at `/bundles/itkcms/admin/`. Flex does this on `composer install` and `composer update`.
5. Run `doctrine:schema:validate` and `doctrine:migrations:diff` only after `itk:install`. `itk:install` creates the `messenger_messages` table. Before that step, `validate` reports a mismatch, and `diff` writes the creation of that table into the site's migration.

### Site migrations

Bundle migrations (`Itk\Cms\Migrations`) and site migrations run in the order of the date in the class name (`Version20261030120000` means 2026-10-30 12:00:00). For equal dates, namespaces run in alphabetical order. The bundle sets this order with its own version comparator in `doctrine_migrations.services`. A comparator set by the site replaces it.

`config/packages/doctrine_migrations.yaml` sets the namespace of the site migrations. In a new project it is `App\Migrations` in `src/Migrations/`. Older projects use `DoctrineMigrations` in `migrations/`.

- The bundle adds its migrations path after the site paths. So `doctrine:migrations:diff` and `:generate` without `--namespace` write a new migration into the site directory, not into `vendor`. A site with several paths picks one with `--namespace`. The site does not set its own path for `Itk\Cms\Migrations`.
- Name a site migration that touches bundle data with the current date (as `diff` does). It then runs after the bundle migrations that came before it.
- A migration that touches bundle tables must not fail on an empty database. Check the table with a direct query:

  ```php
  $this->skipIf(null === $this->connection->fetchOne("SELECT to_regclass('itk_setting')"), 'itk_setting is not created yet');
  ```

  `$schema->hasTable()` does not work here. With the filter from the next item, it always returns `false`.

- Hide the `itk_*` tables from the site's `doctrine:migrations:diff`. Otherwise any difference between the bundle version and the database ends up in a site migration. Only bundle migrations change bundle tables.

  ```yaml
  # config/packages/doctrine.yaml
  doctrine:
      dbal:
          schema_filter: '~^(?!itk_)~'
  ```

  If the site has its own tables with the `itk_` prefix, refine the expression. The filter applies only to `diff`. `doctrine:schema:update` and `doctrine:schema:validate` still see the `itk_*` tables. If you cannot replace `hasTable()` with `to_regclass` in the site migrations, leave `schema_filter` empty and filter at generation time: `doctrine:migrations:diff --filter-expression='~^(?!itk_)~'`.

## Core concepts

### Site schema

The site structure is described by `config/itk/schema.php` (the `schema_file` option changes the path) or by resource classes (see [Resource classes](#resource-classes)). Both end up in one schema.

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

| Call | What it describes |
|---|---|
| `design(headlineMarks: [...])` | Styling that the site design supports (marks in headlines) |
| `menuArea(key, title, depth:)` | A menu area. `depth: 2` allows an item and one level of children |
| `layout(top:, bottom:)` | Menu areas in the layout. By default `header` is at the top and the rest at the bottom |
| `section(key, title)` | A section type: `group()`, `short()`, `tone()`, `silhouette()`, `about()`, `h1()`, `template()`, `fields()` |
| `template(key, title)` | A page template: `single()`, `root()`, `topLevel()`, `parents()`, `anyParent()`, `leaf()`, `sections()`, `listing()`, `shortcut()`, `card()`, `h1()`, `areas()`, `about()`, `fields()` |
| `collection(key, title)` | A collection of records, see [Collections](#collections) |
| `settings(key, title)` | A settings group, see [Settings](#settings) |
| `form(key, title)` | A site form, see [Receiving requests](#receiving-requests) |
| `withoutBlog()` | A site without a blog |

**Schema validation.** `Schema::validate()` returns a list of errors in the admin language. It finds a repeated field key (in a section, a template, a collection with its tabs, a form, a repeater), a repeated settings key in two groups, an invalid key, `parents()` and `listing(of:)` with a template that does not exist, a form without fields, `titleField()` pointing to a field that does not exist, the reserved field key `version` in a collection, and a menu area declared twice. A key consists of Latin letters, digits, `_` and `-`, and starts with a letter or a digit. In debug, the schema is validated on load, and an error throws an exception with the whole list. In prod, there is no check on each request.

`php bin/console itk:schema:lint` shows the same errors and checks the Twig templates. A section without `sections/<key>.html.twig` (or its own `template()`) is an error. A page template without `pages/<key>.html.twig` gives a warning: the `default` template renders such pages. The exit code is 1 on an error. Warnings do not change it.

### Pages, templates and sections

A page consists of the fields of its template and a list of sections. The template sets where the page can stand in the tree and which fields it has. A section is a page block with its own fields and its own Twig template, `templates/sections/<key>.html.twig`.

Core templates: `home`, `default`, `blog`, `blog_category`, `blog_article`. A site changes a core template by calling `$s->template('blog_article')` again. `$s->withoutBlog()` at the start of `schema.php`, or the `itk_cms.blog: false` option, removes the three blog templates and the **Blog articles** link in the admin menu. `itk:schema:lint` reports an error for a template that refers to the removed `blog` in `parents()` or `listing(of:)`.

**The H1 heading.** The first visible section of the `SectionType::FIRST_SCREEN` group gets the `is_h1` variable in its template. The H1 of that section is the field from `->h1('field')` or the first `headline` field. A group is the literal string passed to `group()`, and the admin panel shows it as written. Use `SectionType::FIRST_SCREEN` for the first-screen group. The admin panel shows this group and the default group in the interface language. A page template with `->h1('field')` owns the H1 itself. Only that field gets the **Page heading (H1)** label. If the H1 field has the `headline` type, the page title equals its first line as plain text.

**Content languages.** Set `main_locale` for the primary content language and `locales` to list the languages available to editors. See [Content language configuration](#content-language-configuration) and [Admin language](#admin-language).

### Collections

A collection is a list of records with fields: reviews, tours, staff. Records are published the same way as pages. Collection options:

| Method | What it does |
|---|---|
| `fields(...)`, `tab(key, label, ...fields)` | Fields and tabs of the record form |
| `titleField()`, `thumbField()`, `subField()`, `toggleField()` | The title field, thumbnail, second line and toggle in the list |
| `view('table'\|'cards')`, `views([...])` | The list view, see [List views](#list-views) |
| `sortable()` | The list order is set by hand, and the site uses the same order |
| `defaultSort([...])`, `perPage(int)` | The default sort and the list page size (50) |
| `filters(...)`, `actions(...)`, `metrics(...)` | Filters, actions and metrics |
| `tree()` | Records nest inside each other |
| `singleton('main')` | A collection of one record with this id. Reads return initial values until the first explicit save creates the row |
| `view('matrix')`, `matrix([...])` | A table editor for one record: `colFields`, `rowKinds`, `rowLabel` |
| `singular()`, `newLabel()`, `icon()`, `describe()`, `emptyState()` | Labels and the icon in the admin panel |
| `menu(...)`, `visibleWhen(fn)` | The place in the admin menu and visibility, see [Admin menu](#admin-menu) |

Use a business field such as `stage` for a kanban column. Collection fields cannot use metadata keys from `Collection::RESERVED_KEYS`. Admin, PHP, MCP and collection imports share value validation. A custom field can implement `Itk\Cms\Field\ValueValidatingFieldInterface` and return translated errors from `validationErrors()`.

### Menus

`menuArea()` declares menu areas. Items are edited in **Settings** → **Menus** (the `set:menus` permission). An item leads to a page or to a URL. Items are dragged by the handle on the left, and the area `depth` limits nesting. In a template, `itk_menu('header')` outputs the menu. Only published pages appear in the menu.

### Settings

A settings group is a form in **Settings** with its own fields. Settings keys are shared by all groups. Built-in groups:

| Group | Fields |
|---|---|
| `site` (**Site**) | `siteName`, `domain` (the site address), company fields for schema.org: `organizationType`, `organizationName`, `organizationAddress`, `phone`, `email`, `organizationLogo`, `organizationLinks`, `schemaOrgOff` |
| `analytics` (**Analytics and webmaster tools**) | `yandexMetrikaId`, `yandexMetrikaWebvisor`, `googleAnalyticsId`, `yandexVerification`, `googleVerification`, `indexNow` |

A site extends a built-in group with `$s->settings('site')->fields(...)`. A site field with the key of a built-in field replaces the core field, whichever group the site declares it in.

Set `domain` to a hostname or an HTTP(S) origin. A path, query or fragment is invalid. `itk_cms.public_url` alone supplies the origin for the sitemap, IndexNow and Webmaster. International hostnames are written as ASCII in URLs. Internal settings such as SMTP and bot credentials use a separate `itk:` namespace.

Site code reads settings with the `Itk\Cms\Settings\Settings` service. The value comes in the field's type:

```php
$settings->get('phone');                  // toggle → bool, number → int|float, text/select → string, image/link/repeater → array
$settings->string('phone', 'none');
$settings->int('seats', 10);
$settings->bool('maintenance');
$settings->array('socials');
$settings->media('logo');                 // {src, alt} or null
$settings->get('siteName', locale: 'en'); // for fields with translatable()
$settings->all();                         // all settings of the language
```

The call's default is used when the setting is not in the schema or is empty. `false` and `0` count as real values. Until the setting is saved, the field's `default()` is read. The `itk_settings()` Twig function uses the same service. `canSee()` restrictions do not apply to this service.

### Media library and images

Originals are stored at `{upload_url}/Y/m/name.jpg` (by default `/uploads/media`). Resized copies sit next to them: `{upload_url}/_v/w{width}/Y/m/name.webp` and `.avif`.

- After an image is uploaded, the worker creates WebP and, if GD supports `imageavif`, AVIF for each width. Without a queue, the `itk_image_variant` route creates the copy on the first request and puts the file at the public path. After that the web server serves it. For this to work, the web server passes a request for a missing file to `index.php` (`try_files $uri /index.php$is_args$args`).
- Only widths from `itk_cms.images.widths` are allowed. Any other URL returns 404. The original is looked up only inside `upload_dir`.
- An image is never enlarged. Proportions and transparency are kept, and the EXIF rotation of JPEG is applied.
- JPG, PNG, non-animated WebP and AVIF are converted. GIF, SVG, PDF, animated WebP and originals larger than `images.max_pixels` (50 megapixels) are served as is.
- In `article` and `description` fields, `<img src="/uploads/…">` tags get on output the `src` of the 960 copy, a `srcset` up to 1920, `sizes`, `loading="lazy"` and `decoding="async"`. The database keeps the original HTML.
- `php bin/console itk:media:warm [--width=640 --width=1280]` creates copies of all images in advance, for example after an import.

**Crops and focal point.** A media library file has a focal point and saved crops for aspect ratios (**Media library** → file → **Crop and focal point**). A crop URL is `{upload_url}/_v/w{width}-r{A}x{B}/Y/m/name.webp`, and `itk_media_url(image, 960, '16:9')` returns it. Without a saved crop, the largest frame of that ratio around the focal point is used. Allowed ratios are those in `images.ratios` and those for which the file has a saved crop. Write the ratio unreduced (`16x9`, not `32x18`), otherwise you get 404. The browser caches a crop for a day, not a year, because the crop can be redrawn.

**Folders.** Folders nest up to 6 levels, and a name is unique among siblings. A folder with content can be deleted only by moving the content to another folder or to the top level. API (the `media` area): `GET|POST media-folders`, `PATCH|DELETE media-folders/{id}` (`?moveTo=<id>|root`), `GET media?folder=<id>|none`, `PATCH media/{id} {folderId}`, `POST media/move {ids, folderId}`.

## Building the site

### Twig templates

Place overrides in `templates/bundles/ItkCmsBundle/`. To extend the original bundle template from an override, use `@!ItkCms/…`. Admin and preview assets use the default Symfony Asset package when available, including its base path or CDN.

The site puts its templates in `templates/`:

- `layout.html.twig` is the layout. It declares `{% block content %}`. `{% block title %}` is convenient for `<title>`.
- `pages/<template>.html.twig` renders a page of its template. Without it, `pages/default.html.twig` is used.
- `sections/<section>.html.twig` renders a section. Variables: `section`, `page`, `is_h1`.

A page template has access to `page` (`id`, `parentId`, `template`, `locale`, `html_lang`, `og_locale`, `translations`, `title`, `url`, `seo`, `fields`, `sections`, `h1Id`) and `site` (the settings, plus `name`, `domain`, `locale`).

To make a record or setting clickable in the page preview, use `itk_field(record, 'title')` on a row from `itk_collection()` or `itk_listing()`, and `itk_field(site, 'phone')` or `itk_field(itk_settings(), 'phone')` for a setting. Use `itk_field_attrs()` on the surrounding element for custom markup. A click opens the field in the preview language when the editor has access.

### Twig functions

All bundle Twig functions use the `itk_` prefix. To upgrade to 0.3.0, rename the old calls with the command in the [upgrade guide](https://github.com/it-koval/cms/blob/main/UPGRADE.md#twig-functions).

| Function | What it does |
|---|---|
| `itk_render_sections(page)` | Renders the visible sections through `sections/<type>.html.twig` |
| `itk_field(section\|page, 'key')` | The field value. `headline` and formatted text come as safe HTML, `text` as escaped text, everything else as data. In preview, wraps the value in markup for highlighting |
| `itk_field_attrs(section\|page, 'key')` | Preview attributes for non-text fields |
| `itk_section_attrs(section)`, `itk_section_class(section)` | Attributes of the section root and the `chg` class for a changed section, in preview only |
| `itk_is_preview()` | `true` while the admin panel shows a draft |
| `itk_menu('header')` | The area items: `label`, `kind`, `url`, `active`, `open`, `children` |
| `itk_settings('phone')`, `itk_settings('phone', 'none')`, `itk_settings()` | Settings |
| `itk_page_url(link)` | A safe URL from a **Link** field value, a page or a page id |
| `itk_listing(page)` | The published pages or records that the page collects. Each row has `parentId` |
| `itk_pages({...})`, `itk_page(id)`, `itk_collection(key, {...})` | Public data reading, see the section below |
| `itk_media_url(image[, width[, ratio]])` | The image URL, its WebP copy of the nearest allowed width not smaller than the given one, or a crop of the ratio |
| `itk_media_srcset(image[, maxWidth[, ratio]])` | A `srcset` value up to the original or up to the first copy that covers `maxWidth`. Empty for GIF, SVG and external URLs |
| `itk_media_width(image)` | The width of the original in pixels, or `null` |
| `itk_media_ratio(width, height)` | The ratio key of a slot in the layout: `itk_media_ratio(700, 250)` returns `'14:5'` |
| `itk_img(image, {...})` | A ready `<img>` with `srcset`, `sizes`, `alt`, `loading="lazy"`, `decoding="async"`. Wrapped in `<picture>` when AVIF is supported |
| `itk_seo({...})` | Meta tags of the current URL, see [SEO rules for URLs](#seo-rules-for-urls) |
| `itk_cache(300)`, `itk_cache(false)` | The lifetime of the entry in the page cache, see [Page cache](#page-cache) |
| `itk_stylesheets({nonce: x})` | The site stylesheets, see [Critical CSS](#critical-css) |
| `itk_asset('preview.js')` | URL of a file in `bundles/itkcms/`. Uses the site's Symfony Asset package or the request base path |
| `itk_t('area.key', {...})`, `itk_locale()` | Text and language of the admin interface. For your own sign-in pages and e-mails |
| `itk_admin_font()` | The `@font-face` rules of the admin font for your own sign-in pages |

`itk_img` options: `sizes`, `class`, `width`, `height`, `loading`, `alt`, `ratio`, `src_width` (the width of `src`, by default `width`, otherwise 960), `max_width` (the widest image in device pixels, by default `2 × width`), `attrs` (an array of attributes or `itk_field_attrs(...)`). `sizes` defaults to `(max-width: {width}px) 100vw, {width}px`.

A ratio that is in neither `images.ratios` nor the file's crops does not break the page. The whole image is used, and a warning goes to the log. To make an image fit the shape of its slot, write `itk_media_url(img, 700, itk_media_ratio(700, 250))`.

### Public data reading

`itk_pages`, `itk_page` and `itk_collection` read only published content, and read it the same way for everyone. A guest and a signed-in administrator see the same thing. `itk_pages` and `itk_collection` each make one database query per call. All `itk_page` lookups share one query per language per visitor request. Site code reads the same data with `Itk\Cms\Content\ContentReader`.

```twig
{% for c in itk_pages({template: 'blog_category'}) %}<a href="{{ c.url }}">{{ c.title }}</a>{% endfor %}
{% for a in itk_pages({template: 'blog_article', parent: category.id, sort: '-fields.date', limit: 6}) %}…{% endfor %}
{{ itk_page(article.parentId).title }}
{% for t in itk_collection('tours', {where: {state: 'open', seats: {from: 5}, level: ['easy', 'mid']}, sort: '-start', limit: 10, offset: 20}) %}…{% endfor %}
```

- `itk_pages` returns rows `{id, parentId, template, locale, title, url, seo, fields, position, publishedAt}`. Parameters: `template` (a key or a list), `parent` (a page id, direct children only; an empty value is an error), `limit`, `sort`. `sort` accepts `position` (the default), `createdAt`, `publishedAt`, `title`, `url` and `fields.<key>`. A `-` sign means descending order. Write several keys as a list or comma-separated. A numeric field sorts as a number. Rows have no sections. `itk_listing()` returns those.
- `itk_page(id)` returns the same kind of row, or `null`. All pages of the language are read in one query per visitor request, so a loop with `itk_page(a.parentId)` does not run a query for each article.
- `itk_collection(key)` returns records in order. Parameters: `where` (`{field: value}`; a list means any of the values, `true` and `false` for a toggle, `{from, to}` for a number or a date such as `2026-11-30`), `sort` (`position`, `createdAt` or a field), `limit`, `offset`. A record comes as `{id, ...fields}`.
- In PHP, `ContentReader` throws `\InvalidArgumentException` for an invalid parameter, field or template. In Twig, invalid `itk_pages` or `itk_collection(key, options)` calls throw `\LogicException` in strict mode. In soft mode they return `[]` and log a warning. Pass `{}` as options to check a collection key without filters.

### Strict mode

The `itk_cms.strict` option (equal to `kernel.debug` by default) makes template errors loud. An exception is then thrown for an unknown key in `itk_field` (with a hint listing the allowed keys), an unknown key inside a repeater, a section type that is not in the schema, a section without a template, and an invalid call of `itk_pages` or `itk_collection(key, options)`. With `strict: false`, the page renders, and one warning per error per request goes to the log. A path into a value without subfields (`image.src`) is not an error. In the admin preview, field and section checks are softened, and a section without a template shows as a grey frame. Invalid content-reading calls still follow `strict`.

### Safe URLs

`itk_page_url()` returns only a safe URL: a site path (`/blog`, but not `//host`), a page URL by id, `http(s)://`, `mailto:`, `tel:` and `#anchor`. A URL with another scheme (`javascript:`, `data:`) becomes an empty string. The **Link** field is checked by the same rule on save. The rule is the same for pages, records, settings and menu items.

The `Itk\Cms\Support\Url` class gives these checks to site code: `safeLocalPath()`, `isLocalPath()`, `cleanPageRef()`, `cleanLinkUrl()`, `safeHref()`. `Url::safeLocalPath($path)` returns a path that is safe to put in `Location` or `href`. It keeps exactly one `/` at the start, reads `\` as `/`, and removes tabs and line breaks. Pass through it any part of a URL from the request that goes into a redirect:

```php
return new RedirectResponse(Url::safeLocalPath($request->getPathInfo()) . '?' . $query, 301);
```

## Fields

A field is an object from `Itk\Cms\Field\` with chained methods. The label comes first and the key second: `Text::make('Title', 'title')`. Every field that holds data needs a key. Only `Note` has no key. In `schema.php`, the short form `Itk\Cms\Schema\Field` creates the same field with the key first: `Field::text('title', 'Title')`. It returns the same object.

### Field types

| Class | `Field::` | What it is | Value |
|---|---|---|---|
| `Text`, `Textarea` | `text`, `textarea` | A line, multi-line text | string |
| `Headline` | `headline` | A headline | safe HTML |
| `Description` | `description` | Formatted paragraphs | safe HTML |
| `Article` | `article` | Article text: subheadings, quote, divider, photo, simple table | safe HTML |
| `Number` | `number` | A number. `->grouped()` shows thousands separators | number |
| `Toggle` | `toggle` | A toggle | `bool` |
| `Select` | `select` | One option: `->options(['open' => 'Open'])` | string |
| `Date`, `DateTime` | `date`, `dateTime` | `2026-11-30`, `2026-11-30T18:00` | string |
| `Image` | `image` | An image | `{src, alt}` |
| `Photos` | `photos` | Photos with individual alt text. An item is a URL string or `{src, alt}`. Use `p.src ?? p` for the URL and `p.alt ?? ''` for the alt text | list |
| `Link` | `link` | A link to a page or a URL | `{kind, page, url}` |
| `Repeater` | `repeater` | A list of items: `->fields(...)` | list, each item has `_id` |
| `Relation` | `relation` | A link to a collection record: `->to('destinations')`, `->multiple()` | id or list of ids |
| `Note` | `note` | A hint for the editor, not data: `->text()`, `->linkTo()` | none |

A table in `Article` contains `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`. A cell allows text, links, `<strong>`, `<em>` and `<br>`. The only allowed attributes are numeric `colspan` and `rowspan`.

### Field methods

All methods return the field, and you can call them in any order.

| Method | What it does |
|---|---|
| `required()`, `max(int)`, `rows(int)` | Required, length, height of the input |
| `lineBreak(false)` | Disable line breaks in a headline. The default and `lineBreak()` allow them |
| `hint(string)` | Help text under the field. Alias: `help()` |
| `default($v)` | The value for a new record. Alias: `initial()` |
| `pattern($regex, $message)` | Server-side check of a site form value |
| `inputType(string)` | The HTML input type, for example `email` |
| `translatable()` | The setting is stored separately for each language |
| `readonly()` | The editor sees the value but cannot change it. The server ignores the editor's value. Code and hooks can write it |
| `canSee('set:users' \| 'col:tours:edit' \| fn (Viewer $v): bool)` | Who can see the field. For everyone else, the server leaves the field out of the schema, records and history, and does not accept its value |
| `raw()` | Multi-line text is output as raw HTML with scripts. Only a person with the permission to manage users can add or change the value. Nobody can do it through MCP |
| `half()` | The field takes half a form row. Two neighboring fields with `half()` stand side by side |
| `section('Heading')` | A form section heading before the field |
| `sortable()`, `searchable()`, `filterable(?FilterKind)` | Sorting, search and filter in the collection list |
| `showOnIndex()` (`column()`), `hideOnIndex()`, `hideOnForm()`, `hideOnDetail()`, `width(int)` | List column, form, detail view, column width in pixels |
| `variant()` | A `Select` field becomes the section style option of the section |
| `noun()`, `addLabel()` | The noun for a repeater item and the label of the add button |

`readonly()` and `canSee()` apply to collection records, template fields, section fields (top level) and settings. In settings, values of a group for which the person lacks the **View** level of the `set:<group>` permission are not returned either.

### Repeater row

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

- `itemTitle()` sets the title of a collapsed row, and `itemSummary()` sets the line under it. `{key}` inserts the subfield value as a person reads it: `Relation` as the record title, `Select` as the option label, `Toggle` as the field label when it is on, `Image` as the image description, `Number` with `grouped()` with thousands separators. `Link`, `Photos` and a nested `Repeater` give an empty value.
- Text between two values is printed only if both values are present. Text before the first value and after the last value is printed only if a value is next to it. Brackets around an optional value are not supported. `0` counts as a value, an empty string does not.
- The next arguments of `itemTitle()` are fallback templates. The first one that gives non-empty text wins. If all are empty, the row is named by `noun()` with a number.
- In history, a `Relation` in the title is empty, because history is written without queries to other collections.
- `duplicable()` adds a **Duplicate** button. The copy appears under the original and gets new `_id` values.
- With `itemTitle()` or `itemSummary()`, the row thumbnail comes from the first `Image` field, or, without one, from the first photo of the first `Photos` field.

### Custom field type

A custom field type extends `Itk\Cms\Field\AbstractField`. The `wireType()` method returns a type that the admin panel understands. An extension registers the admin component for it (see [Extensions](#extensions)). `itk:make:field` creates a stub.

## Resource classes

Resource classes are the second way to describe a site: one PHP class per collection, template, section or settings group. The site puts them in `src/Itk/{Collection,Template,Section,Settings}/`, and they are picked up automatically through the `itk.resource` tag if `services.yaml` loads `src/`. You can inject services into the constructor.

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

| Class | Required | Optional |
|---|---|---|
| `CollectionResource` | `key()`, `title()`, `fields()` | `singular()`, `newLabel()`, `icon()`, `description()`, `emptyState()`, `views()`, `titleField()`, `thumbField()`, `subField()`, `toggleField()`, `filters()`, `actions()`, `metrics()`, `tree()`, `sortable()`, `defaultSort()`, `perPage()`, `canSee(Viewer)`, hooks |
| `TemplateResource` | `key()`, `title()`, `fields()` | `about()`, `parents()`, `topLevel()`, `single()`, `root()`, `leaf()`, `sections()`, `listing()`, `h1()`, `shortcut()`, `card()`, `areas()`, `configure(Template $t)`, hooks |
| `SectionResource` | `key()`, `title()`, `fields()` | `group()`, `template()` (by default `sections/<key>.html.twig`), `short()`, `tone()`, `silhouette()`, `about()`, `h1()` |
| `SettingsResource` | `key()`, `title()`, `fields()` | `description()` |

A class with the key of a core template (`blog_article`) changes that template. If the class has no fields, the core fields stay. Any other key declared twice (in `schema.php` and in a class, or in two classes) is an error. Do not inject `Schema`, `PageService` or `CollectionService` into a resource: the schema is built from resources, and you get a dependency cycle. To work with data in hooks, use events and `Context`.

### Hooks and events

Each object lifecycle operation on a collection record or a page goes like this: first a Symfony event, then a method of the resource class. For a page, the resource is the class of its template, and `kind` is `page`. For a record, the resource is the collection class, and `kind` is `item`.

| Moment | Event (`Itk\Cms\Event\`) | Class method |
|---|---|---|
| First read of the section in a request | | `onLoad(Context)` |
| Create | `RecordCreating`, `RecordCreated` | `beforeCreating(array $data, Context): array`, `afterCreated(Record, Context)` |
| Update, including autosave | `RecordUpdating`, `RecordUpdated` | `beforeUpdating(Record, array $data, Context): array`, `afterUpdated(Record, Context)` |
| Delete | `RecordDeleting`, `RecordDeleted` | `beforeDeleting(Record, Context)`, `afterDeleted(Record, Context)` |
| Publish | `RecordPublishing`, `RecordPublished` | `beforePublishing(Record, Context)`, `afterPublished(Record, Context)` |
| Unpublish | `RecordUnpublishing`, `RecordUnpublished` | `beforeUnpublishing(Record, Context)`, `afterUnpublished(Record, Context)` |
| Translation added or removed (after commit) | `RecordTranslationAdded`, `RecordTranslationRemoved` | |
| Page template change | `PageTemplateChanged` (`from` is the old template, `resourceKey` the new one) | |
| Several records at once | `MassAction` (`action`: `delete`, `publish`, `unpublish`; `phase`: `before`, `after`) | `beforeMassDeleting`, `afterMassDeleted`, `beforeMassPublishing`, `afterMassPublished`, `beforeMassUnpublishing`, `afterMassUnpublished` (`array $records, Context`) |

- An event has `resourceKey`, `kind`, `record` (`null` in `RecordCreating`), `data` and `viewer`. You can change `data` in `RecordCreating` and `RecordUpdating`, and the changed data is saved.
- `RecordTranslationAdded` and `RecordTranslationRemoved` extend `RecordEvent` and carry the operation language in `locale` (also in `record->locale`). They are dispatched only after the transaction commits. Adding or removing a translation does not call the object create/delete events or the resource lifecycle hooks. Removing a live translation still calls `RecordUnpublishing` / `RecordUnpublished` and the corresponding unpublish hooks.
- `Record` is `{id, kind, resourceKey, locale, fields, status, meta}` with a `get($key)` method. For a page, `fields` holds the template fields, and `meta` holds `title`, `url`, `parentId`, `template`.
- `Context` holds `viewer`, `locale`, `kind`, `resourceKey`. `Viewer` is either a person with permissions (`can('col:tours', 'publish')`, `allows('set:users')`, `user()`, `role()`) or the system (console, import, queue: `isSystem()`, everything is allowed).
- Any `before…` can throw `Itk\Cms\Content\ContentException`. The change is cancelled, and the editor sees the exception text. `after…` runs after the save, and an exception in it rolls nothing back.
- A mass operation first calls the shared `before` once. Then each record goes through its own `before…` and the database write, all in one transaction. The `after…` of each record and the shared `afterMass…` run after the transaction, and only if it did not roll back.
- The core removes `readonly()` fields and fields hidden by `canSee()` from the person's data before the events. Values added by a hook are saved.
- Page data in `beforeCreating` and `beforeUpdating` is nested: `{title, url, seo, parentId, template, sections, fields: {...}}`. An update brings only the changing keys. An unknown top-level key is ignored.
- A move in the tree goes through `beforeUpdating` with the data `{parentId}`. A hook can cancel the move with an exception, but cannot change it. **Discard edits** does not call the update hooks.
- Returning an unpublished page to the site calls `RecordPublished` and `afterPublished` without `before…`.

```php
use Itk\Cms\Support\Slug;

// CollectionResource: flat data
public function beforeCreating(array $data, Context $ctx): array
{
    $data['slug'] = Slug::slugify((string) ($data['title'] ?? ''));

    return $data;
}

// TemplateResource: template fields inside 'fields'
public function beforeCreating(array $data, Context $ctx): array
{
    $data['fields']['slug'] = Slug::slugify((string) ($data['title'] ?? ''));

    return $data;
}
```

### Actions

An action is a button over collection records. A class declares actions in `actions()`, and `schema.php` in `->actions(...)`.

```php
Action::make('Duplicate', 'duplicate')->row()->bulk()->icon('copy')
    ->modal('How many copies?', Number::make('Copies', 'times')->required())
    ->handle(static function (ActionContext $c): ActionResult {
        // $c->records() are the selected records, $c->input() the dialog values, $c->viewer()
        return ActionResult::message('Done')->reload();
    });
```

| Method | What it does |
|---|---|
| `row()`, `bulk()`, `top()`, `form()` | Where the button is: the row menu, the checked records, above the list, the record page. Several are allowed. The default is `row()` |
| `icon()`, `danger()`, `confirm('Question')` | Icon, dangerous action, question before running |
| `modal($title, ...$fields)` | A dialog with fields. The values are validated by the fields |
| `canSee(string\|callable)` | Who can see the button |
| `requires('publish')` | The collection permission needed to run it. The default is **Edit** |
| `permission()` | A separate `action:<collection>:<action>` row in the role matrix. By default only the administrator has it |
| `handle(callable)` | The handler |

Result: `ActionResult::message('…')->reload()`, `::redirect('/pages/42')` (an admin path or `http(s)://`), `::download('/path/file.csv', 'name.csv', delete: true)`, `::download(StreamedResponse)`, `::error('…')`.

Every collection except single-record collections gets the built-in actions `publish-selected`, `unpublish-selected` and `delete-selected`. They go through the mass hooks. Your own action with the same key replaces the built-in one.

API: `POST {admin}/api/resources/{collection}/actions/{action}` with the body `{ids?: [], input?: {}, locale?}` returns `{ok, message?, reload?, redirect?, download?}`. An action without the `top()` placement needs `ids`, at most 500. An action error returns `422 {ok: false, error, message}`. An action file is served once, to the person who ran the action, at `GET {admin}/api/downloads/{token}` within 5 minutes.

### Filters, sorting and metrics

A filter is a field with `filterable()`. The filter kind is picked from the field or set explicitly: `FilterKind::Text`, `Select`, `Multi`, `Toggle`, `DateRange`, `NumberRange`, `Relation`. The resource's `filters()` replaces the set of fields with `filterable()`. Every collection has the `status` filter (`draft`, `published`).

```
GET {admin}/api/collections/tours?q=altai&filter[state]=open&filter[start][from]=2026-11-01&filter[seats][from]=5
    &filter[level]=mid,hard&sort=-start,title&page=2&perPage=50
→ { items, total, page, perPage, facets: { state: { open: 4, full: 3 } }, metrics: [{ label, kind, value }] }
```

- The response is always `{ items, total, page, perPage, facets, metrics }`, including a request without query parameters. See **Collection lists** in the JSON API section below.
- `sort` takes comma-separated keys, and `-` means descending. You can sort by fields with `sortable()`, and by `status`, `updatedAt`, `createdAt`, `position`. Without `sort`, `defaultSort()` applies, otherwise the manual order.
- With `filter`, `q` searches visible fields marked `searchable()` or defaults to the title and second line if none are marked. A field hidden by `canSee()` cannot be filtered or sorted (422).
- `perPage` defaults to 50, at most 200. `facets` counts records by the values of the `Select`, `Multi`, `Toggle` and `status` filters across the whole collection.
- Metrics (`Metric::count('Total')`, `Metric::count('Published', filter: ['status' => 'published'])`, `Metric::sum('Seats', 'seats')`) are computed over the whole collection, not over the current filter.
- The server remembers filters and the list view per person: `GET|PUT|DELETE {admin}/api/prefs/{key}`. The key is up to 100 characters of letters, digits and `. _ : -`, and the value is up to 16 KB of JSON.

### List views

The resource's `views()` or `->views([...])` lists the views a person switches between. The first view is the default.

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

- **Calendar.** `start` is a `Date` or `DateTime` field and is required. `end` is optional. `title` sets the label, by default the record title. A click on a day creates a record with that date. Dragging and Alt + arrows shift both dates.
- **Board.** `field` is a `Select` field, and the columns are its values or `columns`. Moving a card changes the field value. Records with an unnamed value go to the **No value** column.
- Shifting dates and moving cards on the board are ordinary record updates: field permissions, hooks, events and history all apply. A hook can cancel the move with an exception.
- The calendar, the board and the tree show all records without paging, up to 2000.
- An invalid view definition (a calendar without `start`, a field that does not exist, a board not on a `Select`) throws `InvalidArgumentException` when the schema is built.

### Tree

Records of a collection with `tree()` nest inside each other. The list gets the **Structure** view (the default first view) with drag and drop and keys: Alt + ↑ and ↓ move a record, Alt + → nests it into the sibling above, Alt + ← moves it up a level.

```
PUT {admin}/api/collections/{col}/tree   [{ "id": "…", "parentId": "…"|null, "position": 0 }, …]   → { ok, moved }
PUT {admin}/api/pages/tree               the same for pages
```

- `position` is the place among the records of the same parent, starting from zero. The named nodes move, and the siblings under the affected parents are renumbered.
- You cannot nest a node into itself or into its own branch (422), name a node twice, or point to an unknown node or parent. Everything runs in one transaction.
- A record whose parent changed goes through an update with hooks. A record with `parentId` is created with an ordinary `POST collections/{col}`. Deleting a record lifts its children into its place. The **Edit** permission on `col:<key>` is required.
- For pages, the template rules apply (`parents()`, `single`, `leaf`, the home page always at the top), plus the **Edit** permission on the template of each page being moved. In the admin panel: **Pages** → **Edit structure**.

### Admin menu

The server builds the left column of the admin panel: `GET {admin}/api/schema` returns `nav`, with only what the person can access. Built-in items: **Today** (0), **Pages** (10) with quick links for templates with `shortcut()`, **Requests** (20), **Media library** (30), the **Settings** group (900). A collection class appears in the menu automatically.

| Class attribute | What it does |
|---|---|
| `#[Group('Catalog', icon: 'box', order: 20)]` | The menu group. Without the attribute, the **Collections** group with `order` 50 |
| `#[Order(10)]` | The item's place in the group |
| `#[Icon('map')]` | The item icon |
| `#[CanSee('col:tours:edit')]` | Who sees the item, in addition to the **View** permission |
| `#[HideFromMenu]` | The section works, but has no menu item |

In `schema.php`: `$s->collection('tours')->menu(group: 'Catalog', order: 10, icon: 'map', hidden: false, canSee: 'col:tours', groupIcon: 'box', groupOrder: 20)`. The class's `canSee(Viewer)` hides the collection completely, from the schema and from the API.

## Admin panel

### Address, sign-in and API

The admin panel is at `/admin`. The `itk_cms.admin_path` option sets another path (one or more segments, with `/` at the start and no `/` at the end, for example `/cp`). You can also set `prefix` on the route import. The admin panel is then at `prefix` + `admin_path`. After changing the path, update `security.yaml` (see [Security](#security-securityyaml)).

The bundle recognizes the admin panel by the route names `itk_admin_*` and `itk_api_*`, not by the URL string. So API CSRF, security headers, two-step sign-in and JSON errors work at any path. The paths below are given for `/admin`.

| Path | What it is |
|---|---|
| `/admin` (`itk_admin_app`) | The admin shell. Files come from `/bundles/itkcms/admin/` by the Vite manifest. Client settings are in `window.ITK_ADMIN` |
| `/admin/login` (`itk_admin_login`), `/admin/logout` (`itk_admin_logout`) | Sign-in (`form_login`, fields `_username`, `_password`, `_csrf_token`) and sign-out |
| `/admin/login/otp` | The second sign-in step |
| `/admin/forgot-password`, `/admin/reset-password/{token}` | **Forgot your password?** and a new password from the e-mailed link |
| `/admin/preview/{id}?locale=` (`itk_admin_preview`) | A page draft rendered with the real site templates |
| `/admin/api/*` | The admin JSON API |

- Every API call that changes data carries the `X-CSRF-Token` header with the value from `window.ITK_ADMIN.csrf`.
- An API error comes as `{ "error": "…", "details"?: … }` with code 401, 403, 404, 409 or 422. The `error` text is in the person's language and can change. Integrations rely on the HTTP code and on `details.code`: `conflict`, `csrf`, `no_translation`, `link_gone`, `password_locked`, `mail_not_configured`, `mail_failed`, `mail_queue`, `mail_test_limit`, `no_public_url`, `2fa_code`, `2fa_enrol`, `2fa_locked`. A form field error is in `details.<field>`.
- The server checks permissions on every call.
- All admin responses get `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'`, `X-Content-Type-Options: nosniff` and `Referrer-Policy: same-origin`. A header set by the controller is not overwritten.

The `Itk\Cms\Admin\AdminPath` service tells where the admin panel is (`base()`, `to('/api/me')`), taking the import `prefix` into account. Links in e-mails are built through it.

#### Requests and media lists

`GET /admin/api/requests` and `GET /admin/api/media` return `{ items, total, page, perPage, nextCursor, previousCursor }`. Use `page` (starting at 1) and `perPage` (default 50, range 1–100). `total` counts all matches. Both lists sort by creation time and ID, descending. For adjacent pages, pass `nextCursor` or `previousCursor` as `cursor` with the same filters and page size. The cursor takes precedence over the offset implied by `page`, and is `null` in a direction with no more results. Requests using only a page number select by current position in the matching list.

- Requests support `status`, `form`, `q`, `period`, `from`, `to` and `locale`. `GET /admin/api/requests/counts` gives site-wide totals.
- Media supports `q` for a literal substring in the title, original filename or alt text, `type=img|pdf`, and `folder` (a folder ID, `none` for unfiled files, or omitted for all files).
- `GET /admin/api/dashboard` includes up to eight rows in `newRequests` and reports the full count in `newRequestCount`. All new requests are available through `GET /admin/api/requests?status=new`.

#### Collection lists

`GET /admin/api/collections/{col}` always returns `{ items, total, page, perPage, facets, metrics }`. `items` contains the current page of rows. `total` counts matching rows before pagination. Read `items` and request the remaining pages up to `ceil(total / perPage)`.

| Query parameter | Meaning |
|---|---|
| `q` | Case-insensitive substring search. Without `filter`, searches the visible title and second line (`titleField()` and `subField()`). With `filter`, uses the collection's visible search fields |
| `status` | Omit or leave empty to include all statuses. `draft` includes drafts and published versions with unpublished changes. `published` includes published versions without unpublished changes. `missing` includes records with no version in the selected language. Other values return `422` |
| `onlyTranslated` | Boolean such as `1` or `true`. Excludes records with no version in the selected language before counting and pagination |
| `filter` | Pass field filters such as `filter[state]=open`, `filter[level]=mid,hard`, or `filter[start][from]=2026-11-01`. `Multi` and `Relation` accept arrays. Date and number bounds are inclusive and either bound can be omitted. `filter[status]` matches the stored status |
| `sort` | Comma-separated keys such as `-start,title`. A leading `-` means descending. Allowed keys are visible fields with `sortable()`, plus `status`, `updatedAt`, `createdAt` and `position`. Defaults to `defaultSort()` when configured, or the manual order |
| `page` | Page number starting at 1, which is also the default |
| `perPage` | Page size set by the collection's `perPage()` by default (50). Values above 200 are capped at 200 |
| `locale` | Content language. Defaults to the main language. A code outside an explicitly configured list returns `422` |
| `envelope` | Accepted for compatibility. Every value and omission return the same response shape |

Filters, search, status and translation selection apply before counting and pagination. `facets` contains counts for the collection's `Select`, `Multi`, `Toggle` and `status` filters. `metrics` contains the configured metrics. Both are computed over the whole collection.

#### Page and record translations

Each translation belongs to the same page or collection record. Creating a translation keeps its `id`, template, parent and position. The target language is the `locale` query parameter on creation and the final path segment on deletion.

| Request | JSON body | Success response |
|---|---|---|
| `POST /admin/api/pages/{id}/translations?locale=kk` | `{"from":"ru"}` or `{"from":null}` | `201`, a flat page draft with the same `id` and `locale: "kk"` |
| `DELETE /admin/api/pages/{id}/translations/{locale}` | None | `200 {"ok":true}` |
| `POST /admin/api/collections/{col}/{id}/translations?locale=kk` | `{"from":"ru"}` or `{"from":null}` | `201`, a flat record draft with the same `id` and `locale: "kk"` |
| `DELETE /admin/api/collections/{col}/{id}/translations/{locale}` | None | `200 {"ok":true}` |

`from` selects the existing draft to copy, subject to field permissions. Pass `null` to use schema defaults for fields and sections; a page still takes its title and the basis of its address from the main translation. Each translation starts as an unpublished draft with no pending approval. New page addresses use the target language prefix. An occupied path gets an available suffix, while an occupied language homepage returns `409`.

Creation requires `edit` and deletion requires `delete` on the template or collection. Both require sign-in and the CSRF header described above. Deletion removes the selected translation and records its snapshot in history as `delete_locale`. This audit entry is not a current version, is not placed in the trash and cannot be restored; create a new translation instead. To remove the last version, delete the whole page or record.

- `404` if the page or record is missing, or the translation to delete is missing.
- `409` if the target translation already exists, its address conflicts, or the operation would delete the main or last version.
- `422` if the target language is missing or disabled, `from` is invalid or selects a missing draft, or the address is invalid.
- `401` if sign-in is required. `403` if the user lacks permission.

Errors use `{error, details?}`. Existing responses for a missing translation retain `details.code: "no_translation"`. Reading a draft in a language without a translation still returns the read response wrapper with `translated: false` and `page: null` or `item: null`.

Trusted site PHP code can use the same public operations on `Itk\Cms\Content\PageService` and `Itk\Cms\Content\CollectionService`. `addTranslation()` requires the source argument, a language code or `null`, and returns the flat draft as an array. `removeTranslation()` returns `void`.

```php
$pages->addTranslation($id, 'kk', 'ru');
// Or: $pages->addTranslation($id, 'kk', null);
$pages->removeTranslation($id, 'kk');

$collections->addTranslation('tours', $id, 'kk', 'ru');
// Or: $collections->addTranslation('tours', $id, 'kk', null);
$collections->removeTranslation('tours', $id, 'kk');
```

### Roles and permissions

A permission is a pair of an area and an action. Actions: `view` (**View**), `edit` (**Edit**), `publish` (**Publish**), `delete` (**Delete**). `publish` and `delete` include `edit`, and `edit` includes `view`. The role matrix in **Settings** → **Users and roles** → **Roles** is built from the schema areas and picks up new rows automatically.

| Area | What it grants |
|---|---|
| `pages` | Pages. A role can be limited to a list of templates |
| `col:<key>` | A collection |
| `requests` | Requests (`view`, `edit`) |
| `media` | The media library (`view`, `edit`, `delete`) |
| `set:<group>` | A settings group, including `set:site` and `set:analytics` |
| `set:menus`, `set:redirects`, `set:seo` | Menus, redirects, SEO rules, `robots.txt` and `llms.txt` |
| `set:notifications`, `set:mail`, `set:bots` | Notification recipients, mail server, bot tokens |
| `set:theme`, `set:users`, `set:mcp` | Admin appearance, users and roles, AI assistants |
| `set:languages` | Reserved permission. The site developer sets the available languages in `itk_cms.locales`. |
| `templates` | Viewing templates |
| `dev` | The **For developers** section: **Site status** |
| `action:<collection>:<action>` | Running an action with `permission()` |

Built-in roles:

| Role | Permissions |
|---|---|
| `admin` (**Administrator**) | All areas |
| `editor` (**Editor**) | Pages and collections in full, the media library in full, viewing requests |
| `author` (**Author**) | Viewing and editing pages from templates marked with `forAuthors()`, viewing and editing the media library |
| `requests` (**Requests**) | Viewing and editing requests |

Symfony gives every signed-in person the `ROLE_ITK_USER` role. In code, `Itk\Cms\Security\Access` and `PermissionVoter` (the `itk` attribute) check permissions.

### Passwords

- **Your own password.** **My profile** → **Change password**: the current password, a new one of at least 10 characters, and a repeat. `POST {admin}/api/me/password {current, password, repeat?}`. More than five attempts in 15 minutes return 429 (`itk_password_change`). Open sessions on other devices end.
- **Invitation and reset by an administrator** (`POST users`, `POST users/{id}/reset-password`). When mail is configured, the person gets a link: an invitation lives 7 days, a reset 24 hours. The `emailed: true` response comes after the queue accepts the e-mail. Without mail, the server returns a one-time password once, in the `password` field, and the admin panel shows it with a **Copy** button. You cannot reset your own password with this call (403).
- **Forgot your password.** The response is the same whether the address exists or not. The page only queues a message. The worker looks up the person and sends the e-mail, so the address cannot be found out from the response time either. The link lives one hour and works once. The database stores only its sha256. Limits: 10 requests per hour from a visitor address, 3 e-mails per hour to one e-mail address, 10 invalid links in 15 minutes (`itk_password_reset_ip`, `itk_password_reset_email`, `itk_password_reset_guess`). Without mail, the page suggests contacting the administrator.
- All unused links of a person expire when the person's password is set in any way, their e-mail changes, or they are blocked.
- **Console.** `php bin/console itk:user:password name@example.com` asks for the password with hidden input. `--password=…` leaves the password in the shell history.

Password and invitation links use the configured `itk_cms.public_url`, such as `https://example.com`. Include the port if the site uses a custom one. Without this configuration, **Forgot your password?** records a configuration error, and admin invitation and reset return a one-time password. **Site status** and `itk:doctor` report the missing address.

### Two-step sign-in

The second step is a one-time code from an app (TOTP per RFC 6238: SHA-1, 6 digits, 30 seconds). Google Authenticator, 1Password, Authy and others work. You do not need to change `security.yaml`.

- **Turning it on.** **My profile** → **Two-step sign-in**: a QR code and a key for manual entry, confirmation with the first code, 10 recovery codes. The codes are shown once, and each works once. Turning it off and getting new recovery codes require the password.
- **Sign-in.** After the password, `/admin/login/otp` opens. Until the code is entered, only this page and sign-out work in the admin panel.
- **Mandatory for a role.** The **Require two-step sign-in** checkbox in **Settings** → **Users and roles** → **Roles** (`Role::require2fa`). A person with such a role and no factor sees only the setup dialog. Everything else returns 403 with `details.code = '2fa_enrol'`. That person cannot turn the factor off (409).
- **Attempts.** Five wrong codes in a row lock the factor for 15 minutes (429, `details.code = '2fa_locked'`). A per-user limiter (`framework.rate_limiter.itk_two_factor`) also counts attempts. A code works once, with a window of ±1 step.
- **Storage.** The secret is encrypted with `sodium_crypto_secretbox` using a key from `APP_SECRET`. Recovery codes are stored as HMAC-SHA256.
- **Changing `APP_SECRET`** makes the secrets and recovery codes unreadable. People with the factor turned on cannot sign in until their factor is reset. Site Health and `itk:doctor` report unreadable keys without showing any account details.
- **Reset.** An administrator clicks **Settings** → **Users and roles** → **Users** → **Reset two-step sign-in** (`POST users/{id}/reset-2fa`, the **Edit** level of `set:users`, not for yourself). If nobody can sign in: `php bin/console itk:user:reset-2fa name@example.com`.
- **API.** `GET 2fa`, `POST 2fa/setup`, `POST 2fa/enable {code}`, `POST 2fa/recovery-codes {password}`, `POST 2fa/disable {password}`. These act only on yourself and need no special permissions.

### History and trash

Every saved edit of a page, a record, a settings group and a menu is written to `itk_revision`: who, when, what changed, and the whole version. The core services write it in the same transaction as the edit. Edits from the console, imports and the queue are signed **System**. Edits through MCP are signed with the key name.

- Autosaves by one person with a gap of less than 10 minutes merge into one entry. An edit without changes leaves no entry.
- Actions: `create`, `edit`, `publish`, `unpublish`, `revert`, `delete`, `restore`.
- The `history.keep` (entries per object and language, 100) and `history.days` (365) options set the depth. `php bin/console itk:history:prune [--keep=N] [--days=N] [--dry-run]` deletes the rest. The core has no schedule, so put the command in cron once a day.
- **Restoring a version** (`POST history/revision/{id}/restore`) makes that version the draft. The tree position and the template do not change, and nothing changes on the site until publication. Fields hidden by `canSee()` or `readonly()` stay as they are.
- **Trash.** A deleted page or record is kept whole in history. `GET history/trash` returns deleted items. Restoring creates the object again with the same id, as a draft. If the parent is gone, the object goes to the top level. If the URL is taken, the response is 409.
- **API.** `GET history/{page|item|settings|menu}/{id}?locale=&limit=&offset=`, `GET history/revision/{id}`, `POST history/revision/{id}/restore`, `GET history/trash?kind=&key=`.
- The change description is written in the language of the person who made the edit and is not translated.

### Concurrent edits

A page and a collection record return a `version` field, a hash of the draft. The admin autosave sends it as `baseVersion`. If the draft changed in the meantime (another person, an assistant through MCP, a move in the tree), the server writes nothing and returns `409` with `details.code: "conflict"` and `details.current` (`version`, `by`, `at`, `via`, `viaName`). The editor stops autosave and asks: **Show the new version** or **Keep my edits**.

Publication also checks `baseVersion` and returns `409` for a stale draft. A request without `baseVersion` is not checked: MCP tools, the console, site code and `PATCH` use "last write wins"; publication does too if the version is omitted. MCP tools that change a page or a record run one at a time per object under a PostgreSQL advisory lock.

A collection record response always carries the service keys `id`, `locale`, `translated`, `status`, `updatedAt`, `awaiting`, `returned`, `changed`, `changes` and `version`. Do not give collection fields these names. `itk:schema:lint` and `itk:doctor` report the `version` key as an error.

### Admin appearance

The developer sets the colors and the logo in the configuration. An administrator changes the colors in **Settings** → **Admin panel appearance** (the `set:theme` permission).

```yaml
itk_cms:
    admin:
        theme:
            brand: '#0B5D80'        # main color: left column, primary buttons, links
            accent: '#C8217A'       # accent: unsaved-change marks, counters
            radius: 6               # corner radius of buttons and inputs, px, from 0 to 16
            logo: /build/logo.svg   # logo in the left column and on the sign-in page; null means the ITK CMS logo
            name: My Studio         # name next to the logo, up to 40 characters; null means "ITK CMS"
```

- A palette from 50 to 950 in OKLCH is built from the color. A color on which neither white nor dark text is readable (contrast below 4.5:1) cannot be saved (422).
- The saved appearance is stored in the `adminTheme` setting. **Reset** returns the developer's configuration.
- Variables for plugin styles: `--brand`, `--brand-ink`, `--brand-text`, `--brand-50` … `--brand-950`, `--accent`, `--accent-ink`, `--accent-text`, `--accent-50` … `--accent-950`, `--r`, `--r-lg`.
- API: `GET admin-theme`, `POST admin-theme/preview`, `PUT admin-theme`, `DELETE admin-theme`.

### Admin language

The admin panel, its e-mails, the console and API messages are translated into English (`en`), Russian (`ru`) and Kazakh (`kk`). This is the interface language. It is not tied to the content language.

```yaml
itk_cms:
    main_locale: en              # content language, ru by default
    admin_locale: en             # default admin language, en by default
    admin_locales: [en, ru, kk]  # what a person chooses from; admin_locale must be in the list
```

Each person chooses a language in **My profile** (`PUT {admin}/api/me/locale`). An empty choice means `admin_locale`.

| Where | Language |
|---|---|
| The admin panel, its API, MCP | The person's language, otherwise `admin_locale` |
| Sign-in and password recovery pages | `admin_locale` |
| An e-mail to a specific person | That person's language |
| Request e-mails, Telegram and MAX messages | `admin_locale` |
| Console and worker | `admin_locale` |
| Site form responses | `main_locale` if it is in `admin_locales`, otherwise `admin_locale` |
| The preview canvas | The content language. The panel above a section is in the person's language |

The bundle turns on `framework.translator` and appends `en` to the end of `fallbacks`. In the console, the translator works in `admin_locale`. If site code calls `trans()` there, pass the language explicitly.

**Your own wording.** Server texts are in `translations/itk_<area>.<language>.yaml`. The areas are `content`, `http`, `i18n`, `model`, `ops`, `preview`. To change a phrase, put a file with the same name in the site's `translations/` and list only the changed keys in it. This file does not change the texts of the Vue part of the admin panel. A plugin changes those with `window.ITK.i18n.add('ru', {...})`.

In PHP, `Itk\Cms\I18n\I18n::t('area.key', ['name' => $x])` returns interface text. The `count` parameter and forms separated by `|` in the Symfony order set the plural. A key that is not in the catalog is returned as is, and a warning goes to the log. Twig has `itk_t()` and `itk_locale()`.

A site does not register new interface languages. A language is added to the bundle itself. The steps are in [CONTRIBUTING.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.md#add-an-interface-language).

## SEO

### SEO rules for URLs

A URL rule sets the title, description, indexing (`robots`), canonical URL and social image for any site URL: a CMS page or a route of the site itself. Rules are edited in **Settings** → **SEO** → **Address rules** (the `set:seo` area). A rule wins over the page's own SEO, but changes only the fields it fills.

- A rule's address is a path with an optional query string (`/catalog`, `/catalog?page=2`). A rule with parameters matches if the request has the same parameters. Extra parameters do not get in the way.
- `match: exact` applies only to this URL. `match: prefix` applies to the URL and everything inside it, by whole segments: `/blog` covers `/blog/a`, but not `/blogger`.
- Among matching rules, the most specific one wins: more parameters, `exact` before `prefix`, a longer path, a rule in the request's language, a newer rule.
- A tag value comes from the rule, then from the page SEO, then from the page title (only `title`), then from the `itk_seo()` argument. Relative `canonical` and `ogImage` become full URLs. Rules do not apply on an error page.

The layout outputs the tags with `itk_seo()`. It works in any template and returns `{title, description, robots, canonical, ogImage, rule, alternates}`:

```twig
{% set seo = itk_seo({title: 'My site', robots: 'index,follow'}) %}
<title>{{ seo.title }}</title>
{% if seo.canonical != '' %}<link rel="canonical" href="{{ seo.canonical }}">{% endif %}
```

The API offers `GET seo-rules?q=&locale=`, `POST seo-rules` and `PATCH|DELETE seo-rules/{id}`. `GET seo-rules/check?path=&locale=` shows what answers the URL, the matching rules and the sources of the page's tags. Its `locale` filter applies only to the rules list. The URL's language determines the metadata.

### sitemap.xml

The core serves `GET /sitemap.xml` from the published pages. The site does not need to write a sitemap.

- The sitemap includes published pages, each with `<loc>` and `<lastmod>`. Pages with indexing turned off (in the page SEO or by a URL rule) are left out. Collection records are not included.
- On a single-language site, URLs use the **Site address** setting (`domain`) and default to `https://` when the scheme is omitted. A multilingual site uses the trusted public base for links in both HTML and the sitemap. The generated route returns 404 when its base is missing. [Multilingual indexing and operations](#multilingual-indexing-and-operations)
- The sitemap is cached for an hour. The cache is cleared when a page is published, unpublished, changed or deleted, and when a URL rule changes.
- A site's own `public/sitemap.xml` file wins, because the web server serves it before PHP.

### robots.txt and llms.txt

The core serves `GET /robots.txt` and `GET /llms.txt` (a site description for AI services in the [llmstxt.org](https://llmstxt.org) format). The text is edited in **Settings** → **SEO** → **robots.txt and llms.txt** (the `set:seo` area), and the edits go into history.

- The response comes from the first of these that exists: a file in the site's `public/`, a custom text from the admin panel, the standard text.
- The standard `robots.txt` opens the site and closes the admin path, `/request/` and `/mcp`. It adds the `Sitemap:` line when the sitemap has a base URL.
- The standard `llms.txt` has a site title, the SEO description of the home page if available, and one `- [Title](url): description` line per indexable page. Single-language sites use one `## Pages` section. Multilingual sites use a section per language.
- A custom text is up to 64 KB. An empty field, the **Back to the standard text** button, and a text equal to the standard one all return the standard text.
- You cannot create a page with the URL `/robots.txt` or `/llms.txt`.
- The **AI search** line on **Site status** warns if `robots.txt` closes at least one AI bot: OAI-SearchBot, Claude-SearchBot, PerplexityBot, Googlebot, Bingbot, YandexBot, YandexAdditional, YandexAdditionalBot, ChatGPT-User, Claude-User, Perplexity-User, GPTBot, ClaudeBot, Google-Extended.
- API: `GET {admin}/api/seo-files` returns `{robots: {text, default, custom, file}, llms: {...}}`. `PUT {admin}/api/seo-files {robots?, llms?}`, where `null` or `''` returns the standard text.

### schema.org markup

The core puts a `<script type="application/ld+json">` block into every published page before `</head>`. You do not need to change the templates. The block's `@graph` contains:

- `WebSite`: the URL, the name (`siteName`), the language.
- `Organization` or `LocalBusiness`: the name, the site URL, the logo, phone, e-mail, address (`PostalAddress`), social links (`sameAs`).
- `BreadcrumbList`: the chain of the page's published parents.
- `BlogPosting` for a blog article: the headline, the date from the **Date** field or the publication date, the cover, the description, the author and the publisher.

The company data is in the **Site** group (see [Settings](#settings)). `organizationLinks` holds one `http(s)` link per line. `schemaOrgOff` turns the markup off.

- Without a **Site address** there is no block.
- The core does not repeat a type that the site already outputs in its own `application/ld+json` block. `Organization` and `LocalBusiness` count as one type.
- The preview has no block.

A listener of the `Itk\Cms\Event\SchemaOrgGraph` event adds your own types. The event has `page` (`id`, `template`, `locale`, `title`, `url`, `fields`), `base`, and the methods `add()`, `remove()`, `nodes()`.

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

### Analytics and verification codes

The owner enters counter IDs and verification codes in **Settings** → **General** → **Analytics and webmaster tools** (the `set:analytics` area, by default only the administrator has it). The core puts them on public pages.

| Field | Format |
|---|---|
| Yandex Metrika counter ID (`yandexMetrikaId`) | 4 to 12 digits |
| Webvisor (`yandexMetrikaWebvisor`) | A toggle. Works when a counter is set |
| Google Analytics 4 (`googleAnalyticsId`) | `G-XXXXXXXXXX` |
| Yandex Webmaster, Google Search Console (`yandexVerification`, `googleVerification`) | The code from the meta tag, or the whole meta tag. Latin letters, digits, `-` and `_`, 8 to 120 characters |

Set `itk_cms.analytics.start` in `config/packages/itk_cms.yaml`. This option has no control in the admin panel.

```yaml
itk_cms:
    analytics:
        start: manual # auto by default
```

- `auto` starts configured counters when the bundle loader runs. Metrika's `<noscript>` pixel follows the opening `<body>` and can send a request when JavaScript is disabled.
- `manual` waits for the site's call to `window.itkAnalytics.start()`. The bundle makes no requests or preliminary connections to Yandex or Google before that call and outputs no `<noscript>` pixel.
- Both modes output verification meta tags, including when no counter is configured. Distinct Yandex verification codes are preserved, and identical tags are deduplicated.

When at least one valid counter is configured, a single versioned external `defer` loader, marked with `data-itk-analytics`, goes before the first `</head>`. Its URL follows the site's asset configuration and base path. Its `data-*` attributes carry the mode, validated IDs and Webvisor flag. The loader needs no executable inline script.

The browser API is available after the loader runs. `window.itkAnalytics.start()` starts all configured counters. `window.itkAnalytics.start(['ga'])` starts only GA. The allowed keys are `metrika` and `ga`. Unknown and unconfigured keys are skipped. Repeated or overlapping calls initialize each counter once per page.

After beginning initialization of new counters, the loader dispatches `itk-analytics:started` on `document` with `bubbles: true` and `detail.counters`, for example `['ga']`. Listeners on both `document` and `window` receive it. A call that starts no new counter emits no event. The event records initialization starting, without waiting for provider downloads or delivery.

In `auto`, the event can fire while the loader runs, before a later deferred site script runs. Check `window.itkAnalytics?.started` before subscribing. The array lists the counters this loader has started. Check for the specific key your code needs.

**Migrating early calls from 0.2.** Even in `auto`, `ym`, `dataLayer` and `gtag` may be undefined during HTML parsing or in a deferred site script that precedes the loader. Move those calls into `DOMContentLoaded` handlers in `auto`, or wait for the required counter as below. In `manual`, the site controls when counters start. Save this example as `public/js/analytics-events.js` and include it with `defer` in the site's layout. Replace the GA ID and event with the site's values.

```js
function whenCounterStarts(counter, callback) {
    if (window.itkAnalytics?.started.includes(counter)) {
        callback();
        return;
    }

    function onStarted(event) {
        if (!event.detail.counters.includes(counter)) return;
        document.removeEventListener('itk-analytics:started', onStarted);
        callback();
    }

    document.addEventListener('itk-analytics:started', onStarted);
}

whenCounterStarts('ga', () => {
    window.gtag('event', 'contact_view', { send_to: 'G-XXXXXXXXXX' });
});
```

For Metrika, use the `metrika` key and call `window.ym` with the site's counter ID inside the callback. To keep early calls in `auto`, define queue stubs in an external script that executes before both the loader and the calls. A synchronous script in `<head>` works. The loader preserves existing `ym`, `dataLayer` and `gtag` queues. Limit the stubs to queuing calls.

```js
if (!window.ym) {
    window.ym = function () {
        (window.ym.a = window.ym.a || []).push(arguments);
    };
    window.ym.l = Date.now();
}
window.dataLayer = window.dataLayer || [];
window.gtag = window.gtag || function () {
    window.dataLayer.push(arguments);
};
```

For a site's own consent banner, include an external script in the site's Twig layout and use its existing accept button. Use `defer` as shown, or place a synchronous script after the closing `</head>` and after the banner markup. This ensures the script can find the loader tag and the button in the DOM. This example uses a button with `id="accept-analytics"` in `<body>`.

```twig
{# In <head> with defer. #}
<script src="{{ app.request.basePath }}/js/analytics-consent.js" defer></script>
{# In <body>. #}
<button type="button" id="accept-analytics">Allow analytics</button>
```

Save the following code as `public/js/analytics-consent.js`. It waits for the bundle script if the site's script runs first. Finding the script by its attribute also works when the site is served from a subdirectory.

```js
function startAnalytics(counters) {
    if (window.itkAnalytics) {
        window.itkAnalytics.start(counters);
        return;
    }

    document.querySelector('script[data-itk-analytics]')
        ?.addEventListener('load', () => {
            window.itkAnalytics?.start(counters);
        }, { once: true });
}

document.getElementById('accept-analytics')?.addEventListener('click', () => {
    startAnalytics();
    // Use startAnalytics(['ga']) for a choice that allows only GA.
});
```

The site implements the banner, stores the visitor's choice and calls the same function on later visits only when that choice permits analytics. On revocation, update the stored choice and reload the page without calling `start()`. The CMS does not stop counters already running on the current page. Before enabling `manual`, remove direct counter scripts, pixels, tag-manager triggers and analytics `preconnect` or `dns-prefetch` links from the site's templates and scripts so they cannot contact providers before the site's choice.

For a CSP based on source allowlists, allow the bundle and site scripts from their actual asset origin in `script-src`. The following table is a starting allowlist for the standard provider integrations. Include only the providers and features the site uses.

| Directive | Provider sources and scope |
|---|---|
| `script-src` | `https://mc.yandex.ru` for Metrika's `tag.js`, `https://www.googletagmanager.com` for GA's `gtag/js` |
| `connect-src` | `https://mc.yandex.ru` and `https://mc.yandex.com` for Metrika collection, `https://*.google-analytics.com` for GA collection including regional endpoints |
| `img-src` | `https://mc.yandex.ru` for the Metrika pixel in `auto`, and the relevant collection origins above if the provider uses image requests |
| `frame-src` | When Webvisor uses frames, allow the frame origins required by the enabled recording and playback features |

Provider endpoints can vary with region, Webvisor features, Google Signals, advertising integrations and custom collection endpoints. The table covers the bundle's standard script URLs and common collection origins. Check the site's CSP reports and browser network log with its actual provider settings to complete the allowlist, including any Webvisor frame origins. The CMS does not change the site's CSP or grant provider origins automatically.

With `script-src 'nonce-…' 'strict-dynamic'`, authorize the loader with a nonce. The site must generate an unpredictable nonce for each response, set the trusted Symfony request attribute `csp_nonce` before rendering (for example, in an early `kernel.request` listener), and put the same nonce in the response's CSP. The CMS copies the server-set attribute to the loader tag. With `strict-dynamic`, the trusted loader can load the provider scripts it creates. The `connect-src`, `img-src` and applicable `frame-src` rules still apply.

Use the same nonce on the site's own scripts.

```twig
<script src="{{ app.request.basePath }}/js/analytics-consent.js"
        nonce="{{ app.request.attributes.get('csp_nonce') }}" defer></script>
{{ itk_stylesheets({nonce: app.request.attributes.get('csp_nonce')}) }}
```

A request with `csp_nonce` bypasses the CMS page HTML cache and conditional ETag handling to keep each response's nonce fresh. Configure any site-owned or upstream response cache accordingly. Pass the nonce explicitly to the stylesheet helper. See [Critical CSS](#critical-css).

- The tags go into CMS pages and pages of site controllers for the main request with status 200 or 404, `text/html` and an ordinary `Response`. Preview, admin, API, MCP, profiler, 304, redirects, streamed and compressed responses are excluded.
- In `auto`, the core skips a counter already present in the HTML with the same ID. Verification tags are deduplicated by name and code in both modes.
- An invalid value is not output to the site. **Site status** shows it marked as **warning**.

### Yandex Webmaster

The owner pastes a Yandex OAuth token once on the **Today** screen. After that, the screen shows whether ownership is verified, the site quality index (IKS), the number of pages in search, and popular queries.

**Token.** The server administrator registers an app at [oauth.yandex.ru](https://oauth.yandex.ru/): the "Web services" platform, Redirect URI `https://oauth.yandex.ru/verification_code`, the `webmaster:hostinfo` and `webmaster:verify` permissions. The app ID goes into the `ITK_YANDEX_CLIENT_ID` variable (the `yandex_webmaster.client_id` option). **Today** then shows a link to get the token. Without the ID, the owner brings the token on their own. The token lives 6 months and is not renewed.

**Connection.** The core checks the token and finds the site with the **Site address** among the owner's sites, or adds it. The core puts the verification code on the pages as a `yandex-verification` meta tag and asks Yandex to check it. When ownership is verified, the core adds `/sitemap.xml` once. An address without a public host (`localhost`, an IP, `.test` and other special-use zones) cannot be connected.

- The summary is stored in the `cache.app` cache and is refreshed when **Today** opens, if it is older than 6 hours.
- The state (`state` in `GET yandex-webmaster`): `not_connected`, `no_address`, `no_http_client`, `token_invalid`, `verifying`, `verification_failed`, `ready`. `tokenExpiresSoon` comes 14 days before the token expires.
- The token is stored encrypted with a key from `APP_SECRET` and never appears in API responses, the log or the settings export. After `APP_SECRET` changes, enter the token again.
- API (the `set:analytics` area): `GET yandex-webmaster` (**View**), `POST yandex-webmaster/connect {token}` (**Edit**; 422 for an invalid token or address, 502 if Yandex does not respond), `POST yandex-webmaster/refresh` (**View**), `DELETE yandex-webmaster` (**Edit**; the site stays in Webmaster).

### IndexNow

The **IndexNow: tell search engines** toggle (`indexNow`) is in the **Analytics and webmaster tools** group. When it is on, the core tells Yandex and Bing about a changed page URL using the [IndexNow](https://www.indexnow.org/) protocol: publication, a new URL (both the old and the new one are sent), unpublication, deletion, restoration.

- The URLs of pages from the sitemap are sent. Collection records are not sent.
- The worker collects up to 100 URLs and sends them in one request. The full URL is built from the **Site address** at the moment of sending.
- Responses `200` and `202` count as success. `429`, `5xx` and a dropped connection are retried by the `retry_strategy` of the `async` transport. Other `4xx` go straight to the failure transport.
- The key is computed from the site address and `APP_SECRET`, and nothing is stored. The key file `GET /{key}.txt` responds while the toggle is on.
- Nothing is sent while the **Site address** is empty or not public, or without `symfony/http-client`.
- The `indexnow.endpoint` option sets the receiver URL (by default `https://yandex.com/indexnow`). IndexNow search engines pass the notifications to each other. Google does not support IndexNow.

## Requests and notifications

### Receiving requests

A form is described in the schema: `$s->form('call', 'Call request')->fields(...)->telegram('Request #{id}: {phone}')`. The `telegram()` template sets the text of the Telegram and MAX message.

The site sends the form to `POST /request/{form}`. The hidden `itk_hp` field (a trap for bots) must be empty. The response is `{"success": true}` or `{"success": false, "message": "…", "errors": {...}}`. The limit is 10 requests in 15 minutes from one address (`framework.rate_limiter.itk_request_form`, the site can override it).

The request body limit is 256 KiB by default (`itk_cms.requests.max_body`, in bytes). Larger submissions return HTTP 413. JSON accepts one object with up to 1,000 scalar or null values. Malformed JSON and nested values return HTTP 422. For conventional form posts, the CMS uses the fields declared in the schema.

The Docker kit applies a `256k` nginx body limit and PHP `post_max_size=256K` on `/request/`. Larger bodies are refused before PHP parses them, including chunked requests. If you change `max_body`, change both route limits to match. With another proxy, enforce the limit before passing multipart bodies to PHP.

The request is saved and appears in the **Requests** section (the `requests` area). Then the `request.new` notification goes to the channels: the admin bell, Telegram, MAX and e-mail. A request has its own status for each messenger (`channels`). The overall `delivery` status is `failed` if the request did not reach at least one messenger, otherwise `sent` if it reached at least one. A messenger that is not connected leaves the status at `pending`, which is not a failure. To retry undelivered requests, use the button in the admin panel or `php bin/console itk:requests:resend`. The request JSON also has `sendable`: the messengers that never got the request and can take it now (connected, on, with a chat for the form). The request drawer shows **Send to MAX** or **Send to Telegram** for each of them and calls `POST requests/{id}/resend {channel}`.

**Long fields.** The length of a value is counted in characters after trimming and removing tags. A browser form line break (`\r\n`) counts as two characters. Each field defaults to `max(1000)`. A longer value returns HTTP 422 with a field error. `itk:schema:lint` and **Site status** flag fields without an explicit `max()`. Only HTML tags are removed. Other occurrences of `<` get a following space (for example, `<500` becomes `< 500`), which counts towards `max()`. The request card and e-mail show the whole value. The bell shows one short line. MCP `request_get` shows up to 6,000 characters per form value and marks a cut with `…`.

A text too long for one message is split at line breaks. Telegram allows 4,096 visible characters. MAX allows 4,000 HTML characters. Parts go out in order with a one-second pause, or 3.1 seconds for Telegram deliveries of more than 20 parts. A resend of up to 20 parts per channel runs in the request. If a channel needs more than 20 parts, the worker sends them and the API returns HTTP 202 with `queuedChannels` after the queue accepts the job. The admin panel shows **Queued**.

A rejected part stops that chat's delivery and the error shows which part failed. The next attempt starts from the first part for each chat, so some messages may appear twice. The built-in `async` transport retries request delivery after 60, 120 and 240 seconds, then moves it to the failure transport. Transport settings supplied by the site take precedence.

The **Requests** screen has a **Board** view with the columns **New**, **In progress** and **Closed**.

### Telegram

Telegram is optional. Without it, the request is saved, and the **Telegram** line on **Site status** says **Telegram is not connected**.

1. Install the packages: `composer require symfony/notifier symfony/telegram-notifier symfony/http-client`.
2. Set the bot token in **Settings** → **Bots** (see the section below). A token from the admin panel takes effect immediately. A fallback is set on the server:

   ```yaml
   framework:
       notifier:
           chatter_transports:
               telegram: '%env(TELEGRAM_DSN)%'   # telegram://BOT_TOKEN@default
   ```

   `null://null` and an unset variable mean "not connected".
3. Set the chats in **Settings** → **Notifications** (`tgRoutes`). The `itk_cms.telegram.chat_ids` option sets fallback chats.

If Telegram is connected but a form has no chat, requests are saved without sending, and **Site status** warns about it. The `itk_cms.telegram.enabled: false` option turns sending off. Only a message that a connected Telegram refused counts as a failure. Such a request is marked as not delivered and is retried by `retry_strategy`.

### MAX

MAX (max.ru) works alongside Telegram and independently of it.

1. Install the bundle: `composer require yuri-kovalev/max-bot-symfony-bundle`.
2. Set the token in **Settings** → **Bots**. The server fallback token:

   ```yaml
   # config/packages/max_bot.yaml
   max_bot:
       access_token: '%env(MAX_BOT_ACCESS_TOKEN)%'
       retry_attempts: []   # Messenger does the retries
   ```

3. Add the bot to a chat. `php bin/console max-bot:chats` shows the chat ID. Enter it in **Settings** → **Notifications** → **MAX** (`maxRoutes`, comma-separated integers; a group has a negative number). `itk_cms.max.chat_ids` sets fallback chats.

The **MAX** switch (`maxOn`) and the `itk_cms.max.enabled: false` option work the same way as for Telegram. The **Test** button sends a test message. A MAX failure does not change the Telegram status, and the other way round.

The `set:notifications` area uses `tgOn`, `tgRoutes`, `maxOn`, `maxRoutes` and `mailRoutes`. Read and update them through `GET settings?scope=notifications` and `PATCH settings?scope=notifications`. The response also includes `tgFallback`, `maxFallback` (a chat is set in the configuration), `tgReady`, `maxReady` and `mailReady`. `POST settings/telegram-test` and `POST settings/max-test {form?, chatId?}` send a test message.

### Bots

In **Settings** → **Bots** (the `set:bots` area, by default only the administrator has it) you set the Telegram and MAX tokens without editing files or restarting. A token from the admin panel wins over the server token. Without either, the messenger is not connected.

- The tokens are stored encrypted with a key from `APP_SECRET` in the `telegramBot` and `maxBot` settings. Do not declare schema fields with these keys. The tokens never appear in `GET settings`, history, the export or MCP. After `APP_SECRET` changes, the token from the admin panel counts as unset.
- `GET {admin}/api/settings/bots` returns, for `telegram` and `max`, the fields `tokenSet`, `source` (`admin`, `server` or `null`) and `ready`; `problem` appears only when a problem is found.
- `PUT {admin}/api/settings/bots {telegram?: {token?, clear?}, max?: {token?, clear?}}`. An empty `token` keeps the old one, and `clear: true` removes the admin token. A Telegram token must match `^\d{5,20}:[A-Za-z0-9_-]{30,}$`. A MAX token has no spaces and is up to 512 characters. The token is never returned.

`@BotFather` issues the Telegram token. The MAX partner platform business.max.ru or dev.max.ru issues the MAX token.

### Mail

The site sends e-mails: "set a password" links and e-mails about new requests. E-mails go through the `async` queue, so a worker is needed.

The mail server is chosen at the moment of sending:

1. **Settings** → **Mail** is turned on and contains a server and a sender address.
2. Otherwise, the `MAILER_DSN` variable, if it holds a real transport. `null://null` means mail is not configured.
3. Otherwise, mail is not configured: e-mails are not sent, **Forgot your password?** suggests contacting the administrator, and invitation and reset show a one-time password.

The sender address comes from **Settings** → **Mail**. With only `MAILER_DSN` and no sender address, `noreply@<site domain>` is used.

**The Mail settings** (the `set:mail` area, by default only the administrator has it). Fields: `enabled`, `host`, `port`, `encryption` (`none`, `ssl`, `starttls`), `username`, `password`, `fromAddress`, `fromName`.

- The password is stored encrypted with a key from `APP_SECRET` and is never returned. The response contains only `passwordSet`. An empty `password` keeps the old one, and `clearPassword: true` erases it.
- If `host` or `username` changes, the new password must come in the same request, otherwise 422. This way the old password cannot be sent to another server.
- `GET {admin}/api/settings/mail` returns the fields, `passwordSet`, `configured`, `source` (`settings`, `env`, `none`), `problem` and `last` (the result of the last sending).
- `PUT {admin}/api/settings/mail` saves the settings.
- `POST {admin}/api/settings/mail-test {to?}` sends a test e-mail right away, without the queue. The server's refusal reason comes in the response (502). At most 10 e-mails per hour per person (429).
- `ssl` uses `smtps://` and `starttls` requires TLS. Choose either when saving a password. `none` is available for servers without a password. The SMTP dialog is excluded from logs. If the active `MAILER_DSN` uses password authentication with optional TLS, `itk:doctor` and **Site status** show a warning. Use `smtps://` or set `require_tls=1` on `smtp://`.

**Errors.** A delivery that exhausts its retries stays in the failure transport and raises a `mail.failed` notification. Password and invitation deliveries store a job. The worker creates its one-time link for each attempt and revokes it when the attempt fails.

**Request e-mails.** **Settings** → **Notifications** → **Emails about new requests** (`mailRoutes`, the `set:notifications` area): comma-separated addresses for all forms and a separate list for each form, up to 10 addresses. Each address gets an e-mail with the form fields and a link to the request.

Custom request filters use calendar dates in `YYYY-MM-DD` format. Telegram and MAX delivery events run after the CMS saves the outcome.

**E-mail templates.** `@ItkCms/email/layout.html.twig` and the pairs `reset_password`, `invite`, `request`, `test` (`.html.twig` and `.txt.twig`). The site overrides any file with its own file in `templates/email/`, for example `templates/email/layout.html.twig`. Inside, you can extend the bundle template: `{% extends '@ItkCms/email/reset_password.html.twig' %}`.

### Admin notifications and custom channels

`Itk\Cms\Notification\NotifierInterface::notify()` sends messages to the people who run the site. The core and site code call it.

```php
$notifier->notify(new Notification(
    type: 'order.paid',
    title: 'Order #15 paid',
    body: '3,200 USD',
    link: '/collections/orders',      // an admin route or a full http(s) URL
    level: Notification::WARNING,     // INFO, WARNING, ERROR
    audience: 'col:orders',           // null for everyone signed in; a permission ('requests', 'col:tours:edit'); 'role:editor'
    payload: ['orderId' => 15],       // data for the channels, no secrets
    key: 'order.paid:15',             // one notification per key
));
```

- **Channels** implement `NotificationChannelInterface` (`name()`, `supports()`, `send()`), and the `itk.notification_channel` tag is added automatically. A class that is not a service is listed in `Registry::notificationChannels()`. A channel error goes to the log and does not affect the others.
- **`DatabaseChannel`** stores the notification for the bell. A person sees the notifications that match their permissions and role and were sent after they registered. `php bin/console itk:notifications:prune [--days=90] [--dry-run]` deletes old ones. Put it in cron.
- **`TelegramChannel`, `MaxChannel`, `EmailChannel`** deliver only `request.new`.
- **Core events:** `request.new` (a new request, for the `requests` permission), `telegram.failed` and `max.failed` (a request did not get through), `mail.failed` (an e-mail was not sent). A failure notification is removed when delivery succeeds.
- **Delivery outcome** comes as the Symfony event `Itk\Cms\Event\RequestDelivery { request, delivered, error, cancelled, channel }` after the status is committed. `channel` is `telegram` or `max`. Listeners that edit the request reload it by ID and save explicitly.
- **Language.** `new Notification(title: …)` stores ready text. `Notification::translated(type: …, titleKey: 'area.key', titleParams: [...])` stores a key, and each reader sees the title in their own language.
- **API.** `GET notifications?unread=1&limit=&offset=`, `POST notifications/read { ids } | { all: true }`. Each person reads only their own.

## Knowledge base

The knowledge base is a pgvector table with the text of published pages and records, split into chunks with OpenAI embeddings. The `KnowledgeBase\KnowledgeSearch` service searches by meaning. The worker updates the index after publication and unpublication, and deletion removes the rows on its own. It is off by default.

**What leaves the site.** The published site text is sent to the OpenAI API. This is only what any visitor sees, but the site owner decides whether to send it. Drafts, hidden sections, `raw()` fields, fields with `canSee()` and pages with `noindex` are not sent.

**How to turn it on.** You need the `symfony/http-client` package and an OpenAI key.

1. Run `composer require symfony/http-client`. Put the key in `.env.local`: `OPENAI_API_KEY=sk-...`.
2. Turn on the option:

   ```yaml
   itk_cms:
       knowledge_base:
           enabled: true
   ```

3. Fill the index: `php bin/console itk:knowledge-base:sync --now`. The command prints the number of texts sent and tokens spent.
4. Start the worker for the `knowledge_base` transport.
5. Test the search: `php bin/console itk:knowledge-base:search "how to return an item"`.

Add `itk:knowledge-base:sync` to the deploy script. The command queues only what went stale after the deploy (schema, configuration, model).

**What is indexed.** Pages from the sitemap (published, without `noindex`) and published collection records, except `exclude_templates` and `exclude_collections`. From each version, the text a visitor sees is taken: the title, the SEO description, the template fields, the visible sections in order. `Text`, `Textarea`, `Headline`, `Description` and `Article` give text. `Select` gives "label: option", `Image` and `Photos` give `alt`, and each `Repeater` row gives a separate block. `Number`, `Toggle`, `Date`, `Link`, `Relation`, `Note`, and `variant()`, `raw()` and `canSee()` fields are not indexed.

Custom fields contribute when they implement `PublicFieldInterface`, report public visibility and provide a supported field type. Search vectors use up to 256 cache slots with a 24-hour lifetime. A slot collision replaces the previous entry.

**Chunks.** The blocks of one section are combined into chunks of about 1200 characters, at most 2000. A chunk does not cross a section boundary, so a hit has an exact `sectionId`. The page title and the block heading are sent to the embedder before the chunk text.

**Updates.** An embedding is built only for text that is not yet in the index. The worker collects up to 32 messages into one API request. An unpublished page disappears from the results right away. A draft autosave triggers nothing. The index catches up with a publication within a minute, because the Doctrine transport polls the queues every `check_delayed_interval` (60 seconds). A separate `messenger:consume knowledge_base` worker is faster.

**Changing the model.** After `model` changes, the search finds nothing until `itk:knowledge-base:sync --now` rebuilds the index. **Site status** shows how many documents are left in the old model.

**Searching from code.**

```php
$result = $knowledge->search($request->query->getString('q'), new SearchOptions(limit: 20));
foreach ($result->bySource() as $hit) {
    // $hit->source->title, $hit->source->url, $hit->text
}
```

- `SearchOptions`: `locale`, `limit` (1 to 50, 8 by default), `templates` and `collections` (`null` for all, `[]` for none), `minScore`.
- `SearchResult::$hits` is a list of `Hit` in descending `score` order. `bySource()` keeps the best hit per page or record.
- `Hit`: `score`, `text`, `heading`, `source` (`kind`, `id`, `locale`, `resourceKey`, `title`, `url`), `sectionId`, `sectionType`, `field`.
- A disabled knowledge base or an API failure throws `KnowledgeBaseUnavailable`. An unknown template or collection throws `\InvalidArgumentException`.

**Commands.** `itk:knowledge-base:sync` queues missing, stale and extra documents. `--now` does it in-process and exits with code 1 on failure. `--dry-run` prints the counters and an estimated cost. `--source=page:<id>[:<locale>]` or `item:<id>[:<locale>]` limits it to one source. `itk:knowledge-base:search "<query>" [--locale=] [--limit=8] [--template=] [--collection=]` prints the hits as a table.

**Failures.** 429, a server error and a network drop are retried after 10, 30, 90, 270 and 810 seconds, and then the message goes to the failure transport. An invalid key, an unknown model and a missing `symfony/http-client` go straight to the failure transport. After you fix the cause, `messenger:failed:retry` brings them back.

A site can replace the embedder with its own implementation of `KnowledgeBase\Embedding\Embedder` with 1536-dimensional vectors.

## Performance

### Headers and 304 responses

- **Security headers.** The public routes of the bundle (pages, `sitemap.xml`, `robots.txt`, `llms.txt`, image copies, `POST /request/{form}`, `/healthz`) get `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`. The `security_headers: false` option turns them off. The site sets headers for its own controllers itself.
- **ETag and 304.** A visitor without a session and without `Authorization` gets `ETag`, `Last-Modified` and `Cache-Control: no-cache, private` for a page. If the visitor sends the same `ETag` in `If-None-Match`, a 304 response comes without rendering.
- The tag depends on everything the page can show: the page itself, the published pages and their order, settings, menus, published records, image crops, SEO rules, the query string, the container build and the current UTC date. Drafts do not affect the tag. So a page that depends on the time of day updates at least once a day. If pages must update more often, turn off `conditional_responses`.
- The tag needs one query with sums over the tables, including all collection records. On 30,000 records this takes about 0.1 seconds. The same query builds the page cache key. For a site with very large collections, turn off both `conditional_responses` and `page_cache.enabled`.
- Debug mode sends no tag. In production, run `cache:clear` after you deploy new templates. Otherwise visitors with a cached page get 304 with the old markup.

### Page cache

The rendered HTML of a published page is cached, and a repeat view is served without rendering. Edits are visible to visitors right away: the entry key is built from the same fingerprint as the `ETag`, and any edit gives a new key.

- **Key.** The scheme, the host, the base path and the content fingerprint. Cookies, `User-Agent`, `Accept-Language` and other headers are not part of the key.
- **What is stored.** Only a `200` response to `GET` without `Set-Cookie`, if no session was started. 404, redirects, the preview, `sitemap.xml`, `robots.txt`, `llms.txt` and forms are not stored. `HEAD` uses the entry made by `GET`.
- **Requests that bypass the cache:** when the option is off and in debug, with `Authorization` or a session, with a method other than `GET` and `HEAD`, with a query string, with a `Forwarded` or `X-Forwarded-Prefix` header, with a URL spelling that differs from the page URL (`/ABOUT`, `//about`), and to a host or port that is not trusted (not in `framework.trusted_hosts`, or, without trusted hosts, not the host and port of the site address). This way an outsider cannot fill the disk with entries or replace a page for everyone.
- **Your host.** A host is cached if `framework.trusted_hosts` is set or the host matches the **Site address**. The port must be the default port or the port from the **Site address**. Otherwise the cache does not work. A site behind a proxy sets `trusted_proxies`.
- **`X-ITK-Cache`.** Every page response carries `HIT`, `MISS` or `BYPASS`. You can check the cache with `curl -I`. A 304 reports `HIT`.
- **What the fingerprint does not see.** Data in the site's own tables and the time within a day. For those there is the entry lifetime `page_cache.ttl` (3600 seconds) and the `itk_cache()` function. `{% do itk_cache(300) %}` keeps the rendering for at most 300 seconds, and `{% do itk_cache(false) %}` does not keep it at all. Among several calls, `false` wins, otherwise the smaller number. **A template whose output depends on a cookie, `User-Agent` or `Accept-Language` must call `{% do itk_cache(false) %}`.** `itk_cache()` does not affect 304.
- **Manual clearing.** `PageCache::clear()` from site code (returns `false` if the pool failed), the `itk:cache:clear` command, and the **Clear the page cache** button on **Site status** (`POST {admin}/api/health/page-cache/clear`, the **Edit** level of the `dev` area). Only the page cache pool is cleared.
- **Storage.** The `itk_cms.page_cache` pool on the `cache.app` adapter. On one server these are files in `var/cache/<env>/pools`. For several servers, give the pool a shared adapter in `framework.cache.pools`. Expired entries are deleted at most once every 10 minutes. Put `bin/console cache:pool:prune` in cron.

### Critical CSS

The rules the first screen needs go straight into `<head>`, and the full stylesheet loads without blocking rendering. [crittr](https://github.com/philipp-winterle/crittr) extracts the rules in the worker after each publication. It is off by default.

**How to turn it on.** You need Node 22 or newer and Chrome or Chromium on the server.

Run Chrome as a non-root user with its sandbox available. The browser verifies TLS and can fetch only the local render server. Serve critical assets from the site's `public/` directory. External resources are unavailable during extraction.

1. In the project root, run `npm install crittr`. Puppeteer downloads Chrome for the user who ran the command. To use the system Chrome, set the `chrome` option.
2. Turn on the option and list the stylesheets:

   ```yaml
   itk_cms:
       critical_css:
           enabled: true
           stylesheets: ['/css/site.css']
   ```

3. In the layout, replace `<link rel="stylesheet" href="/css/site.css">` with `{{ itk_stylesheets() }}`.
4. Test one page: `php bin/console itk:critical-css:generate --page=<id> --now`. The command prints the reason for each failure.
5. Start the worker for the `critical_css` transport.
6. Queue the remaining pages: `php bin/console itk:critical-css:generate` (`--all` for all published pages).

**What the visitor gets.** On a page with ready critical CSS, `itk_stylesheets()` prints:

```html
<style>…</style>
<link rel="stylesheet" href="/css/site.css?v=3f9a1c2e" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="/css/site.css?v=3f9a1c2e"></noscript>
```

In all other cases (turned off, not ready yet, failed, preview, a site controller, an error page), the function prints an ordinary link. `?v=` changes together with the stylesheet. While the option is off, there is no `?v=`.

- `<style>` holds the union of the rules of all windows from `viewports`, with the cascade preserved. If the windows cannot be reconciled, one `<style media="…">` per width range is output.
- Critical CSS goes stale when the page is published again, when the container is rebuilt and when the stylesheets change. The stale CSS is shown while the worker builds a new one. The first view asks the worker to recompute the page. After you deploy new CSS, you do not need to do anything. `itk:critical-css:generate` in the deploy script only speeds up the recomputation.
- Settings, menus and collection records do not affect critical CSS. If a menu changed the first screen, run `itk:critical-css:generate --all`.
- A page that crittr failed on is not recomputed automatically again. `itk:critical-css:generate`, a new publication or new CSS retries it.
- A result larger than `max_kb` counts as a failure. If the CSS has `!important` or `@layer`, the worker writes a warning, because crittr loses them.
- The generator does not work with the `sync` transport.

**CSP.** Pass `{{ itk_stylesheets({nonce: nonce}) }}` to put the nonce on each critical `<style>` and use regular links for the full stylesheets. Allow the stylesheet source in the site's CSP.

**In Docker**, the image is built with Node and Chromium with the `CRITICAL_CSS=1` argument (see [Critical CSS in Docker](#critical-css-in-docker)).

## MCP: AI assistants

ITK CMS works as a [Model Context Protocol](https://modelcontextprotocol.io) server. Claude, Cursor, VS Code and other clients read the site and prepare edits in drafts. The assistant acts on behalf of the staff member who issued the key and never gets more than that person's permissions. Every call goes through the same permissions, field rules, hooks and history as the admin API.

### Turning it on and keys

1. **The switch.** **Settings** → **AI assistants** (the `set:mcp` area, by default only the administrator has it). While MCP is off, `/mcp` returns 404, `itk:mcp` does not start, and keys are not created. This screen shows the server address, connection examples and the list of all staff keys with a **Revoke** button.
2. **The key.** **My profile** → **My keys for AI assistants**: a name, scopes and a lifetime (30 or 90 days, a year, no expiry; 90 days by default). The key looks like `itk_` plus 43 characters and is shown once. The database stores its sha256. A person has at most 20 active keys and 10 new ones per hour. A key can be created only from a session that passed two-step sign-in, if it is turned on.
3. **Scopes.** A scope narrows the person's permissions. A tool needs both the key's scope and the person's permission.

   | Scope | What it grants | Default |
   |---|---|---|
   | `read` | View pages, records, the media library, menus, settings, SEO, history | yes |
   | `write` | Create and change drafts, upload files | no |
   | `publish` | Publish and unpublish (requires the **Publish** permission) | no |
   | `requests` | Read and handle requests, including customer phone numbers | no |

   **`requests` cannot be combined with `write` and `publish` in one key.** Visitors write requests, and a key that reads them and changes pages could carry out someone else's instruction from a request. The server answers such a key with 422. If you need both scopes, you need two keys.
4. **What stops a key.** Revocation, expiry, blocking of the owner, the switch turned off, a role that now requires two-step sign-in. A new owner password does not stop the keys. Permissions are taken at the moment of the call. The key is checked on every message, so revocation also stops an assistant that is already running.

### Connecting

HTTP: each call is a `POST /mcp` with the `Authorization: Bearer itk_…` header. The response is `application/json`, without sessions or an event stream.

```bash
# Claude Code
claude mcp add --transport http mysite https://example.com/mcp --header "Authorization: Bearer itk_…"
```

```jsonc
// Claude Desktop, claude_desktop_config.json (requires Node.js; mcp-remote connects the app to the site)
{ "mcpServers": { "mysite": {
    "command": "npx",
    "args": ["-y", "mcp-remote", "https://example.com/mcp", "--header", "Authorization:${AUTH_HEADER}"],
    "env": { "AUTH_HEADER": "Bearer itk_…" } } } }
```

```jsonc
// Cursor: ~/.cursor/mcp.json or .cursor/mcp.json in the project; the key is in an environment variable
{ "mcpServers": { "mysite": {
    "url": "https://example.com/mcp",
    "headers": { "Authorization": "Bearer ${env:ITK_MCP_TOKEN}" } } } }
```

```jsonc
// VS Code (GitHub Copilot, agent mode): .vscode/mcp.json; the key is kept in secure storage
{ "servers": { "mysite": {
    "type": "http",
    "url": "https://example.com/mcp",
    "headers": { "Authorization": "Bearer ${input:itk-key}" } } },
  "inputs": [ { "type": "promptString", "id": "itk-key", "description": "ITK CMS key", "password": true } ] }
```

**claude.ai.** In Customize → Connectors, add your own connector with the address `https://example.com/mcp`, the authentication method "No authentication", and an `Authorization` header with the value `Bearer itk_…` in the "Request headers" section. Request headers are not available to every organization. One key serves all users of the connector, and it cannot be changed after saving. The site must be reachable from the internet over HTTPS.

stdio: the assistant starts `itk:mcp` itself. Pass the key in the `ITK_MCP_TOKEN` variable, not with `--token`, because other users of the machine can see arguments in the process list.

```jsonc
{ "mcpServers": { "mysite": { "command": "php", "args": ["/var/www/site/bin/console", "itk:mcp"], "env": { "ITK_MCP_TOKEN": "itk_…" } } } }
// in Docker
{ "mcpServers": { "mysite": { "command": "docker",
    "args": ["compose", "--project-directory", "/path/to/site", "exec", "-T", "-e", "ITK_MCP_TOKEN", "php", "bin/console", "itk:mcp"],
    "env": { "ITK_MCP_TOKEN": "itk_…" } } } }
```

The server supports protocol versions `2025-11-25`, `2025-06-18`, `2025-03-26` and `2024-11-05`. The server does not support the `2026-07-28` revision without the `initialize` handshake: it answers an unknown `MCP-Protocol-Version` header with 400. OAuth authorization is not offered.

### Tools

| Scope | Tools |
|---|---|
| `read` | `site_schema`, `pages_list`, `page_get`, `collection_list`, `collection_item_get`, `media_search`, `media_get`, `menus_get`, `settings_get`, `seo_rules_list`, `history_list` |
| `write` | `page_create`, `page_update`, `page_section_add`, `page_section_update`, `page_section_move`, `page_section_remove`, `collection_item_create`, `collection_item_update`, `media_upload` |
| `publish` | `page_publish`, `page_unpublish`, `collection_item_publish` |
| `requests` | `requests_list`, `request_get`, `request_update` |

- `site_schema` without arguments gives an overview, and with `kind` and `key` the full description of one section. The `itk://schema` resource returns the same.
- `settings_get` does not return passwords, tokens, the mail server, chats or notification addresses.
- `requests_list` and `media_search` accept `offset` (default 0), `limit` (default 20 for requests and 30 for media, maximum 100), and an optional `cursor` that takes precedence over `offset`. Both return `{ items, total, offset, limit, hasMore, nextCursor, previousCursor }`. Pass `nextCursor` as `cursor` with the same filters to continue through rows ordered by creation time and ID, descending.
- `media_upload` accepts a base64 file of up to 5 MB. The server does not download files by URL.
- `page_unpublish` leaves the page as a draft. The home page cannot be unpublished.
- Through MCP you cannot delete pages, records, files or rules, change users, roles, keys, settings or redirects, or restore versions.
- Nobody changes `raw()` fields through MCP, not even an administrator. Removing or moving a section or a list item with such a field is allowed.
- The assistant can put only a file from the media library into an image field.
- An error that the model can fix comes as a result with `isError: true` and a text. Protocol errors come as JSON-RPC codes.
- Tools that replace values, publish or unpublish carry the `destructiveHint: true` hint.
- The assistant's edits go into history marked as made through an AI assistant, with the key name.

### Protection

- A request with an `Origin` header is accepted only if it is the site address, an address from `mcp.allowed_origins`, or localhost in debug. Everyone else gets 403. Assistant programs do not send `Origin`. This is not CORS: the server answers `OPTIONS` with 405.
- The limit is 120 calls per minute per key (`itk_mcp_token`), and each message in a batch counts as a call. 20 invalid keys in 15 minutes from one address give 429 (`itk_mcp_failed_auth`). The limits use `framework.lock`. On several servers, set a shared lock store.
- The request body is at most `mcp.max_body_mb` (8 MB). For `media_upload`, raise the web server's `client_max_body_size` and PHP's `post_max_size`.
- The `Authorization` header must reach PHP. On Apache, add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
- The core owns the `/mcp` URL, and you cannot create a page with that URL.
- Visitor texts from requests reach the model inside an `<untrusted-data>…</untrusted-data>` frame. The frame is a hint to the model, not a protection. The protection is the separate keys for requests and edits, and the ban on writing `raw()`.
- Give a key the minimum scopes and lifetime. A key is equal to a password within its permissions: keep it in a password manager, and revoke it if it leaks.

### Log

The `itk_mcp` Monolog channel gets each tool call and resource read as an `info` line, and each refusal as a `warning` line: a sign-in refusal (`missing`, `invalid`, `revoked`, `expired`, `blocked`, `two_factor`, `too_many_wrong_keys`), an unknown tool, invalid arguments, a limit. Arguments, responses and the key are never written. The `fingers_crossed` handler drops these lines if the request has no error. Give the channel its own handler:

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

### Custom tool

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

- A service with `McpToolInterface` is picked up automatically (the `itk.mcp_tool` tag). A class without constructor arguments can be listed in an extension: `$registry->mcpTools(ToursCountTool::class)`.
- Write the description for the model in English. `requiredScope()` returns `READ`, `WRITE`, `PUBLISH` or `REQUESTS`.
- `annotations()` is required and must contain the booleans `readOnly` and `destructive`. There is no default.
- The tool set is checked on the first build. Two classes with one name, an unknown scope or incomplete `annotations()` throw `McpToolListError` with the class names. The client gets `Internal error`, and the text goes to the `itk_mcp` channel.
- Arguments are validated against `inputSchema()` before the call: `type`, `enum`, `required`, `properties`, `additionalProperties`, `items`, `minimum`, `maximum`, `minLength`, `maxLength`, `minItems`, `maxItems`.
- If the input needs fixing, throw `McpToolException` with a clear text. A tool that not everyone can use implements `McpConditionalTool::isAvailableTo()`.
- A tool that writes calls the core services (`PageService`, `CollectionService`), not the database. Permissions, hooks and history then work automatically.

## Site status and itk:doctor

### The Site status screen

**For developers** → **Site status** (`{admin}#/health`) is the screen for the person who looks after the site. The `dev` area: **View** opens the screen, and **Edit** allows retrying and deleting failed jobs, sending requests again and clearing the page cache. By default only the administrator has this area.

- **Background jobs.** How many messages wait in each queue, and up to 50 latest failed ones from the failure transport: the job, the reason, the number of attempts. **Try again** returns the message to its queue, and **Delete** removes it. A transport that could not be built is shown with the reason.
- **Undelivered requests.** The latest 20 undelivered requests, without names and phone numbers. **Send again** sends the request to the messenger it did not reach.
- **Schedule.** Symfony Scheduler tasks (`#[AsSchedule]`) and the time of the next run, if there are any.
- **Disk.** Free space for `upload_dir` and `var/`. Less than 2 GB gives a warning, less than 512 MB gives an error.
- **Versions.** PHP, Symfony and `itk/cms`. The update check works when the `update_source` option is set. It accepts a URL that returns `{"version": "…"}`, GitHub `releases/latest`, or Packagist metadata, for example `https://repo.packagist.org/p2/itk/cms.json`. The response is cached for 12 hours.
- **Core checks.** Database, PHP, `APP_SECRET`, upload folder, mail, Telegram, MAX, MCP firewall, analytics, Yandex Webmaster, IndexNow, AI search (AI bots closed in `robots.txt`), critical CSS, knowledge base. Each check shows `ok`, **Not connected**, warning or error. **Not connected** (`off`) is a yellow chip for a module that is switched off or not set up, for example Yandex Webmaster, Telegram or IndexNow. It is not a problem: the overall status of the screen and the exit code of `itk:doctor --ci` do not change.
- **API** (the `dev` area): `GET health`, `POST health/messenger/{id}/retry|delete`, `POST health/requests/{id}/resend {channel?}`, `POST health/updates/refresh`, `POST health/page-cache/clear`.

**Custom checks.** A class implements `Itk\Cms\Health\HealthCheckInterface`: `name()` and `check(): HealthResult` (`HealthResult::ok|off|warn|fail('text')`). Return `off` when the module is switched off or not connected: it ranks like `ok` and does not turn the report into a warning. In a folder that the site container scans, the class is picked up automatically (the `itk.health_check` tag). From a package, list it in `Registry::healthChecks()`. A check must be fast. An exception shows as an error line and does not break the screen.

### itk:doctor

`php bin/console itk:doctor` checks the site in one pass. The command suits deploys and CI.

| Group | What it checks |
|---|---|
| Environment | PHP extensions, WebP, AVIF, `APP_SECRET`, permissions on `upload_dir`, database |
| Site | Trusted site address, applied migrations, published home page, firewalls of the admin panel and `/mcp`, queue and worker (messages older than 5 minutes), built admin panel |
| Site status | Everything the **Site status** screen shows |
| Schema | The same as `itk:schema:lint` |

Each line is `ok`, `off`, `warning` or `ERROR`. `off` marks a module that is not connected and is only counted in the total. With `--ci`, the command exits with code 1 if there is at least one error.

### /healthz

`GET /healthz` is a public endpoint for a load balancer. It returns `{"status":"ok"}` (200) or `{"status":"fail"}` (503). `HEAD /healthz` returns the same status without a body. The endpoint checks database connectivity and write access to `upload_dir` and reserves the `/healthz` path.

## Console commands

| Command | What it does |
|---|---|
| `itk:install` | Prepares site roles, menus, settings, the message queue, the home page and the first administrator. The first password must be 10 to 200 characters. Options include `--email`, `--password-file`, `--name`, `--site-name`, `--domain` and `--no-home` |
| `itk:doctor [--ci]` | Checks the site |
| `itk:schema:lint` | Checks the schema and the Twig templates |
| `itk:user:password <email> [--password=]` | Sets a password |
| `itk:user:reset-2fa <email>` | Resets two-step sign-in |
| `itk:requests:resend` | Queues all undelivered requests |
| `itk:media:warm [--width=]` | Creates copies of the media library images |
| `itk:cache:clear` | Clears the page cache |
| `itk:critical-css:generate` | Queues pages without ready critical CSS. `--all` for all pages, `--page=<id>`, `--locale=`, `--dry-run`, `--now` in this process with a failure report |
| `itk:knowledge-base:sync` | Updates the knowledge base. `--now`, `--dry-run`, `--source=` |
| `itk:knowledge-base:search <query>` | Searches the knowledge base. `--locale`, `--limit`, `--template`, `--collection` |
| `itk:history:prune` | Deletes old history. `--keep`, `--days`, `--dry-run` |
| `itk:notifications:prune` | Deletes old notifications. `--days` (90), `--dry-run` |
| `itk:mcp` | The MCP server over stdio. The key is in `ITK_MCP_TOKEN` |
| `itk:make:template`, `itk:make:section`, `itk:make:collection`, `itk:make:settings`, `itk:make:field`, `itk:make:plugin` | Code generators, see [Code generators](#code-generators) |

Cron usually runs `itk:history:prune`, `itk:notifications:prune` and `cache:pool:prune` once a day.

## Configuration reference

All options are set in `config/packages/itk_cms.yaml` and are optional.

| Option | Default | What it does |
|---|---|---|
| `schema_file` | `%kernel.project_dir%/config/itk/schema.php` | The site schema file |
| `main_locale` | `ru` | The main content language. With `locales`, spaces at the ends are removed and letters are lowercased |
| `locales` | Omitted | Content languages and their names, keyed by code. See [Content language configuration](#content-language-configuration) |
| `admin_locale` | `en` | The default admin language. Must be in `admin_locales` |
| `admin_locales` | `[en, ru, kk]` | The languages a person chooses from |
| `admin_path` | `/admin` | The admin path. The format is checked at startup |
| `public_url` | `null` | The site address for links in e-mails, for example `https://example.com`. `%env(PUBLIC_URL)%` works |
| `requests.max_body` | `262144` | Public form submission body limit in bytes |
| `strict` | `null` (as `kernel.debug`) | Strict template mode |
| `blog` | `true` | `false` removes the blog templates |
| `security_headers` | `true` | Security headers on public routes |
| `conditional_responses` | `true` | `ETag`, `Last-Modified` and 304 for pages |
| `page_cache.enabled` | `true` | The page cache |
| `page_cache.ttl` | `3600` | The entry lifetime in seconds, at least 1 |
| `upload_dir` | `%kernel.project_dir%/public/uploads/media` | The media library folder |
| `upload_url` | `/uploads/media` | The URL of the media library folder |
| `max_upload_mb` | `20` | The media library file size limit |
| `images.widths` | `[320, 640, 960, 1280, 1920, 2560]` | Copy widths |
| `images.quality` | `82` | WebP quality |
| `images.avif_quality` | `60` | AVIF quality |
| `images.max_pixels` | `50000000` | An original larger than this is not converted |
| `images.ratios` | `['16:9', '4:3', '1:1', '3:4']` | Crop ratios |
| `history.keep` | `100` | History entries per object and language |
| `history.days` | `365` | The age of history entries |
| `admin.theme.brand`, `admin.theme.accent` | `#0B5D80`, `#C8217A` | Admin colors |
| `admin.theme.radius` | `6` | Corner radius, from 0 to 16 px |
| `admin.theme.logo`, `admin.theme.name` | `null` | The logo and the name in the admin panel |
| `critical_css.enabled` | `false` | Critical CSS |
| `critical_css.stylesheets` | `[]` | Public paths of the stylesheets in cascade order. At least one is needed when it is on |
| `critical_css.viewports` | `390×844` (phone), `1366×900` | Windows `{width, height, mobile}` |
| `critical_css.keep_selectors`, `critical_css.remove_selectors` | `[]` | Selectors that crittr always keeps or drops |
| `critical_css.node` | `node` | The Node program |
| `critical_css.chrome` | `null` | The path to Chrome. `null` means `PUPPETEER_EXECUTABLE_PATH` or the Chrome from puppeteer |
| `critical_css.timeout` | `60` | Seconds per page in one window, at least 10 |
| `critical_css.max_kb` | `75` | The critical CSS limit in KB |
| `knowledge_base.enabled` | `false` | The knowledge base |
| `knowledge_base.model` | `text-embedding-3-small` | A `text-embedding-3-*` model, 1536-dimensional vectors |
| `knowledge_base.base_url` | `https://api.openai.com/v1` | An OpenAI-compatible API |
| `knowledge_base.timeout` | `30` | Seconds per request, at least 5 |
| `knowledge_base.exclude_templates`, `knowledge_base.exclude_collections` | `[]` | What is not indexed |
| `indexnow.endpoint` | `https://yandex.com/indexnow` | The IndexNow receiver |
| `yandex_webmaster.client_id` | `%env(string:default::ITK_YANDEX_CLIENT_ID)%` | The Yandex OAuth app ID |
| `yandex_webmaster.api_base` | `https://api.webmaster.yandex.net/v4` | The Webmaster API URL |
| `mcp.allowed_origins` | `[]` | Allowed `Origin` values for `/mcp` |
| `mcp.max_body_mb` | `8` | The `/mcp` request body limit, from 1 to 64 |
| `telegram.enabled` | `true` | Sending requests to Telegram |
| `telegram.chat_ids` | `''` | Comma-separated fallback chats. `%env(...)%` works |
| `max.enabled` | `true` | Sending requests to MAX |
| `max.chat_ids` | `''` | Fallback MAX chats, comma-separated integers |
| `update_source` | `null` | The URL for checking for a new version |

### Content language configuration

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

- Language codes are 2 or 3 lowercase Latin letters. Each language needs a non-empty `name`. An explicitly configured list must be non-empty and include `main_locale`. A code must not conflict with a reserved system path segment (for example `mcp`) or the first segment of `admin_path` (including when the conflicting code is the main language).
- `html_lang` defaults to the code. It accepts 2 or 3 lowercase Latin letters with an optional hyphen and 2 to 8 letters or digits, as in `zh-CN`. Each language must have a distinct resulting tag.
- `og_locale` accepts 2 or 3 lowercase Latin letters, an underscore and 2 uppercase Latin letters, as in `zh_CN`. The service can derive it from the region in `html_lang`. It returns `null` when neither is present.
- The admin lists the main language first and then follows the configuration order. When `locales` is omitted, the admin lists only the main language and the API still accepts legacy language codes. An explicit list restricts API inputs to the listed languages. An explicit empty list is rejected; omit `locales` for legacy behavior.
- On a site with multiple configured languages, addresses for the main language have no language prefix. Other versions use `/{code}`. For example, the Kazakh home page uses `/kk`, and other Kazakh pages use paths such as `/kk/about`. The server checks the language prefix when creating a translation, changing its address (including a restore that changes the address), and publishing. Saving other fields without changing the address remains possible for legacy pages with an incompatible prefix.

Inject `Itk\Cms\I18n\ContentLocales` to read the site's language codes, display names, path prefixes and metadata.

#### Editing translations in the admin

- In **Pages** or a collection list, select the language next to a page or record to open that version. A missing version offers **Copy from…** and **Start with a blank page** or **Start with an empty record**. Both create a draft that you can edit and publish in that language. On narrow screens, the language and its status may appear in a single picker.
- Use the language picker in an editor, in the translatable **Settings**, or in **Settings → Menus**. An unset translation opens empty. Fields marked **Same for all languages** have one shared value. Save settings and menus with **Save**.
- In a page or record editor, open **History** and select **This language** or **All languages**. Each revision shows its language. Restoring a revision updates that language’s draft. Publish it when it is ready to go live.

#### Public pages and forms

The public route serves each published version at its language's address. Only a whole first segment selects a language, so `/kk/about` selects Kazakh and `/kk-news` stays in the main language. Draft versions return `404` at their public addresses. A canonical slash redirect keeps the original query string, including repeated parameters. Moving a version on a multilingual site creates an automatic redirect only if the old address served that version and no other live version serves it.

In Twig, `page.locale` is the version's language code, `page.html_lang` is the tag for `<html lang>`, and `page.og_locale` is the Open Graph locale or `null`. With `itk_cms.locales` configured, Twig's `|trans` uses the version's language. `page.translations` lists the published versions that route to their own language as `{code, name, url}`. The main language comes first, followed by the configuration order. A language switcher can use those URLs directly. Use `itk:doctor` to find legacy addresses that need a different prefix.

```twig
{% if page.translations|length > 1 %}
    <ul>
        {% for version in page.translations %}
            <li><a href="{{ version.url }}"{% if version.code == page.locale %} aria-current="page"{% endif %}>{{ version.name }}</a></li>
        {% endfor %}
    </ul>
{% endif %}
```

Menus, `site`, `itk_settings()`, `itk_listing()`, `itk_pages()`, `itk_collection()` and `itk_page_url()` use the page's language. A menu link to a page requires a published version in that language. Literal URL links keep their address. Translatable settings read the current language without fallback to the main language. Shared settings have one value across languages, and unsaved settings use their schema defaults.

On a site with `itk_cms.locales`, `POST /request/{form}` records the language from `?locale=` if supplied. Without the query parameter, the form uses the decoded page address from a same-origin `Referer`. The default port may be present or omitted. With no same-origin referrer the form uses the main language. An unlisted explicit code uses the main language for `429` and returns `422` if the request passes the rate limiter and honeypot checks. To pass the language explicitly, use `action="/request/callback?locale={{ page.locale }}"` on the form. For AJAX, pass the same address and send the form data.

Form messages, including `429`, use the submission language whenever the bundle provides a translation. This also applies to sites without `itk_cms.locales`. `admin_locales` controls the admin interface.

```twig
<form id="callback" action="/request/callback?locale={{ page.locale }}" method="post">
    {# Form fields #}
</form>
```

```js
const form = document.getElementById('callback');
const response = await fetch(form.action, { method: 'POST', body: new FormData(form) });
const result = await response.json();
```

#### Multilingual indexing and operations

With two or more configured content languages, `itk_seo().alternates` contains `{hreflang, href}` for the indexable published versions of the same page, including the current version. Language tags use `html_lang`, so `zh` may produce `zh-CN`. The main version comes first, followed by configuration order. At least two indexable versions are required. `x-default` points to the main version when it is published and indexable. Preview and a current `noindex` page expose no SEO alternatives. The language switcher can still use `page.translations` to link to published `noindex` versions.

Absolute links use the trusted base resolved from `itk_cms.public_url`, a request whose host is covered by `framework.trusted_hosts`, or the `domain` site setting, in that order. Debug mode can fall back to the request URL. Configure `public_url` or the site address for consistent links in HTTP requests and workers. If the base is missing or invalid, public pages keep working with relative language links, no `hreflang` and no canonical derived from the base. The generated sitemap returns `404`, and generated `llms.txt` uses relative links. Doctor reports an invalid base. A live multilingual page uses its own absolute canonical URL when the page SEO, a rule and the `itk_seo()` defaults leave it empty.

The stock layout prints the links after the canonical and emits `og:locale` when the language has an Open Graph locale. A custom layout can use the same loop.

```twig
{% set seo = itk_seo() %}
{% if seo.canonical != '' %}<link rel="canonical" href="{{ seo.canonical }}">{% endif %}
{% for alternate in seo.alternates %}
    <link rel="alternate" hreflang="{{ alternate.hreflang }}" href="{{ alternate.href }}">
{% endfor %}
```

One `/sitemap.xml` lists all enabled, indexable published versions and adds `xhtml:link` with the same alternates as HTML. Generated `/llms.txt` groups page titles, descriptions and links under language names in configuration order, omitting empty languages. Publishing, moving, unpublishing or deleting a version invalidates both files after commit. Generated multilingual responses revalidate with content ETags, including for changes within one second. Date-only conditional requests receive the current body. A site's `public/sitemap.xml` or `public/llms.txt` takes precedence. Saved SEO text takes precedence over generated `llms.txt`.

IndexNow uses the affected language's full path. Moves include the old and new addresses when eligible, and republishing at the same address queues one notification. Unpublishing and deleting use the last live address. Deleting a page covers its indexable live versions at their own language addresses. Critical CSS uses the full path of each version served in its language, including pages with `noindex`.

Run `php bin/console itk:doctor --ci` to check the main language and prefixes, home pages, conflicting paths, the SEO base, manual crawler files, and empty settings or menu areas by language. A missing main home page is an error. A missing secondary home page is a warning.

### Rate limits

The bundle sets the limits in `framework.rate_limiter`, and the site can override them under the same names:

| Limiter | Value |
|---|---|
| `itk_request_form` | 10 requests in 15 minutes per address |
| `itk_password_change` | 5 attempts in 15 minutes per person |
| `itk_password_reset_ip`, `itk_password_reset_email`, `itk_password_reset_guess` | 10 per hour per address, 3 per hour per e-mail, 10 invalid links in 15 minutes |
| `itk_mail_test` | 10 test e-mails per hour per person |
| `itk_mcp_token`, `itk_mcp_failed_auth`, `itk_mcp_token_create` | 120 calls per minute per key, 20 invalid keys in 15 minutes per address, 10 new keys per hour |

## Extensions and generators

### Extensions

A package or the site itself connects to the core with a class that implements `ItkExtensionInterface`. The `itk.extension` tag is added automatically.

```php
final class ToursExtension implements ItkExtensionInterface
{
    public function register(Registry $r): void
    {
        $r->resources(ToursCollection::class, TourTemplate::class)        // also sections(), templates(), collections(), settings()
            ->twigPaths(__DIR__ . '/../templates', 'Tours')                   // {% include '@Tours/block.html.twig' %}
            ->menu(NavItem::make('Reports', '/x/reports')->group('Analytics', icon: 'chart', order: 60)->icon('chart')->canSee('col:tours'))
            ->adminAssets(js: ['/bundles/tours/admin.js'], css: ['/bundles/tours/admin.css'])
            ->notificationChannels(SmsChannel::class)
            ->healthChecks(DiskCheck::class)
            ->mcpTools(ToursCountTool::class);
    }
}
```

- Each extension registers on first use of `Registry`, once per registry instance. Classes come from the container if they are there, otherwise they are created without arguments.
- `twigPaths()` adds folders to Twig, including for the console and `lint:twig`.
- `NavItem` is an admin menu item that is not a collection. `to` is a route of the admin client. The default group is **Plugins** (`order` 800).
- `adminAssets()` adds CSS after the main admin stylesheet in `<head>`; its module scripts start after the interface language and `window.ITK` are ready, before the main admin app mounts. It accepts `/…` and `http(s)://…` URLs. A plugin calls `window.ITK.registerPlugin({ fields, panels, actions, previews })` and gets `window.ITK.vue`, `http`, `toast`, `confirm`, `i18n`, `ui` (including `SortableTree` and `KanbanBoard`). Details are in [admin/src/README.md](https://github.com/it-koval/cms/blob/main/admin/src/README.md).

### Code generators

The commands create a ready class in the site and, where needed, a Twig or JS stub. The classes go into `src/Itk/…` and are picked up automatically. In production, run `cache:clear` after you generate a class.

| Command | Creates |
|---|---|
| `itk:make:template <key> [title]` | `src/Itk/Template/<Key>Template.php` and `templates/pages/<key>.html.twig` |
| `itk:make:section <key> [title] [--group=]` | `src/Itk/Section/<Key>Section.php` and `templates/sections/<key>.html.twig`. Without `--group`, it writes `->group('Тексты и картинки')`, the default group, which the admin panel shows in the interface language |
| `itk:make:collection <key> [title] [--group=] [--icon=]` | `src/Itk/Collection/<Key>Collection.php` |
| `itk:make:settings <key> [title]` | `src/Itk/Settings/<Key>Settings.php` |
| `itk:make:field <type> [title]` | `src/Itk/Field/<Type>Field.php` and the admin component `public/itk/fields/<type>.js` |
| `itk:make:plugin <key> [title]` | `src/Itk/Plugin/<Key>Extension.php` and the admin screen `public/itk/plugins/<key>/admin.js` |

- Without a key or a title, the command asks for them. With `-n`, the key is required.
- An existing class is not overwritten without `--force` (exit code 1). Existing Twig and JS stubs are replaced only with `--force`.
- `--dry-run` shows the files and the class without writing them. `--dir=src/Other/Folder` changes the class folder within `autoload.psr-4`.
- A key taken by another class of the same kind or by `schema.php` is not accepted.
- An extension loads the field script: `$registry->adminAssets(js: ['/itk/fields/rating.js'])`.

### Collection from a description or a table

```
php bin/console itk:make:collection --from=form.json
php bin/console itk:make:collection --from=table:table_name [--import] [--publish] [--map=…]
```

The JSON description: `{"key": "tours", "title": "Tours", "singular": "tour", "icon": "map", "fields": [{"key": "city", "label": "City", "type": "text", "required": true, "max": 80}], "rows": [{"id": 1, "city": "Perm"}]}`. Types: `text`, `textarea`, `description`, `headline`, `article`, `number`, `toggle`, `select` (with `"options"`), `date`, `datetime`, `image`, `photos`, `link`. `rows` are needed only for `--import`. Add a repeater or a relation to the class by hand.

From a PostgreSQL table, the columns become fields. Write a table in another schema as `table:schema.table`. `--connection=name` sets another Doctrine connection.

| Column | Field |
|---|---|
| `character varying`, `character` | `Text` (a length up to 1000 becomes `max`) |
| `text`, `json`, `jsonb` | `Textarea` |
| `smallint`, `integer`, `bigint`, `numeric`, `real`, `double precision` | `Number` |
| `boolean` | `Toggle` |
| `date` | `Date` |
| `timestamp` | `DateTime` |
| `time` | `Text` |
| an enum type (`CREATE TYPE … AS ENUM`) | `Select` |

- Other types are skipped with an explanation. `NOT NULL` without a default gives `required()`. The label comes from the column comment or the name. The primary key does not become a field.
- `--map=column:field[:type],…` renames a column or changes its type. `column:-` skips the column.
- `--strip-prefix=old_` drops the start of the table name when the key is chosen. `--id=column` sets the row key if there is no primary key or it is composite.
- `--import` moves the rows into records as drafts, with the usual checks and hooks. A repeat run updates its own records instead of creating new ones. `--publish` publishes the records right away. A row without a required field stays a draft and goes into the report.

## Docker

The package ships a ready Docker kit in `vendor/itk/cms/docker`. You copy it into the site and then edit it as your own files.

| File | Contents |
|---|---|
| `compose.yaml` | The services `php` (php-fpm), `nginx`, `worker`, `database` (PostgreSQL 17 with pgvector) and `mailer` (Mailpit). Ports are open only on `127.0.0.1` |
| `Dockerfile` | The stages `php_base`, `php_dev`, `php_prod`, PHP extensions, Composer, health check |
| `Dockerfile.dockerignore` | What stays out of the image: `.git`, `vendor`, `node_modules`, `var`, `public/uploads`, `public/bundles`, `.env.local`, `.env.*.local`, `.env.local.php`, the secrets decryption key, `compose.override.yaml` |
| `nginx/default.conf` | Static files, caching, headers, a route-specific request body limit, and forwarding to `index.php` |
| `chrome-seccomp.json` | Docker Engine 27.5.1 default seccomp policy with three additional rules for Chromium namespaces |
| `php/conf.d/10-app.ini`, `php/php-fpm.d/zz-app.conf` | PHP and php-fpm settings |
| `php/docker-entrypoint.sh` | On `php` start: `composer install`, `assets:install`, waiting for the database, migrations, `itk:install` |

Build arguments: `PHP_VERSION` (`8.4` by default), `CRITICAL_CSS` (`0` or `1`), `UID` and `GID` (the `www-data` user gets your IDs).

### First run

The host needs only Docker.

1. Create the site and install the package with the Composer image. `--ignore-platform-reqs` is needed because the Composer image lacks the PHP extensions the bundle requires. The `php` container has them.

   ```bash
   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 \
       sh -c 'composer config extra.symfony.docker false && composer require itk/cms --ignore-platform-reqs --no-scripts'
   ```

2. Copy the kit to the site root:

   ```bash
   cp -R vendor/itk/cms/docker docker
   mv docker/compose.yaml compose.yaml
   ```

3. Do steps 3 to 5 of the quick start (`config/routes/itk.yaml`, `security.yaml`, `itk_cms.yaml`) and put `APP_SECRET` in `.env.local`. `compose.yaml` sets `DATABASE_URL`.
4. Set variables if needed. Compose reads them from the shell environment and from the `.env` next to `compose.yaml`. That is the site's `.env`, which is in git, so write only safe values into it.

   | Variable | Default | What it does |
   |---|---|---|
   | `HTTP_PORT`, `DB_PORT`, `MAILPIT_PORT` | `8080`, `5433`, `8025` | Ports on the host |
   | `DB_NAME` | `app` | The database name |
   | `DOCKER_APP_ENV` | `dev` | `APP_ENV` in the containers |
   | `COMPOSE_PROJECT_NAME` | the directory name | The project name. Each site has its own |
   | `PHP_VERSION` | `8.4` | The PHP version |
   | `CRITICAL_CSS` | `0` | `1` adds Node 22 and Chromium to the image |
   | `ADMIN_EMAIL` | empty | First administrator e-mail for automatic setup with a mounted secret |
   | `SITE_NAME`, `SITE_DOMAIN` | empty | The name and domain for `itk:install` |
   | `UID`, `GID` | `1000` | The container user. On Linux, set your own values in `.env` (`id -u`, `id -g`) |

   For automatic setup, uncomment the `itk_admin_password` secret blocks in `compose.yaml`. Set `ITK_ADMIN_PASSWORD_FILE` to the path of a password file outside the project and readable by the container user. Compose mounts it at `/run/secrets/itk_admin_password` in `php`.

5. Start:

   ```bash
   docker compose up -d --build
   docker compose logs -f php        # wait for the "ready to handle connections" line
   ```

   To turn off migrations and `itk:install` at start, set `AUTO_MIGRATE: "0"` in the `environment` of the `php` service.

6. Use the interactive installer if the automatic setup was skipped.

   ```bash
   docker compose exec php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   ```

The site opens at `http://localhost:8080`, and the admin panel at `http://localhost:8080/admin/login`. Publish the home page in the admin panel.

**If it does not start.**

- The `[entrypoint] ... failed` lines in `docker compose logs php` name the step where the container stopped.
- On the first start, `nginx` and `worker` wait until `php` finishes `composer install`. Wait and run `docker compose up -d` again.
- A port is taken: set other `HTTP_PORT`, `DB_PORT`, `MAILPIT_PORT` values.
- `docker compose down -v` deletes the volumes, including the `postgres_data` database.

### Mail and worker

Mailpit collects all e-mails: `http://localhost:8025`. The containers set `MAILER_DSN=smtp://mailer:1025` and `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`.

The `worker` service is built from the same image and runs `messenger:consume async critical_css knowledge_base --time-limit=3600 --memory-limit=256M`. After an hour it exits, and `restart: unless-stopped` starts it again. The worker keeps code in memory: after you edit PHP, configuration or templates, run `docker compose restart worker`. If the site declared its own transports, change `command`.

### Critical CSS in Docker

Node, crittr and Chrome must be in the same image as PHP. The `php` and `worker` services use `docker/chrome-seccomp.json`. It starts from the Docker Engine 27.5.1 built-in profile and permits the `clone` and `unshare` flags needed for user, PID, network and mount namespaces. Keep the profile on both services. Run Chromium as an unprivileged user with Linux user namespaces available.

The renderer serves files whose resolved paths are inside the site's `public/`. Copy needed assets there when links in `public/` point elsewhere.

1. Build the image with Node and Chromium:

   ```bash
   echo 'CRITICAL_CSS=1' >> .env
   docker compose up -d --build
   ```

   The image sets `PUPPETEER_SKIP_DOWNLOAD=1` and `PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium`, so you do not need to set the `chrome` option.

2. Install crittr inside the container: `docker compose exec php npm install 'crittr@^5'`. Add `node_modules` to `.gitignore`, and commit `package.json` and `package-lock.json`. For `php_prod`, crittr must be in `dependencies`.
3. Turn on the option and `itk_stylesheets()` (see [Critical CSS](#critical-css)), then run `docker compose exec php bin/console cache:clear` and `docker compose restart worker`.
4. Test one page: `docker compose exec php bin/console itk:critical-css:generate --page=<id> --now`.

### Production build

Use `docker/compose.prod.yaml` for a production site. It builds `php_prod` with production dependencies and packages the admin files in the nginx image. PHP-FPM and the worker share the app image. The deployment runs migrations and `itk:install` as separate commands.

Pass production secrets at runtime through the environment or the [Symfony vault](#secrets-in-production-symfony-vault). The build copies the committed `.env`, so keep only defaults in it. See the [deployment and rollback guide](https://github.com/it-koval/cms/blob/main/docs/DEPLOY.md).

### Updating the kit

The kit belongs to the site, and `composer update` does not change it. After a package update, compare the files and carry over the changes you need:

```bash
docker compose exec php composer update itk/cms
diff -r -x compose.yaml vendor/itk/cms/docker docker && diff vendor/itk/cms/docker/compose.yaml compose.yaml
docker compose up -d --build
```

## AI agent in the site project

The rules for an AI agent that builds a site on ITK CMS ship in the package: [SITE-AGENTS.md](SITE-AGENTS.md) (in a site, `vendor/itk/cms/SITE-AGENTS.md`). The main rule: when the core lacks a feature that other sites would also use, the agent proposes it as a pull request to [it-koval/cms](https://github.com/it-koval/cms) instead of modifying a copy of the core inside the site.

Agents read instructions from the project root, so reference the file in the site's instructions:

- Claude Code, a line in `CLAUDE.md`: `@vendor/itk/cms/SITE-AGENTS.md`.
- Codex, Cursor and other agents that read `AGENTS.md`, a line in `AGENTS.md`: `Before you start, read vendor/itk/cms/SITE-AGENTS.md: these are the ITK CMS core rules, and they are mandatory.`

## Public API and compatibility

[API.md](API.md) is the authoritative public API contract: it lists every public PHP type and the supported `PageService` operations, including translations. [UPGRADE.md](https://github.com/it-koval/cms/blob/main/UPGRADE.md) describes compatibility rules and release-specific migration steps. Unlisted types and members marked `@internal` are implementation details and may change in a minor release. A site reads pages and collections through `ContentReader` and the Twig functions, not through repositories.

## Developing and testing the bundle

The [it-koval/cms](https://github.com/it-koval/cms) monorepo holds the bundle (`packages/cms`) and the admin panel sources (`admin/`). [CONTRIBUTING.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.md) explains how to set up the environment, run the tests, build the admin panel and add an interface language.

## Other documents

- [CHANGELOG.md](https://github.com/it-koval/cms/blob/main/CHANGELOG.md): changes by version.
- [API.md](API.md): the public API contract.
- [UPGRADE.md](https://github.com/it-koval/cms/blob/main/UPGRADE.md): compatibility rules and upgrading between versions.
- [CONTRIBUTING.md](https://github.com/it-koval/cms/blob/main/CONTRIBUTING.md): how to contribute.
- [SECURITY.md](https://github.com/it-koval/cms/blob/main/SECURITY.md): how to report a vulnerability.
- [SITE-AGENTS.md](SITE-AGENTS.md): rules for an AI agent in the site project.
- [admin/src/README.md](https://github.com/it-koval/cms/blob/main/admin/src/README.md): how the admin panel works and plugins.
