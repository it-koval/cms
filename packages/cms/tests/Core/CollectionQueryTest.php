<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\CollectionQuery;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Field\Text;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Список коллекции силами базы: поиск, фильтры, сортировка (в т. ч. по числам внутри JSON), страницы, фасеты, метрики. */
final class CollectionQueryTest extends ResourceKernelTestCase
{
    private CollectionQuery $query;
    private Schema $schema;

    protected function prepare(): void
    {
        parent::prepare();
        $this->query = self::getContainer()->get(CollectionQuery::class);
        $this->schema = self::getContainer()->get(Schema::class);
        foreach ([
            ['Алтай', '2026-12-01', 10, 'open', true, 'mid'],
            ['Байкал', '2026-11-15', 8, 'full', false, 'easy'],
            ['Камчатка', '2027-01-10', 12, 'open', false, 'hard'],
            ['Карелия', '2026-11-20', 6, 'open', true, 'easy'],
            ['Сочи', '2026-12-20', 20, 'full', false, 'easy'],
            ['Урал', '2026-11-05', 9, 'open', false, 'mid'],
            ['Эльбрус', '2026-10-01', 4, 'full', false, 'hard'],
        ] as [$title, $start, $seats, $state, $hot, $level]) {
            $this->trip($title, ['start' => $start, 'seats' => $seats, 'state' => $state, 'hot' => $hot, 'level' => $level]);
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function titles(array $params, ?int &$total = null): array
    {
        $r = $this->query->run($this->schema->collection('test_trips'), $params + ['perPage' => 50], 'ru');
        $total = $r['total'];

        return \array_column($r['items'], 'title');
    }

    #[Test]
    public function itPagesInTheDefaultOrderOfTheClass(): void
    {
        $r = $this->query->run($this->schema->collection('test_trips'), ['page' => 1], 'ru');
        self::assertSame(7, $r['total']);
        self::assertSame([1, 3], [$r['page'], $r['perPage']], 'perPage() of the class');
        self::assertSame(['Эльбрус', 'Урал', 'Байкал'], \array_column($r['items'], 'title'));

        $p2 = $this->query->run($this->schema->collection('test_trips'), ['page' => 2], 'ru');
        self::assertSame(['Карелия', 'Алтай', 'Сочи'], \array_column($p2['items'], 'title'));
        $p3 = $this->query->run($this->schema->collection('test_trips'), ['page' => 3], 'ru');
        self::assertSame(['Камчатка'], \array_column($p3['items'], 'title'));
    }

    #[Test]
    public function perPageIsCapped(): void
    {
        self::assertSame(200, $this->query->run($this->schema->collection('test_trips'), ['perPage' => 9999], 'ru')['perPage']);
        self::assertSame(1, $this->query->run($this->schema->collection('test_trips'), ['perPage' => 1], 'ru')['perPage']);
    }

    #[Test]
    public function itSortsNumbersAsNumbersAndTextAsText(): void
    {
        self::assertSame(['Сочи', 'Камчатка', 'Алтай', 'Урал', 'Байкал', 'Карелия', 'Эльбрус'], $this->titles(['sort' => '-seats']));
        self::assertSame(['Эльбрус', 'Карелия', 'Байкал', 'Урал', 'Алтай', 'Камчатка', 'Сочи'], $this->titles(['sort' => 'seats']));
        self::assertSame(['Алтай', 'Байкал', 'Камчатка', 'Карелия', 'Сочи', 'Урал', 'Эльбрус'], $this->titles(['sort' => 'title']));
        self::assertSame(['Камчатка', 'Сочи', 'Алтай'], \array_slice($this->titles(['sort' => '-start']), 0, 3));
    }

    #[Test]
    public function aNumericStringWithAnExponentSortsAsANumber(): void
    {
        $db = self::getContainer()->get(Connection::class);
        foreach (['Эльбрус' => '2.5E1', 'Урал' => '1.5e+1', 'Карелия' => '1e-1'] as $title => $seats) {
            $db->executeStatement("UPDATE itk_collection_item SET translations = jsonb_set(translations, '{ru,draft,seats}', to_jsonb(CAST(? AS text))) WHERE translations #>> '{ru,draft,title}' = ?", [$seats, $title]);
        }

        self::assertSame(['Эльбрус', 'Сочи', 'Урал', 'Камчатка', 'Алтай', 'Байкал', 'Карелия'], $this->titles(['sort' => '-seats']));
    }

    #[Test]
    public function aSecondSortKeyBreaksTies(): void
    {
        // full: Эльбрус 4, Байкал 8, Сочи 20; open: Карелия 6, Урал 9, Алтай 10, Камчатка 12
        self::assertSame(['Эльбрус', 'Байкал', 'Сочи', 'Карелия', 'Урал', 'Алтай', 'Камчатка'], $this->titles(['sort' => 'state,seats']));
        self::assertSame(['Камчатка', 'Алтай', 'Урал', 'Карелия', 'Сочи', 'Байкал', 'Эльбрус'], $this->titles(['sort' => '-state,-seats']));
    }

    #[Test]
    public function itFiltersByEveryKind(): void
    {
        self::assertSame(['Алтай', 'Камчатка', 'Карелия', 'Урал'], $this->titles(['filter' => ['state' => 'open'], 'sort' => 'title'], $total));
        self::assertSame(4, $total);
        self::assertSame(['Алтай', 'Карелия'], $this->titles(['filter' => ['state' => 'open', 'hot' => '1'], 'sort' => 'title']), 'toggle');
        self::assertSame(['Алтай', 'Байкал', 'Урал'], $this->titles(['filter' => ['seats' => ['from' => '8', 'to' => '10']], 'sort' => 'title']), 'numberRange is numeric, not textual');
        self::assertSame(['Алтай', 'Байкал', 'Карелия'], $this->titles(['filter' => ['start' => ['from' => '2026-11-15', 'to' => '2026-12-01']], 'sort' => 'title']), 'dateRange');
        self::assertSame(['Байкал', 'Камчатка', 'Сочи', 'Урал', 'Эльбрус'], $this->titles(['filter' => ['hot' => '0'], 'sort' => 'title']), 'toggle off includes records that never had it');
        self::assertSame(['Алтай', 'Камчатка', 'Сочи'], $this->titles(['filter' => ['seats' => ['from' => '10']], 'sort' => 'title']), 'one edge of a range is enough');
    }

    #[Test]
    public function aMultiFilterTakesSeveralValuesAsAListOrCommaSeparated(): void
    {
        self::assertSame(['Алтай', 'Камчатка', 'Урал', 'Эльбрус'], $this->titles(['filter' => ['level' => ['mid', 'hard']], 'sort' => 'title']));
        self::assertSame(['Алтай', 'Камчатка', 'Урал', 'Эльбрус'], $this->titles(['filter' => ['level' => 'mid,hard'], 'sort' => 'title']));
        self::assertSame(['Байкал', 'Карелия', 'Сочи'], $this->titles(['filter' => ['level' => 'easy'], 'sort' => 'title']));
    }

    #[Test]
    public function theSearchBoxIgnoresCaseAndLooksOnlyInSearchableFields(): void
    {
        self::assertSame(['Байкал', 'Камчатка', 'Карелия'], $this->titles(['q' => 'ка', 'sort' => 'title']));
        self::assertSame(['Байкал', 'Камчатка', 'Карелия'], $this->titles(['q' => 'КА', 'sort' => 'title']));
        self::assertSame([], $this->titles(['q' => 'open']), 'the state field is not searchable');
        self::assertSame(['Алтай'], $this->titles(['q' => 'алт']));
        // % and _ are plain characters
        self::assertSame([], $this->titles(['q' => '%']));
    }

    #[Test]
    public function searchingWhenEverySearchFieldIsHiddenReturnsNoRecords(): void
    {
        $collection = (new Collection('test_trips', 'Trips'))->fields(
            Text::make('Title', 'title'),
            Text::make('Secret', 'secret')->searchable()->canSee('set:users'),
        );
        $this->signIn('editor');
        $result = $this->query->run($collection, ['q' => 'text'], 'ru');
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['items']);
    }

    #[Test]
    public function theStatusIsAFilterOfEveryCollection(): void
    {
        $ids = \array_column($this->collections->list('test_trips', 'Алтай'), 'id');
        $this->collections->publish('test_trips', $ids[0]);
        self::assertSame(['Алтай'], $this->titles(['filter' => ['status' => 'published']]));
        self::assertCount(6, $this->titles(['filter' => ['status' => 'draft']]));
    }

    #[Test]
    public function itCountsValuesForTheFilters(): void
    {
        $r = $this->query->run($this->schema->collection('test_trips'), ['page' => 1], 'ru');
        self::assertSame(['open' => 4, 'full' => 3], $r['facets']['state']);
        self::assertSame(['mid' => 2, 'easy' => 3, 'hard' => 2], $r['facets']['level']);
        self::assertSame(2, $r['facets']['hot']['true']);
        self::assertSame(['draft' => 7], $r['facets']['status']);
    }

    #[Test]
    public function itCalculatesTheMetrics(): void
    {
        $r = $this->query->run($this->schema->collection('test_trips'), ['page' => 1], 'ru');
        self::assertSame([
            ['label' => 'Всего', 'kind' => 'count', 'value' => 7],
            ['label' => 'Открыто', 'kind' => 'count', 'value' => 4],
            ['label' => 'Горячих', 'kind' => 'count', 'value' => 2],
            ['label' => 'Мест', 'kind' => 'sum', 'value' => 69],
        ], $r['metrics']);
    }

    #[Test]
    public function aBadFilterOrSortIsRefused(): void
    {
        foreach ([['filter' => ['secret' => 'x']], ['filter' => ['nope' => 'x']], ['sort' => 'note'], ['sort' => 'nope'], ['filter' => ['start' => ['from' => 'вчера']]], ['filter' => ['seats' => ['from' => 'много']]]] as $params) {
            try {
                $this->titles($params);
                self::fail('refused: ' . \json_encode($params));
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    #[Test]
    public function aValueIsNeverPartOfTheQuery(): void
    {
        self::assertSame([], $this->titles(['filter' => ['state' => "x' OR '1'='1"]]));
        self::assertSame([], $this->titles(['q' => "' OR 1=1 --"]));
        self::assertSame(7, \count($this->titles([])));
    }

    #[Test]
    public function theOldListIsTheSame(): void
    {
        self::assertCount(7, $this->collections->list('test_trips'));
        self::assertCount(1, $this->collections->list('test_trips', 'байк'));
    }
}
