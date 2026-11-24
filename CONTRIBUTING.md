English | [Русский](CONTRIBUTING.ru.md)

# Contributing to ITK CMS

Bug reports with steps to reproduce, fixes with a test, and documentation fixes help the most.

Follow the [code of conduct](CODE_OF_CONDUCT.md) when taking part. Report security problems privately as described in [SECURITY.md](SECURITY.md).

## Repository layout

- `packages/cms` is the Composer package `itk/cms`: the Symfony bundle, its tests, and the built admin panel.
- `admin` contains the admin panel sources (Vue 3 and Vite). The build goes to `packages/cms/public/admin`.

## Set up the environment

Install Docker with Compose. Run the PHP setup from the repository root. Compose starts PostgreSQL with pgvector and Mailpit.

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

## Run the checks

### PHP

Run the checks from the repository root with the same Compose settings.

```bash
test_compose run --rm --no-deps php composer test
test_compose run --rm --no-deps php composer stan
test_compose run --rm --no-deps php composer cs
```

`composer test` checks SQL, recreates the test database, and runs PHPUnit. `composer stan` checks `src` and `tests` at PHPStan level 6. `composer cs` checks formatting.

To test one class, run `composer test:prepare` once in the PHP service, then `php vendor/bin/phpunit --filter ClassName` there. For a random run, use `composer test:run -- --order-by=random --random-order-seed=30301` after preparation. CI also uses seed `30302`.

The `tests/App` site runs the tests in `tests/Core`. For a separate test stack, use a different Compose project name. If you supply your own `DATABASE_URL`, keep `serverVersion` in it. `ITK_TEST_CACHE_DIR` can isolate the test cache. The tests stub Telegram and MAX. Mail tests need `ITK_TEST_MAILPIT_URL`.

### Admin panel

Run these commands from `admin`:

```bash
docker compose run --rm node npm ci
docker compose run --rm node npm audit --include=dev
docker compose run --rm node npm audit --omit=dev --audit-level=high
docker compose run --rm node npm run lint
docker compose run --rm node npm run check:i18n  # interface catalogs and Cyrillic in the code
docker compose run --rm node npm test
docker compose run --rm node npm run build        # writes the build to packages/cms/public/admin
```

`docker compose up node` starts Vite at `http://127.0.0.1:5174/bundles/itkcms/admin/`. By default the container reaches the site API at `host.docker.internal:8080`. Set `ITK_SITE_URL` to use another address. To run the admin panel without PHP, on made-up data, run `docker compose run --rm --service-ports node npm run dev:mock -- --host 0.0.0.0`.

`admin/compose.yaml` mounts the repository root at `/workspace` and keeps the Linux dependencies in a named volume. The container does not use or overwrite a `node_modules` directory on the host. `npm ci` installs exactly what the lock file lists. Keep the Node version the same in `admin/compose.yaml` and `admin/.nvmrc`.

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
