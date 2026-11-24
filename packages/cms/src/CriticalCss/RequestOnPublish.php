<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Itk\Cms\Event\RecordPublished;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\Repository\PageTranslationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * A page that goes live (publish, republish, each page of a mass publish) gets its critical CSS made before visitors come.
 * Only RecordPublished: an autosave (RecordUpdated) changes the draft, not what visitors see; unpublish keeps the row (a republish of the same
 * snapshot finds it Ready again); a delete removes it by the foreign key.
 * The event comes synchronously in the editor's request after flush, so this reads one row, dispatches one message and never throws.
 *
 * @internal
 */
#[AsEventListener(event: RecordPublished::class)]
final class RequestOnPublish
{
    public function __construct(
        private readonly CriticalCssService $critical,
        private readonly PageTranslationRepository $translations,
        private readonly LoggerInterface $logger,
        private readonly ?ContentLocales $contentLocales = null,
    ) {
    }

    public function __invoke(RecordPublished $event): void
    {
        if ('page' !== $event->kind || null === $event->record) {
            return;
        }
        try {
            $t = $this->translations->findOneBy(['page' => $event->record->id, 'locale' => $event->record->locale]);
            if (null !== $t && $t->isLive() && ($this->contentLocales?->serves($t->locale, (string) ($t->published['url'] ?? $t->publishedUrl)) ?? true)) {
                $this->critical->request($t);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Critical CSS of page {id} was not requested after publishing: {error}', ['id' => $event->record->id, 'error' => $e->getMessage()]);
        }
    }
}
