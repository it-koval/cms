English | [Русский](CHANGELOG.ru.md)

# Changelog

All notable changes to ITK CMS are recorded in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). [UPGRADE.md](UPGRADE.md) defines the public API.

## [Unreleased]

### Added

- `itk_cms.analytics.start` selects `auto` (default) or `manual` in site configuration. The browser API `window.itkAnalytics.start()` starts all configured counters, and `start(['ga'])` selects GA. Counter keys are `metrika` and `ga`. New starts dispatch the bubbling `itk-analytics:started` event on `document` with `detail.counters`. Repeated calls without new counters emit no event.
- Page and collection lists show translation status and open the selected version. Content editors, translated settings and menus have a language picker. The dashboard and collection counters cover all configured languages.
- Page and record history can show one language or all languages. `GET /admin/api/history/{kind}/{id}?scope=all` paginates across languages, and restoring a revision targets its own draft.
- Multilingual SEO emits reciprocal `hreflang` links, including `x-default` for an indexable main version. Published pages use their own canonical URL when none is set. The sitemap covers every indexable language version, and `llms.txt` groups pages by language. Both generated files refresh after committed page changes and revalidate with content ETags.
- `itk:doctor` checks language prefixes, home pages, missing translations in settings and menus, and overrides of generated crawler files.
- Public routes serve published language versions at addresses such as `/kk/about`.
- Twig pages expose `html_lang`, `og_locale` and `translations`. The last field lists published versions for a language switcher.
- `RecordTranslationAdded` and `RecordTranslationRemoved` extend `RecordEvent`, carry the operation locale and are dispatched after commit. Translation operations do not call object create/delete events or lifecycle hooks; removing a live translation still emits the unpublish events and hooks.
- Page and collection record translations can be created and deleted through the admin JSON API and the public `PageService` and `CollectionService` methods. A new translation is a draft copied from another language or filled with schema defaults. Deleting it preserves the other languages. The main and last versions cannot be deleted.
- `itk_cms.locales` configures content languages and their display names and metadata.
- The public `Itk\Cms\I18n\ContentLocales` service exposes the ordered language list, display names, prefixes and metadata.

### Changed

