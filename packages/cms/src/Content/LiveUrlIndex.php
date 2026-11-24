<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Support\Url;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The published addresses of a language (one query per request): which page links lead to a live page.
 *
 * @internal
 */
final class LiveUrlIndex implements ResetInterface
{
    /** @var array<string, array<string, array{id: string, title: string, url: string}>> */
    private array $cache = [];

    /** @var array<string, array<string, array{id: string, title: string, url: string}>> language => page id => the same rows */
    private array $ids = [];

    public function __construct(
        private readonly PageTranslationRepository $translations,
        private readonly ?ContentLocales $locales = null,
    ) {
    }

    public function has(string $locale, string $url): bool
    {
        return isset($this->index($locale)[Url::key($url)]);
    }

    /** @return array{id: string, title: string, url: string}|null */
    public function find(string $locale, string $url): ?array
    {
        return $this->index($locale)[Url::key($url)] ?? null;
    }

    /**
     * A site path in the spelling its live page was saved with (`/blog` becomes `/blog/` when the page is stored so); `?query` and `#fragment` are kept. A path of no live page is returned as written.
     */
    public function spelled(string $locale, string $ref): string
    {
        $cut = \strcspn($ref, '?#');
        $path = \substr($ref, 0, $cut);
        $found = '' === $path ? null : $this->find($locale, $path);
        if (null === $found || '' === $found['url']) {
            return $ref;
        }

        return Url::spelling($found['url']) . \substr($ref, $cut);
    }

    /** @return array{id: string, title: string, url: string}|null the live page with this id */
    public function byId(string $locale, string $id): ?array
    {
        $this->index($locale);

        return $this->ids[$locale][$id] ?? null;
    }

    /** The after events run once the write commits, including the unpublish of a removed translation. */
    #[AsEventListener(event: RecordPublished::class)]
    #[AsEventListener(event: RecordUnpublished::class)]
    #[AsEventListener(event: RecordDeleted::class)]
    public function onPageChange(RecordEvent $event): void
    {
        if ('page' === $event->kind && $this->locales?->isMultilingual()) {
            $this->reset();
        }
    }

    /** kernel.reset: a worker asks the database again for the next message. */
    public function reset(): void
    {
        $this->cache = [];
        $this->ids = [];
    }

    /** @return array<string, array{id: string, title: string, url: string}> */
    private function index(string $locale): array
    {
        if (!isset($this->cache[$locale])) {
            $map = [];
            foreach ($this->translations->findLiveAddresses($locale) as $row) {
                $map[$row['key']] = ['id' => $row['id'], 'title' => $row['title'], 'url' => $row['url']];
                $this->ids[$locale][$row['id']] = $map[$row['key']];
            }
            $this->cache[$locale] = $map;
        }

        return $this->cache[$locale];
    }
}
