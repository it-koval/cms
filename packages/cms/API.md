# Public API

[Русская версия](API.ru.md)

This is the single, authoritative public API contract of `itk/cms`. `PublicApiContractTest` reads the English lists below and checks the Russian translation against them. The [README](README.md) contains usage recipes, and [UPGRADE.md](https://github.com/it-koval/cms/blob/main/UPGRADE.md) describes compatibility rules and release-specific migration steps.

Only the named PHP types below are public. Every other named class, interface, trait and enum in `src/` has a class-level `@internal` docblock. An `@internal` member of a public type is excluded from the compatibility guarantee too. Inject services through the container. Their constructors and container wiring are internal.

## PHP types

Each bullet lists one fully qualified public type.

### Bundle and schema

- `Itk\Cms\ItkCmsBundle`
- `Itk\Cms\Schema\SchemaBuilder`
- `Itk\Cms\Schema\Schema`
- `Itk\Cms\Schema\Field`
- `Itk\Cms\Schema\Template`
- `Itk\Cms\Schema\SectionType`
- `Itk\Cms\Schema\Collection`
- `Itk\Cms\Schema\SettingsGroup`
- `Itk\Cms\Schema\FormDef`
- `Itk\Cms\Schema\MenuAreaDef`

### Fields

- `Itk\Cms\Field\FieldInterface`
- `Itk\Cms\Field\PublicFieldInterface`
- `Itk\Cms\Field\ValueValidatingFieldInterface`
- `Itk\Cms\Field\AbstractField`
- `Itk\Cms\Field\FilterKind`
- `Itk\Cms\Field\Article`
- `Itk\Cms\Field\Date`
- `Itk\Cms\Field\DateTime`
- `Itk\Cms\Field\Description`
- `Itk\Cms\Field\Headline`
- `Itk\Cms\Field\Image`
- `Itk\Cms\Field\Link`
- `Itk\Cms\Field\Note`
- `Itk\Cms\Field\Number`
- `Itk\Cms\Field\Photos`
- `Itk\Cms\Field\Relation`
- `Itk\Cms\Field\Repeater`
- `Itk\Cms\Field\Select`
- `Itk\Cms\Field\Text`
- `Itk\Cms\Field\Textarea`
- `Itk\Cms\Field\Toggle`

`Field\FormFields` is internal and prepares editor data.

### Resources, hooks, actions and attributes

- `Itk\Cms\Resource\ResourceInterface`
- `Itk\Cms\Resource\CollectionResource`
- `Itk\Cms\Resource\TemplateResource`
- `Itk\Cms\Resource\SectionResource`
- `Itk\Cms\Resource\SettingsResource`
- `Itk\Cms\Resource\ResourceHooks`
- `Itk\Cms\Resource\Context`
- `Itk\Cms\Resource\Record`
- `Itk\Cms\Resource\Action`
- `Itk\Cms\Resource\ActionContext`
- `Itk\Cms\Resource\ActionResult`
- `Itk\Cms\Resource\Metric`
- `Itk\Cms\Attribute\CanSee`
- `Itk\Cms\Attribute\Group`
- `Itk\Cms\Attribute\HideFromMenu`
- `Itk\Cms\Attribute\Icon`
- `Itk\Cms\Attribute\Order`

`HookDispatcher`, `ActionRunner`, `ResourceRegistry` and `ResourceApplier` execute or discover these declarations inside the bundle and are internal.

### Events

- `Itk\Cms\Event\RecordEvent`
- `Itk\Cms\Event\RecordCreating`
- `Itk\Cms\Event\RecordCreated`
- `Itk\Cms\Event\RecordUpdating`
- `Itk\Cms\Event\RecordUpdated`
- `Itk\Cms\Event\RecordDeleting`
- `Itk\Cms\Event\RecordDeleted`
- `Itk\Cms\Event\RecordPublishing`
- `Itk\Cms\Event\RecordPublished`
- `Itk\Cms\Event\RecordUnpublishing`
- `Itk\Cms\Event\RecordUnpublished`
- `Itk\Cms\Event\RecordTranslationAdded`
- `Itk\Cms\Event\RecordTranslationRemoved`
- `Itk\Cms\Event\MassAction`
- `Itk\Cms\Event\PageTemplateChanged`
- `Itk\Cms\Event\SeoRulesChanged`
- `Itk\Cms\Event\SchemaOrgGraph`
- `Itk\Cms\Event\RequestDelivery`
- `Itk\Cms\Entity\Request`

`Entity\Request` is the delivery event's payload. This does not expose the database tables or repositories as a supported integration API.

### Content and site services

- `Itk\Cms\Content\ContentReader`
- `Itk\Cms\Content\ContentException`
- `Itk\Cms\Content\PageService`
- `Itk\Cms\Content\CollectionService`
- `Itk\Cms\Content\SettingsService`
- `Itk\Cms\Content\MenuService`
- `Itk\Cms\Content\SiteAddress`
- `Itk\Cms\Content\ItemTemplate`
- `Itk\Cms\Settings\Settings`
- `Itk\Cms\I18n\I18n`
- `Itk\Cms\I18n\ContentLocales`
- `Itk\Cms\Support\Slug`
- `Itk\Cms\Support\Url`
- `Itk\Cms\Admin\AdminPath`
- `Itk\Cms\Rendering\PageCache`

Use `ContentReader` and Twig functions to read visitor-facing published content. `SiteAddress` parses and normalizes a site origin. `ItemTemplate::render()` and `first()` render repeater item labels. `PageCache` exposes `clear(): bool` for manual clearing.

`Slug::slugify(string $text, int $max = 64): string` transliterates Russian and Kazakh text into a lowercase Latin slug, replaces separators with hyphens and limits its length to `$max`. Use it in resource hooks as shown in the README. `Slug::areaKey(string $name, array $taken = []): string` builds a menu area key using underscores and starting with a letter. Pass existing keys in `$taken` to add a numeric suffix on collision.

### Extensions, status and notifications

- `Itk\Cms\Extension\ItkExtensionInterface`
- `Itk\Cms\Extension\Registry`
- `Itk\Cms\Extension\NavItem`
- `Itk\Cms\Health\HealthCheckInterface`
- `Itk\Cms\Health\HealthResult`
- `Itk\Cms\Notification\NotifierInterface`
- `Itk\Cms\Notification\Notification`
- `Itk\Cms\Notification\NotificationChannelInterface`

### Security and custom MCP tools

- `Itk\Cms\Entity\User`
- `Itk\Cms\Entity\ApiToken`
- `Itk\Cms\Security\AdminUserProvider`
- `Itk\Cms\Security\UserChecker`
- `Itk\Cms\Security\UserManager`
- `Itk\Cms\Security\Viewer`
- `Itk\Cms\Security\PermissionVoter`
- `Itk\Cms\Security\Access`
- `Itk\Cms\Mcp\ApiTokenAuthenticator`
- `Itk\Cms\Mcp\McpToolInterface`
- `Itk\Cms\Mcp\AbstractMcpTool`
- `Itk\Cms\Mcp\McpContext`
- `Itk\Cms\Mcp\McpScopes`
- `Itk\Cms\Mcp\McpToolException`
- `Itk\Cms\Mcp\McpToolListError`
- `Itk\Cms\Mcp\McpConditionalTool`

`Entity\ApiToken` is exposed by `McpContext::$token`. Authentication listeners, MCP server internals and built-in MCP tool implementations are internal.

### Knowledge base search and embedding providers

- `Itk\Cms\KnowledgeBase\KnowledgeSearch`
- `Itk\Cms\KnowledgeBase\Model\SearchOptions`
- `Itk\Cms\KnowledgeBase\Model\SearchResult`
- `Itk\Cms\KnowledgeBase\Model\Hit`
- `Itk\Cms\KnowledgeBase\Model\Source`
- `Itk\Cms\KnowledgeBase\Model\SourceKind`
- `Itk\Cms\KnowledgeBase\Model\SourceRef`
- `Itk\Cms\KnowledgeBase\Model\KnowledgeBaseUnavailable`
- `Itk\Cms\KnowledgeBase\Embedding\Embedder`
- `Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed`
- `Itk\Cms\KnowledgeBase\Embedding\Embeddings`
- `Itk\Cms\KnowledgeBase\Embedding\Purpose`
- `Itk\Cms\KnowledgeBase\Embedding\Space`
- `Itk\Cms\KnowledgeBase\Embedding\Vector`

`SourceRef` is returned by search sources and sync messages. `OpenAiEmbedder` and indexing internals are internal.

### Messenger messages

- `Itk\Cms\Request\SendRequestNotification`
- `Itk\Cms\Media\GenerateImageVariants`
- `Itk\Cms\Mail\SendMail`
- `Itk\Cms\Security\RequestPasswordLink`
- `Itk\Cms\IndexNow\Model\PingIndexNow`
- `Itk\Cms\CriticalCss\Model\GenerateCriticalCss`
- `Itk\Cms\CriticalCss\Model\When`
- `Itk\Cms\KnowledgeBase\Model\SyncKnowledge`

Sites may route these messages to their transports. `GenerateCriticalCss(string $translationId, When $when = When::Outdated)` targets a page translation. `When::Outdated` regenerates missing or stale CSS, `When::NotReady` also retries failed generation, and `When::Always` regenerates regardless of freshness. The policy evaluation helpers, message handlers, mail implementation and other critical CSS models are internal.

## PageService operations

Inject `PageService` for trusted PHP code that edits content. These operations preserve validation, field permissions, hooks and history. At an HTTP or MCP entry point the caller must also authorize the operation and resource. A `null` locale selects the main language. Methods returning a page return the flat draft array described in the README. `getDraft()` returns the editor wrapper, `getPublished()` returns the live snapshot or `null`, and `list()` returns list rows. Use `ContentReader` for public rendering.

The six `$guard` parameters in `addTranslation()`, `removeTranslation()`, `saveDraft()`, `update()`, `reorder()` and `tree()` are internal authorization callbacks. They receive the bundle's internal `Entity\Page` objects. Site code must omit `$guard` or pass `null`.

### Reading and record selection

- `PageService::mainLocale()`
- `PageService::list()`
- `PageService::get()`
- `PageService::getPublished()`
- `PageService::getDraft()`
- `PageService::records()`

`records()` returns `Resource\Record` values for hooks and actions and raises an error for missing IDs. `get()` requires a translation. `getDraft()` can describe a missing translation without creating one.

### Creating and editing

- `PageService::create()`
- `PageService::saveDraft()`
- `PageService::update()`
- `PageService::reorder()`
- `PageService::tree()`

`create()` accepts the page input array. `saveDraft()` writes sections and fields. `update()` patches list-level data without sections. Pass the last returned opaque `version` as `saveDraft()`'s `$baseVersion` to reject stale edits with `409`. Parent and template are shared by all translations. `reorder()` returns `void`. `tree()` returns the number of moved pages.

### Translations

- `PageService::addTranslation()`
- `PageService::removeTranslation()`

`addTranslation(string $id, string $locale, ?string $from, ?callable $guard = null): array` requires an explicit source language or `null` for schema defaults. It creates an unpublished draft and returns that language's flat page. `removeTranslation(string $id, string $locale, ?callable $guard = null): void` removes a translation when it is neither the main nor the last translation. Both operations validate enabled languages and preserve the shared page identity and tree placement.

### Publication and approval

- `PageService::publish()`
- `PageService::republish()`
- `PageService::unpublish()`
- `PageService::revert()`
- `PageService::submit()`
- `PageService::returnToAuthor()`
- `PageService::massPublish()`

`publish()` publishes the draft, optionally checking `$baseVersion`. `republish()` reactivates the saved publication. `revert()` restores the live content into the draft. Approval operations change the selected language's workflow state. `massPublish()` returns the number published.

### Deletion

- `PageService::remove()`
- `PageService::massRemove()`

`remove()` deletes the whole page and returns `void`. `massRemove()` returns the number removed. Use `removeTranslation()` for one language. Domain failures raise `ContentException` with HTTP codes including `403`, `404`, `409` and `422`.

### Internal PageService members

The constructor, container setter, lock wrapper, entity lookup and conversion, live-snapshot helper, change counters/descriptions, H1 and required-field helpers, history restoration, presentation (`present`, `summary`, `flat`, `versionOf`, `row`, `iso`), address validation and section normalization/defaults are marked `@internal`.

## Other public contracts

The compatibility rules also cover documented Twig function names and documented arguments (including `itk_stylesheets()`), route names, `itk_cms` configuration options, console command names and options, Messenger transport names (`async`, `critical_css`, `knowledge_base`, `failed`), configured rate limiter names, the `window.ITK` admin plugin contract versioned by `ITK.version`, and the documented admin JSON API used by the shipped admin and supported clients.

The `itk_*` database schema, repository queries, generated admin implementation and unlisted PHP types are internal. Bundle migrations own the CMS tables. Use the documented services and extension points for integrations.
