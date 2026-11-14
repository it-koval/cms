<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Itk\Cms\Content\IndexablePages;
use Itk\Cms\KnowledgeBase\Embedding\Embedder;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Embedding\Purpose;
use Itk\Cms\KnowledgeBase\Embedding\Vector;
use Itk\Cms\KnowledgeBase\Model\Hit;
use Itk\Cms\KnowledgeBase\Model\KnowledgeBaseUnavailable;
use Itk\Cms\KnowledgeBase\Model\SearchOptions;
use Itk\Cms\KnowledgeBase\Model\SearchResult;
use Itk\Cms\KnowledgeBase\Model\Source;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\Schema\Schema;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Public API. Ranks the live site's text by meaning: the one way site code, MCP tools and the console search the knowledge base.
 *
 *   $knowledge->search('как вернуть товар', new SearchOptions(locale: 'ru', limit: 6))->hits
 *   $knowledge->search($q, new SearchOptions(templates: ['blog_article'], collections: []))->bySource()
 *
 * Reads itk_kb_chunk and itk_kb_document, never writes them (Indexer is the one writer).
 * Hides: the query embedding and its cache, the space match, the HNSW settings, the liveness join, the score.
 */
final class KnowledgeSearch
{
    private const MAX_QUERY = 1000;
    /** a repeated query (a site search box) is embedded once a day */
    private const QUERY_TTL = 86400;

    public function __construct(
        private readonly Connection $db,
        private readonly Embedder $embedder,
        private readonly Schema $schema,
        private readonly IndexablePages $indexable,
        private readonly KnowledgeBaseOptions $options,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function enabled(): bool
    {
        return $this->options->enabled;
    }

    /**
     * The hits are live now: a page unpublished a second ago is gone from the results before the worker runs, and a page whose SEO or SEO rule
     * says noindex is left out like everywhere else the site shows itself to machines.
     *
     * @throws \InvalidArgumentException an unknown template or collection key (a developer mistake)
     * @throws KnowledgeBaseUnavailable off, or the embedder failed for the query
     */
    public function search(string $query, SearchOptions $options = new SearchOptions()): SearchResult
    {
        if (!$this->options->enabled) {
            throw new KnowledgeBaseUnavailable('The knowledge base is off (itk_cms.knowledge_base.enabled).');
        }
        foreach ((array) $options->templates as $key) {
            if (null === $this->schema->template($key)) {
                throw new \InvalidArgumentException(\sprintf('There is no template "%s" in the schema.', $key));
            }
        }
        foreach ((array) $options->collections as $key) {
            if (null === $this->schema->collection($key)) {
                throw new \InvalidArgumentException(\sprintf('There is no collection "%s" in the schema.', $key));
            }
        }
        $locale = $options->locale ?? $this->options->mainLocale;
        $q = \trim(\mb_substr($query, 0, self::MAX_QUERY));
        if ('' === $q) {
            return new SearchResult($query, $locale, []);
        }
        $vector = $this->queryVector($q);

        $where = ['c.space = ?', 'c.locale = ?', "(t.status = 'published' OR (i.translations -> d.locale ->> 'status') = 'published')"];
        $params = [$this->embedder->space()->id(), $locale];
        $types = [Types::STRING, Types::STRING];
        if (null !== $options->templates || null !== $options->collections) {
            $kinds = [];
            foreach ([SourceKind::Page->value => $options->templates, SourceKind::Item->value => $options->collections] as $kind => $keys) {
                if (null === $keys) {
                    $kinds[] = 'c.kind = ?';
                    $params[] = $kind;
                    $types[] = Types::STRING;
                } elseif ([] !== $keys) {
                    $kinds[] = '(c.kind = ? AND c.resource_key IN (?))';
                    \array_push($params, $kind, \array_values($keys));
                    \array_push($types, Types::STRING, ArrayParameterType::STRING);
                }
            }
            $where[] = [] === $kinds ? 'false' : '(' . \implode(' OR ', $kinds) . ')';
        }
        \array_push($params, $vector->toSql(), $options->limit * 2);
        \array_push($types, Types::STRING, Types::INTEGER);
        $sql = 'SELECT c.text, c.heading, c.section_id, c.section_type, c.field, d.kind, d.source_id, d.locale, d.resource_key, d.title, d.url,
                       t.published -> \'seo\' AS page_seo, 1 - (c.embedding <=> CAST(? AS vector)) AS score
                  FROM itk_kb_chunk c
                  JOIN itk_kb_document d ON d.id = c.document_id
                  LEFT JOIN itk_page_translation t ON t.id = d.page_translation_id
                  LEFT JOIN itk_collection_item i ON i.id = d.item_id
                 WHERE ' . \implode(' AND ', $where) . '
                 ORDER BY c.embedding <=> CAST(? AS vector)
                 LIMIT ?';
        $rows = $this->db->transactional(function () use ($sql, $params, $types, $vector): array {
            // keep scanning the graph when the filters drop candidates (pgvector 0.8), instead of returning too few rows
            $this->db->executeStatement('SET LOCAL hnsw.iterative_scan = relaxed_order');
            $this->db->executeStatement('SET LOCAL hnsw.ef_search = 100');

            return $this->db->fetchAllAssociative($sql, [$vector->toSql(), ...$params], [Types::STRING, ...$types]);
        });

        $hits = [];
        foreach ($rows as $row) {
            $score = (float) $row['score'];
            if ($score < $options->minScore) {
                continue;
            }
            $kind = SourceKind::from((string) $row['kind']);
            $url = null === $row['url'] ? null : (string) $row['url'];
            if (SourceKind::Page === $kind && $this->indexable->noindex((array) \json_decode((string) ($row['page_seo'] ?? '[]'), true), (string) $url, (string) $row['locale'])) {
                continue;
            }
            $hits[] = new Hit(
                $score,
                (string) $row['text'],
                null === $row['heading'] ? null : (string) $row['heading'],
                new Source($kind, (string) $row['source_id'], (string) $row['locale'], (string) $row['resource_key'], (string) $row['title'], $url),
                null === $row['section_id'] ? null : (string) $row['section_id'],
                null === $row['section_type'] ? null : (string) $row['section_type'],
                (string) $row['field'],
            );
        }
        // relaxed_order may hand rows slightly out of order
        \usort($hits, static fn (Hit $a, Hit $b): int => $b->score <=> $a->score);

        return new SearchResult($query, $locale, \array_slice($hits, 0, $options->limit));
    }

    /** The query's vector, from the cache when the same text was asked in the same space today. */
    private function queryVector(string $q): Vector
    {
        $key = 'itk_kb_q_' . \sha1($this->embedder->space()->id() . "\x1f" . $q);
        try {
            $item = $this->cache->getItem($key);
            if ($item->isHit() && \is_string($item->get())) {
                return Vector::fromSql($item->get());
            }
        } catch (\Throwable) {
            $item = null;
        }
        try {
            $vector = $this->embedder->embed([$q], Purpose::Query)->vectors[0];
        } catch (EmbeddingFailed $e) {
            throw new KnowledgeBaseUnavailable('The query could not be embedded: ' . $e->getMessage(), 0, $e);
        }
        if (null !== $item) {
            try {
                $this->cache->save($item->set($vector->toSql())->expiresAfter(self::QUERY_TTL));
            } catch (\Throwable) {
            }
        }

        return $vector;
    }
}
