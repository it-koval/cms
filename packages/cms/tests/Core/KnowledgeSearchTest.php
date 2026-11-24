<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\PageService;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Embedding\Purpose;
use Itk\Cms\KnowledgeBase\Indexer;
use Itk\Cms\KnowledgeBase\KnowledgeSearch;
use Itk\Cms\KnowledgeBase\Model\Hit;
use Itk\Cms\KnowledgeBase\Model\KnowledgeBaseUnavailable;
use Itk\Cms\KnowledgeBase\Model\SearchOptions;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use Itk\Cms\KnowledgeBase\Model\SyncKnowledge;
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
 * KnowledgeSearch::search(): что видит потребитель. Попадания только с живых страниц (снятая с публикации исчезает сразу, удалённая тоже),
 * только из пространства текущей модели, по фильтрам шаблонов и порогу; выключенная база или упавший эмбеддер дают KnowledgeBaseUnavailable.
 */
final class KnowledgeSearchTest extends KernelTestCase
{
    /** @var list<string> */
    private array $pages = [];

    /** @param array<string, mixed> $options */
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
        $c->get(FakeEmbedder::class)->forget();
        $c->get('cache.app')->clear();
        $this->transport()->reset();
        parent::tearDown();
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.knowledge_base');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function search(): KnowledgeSearch
    {
        return self::getContainer()->get(KnowledgeSearch::class);
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

    /**
     * @param array<array-key, mixed> $hits
     *
     * @return list<string> the page ids of the hits, best first
     */
    private static function ids(array $hits): array
    {
        return \array_map(static fn (Hit $h): string => $h->source->id, $hits);
    }

    #[Test]
    public function hitsAreRankedByMeaningAndCiteTheirSource(): void
    {
        self::bootKernel();
        $return = $this->publish('/kb-s-return', 'Возврат товара', '<p>Вернуть товар можно в течение 14 дней.</p>');
        $delivery = $this->publish('/kb-s-delivery', 'Доставка', '<p>Курьером по городу за один день.</p>');
        $this->drain();

        $result = $this->search()->search('как вернуть товар', new SearchOptions(limit: 10));

        self::assertSame('ru', $result->locale);
        self::assertSame($return, $result->hits[0]->source->id);
        self::assertSame('Вернуть товар можно в течение 14 дней.', $result->hits[0]->text);
        self::assertSame(['/kb-s-return', 's1', 'text', 'text', SourceKind::Page], [$result->hits[0]->source->url, $result->hits[0]->sectionId, $result->hits[0]->sectionType, $result->hits[0]->field, $result->hits[0]->source->kind]);
        self::assertGreaterThan(0.3, $result->hits[0]->score);
        self::assertSame([$return, $delivery], self::ids($result->bySource()), 'one row per page, the title chunk of the return page folded away');
        self::assertLessThan(0.05, $result->bySource()[1]->score, 'the delivery page shares no word with the query');

        self::assertSame([], $this->search()->search('как вернуть товар', new SearchOptions(minScore: 0.99))->hits);
        self::assertSame([], $this->search()->search('как вернуть товар', new SearchOptions(templates: []))->hits, 'no pages wanted');
        self::assertSame([], $this->search()->search('   ')->hits);

        $queries = \array_filter(self::getContainer()->get(FakeEmbedder::class)->calls, static fn (array $c): bool => Purpose::Query === $c['purpose']);
        self::assertCount(1, $queries, 'the same query is embedded once and cached');
    }

    #[Test]
    public function anUnpublishedPageDisappearsAtOnceAndADeletedPageToo(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-s-unpublish', 'Акция', '<p>Скидка на возврат товара.</p>');
        $this->drain();
        self::assertSame([$id], self::ids($this->search()->search('возврат товара')->bySource()));

        self::getContainer()->get(PageService::class)->unpublish($id);

        self::assertSame([], $this->search()->search('возврат товара')->hits, 'gone before the worker runs');
        $this->drain();
        self::assertSame([], $this->search()->search('возврат товара')->hits);

        $again = $this->publish('/kb-s-delete', 'Возврат', '<p>Возврат товара в магазин.</p>');
        $this->drain();
        self::assertSame([$again], self::ids($this->search()->search('возврат товара')->bySource()));
        self::getContainer()->get(PageService::class)->remove($again);
        $this->pages = [$id];

        self::assertSame([], $this->search()->search('возврат товара')->hits);
    }

    #[Test]
    public function searchSeesOnlyTheSpaceOfTheConfiguredModel(): void
    {
        self::bootKernel();
        $id = $this->publish('/kb-s-space', 'Оплата', '<p>Оплата картой при получении.</p>');
        $this->drain();
        self::assertSame([$id], self::ids($this->search()->search('оплата картой')->bySource()));

        self::getContainer()->get(FakeEmbedder::class)->model = 'v2';
        self::assertSame([], $this->search()->search('оплата картой')->hits, 'documents of the old model are never ranked with the new query');

        self::getContainer()->get(Indexer::class)->sync([new SourceRef(SourceKind::Page, $id, 'ru')]);
        self::assertSame([$id], self::ids($this->search()->search('оплата картой')->bySource()));
    }

    #[Test]
    public function offOrWithoutAnEmbedderTheSearchIsUnavailable(): void
    {
        self::bootKernel();
        self::getContainer()->get(FakeEmbedder::class)->failNext = EmbeddingFailed::transient('down');
        try {
            $this->search()->search('что угодно');
            self::fail('a failed query embedding was accepted');
        } catch (KnowledgeBaseUnavailable $e) {
            self::assertStringContainsString('down', $e->getMessage());
        }
        try {
            $this->search()->search('что угодно', new SearchOptions(templates: ['no_such_template']));
            self::fail('an unknown template was accepted');
        } catch (\InvalidArgumentException) {
        }

        self::ensureKernelShutdown();
        self::bootKernel(['itk' => ['knowledge_base' => ['enabled' => false]]]);
        self::assertFalse($this->search()->enabled());
        $this->expectException(KnowledgeBaseUnavailable::class);
        $this->search()->search('что угодно');
    }
}
