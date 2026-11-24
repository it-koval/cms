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

See the [ITK CMS public API](packages/cms/API.md) for the supported types, methods and integration points covered by the versioning policy above.

## Version notes

This section lists the manual steps a release needs, newest first.

### 0.3.0

#### AI suggestions

Remove `->feature('ai')` from the site's schema builder chain, usually in `config/itk/schema.php`. The editor's AI suggestions panel and its configuration flag were removed.

#### Twig functions

Update the following Twig function calls in site templates and bundle overrides.

| Before | After |
| --- | --- |
| `menu` | `itk_menu` |
| `settings` | `itk_settings` |
| `listing` | `itk_listing` |
| `media_url` | `itk_media_url` |
| `media_srcset` | `itk_media_srcset` |
| `media_width` | `itk_media_width` |
| `media_ratio` | `itk_media_ratio` |
| `page_url` | `itk_page_url` |
| `render_sections` | `itk_render_sections` |

Run this command from the site root to update `templates/` in place. Review the diff, including any site-defined functions or imported macros with the old names. Check inline Twig in PHP separately.

```bash
find templates -type f -name '*.twig' -exec perl -0777 -pi -e '
  our ($source, $i);
  sub quoted {
    my ($quote) = @_;
    my $out = $quote;
    ++$i;
    while ($i < length $source) {
      my $char = substr($source, $i, 1);
      if ($char eq "\\") {
        $out .= substr($source, $i, 2);
        $i += 2;
      } elsif ($char eq $quote) {
        ++$i;
        return $out . $quote;
      } elsif ($quote eq "\"" && substr($source, $i, 2) eq "#{") {
        $i += 2;
        $out .= "#{" . expression("}");
      } else {
        $out .= $char;
        ++$i;
      }
    }
    return $out;
  }
  sub expression {
    my ($end) = @_;
    my $start = $i;
    my ($out, $closed) = scan_expression($end, 0);
    return $out if $closed;
    $i = $start;
    ($out) = scan_expression($end, 1);
    return $out;
  }
  sub scan_expression {
    my ($end, $inline_end) = @_;
    my ($out, $previous, @closing) = ("", "");
    while ($i < length $source) {
      if (!@closing && substr($source, $i, length $end) eq $end) {
        $i += length $end;
        return ($out . $end, 1);
      }
      return ($out, 0) if !@closing && substr($source, $i, 2) =~ /^\{[#%{]/;
      my $char = substr($source, $i, 1);
      if ($char eq "\"" || ord($char) == 39) {
        $out .= quoted($char);
        $previous = "string";
        next;
      }
      if ($char eq "#") {
        my $start = $i++;
        ++$i while $i < length($source)
          && substr($source, $i, 1) !~ /[\r\n]/
          && (!$inline_end || substr($source, $i, length $end) ne $end);
        $out .= substr($source, $start, $i - $start);
        next;
      }
      pos($source) = $i;
      if ($source =~ /\G\s+/gc) {
        $out .= $&;
      } elsif ($source =~ /\G[A-Za-z_\x80-\xff][\w\x80-\xff]*/gc) {
        my $name = $&;
        my $after = pos($source);
        my $direct = $previous !~ /^(?:\.|\||is|is not|macro|apply|filter)$/;
        $out .= ($direct
          && $name =~ /^(?:menu|settings|listing|media_url|media_srcset|media_width|media_ratio|page_url|render_sections)$/
          && substr($source, $after) =~ /^(?:\s|\#[^\r\n]*[\r\n])*\(/
          ? "itk_" : "") . $name;
        $previous = $previous eq "is" && $name eq "not" ? "is not" : $name;
      } elsif ($source =~ /\G(?:\.\.\.|\.\.)/gc) {
        $out .= $&;
        $previous = $&;
      } else {
        ++$i;
        $out .= $char;
        push @closing, $char eq "(" ? ")" : $char eq "[" ? "]" : "}" if $char =~ /[([{]/;
        pop @closing if @closing && $char eq $closing[-1];
        $previous = $char;
        next;
      }
      $i = pos($source);
    }
    return ($out, 0);
  }
  $source = $_;
  $i = 0;
  my $out = "";
  while ($i < length $source) {
    pos($source) = $i;
    last unless $source =~ /\{[#%{]/g;
    my ($start, $open) = ($-[0], $&);
    $out .= substr($source, $i, $start - $i);
    $i = pos($source);
    pos($source) = $start;
    if ($open eq "{#") {
      my $end = index($source, "#}", $i);
      $i = $end < 0 ? length($source) : $end + 2;
      $out .= substr($source, $start, $i - $start);
    } elsif ($source =~ /\G\{%[-~]?\s*verbatim\s*[-~]?%\}.*?\{%[-~]?\s*endverbatim\s*[-~]?%\}/gcs) {
      $out .= $&;
      $i = pos($source);
    } else {
      $out .= $open . expression($open eq "{{" ? "}}" : "%}");
    }
  }
  $_ = $out . substr($source, $i);
' {} +
```

