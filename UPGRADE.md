English | [Русский](UPGRADE.ru.md)

# Upgrading ITK CMS

## Upgrade a site

Do these steps in order, on a copy of the site first:

1. Read [CHANGELOG.md](CHANGELOG.md) for every version between yours and the target, and the version notes at the end of this file.
2. Back up the database.
3. Update the package: `composer update itk/cms`. With the Docker kit, run `docker compose exec php composer update itk/cms`.
4. Run the migrations: `bin/console doctrine:migrations:migrate`. The bundle migrations (`Itk\Cms\Migrations`) run together with the site migrations, ordered by the date in their class names.
5. Publish the built admin panel with `bin/console assets:install`. Symfony Flex does this on `composer install` and `composer update`. Then clear the cache with `bin/console cache:clear`.
6. Restart the queue workers with `bin/console messenger:stop-workers` or by restarting the service. A running worker keeps the old code.
7. Check the site: run `bin/console itk:doctor` and `bin/console itk:schema:lint`, then open the admin panel and a few pages.

If the version notes ask you to change a site configuration file (for example, `security.yaml`), do it before step 7.

## Versions

ITK CMS follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html):

- A patch release (0.1.1) contains only fixes.
- A minor release (0.2.0) adds features. From 1.0.0 on, it does not break the public API.
- A major release (2.0.0) may break the public API.

Before 1.0.0 a minor release may break the public API. Each such change is listed in [CHANGELOG.md](CHANGELOG.md) and in the version notes below. A patch release never breaks it. To get fixes without surprises, pin the minor version in the site: `"itk/cms": "~0.2.0"`.

Database changes come as bundle migrations. The bundle does not change the site's own tables.

## Public API

These parts of the bundle are the public API and follow the rules above:

- the schema API: `Schema\SchemaBuilder`, `Schema\Field`, `Template`, `SectionType`, `Collection`, `SettingsGroup`, and `FormDef`;
- the fields in `Field\*`;
- resource classes and their API: `Resource\*` (`CollectionResource`, `TemplateResource`, `SectionResource`, `SettingsResource`, `Action`, and `Metric`) and the attributes in `Attribute\*`;
- the events in `Event\*`;
- `Extension\ItkExtensionInterface`, `Extension\Registry`, and `Extension\NavItem`;
- the interfaces a site implements and the classes they use: `Health\HealthCheckInterface`, `Health\HealthResult`, `Notification\NotifierInterface`, `Notification\Notification`, and `Notification\NotificationChannelInterface`;
- `Content\ContentReader` and the content services `PageService`, `CollectionService`, `SettingsService`, and `MenuService`;
- `Settings\Settings`, `I18n\I18n`, `I18n\ContentLocales`, `Support\Url`, and `Admin\AdminPath`;
- the knowledge base search: `KnowledgeBase\KnowledgeSearch`, `KnowledgeBase\Model\{SearchOptions, SearchResult, Hit, Source, SourceKind, KnowledgeBaseUnavailable}`, and `KnowledgeBase\Embedding\*` except `OpenAiEmbedder`;
- `Rendering\PageCache::clear()`;
- the Twig functions of the bundle, including `itk_stylesheets()`;
- route names and the `itk_cms` configuration options;
- console command names and their options;
- the names of the Messenger transports (`async`, `critical_css`, `knowledge_base`, and `failed`) and of the rate limiters in `framework.rate_limiter`;
- the Messenger message classes, so that a site can route them: `Request\SendRequestNotification`, `Media\GenerateImageVariants`, `Mail\SendMail`, `Security\RequestPasswordLink`, `IndexNow\Model\PingIndexNow`, `CriticalCss\Model\GenerateCriticalCss`, and `KnowledgeBase\Model\SyncKnowledge`;
- the classes a site puts in its own configuration: `Entity\User`, `Security\{AdminUserProvider, UserChecker, UserManager, Viewer, PermissionVoter, Access}`, and `Mcp\{ApiTokenAuthenticator, McpToolInterface, AbstractMcpTool, McpContext, McpScopes, McpToolException, McpConditionalTool}`;
- the admin plugin contract `window.ITK`, versioned by `ITK.version`;
- the shape of the JSON API under the admin URL, as far as the built admin panel and documented clients rely on it.

Classes marked `@internal` in their docblock are not public and may change in any minor release. This includes `Repository\*`, `Controller\*`, `Generator\*`, `Rendering\*` (except `PageCache::clear()`), `Mcp\Tool\*`, the `Security\*` listeners, and `Twig\{ItkExtension, ItkRuntime, SeoRuntime}`. To read pages and collections in templates, use `Content\ContentReader` and the Twig functions, not the repositories.

The database schema (the `itk_*` tables) is not part of the public API. Read and write through the services. Only bundle migrations change these tables.

## Version notes

This section lists the manual steps a release needs, newest first.

### 0.3.0

