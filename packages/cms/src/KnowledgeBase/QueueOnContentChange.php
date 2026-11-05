<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordUnpublished;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A page or item version that goes live (publish, republish, each record of a mass publish, an import with --publish) or leaves the site
 * gets its document brought up to date by the worker. One message per event: the batch handler coalesces bursts on the consumer side.
 *
 * Not RecordUpdated: an autosave changes the draft, never what is indexed.
 * Not RecordDeleted: the foreign keys of itk_kb_document drop the document of every language version with the page or item.
 * Not MassAction: every record of it fires its own RecordPublished / RecordUnpublished after the commit.
 * Not an excluded template or collection, nor a language the index does not hold: KnowledgeBaseOptions::indexes() says no before any message is sent.
 *
 * The event comes synchronously in the editor's request after flush, so this only dispatches and never throws.
 *
 * @internal
 */
#[AsEventListener(event: RecordPublished::class)]
#[AsEventListener(event: RecordUnpublished::class)]
final class QueueOnContentChange
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly KnowledgeBaseOptions $options,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordEvent $event): void
    {
        $kind = SourceKind::tryFrom($event->kind);
        if (null === $kind || null === $event->record || !$this->options->indexes($kind, $event->resourceKey, $event->record->locale)) {
            return;
        }
        try {
            $this->bus->dispatch(new SyncKnowledge($event->kind, $event->record->id, $event->record->locale));
        } catch (\Throwable $e) {
            $this->logger->warning('Knowledge base sync of {kind} {id} was not queued: {error}', ['kind' => $event->kind, 'id' => $event->record->id, 'error' => $e->getMessage()]);
        }
    }
}
