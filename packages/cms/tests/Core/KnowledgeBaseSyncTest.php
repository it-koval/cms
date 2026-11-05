<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Indexer;
use Itk\Cms\KnowledgeBase\Model\Freshness;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Itk\Cms\KnowledgeBase\SyncKnowledgeHandler;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Tests\App\FakeEmbedder;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * База знаний следует за публикацией: публикация даёт фрагменты, повторная без изменений ничего не стоит, правка одной секции отправляет
 * в эмбеддер только её текст, снятие с публикации и удаление убирают строки, смена модели делает документы устаревшими, а noindex, canSee()
 * и raw() никогда не попадают в индекс. Массовая публикация идёт в эмбеддер пачками.
 */
final class KnowledgeBaseSyncTest extends KernelTestCase
{
    /** @var list<string> */
    private array $pages = [];
    /** @var list<string> */
    private array $rules = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? ['knowledge_base' => ['enabled' => true]]);
    }

    protected function tearDown(): void
    {
        $c = self::getContainer();
        foreach ($this->pages as $id) {
            try {
                $c->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        foreach ($this->rules as $id) {
            try {
                $c->get(SeoRuleService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        $c->get(FakeEmbedder::class)->forget();
        $this->transport()->reset();
        parent::tearDown();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.knowledge_base');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function embedder(): FakeEmbedder
    {
        return self::getContainer()->get(FakeEmbedder::class);
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /** @param list<array<string, mixed>> $sections */
    private function publish(string $url, string $title, array $sections = [], array $seo = []): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => $title, 'url' => $url, 'sections' => $sections] + ([] === $seo ? [] : ['seo' => $seo]));
        $this->pages[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    /** @return array<string, mixed> a text section */
    private static function text(string $id, string $html): array
    {
        return ['id' => $id, 'type' => 'text', 'hidden' => false, 'data' => ['text' => $html]];
    }

    /**
     * The worker: every message of the queue through the batch handler, as messenger:consume would feed it.
     *
     * @return list<\Throwable> what was nacked
     */
    private function drain(): array
    {
        $handler = self::getContainer()->get(SyncKnowledgeHandler::class);
        $errors = [];
        foreach ($this->transport()->getSent() as $envelope) {
            \assert($envelope instanceof Envelope);
            $message = $envelope->getMessage();
            \assert($message instanceof SyncKnowledge);
            $handler($message, new Acknowledger(SyncKnowledgeHandler::class, static function (?\Throwable $e) use (&$errors): void {
                if (null !== $e) {
                    $errors[] = $e;
                }
            }));
        }
        $handler->flush(true);
        $this->transport()->reset();

        return $errors;
    }

    /** @return array{documents: int, chunks: int} */
    private function rows(string $pageId): array
    {
        return [
            'documents' => (int) $this->db()->fetchOne('SELECT COUNT(*) FROM itk_kb_document WHERE source_id = ?', [$pageId]),
            'chunks' => (int) $this->db()->fetchOne('SELECT COUNT(*) FROM itk_kb_chunk c JOIN itk_kb_document d ON d.id = c.document_id WHERE d.source_id = ?', [$pageId]),
        ];
    }

    /** @return list<string> */
    private function chunkTexts(string $pageId): array
    {
        return $this->db()->fetchFirstColumn('SELECT c.text FROM itk_kb_chunk c JOIN itk_kb_document d ON d.id = c.document_id WHERE d.source_id = ? ORDER BY c.ordinal', [$pageId]);
    }

    #[Test]
    public function aPublishMakesChunksAndARepublishWithoutChangesEmbedsNothing(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-publish', 'Возврат товара', [self::text('s1', '<p>Вернуть товар можно в течение 14 дней.</p>')]);

        self::assertCount(1, $this->transport()->getSent(), 'one message per publish');
        self::assertSame([], $this->drain());
        self::assertSame(['documents' => 1, 'chunks' => 2], $this->rows($id), 'the head and the section');
        self::assertSame(['Возврат товара', 'Вернуть товар можно в течение 14 дней.'], $this->chunkTexts($id));
        self::assertCount(1, $this->embedder()->calls);
        self::assertSame("Возврат товара\n\n\nВернуть товар можно в течение 14 дней.", $this->embedder()->embeddedDocuments()[1]);

        self::getContainer()->get(PageService::class)->publish($id);
        self::assertSame([], $this->drain());

        self::assertCount(1, $this->embedder()->calls, 'the same text is not embedded twice');
        self::assertSame(['documents' => 1, 'chunks' => 2], $this->rows($id));
    }

    #[Test]
    public function editingOneSectionEmbedsOnlyThatSectionsText(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-edit', 'Доставка', [self::text('s1', '<p>Курьером по городу.</p>'), self::text('s2', '<p>Самовывоз из магазина.</p>')]);
        $this->drain();
        $this->embedder()->forget();

        $pages = self::getContainer()->get(PageService::class);
        $pages->saveDraft($id, null, ['sections' => [self::text('s1', '<p>Курьером по городу.</p>'), self::text('s2', '<p>Самовывоз со склада.</p>')]]);
        $pages->publish($id);
        self::assertSame([], $this->drain());

        self::assertSame(["Доставка\n\n\nСамовывоз со склада."], $this->embedder()->embeddedDocuments(), 'the head and the first section reuse their vectors by hash');
        self::assertSame(['Доставка', 'Курьером по городу.', 'Самовывоз со склада.'], $this->chunkTexts($id));
    }

    #[Test]
    public function anUnpublishRemovesTheDocument(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-unpublish', 'Акция', [self::text('s1', '<p>Скидка до конца месяца.</p>')]);
        $this->drain();
        self::assertSame(1, $this->rows($id)['documents']);

        self::getContainer()->get(PageService::class)->unpublish($id);
        self::assertCount(1, $this->transport()->getSent(), 'an unpublish queues the housekeeping');
        self::assertSame([], $this->drain());

        self::assertSame(['documents' => 0, 'chunks' => 0], $this->rows($id));
    }

    #[Test]
    public function deletingAPageWithTwoLanguagesDropsEveryRowByTheForeignKey(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-delete', 'О нас', [self::text('s1', '<p>Мы работаем с 2010 года.</p>')]);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $page = self::getContainer()->get(PageRepository::class)->find($id);
        self::assertNotNull($page);
        $ru = $page->translation('ru');
        self::assertNotNull($ru);
        $en = new PageTranslation($page, 'en');
        $en->draft = $en->published = ['title' => 'About us', 'url' => '/kb-delete', 'seo' => [], 'sections' => $ru->published['sections'], 'fields' => []];
        $en->status = PageTranslation::PUBLISHED;
        $en->publishedUrl = '/kb-delete';
        $page->translations->set('en', $en);
        $em->persist($en);
        $em->flush();
        $this->drain();
        self::getContainer()->get(Indexer::class)->sync([new SourceRef(SourceKind::Page, $id, 'en')]);
        self::assertSame(['documents' => 1, 'chunks' => 2], $this->rows($id), 'only the main language is indexed');

        self::getContainer()->get(PageService::class)->remove($id);
        $this->pages = [];

        self::assertSame(['documents' => 0, 'chunks' => 0], $this->rows($id));
        self::assertSame([], $this->transport()->getSent(), 'a delete needs no message');
    }

    #[Test]
    public function aModelChangeMakesEveryDocumentStaleAndASyncRewritesItInTheNewSpace(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-model', 'Оплата', [self::text('s1', '<p>Картой или наличными.</p>')]);
        $this->drain();
        $indexer = self::getContainer()->get(Indexer::class);
        $ref = new SourceRef(SourceKind::Page, $id, 'ru');
        self::assertSame(Freshness::Current, $indexer->plan([$ref])[0]->state);

        $this->embedder()->model = 'v2';
        $planned = $indexer->plan([$ref])[0];
        self::assertSame(Freshness::Stale, $planned->state);
        self::assertSame(2, $planned->newInputs, 'nothing of the new space is in the table yet');

        $report = $indexer->sync([$ref]);
        self::assertSame(['Written' => 1], \array_filter($report->counts()));
        self::assertSame('fake:v2:1536', $this->db()->fetchOne('SELECT space FROM itk_kb_document WHERE source_id = ?', [$id]));
        self::assertSame(Freshness::Current, $indexer->plan([$ref])[0]->state);
    }

    #[Test]
    public function aNoindexPageIsNeverIndexedAndARuleMakesItAnOrphan(): void
    {
        self::bootKernel();
        $own = $this->publish('/kb-noindex-own', 'Спасибо', [self::text('s1', '<p>Заявка принята.</p>')], ['robots' => 'noindex, follow']);
        $this->drain();
        self::assertSame(['documents' => 0, 'chunks' => 0], $this->rows($own));
        self::assertSame([], $this->embedder()->calls);

        $ruled = $this->publish('/kb-noindex-rule', 'Архив', [self::text('s1', '<p>Старые новости.</p>')]);
        $this->drain();
        self::assertSame(1, $this->rows($ruled)['documents']);
        $this->rules[] = self::getContainer()->get(SeoRuleService::class)->create(['path' => '/kb-noindex-rule', 'match' => 'exact', 'robots' => 'noindex,follow'])->id;
        $indexer = self::getContainer()->get(Indexer::class);
        $ref = new SourceRef(SourceKind::Page, $ruled, 'ru');

        self::assertSame(Freshness::Orphan, $indexer->plan([$ref])[0]->state);
        $indexer->sync([$ref]);
        self::assertSame(['documents' => 0, 'chunks' => 0], $this->rows($ruled));
    }

    #[Test]
    public function fieldsWithCanSeeAndRawHtmlNeverReachTheIndex(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-private', 'Контакты', [
            ['id' => 'n', 'type' => 'notes', 'hidden' => false, 'data' => ['summary' => 'Открытый текст', 'internal' => 'СекретнаяПометка', 'mood' => 'calm', 'gallery' => []]],
            ['id' => 'h', 'type' => 'html', 'hidden' => false, 'data' => ['html' => '<p>СыройКод</p>']],
        ]);
        self::assertSame([], $this->drain());

        $all = \implode("\n", [...$this->chunkTexts($id), ...$this->embedder()->embeddedDocuments()]);
        self::assertStringContainsString('Открытый текст', $all);
        self::assertStringNotContainsString('СекретнаяПометка', $all);
        self::assertStringNotContainsString('СыройКод', $all);
    }

    #[Test]
    public function aMassPublishIsOneEmbedderCallPerBatch(): void
    {
        self::bootKernel();
        for ($i = 0; $i < 40; ++$i) {
            $this->publish('/kb-mass-' . $i, 'Страница ' . $i, [self::text('s1', '<p>Текст страницы номер ' . $i . '.</p>')]);
        }
        self::assertCount(40, $this->transport()->getSent());

        self::assertSame([], $this->drain());

        self::assertCount(2, $this->embedder()->calls, '32 messages in the first batch, 8 in the second');
        self::assertSame(40, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM itk_kb_document WHERE url LIKE '/kb-mass-%'"));
    }

    #[Test]
    public function anEmbedderFailureNacksTheBatchTransientlyOrForGood(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-fail', 'Сбой', [self::text('s1', '<p>Текст.</p>')]);
        $this->embedder()->failNext = EmbeddingFailed::transient('rate limit', 20);
        $errors = $this->drain();
        self::assertCount(1, $errors);
        self::assertInstanceOf(RecoverableMessageHandlingException::class, $errors[0]);
        self::assertSame(20000, $errors[0]->getRetryDelay(), 'the Retry-After of the API, in milliseconds');
        self::assertSame(0, $this->rows($id)['documents']);

        self::getContainer()->get(PageService::class)->publish($id);
        $this->embedder()->failNext = EmbeddingFailed::permanent('bad key');
        $errors = $this->drain();
        self::assertInstanceOf(UnrecoverableMessageHandlingException::class, $errors[0]);
    }

    #[Test]
    public function anAutosaveAndAnExcludedTemplateQueueNothing(): void
    {
        self::bootKernel(['itk' => ['knowledge_base' => ['enabled' => true, 'exclude_templates' => ['default']]]]);
        $id = $this->publish('/kb-excluded', 'Исключено', [self::text('s1', '<p>Текст.</p>')]);
        self::getContainer()->get(PageService::class)->saveDraft($id, null, ['title' => 'Исключено (черновик)']);

        self::assertSame([], $this->transport()->getSent());
    }
}
