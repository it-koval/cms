English | [Русский](README.ru.md)

# ITK CMS

ITK CMS is a Symfony bundle that adds a content management system and an editor admin panel to a PHP site. You describe the site structure in PHP and write Twig templates. Editors build pages from sections, preview drafts, and publish them in the admin panel. The bundle stores its content in PostgreSQL and ships the admin panel prebuilt, so a site does not need Node.

The current version is 0.3.0. Until 1.0.0 a minor release may change the public API. [API.md](packages/cms/API.md) defines its boundary. [UPGRADE.md](UPGRADE.md) describes the compatibility policy and migration steps.

## Features

- Pages built from sections, page templates with placement rules, drafts, preview, and publishing.
- Collections with filters, list views (table, cards, calendar, kanban board, tree), actions, and metrics.
- Menus, site settings, and a media library with WebP and AVIF copies, crops, and focus points.
- SEO rules for any URL, redirects, `sitemap.xml`, `robots.txt`, `llms.txt`, and schema.org markup.
- Yandex Metrika, Google Analytics, Yandex Webmaster, and IndexNow.
- Form requests with notifications by e-mail, Telegram, and MAX. Bot tokens are set in the admin panel.
- Users, roles with per-section permissions, two-step sign-in, and password recovery.
- Change history with restore, and a trash for deleted pages and records.
- A page cache and optional critical CSS for live pages.
- A knowledge base on pgvector and an MCP server for AI assistants.
- A site status screen and the `itk:doctor` command.
- An admin interface in English, Russian, and Kazakh.
- A Docker kit and `itk:make:*` generators.

## Requirements

- PHP 8.3 or newer with the `ctype`, `dom`, `gd`, `json`, `mbstring`, `pdo_pgsql`, and `sodium` extensions. `exif` and `intl` are optional. Use a current patch release: the site status screen warns when the PHP patch level has known security fixes missing.
- Symfony 7.4 (7.4.23 or newer) or 8.1.11 and newer. Symfony 8 needs PHP 8.4 or newer. Symfony 8.0 is not supported: it reached its end of life.
- PostgreSQL 16 or newer with the [pgvector](https://github.com/pgvector/pgvector) extension 0.8 or newer. A core migration runs `CREATE EXTENSION vector`, so the extension is required even if you do not use the knowledge base. The `pgvector/pgvector:pg17` Docker image has it.
- Composer.
- Node only to build the admin panel from source or to generate critical CSS.

## Quick start

The package is `itk/cms` on Packagist. These steps add it to a new Symfony 7.4 project. The [quick start in the bundle reference](packages/cms/README.md#quick-start) explains each step in detail.

1. Create the project and install the package:

   ```bash
   composer create-project symfony/skeleton:"7.4.*" mysite
   cd mysite
   composer config extra.symfony.docker false
   composer require itk/cms
   ```

   The `extra.symfony.docker false` line stops Flex from adding its own Docker files. The bundle ships its own Docker kit.

2. Set the database and the secret in `.env.local`:

   ```dotenv
   DATABASE_URL="postgresql://user:password@127.0.0.1:5432/mysite?serverVersion=17&charset=utf8"
   APP_SECRET=a-long-random-string
   ```

3. Import the bundle routes in `config/routes/itk.yaml`:

   ```yaml
   itk_admin:
       resource: '@ItkCmsBundle/config/routes/admin.php'
   itk_site:
       resource: '@ItkCmsBundle/config/routes/site.php'
   ```

4. Replace `config/packages/security.yaml` with the file from the [Security](packages/cms/README.md#security-securityyaml) section of the bundle reference.

5. Set the content language in `config/packages/itk_cms.yaml`. The default is `ru`:

   ```yaml
   itk_cms:
       main_locale: en
   ```

   The admin interface language is a separate option, `itk_cms.admin_locale`. It is `en` by default and can be `ru` or `kk`.

6. Create the tables and the first administrator. `itk:install` asks for the password.

   ```bash
   php bin/console doctrine:database:create
   php bin/console doctrine:migrations:migrate -n
   php bin/console itk:install --email=you@example.com --site-name="My site" --domain=example.com
   php bin/console assets:install
   ```

7. Start the web server and the queue worker as shown in the [bundle quick start](packages/cms/README.md#quick-start). Then sign in at `/admin/login` and run `php bin/console itk:doctor`. `itk:install` creates the home page as a draft, so `/` returns 404 until you publish it.

Next, describe the site in `config/itk/schema.php` (page templates, sections, collections, menus, settings, and forms) and add Twig templates.

## Repository layout

This repository is a monorepo:

- `packages/cms` is the Composer package `itk/cms`. It contains the bundle, its tests, its Docker kit, and the built admin panel in `packages/cms/public/admin`. A workflow copies this directory to [it-koval/cms-bundle](https://github.com/it-koval/cms-bundle) after CI passes for the same commit on `main` or a `v*` tag.
- `admin` contains the admin panel sources (Vue 3 and Vite). `npm run build` writes the build to `packages/cms/public/admin`.

## Development

Use Docker Compose to work on the bundle or the admin panel. The containers run PHP, Composer, PostgreSQL, Mailpit, and Node. See the [contributor setup and checks](CONTRIBUTING.md).

## More information

- [Bundle reference](packages/cms/README.md)
- [Public API contract](packages/cms/API.md)
- [Rules for an AI agent that builds a site](packages/cms/SITE-AGENTS.md)
- [Contributing](CONTRIBUTING.md)
- [Code of conduct](CODE_OF_CONDUCT.md)
- [Security policy](SECURITY.md)
- [Deployment and rollback](docs/DEPLOY.md)
- [Upgrading](UPGRADE.md)
- [Changelog](CHANGELOG.md)

## License

ITK CMS is released under the [MIT license](LICENSE). The npm packages bundled into the admin panel have their own licenses, listed in [THIRD_PARTY_NOTICES.txt](packages/cms/public/admin/THIRD_PARTY_NOTICES.txt).
