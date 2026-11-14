<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\PageService;
use Itk\Cms\KnowledgeBase\Indexer;
use Itk\Cms\KnowledgeBase\KnowledgeSearch;
use Itk\Cms\KnowledgeBase\Model\SearchOptions;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
use Itk\Cms\KnowledgeBase\SourceReader;
use Itk\Cms\KnowledgeBase\SyncKnowledgeHandler;
use Itk\Cms\Tests\App\FakeEmbedder;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Решение о записи документа принимается по свежему состоянию базы, а не по снимку, который EntityManager уже держит в памяти,
 * и смена шаблона страницы доходит до индекса. Исключённый шаблон убирает документ, другой включённый обновляет ключ шаблона в выдаче.
 */
final class KnowledgeBaseFreshnessTest extends KernelTestCase
{
    /** @var list<string> */
    private array $pages = [];

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? ['knowledge_base' => ['enabled' => true]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->pages as $id) {
            try {
                self::getContainer()->get(PageService::class)->remove($id);
            } catch (\Throwable) {
            }
        }
        self::getContainer()->get(FakeEmbedder::class)->forget();
        $this->transport()->reset();
        parent::tearDown();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.knowledge_base');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function db(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function publish(string $url, string $title, string $html): string
    {
        $pages = self::getContainer()->get(PageService::class);
        $page = $pages->create(['template' => 'default', 'title' => $title, 'url' => $url, 'sections' => [['id' => 's1', 'type' => 'text', 'hidden' => false, 'data' => ['text' => $html]]]]);
        $this->pages[] = $page['id'];
        $pages->publish($page['id']);

        return $page['id'];
    }

    private function drain(): void
    {
        $handler = self::getContainer()->get(SyncKnowledgeHandler::class);
        foreach ($this->transport()->getSent() as $envelope) {
            \assert($envelope instanceof Envelope);
            $message = $envelope->getMessage();
            \assert($message instanceof SyncKnowledge);
            $handler($message, new Acknowledger(SyncKnowledgeHandler::class));
        }
        $handler->flush(true);
        $this->transport()->reset();
    }

    /** @return list<string> */
    private function chunkTexts(string $pageId): array
    {
        return $this->db()->fetchFirstColumn('SELECT c.text FROM itk_kb_chunk c JOIN itk_kb_document d ON d.id = c.document_id WHERE d.source_id = ? ORDER BY c.ordinal', [$pageId]);
    }

    #[Test]
    public function aSourceChangedBehindTheEntityManagersBackIsIndexedFromTheDatabase(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-fresh', 'Сроки', '<p>Срок возврата четырнадцать дней.</p>');
        $this->drain();
        $ref = new SourceRef(SourceKind::Page, $id, 'ru');
        self::assertNotNull(self::getContainer()->get(SourceReader::class)->read($ref), 'the version is now managed by the EntityManager');

        $published = \json_decode((string) $this->db()->fetchOne('SELECT published FROM itk_page_translation WHERE page_id = ?', [$id]), true);
        $published['sections'][0]['data']['text'] = '<p>Срок возврата тридцать дней.</p>';
        $this->db()->executeStatement('UPDATE itk_page_translation SET published = CAST(? AS jsonb) WHERE page_id = ?', [\json_encode($published, \JSON_UNESCAPED_UNICODE), $id]);

        self::getContainer()->get(Indexer::class)->sync([$ref]);

        self::assertSame(['Сроки', 'Срок возврата тридцать дней.'], $this->chunkTexts($id));
    }

    #[Test]
    public function aPageSwitchedToAnExcludedTemplateLeavesTheIndex(): void
    {
        self::bootKernel(['itk' => ['knowledge_base' => ['enabled' => true, 'exclude_templates' => ['blog']]]]);
        $id = $this->publish('/kb-to-blog', 'Возврат товара', '<p>Вернуть товар можно в течение 14 дней.</p>');
        $this->drain();
        $search = self::getContainer()->get(KnowledgeSearch::class);
        self::assertSame([$id], \array_map(static fn ($h): string => $h->source->id, $search->search('вернуть товар')->bySource()));

        self::getContainer()->get(PageService::class)->saveDraft($id, null, ['template' => 'blog']);
        self::assertCount(1, $this->transport()->getSent(), 'a template change queues the housekeeping');
        $this->drain();

        self::assertSame([], $search->search('вернуть товар')->hits);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM itk_kb_document WHERE source_id = ?', [$id]));
    }

    #[Test]
    public function aPageSwitchedBetweenTwoIncludedTemplatesChangesItsTemplateInTheResults(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-to-blog-2', 'Возврат товара', '<p>Вернуть товар можно в течение 14 дней.</p>');
        $this->drain();
        $search = self::getContainer()->get(KnowledgeSearch::class);
        self::assertSame('default', $search->search('вернуть товар')->hits[0]->source->resourceKey);

        self::getContainer()->get(PageService::class)->saveDraft($id, null, ['template' => 'blog']);
        $this->drain();

        self::assertSame('blog', $search->search('вернуть товар')->hits[0]->source->resourceKey);
        self::assertSame([], $search->search('вернуть товар', new SearchOptions(templates: ['default']))->hits);
        self::assertSame([$id], \array_map(static fn ($h): string => $h->source->id, $search->search('вернуть товар', new SearchOptions(templates: ['blog']))->bySource()));
    }

    #[Test]
    public function aTemplateChangeQueuesNothingWhileTheKnowledgeBaseIsOff(): void
    {
        self::bootKernel(['itk' => ['knowledge_base' => ['enabled' => false]]]);
        $id = $this->publish('/kb-off-switch', 'Возврат товара', '<p>Вернуть товар можно в течение 14 дней.</p>');

        self::getContainer()->get(PageService::class)->saveDraft($id, null, ['template' => 'blog']);

        self::assertSame([], $this->transport()->getSent());
    }
}
