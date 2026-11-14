English | [Русский](CONTRIBUTING.ru.md)

# Contributing to ITK CMS

Bug reports with steps to reproduce, fixes with a test, and documentation fixes help the most.

Do not report a security problem in a public issue. Follow [SECURITY.md](SECURITY.md) instead.

## Repository layout

- `packages/cms` is the Composer package `itk/cms`: the Symfony bundle, its tests, and the built admin panel.
- `admin` contains the admin panel sources (Vue 3 and Vite). The build goes to `packages/cms/public/admin`.

## Set up the environment

You need:

- PHP 8.3 or newer with the `ctype`, `dom`, `gd`, `json`, `mbstring`, `pdo_pgsql`, and `sodium` extensions. `exif` and `intl` are optional.
- Composer.
- Docker for the test database, the mail catcher, and the admin panel. The admin panel runs Node 24.22.2 in a container, so you do not need Node on the host.

The tests run on PostgreSQL 17 with pgvector (the `pgvector/pgvector:pg17` image) and send mail to Mailpit. Start both and install the dependencies:

```bash
docker run -d --name itk-test-pg -p 127.0.0.1:33960:5432 -e POSTGRES_PASSWORD=itk-test pgvector/pgvector:pg17
docker run -d --name itk-test-mail -p 127.0.0.1:33951:1025 -p 127.0.0.1:33952:8025 axllent/mailpit

cd packages/cms
composer install
cd ../../admin
docker compose run --rm node npm ci
```

## Run the checks

### PHP

Run these commands from `packages/cms`:

```bash
export ITK_TEST_MAILPIT_URL=http://127.0.0.1:33952 ITK_TEST_MAILPIT_SMTP_PORT=33951
composer test        # composer lint:sql, then recreates the itk_test database, runs the migrations and itk:install, then PHPUnit
composer lint:sql    # fails on MySQL constructs in the SQL of the bundle (src/, migrations included)
composer stan        # PHPStan, level 5
composer cs          # PHP CS Fixer, check only. composer cs:fix applies the fixes.
```

By default `DATABASE_URL` is `postgresql://postgres:itk-test@127.0.0.1:33960/itk?serverVersion=17&charset=utf8`. The tests use the `itk_test` database. If you set your own `DATABASE_URL`, keep `serverVersion` in it. Without it Doctrine connects to the database that does not exist yet to detect the server version, and `doctrine:database:create` fails.

Without `ITK_TEST_MAILPIT_URL` the mail tests are skipped. Other notes:

- `tests/` holds a test site (`tests/App`, the `TestKernel` kernel) and the core tests (`tests/Core`).
- `ITK_TEST_CACHE_DIR` sets a separate cache directory of the test site for parallel runs.
- `composer test:run -- --order-by=random` (after `composer test:prepare`) checks that the test order does not matter.
- The tests do not need Telegram or MAX: `TestKernel` replaces the sending.

CI also runs `php tests/bin/console doctrine:schema:validate --env=test` to check that the database schema matches the Doctrine mapping.

### Admin panel

Run these commands from `admin`:

```bash
docker compose run --rm node npm audit
docker compose run --rm node npm audit --omit=dev
docker compose run --rm node npm run lint
docker compose run --rm node npm run check:i18n  # interface catalogs and Cyrillic in the code
docker compose run --rm node npm test
docker compose run --rm node npm run build        # writes the build to packages/cms/public/admin
```

`docker compose up node` starts Vite at `http://127.0.0.1:5174/bundles/itkcms/admin/`. By default the container reaches the site API at `host.docker.internal:8080`. Set `ITK_SITE_URL` to use another address. To run the admin panel without PHP, on made-up data, run `docker compose run --rm --service-ports node npm run dev:mock -- --host 0.0.0.0`.

`admin/compose.yaml` mounts the repository root at `/workspace` and keeps the Linux dependencies in a named volume. The container does not use or overwrite a `node_modules` directory on the host. `npm ci` installs exactly what the lock file lists. Keep the Node version the same in `admin/compose.yaml`, `.github/workflows/ci.yml`, and `admin/.nvmrc`.

## Commit the built admin panel

`npm run build` creates `packages/cms/public/admin`, and the directory is committed, so a site does not need Node. If you change anything in `admin/`, rebuild the admin panel and commit the build together with the sources. CI rebuilds the admin panel and fails if the result differs from the committed files. Do not edit the built files by hand.

A site that uses the bundle from a local clone (a Composer `path` repository with `symlink`) sees a new build after `bin/console assets:install`.

## Update the Docker kit with new system dependencies

A feature that needs a new system dependency (an OS package, a binary, a PHP extension, another queue transport, or a service) also changes `packages/cms/docker/` and the Docker section of `packages/cms/README.md` in the same pull request.

The `Docker kit` CI job builds the image and checks it:

- the PHP extensions from `require` in `composer.json`;
- WebP support in `gd`, and `pcntl`;
- Node 22 and `crittr` with a browser for critical CSS;
- that the worker command in `docker/compose.yaml` consumes every transport the bundle adds by default.

