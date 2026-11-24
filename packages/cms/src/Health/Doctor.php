<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Itk\Cms\Admin\AdminPath;
use Itk\Cms\Analytics\AnalyticsAsset;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Mcp\ApiTokenAuthenticator;
use Itk\Cms\Mcp\McpGate;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Repository\MenuAreaRepository;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaLint;
use Itk\Cms\Support\Url;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\HttpFoundation\Request;

/**
 * The checks of itk:doctor, one pass over what a deploy can get wrong. A line is { group, name, status: ok|off|warn|fail, message }.
 *   Окружение          PHP extensions, WebP and AVIF, APP_SECRET, the folder of uploads, the database
 *   Сайт               the address for links in letters, migrations, the published home page, the firewalls of the admin and of /mcp, the queue and its worker, the built admin
 *   Состояние сайта    what HealthService::report() says (the screen «Состояние сайта» of the admin)
 *   Схема              SchemaLint (the schema of the site and the Twig templates it asks for)
 * A check that needs the schema (the settings, the report) is asked through a locator and fails on its own line: a schema with mistakes does not stop the other checks.
 *
 * @internal
 */
final class Doctor
{
    private const REQUIRED = ['ctype', 'dom', 'gd', 'json', 'mbstring', 'pdo_pgsql', 'sodium'];
    private const OPTIONAL = ['exif' => 'ops.doctor_optional_exif'];
    /** a message in the queue for longer than this many seconds means that nobody takes it */
    private const STALE = 300;

    /** @param ContainerInterface $services the services that need the schema: HealthService, PublicUrl */
    public function __construct(
        private readonly AppSecretHealthCheck $secret,
        private readonly UploadsHealthCheck $uploads,
        private readonly DatabaseHealthCheck $database,
        private readonly ImageVariants $images,
        private readonly MessengerMonitor $messenger,
        private readonly PageTranslationRepository $translations,
        private readonly Connection $db,
        private readonly Security $security,
        private readonly AdminPath $admin,
        private readonly SchemaLint $lint,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private readonly DependencyFactory $migrations,
        #[AutowireLocator([HealthService::class, PublicUrl::class, SettingsService::class, Schema::class, MenuAreaRepository::class])]
        private readonly ContainerInterface $services,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly ?ContentLocales $contentLocales = null,
        private readonly AnalyticsAsset $analyticsAsset = new AnalyticsAsset(),
    ) {
    }

    /** @return list<array{group: string, name: string, status: string, message: string}> */
    public function run(): array
    {
        $lines = [];
        $add = static function (string $group, string $name, HealthResult $r) use (&$lines): void {
            $lines[] = ['group' => $group, 'name' => $name, 'status' => $r->status, 'message' => $r->message];
        };

        $env = I18n::t('ops.doctor_group_env');
        $add($env, I18n::t('ops.doctor_name_extensions'), $this->extensions());
        $add($env, 'WebP', $this->images->supportsWebp() ? HealthResult::ok(I18n::t('ops.doctor_webp_ok')) : HealthResult::fail(I18n::t('ops.doctor_webp_missing')));
        $add($env, 'AVIF', $this->images->supportsAvif() ? HealthResult::ok(I18n::t('ops.doctor_avif_ok')) : HealthResult::warn(I18n::t('ops.doctor_avif_missing')));
        $done = [];
        foreach ([$this->secret, $this->uploads, $this->database] as $check) {
            $done[$check->name()] = true;
            $add($env, $check->name(), $check->check());
        }

        $site = I18n::t('ops.doctor_group_site');
        if (!$this->multilingual()) {
            $add($site, I18n::t('ops.doctor_name_public_url'), $this->publicUrl());
        }
        $add($site, I18n::t('ops.doctor_name_migrations'), $this->migrations());
        if (!$this->multilingual()) {
            $add($site, I18n::t('ops.doctor_name_home'), $this->home());
        }
        foreach ($this->localeChecks() as [$name, $result]) {
            $done[$name] = true;
            $add($site, $name, $result);
        }
        $add($site, I18n::t('ops.doctor_name_firewall_admin'), $this->firewall($this->admin->base() . '/login', null));
        $add($site, I18n::t('ops.doctor_name_firewall_mcp'), $this->firewall(McpGate::PATH, ApiTokenAuthenticator::class));
        $add($site, I18n::t('ops.doctor_name_queue'), $this->queue());
        $add($site, I18n::t('ops.doctor_name_admin_build'), $this->adminBuild());
        $add($site, I18n::t('ops.doctor_name_analytics_loader'), self::checkAnalyticsLoader($this->analyticsAsset, $this->projectDir . '/public/' . AnalyticsAsset::PATH));

        foreach ($this->report($done) as [$name, $result]) {
            $add(I18n::t('ops.doctor_group_health'), $name, $result);
        }

        $schema = $this->lint->run();
        $add(I18n::t('ops.doctor_group_schema'), I18n::t('ops.doctor_name_schema'), [] === $schema['errors'] ? HealthResult::ok(I18n::t('ops.doctor_schema_ok')) : HealthResult::fail(\implode('; ', $schema['errors'])));
        if ([] !== $schema['warnings']) {
            $add(I18n::t('ops.doctor_group_schema'), I18n::t('ops.doctor_name_templates'), HealthResult::warn(\implode('; ', $schema['warnings'])));
        }

        return $lines;
    }

