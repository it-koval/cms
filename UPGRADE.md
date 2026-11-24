English | [Русский](UPGRADE.ru.md)

# Upgrading ITK CMS

## Upgrade a site

Do these steps in order, on a copy of the site first:

1. Read [CHANGELOG.md](CHANGELOG.md) for every version between yours and the target, and the version notes at the end of this file.
2. Put the site in maintenance and block form intake in the environment currently serving it. For 0.2.2 → 0.3.0, keep the 0.2.2 workers running until the queue drains, including delayed retries. Use `bin/console messenger:stats` to check the queue and confirm **0** with a check that includes delayed and in-flight messages. For Doctrine, count all rows in `messenger_messages` (or the configured table), grouped by `queue_name`. Resolve any failed messages before proceeding. Then stop the workers and every other database/upload writer before backing up the database and uploads. If you plan to switch from your existing setup to the 0.3.0 production kit, follow [First adoption of the production kit](#first-adoption-of-the-production-kit).
3. Set the package constraint in the site's `composer.json` to the target minor version. For 0.2.x → 0.3.0, replace `"itk/cms": "~0.2.0"` with `"itk/cms": "~0.3.0"`. In the development kit, run `docker compose exec php composer update itk/cms` and verify the version with `docker compose exec php composer show itk/cms`. For production, update the site's `composer.lock` in the release checkout. If you use the production kit, build both the application and nginx images from that release. The nginx image contains the admin assets. Verify the installed version in the new application image and follow the update sequence in [DEPLOY](docs/DEPLOY.md#update).
4. Run the migrations: `bin/console doctrine:migrations:migrate`. The bundle migrations (`Itk\Cms\Migrations`) run together with the site migrations, ordered by the date in their class names.
5. In a development checkout, publish the built admin panel with `bin/console assets:install` and clear the cache with `bin/console cache:clear`. Symfony Flex publishes the assets during `composer install` and `composer update`. In the production kit, use the assets packaged in the new nginx image.
6. Start the queue worker service with the new release. `bin/console messenger:stop-workers` asks running workers to exit; it does not start the workers stopped before backup.
7. Check the site: run `bin/console itk:doctor` and `bin/console itk:schema:lint`, then open the admin panel and a few pages. Lift maintenance and unblock form intake only after these checks pass.

If the version notes ask you to change a site configuration file (for example, `security.yaml`), do it before step 7.

## Versions

ITK CMS follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html):

- A patch release (0.1.1) contains only fixes.
- A minor release (0.2.0) adds features. From 1.0.0 on, it does not break the public API.
- A major release (2.0.0) may break the public API.

Before 1.0.0 a minor release may break the public API. Each such change is listed in [CHANGELOG.md](CHANGELOG.md) and in the version notes below. A patch release never breaks it. To get fixes without surprises, pin the minor version in the site: `"itk/cms": "~0.3.0"`.

Database changes come as bundle migrations. The bundle does not change the site's own tables.

## Public API

See the [ITK CMS public API](packages/cms/API.md) for the supported types, methods and integration points covered by the versioning policy above.

## Version notes

This section lists the manual steps a release needs, newest first.

### 0.3.0

#### First adoption of the production kit

Version 0.3.0 introduces the optional `compose.prod.yaml` kit. You can keep the site's existing deployment setup. Rehearse this transfer and its restore on a copy first. Import the database and uploads manually.

1. Put the old site in maintenance and block form intake. Keep the 0.2.2 workers running until the queue drains, including delayed retries. Use `bin/console messenger:stats` to check the queue and confirm **0** with a check that includes delayed and in-flight messages. For Doctrine, count all rows in `messenger_messages` (or the configured table), grouped by `queue_name`. Resolve any failed messages before proceeding. Then stop the workers and every other database/upload writer before backup. Save the **original 0.2.2 deployment**: code or images, Composer lock file, Compose/service definitions, proxy configuration, runtime settings and the actual database/upload locations. Back up its database and actual upload directory or volume to a protected location outside the build context. Do not substitute the new kit's empty volumes for these sources. Archive the contents of the directory the old site serves as `public/uploads`, with that directory as the archive root (for example `tar -C <old-site>/public/uploads -cf before-deploy-uploads.tar .`), so that `media/` is a top-level entry. With a custom `upload_dir`, extract into the matching path under `/app/public/uploads`.
2. Check the database backup using `pg_restore --list` for a custom-format dump. For plain SQL, rehearse a restore with `psql -v ON_ERROR_STOP=1`. List the upload archive and compare its regular-file count with the source recursively. Restore both backups on a staging copy and verify representative records and media. A readable archive alone does not prove a usable restore.
3. Prepare the new kit using [DEPLOY → Prepare](docs/DEPLOY.md#prepare), build both images and start only `database`. If moving the database, restore into the kit's **empty target database** before migrations. Its name is `DEPLOY_DB_NAME` (default `app`), exposed as `POSTGRES_DB` inside `database`. The database name must match `DATABASE_URL`. For example, choose the command matching the backup format:

   ```sh
   # Custom-format backup; do not use --create (the old database name may differ).
   docker compose -f compose.prod.yaml exec -T database sh -c 'pg_restore --no-owner --no-privileges --exit-on-error -U postgres -d "$POSTGRES_DB"' < "$BACKUP_DIR/before-deploy.dump"
   # Alternative: plain SQL from pg_dump --no-owner --no-privileges, without --create.
   docker compose -f compose.prod.yaml exec -T database sh -c 'psql -v ON_ERROR_STOP=1 -U postgres -d "$POSTGRES_DB"' < "$BACKUP_DIR/before-deploy.sql"
   ```

   Check the exit status and query representative site records in that target database. If keeping an external database, keep its connection and backup/restore procedure instead of restoring into the kit's unused database.
4. Before starting nginx, restore uploads into the shared `uploads` volume, or mount the existing directory at `/app/public/uploads` for PHP and the worker and read-only for nginx. The supplied production build sets PHP/worker `www-data` to **UID/GID `10033:10033`**. nginx runs as `10101:10101`. After copying the archive into the new volume, set its owner and permissions:

   ```sh
   docker compose -f compose.prod.yaml run --rm -T --no-deps --user 0:0 --entrypoint sh php -ec 'tar -C /app/public/uploads -xf - && chown -R 10033:10033 /app/public/uploads && chmod -R u+rwX,go+rX /app/public/uploads' < "$BACKUP_DIR/before-deploy-uploads.tar"
   ```

   For a host bind mount, apply equivalent ownership or ACLs to that directory. Check that both PHP and the worker can create and remove a test file and that nginx can traverse directories and read existing files. Compare the restored regular-file count with the source. Adjust the numeric IDs if the site changes the kit's build arguments/users.
5. Run the migrations and refresh defaults as in [DEPLOY → Update](docs/DEPLOY.md#update), retaining their output. Start the application, nginx and worker under maintenance. If the migration reports SEO path collisions, find the listed IDs in **Settings → SEO → Address rules**. For each collision, move the needed SEO settings to the rule you keep and remove the duplicate, or give it a distinct address. Save the retained rules **before opening traffic**. The checks in [DEPLOY → Verify](docs/DEPLOY.md#verify) cover `/healthz` and a queued notification. Also open existing media over HTTP and upload a new image in the admin panel to verify its generated copy. Rehearse [rollback to the original 0.2.2 deployment](#rollback-to-022) before switching the proxy.

#### Reserved settings groups

Run `itk:schema:lint` with 0.3.0 on a copy of the site before migrating. Rename site groups that use `menus`, `redirects`, `seo`, `notifications`, `mail`, `bots`, `theme`, `languages`, `users`, `mcp` or `seo-files` in `SchemaBuilder::settings()` or `SettingsResource::key()`. Update saved role permissions and field visibility references to `set:<new-key>`. Keep the field keys to retain their saved values.

#### Rollback to 0.2.2

The 0.3.0 database migrations are incompatible with the 0.2.2 code, and some are irreversible. A code-only rollback cannot recover the site.

1. Keep maintenance enabled and stop all 0.3.0 writers. Use the 0.3.0 deployment configuration to locate and back up the current database and uploads. Keep this backup separate from the pre-upgrade copy.
2. Restore the pre-upgrade database **and** uploads into the locations used by the saved original 0.2.2 deployment. Restore its matching code or images, lock file, service and proxy configuration, and runtime settings. After a first kit adoption, use the original service definitions for this rollback.
3. Before starting any 0.2.2 worker, validate the restored queue, including delayed retries and failed messages (`messenger_messages` for Doctrine, or the site's external transport). Match each envelope to the delivery outcomes in the retained 0.3.0 backup using the request ID, channel, any job ID and, for mail, the recipient. For a resend without a job ID, verify delivery of that attempt in the provider records. Delete individual envelopes only after confirming that 0.3.0 delivered them. Keep uncertain envelopes out of consumption until their delivery is reconciled, using provider evidence where necessary. Restoring old queue data can otherwise repeat deliveries made after the backup.
4. Start the original services, verify its database contents, `/healthz`, existing media and a new media upload, then return traffic through its proxy. Do not point 0.2.2 at the migrated database. Requests saved by 0.3.0 after traffic opened are not in the pre-upgrade copy: export them from the retained 0.3.0 backup (`itk_request` table, by creation time) and re-enter or forward them manually.

For later updates of sites already running the kit, see [DEPLOY → Roll back](docs/DEPLOY.md#roll-back). Keep this first-adoption restore procedure with the old deployment's backup.

#### AI suggestions

Remove `->feature('ai')` from the site's schema builder chain, usually in `config/itk/schema.php`. The editor's AI suggestions panel and its configuration flag were removed.

#### Collection row import

Previously, `itk:make:collection --import` created collection records from source rows and `--publish` published them immediately. Version 0.3.0 removes both options and ignores the JSON field `rows`. Class generation from a table or a JSON description still works. Move old site records with your own script or SQL when migrating the database dump.

Previously imported records remain in the database and can be edited like any other collection record. The core no longer reads `meta.sourceId` and `meta.source` for these records.

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
- Run the bundle migrations before using saved SMTP settings. SMTP uses `itk:mail` independently of the site's `mail` field. Migration `20261124110100` also copies unrelated values from `itk:mail` back to `mail` when an earlier migration moved them and `mail` is absent. Review conflicting values before using saved SMTP settings.
- Public forms default to a 256 KiB body limit and a 1,000-character limit on fields lacking `max()`. For JSON, submit one flat object of up to 1,000 scalar or null values. Nested objects and arrays return HTTP 422. Arrays in declared fields of conventional form posts also return HTTP 422. Set explicit field limits and coordinate `itk_cms.requests.max_body` with the reverse proxy and PHP. The form client must display field errors and handle HTTP 413. On multilingual sites, a stale or unsupported `?locale=` saves a valid submission in the main language and logs a warning.
- Run the new queue-intent migration `20261124120000` before restarting workers. New request notifications and their queue intents commit together. When a caller owns the transaction, publication waits for that commit. Keep a Messenger worker running. Its recovery hook checks for pending intents at most once every five seconds, in batches of 50. Run `php bin/console itk:requests:recover` to process up to 500 due intents manually. Repeat for a larger backlog. If the broker receiver fails before the worker loop starts, its recovery hook cannot run. Use the command from the site's existing scheduler for recovery independent of that worker. The bundle does not install a schedule. A publication failure leaves the intent for another attempt after 60 seconds. Recovery reuses the operation ID because a transport can accept an envelope before dispatch throws. This prevents a duplicate envelope from starting the same completed delivery again. Provider acceptance can still be uncertain.
- A request resend API client reads `outcomes` for the result of each selected channel. HTTP 202 indicates a selected channel has a pending job. `queuedChannels` shows pending jobs when the request is reopened. The default async transport retries request delivery at 60, 120 and 240 seconds. Site transport settings take precedence. Standard `messenger:failed:retry` reopens only the current failed operation. Completed and superseded operations stay closed.
- PHP integrations that dispatched `SendRequestNotification` with `resend: true` must inject `Itk\Cms\Request\RequestQueue` and call `enqueue($requestId, $channel, resend: true)` to start a new delivery. A 0.2.2 resend envelope without a job ID can still run for an undelivered channel with no newer operation. If the channel is already marked sent and has no recorded job, the envelope produces a warning and the channel waits for operator review without sending anything. Check the outcome of that resend across all its destinations. For this channel-wide case use `itk:requests:resolve <id> <telegram|max> '*' sent` if all destinations accepted it. Use `itk:requests:resolve <id> <telegram|max> '*' rejected` only if none accepted it. After `rejected`, use `itk:requests:resend <id> --channel=<telegram|max>` to start a new delivery.
- Pass an explicit `Viewer` to `MediaService::usage()` and to `present()` with usage enabled. Add `PublicFieldInterface` to custom fields that can be indexed and implement `isPublic()`.
- For automatic administrator setup in the Docker kit, mount the `itk_admin_password` secret in `php` or use the interactive installer. Retire the old `ADMIN_PASSWORD` environment variable. Copy `docker/chrome-seccomp.json` and the updated Compose files. Both dev and production attach the profile to `php` and `worker` regardless of the `CRITICAL_CSS` build argument. Copy the updated nginx configuration to enforce the `256k` body limit only on `/request/{form}` before PHP parses the body and to resolve PHP through Docker DNS. Other routes, including media uploads, retain the `256m` nginx limit; coordinate PHP upload limits separately.
- Use a password of 10 to 200 characters when the installer creates an administrator. The same limits apply to `--password-file`.
- Admin collection actions require sign-in and permission. Blocking a user ends access on their next request. Rich-text links and image URLs reject control characters. MCP `request_get` returns up to 6,000 characters per form value.
- For an existing knowledge index, run `itk:knowledge-base:sync --now` before reopening search. This refreshes documents whose titles were built from fields that lack public visibility.
- Run the bundle migrations before starting workers. They move internal settings to the `itk:` namespace, normalize the page creation column to `created_at`, and keep the oldest notification per deduplication key. Read marks on duplicate notifications move to the kept row.
- The settings migration copies the values that 0.2.2 would read into `itk:`. It keeps all original rows, including objects, and gives existing `itk:` values precedence. Flags keep their effective boolean meaning and delivery routes follow the old array conversion. The notification settings API returns routes as JSON objects, preserving numeric form keys such as `"0"`. Admin integrations read and write these settings, including `forms`, through `/admin/api/settings?scope=notifications`.
- Settings history uses the current group and field permissions for reading and restoring. New revisions identify site settings separately from internal notifications. Legacy notification revisions without that provenance are read-only and available only to a full administrator. A removed settings group's history is read-only for a full administrator. Review a saved revision after renaming groups rather than assigning its former `set:<group>` permission to an unrelated group.
- Rename collection fields that use reserved metadata keys. Find the list in `Collection::RESERVED_KEYS`. If site code reads a singleton before its first save, it receives a draft with initial values. The first explicit save creates the row and its history.
- If a collection used `locales` or `position` as a business field, rename it in the schema and in stored draft and published values. Table generators accept an explicit `--map` for source columns that use a reserved key.
- Run `itk:schema:lint` to find missing `h1()` fields and invalid field keys. Use `firstScreen()` to mark the first screen with any group name. Use a separate business field such as `stage` for kanban.
- Field choices, limits, date formats and `regex()` are checked on writes through CMS services. This includes the admin panel, MCP and the save methods of the PHP services. `SettingsService::set()` is a low-level setter for installation code and writes without these checks and without history. `regex()` checks nonempty values. Fix stored values that fail the pattern before publishing. Site-defined fields can add validation through `ValueValidatingFieldInterface`.
- Direct database writes by your own script bypass these checks and the edit history. Verify those data yourself.
- Public forms reject nonempty values outside the available choices. An empty optional field is accepted.
- Public forms reject invalid date and time formats.
- A form value that exceeds its configured text limit returns a validation error.
- Date fields without an explicit initial value now start empty. Before publishing an existing collection draft, replace date placeholders such as `New item` with real dates. Use `YYYY-MM-DD` for dates and `YYYY-MM-DDTHH:mm` for local date and time.
- Page addresses must be local paths of at most 255 characters. Query strings, fragments, backslashes and control characters are invalid. Spaces, Unicode and double slashes inside the path are still accepted. Public links encode spaces and Unicode.
- Put Twig overrides under `templates/bundles/ItkCmsBundle/`. If an override extends the original template, use `@!ItkCms/…`.
- Review `framework.default_locale` for console commands and Messenger. Keep `itk_cms.admin_locale` for CMS screens. A message sent to a specific user uses that user's locale.
- Bundle files use the Symfony Asset base path and CDN URLs with their own filenames. The site's version strategy and JSON manifest apply to its own assets. Rebuild local admin assets if you compile them yourself. A supplied CSP nonce makes the full stylesheet load through a regular link.
- The `domain` setting accepts an HTTP(S) origin or host without a path, query or fragment. Set a valid `itk_cms.public_url` or `domain` for generated absolute SEO URLs. Canonical and `hreflang` links, the SEO address check, sitemap, `robots.txt` Sitemap line, `llms.txt`, JSON-LD and IndexNow use the normalized `public_url` when valid and fall back to the configured `domain`. Trusted request hosts and debug mode do not provide an SEO fallback. Without a configured origin, the sitemap returns 404, the Sitemap line, generated canonical/hreflang and JSON-LD are omitted, IndexNow has no address, and `llms.txt` keeps relative links. Relative canonical rules stay relative without an origin. Explicit absolute rules retain their value. Single-language domain-only sitemaps now normalize IDNs to ASCII, host case and default ports. Remove request-derived canonical fallbacks from site templates to use the same origin as `itk_seo()`.
- Migration `20261124120100` normalizes percent-encoded SEO rule paths, preserving escaped separators such as `%2F`, `%3F` and `%23`. If multiple rules would match the same address, match type and locale, the migration keeps them as stored and prints their IDs. Review those rules in **Settings → SEO → Address rules**. Merge or change their addresses, then save the rules you keep before reopening traffic.
- For redirects, the path-only rule wins. Query-specific rules compare normalized parameters and retain their case. Requests use `YYYY-MM-DD` for custom date filters.
- MCP token creation rejects an unknown scope.
- Telegram and MAX keep the rendered text and accepted parts for the current operation. A temporarily disabled channel retains that progress. A retry skips accepted parts and allows confirmed refusals to retry while an unknown part waits for review. A manual resend after a fully refused attempt renders fresh text. An interrupted `sending` part stays blocked until an operator checks the destination. After that check, record the outcome with `itk:requests:resolve <request> <telegram|max> <recipient> <sent|rejected>`, then run `itk:requests:resend <request> --channel=<telegram|max>` for the outstanding parts. This continuation retains the frozen text and skips accepted parts. For a negative chat ID, place `--` before it. A lease expiring does not let another worker replace a delivery that still holds its channel lock.
- Telegram acceptance requires `ok: true` and `result.message_id` in the original successful provider response. A later decoding or listener exception does not undo it. A custom HTTP client decorator that cannot be safely isolated is refused before sending with a definite failure. Fix the decorator before retrying.
- Migration `20261124110400` adds per-request/per-recipient SMTP checkpoints. Accepted request emails skip duplicate envelopes. An uncertain result remains blocked even on `messenger:failed:retry`. After checking the SMTP server, run `php bin/console itk:requests:mail:resolve <request> <recipient> <sent|rejected>`. Choose `sent` when the server accepted the email. A subsequent replay acknowledges the envelope without sending again. Choose `rejected` only after confirming rejection and retry that recipient with `itk:requests:resend <request> --channel=mail --recipient=<address>`. If a matching failed envelope is found, the command also prints a retry command with its transport name and envelope ID. These commands work without a configured failure transport. A verified `sent` also records acceptance for an old aggregate warning, even when the recipient has no checkpoint. The old aggregate warning does not close automatically: 0.2.2 did not persist its complete historical recipient list, and current or retained legacy routes cannot prove that list. After checking every original recipient independently, close an old aggregate warning explicitly with `itk:requests:mail:resolve <request> --legacy-checked`. The flag closes only the aggregate warning. A recipient’s `sent` outcome confirms SMTP acceptance. Inbox delivery is a separate step. The checkpoint migration is irreversible because dropping it could resend accepted mail.
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
