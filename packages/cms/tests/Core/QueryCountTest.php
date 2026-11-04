<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\Listing;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Tests\App\QueryCounter;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Число запросов не растёт с числом страниц: список страниц, дерево, внутренние адреса. */
final class QueryCountTest extends ResourceKernelTestCase
{
    private function pagesOf(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            $page = $this->page('default', 'Страница ' . $i, '/test-q-' . $i);
            $this->pages->publish($page['id']);
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    #[Test]
    public function theListOfPagesDoesNotAskForTheVersionsOfEachPage(): void
    {
        $this->pagesOf(8);

        $queries = QueryCounter::during(fn () => $this->pages->list());

        self::assertLessThanOrEqual(3, \count($queries), \implode("\n", $queries));
    }

    #[Test]
    public function theListingOfAPageIsNotOneQueryPerPageEither(): void
    {
        $blog = $this->page('blog', 'Блог', '/test-q-blog');
        $this->pages->publish($blog['id']);
        for ($i = 1; $i <= 6; ++$i) {
            $a = $this->page('blog_article', 'Статья ' . $i, '/test-q-blog/a' . $i, $blog['id'], ['title' => 'Статья ' . $i, 'body' => 'Текст']);
            $this->pages->publish($a['id']);
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $queries = QueryCounter::during(static fn () => self::getContainer()->get(Listing::class)->forPage($blog['id']));

        self::assertLessThanOrEqual(4, \count($queries), \implode("\n", $queries));
    }

    #[Test]
    public function liveUrlsAreOneScalarQueryThatHydratesNoPages(): void
    {
        $this->pagesOf(5);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $index = new LiveUrlIndex(self::getContainer()->get(\Itk\Cms\Repository\PageTranslationRepository::class));

        $queries = QueryCounter::during(static function () use ($index): void {
            self::assertTrue($index->has('ru', '/test-q-3'));
            self::assertSame('Страница 3', $index->find('ru', '/test-q-3')['title']);
            $id = $index->find('ru', '/test-q-4')['id'];
            self::assertSame('/test-q-4', $index->byId('ru', $id)['url']);
            self::assertNull($index->byId('ru', 'no-such-id'));
        });

        self::assertCount(1, $queries, 'the index of a language is read once');
        self::assertSame(0, $em->getUnitOfWork()->size(), 'no page and no version is hydrated to build the addresses');
    }
}
