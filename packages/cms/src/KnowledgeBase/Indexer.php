<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Types\Types;
use Itk\Cms\KnowledgeBase\Embedding\Embedder;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Embedding\Purpose;
use Itk\Cms\KnowledgeBase\Embedding\Vector;
use Itk\Cms\KnowledgeBase\Model\Basis;
use Itk\Cms\KnowledgeBase\Model\DocumentPlan;
use Itk\Cms\KnowledgeBase\Model\Freshness;
use Itk\Cms\KnowledgeBase\Model\Outcome;
use Itk\Cms\KnowledgeBase\Model\Planned;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\KnowledgeBase\Model\SyncReport;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The knowledge base, worker side: brings documents in line with the live site. The one writer of itk_kb_document and itk_kb_chunk
 * (KnowledgeSearch and the health check only read; deletes of sources reach the tables by the foreign keys, not through here).
 *
 * Idempotent: everything is derived from the live content and the rows as they are now. A crash leaves the documents written so far Current and
 * the rest Stale; running again finishes them. Two workers on one document serialize on an advisory lock and the later one re-plans under it.
 *
 * @internal
 */
final class Indexer
{
    public function __construct(
        private readonly Connection $db,
        private readonly SourceReader $reader,
        private readonly Extractor $extractor,
        private readonly Embedder $embedder,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Every source the index should hold or holds now: SourceReader::all() and every stored document (the Orphans are only there).
     *
     * @return list<SourceRef>
     */
    public function everything(): array
    {
        $refs = [];
        foreach ($this->reader->all() as $ref) {
            $refs[$ref->key()] = $ref;
        }
        foreach ($this->db->fetchAllAssociative('SELECT kind, source_id, locale FROM itk_kb_document') as $row) {
            $ref = new SourceRef(SourceKind::from((string) $row['kind']), (string) $row['source_id'], (string) $row['locale']);
            $refs[$ref->key()] = $ref;
        }

        return \array_values($refs);
    }

    /**
     * The state of each source without the API and without writing: for the command (--dry-run, and choosing what to queue).
     *
     * @param list<SourceRef> $refs
     *
     * @return list<Planned> only refs with something to say (a ref with neither a row nor a live source is left out)
     */
    public function plan(array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            $plan = $this->planOne($ref);
            $state = Freshness::of($this->storedBasis($ref), $plan);
            if (null === $state) {
                continue;
            }
            $inputs = null === $plan || !$state->needsWork() ? [] : $this->inputsOf([$plan]);
            $new = \array_diff_key($inputs, [] === $inputs ? [] : $this->knownVectors($this->embedder->space()->id(), \array_keys($inputs)));
            $out[] = new Planned($ref, $state, $plan, \count($new), (int) \ceil(\array_sum(\array_map('mb_strlen', $new)) / 3));
        }

        return $out;
    }