Run `bin/console itk:doctor` or open **Site status** and look for **Legacy Twig functions**. The check scans `templates/`, shows up to ten `file:line` locations and counts any remaining matches. Run `bin/console lint:twig templates` to check syntax. Symfony's `render_*` wildcard can resolve an old `render_sections()` call during linting, so use the legacy call check as well.

For a quick text search, review the matches from this command. They can include comments and strings.

```bash
grep -rnE --include='*.twig' '(^|[^[:alnum:]_])(menu|settings|listing|media_url|media_srcset|media_width|media_ratio|page_url|render_sections)[[:space:]]*\(' templates/
```

#### Other changes

- The **Photos** field now adds `{src, alt}` objects in the admin panel, including when `alt` is empty. Existing URL strings stay unchanged, so one field can contain both strings and objects. Update site templates before editors add new photos; render each value with a fallback for the old format:

  ```twig
  {% for p in photos %}
      <img src="{{ p.src ?? p }}" alt="{{ p.alt ?? '' }}">
  {% endfor %}
  ```

- `GET /admin/api/requests` and `GET /admin/api/media` replace their flat arrays with `{ items, total, page, perPage, nextCursor, previousCursor }`. The default is page 1 with 50 rows, up to 100 rows per page. Pass the returned cursor in `cursor` with the same filters to visit an adjacent page when rows may have changed. MCP tools `requests_list` and `media_search` also accept cursors and still accept `offset` and `limit`.
- In `GET /admin/api/dashboard`, `newRequests` contains the latest eight new requests. Use `newRequestCount` for the total and the paginated requests endpoint with `status=new` for the complete list.

The analytics inline initialization is replaced by one versioned external `defer` loader with `data-*` attributes. Publish the new bundle assets with `bin/console assets:install` and allow the actual asset origin and enabled provider sources in the site's CSP. The [analytics guide](packages/cms/README.md#analytics-and-verification-codes) lists `script-src`, `connect-src`, `img-src` and conditional Webvisor `frame-src` requirements.

Even in `auto`, `ym`, `dataLayer` and `gtag` may be undefined while HTML is being parsed or when an earlier deferred site script runs. Move early calls into `DOMContentLoaded` handlers in `auto`, or wait for the required counter through `window.itkAnalytics?.started` and `itk-analytics:started`. The auto event can fire during the loader's deferred execution before later site scripts. Check whether the selected key (`metrika` or `ga`) has already started before listening, and filter later events by `detail.counters`. The guide includes an external JavaScript example. You can also define queue stubs in an external script that executes before both the loader and the early calls. The loader preserves existing `ym`, `dataLayer` and `gtag` queues.

For nonce-based CSP with `strict-dynamic`, the site must generate a fresh nonce per response, set the trusted Symfony request attribute `csp_nonce` before rendering and use the same nonce in its CSP header and site script tags. The CMS takes the nonce for the analytics loader from that server-set attribute. Requests with a nonce bypass the CMS page HTML cache and conditional ETag handling. Configure site-owned and upstream response caches accordingly. `connect-src`, `img-src` and applicable `frame-src` still need the provider sources. The guide shows the Twig integration, including the explicit `itk_stylesheets({nonce: ...})` argument.

`itk_cms.analytics.start` defaults to `auto`, which starts configured counters and preserves Metrika's `<noscript>` pixel. To let the site's consent banner control startup, set `analytics.start: manual` under `itk_cms` in the site's configuration. There is no admin control for the mode. Before switching, remove direct counter scripts, pixels, tag-manager triggers and analytics `preconnect` or `dns-prefetch` links from the site. In `manual`, the bundle outputs no pixel and makes no provider requests or preliminary connections before the API call. Verification meta tags are output in both modes, including without counters.

After the bundle loader runs, call `window.itkAnalytics.start()` for all configured counters or `window.itkAnalytics.start(['ga'])` for GA only. The allowed keys are `metrika` and `ga`. New starts dispatch `itk-analytics:started` on `document`, bubbling to `window`, with the newly started keys in `detail.counters`. A retry without a new counter emits no event. The guide includes an external consent script that waits for the loader. Include it with `defer`, or after `</head>` and the banner markup, so both the loader tag and the button exist in the DOM. The URL uses the site's base path and works in a subdirectory. The site stores consent and, after revocation, reloads without calling `start()`. The CMS does not stop already running counters.

