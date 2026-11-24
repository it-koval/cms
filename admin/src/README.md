English | [Русский](README.ru.md)

# ITK CMS admin panel (Vue 3 and Vite)

The admin panel is a thin client for the `itk/cms` server. All data goes through `fetch` to the admin API (`/admin/api` by default). The admin URL is the site option `itk_cms.admin_path`, `/admin` by default, and the client takes every URL from the server shell. The site and its schema come from the server.

## Commands

Run npm commands from `admin/` inside Docker with `docker compose run --rm node <command>`. For Vite, use the root `make` targets in the table so the container publishes its port. The container uses Node 24.22.2 (`.nvmrc`, `engines`, `admin/compose.yaml`) and keeps the Linux dependencies in a separate volume, apart from the host `node_modules`. You do not need Node or PHP changes on the host.

| Command | What it does |
|---|---|
| `npm ci` | Installs the dependencies from the lock file. |
| `make admin-dev` from the repository root | Runs `npm run dev` in Docker. Starts Vite at `http://127.0.0.1:18023/bundles/itkcms/admin/` and proxies the API to the site. See [Develop without the server shell](#develop-without-the-server-shell). |
| `make admin-mock` from the repository root | Runs `npm run dev:mock` in Docker. Starts the admin panel on port 18023 without PHP, on made-up data. See [Mock mode](#mock-mode). |
| `npm run lint` | ESLint 9. |
| `npm run check:i18n` | Checks the interface catalogs. See [Interface language](#interface-language). |
| `npm test` | Vitest. |
| `npm run build` | Writes the admin panel to `packages/cms/public/admin`, with `.vite/manifest.json`. A site then runs `bin/console assets:install`. |

## Server shell

`packages/cms/templates/admin/index.html.twig` renders the page and reads the Vite manifest. It sets `window.ITK_ADMIN`:

```js
window.ITK_ADMIN = {
  adminBase, apiBase, csrf, user, site: { name, domain }, mainLocale,
  siteTexts: { item, section }, locale, locales: [{ code, name }],
  previewBase, loginUrl, logoutUrl, assetsBase,
  images: { uploadUrl, variantDir, widths }, theme,
}
```

- `locale` is the interface language of the current user, used by `t()`. `locales` lists the languages the user can pick, each named in its own language.
- `mainLocale` is the language of the site content, not of the interface.
- `siteTexts` holds default names for a new item and a new section, in the content language.
- `theme` holds the colors and radii. See [Theme](#theme).

## Source layout

- `api/` is the API client. `http.js` wraps `fetch`, sends the CSRF token in `X-CSRF-Token`, and turns `{error, details}` responses into `ApiError`. There is one module per area (`pages`, `collections`, `requests`, `media`, `settings`, `users`, `site`, and others). A comment above each function names its endpoint.
- `schema/` holds the site schema. `registry.js` loads `GET /admin/api/schema` into one `schema` object before the app starts. The other files are shared logic on top of it: templates and placement rules, the H1 rule, the section library, settings, and menu areas.
- `i18n/` is the interface language. See [Interface language](#interface-language).
- `stores/app.js` holds the site, the languages, the current user and permissions (`can(area, action, {template})`), and the navigation badges.
- `views/SettingsView.vue` and `components/settings/` are the settings screen. `NAV` in `settingsSchema.js` defines the left menu: five headings with eleven items (General, Menus, Redirects, SEO, Notifications, Bots, Mail, Users and roles, AI assistants, Admin panel appearance, and Templates under a developer heading). **General** shows every settings group of the site schema on one page, one card per group, with permissions checked per card.
- `components/editor/` is the page editor. The canvas is `PreviewFrame.vue`, an iframe of `/admin/preview/{id}` where the server renders the draft with the site templates. The frame and the editor talk through `postMessage` (`itk:focus`, `itk:action`, `itk:select`, `itk:reload`, see `packages/cms/public/preview.js`). After an autosave the iframe reloads and keeps the scroll position.
- The language switcher is hidden while the site has one language.
- `layouts/AppLayout.vue` is the left rail. The server builds it (`GET /admin/api/schema` returns `nav`, stored as `app.nav`):

  ```js
  [{ key, group, icon, order, items: [{ key, label, to, icon, order, badge?, sub? }] }]
  ```

  The list is sorted and filtered to what the user may open. A group with a name gets a small heading. `sub: true` marks a quick link under the previous item. The rail highlights the most specific item for the current URL. A badge comes from `app.badges[key]` if present, otherwise from the server `badge`. A new resource class or an extension `NavItem` appears in the rail on its own. Icons are in `components/ui/icons.js`. An unknown icon name draws a document icon.

## Theme

The server (`Admin\Theme\AdminTheme`) computes the colors and radii and injects them into the shell as CSS variables before the first paint. The admin styles have no hard-coded brand or accent colors, only tokens: `--cy*`, `--mag*`, `--brand-ink` for text on the brand color, `--brand-text` for brand-colored text on a light background, and `--rail-shade`. `styles/tokens.css` lists them.

`utils/theme.js` applies the `/admin-theme` response to the open page. The screen is `components/settings/ThemePanel.vue` with `ThemeColor.vue`, and the client is `api/theme.js`. In new styles, write brand and accent colors as tokens, and use `var(--brand-ink)` instead of `#fff` for text on the brand color.

## Image previews

Every `<img>` that shows a media library image (in fields, cards, lists, the file drawer, and the rich text editor) gets its URL from `thumbUrl(src, width)` in `utils/media.js`. Use 320 (`THUMB`) for small previews, 640 (`PREVIEW`) for cards and the file drawer, and 960 in article text. The helper builds the WebP copy URL from `window.ITK_ADMIN.images`, with the same scheme as `ImageVariants::url` on the server. GIF, SVG, PDF, external URLs, and `npm run dev` (which has no image config) keep the original URL. Saved values do not change, only the display. Write any new `<img>` for a media library image the same way.

## Collection list

The list knows only what the collection schema (`cfg`) tells it. No collection code appears in it. The files are `components/collections/CollectionList.vue`, `list/`, and `actions/`.

- **Data.** `api.collections.query(col, { q, filter, sort, page, perPage })` returns `{ items, total, page, perPage, facets, metrics }`. The database does the search, filters, sorting, and paging. A filter goes out as `filter[status]=…&filter[date][from]=…`, and sorting as `sort=-dateStart`.
- **Filter panel.** `list/ListFilters.vue` draws a control for each field with `field.filter = { kind, options }`, which the server sends for `filterable()` fields. The kinds are `text`, `select`, `multi`, `toggle`, `dateRange`, `numberRange`, and `relation`. Every collection has a status filter. An unknown kind is dropped. `facets` show how many records have each value.
- **Columns and sorting.** The title comes first, then fields with `list.show === true` (or `null` for sortable fields). `hideOnIndex` on the server becomes `list.show === false`. A click on a sortable header cycles through ascending, descending, and unsorted. `list/CellValue.vue` picks the cell renderer by value type.
- **Metrics.** `list/ListMetrics.vue` draws tiles above the list from the resource's `Metric::count` and `Metric::sum`.
- **State.** `list/useListState.js` saves filters, sorting, page size, and view on the server per user (`api.prefs`, key `list.<collection>`, `GET` and `PUT /admin/api/prefs/{key}`). They come back on the next visit from any device. Search and page number are not saved. Saved values for fields that no longer exist are dropped on read (`sanitizeSaved`). If the server still refuses them (422), the list opens without filters.
- **Field permissions.** The server does not send fields the user may not see, and the client works without them: no column, no value. `hideOnForm` becomes `form: false` (the form does not draw the field). `readonly` becomes `ro: true` (drawn but not editable).

## Tree, calendar, and board

These three components know nothing about a specific site. Where possible, their logic sits outside Vue so it can be tested without a browser.

### `components/ui/SortableTree.vue` and `treeOps.js`

A tree whose nodes can be moved by dragging the handle (only the handle is draggable, because rows may contain inputs) or with the keyboard (Alt with the arrow keys, and the Up, Down, Indent, and Outdent buttons).

- The tree is nested: `[{ id, …, children: [...] }]`. `treeOps.nest(flatListWithParentId)` builds it. The `id-key` and `kids-key` props rename the keys.
- Props:
  - `max-depth`: levels, starting at 1. A node counts with its whole branch.
  - `can-drop({ node, parent, index, nodes })`: returns `true`, `false`, or a reason string. It runs after the built-in rules (not into itself, not into its own branch, depth).
  - `locked(node)`: the node stays in place, for example the home page.
  - `tools`: buttons in the row.
  - `bare`: a row without its own border.
  - `foldable` and `disabled`.
- The component changes nothing itself. The `move({ id, node, parentId, index, from, to, nodes, groups })` event passes the new tree to the parent, which saves it and passes the tree back. `invalid(reason)` reports a refused move.
- `treeOps.payload(nodes, groups)` builds `[{ id, parentId, position }]` for the siblings under the old and the new parent.
- Drop zones on a row: the top third drops before it, the bottom third after it (as the first child for an expanded branch), and the middle inside it. A forbidden target shows a red border and the reason under the tree. A live region tells screen readers where the node is now.
- Used by: **Pages → Change structure** (`api.pages.tree`, placement rules from `canPlace` in `schema/templates.js`, undo in the toast), menu items (`components/settings/MenusEditor.vue`, depth from the menu area), and the **Structure** view of a collection with `tree()` (`api.collections.tree`).

### `components/collections/list/CalendarView.vue` and `calendarModel.js`

- A month or a week view. Weeks start on Monday. Days are `YYYY-MM-DD` strings computed in UTC, so daylight saving time shifts nothing.
- The `start` and `end` props are field keys from the view settings.
- Events: `create(day)` (a click on a day or on "+") and `move({ row, start, end, days })` (dragging or Alt with the arrow keys moves both dates by the same number of days and keeps the time of day).
- `CollectionList.vue` saves the move with a regular `api.collections.update` of the draft. The row changes on screen at once and moves back if the server refuses. The toast offers undo.
- A month cell shows three records. "N more" opens that day's week.

### `components/ui/KanbanBoard.vue`

- Columns come from values: `columns: [{ value, label }]` and `column-of(item)`.
- A card moves by dragging or with the arrow buttons (Alt with Left or Right). The event is `move({ item, from, to })`. The order inside a column is not stored. The `card` slot draws the card.
- Used in the collection list (the `kanban` view from the schema, on a `select` field) and on the **Requests** screen (the board view with New, In progress, and Closed columns; `api.requests.update` changes the status; spam is not shown on the board).

### View switcher

`viewsOf(cfg)` in `list/listModel.js` builds the switcher above the collection list: `table`, `cards`, `calendar`, and `kanban` from the schema `views`, plus `tree` when the collection has `tree()`. The calendar, board, and tree load all records at once (`queryAll`, in batches of 200, up to 2000) and have no pager. The chosen view is saved with the rest of the list state (`api.prefs`). On the **Requests** screen it is saved under `requests.view`.

## Site words come from the schema

The admin panel contains no site name and no names of things a site sells. What the user sees comes from the schema (`schema/*`). Shared words are in `components/collections/labels.js` and `utils/format.js`.

- **Row toggle in a list.** A collection names its toggle field in `cfg.toggleField`. `toggleLabelOf(cfg)` in `labels.js` takes the field's own label, for example "On the home page". A field without a label is called "Show". The column header and the toast after a click (`toggleNote()`) use that name.
- **Page toggle in the parent listing.** `listingSwitch(template)` in `schema/templates.js` finds the field that the parent template names in `listing.onlyIf` (`->listing(of: [...], onlyIf: 'show')`) and takes its label. A site without `onlyIf` whose pages have a toggle field named `catalog` falls back to that field. The same function draws the column in the flat page list (`PagesView.vue`) and the "Not shown in the listing" warning on the page card (`components/editor/TemplateCard.vue`).
- **Options matrix.** The `view: 'matrix'` view (`components/collections/MatrixView.vue` with `matrixChanges.js`, words in `schema/matrix.js`) uses generic words by default ("column", "row", "New column"). A collection sets its own words like this:
  - `cfg.newLabel`: the label of the add button only, a verb phrase such as "Add plan".
  - `cfg.description`: the line under the title.
  - `cfg.matrix.colFields[1].label`: the name of the price row.
  - `cfg.matrix.words = { colNew, colPlural: ['plan', 'plans', 'plans'], rowNew, … }`: any phrase from `MATRIX_WORDS`, with `{name}` substitution. The name of a new column ("New plan") is set only here, in `colNew`. A value of the wrong type is dropped.
- **Migrated pages.** A page imported from a site that existed before the CMS shows a "migrated" note instead of the update date until someone edits it (`MIGRATED_NOTE` in `utils/format.js`).

## History and trash

- `api/history.js` covers `GET history/:kind/:id`, `GET history/revision/:id`, `POST history/revision/:id/restore`, and `GET history/trash`.
- `components/history/HistoryDrawer.vue` is the **History** side panel. It lists who changed what and when. An entry opens to show what it changed and what will change if you restore it. **Restore this version** asks for confirmation.
- `ChangeRows.vue` draws the change rows, and `historyWords.js` names the actions.
- The panel takes `kind` (`page`, `item`, `settings`, or `menu`), `resource-id`, `locale`, and `can-restore`, and emits `restored`. The screen then reloads the draft (`ed.reloadDraft()` in the page editor, `load()` for a record).
- The button sits in `EditorTopBar.vue` and in the header of `CollectionItemView.vue`. The screen flushes the autosave before the panel opens.
- `views/TrashView.vue` (`/trash?kind=&key=`) is the **Trash** screen with deleted pages and records. **Restore** opens the restored item as a draft. **Pages** and the collection list link to it.

## Notifications

- `api/notifications.js` covers `GET notifications?unread=1&limit=&offset=` and `POST notifications/read` with `{ ids }` or `{ all: true }`.
- `stores/notifications.js` holds the unread count and the panel list, and polls every 60 seconds while the tab is open.
- `components/notifications/NotificationBell.vue` is the bell button in the site block of the rail, with a counter and a **Notifications** side panel (All or Unread, and Mark all as read). Each row shows a title, details, and the time.
- A click on a row marks it read and follows its link. An admin route (`/requests/12`) opens through the router. A full URL opens in a new tab.
- The server decides what each user sees by permissions and role. The client filters nothing.

## Actions

`Action::make(...)` in a resource class ends up in `cfg.actions`:

```js
{ key, label, icon?, danger?, confirm?, modal?: { title, fields }, places: ['row', 'bulk', 'top', 'form'] }
```

The server has already removed actions the user may not run. `components/collections/actions/useActions.js` handles them:

- Places: `top` puts buttons in the list header, `bulk` in the bar above the table when rows are selected, `row` in the row menu, and `form` in the **Actions** menu of the record page (the draft is saved before the action runs).
- `confirm` opens a confirmation dialog. `modal` opens a dialog with fields. `ActionDialog.vue` draws them with the same `SchemaForm` as record forms and checks required fields before sending.
- The `ActionResult` response: `message` shows a toast, `reload` reloads the list, `redirect` opens an admin URL through the router or an external `http…` URL in the browser, and `download` downloads a file. An action that returns a file itself arrives as `{ file, blob, name }` and is saved. A refusal (422) shows an error and keeps the dialog open.

## Admin plugins (`window.ITK`)

A site package adds its script on the server with `Registry::adminAssets(js: [...], css: [...])`.

How plugins load:

1. The shell declares a stub `window.ITK = { plugins: [], registerPlugin }` before the main bundle and keeps plugin scripts inactive in `<template id="itk-plugins">`.
2. The admin panel loads the language and its own code, then builds `window.ITK` (`plugins/registry.js` picks up what the stub collected).
3. It runs the plugin scripts in order (`plugins/scripts.js`), so a plugin can read `ITK.vue` on its first line. `plugins/state.js` stores what plugins registered. The registry is reactive, so a plugin that arrives after the first screen is picked up too.

The first screen waits for plugin scripts. `start.js` builds the app when the admin code and every plugin script have loaded or failed. A script that hangs delays the first screen until the `WAIT` timeout in `plugins/scripts.js` (8000 ms). After that the admin panel starts without it, and a late script still registers. `registerPlugin` works immediately. A broken description is skipped and does not break the admin panel. A plugin whose widget fails to render disappears from the screen, and the rest keeps working.

### Contract version

`ITK.version` is a semver string (`ITK_VERSION` in `plugins/state.js`). A minor version adds things. A major version changes or removes something a plugin could rely on. A plugin can check the version before it starts: `if (!window.ITK || parseInt(ITK.version) !== 1) return`.

`admin/types/itk.d.ts` types the whole contract. The admin package is private and does not end up in a plugin's `node_modules`. Copy the file into the plugin project (for example, to `types/itk.d.ts`) and add it to `include` in `tsconfig.json` or `jsconfig.json`, or add `/// <reference path="../types/itk.d.ts" />` with a relative path. The global `ITK` and `window.ITK` are then typed across the project without an import.

### Register a plugin

```js
window.ITK.registerPlugin({
  // Custom field type: a schema field with t: 'color' is drawn by this component. It takes the same props and
  // events as the built-in fields (ITK.ui.fieldProps and fieldEmits, components/form/fieldProps.js).
  // An optional cell(value, field) function on the component gives the text for a list column.
  fields: { color: ColorField },
  // Custom screen: the menu item NavItem::make('Reports', '/x/reports') opens #/x/reports with this component (props: path, query).
  panels: { reports: ReportsPanel },
  // Client side of an action: 'resource:action' or just 'action'. The function receives
  // { resource, action, ids, records, locale, run(input), done(result), toast, http } and may return an ActionResult-like object.
  // { modal: Component } draws the component in a dialog (props: resource, action, ids, close(), run(input)).
  actions: { 'tours:export': async (ctx) => ctx.run({ format: 'csv' }) },
  // Custom record card on the record page, right of the form, by collection key. Props: cfg (collection schema), data (draft).
  // Without it the core draws a simple card: image (thumbField), title (titleField), subtitle (subField).
  previews: { tours: TourCard },
  // Block on the Today screen, below the built-in sections. The component draws its own card (.sheet). Props: locale.
  // Blocks appear in registration order. A widget that fails to render is hidden, and the reason stays in the console.
  widgets: { stats: StatsCard },
  // Button in the page editor header, after Preview (in the "⋯" menu on a phone).
  editorActions: {
    check: {
      label: 'Check', icon: 'check', title: 'Check the page before publishing', order: 10,
      when: (ctx) => ctx.template === 'blog_article', // optional: false hides the button for this page
      run: async (ctx) => { const r = await ctx.http.post(`x/check/${ctx.pageId}`); ctx.toast.show(r.ok ? 'All good' : r.message) },
    },
  },
})

// Custom route: #/reports/12. The path starts with "/", and its first segment must not match an admin area
// (pages, collections, requests, media, settings, trash, health, x). Such a route is refused with a reason in the console.
// Returns a function that removes the route, or null. meta.layout: 'app' (inside the rail, default) or 'bare'. meta.title: the tab title.
const remove = window.ITK.addRoute({ path: '/reports/:id?', component: ReportPage, meta: { title: 'Report' } })
```

**Editor buttons** (`editorActions`) are objects `{ label, run(ctx), icon?, title?, order?, when?(ctx) }`. A button without `label` or `run` is skipped, with the reason in the console. The admin panel flushes the autosave before `run`. `ctx` has `pageId`, `locale`, `title`, `status` (`published`, `draft`, or `changed`), `template`, `readOnly`, `page` (a copy of the draft, read on demand), `flush()`, `reload()` (rereads the draft after your own changes through `ctx.http`), `toast`, and `http`. An error thrown by `run` shows as a toast.

**Routes** (`ITK.addRoute`): a plugin script loads after the bundle, and the user may have opened `#/reports` earlier. By then the router has sent the user to **Today** because the URL was unknown. When the route appears, the user is sent where they were going. If a menu item is all you need, `panels` with `NavItem::make('Reports', '/x/reports')` is simpler. `addRoute` refuses a route and returns `null`, with the reason in the console, if:

- the path starts with an admin area;
- the `component` has a `template` option without `render` (the screen would be empty);
- the `name` matches a core route name (`today`, `pages`, and others), because vue-router would replace the admin route.

### What a plugin can use

- `ITK.version`: the contract version.
- `ITK.vue`: all of Vue (`import * as Vue`): `h`, `ref`, `reactive`, `computed`, `watch`, `defineComponent`, and everything compiled `.vue` files import (`openBlock`, `createElementBlock`, `createVNode`, `toDisplayString`, `withCtx`, `normalizeClass`, and others). It is the same Vue instance as the admin panel, so reactivity is shared.
- `ITK.http`: the API client with CSRF (`get`, `post`, `put`, `patch`, `del`, `upload`). Also `ITK.toast` (`show`, `error`), `ITK.confirm({ title, message, confirmLabel, danger })` returning `Promise<boolean>`, and `ITK.addRoute`.
- `ITK.i18n`: the interface language. It has `t(key, params)`, `locale`, and `add(locale, messages)` for the plugin's own texts, for example `add('en', { 'reports.title': 'Reports' })` and `add('ru', { 'reports.title': 'Отчёты' })`. A plural form is an object `{ one, few, many, other }`. The page reloads when the language changes, so reactivity is not needed. Plugin scripts run after the language and the admin code have loaded, so `ITK` is ready on the plugin's first line. For the same reason, `add` can override an admin key only for `t()` calls made after startup: module constants (menus, schemas) are already computed.
- `ITK.ui`: ready-made admin components. Their props and events are in the component files in `components/ui/` and `components/form/`.

  | Name | What it is |
  |---|---|
  | `Modal`, `Drawer`, `Popover` | A centered dialog (`v-model:open`, `title`, `footer` slot), a right side panel, and a menu anchored to a button (`anchor`, `align`). |
  | `ConfirmDialog` | The dialog that `ITK.confirm()` opens. It is already on the page once. Call `ITK.confirm()` instead of drawing your own. |
  | `SchemaForm` | A form built from field descriptions (`schema`, `v-model`, `published`, `readonly`), like record and action forms. |
  | `FieldShell`, `FieldRenderer` | The field wrapper (label, hint, error) and the component picker by `field.t`, including your own types. |
  | `TextField`, `TextareaField`, `HeadlineField`, `NumberField`, `ToggleField`, `SelectField`, `ImageField`, `LinkField` | The basic fields. |
  | `fieldProps`, `fieldEmits` | The props and events a field component accepts. |
  | `Icon`, `SortableTree`, `KanbanBoard` | An icon by name (`components/ui/icons.js`), the tree, and the board. |

- The classes from `styles/base.css` are already on the page: `.page` (a screen inside the rail: `max-width` and padding) with `.page-h` (the header: `h1`, `.sub`, `.actions`), `.sheet` (a white card), `.btn` (with `primary`, `ghost`, `danger`, `sm`, `icon`), `.alert` (with `info-a`, `warn-a`, `err-a`), `.chip`, `.muted`, and `.sr` (screen readers only). Use tokens for colors and spacing (`var(--ink)`, `var(--cy)`, `var(--hair)`, and others from `styles/tokens.css`), not literal values: the site theme changes them.

### Build a plugin from `.vue` files

A plugin described with `h()` needs no build. To use `.vue` files, build them with Vite and keep `vue` external, taken from `ITK.vue`. The script loads as `type="module"` in the `iife` format:

```js
// vite.config.js of the plugin
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  define: { 'process.env.NODE_ENV': '"production"' }, // Vite does not replace it in library mode
  build: {
    // Styles from <style> go to admin.css next to admin.js (without cssFileName the file is named after the package).
    // Register both with Registry::adminAssets(js: [...], css: [...]).
    lib: { entry: 'src/plugin.js', name: 'MyPlugin', formats: ['iife'], fileName: () => 'admin.js', cssFileName: 'admin' },
    rollupOptions: { external: ['vue'], output: { globals: { vue: 'ITK.vue' } } },
  },
})
```

`src/plugin.js` calls `window.ITK.registerPlugin({...})`. Do not bundle Vue into the plugin: a second Vue instance breaks reactivity and `provide` and `inject`.

The `template:` option does not work without a build. The admin panel ships Vue without the template compiler, so `{ template: '<b>x</b>' }` renders nothing. The admin panel logs a warning in the console, in development and in production, and does not register such a component. Write a `render()` function with `h()` from `ITK.vue`, or build `.vue` files as shown above.

## Interface language

`i18n/index.js` exports `t`, `locale`, `formatDate`, `formatNumber`, and `collator`:

```js
import { t, locale, formatDate, formatNumber, collator } from '@/i18n/index.js'
```

- Texts are in `i18n/<locale>/<area>.json`. Keys are flat: `"area.key"`, snake_case after the dot.
- Parameters: `t('area.key', { name: x })` fills `{name}`.
- Plurals: `t('area.key', { count: n })`. The JSON value is an object keyed by `Intl.PluralRules` categories: `{"one":…,"few":…,"many":…,"other":…}` for `ru`, `{"one":…,"other":…}` for `en`, and `{"other":…}` for `kk`.
- A key missing in a language falls back to the English text. If English is missing too, `t()` returns the key and logs `console.warn` in development.
- Format dates, numbers, and sorting with `formatDate`, `formatNumber`, and `collator` (`list.sort(collator.compare)` or `collator({ numeric: true }).compare`), not with `'ru-RU'` or `localeCompare`. `formatDate` returns an empty string for an empty (`null`, `undefined`, `''`) or invalid date instead of throwing.
- Some Chrome builds have no Intl data for `kk`, although `supportedLocalesOf` reports the language as supported. `initI18n` detects this (`hasIntlData`). Without the data, Kazakh dates are numeric (`02.11.2026`, without month and day names), and numbers and sorting use `ru`. No polyfill is loaded.

`main.js` loads the language chunk (and English after it) first, then dynamically imports `start.js` with the whole app, so `t()` works at module top level too. Changing the language reloads the page.

The language comes in `window.ITK_ADMIN.locale`. `setLocale(code)` saves the user's choice (`PUT me/locale`, the **My profile** dialog). `npm run dev` and `npm run dev:mock` have no shell, so the language is kept in `localStorage`, English by default.

The editor canvas script (`packages/cms/public/preview.js`) runs inside the site page and has no catalog. The `itk_admin_preview` route renders the page in its own language, as a visitor sees it. The words of the section toolbar come in `window.ITK_PREVIEW.texts`: `PreviewController::texts()` builds them in the user's language from `translations/itk_preview.<locale>.yaml`, and the script reads them with `say(name, label)`. To add a word, add it to the catalog and to `texts()`. `check:i18n` checks `preview.js` for Cyrillic.

### Check the catalogs

`npm run check:i18n` (`scripts/check-i18n.mjs`) checks the admin and bundle catalogs:

- the same keys and parameters in every language;
- plural forms (if one language has them for a key, every language needs them);
- the keys used in `t()`, `I18n::t()`, and `itk_t()`;
- unused keys;
- Cyrillic outside comments.

The `package.json` script runs with `--strict`, which turns an unused key from a warning into an error. CI runs `npm run check:i18n`. `--area=<name>` checks one area. `--warn-cyrillic=<path>` only lists Cyrillic under a path. A key built from pieces is matched by its literal part. If there is none, add an `i18n-dynamic: area.prefix_` comment. To keep Cyrillic in the code on purpose, add an `i18n-ignore` comment on the line or an entry in `scripts/check-i18n.allow.json`.

## Develop without the server shell

`npm run dev` proxies `/admin/*` (or the path in `VITE_ITK_ADMIN_PATH` if the site moved the admin panel), `/bundles/itkcms/*` (except `admin/`), `/build`, and `/uploads` to the site at `ITK_SITE_URL` (`http://localhost:8080` by default). Sign in at the site's `/admin/login` first. The session cookie is shared between the ports of one host.

In this mode the page does not get `window.ITK_ADMIN` from the server, so `api/http.js` uses fallbacks: `/admin` (or `VITE_ITK_ADMIN_PATH`) and the CSRF token from `GET /me`. `MetaController::me()` returns `csrf`, the same token the shell puts in `window.ITK_ADMIN.csrf` (an HMAC of the user and the session). Only a signed-in user gets it. If a write is refused with 403 `{ details: { code: 'csrf' } }`, the client explains the cause (`DEV_CSRF_HINT` in `api/http.js`) instead of showing "Page expired".

## Mock mode

`npm run dev:mock` (the same as `VITE_MOCK=1 npm run dev`; `.env.mock` enables it for `vite --mode mock`) shows the screens without PHP and without a database.

- `main.js` calls `mock/install.js`, which intercepts `fetch` for `/admin/api/*`. `mock/handler.js` answers from the data in `mock/fixtures.js`: a made-up demo site with sections and templates, 8 pages, a News collection, and a Plans options matrix.
- The mock covers, among other routes, pages, collections, requests, notification and bot settings, `robots.txt` and `llms.txt`, and Yandex Webmaster. Unsupported routes get a 404 that says the demo does not have them. A screen whose permission the fixture role lacks shows "No access".
- Writes go to the tab's memory (draft, publish, revert, create, delete, tree moves). A page reload restores the fixtures.
- The page preview is a placeholder, because the site templates normally render it.
- The mock is not part of `npm run build`: the branch in `main.js` is removed when the variable is not set.
- The handler is plain code with tests (`mock/handler.test.js`). A new screen that needs a response is added to `handler.js` and `fixtures.js`.

## Tools

- **Lint.** `npm run lint` runs ESLint 9 with the flat config `eslint.config.js`, `eslint-plugin-vue` (the `essential` set), and `@eslint/js`. It reports errors only, with no formatting rules. There is no Prettier: follow the formatting of the surrounding code.
- **Tests.** `npm test` runs Vitest in the `node` environment by default. Editor component tests use `jsdom` through the `@vitest-environment` directive. Tests cover pure logic and rich text behavior. A test lives next to its file as `*.test.js`. CI also runs `npm audit` and `npm audit --omit=dev`.
- **Build.** `npm run build` (Vite 6) writes the admin panel to `packages/cms/public/admin`. To check a build without touching the package directory, run `npx vite build --outDir /tmp/itk-admin-check --emptyOutDir`.

### Chunks

- Tiptap and ProseMirror are in a separate named chunk `tiptap` (`manualChunks` in `vite.config.js`) and do not load on screens without the editor. `TextareaField` and `HeadlineField` load it through the wrapper `form/RichTextAsync.js`. Do not import `RichText.vue` directly from code that goes into the main bundle.
- Screens that focus such fields (the page editor and the collection record) load the chunk with the route (`withRichText` in `router/index.js`). The field then renders with the editor at once, and the first click on the canvas text lands in it. On other screens the chunk loads with the first rich text field.
- Vue is in its own `vue` chunk. Otherwise Rollup puts the runtime where the Tiptap chunk takes it from, and the editor loads on every screen.
- Check after a change: the built `index.html` has no `modulepreload` for `tiptap`, `index.html` in `.vite/manifest.json` imports only `vue`, and the `tiptap` chunk has no Vue runtime.
- The chunk size warning (`chunkSizeWarningLimit`) is set to 900 kB.