    private function multilingual(): bool
    {
        return $this->contentLocales?->isMultilingual() ?? false;
    }

    /** Shared by the console and Site Health; legacy sites keep their existing report.
     * @return list<array{0: string, 1: HealthResult}>
     */
    public function localeChecks(): array
    {
        if (!$this->multilingual()) {
            return [];
        }
        $locales = $this->contentLocales;
        $out = [[I18n::t('ops.doctor_name_languages'), HealthResult::ok(I18n::t('ops.doctor_languages', [
            'main' => $locales->main(),
            'list' => \implode(', ', \array_map(static fn (string $code): string => $code . (' ' . ('' === $locales->prefix($code) ? I18n::t('ops.doctor_no_prefix') : $locales->prefix($code))), $locales->codes())),
        ]))]];
        foreach ($locales->codes() as $code) {
            $path = $locales->prefix($code) ?: '/';
            try {
                $live = $this->translations->findLive($code, Url::key($path));
                $result = null !== $live
                    ? HealthResult::ok(I18n::t('ops.doctor_locale_home_ok', ['path' => $path]))
                    : ($code === $locales->main()
                        ? HealthResult::fail(I18n::t('ops.doctor_home_unpublished'))
                        : HealthResult::warn(I18n::t('ops.doctor_locale_home_missing', ['path' => $path])));
            } catch (\Throwable $e) {
                $result = HealthResult::fail(I18n::t('ops.doctor_pages_unreadable', ['error' => MessengerMonitor::scrub($e->getMessage())]));
            }
            $out[] = [I18n::t('ops.doctor_name_locale_home', ['locale' => $code]), $result];
        }
        try {
            $conflicts = [];
            foreach ($locales->codes() as $code) {
                foreach ($this->translations->findAddresses($code) as $row) {
                    foreach ([$row['draftUrl'], $row['publishedUrl']] as $path) {
                        if (null !== $path && null !== $locales->addressProblem($code, $path)) {
                            $conflicts[$path] = true;
                        }
                    }
                }
            }
            $paths = \array_keys($conflicts);
            \sort($paths, \SORT_STRING);
            $out[] = [I18n::t('ops.doctor_name_locale_conflicts'), [] === $conflicts
                ? HealthResult::ok(I18n::t('ops.doctor_locale_conflicts_ok'))
                : HealthResult::warn(I18n::t('ops.doctor_locale_conflicts', ['list' => self::shortList($paths)]))];
        } catch (\Throwable $e) {
            $out[] = [I18n::t('ops.doctor_name_locale_conflicts'), HealthResult::warn(I18n::t('ops.doctor_check_unavailable', ['error' => MessengerMonitor::scrub($e->getMessage())]))];
        }
        $base = $this->publicUrl();
        if (HealthResult::WARN === $base->status && I18n::t('ops.doctor_public_url_missing') === $base->message) {
            $base = HealthResult::warn(I18n::t('ops.doctor_seo_base_missing'));
        }
        $out[] = [I18n::t('ops.doctor_name_seo_base'), $base];
        try {
            $settings = $this->services->get(SettingsService::class);
            $schema = $this->services->get(Schema::class);
            $areas = [];
            foreach ($schema->menuAreas() as $area) {
                $areas[$area->key] = [];
            }
            foreach ($this->services->get(MenuAreaRepository::class)->findAll() as $area) {
                $areas[$area->key] = $area->items;
            }
            $overrides = [];
            foreach (['robots.txt' => 'robotsTxt', 'llms.txt' => 'llmsTxt', 'sitemap.xml' => null] as $file => $key) {
                if (\is_file($this->projectDir . '/public/' . $file) || (null !== $key && '' !== \trim((string) $settings->get($key, null, '')))) {
                    $overrides[] = $file;
                }
            }
            $out[] = [I18n::t('ops.doctor_name_crawler_overrides'), [] === $overrides
                ? HealthResult::ok(I18n::t('ops.doctor_crawler_auto'))
                : HealthResult::warn(I18n::t('ops.doctor_crawler_overrides', ['list' => \implode(', ', $overrides)]))];
            foreach ($locales->codes() as $code) {
                $values = $settings->all($code);
                $empty = [];
                foreach ($schema->settingsFields() as $field) {
                    $value = $values[$field->key()] ?? null;
                    if ($field->isTranslatable() && !$field->isNote() && (null === $value || [] === $value || (\is_string($value) && '' === \trim($value)))) {
                        $empty[] = $field->key();
                    }
                }
                $out[] = [I18n::t('ops.doctor_name_locale_settings', ['locale' => $code]), [] === $empty
                    ? HealthResult::ok(I18n::t('ops.doctor_locale_settings_ok'))
                    : HealthResult::warn(I18n::t('ops.doctor_locale_settings_empty', ['list' => self::shortList($empty)]))];
                $empty = [];
                foreach ($areas as $key => $items) {
                    if ([] === ($items[$code] ?? [])) {
                        $empty[] = $key;
                    }
                }
                $out[] = [I18n::t('ops.doctor_name_locale_menus', ['locale' => $code]), [] === $empty
                    ? HealthResult::ok(I18n::t('ops.doctor_locale_menus_ok'))
                    : HealthResult::warn(I18n::t('ops.doctor_locale_menus_empty', ['list' => self::shortList($empty)]))];
            }
        } catch (\Throwable $e) {
            $out[] = [I18n::t('ops.doctor_name_locale_content'), HealthResult::warn(I18n::t('ops.doctor_check_unavailable', ['error' => MessengerMonitor::scrub($e->getMessage())]))];
        }

        return $out;
    }

