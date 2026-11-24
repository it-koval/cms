<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow;

use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Event\RecordDeleting;
use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordPublishing;
use Itk\Cms\Event\RecordUnpublishing;
use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\Repository\PageRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A page of the sitemap (IndexablePages::admits(): main language, live, not noindex) whose address is about to change or leave the site, or has just appeared,
 * is announced to the search engines at its address as the site has it right then:
 *   RecordPublishing, RecordUnpublishing, RecordDeleting   the address that is live now (after a publish with a new address it is a redirect)
 *   RecordPublished                                        the address that is live now (also the only event of a republish)
 * Stateless on purpose: an operation that fails after its «before» event only announces an address that is still live, which is harmless.
 * Items of collections are not in the sitemap and are not announced.
 *
 * The events come synchronously in the editor's request, so this only dispatches and never throws.
 *
 * @internal
 */
#[AsEventListener(event: RecordPublishing::class)]
#[AsEventListener(event: RecordUnpublishing::class)]
#[AsEventListener(event: RecordDeleting::class)]
#[AsEventListener(event: RecordPublished::class)]
final class QueueIndexNow
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly IndexNowSite $site,
        private readonly IndexablePages $indexable,
        private readonly PageRepository $pages,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    public function __invoke(RecordEvent $event): void
    {
        if ('page' !== $event->kind || null === $event->record || $event->record->locale !== $this->mainLocale || IndexNowState::Ready !== $this->site->state()) {
            return;
        }
        try {
            $t = $this->pages->find($event->record->id)?->translation($this->mainLocale);
            if (null !== $t && $this->indexable->admits($t)) {
                $this->bus->dispatch(new PingIndexNow((string) ($t->published['url'] ?? $t->publishedUrl)));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('IndexNow of page {id} was not queued: {error}', ['id' => $event->record->id, 'error' => $e->getMessage()]);
        }
    }
}
