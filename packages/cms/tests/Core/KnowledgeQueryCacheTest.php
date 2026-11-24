<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\IndexablePages;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\KnowledgeBase\KnowledgeBaseOptions;
use Itk\Cms\KnowledgeBase\KnowledgeSearch;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Repository\SeoRuleRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\App\FakeEmbedder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\RequestStack;

final class KnowledgeQueryCacheTest extends TestCase
{
    #[Test]
    public function searchCacheHitsEvictsAndExpiresWithoutGrowingWithTheQueryCount(): void
    {
        $clock = new class implements ClockInterface {
            public int $offset = 0;

            public function now(): \DateTimeImmutable
            {
                return (new \DateTimeImmutable())->modify('+' . $this->offset . ' seconds');
            }
        };
        $cache = new ArrayAdapter(clock: $clock);
        $embedder = new FakeEmbedder();
        $db = $this->createStub(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (\Closure $run): mixed => $run());
        $db->method('fetchAllAssociative')->willReturn([]);
        $indexable = new IndexablePages($this->createStub(PageTranslationRepository::class), new SeoResolver($this->createStub(SeoRuleRepository::class), new RequestStack(), 'ru'), 'ru');
        $search = new KnowledgeSearch($db, $embedder, new Schema('ru', [], [], [], [], [], [], [], [], []), $indexable, new KnowledgeBaseOptions(true, [], [], 'ru'), $cache);

        $search->search('cache fixture first');
        $search->search('cache fixture first');
        self::assertCount(1, $embedder->calls, 'a repeated query is a cache hit');
        for ($i = 0; $i < 512; ++$i) {
            $search->search('cache fixture query ' . $i);
        }
        self::assertLessThanOrEqual(256, \count($cache->getValues()), 'unique queries cannot grow cache storage indefinitely');
        $before = \count($embedder->calls);
        for ($i = 0; $i < 512; ++$i) {
            $search->search('cache fixture query ' . $i);
        }
        self::assertGreaterThan($before, \count($embedder->calls), 'a full cache evicts previous vectors');
        $before = \count($embedder->calls);
        $search->search('cache fixture query 511');
        self::assertCount($before, $embedder->calls);
        $clock->offset = 86401;
        $search->search('cache fixture query 511');
        self::assertCount($before + 1, $embedder->calls, 'a vector expires after one day');
        $embedder->model = 'new-space';
        $search->search('cache fixture query 511');
        self::assertCount($before + 2, $embedder->calls, 'a different embedding space never reuses the previous vector');
    }
}