    /** @param list<string> $items */
    private static function shortList(array $items): string
    {
        return \implode(', ', \array_slice($items, 0, 10)) . (\count($items) > 10 ? I18n::t('ops.doctor_list_more', ['count' => \count($items) - 10]) : '');
    }

    private function extensions(): HealthResult
    {
        $missing = \array_values(\array_filter(self::REQUIRED, static fn (string $e): bool => !\extension_loaded($e)));
        if ([] !== $missing) {
            return HealthResult::fail(I18n::t('ops.doctor_extensions_missing', ['list' => \implode(', ', $missing)]));
        }
        $loose = [];
        foreach (self::OPTIONAL as $ext => $why) {
            if (!\extension_loaded($ext)) {
                $loose[] = $ext . ' (' . I18n::t($why) . ')';
            }
        }

        return [] === $loose ? HealthResult::ok(I18n::t('ops.doctor_extensions_ok')) : HealthResult::warn(I18n::t('ops.doctor_extensions_optional', ['list' => \implode('; ', $loose)]));
    }

    private function publicUrl(): HealthResult
    {
        try {
            $base = $this->services->get(PublicUrl::class)->base();
        } catch (\UnexpectedValueException $e) {
            return HealthResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return HealthResult::warn(I18n::t('ops.doctor_check_unavailable', ['error' => MessengerMonitor::scrub($e->getMessage())]));
        }

        return null === $base
            ? HealthResult::warn(I18n::t('ops.doctor_public_url_missing'))
            : HealthResult::ok($base);
    }

    /** Which migrations of the site and of the bundle the database has not run. The table of the versions is read by a plain query: a schema filter of the site that hides it from the schema tool must not make every migration «new». */
    private function migrations(): HealthResult
    {
        try {
            $storage = $this->migrations->getConfiguration()->getMetadataStorageConfiguration();
            $table = $storage instanceof TableMetadataStorageConfiguration ? $storage->getTableName() : 'doctrine_migration_versions';
            $column = $storage instanceof TableMetadataStorageConfiguration ? $storage->getVersionColumnName() : 'version';
            try {
                $executed = $this->db->fetchFirstColumn(\sprintf('SELECT %s FROM %s', $this->db->quoteSingleIdentifier($column), $this->db->quoteSingleIdentifier($table)));
            } catch (TableNotFoundException) {
                $executed = []; // not a migration has run
            }
            $available = \array_map(static fn (AvailableMigration $m): string => (string) $m->getVersion(), $this->migrations->getMigrationRepository()->getMigrations()->getItems());
            $new = \count(\array_diff($available, \array_map('strval', $executed)));
        } catch (\Throwable $e) {
            return HealthResult::fail(I18n::t('ops.doctor_migrations_unknown', ['error' => MessengerMonitor::scrub($e->getMessage())]));
        }

        return 0 === $new ? HealthResult::ok(I18n::t('ops.doctor_migrations_ok')) : HealthResult::fail(I18n::t('ops.doctor_migrations_pending', ['count' => $new]));
    }

