<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentReader;
use Itk\Cms\Tests\App\QueryCounter;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Twig\Environment;

/** ContentReader и itk_pages() / itk_page() / itk_collection(): публичное чтение опубликованного, не зависящее от того, кто смотрит. */
final class ContentReaderTest extends ResourceKernelTestCase
{
    private ContentReader $reader;

    protected function prepare(): void
    {
        parent::prepare();
        $this->reader = self::getContainer()->get(ContentReader::class);
        $this->reader->reset();
    }

    /** @return array{blog: string, news: string, tips: string, a: string, b: string, c: string, draft: string} */
    private function blog(): array
    {
        $blog = $this->page('blog', 'Блог', '/test-blog');
        $news = $this->page('blog_category', 'Новости', '/test-blog/news', $blog['id']);
        $tips = $this->page('blog_category', 'Советы', '/test-blog/tips', $blog['id']);
        $a = $this->page('blog_article', 'Первая', '/test-blog/news/a', $news['id'], ['title' => 'Первая', 'date' => '2026-10-01', 'body' => 'Текст А']);
        $b = $this->page('blog_article', 'Вторая', '/test-blog/news/b', $news['id'], ['title' => 'Вторая', 'date' => '2026-10-20', 'body' => 'Текст Б']);
        $c = $this->page('blog_article', 'Третья', '/test-blog/tips/c', $tips['id'], ['title' => 'Третья', 'date' => '2026-10-10', 'body' => 'Текст В']);
        $draft = $this->page('blog_article', 'Черновик', '/test-blog/news/draft', $news['id'], ['title' => 'Черновик', 'date' => '2026-10-30', 'body' => 'Не готово']);
        foreach ([$blog, $news, $tips, $a, $b, $c] as $p) {
            $this->pages->publish($p['id']);
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->reader->reset();

        return ['blog' => $blog['id'], 'news' => $news['id'], 'tips' => $tips['id'], 'a' => $a['id'], 'b' => $b['id'], 'c' => $c['id'], 'draft' => $draft['id']];
    }

    #[Test]
    public function pagesAreThePublishedOnesOfATemplateInSiteOrder(): void
    {
        $this->blog();

        $rows = $this->reader->pages(['template' => 'blog_article']);

        self::assertSame(['Первая', 'Третья', 'Вторая'], \array_column($rows, 'title'), 'the order among neighbours (position), then by creation: «Первая» and «Третья» are first in their categories; the draft article is not there');
        self::assertSame(['id', 'parentId', 'template', 'locale', 'title', 'url', 'seo', 'fields', 'position', 'publishedAt'], \array_keys($rows[0]));
        self::assertSame('/test-blog/news/a', $rows[0]['url']);
        self::assertSame('<p>Текст А</p>', $rows[0]['fields']['body'], 'the published fields as they are stored');
        self::assertSame('ru', $rows[0]['locale']);
        self::assertSame('blog_article', $rows[0]['template']);
    }

    #[Test]
    public function aDraftEditOrAnUnpublishedPageDoesNotShow(): void
    {
        $ids = $this->blog();
        $this->pages->update($ids['a'], 'ru', ['title' => 'Первая, правка']);
        $this->pages->unpublish($ids['c']);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->reader->reset();

        self::assertSame(['Первая', 'Вторая'], \array_column($this->reader->pages(['template' => 'blog_article']), 'title'), 'the published text, not the draft; no unpublished page');
        self::assertNull($this->reader->page($ids['c']));
        self::assertNull($this->reader->page($ids['draft']));
        self::assertSame('Первая', $this->reader->page($ids['a'])['title']);
        self::assertNull($this->reader->page('no-such-id'));
    }

    #[Test]
    public function theParentTemplatesLimitAndSortAreOptions(): void
    {
        $ids = $this->blog();

        self::assertSame(['Первая', 'Вторая'], \array_column($this->reader->pages(['template' => 'blog_article', 'parent' => $ids['news']]), 'title'));
        self::assertSame(['Вторая', 'Третья', 'Первая'], \array_column($this->reader->pages(['template' => 'blog_article', 'sort' => '-fields.date']), 'title'));
        self::assertSame(['Первая', 'Вторая'], \array_column($this->reader->pages(['template' => ['blog_article'], 'parent' => $ids['news'], 'sort' => '-title', 'limit' => 5]), 'title'));
        self::assertSame(['Вторая', 'Первая', 'Третья'], \array_column($this->reader->pages(['template' => 'blog_article', 'sort' => ['title'], 'limit' => 3]), 'title'));
        self::assertSame(['Первая', 'Третья'], \array_column($this->reader->pages(['template' => 'blog_article', 'limit' => 2]), 'title'));
        self::assertSame(['Новости', 'Советы'], \array_column($this->reader->pages(['template' => 'blog_category']), 'title'));
        self::assertSame(['blog_category', 'blog_category'], \array_column($this->reader->pages(['parent' => $ids['blog']]), 'template'));
    }

    #[Test]
    public function aWrongOptionIsAnErrorThatNamesWhatIsAllowed(): void
    {
        $this->blog();
        foreach ([
            [['template' => 'blogg'], 'blogg'],
            [['sort' => 'nope'], 'position'],
            [['sort' => 'fields.bad key'], 'bad key'],
            [['limit' => -1], 'limit'],
            [['colour' => 'red'], 'template, parent, limit, sort'],
        ] as [$options, $needle]) {
            try {
                $this->reader->pages($options);
                self::fail('a wrong option must be refused: ' . \json_encode($options));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    #[Test]
    public function theBlogScenario(): void
    {
        $ids = $this->blog();

        // the categories of the blog (the old BlogExtension::blog()) and the title of the parent of an article (BlogExtension::page())
        $categories = $this->reader->pages(['template' => 'blog_category']);
        self::assertSame([['id' => $ids['news'], 'title' => 'Новости', 'url' => '/test-blog/news'], ['id' => $ids['tips'], 'title' => 'Советы', 'url' => '/test-blog/tips']], \array_map(static fn (array $c): array => ['id' => $c['id'], 'title' => $c['title'], 'url' => $c['url']], $categories));
        $root = $this->reader->pages(['template' => 'blog', 'limit' => 1]);
        self::assertSame('Блог', $root[0]['title']);
        foreach ($this->reader->pages(['template' => 'blog_article']) as $article) {
            self::assertSame('Третья' === $article['title'] ? 'Советы' : 'Новости', $this->reader->page($article['parentId'])['title']);
        }
    }

    #[Test]
    public function manyPagesByIdAreNotOneQueryEach(): void
    {
        $ids = $this->blog();
        $queries = QueryCounter::during(function () use ($ids): void {
            for ($i = 0; $i < 5; ++$i) {
                foreach ([$ids['news'], $ids['tips'], $ids['a'], $ids['b'], $ids['c'], $ids['blog']] as $id) {
                    self::assertNotNull($this->reader->page($id));
                }
            }
        });
        self::assertLessThanOrEqual(1, \count($queries), \implode("\n", $queries));

        $list = QueryCounter::during(fn () => $this->reader->pages(['template' => 'blog_article', 'sort' => '-fields.date', 'limit' => 2]));
        self::assertCount(1, $list);
    }

    #[Test]
    public function aLanguageWithoutVersionsHasNoPages(): void
    {
        $this->blog();

        self::assertSame([], $this->reader->pages(['template' => 'blog_article'], 'en'));
        self::assertNull($this->reader->page($this->pageIds[0], 'en'));
    }

    /** @return list<string> */
    private function seedTrips(): array
    {
        $ids = [];
        foreach ([
            ['Алтай', '2026-12-01', 10, 'open', true],
            ['Байкал', '2026-11-15', 8, 'full', false],
            ['Камчатка', '2027-01-10', 12, 'open', false],
            ['Карелия', '2026-11-20', 6, 'open', true],
            ['Черновик', '2026-11-01', 99, 'open', true],
        ] as [$title, $start, $seats, $state, $hot]) {
            $ids[$title] = $this->trip($title, ['start' => $start, 'seats' => $seats, 'state' => $state, 'hot' => $hot])['id'];
        }
        foreach (['Алтай', 'Байкал', 'Камчатка', 'Карелия'] as $title) {
            $this->collections->publish('test_trips', $ids[$title]);
        }
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $ids;
    }

    /** @param array<string, mixed> $options @return list<string> */
    private function trips(array $options = []): array
    {
        return \array_column($this->reader->collection('test_trips', $options), 'title');
    }

    #[Test]
    public function aCollectionGivesThePublishedRecordsWhoeverLooks(): void
    {
        $ids = $this->seedTrips();
        $this->collections->update('test_trips', $ids['Алтай'], ['title' => 'Алтай, правка']);
        $this->collections->unpublish('test_trips', $ids['Камчатка']);

        foreach (['admin', null] as $who) {
            if (null === $who) {
                self::getContainer()->get('security.token_storage')->setToken(null);
            }
            $rows = $this->reader->collection('test_trips');
            self::assertSame(['Алтай', 'Байкал', 'Карелия'], \array_column($rows, 'title'), 'the published text of the edited record, no draft, no unpublished record');
            self::assertSame($ids['Алтай'], $rows[0]['id']);
            self::assertSame(10, $rows[0]['seats']);
            self::assertArrayHasKey('secret', $rows[0], 'the published values as they are, like itk_collection() always gave them');
        }
        self::assertEquals(self::getContainer()->get(\Itk\Cms\Content\CollectionService::class)->published('test_trips'), $this->reader->collection('test_trips'), 'without options: what itk_collection() gave before');
    }

    #[Test]
    public function aCollectionIsFilteredSortedAndCut(): void
    {
        $this->seedTrips();

        self::assertSame(['Алтай', 'Камчатка', 'Карелия'], $this->trips(['where' => ['state' => 'open']]));
        self::assertSame(['Алтай', 'Карелия'], $this->trips(['where' => ['hot' => true]]));
        self::assertSame(['Байкал', 'Камчатка'], $this->trips(['where' => ['hot' => false]]));
        self::assertSame(['Алтай', 'Байкал'], $this->trips(['where' => ['state' => ['open', 'full'], 'seats' => ['from' => 8, 'to' => 10]]]));
        self::assertSame(['Байкал', 'Карелия'], $this->trips(['where' => ['start' => ['from' => '2026-11-10', 'to' => '2026-11-30']]]));
        self::assertSame(['Камчатка', 'Алтай', 'Байкал', 'Карелия'], $this->trips(['sort' => '-seats']));
        self::assertSame(['Карелия', 'Байкал', 'Алтай', 'Камчатка'], $this->trips(['sort' => 'seats']));
        self::assertSame(['Байкал', 'Карелия'], $this->trips(['sort' => 'start', 'limit' => 2]));
        self::assertSame(['Алтай', 'Камчатка'], $this->trips(['sort' => 'start', 'offset' => 2]));
        self::assertSame(['Карелия', 'Алтай'], $this->trips(['sort' => 'start', 'offset' => 1, 'limit' => 2]));
    }

    #[Test]
    public function aWrongWhereOrSortKeyNamesTheFieldsThatExist(): void
    {
        $this->seedTrips();
        foreach ([['where' => ['colour' => 'red']], ['sort' => 'colour'], ['where' => ['seats' => ['from' => 'many']]], ['speed' => 1]] as $options) {
            try {
                $this->reader->collection('test_trips', $options);
                self::fail('refused: ' . \json_encode($options));
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        try {
            $this->reader->collection('test_trips', ['where' => ['colour' => 'red']]);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('colour', $e->getMessage());
            self::assertStringContainsString('title, start, seats', $e->getMessage());
        }
        try {
            $this->reader->collection('no_such_collection');
            self::fail('an unknown collection');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('no_such_collection', $e->getMessage());
        }
    }

    #[Test]
    public function aCollectionIsOneQueryThatDoesNotLoadTheDrafts(): void
    {
        $this->seedTrips();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $queries = QueryCounter::during(fn () => $this->reader->collection('test_trips', ['where' => ['state' => 'open'], 'sort' => '-seats', 'limit' => 2]));

        self::assertCount(1, $queries);
        self::assertSame(0, $em->getUnitOfWork()->size());
    }

    #[Test]
    public function twigFunctionsAreTheSameReader(): void
    {
        $ids = $this->blog();
        $this->seedTrips();
        $twig = self::getContainer()->get(Environment::class);

        $out = $twig->createTemplate(<<<'TWIG'
{%- for c in itk_pages({template: 'blog_category'}) %}{{ c.title }}:{{ c.url }};{% endfor -%}
|{{ itk_page(id).title }}|{{ itk_page('nope') is null ? 'none' : 'some' }}
|{% for t in itk_collection('test_trips', {where: {state: 'open'}, sort: '-seats', limit: 2}) %}{{ t.title }}{% if not loop.last %},{% endif %}{% endfor %}
|{{ itk_collection('test_trips')|length }}
TWIG)->render(['id' => $ids['tips']]);

        self::assertSame("Новости:/test-blog/news;Советы:/test-blog/tips;|Советы|none\n|Камчатка,Алтай|4", $out);
    }

    #[Test]
    public function aWrongCallInATemplateThrowsInStrictMode(): void
    {
        $this->seedTrips();
        $twig = self::getContainer()->get(Environment::class);

        try {
            $twig->createTemplate("{{ itk_collection('test_trips', {where: {colour: 'red'}})|length }}")->render([]);
            self::fail('strict: an exception');
        } catch (\Throwable $e) {
            while (null !== $e->getPrevious()) {
                $e = $e->getPrevious();
            }
            self::assertStringContainsString('colour', $e->getMessage());
        }
    }
}