- Add `->forAuthors()` to custom article templates that Authors should edit. The built-in `blog_article` already sets it.
- Set `itk_cms.public_url` to the full origin for password and invitation links. The upgrade expires outstanding links. Remove legacy password jobs and rendered letters from live and failed queues, then request fresh links.
- Set STARTTLS or SSL/TLS for saved SMTP credentials. Existing passwords on unencrypted SMTP stay saved while delivery is disabled until the settings are corrected. For password authentication in `MAILER_DSN`, use `smtps://` or `smtp://` with `require_tls=1`. Site status and `itk:doctor` warn when TLS is optional.
- Run the bundle migrations before using saved SMTP settings: SMTP now uses the shared internal key `itk:mail`, leaving the site's `mail` field independent. Migration `20261124110100` moves only the known SMTP object and restores unrelated values moved to `itk:mail` by an earlier namespace migration when `mail` is absent. If both keys exist, it preserves both; review conflicting values before using saved SMTP settings. Localized site values are unchanged.
- Public forms default to a 256 KiB body limit and a 1,000-character limit on fields lacking `max()`. Set explicit field limits and coordinate `itk_cms.requests.max_body` with PHP and the reverse proxy. The form client must display field errors and handle HTTP 413.
- A request resend API client reads `outcomes` for the result of each selected channel. HTTP 202 indicates a selected channel has a pending job. `queuedChannels` shows pending jobs when the request is reopened. The default async transport retries request delivery at 60, 120 and 240 seconds. Site transport settings take precedence.
- Calls to the internal `MediaService::usage()` and to `present()` with usage enabled now require an explicit `Viewer`. Add `PublicFieldInterface` to custom fields that can be indexed and implement `isPublic()`.
- Run the new queue-intent migration `20261124120000` before restarting workers. New request notifications and their queue intents commit together. When a caller owns the transaction, publication waits for that commit. Keep a Messenger worker running. Its recovery hook checks for pending intents at most once every five seconds, in batches of 50. Run `php bin/console itk:requests:recover` to process up to 500 due intents manually. Repeat for a larger backlog. If the broker receiver fails before the worker loop starts, its recovery hook cannot run. Use the command from the site's existing scheduler for recovery independent of that worker. The bundle does not install a schedule. A publication failure leaves the intent for another attempt after 60 seconds. Recovery reuses the operation ID because a transport can accept an envelope before dispatch throws. This prevents a duplicate envelope from starting the same completed delivery again. Provider acceptance can still be uncertain.
- A request resend API client reads `outcomes` for the result of each selected channel. HTTP 202 indicates a selected channel has a pending job. `queuedChannels` shows pending jobs when the request is reopened. The default async transport retries request delivery at 60, 120 and 240 seconds. Site transport settings take precedence. Standard `messenger:failed:retry` reopens only the current failed operation. Completed and superseded operations stay closed.
- Pass an explicit `Viewer` to `MediaService::usage()` and to `present()` with usage enabled. Add `PublicFieldInterface` to custom fields that can be indexed and implement `isPublic()`.
- For automatic administrator setup in the Docker kit, mount the `itk_admin_password` secret in `php` or use the interactive installer. Retire the old `ADMIN_PASSWORD` environment variable. Copy `docker/chrome-seccomp.json` and attach it to `php` and `worker` for Critical CSS. Copy the updated nginx configuration to enforce the form limits before PHP parses the body.
- Use a password of 10 to 200 characters when the installer creates an administrator. The same limits apply to `--password-file`.
- Admin collection actions require sign-in and permission. Blocking a user ends access on their next request. Rich-text links and image URLs reject control characters. MCP `request_get` returns up to 6,000 characters per form value.
- For an existing knowledge index, run `itk:knowledge-base:sync --now` before reopening search. This refreshes documents whose titles were built from fields that lack public visibility.
- Run the bundle migrations before starting workers. They move internal settings to the `itk:` namespace, normalize the page creation column to `created_at`, and keep the oldest notification per deduplication key. Read marks on duplicate notifications move to the kept row.
- Settings move only when the stored JSON has the expected internal type. Delivery routes accept both JSON objects and arrays, preserving numeric form keys such as `"0"`. Existing `itk:` values take precedence. Admin integrations can read and write them through `/admin/api/settings?scope=notifications` using the existing field names.
- On rollback, an internal setting keeps its `itk:` key if a site field occupies its old key. To restore the old configuration, first move that site field and its saved value to a free key. Then restore the internal value under the old key.
- Rolling back the page timestamp migration keeps `created_at`. The original 0.2.2 bundle schema uses that name.
- Rename collection fields that use reserved metadata keys. Find the list in `Collection::RESERVED_KEYS`. If site code reads a singleton before its first save, it receives a draft with initial values. The first explicit save creates the row and its history.
- If a collection used `locales` or `position` as a business field, rename it in the schema and in stored draft and published values. Table generators accept an explicit `--map` for source columns that use a reserved key.
- Run `itk:schema:lint` to find missing `h1()` fields and invalid field keys. Use `firstScreen()` to mark the first screen with any group name. Use a separate business field such as `stage` for kanban.
- Content writers enforce field choices, limits and date formats consistently. Review import data and custom content writers. Site-defined fields can add validation through `ValueValidatingFieldInterface`.
- `regex()` now also applies to nonempty values written through admin, PHP and imports. Fix stored values that fail the pattern before publishing.
- Public forms reject nonempty values outside the available choices. An empty optional field is accepted.
- Public forms reject invalid date and time formats.
- A form value that exceeds its configured text limit returns a validation error.
- Date fields without an explicit initial value now start empty. Before publishing an existing collection draft, replace date placeholders such as `New item` with real dates. Use `YYYY-MM-DD` for dates and `YYYY-MM-DDTHH:mm` for local date and time.
- Page addresses must be local paths of at most 255 characters. Query strings, fragments, backslashes and control characters are invalid. Spaces, Unicode and double slashes inside the path are still accepted. Public links encode spaces and Unicode.
- Put Twig overrides under `templates/bundles/ItkCmsBundle/`. If an override extends the original template, use `@!ItkCms/…`.
- Review `framework.default_locale` for console commands and Messenger. Keep `itk_cms.admin_locale` for CMS screens. A message sent to a specific user uses that user's locale.
- Bundle files use the Symfony Asset base path and CDN URLs with their own filenames. The site's version strategy and JSON manifest apply to its own assets. Rebuild local admin assets if you compile them yourself. A supplied CSP nonce makes the full stylesheet load through a regular link.
- The `domain` setting accepts an HTTP(S) origin or host without a path, query or fragment. Set a valid `itk_cms.public_url` or `domain` for generated absolute SEO URLs. Canonical and `hreflang` links, the SEO address check, sitemap, `robots.txt` Sitemap line, `llms.txt`, JSON-LD and IndexNow use the normalized `public_url` when valid and fall back to the configured `domain`. Trusted request hosts and debug mode do not provide an SEO fallback. Without a configured origin, the sitemap returns 404, the Sitemap line, generated canonical/hreflang and JSON-LD are omitted, IndexNow has no address, and `llms.txt` keeps relative links. Relative canonical rules stay relative without an origin. Explicit absolute rules retain their value. Single-language domain-only sitemaps now normalize IDNs to ASCII, host case and default ports. Remove request-derived canonical fallbacks from site templates to use the same origin as `itk_seo()`.
- For redirects, the path-only rule wins. Query-specific rules compare normalized parameters and retain their case. Requests use `YYYY-MM-DD` for custom date filters.
- MCP token creation rejects an unknown scope.
- Telegram and MAX store accepted message parts per recipient for the current operation. A safe retry of that operation skips those parts. A crash or network error with an unknown provider outcome stops automatic retries and reports that the destination must be checked. Inspect the destination chat before an explicit new resend, which can resend parts already accepted by the provider. A lease expiring does not let another worker replace a delivery that still holds its channel lock.
- Migration `20261124110400` adds per-request/per-recipient SMTP checkpoints. Accepted request emails skip duplicate envelopes. An uncertain result remains blocked even on `messenger:failed:retry`. After checking the SMTP server, run `php bin/console itk:requests:mail:resolve <request> <recipient> <sent|rejected>`. Choose `sent` when the server accepted the email, so a subsequent failed-queue retry acknowledges it without sending again. Choose `rejected` only after confirming rejection, then run `messenger:failed:retry` to send it. Resolution only changes unresolved deliveries and cannot override an active sender. This confirms SMTP acceptance. Inbox delivery is a separate step. The checkpoint migration is irreversible because dropping it could resend accepted mail.
- For request mail, `failover(...)` and `roundrobin(...)` can switch servers after a confirmed SMTP rejection. If the server might have accepted the email, the attempt stops and follows the same manual resolution procedure.
- During queued delivery and resend, `RequestDelivery` listeners run after the outcome is committed. To change the request, reload it by ID in the listener's entity manager and save it explicitly. Listener exceptions are logged. The pending event is retained for replay by the Messenger worker or `itk:requests:recover`, without repeating the provider send. Custom listeners must be idempotent because a crash after a listener succeeds can replay it.
- Delivery and synchronous retry of an already committed request record their outcome on an independent committed connection when the caller owns a transaction or disables autocommit. A new request and its queue intents remain in the caller transaction. Provider delivery starts only after commit.
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
