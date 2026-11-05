<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
use Itk\Cms\I18n\I18n;
use Itk\Cms\KnowledgeBase\Embedding\Embedder;
use Itk\Cms\KnowledgeBase\Embedding\OpenAiEmbedder;
use Itk\Cms\KnowledgeBase\KnowledgeBaseOptions;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * «База знаний» in «Разработчику → Состояние сайта». Quick by contract: a few small queries, never the embedding API.
 *   off                                                               ok    "off"
 *   no HTTP client (symfony/http-client not installed)                fail  "composer require symfony/http-client"
 *   OPENAI_API_KEY empty                                              fail
 *   the extension `vector` missing in the database                    fail
 *   documents of another space than the configured embedder's         fail  "model changed: N documents still in <space>, run itk:knowledge-base:sync --now"
 *   SyncKnowledge in the failure transport                            warn  count; "messenger:failed:show"
 *   messages waiting in `knowledge_base` for over STALE seconds       warn  "is a worker consuming knowledge_base?"
 *   otherwise                                                         ok    "N of M live versions indexed, K chunks"
 * "Indexed" counts document rows against live versions of the main language, not their freshness: the basis of each would need its extraction, too slow
 * for this screen. The client and key lines apply only to the bundle's OpenAiEmbedder; a site's own embedder answers for itself.
 *
 * @internal
 */
final class KnowledgeBaseHealthCheck implements HealthCheckInterface
{
    /** a message waiting in the queue for longer than this many seconds means that nobody takes it */
    private const STALE = 300;

    public function __construct(
        private readonly Connection $db,
        private readonly Embedder $embedder,
        private readonly KnowledgeBaseOptions $options,
        private readonly ?HttpClientInterface $http,
        #[Autowire(env: 'default::OPENAI_API_KEY')]
        private readonly ?string $apiKey,
    ) {
    }

    public function name(): string
    {
        return I18n::t('ops.health_knowledge_base');
    }

    public function check(): HealthResult
    {
        if (!$this->options->enabled) {
            return HealthResult::ok(I18n::t('ops.health_knowledge_base_off'));
        }
        if ($this->embedder instanceof OpenAiEmbedder) {
            if (null === $this->http) {
                return HealthResult::fail(I18n::t('ops.health_knowledge_base_no_client'));
            }
            if (null === $this->apiKey || '' === $this->apiKey) {
                return HealthResult::fail(I18n::t('ops.health_knowledge_base_no_key'));
            }
        }
        if (false === $this->db->fetchOne("SELECT 1 FROM pg_extension WHERE extname = 'vector'")) {
            return HealthResult::fail(I18n::t('ops.health_knowledge_base_no_extension'));
        }
        $space = $this->embedder->space()->id();
        $indexed = 0;
        foreach ($this->db->fetchAllKeyValue('SELECT space, COUNT(*) FROM itk_kb_document GROUP BY space') as $stored => $n) {
            if ((string) $stored !== $space) {
                return HealthResult::fail(I18n::t('ops.health_knowledge_base_space', ['count' => (int) $n, 'space' => (string) $stored]));
            }
            $indexed = (int) $n;
        }
        $failed = $this->failed();
        if ($failed > 0) {
            return HealthResult::warn(I18n::t('ops.health_knowledge_base_failed', ['count' => $failed]));
        }
        $waiting = $this->waiting();
        if (null !== $waiting) {
            return HealthResult::warn(I18n::t('ops.health_knowledge_base_waiting', ['count' => $waiting['count'], 'minutes' => (int) \floor($waiting['age'] / 60)]));
        }
        $live = (int) $this->db->fetchOne("SELECT COUNT(*) FROM itk_page_translation WHERE status = 'published' AND locale = ?", [$this->options->mainLocale])
            + (int) $this->db->fetchOne("SELECT COUNT(*) FROM itk_collection_item WHERE translations -> ? ->> 'status' = 'published'", [$this->options->mainLocale]);
        $chunks = (int) $this->db->fetchOne('SELECT COUNT(*) FROM itk_kb_chunk');

        return HealthResult::ok(I18n::t('ops.health_knowledge_base_ok', ['indexed' => $indexed, 'live' => $live, 'chunks' => $chunks]));
    }

    /** SyncKnowledge messages in the database failure transport (the headers of a stored message name its class), 0 when that cannot be known. */
    private function failed(): int
    {
        try {
            if (!$this->db->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return 0;
            }

            return (int) $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND CAST(headers AS jsonb) ->> 'type' = ?", [SyncKnowledge::class]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Messages of the database transport `knowledge_base` that have waited longer than STALE (another transport cannot say how old its messages are).
     *
     * @return array{count: int, age: int}|null
     */
    private function waiting(): ?array
    {
        try {
            if (!$this->db->createSchemaManager()->tablesExist(['messenger_messages'])) {
                return null;
            }
            $row = $this->db->fetchAssociative("SELECT COUNT(*) AS n, MIN(available_at) AS oldest FROM messenger_messages WHERE queue_name = 'knowledge_base' AND delivered_at IS NULL AND available_at <= (now() AT TIME ZONE 'UTC')");
        } catch (\Throwable) {
            return null;
        }
        if (false === $row || 0 === (int) $row['n'] || !\is_string($row['oldest'])) {
            return null;
        }
        $age = \time() - (new \DateTimeImmutable($row['oldest'], new \DateTimeZone('UTC')))->getTimestamp();

        return $age > self::STALE ? ['count' => (int) $row['n'], 'age' => $age] : null;
    }
}
