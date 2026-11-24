<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Admin\AdminPath;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Support\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * GET /robots.txt and GET /llms.txt: the text the site answers with, for any site.
 *  - a file in `public/` always wins (the web server serves it before PHP runs; a request that still reaches the route gets the same file);
 *  - else the text the editor saved in «Настройки → SEO» (settings robotsTxt / llmsTxt);
 *  - else the standard text, built here:
 *      robots.txt  keeps robots out of the admin, the form POST and the MCP endpoint, and points to the sitemap when the site address is set;
 *      llms.txt    (llmstxt.org) the name of the site, the description of the home page and a link with the description for each live page of IndexablePages.
 *    The standard llms.txt is kept in the cache for an hour and dropped when a page or an SEO rule changes (SitemapInvalidator, SeoRuleService); it also notices by itself
 *    when the site name or the site address changed.
 * A saved text equal to the standard one is not kept: '' stands for «standard», so the standard text follows the site (the address, the pages).
 *
 * @internal
 */
final class CrawlerFiles
{
    public const ROBOTS = 'robots';
    public const LLMS = 'llms';

    private const SETTINGS = [self::ROBOTS => 'robotsTxt', self::LLMS => 'llmsTxt'];
    private const FILES = [self::ROBOTS => 'robots.txt', self::LLMS => 'llms.txt'];
    private const KEY = 'itk_llms';
    private const TTL = 3600;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SitemapBuilder $sitemap,
        private readonly IndexablePages $pages,
        private readonly AdminPath $admin,
        private readonly CacheInterface $cache,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly ?ContentLocales $locales = null,
        private readonly ?CrawlerCacheGeneration $generation = null,
    ) {
    }

    /** The file of the site in `public/` that stands for the kind, or null. */
    public function staticFile(string $kind): ?string
    {
        $path = $this->projectDir . '/public/' . self::FILES[$kind];

        return \is_file($path) ? $path : null;
    }

    /** The text the editor saved ('' = none). */
    public function custom(string $kind): string
    {
        $v = $this->settings->get(self::SETTINGS[$kind], null, '');

        return \is_string($v) ? \trim($v) : '';
    }

    /** The text of the kind as the settings say it: the saved one, else the standard one. No line break at the end. */
    public function text(string $kind): string
    {
        $custom = $this->custom($kind);

        return '' !== $custom ? $custom : $this->defaultText($kind);
    }

    /** The robots.txt the site really serves: the file in `public/` if there is one, else the saved text, else the standard one. */
    public function servedRobots(): string
    {
        $file = $this->staticFile(self::ROBOTS);
        $body = null !== $file ? \file_get_contents($file) : false;

        return \is_string($body) ? $body : $this->text(self::ROBOTS);
    }

    /** What the route sends when there is no file: the text and one line break. */
    public function body(string $kind): string
    {
        return $this->text($kind) . "\n";
    }

    /** The standard text as it is now. No line break at the end. */
    public function defaultText(string $kind): string
    {
        return self::ROBOTS === $kind ? $this->robotsDefault() : $this->llmsDefault();
    }

    /**
     * What the SEO screen shows for each kind.
     *
     * @return array<string, array{text: string, default: string, custom: bool, file: bool}>
     */
    public function overview(): array
    {
        $out = [];
        foreach ([self::ROBOTS, self::LLMS] as $kind) {
            $default = $this->defaultText($kind);
            $custom = $this->custom($kind);
            $out[$kind] = ['text' => '' !== $custom ? $custom : $default, 'default' => $default, 'custom' => '' !== $custom, 'file' => null !== $this->staticFile($kind)];
        }

        return $out;
    }

    /**
     * Saves the texts the keys name (robots, llms); a missing key stays as it is, null or '' or the standard text brings the standard text back.
     * Nothing is saved when any of the texts is refused.
     *
     * @param array<string, mixed> $texts
     */
    public function save(array $texts): void
    {
        $patch = [];
        $errors = [];
        foreach (self::SETTINGS as $kind => $key) {
            if (!\array_key_exists($kind, $texts)) {
                continue;
            }
            $error = SettingsService::crawlerTextError($texts[$kind]);
            if ('' !== $error) {
                $errors[$kind] = $error;
                continue;
            }
            $text = SettingsService::crawlerText($texts[$kind]);
            $patch[$key] = $text === $this->defaultText($kind) ? '' : $text;
        }
        if ([] !== $errors) {
            throw ContentException::invalid(\reset($errors), $errors);
        }
        if ([] !== $patch) {
            $this->settings->update($patch);
        }
    }

    /** Drops the kept standard llms.txt (the next request builds it again). */
    public function forget(): void
    {
        $this->generations()->advance('llms');
    }

    public function isMultilingual(): bool
    {
        return $this->locales?->isMultilingual() ?? false;
    }

    private function robotsDefault(): string
    {
        $lines = ['User-agent: *', 'Disallow: ' . $this->admin->base() . '/', 'Disallow: /request/', 'Disallow: /mcp'];
        $base = $this->sitemap->base();
        if (null !== $base) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        }

        return \implode("\n", $lines);
    }

    private function llmsDefault(): string
    {
        $base = $this->sitemap->base();
        $name = $this->siteName($base);
        $sig = \md5(($base ?? '') . "\0" . $name . ($this->isMultilingual() ? "\0" . $this->pages->catalogSignature() : ''));
        $generation = $this->generations()->current('llms');

        return $this->cache->get(self::KEY . '_' . $generation . '_' . $sig, function (ItemInterface $item) use ($base, $name): string {
            $item->expiresAfter(self::TTL);

            return $this->buildLlms($base, $name);
        });
    }

    private function generations(): CrawlerCacheGeneration
    {
        return $this->generation ?? CrawlerCacheGeneration::forCache($this->cache);
    }

    /** The name of the site (main language); else its address without the scheme; else «Site». */
    private function siteName(?string $base): string
    {
        $name = \trim((string) $this->settings->get('siteName', null, ''));
        if ('' === $name && null !== $base) {
            $name = (string) \preg_replace('~^https?://~i', '', $base);
        }

        return '' !== $name ? $name : 'Site';
    }

    private function buildLlms(?string $base, string $name): string
    {
        $rows = $this->pages->rows();
        \usort($rows, static fn (array $a, array $b): int => $a['url'] <=> $b['url']);
        $home = '';
        $links = [];
        foreach ($rows as $r) {
            if ('/' === Url::key($r['url'])) {
                $home = $r['description'];
            }
            $title = '' !== $r['title'] ? $r['title'] : $r['url'];
            $href = \str_replace(['(', ')'], ['%28', '%29'], ($base ?? '') . Url::encodePath($this->isMultilingual() ? Url::spelling($r['url']) : $r['url']));
            $links[$r['locale']][] = '- [' . self::label($title) . '](' . $href . ')' . ('' !== $r['description'] ? ': ' . self::line($r['description']) : '');
        }
        $out = ['# ' . self::line($name)];
        if ('' !== $home) {
            $out[] = '';
            $out[] = '> ' . self::line($home);
        }
        if ([] !== $links) {
            $out[] = '';
            if ($this->isMultilingual()) {
                foreach ($this->locales->codes() as $code) {
                    if (!isset($links[$code])) {
                        continue;
                    }
                    $out[] = '## ' . self::line($this->locales->name($code));
                    $out[] = '';
                    \array_push($out, ...$links[$code]);
                    $out[] = '';
                }
                \array_pop($out);
            } else {
                $out[] = '## Pages';
                $out[] = '';
                foreach ($links as $group) {
                    \array_push($out, ...$group);
                }
            }
        }

        return \implode("\n", $out);
    }

    /** One line: every run of white space (line breaks too) becomes one space. */
    private static function line(string $text): string
    {
        return \trim((string) \preg_replace('~\s+~u', ' ', $text));
    }

    /** The text of a link: one line, with \ [ ] escaped so that the title cannot end the link early. */
    private static function label(string $text): string
    {
        return \preg_replace('~([\\\\\[\]])~', '\\\\$1', self::line($text)) ?? '';
    }
}