    private function home(): HealthResult
    {
        try {
            $live = $this->translations->findLive($this->mainLocale, Url::key('/'));
        } catch (\Throwable $e) {
            return HealthResult::fail(I18n::t('ops.doctor_pages_unreadable', ['error' => MessengerMonitor::scrub($e->getMessage())]));
        }

        return null === $live
            ? HealthResult::fail(I18n::t('ops.doctor_home_unpublished'))
            : HealthResult::ok(I18n::t('ops.doctor_home_ok'));
    }

    /**
     * Is the address behind a firewall of the site (security.yaml)? For /mcp the firewall must also check the keys of the assistants.
     *
     * @param class-string|null $authenticator an authenticator the firewall must have; null = any
     */
    private function firewall(string $path, ?string $authenticator): HealthResult
    {
        try {
            $config = $this->security->getFirewallConfig(Request::create($path, 'POST'));
        } catch (\Throwable $e) { // the firewalls build the services of the site: a broken schema or a broken service stops them
            return HealthResult::warn(I18n::t('ops.doctor_check_unavailable', ['error' => MessengerMonitor::scrub($e->getMessage())]));
        }
        $found = null !== $config && $config->isSecurityEnabled() && [] !== $config->getAuthenticators() && (null === $authenticator || \in_array($authenticator, $config->getAuthenticators(), true));
        if ($found) {
            return HealthResult::ok(I18n::t('ops.doctor_firewall_ok', ['path' => $path, 'name' => $config->getName()]));
        }
        if (null === $authenticator) {
            return HealthResult::fail(I18n::t('ops.doctor_firewall_admin_open', ['path' => $path]));
        }

        return HealthResult::warn(I18n::t('ops.doctor_firewall_mcp_open', ['path' => $path, 'authenticator' => (new \ReflectionClass($authenticator))->getShortName()]));
    }

    /** Every queue answers, and nothing waits for a worker for more than a few minutes. */
    private function queue(): HealthResult
    {
        $queues = $this->messenger->queues();
        foreach ($queues as $q) {
            if (isset($q['error'])) {
                return HealthResult::fail(I18n::t('ops.doctor_queue_transport_failed', ['name' => $q['name'], 'error' => $q['error']]));
            }
        }
        $old = $this->oldestWaiting();
        if (null !== $old && $old['age'] > self::STALE) {
            return HealthResult::warn(I18n::t('ops.doctor_queue_stale', ['queue' => $old['queue'], 'minutes' => (int) \floor($old['age'] / 60), 'count' => $old['count']]));
        }
        if ([] === $queues) {
            return HealthResult::ok(I18n::t('ops.doctor_queue_no_counters'));
        }

        return HealthResult::ok(\implode(', ', \array_map(static fn (array $q): string => I18n::t('ops.doctor_queue_counter', ['name' => $q['name'], 'count' => (int) $q['waiting']]), $queues)));
    }

    /**
     * The queue of the database transport (doctrine://) that has waited longest, counted from the time a message was due (a delayed one is not waiting yet).
     * Other transports cannot say how old their messages are.
     *
     * @return array{queue: string, age: int, count: int}|null
     */
    private function oldestWaiting(): ?array
    {
        try {
            if (!$this->db->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return null;
            }
            $rows = $this->db->fetchAllAssociative("SELECT queue_name, COUNT(*) AS n, MIN(available_at) AS oldest FROM messenger_messages WHERE delivered_at IS NULL AND available_at <= (now() AT TIME ZONE 'UTC') AND queue_name <> 'failed' GROUP BY queue_name");
        } catch (\Throwable) {
            return null;
        }
        $best = null;
        foreach ($rows as $r) {
            $age = \time() - (new \DateTimeImmutable((string) $r['oldest'], new \DateTimeZone('UTC')))->getTimestamp();
            if (null === $best || $age > $best['age']) {
                $best = ['queue' => (string) $r['queue_name'], 'age' => $age, 'count' => (int) $r['n']];
            }
        }

        return $best;
    }

