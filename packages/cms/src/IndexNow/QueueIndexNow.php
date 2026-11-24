<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow;

use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\Repository\PageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Announce affected full live paths after successful page mutations. PageService captures the old published paths and SEO before a
 * move, unpublish or deletion; the after events run after commit (including mass operations and translation removal).
 * Disabled languages, drafts, noindex versions and collection items are excluded. Repeated paths within an operation are queued once.
 * The editor's request only dispatches messages and never fails because IndexNow could not be queued.
 *
 * @internal
 */
#[AsEventListener(event: RecordUnpublished::class)]
#[AsEventListener(event: RecordDeleted::class)]
#[AsEventListener(event: RecordPublished::class)]
final class QueueIndexNow
{
    private readonly ContentLocales $locales;

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly IndexNowSite $site,
        private readonly IndexablePages $indexable,
        private readonly PageRepository $pages,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'itk_cms.main_locale')]
        string $mainLocale,
        ?ContentLocales $locales = null,
    ) {
        $this->locales = $locales ?? new ContentLocales($mainLocale);
    }

    public function __invoke(RecordEvent $event): void
    {
        if ('page' !== $event->kind || null === $event->record || (!$event instanceof RecordPublished && !$event instanceof RecordUnpublished && !$event instanceof RecordDeleted)) {
            return;
        }
        try {
            if (IndexNowState::Ready !== $this->site->state()) {
                return;
            }
            $versions = $event->data['liveVersions'] ?? null;
            if (null === $versions) {
                if (!$event instanceof RecordPublished) {
                    return;
                }
                // Preserve the public RecordPublished event contract for publishers outside PageService.
                $t = $this->pages->find($event->record->id)?->translation($event->record->locale);
                $versions = null !== $t && $this->indexable->admits($t)
                    ? [['locale' => $t->locale, 'url' => (string) ($t->published['url'] ?? $t->publishedUrl), 'seo' => (array) ($t->published['seo'] ?? [])]]
                    : [];
            }
            $paths = [];
            foreach ($versions as $version) {
                $locale = (string) $version['locale'];
                $path = (string) $version['url'];
                if ($this->locales->has($locale) && \str_starts_with($path, '/') && !$this->indexable->noindex((array) $version['seo'], $path, $locale)) {
                    $paths[$path] = true;
                }
            }
            foreach (\array_keys($paths) as $path) {
                $this->bus->dispatch(new PingIndexNow($path));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow of page {id} was not queued: {error}', ['id' => $event->record->id, 'error' => $e->getMessage()]);
        }
    }
}