Add a check for a new OS package or binary to `packages/cms/tests/docker/smoke.sh`. Review covers a new service and the README text.

## Code style

- Follow the surrounding code: names, comments in English, and patterns. PHP files start with `declare(strict_types=1);`. Declare classes `final` unless they are meant to be extended.
- PHP CS Fixer checks the PHP style with `.php-cs-fixer.dist.php` in the repository root.
- Text that a person sees in the admin panel, in e-mail, in the console, or in API responses is never a string literal in the code. It is a catalog key in three languages. See [Add an interface string](#add-an-interface-string). Do not mix languages on one screen.
- [UPGRADE.md](UPGRADE.md) lists what the bundle promises to sites. Do not break it without a changelog entry.
- Keep the change focused. A fix for one thing does not rewrite its neighbors.

## Add an interface string

The interface is in English, Russian, and Kazakh. The interface language is separate from the language of the site content (`main_locale`).

1. Name the key `area.key`: an area (`pages`, `forms`, `content`, and others), a dot, and a snake_case name. Use one key for one whole sentence. Do not build a sentence from pieces or put a condition inside a phrase: Kazakh word order differs, and a placeholder cannot carry Russian gender and case. If you need two variants, add two keys or a key with a parameter.
2. Add the key to all three catalogs, with the same `{name}` parameters in each.
   - Admin panel (Vue): `admin/src/i18n/{en,ru,kk}/<area>.json`. Call `t('area.key', { name: x })` from `@/i18n/index.js`, in templates too. For a count, call `t('area.key', { count: n })`. The JSON value is then an object keyed by `Intl.PluralRules` categories: `one`, `few`, `many`, and `other` for Russian, `one` and `other` for English, `other` for Kazakh. Format dates, numbers, and sorting with `formatDate`, `formatNumber`, and `collator`.
   - Bundle (PHP): `packages/cms/translations/itk_<area>.{en,ru,kk}.yaml`, values in single quotes. Call `Itk\Cms\I18n\I18n::t('area.key', ['name' => $x])`, or `itk_t('area.key', {name: x})` in Twig. For plurals, pass a `count` parameter and separate the forms with `|`: `{count} страница|{count} страницы|{count} страниц` in Russian, `{count} page|{count} pages` in English, `{count} бет|{count} бет` in Kazakh.
3. Do not reword existing Russian strings: tests compare them. In English, write short labels in sentence case. In Kazakh, use the literary interface language in Cyrillic. Keep the terms used by the neighboring strings of the catalog (бет, жоба, жариялау, өтінім, баптаулар).
4. Do not translate what goes into code or the API: keys, statuses, URLs, field names, and default values in site data. Site data, the site schema, and labels set by the site developer stay as they are.
5. Check the catalogs. `npm run check:i18n` (from `admin/`) compares the catalogs and finds Cyrillic in the code outside comments. `composer test` includes `I18nCatalogTest` and `I18nCyrillicTest`. To keep Cyrillic in the code on purpose (for example, a language name written in that language), add an `i18n-ignore` comment on the same line.

## Add an interface language

1. Add the language to `I18n::LOCALES` (`packages/cms/src/I18n/I18n.php`), to `BUILT_IN` in `admin/src/i18n/index.js`, to `LOCALES` in `admin/scripts/check-i18n.mjs`, to `UI_LOCALES` in `admin/src/mock/handler.js`, and to the `locale` type in `admin/types/itk.d.ts`.
2. Create `admin/src/i18n/<language>/*.json` and `packages/cms/translations/itk_<area>.<language>.yaml` with the same keys as in `en`. `JS_PLURALS` and `PHP_PLURALS` in `admin/scripts/check-i18n.mjs` and `FORMS` in `packages/cms/tests/Core/I18nCatalogTest.php` set the plural forms.
3. Run `npm run check:i18n` (from `admin/`) and `composer test` (from `packages/cms`).

## Write the test first

Write a test, see it fail, then change the code. A bug fix comes with a test that fails without the fix.

- Bundle tests are in `packages/cms/tests/Core`. The test site is in `packages/cms/tests/App`.
- Admin logic that does not need the DOM is tested with Vitest in a `*.test.js` file next to the code.

## Commits and pull requests

- Make one logical change per commit. Write a short subject in the imperative mood (about 72 characters). If the reason is not obvious, explain it in the body. No prefix is required.
- For every change a bundle user will notice, add an entry under `## [Unreleased]` at the top of [CHANGELOG.md](CHANGELOG.md). Create the section if it is missing.
- If the change breaks the public API, add a note to [UPGRADE.md](UPGRADE.md).
- Documentation is in English, with a Russian copy next to each file (`README.ru.md`, `CHANGELOG.ru.md`, and so on). When you change a document, update its Russian copy in the same pull request.
- Run the checks above before you open a pull request. In the description, say what changed, why, and how you tested it.
