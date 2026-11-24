<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

use Itk\Cms\CriticalCss\Model\CriticalStyles;

/**
 * What the Twig functions need to know about the page being rendered: the language, the current address and, in preview mode,
 * the published snapshot to mark changes against, and what the templates asked of the cache of pages. Set by PageRenderer around one render, reset afterwards.
 *
 * @internal
 */
final class RenderState
{
    public bool $preview = false;
    /** The generator's own render: stylesheet links print without ?v=, so the page hashes the same until its content changes. */
    public bool $source = false;
    public ?string $locale = null;
    public string $path = '/';
    /** @var array<string, mixed>|null snapshot on the live site (preview only) */
    public ?array $published = null;
    public string $template = 'default';
    /** @var array<string, string> collection record id => collection key, discovered by Twig reads in this render */
    public array $collectionOwners = [];
    /** @var list<array<string, mixed>> exact site/settings() maps handed to Twig in this render */
    public array $settingsSubjects = [];
    /** What itk_cache() asked of the cache of pages in this render: seconds the entry may live at most, false = do not keep, null = nothing asked. */
    public int|false|null $cache = null;
    /** The critical CSS itk_stylesheets() inlines in this render. */
    public ?CriticalStyles $critical = null;

    /** The one rule for several calls of itk_cache() (the layout and a section both ask): false wins over any number, otherwise the smallest; a number below one is false. */
    public static function mergeCache(int|false|null $current, int|false $asked): int|false
    {
        if (false === $current || false === $asked || $asked < 1) {
            return false;
        }

        return null === $current ? $asked : \min($current, $asked);
    }

    public function reset(): void
    {
        $this->preview = false;
        $this->source = false;
        $this->locale = null;
        $this->path = '/';
        $this->published = null;
        $this->template = 'default';
        $this->collectionOwners = [];
        $this->settingsSubjects = [];
        $this->cache = null;
        $this->critical = null;
    }
}