The analytics inline initialization is replaced by one versioned external `defer` loader with `data-*` attributes. Publish the new bundle assets with `bin/console assets:install` and allow the actual asset origin and enabled provider sources in the site's CSP. The [analytics guide](packages/cms/README.md#analytics-and-verification-codes) lists `script-src`, `connect-src`, `img-src` and conditional Webvisor `frame-src` requirements.

`itk_cms.analytics.start` defaults to `auto`, which starts configured counters and preserves Metrika's `<noscript>` pixel. To let the site's consent banner control startup, set `analytics.start: manual` under `itk_cms` in the site's configuration. There is no admin control for the mode. Before switching, remove direct counter scripts, pixels, tag-manager triggers and analytics `preconnect` or `dns-prefetch` links from the site. In `manual`, the bundle outputs no pixel and makes no provider requests or preliminary connections before the API call. Verification meta tags are output in both modes, including without counters.

After the bundle loader runs, call `window.itkAnalytics.start()` for all configured counters or `window.itkAnalytics.start(['ga'])` for GA only. The allowed keys are `metrika` and `ga`. New starts dispatch `itk-analytics:started` on `document`, bubbling to `window`, with the newly started keys in `detail.counters`. A retry without a new counter emits no event. The guide includes an external site script that waits for the loader. The site stores consent and, after revocation, reloads without calling `start()`. The CMS does not stop already running counters.

- `GET /admin/api/collections/{col}` now always returns `{ items, total, page, perPage, facets, metrics }`. The old flat array response is removed, including for requests without query parameters. Read `response.items` and fetch all required pages using `page`, `perPage` and `total`. The default page size is 50 unless configured otherwise, with a maximum of 200. `envelope` is accepted for compatibility.
- `Mcp\AbstractMcpTool::locale()` is now non-static. Both `self::locale($args)` and `$this->locale($args)` work inside instance methods. Move calls from a static context into an instance method. If a site subclass overrides `locale()` as `static`, remove `static` from the override; otherwise PHP rejects the class.
- Opting into `itk_cms.locales` trims and lowercases `main_locale` and restricts it to a listed code of 2 or 3 lowercase letters. Without `locales`, the existing value is preserved. If an existing site has a nonstandard main code such as `RU`, ` ru ` or `pt-BR`, choose the intended supported code and manually migrate stored language values and JSON keys before enabling `locales`. Changing configuration alone does not migrate content. Region metadata belongs in `html_lang` / `og_locale`.

The PostgreSQL example below maps the exact old code `RU` to `ru`. Adapt both literals to the site's data; inspect a backup first and resolve collisions where the old and new codes already coexist (page translations, settings, menus, collection records and historical snapshots). The JSON updates deliberately skip a row that already has the new key. Keep shared settings (`locale = '*'`) and language-independent SEO rules (`locale IS NULL`). Review site-owned tables and integrations separately. This is a manual maintenance example, not a bundle migration.

```sql
BEGIN;
UPDATE itk_page_translation SET locale = 'ru' WHERE locale = 'RU';
UPDATE itk_setting SET locale = 'ru' WHERE locale = 'RU';
UPDATE itk_revision SET locale = 'ru' WHERE locale = 'RU';
UPDATE itk_seo_rule SET locale = 'ru' WHERE locale = 'RU';
UPDATE itk_menu_area
SET items = (items - 'RU') || jsonb_build_object('ru', items->'RU')
WHERE items ? 'RU' AND NOT (items ? 'ru');
UPDATE itk_collection_item
SET translations = (translations - 'RU') || jsonb_build_object('ru', translations->'RU')
WHERE translations ? 'RU' AND NOT (translations ? 'ru');

-- Historical snapshots of whole deleted pages and collection records.
UPDATE itk_revision
SET snapshot = jsonb_set(snapshot, '{versions}',
    ((snapshot->'versions') - 'RU') || jsonb_build_object('ru', snapshot->'versions'->'RU'))
WHERE snapshot->'versions' ? 'RU' AND NOT (snapshot->'versions' ? 'ru');
UPDATE itk_revision
SET snapshot = jsonb_set(snapshot, '{translations}',
    ((snapshot->'translations') - 'RU') || jsonb_build_object('ru', snapshot->'translations'->'RU'))
WHERE snapshot->'translations' ? 'RU' AND NOT (snapshot->'translations' ? 'ru');
COMMIT;
```

### 0.2.0

- `Content\SettingsService::routesError()` is removed. If the site calls it, delete the call. `SettingsService::save()` now accepts empty chats for the other forms while Telegram or MAX is connected. If the site relies on that being refused, check it in the site code.
- The constructor of `SettingsService` no longer takes `max` and `telegram`. The container wires it, so this matters only for code that creates the service by hand.
- A custom health screen or monitor that reads `checks[].status` of the admin `health` API must accept the new value `off`. It ranks like `ok`.

### 0.1.0

The first release. It has no manual steps.