    /**
     * Brings these documents up to date: the message handler (a batch) and the command --now.
     *   1. plan each ref; Current -> Unchanged, Orphan -> delete -> Removed
     *   2. one embedder call for every distinct input of every Missing / Stale plan whose (space, input_hash) is not already in itk_kb_chunk
     *   3. each document in its own transaction: rewrite() -> Written | Superseded
     * A failure of one source (extraction, its write) is that source's Outcome::Failed; the rest go on.
     *
     * @param list<SourceRef> $refs
     * @param bool $retryTransient workers retry the whole message; CLI reports individual source failures and continues
     *
     * @throws EmbeddingFailed the embedder failed: nothing of this batch that needed it was written; the caller retries the whole batch
     * @throws ConnectionException a shared batch read failed, or workers must retry a source
     * @throws RetryableException a shared batch read failed, or workers must retry a source
     */
    public function sync(array $refs, bool $retryTransient = true): SyncReport
    {
        $outcomes = [];
        $failures = [];
        /** @var array<string, DocumentPlan> $plans */
        $plans = [];
        foreach ($refs as $ref) {
            $key = $ref->key();
            try {
                $plan = $this->planOne($ref);
                $state = Freshness::of($this->storedBasis($ref), $plan);
                if (null === $state || Freshness::Current === $state) {
                    $outcomes[$key] = Outcome::Unchanged;
                } elseif (Freshness::Orphan === $state) {
                    $outcomes[$key] = $this->remove($ref);
                } elseif (null !== $plan) {
                    $plans[$key] = $plan;
                }
            } catch (\Throwable $e) {
                if ($retryTransient && ($e instanceof RetryableException || $e instanceof ConnectionException)) {
                    throw $e;
                }
                if ($e instanceof ConnectionException) {
                    $this->db->close(); // the next CLI source reconnects; workers retry the message instead
                }
                $outcomes[$key] = Outcome::Failed;
                $failures[$key] = $e->getMessage();
                $this->logger->warning('Knowledge base: {key} was not planned: {error}', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        $inputs = $this->inputsOf(\array_values($plans));
        $vectors = [] === $inputs ? [] : $this->knownVectors($this->embedder->space()->id(), \array_keys($inputs));
        $new = \array_diff_key($inputs, $vectors);
        $tokens = null;
        if ([] !== $new) {
            $embeddings = $this->embedder->embed(\array_values($new), Purpose::Document);
            foreach (\array_keys($new) as $i => $hash) {
                $vectors[$hash] = $embeddings->vectors[$i];
            }
            $tokens = $embeddings->tokens;
        }
        $total = 0;
        foreach ($plans as $key => $plan) {
            $total += \count($plan->chunks);
            try {
                $outcomes[$key] = $this->rewrite($plan, $vectors);
            } catch (\Throwable $e) {
                if ($retryTransient && ($e instanceof RetryableException || $e instanceof ConnectionException)) {
                    throw $e;
                }
                if ($e instanceof ConnectionException) {
                    $this->db->close(); // the next CLI source reconnects; workers retry the message instead
                }
                $outcomes[$key] = Outcome::Failed;
                $failures[$key] = $e->getMessage();
                $this->logger->warning('Knowledge base: {key} was not written: {error}', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }

        return new SyncReport($outcomes, $failures, \count($new), $total - \count($new), $tokens);
    }

    /** The live plan of one source, or null when it does not belong in the index. Pure apart from the read. */
    private function planOne(SourceRef $ref): ?DocumentPlan
    {
        $live = $this->reader->read($ref);
        if (null === $live) {
            return null;
        }
        $chunks = Chunker::chunk($live->source, $this->extractor->extract($live));
        $space = $this->embedder->space();

        return new DocumentPlan($live, $space, $chunks, Basis::of($space, $live->source, $chunks));
    }

    private function storedBasis(SourceRef $ref): ?Basis
    {
        $basis = $this->db->fetchOne('SELECT basis FROM itk_kb_document WHERE id = ?', [$ref->key()]);

        return \is_string($basis) ? Basis::fromStored($basis) : null;
    }

    /**
     * The distinct inputs of these plans: the same text in two documents (or twice in one) is embedded once per batch.
     *
     * @param list<DocumentPlan> $plans
     *
     * @return array<string, string> input hash => input
     */
    private function inputsOf(array $plans): array
    {
        $inputs = [];
        foreach ($plans as $plan) {
            foreach ($plan->chunks as $chunk) {
                $inputs[$chunk->inputHash] = $chunk->input;
            }
        }

        return $inputs;
    }

    /**
     * Vectors already in the table for these inputs, from any document of the same space: what makes a republish re-embed only the text that changed.
     *
     * @param list<string> $hashes
     *
     * @return array<string, Vector> input hash => vector
     */
    private function knownVectors(string $spaceId, array $hashes): array
    {
        $out = [];
        $rows = $this->db->fetchAllKeyValue(
            'SELECT DISTINCT ON (input_hash) input_hash, CAST(embedding AS text) FROM itk_kb_chunk WHERE space = ? AND input_hash IN (?)',
            [$spaceId, $hashes],
            [Types::STRING, ArrayParameterType::STRING],
        );
        foreach ($rows as $hash => $text) {
            $out[(string) $hash] = Vector::fromSql((string) $text);
        }

        return $out;
    }

    /**
     * Rewrites one document, or writes nothing when the source moved on since $plan was made: under the document's advisory lock (one writer per
     * document at a time, released at COMMIT) the source is planned again; another basis, or none, means the change that moved it has queued its own
     * message (Superseded). A foreign key violation (the page version or item was deleted meanwhile) is Superseded too.
     *
     * @param array<string, Vector> $vectors input hash => vector, every input of $plan present
     */
    private function rewrite(DocumentPlan $plan, array $vectors): Outcome
    {
        $key = $plan->live->source->ref()->key();
        try {
            return $this->db->transactional(function () use ($plan, $vectors, $key): Outcome {
                $this->lock($key);
                $fresh = $this->planOne($plan->live->source->ref());
                if (null === $fresh || !$fresh->basis->equals($plan->basis)) {
                    return Outcome::Superseded;
                }
                $source = $plan->live->source;
                $this->db->executeStatement('DELETE FROM itk_kb_chunk WHERE document_id = ?', [$key]);
                $this->db->executeStatement(
                    'INSERT INTO itk_kb_document (id, kind, source_id, locale, resource_key, page_translation_id, item_id, title, url, space, basis, chunk_count, indexed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON CONFLICT (id) DO UPDATE SET resource_key = EXCLUDED.resource_key, page_translation_id = EXCLUDED.page_translation_id, title = EXCLUDED.title, url = EXCLUDED.url, space = EXCLUDED.space, basis = EXCLUDED.basis, chunk_count = EXCLUDED.chunk_count, indexed_at = EXCLUDED.indexed_at',
                    [$key, $source->kind->value, $source->id, $source->locale, $source->resourceKey, $plan->live->pageTranslationId, SourceKind::Item === $source->kind ? $source->id : null, $source->title, $source->url, $plan->space->id(), $plan->basis->value, \count($plan->chunks), $this->clock->now()],
                    [12 => Types::DATETIME_IMMUTABLE],
                );
                if ([] === $plan->chunks) {
                    return Outcome::Written;
                }
                $values = [];
                $params = [];
                foreach ($plan->chunks as $chunk) {
                    $values[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS vector))';
                    \array_push($params, $key, $chunk->ordinal, $source->kind->value, $source->locale, $source->resourceKey, $plan->space->id(), $chunk->sectionId, $chunk->sectionType, $chunk->field, $chunk->heading, $chunk->text, $chunk->inputHash, $vectors[$chunk->inputHash]->toSql());
                }
                $this->db->executeStatement('INSERT INTO itk_kb_chunk (document_id, ordinal, kind, locale, resource_key, space, section_id, section_type, field, heading, text, input_hash, embedding) VALUES ' . \implode(', ', $values), $params);

                return Outcome::Written;
            });
        } catch (ForeignKeyConstraintViolationException) {
            return Outcome::Superseded;
        }
    }

    /** An Orphan: its row goes (the chunks by the foreign key) under the same advisory lock, unless it came back to life meanwhile. */
    private function remove(SourceRef $ref): Outcome
    {
        $key = $ref->key();

        return $this->db->transactional(function () use ($ref, $key): Outcome {
            $this->lock($key);
            if (null !== $this->planOne($ref)) {
                return Outcome::Superseded;
            }
            $this->db->executeStatement('DELETE FROM itk_kb_document WHERE id = ?', [$key]);

            return Outcome::Removed;
        });
    }

    private function lock(string $key): void
    {
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }
}
