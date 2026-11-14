<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Itk\Cms\CriticalCss\Model\SourceCss;
use Itk\Cms\CriticalCss\Model\Viewport;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The site's stylesheets and the generator config (itk_cms.critical_css): the ONE list that both the layout (itk_stylesheets()) and the generator read,
 * so what is inlined is always cut from what is linked.
 *
 * On the request side a stylesheet is known by its stat (path, mtime, size), never by its content: no file is read to serve a page. The same stat
 * gives each href its ?v=, so a new stylesheet changes the URL of the full file at the moment it makes the stored critical CSS stale, and a browser
 * never pairs fresh critical CSS with a cached old full file (or the reverse). The worker hashes the content itself (CriticalCss entity, `inputs`).
 *
 * @internal
 */
final class Stylesheets
{
    /** Bump when SourceCss, node/critical-css.mjs or the way CriticalStyles builds the stored blocks changes what comes out of the same input: every page becomes stale once. */
    public const GENERATOR_VERSION = 3;

    /**
     * @param list<string> $hrefs public paths, validated by the config node (start with /, no query, no ..)
     * @param list<array{width: int, height: int, mobile: bool}> $viewports sorted by width, distinct widths (the config node and loadExtension())
     * @param list<string> $keepSelectors
     * @param list<string> $removeSelectors
     */
    public function __construct(
        #[Autowire(param: 'itk_cms.critical_css.enabled')]
        public readonly bool $enabled,
        #[Autowire(param: 'itk_cms.critical_css.stylesheets')]
        private readonly array $hrefs,
        #[Autowire(param: 'itk_cms.critical_css.viewports')]
        private readonly array $viewports,
        #[Autowire(param: 'itk_cms.critical_css.keep_selectors')]
        private readonly array $keepSelectors,
        #[Autowire(param: 'itk_cms.critical_css.remove_selectors')]
        private readonly array $removeSelectors,
        #[Autowire(param: 'itk_cms.critical_css.max_kb')]
        private readonly int $maxKb,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /** @return list<array{href: string, versioned: string}> what itk_stylesheets() links, in cascade order; versioned only while the feature is on (off = today's plain link) */
    public function links(): array
    {
        $out = [];
        foreach ($this->hrefs as $href) {
            $stat = $this->enabled ? $this->stat($href) : '';
            $out[] = ['href' => $href, 'versioned' => '' === $stat ? $href : $href . '?v=' . \substr(\sha1($stat), 0, 8)];
        }

        return $out;
    }

    /** @return list<string> the configured stylesheets that are not under public/ */
    public function missing(): array
    {
        return \array_values(\array_filter($this->hrefs, fn (string $href): bool => !\is_file($this->publicDir() . $href)));
    }

    /** @return list<Viewport> sorted by width */
    public function viewports(): array
    {
        return \array_map(static fn (array $v): Viewport => new Viewport($v['width'], $v['height'], $v['mobile']), $this->viewports);
    }

    /** @return list<string> */
    public function keepSelectors(): array
    {
        return $this->keepSelectors;
    }

    /** @return list<string> */
    public function removeSelectors(): array
    {
        return $this->removeSelectors;
    }

    /** Critical CSS of one viewport, or the union of all of them, above this is not inlined. */
    public function maxBytes(): int
    {
        return $this->maxKb * 1024;
    }

    /**
     * The stats of every stylesheet and the generator config (viewports, selectors, GENERATOR_VERSION), for the Basis and for PageValidators::fingerprint();
     * '' when the feature is off.
     */
    public function stamp(): string
    {
        if (!$this->enabled) {
            return '';
        }
        $stats = [];
        foreach ($this->hrefs as $href) {
            $stats[$href] = $this->stat($href);
        }

        return \sha1($this->generatorConfig() . "\x1f" . (string) \json_encode($stats));
    }

    /** Everything that changes crittr's output for the same page and CSS: viewports, selectors, GENERATOR_VERSION. Part of the stamp and of the worker's `inputs`. */
    public function generatorConfig(): string
    {
        return (string) \json_encode([self::GENERATOR_VERSION, $this->viewports, $this->keepSelectors, $this->removeSelectors]);
    }

    /**
     * The source CSS handed to crittr (SourceCss), read from public/.
     *
     * @throws \RuntimeException naming a missing file
     */
    public function sourceCss(): string
    {
        $public = $this->publicDir();

        return SourceCss::prepare($this->hrefs, static function (string $path) use ($public): ?string {
            $file = $public . $path;
            if (\str_contains($path, '..') || !\is_file($file)) {
                return null;
            }
            $css = \file_get_contents($file);

            return false === $css ? null : $css;
        });
    }

    public function publicDir(): string
    {
        return $this->projectDir . '/public';
    }

    /** 'path|mtime|size' of a stylesheet, '' when the file is missing */
    private function stat(string $href): string
    {
        $file = $this->publicDir() . $href;
        $stat = \is_file($file) ? @\stat($file) : false;

        return false === $stat ? '' : $href . '|' . $stat['mtime'] . '|' . $stat['size'];
    }
}
