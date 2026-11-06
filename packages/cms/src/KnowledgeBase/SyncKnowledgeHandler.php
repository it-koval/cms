<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Model\Outcome;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

/**
 * SyncKnowledge in batches: the worker hands over up to BATCH messages (or fewer when the queue goes idle), so a mass publish of 200 pages is a few
 * Indexer::sync() runs with one embedder request each, not 200. Duplicates in a batch (publish then unpublish) are one ref: sync reads the state now.
 *
 *   Written | Unchanged | Removed | Superseded   ack
 *   Failed (this source only)                    nack with UnrecoverableMessageHandlingException: a bug in the data, a retry would fail the same way;
 *                                                the message lands in the failure transport and on the health line
 *   EmbeddingFailed transient                    nack every job with the EmbeddingFailed itself: the retry_strategy of the transport retries the batch and, after
 *                                                max_retries, moves it to the failure transport (a RecoverableMessageHandlingException would be retried forever)
 *   EmbeddingFailed permanent                    nack every job unrecoverable (wrong key, no HTTP client): the failure transport keeps them for
 *                                                messenger:failed:retry after the fix
 *
 * In a sync transport there is no Acknowledger and the trait runs process() at once with the one job.
 *
 * @internal
 */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class SyncKnowledgeHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    private const BATCH = 32;

    public function __construct(private readonly Indexer $indexer)
    {
    }

    public function __invoke(SyncKnowledge $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /** @param list<array{0: SyncKnowledge, 1: Acknowledger}> $jobs */
    private function process(array $jobs): void
    {
        $refs = [];
        foreach ($jobs as [$message]) {
            $refs[$message->ref()->key()] = $message->ref();
        }
        try {
            $report = $this->indexer->sync(\array_values($refs));
        } catch (EmbeddingFailed $e) {
            $failure = $e->transient ? $e : new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
            foreach ($jobs as [, $ack]) {
                $ack->nack($failure);
            }

            return;
        }
        foreach ($jobs as [$message, $ack]) {
            $key = $message->ref()->key();
            if (Outcome::Failed === ($report->outcomes[$key] ?? null)) {
                $ack->nack(new UnrecoverableMessageHandlingException($key . ': ' . ($report->failures[$key] ?? 'failed')));
            } else {
                $ack->ack();
            }
        }
    }

    private function getBatchSize(): int
    {
        return self::BATCH;
    }
}