- Admin requests and media lists take `page` and `perPage` and return a paginated object. MCP request and media lists apply their existing `offset` and `limit` in the database. See [UPGRADE.md](UPGRADE.md#030).
- Today reads snapshots in batches and displays the latest eight new requests with a link to the whole list. Public listings read the relevant subtree and live collection records. The media warm command reads bounded batches and reuses variant generation.

- Analytics initialization uses one versioned external `defer` loader with `data-*` attributes. In `manual`, the bundle makes no provider requests or preliminary connections before `start()` and omits Metrika's `<noscript>` pixel. `auto` keeps that pixel. Verification meta tags remain available in both modes. Sites manage consent, persistence and reload after revocation, and must remove their direct analytics inserts before switching to `manual`. See [UPGRADE.md](UPGRADE.md#030).
- `GET /admin/api/collections/{col}` always returns the paginated object `{ items, total, page, perPage, facets, metrics }`. Search, filters, status and translation selection apply before counting and pagination. Published versions with unpublished changes are included in the `draft` filter. Clients must read `items` and request further pages. See [UPGRADE.md](UPGRADE.md#030).
- IndexNow submits the affected paths for all configured languages. Critical CSS is generated for the published versions of those languages.
- Publishing or removing a translation refreshes the cached HTML and ETag of the other versions of the same page.
- On configured sites, live and preview templates translate text in the page's language. JSON-LD uses its HTML language tag.
- Public forms record the locale from `?locale=`, the same-origin referrer or the main language on configured sites. Form messages, including `429`, use the submission language when the bundle provides a translation.
- On sites without `itk_cms.locales`, form messages now use the site's main language when the bundle provides a translation. Previously, `admin_locales` determined the message language.
- MCP load hooks run once per resource and language in each request.
- `Mcp\AbstractMcpTool::locale()` is now non-static. Both `self::locale($args)` and `$this->locale($args)` work inside instance methods. Calls from a static context are no longer supported. See [UPGRADE.md](UPGRADE.md#030).
- The admin API uses a shared language check and returns `422` for a code outside an explicitly configured list.
- On sites with several configured languages, page versions use their language's address prefix. The main language's addresses cannot start with another configured language's path segment.
- PHP and the admin editor use a shared Russian and Kazakh transliteration table when generating slugs.
- Republishing a page at the same address queues one IndexNow notification.

### Fixed

- The preview opens the field clicked inside a disclosure, including marked collection records and settings in the preview language. Each photo has its own editable alt text.
- Draft input survives a failed delete or a cancelled navigation. Escape closes the top dialog, destructive confirmations focus Cancel, and profile edits prompt before closing.
- Collection lists show load errors with Retry and disable tree moves when a parent is missing. Field errors are associated with their inputs. Media names and request form names wrap on narrow screens.
- Saving a page draft preserves the sitemap cache. Mail and messenger tests release the session while waiting for delivery.

- Language links, crawler files, IndexNow and critical CSS include only versions whose addresses route to their language. Moving a multilingual version preserves any live page at its old address.
- An invalid public URL leaves public HTML and crawler routes usable. The doctor reports the invalid configuration.
- Canonical slash redirects preserve the original query string, including repeated parameters. The SEO address check applies the same routing and shows the canonical and rules for the URL's language.

## [0.2.2] - 2026-11-22

### Fixed

- A form value no longer loses the text after a `<`. Before, `<500`, `<XS` or `2<3` cut the value up to the next `>` or to its end, so an order could lose the rest of its list and the total. HTML tags are still removed: a lowercase element name with `name="value"` attributes. Any other `<` stays as text with a space after it (`<500` is saved as `< 500`). The space counts towards `max()`, and `pattern()` now sees the text that used to be dropped.

## [0.2.1] - 2026-11-22

### Fixed

- A form field with `max()` above 1000 no longer loses the text after 1001 characters. A value within `max()` is saved whole (as before, after trimming and removing tags), and `pattern()` checks all of it. A longer one gets the usual "field is too long" error, and no request is created. A field without `max()` still keeps the first 1001 characters without an error.
- A request too long for one Telegram or MAX message is sent as several messages in order, a second or more apart because of the rate limits of the messengers. Before, Telegram refused it and MAX cut it at 4000 characters. A request counts as delivered when every message has gone out. A retry starts from the first message, so a chat may show some messages twice.
- The request card shows a name, city or phone whole when it is longer than its database column (120, 120 and 32 characters). The list keeps the short values.

Restart the queue workers after the update: a running worker keeps the old code.

## [0.2.0] - 2026-11-15

### Added

- **Send to a messenger** for a request that never went there. A request that arrived before MAX or Telegram was connected now has a **Send to MAX** (or **Send to Telegram**) button in the request drawer. The request JSON has a new `sendable` field with the messengers that can take it now (not yet tried, connected, switched on and with a chat for the form). A failed messenger keeps **Send again**.

### Changed

- **Site status** shows a module that is not connected or switched off (Yandex Webmaster, Telegram, MAX, mail, IndexNow, analytics, critical CSS, knowledge base, MCP firewall) as a yellow **Not connected** instead of a green **Working**. The new `HealthResult::off()` status does not change the overall status or the exit code of `itk:doctor --ci`. The status `off` can now appear in `checks[].status` of the admin `health` API and in the lines of `itk:doctor`.
- The permission matrix of a role shows **Publish** as the first column.

### Fixed

- **Settings → Notifications** no longer refuses to save when the chat for the other forms is empty while Telegram or MAX is connected. The table now shows a yellow note that requests from forms without a chat will not reach the messenger. `SettingsService::save()` accepts such routes too.

### Removed

- `Content\SettingsService::routesError()`, which checked that a connected messenger had a chat for the other forms. The constructor of `SettingsService` no longer takes the `max` and `telegram` arguments. See the 0.2.0 notes in [UPGRADE.md](UPGRADE.md).

## [0.1.2] - 2026-11-22

### Fixed

- A form value no longer loses the text after a `<`. Before, `<500`, `<XS` or `2<3` cut the value up to the next `>` or to its end, so an order could lose the rest of its list and the total. HTML tags are still removed: a lowercase element name with `name="value"` attributes. Any other `<` stays as text with a space after it (`<500` is saved as `< 500`). The space counts towards `max()`, and `pattern()` now sees the text that used to be dropped.

## [0.1.1] - 2026-11-22

### Fixed

- A form field with `max()` above 1000 no longer loses the text after 1001 characters. A value within `max()` is saved whole (as before, after trimming and removing tags), and `pattern()` checks all of it. A longer one gets the usual "field is too long" error, and no request is created. A field without `max()` still keeps the first 1001 characters without an error.
- A request too long for one Telegram or MAX message is sent as several messages in order, a second or more apart because of the rate limits of the messengers. Before, Telegram refused it and MAX cut it at 4000 characters. A request counts as delivered when every message has gone out. A retry starts from the first message, so a chat may show some messages twice.
- The request card shows a name, city or phone whole when it is longer than its database column (120, 120 and 32 characters). The list keeps the short values.

Restart the queue workers after the update: a running worker keeps the old code.

## [0.1.0] - 2026-11-15

The first public release.

### Requirements

- PHP 8.3 or newer and Symfony 7.4, or PHP 8.4 or newer and Symfony 8.
- PostgreSQL 16 or newer with the pgvector extension 0.8 or newer. MySQL is not supported.
- Node only to build the admin panel from source or to generate critical CSS.

### Pages and sections

- Pages built from sections, with page templates, placement rules for the page tree, and a single H1 rule.
- Drafts with autosave, preview in the site templates, publishing, and unpublishing.
- Optimistic locking of drafts: if someone else saved the draft first, the server returns 409 and the admin panel lets the editor choose a version.
- ETag and 304 responses for published pages, and security headers on public routes.

### Collections

- Collections described in the schema or in resource classes, with fields, filters, sorting, search, and metrics.
- List views: table, cards, calendar, kanban board, and a drag-and-drop tree.
- Actions on rows, selections, the list, and the record form, with confirmation and input dialogs.
- Repeater fields with item titles, summaries, and duplication. Half-width fields and form sections.
- `itk_pages`, `itk_page`, and `itk_collection` Twig functions and `Content\ContentReader` for reading content in templates.

### Menus and settings

- Menus with areas and nesting depth defined in the schema.
- Settings groups from the schema, built-in site and analytics groups, and settings defined in code.
- Admin panel appearance: brand and accent colors and corner radius.

### Media

- A media library with folders, crops, and focus points.
- WebP and AVIF copies made on upload, and resized copies served on request.

### SEO

- SEO rules for any URL, redirects, and `sitemap.xml`.
- `/robots.txt` and `/llms.txt` served by the bundle and edited in **Settings → SEO**. A file in `public/` takes precedence.
- schema.org JSON-LD on live pages: `WebSite`, `Organization` or `LocalBusiness`, `BreadcrumbList`, and `BlogPosting`. Company fields in the site settings. The `Event\SchemaOrgGraph` event adds custom types.
- An AI search line on the site status screen and in `itk:doctor`. It warns when `robots.txt` blocks AI crawlers.

### Analytics and webmaster tools

- Yandex Metrika (with Webvisor), Google Analytics 4, and Yandex and Google verification meta tags, inserted into live pages from the settings. A counter already present in the layout is not added twice.
- Yandex Webmaster: connect once with an OAuth token. The bundle adds and verifies the site, submits `sitemap.xml`, and shows indexing data in the admin panel.
- IndexNow: when enabled, the queue worker reports published, moved, and removed pages to Yandex and Bing.

### Requests

- Form requests with a honeypot field and rate limiting, statuses, a board view, and resending.
- Notifications by e-mail, Telegram, and MAX. Telegram and MAX are optional, and each has its own delivery status.
- Telegram and MAX bot tokens can be set in **Settings → Bots**. They are stored encrypted and take effect without a restart.

### Users and access

- Users and roles with permissions per section, per action, and per page template.
- Two-step sign-in, password recovery by e-mail, and sign-in throttling.
- `itk:user:password` and `itk:user:reset-2fa` commands.

### History and trash

- Change history for pages, records, settings, and menus, with a diff and restore.
- A trash for deleted pages and records.
- In-app notifications.

### Performance

- A page cache for live pages. Publishing and other content changes show up immediately. The `itk_cache()` Twig function, the `X-ITK-Cache` header, and the `itk:cache:clear` command control it.
- Optional critical CSS: after publishing, the worker extracts the above-the-fold rules with `crittr`, and `{{ itk_stylesheets() }}` inlines them. Requires Node 22 or newer.

### AI

- A knowledge base on pgvector: a semantic search index of the published site text, kept up to date by the queue worker. Embeddings come from OpenAI. Off by default.
- An MCP server for AI assistants over HTTP (`/mcp`) and stdio (`itk:mcp`), with API tokens, scopes, and tool annotations. Sites can add their own tools.

### Operations

- A site status screen in the admin panel and the `itk:doctor` command. They check PHP, the database and pgvector, `APP_SECRET`, uploads, the queue, Telegram and MAX, analytics, IndexNow, Yandex Webmaster, AI search, critical CSS, and the knowledge base.
- `GET /healthz` for uptime checks.
- `itk:install` creates roles, default settings, the queue tables, the home page, and the first administrator. It is safe to run again.

### Admin panel

- A Vue 3 admin panel shipped prebuilt with the bundle.
- The interface in English, Russian, and Kazakh. Each user picks a language in **My profile**. The default comes from `itk_cms.admin_locale`.
- A configurable admin URL (`itk_cms.admin_path`).
- A plugin contract (`window.ITK`) for custom fields, screens, actions, widgets, and editor buttons, with TypeScript declarations.

### Developer tools

- `itk:make:*` generators for templates, sections, collections (also from a description or a database table), fields, settings, and admin plugins.
- `itk:schema:lint` and a strict template mode (`itk_cms.strict`).
- A Docker kit in `docker/`: PHP-FPM, nginx, a queue worker, PostgreSQL 17 with pgvector, and Mailpit, with development and production stages.
- Rules for an AI agent that builds a site on the bundle (`SITE-AGENTS.md`).

[0.2.2]: https://github.com/it-koval/cms/releases/tag/v0.2.2
[0.2.1]: https://github.com/it-koval/cms/releases/tag/v0.2.1
[0.2.0]: https://github.com/it-koval/cms/releases/tag/v0.2.0
[0.1.2]: https://github.com/it-koval/cms/releases/tag/v0.1.2
[0.1.1]: https://github.com/it-koval/cms/releases/tag/v0.1.1
[0.1.0]: https://github.com/it-koval/cms/releases/tag/v0.1.0
