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

- `Mcp\AbstractMcpTool::locale()` is now non-static. Both `self::locale($args)` and `$this->locale($args)` work inside instance methods. Move calls from a static context into an instance method.

### 0.2.0

- `Content\SettingsService::routesError()` is removed. If the site calls it, delete the call. `SettingsService::save()` now accepts empty chats for the other forms while Telegram or MAX is connected. If the site relies on that being refused, check it in the site code.
- The constructor of `SettingsService` no longer takes `max` and `telegram`. The container wires it, so this matters only for code that creates the service by hand.
- A custom health screen or monitor that reads `checks[].status` of the admin `health` API must accept the new value `off`. It ranks like `ok`.

### 0.1.0

The first release. It has no manual steps.