    /** The manifest of the built admin and every file it names (the entry, the chunks, the styles). */
    private function adminBuild(): HealthResult
    {
        return self::checkAdminBuild(\dirname(__DIR__, 2) . '/public/admin', $this->projectDir . '/public/bundles/itkcms/admin');
    }

    public static function checkAnalyticsLoader(AnalyticsAsset $bundled, string $installed): HealthResult
    {
        $version = (new AnalyticsAsset(file: $installed))->version();

        return match (true) {
            '0' === $version => HealthResult::warn(I18n::t('ops.doctor_analytics_loader_missing')),
            $version !== $bundled->version() => HealthResult::warn(I18n::t('ops.doctor_analytics_loader_outdated')),
            default => HealthResult::ok(I18n::t('ops.doctor_analytics_loader_ok')),
        };
    }

    /**
     * @param string $dir the build of the admin (public/admin of the bundle)
     * @param string $installed where `assets:install` puts it in the site; its absence is a warning
     */
    public static function checkAdminBuild(string $dir, string $installed): HealthResult
    {
        $manifest = null;
        foreach (['/.vite/manifest.json', '/manifest.json'] as $name) {
            if (\is_file($dir . $name)) {
                $manifest = \json_decode((string) \file_get_contents($dir . $name), true);

                break;
            }
        }
        if (!\is_array($manifest)) {
            return HealthResult::fail(I18n::t('ops.doctor_build_no_manifest'));
        }
        $missing = [];
        foreach ($manifest as $chunk) {
            foreach (\is_array($chunk) ? [(string) ($chunk['file'] ?? ''), ...\array_map('strval', (array) ($chunk['css'] ?? [])), ...\array_map('strval', (array) ($chunk['assets'] ?? []))] : [] as $file) {
                if ('' !== $file && !\is_file($dir . '/' . $file)) {
                    $missing[$file] = true;
                }
            }
        }
        if ([] !== $missing) {
            $names = \array_keys($missing);

            return HealthResult::fail(I18n::t('ops.doctor_build_files_missing', ['count' => \count($names), 'list' => \implode(', ', \array_slice($names, 0, 3)) . (\count($names) > 3 ? ', …' : '')]));
        }

        return \is_dir($installed)
            ? HealthResult::ok(I18n::t('ops.doctor_build_ok', ['count' => \count($manifest)]))
            : HealthResult::warn(I18n::t('ops.doctor_build_not_installed'));
    }

    /**
     * What HealthService::report() says, as lines. The lines the doctor has shown already are left out.
     *
     * @param array<string, true> $done
     *
     * @return list<array{0: string, 1: HealthResult}>
     */
    private function report(array $done): array
    {
        try {
            $report = $this->services->get(HealthService::class)->report(false, false);
        } catch (\Throwable $e) {
            return [[I18n::t('ops.doctor_name_report'), HealthResult::fail(I18n::t('ops.doctor_report_failed', ['error' => MessengerMonitor::scrub($e->getMessage())]))]];
        }
        $out = [];
        foreach ($report['checks'] as $c) {
            if (!isset($done[$c['name']])) {
                $out[] = [$c['name'], HealthResult::of($c['status'], $c['message'])];
            }
        }
        foreach ($report['disk'] as $d) {
            $out[] = [I18n::t('ops.doctor_name_disk', ['label' => $d['label']]), HealthResult::of($d['status'], null === $d['free'] ? I18n::t('ops.doctor_disk_unknown') : I18n::t('ops.doctor_disk_free', ['free' => \sprintf('%.1f', $d['free'] / 1024 ** 3), 'pct' => (int) $d['usedPct']]))];
        }
        if ($report['messenger']['failed']['total'] > 0) {
            $out[] = [I18n::t('ops.doctor_name_failed_jobs'), HealthResult::warn(I18n::t('ops.doctor_failed_jobs', ['count' => $report['messenger']['failed']['total']]))];
        }
        if ($report['requests']['failed'] > 0) {
            $out[] = [I18n::t('ops.doctor_name_failed_requests'), HealthResult::warn(I18n::t('ops.doctor_failed_requests', ['count' => $report['requests']['failed']]))];
        }

        return $out;
    }
}
