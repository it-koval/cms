<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Page;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use Itk\Cms\Tests\Core\Fixture\TreeCollection;
use PHPUnit\Framework\Attributes\Test;

/** Дерево: записи коллекции с tree() и страницы переезжают пачкой (родитель + место), по тем же правилам и через хуки. */
final class TreeApiTest extends ResourceKernelTestCase
{
    protected function prepare(): void
    {
        self::getContainer()->get(ResourceRegistry::class)->add(new TreeCollection()); // before the first service that needs the schema
        parent::prepare();
        TreeCollection::$log = [];
    }

    protected function clean(): void
    {
        $this->signIn('admin');
        self::getContainer()->get(EntityManagerInterface::class)
            ->createQuery('DELETE FROM Itk\Cms\Entity\CollectionItem i WHERE i.collection = :c')->setParameter('c', 'test_tree')->execute();
        parent::clean();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function node(string $title, ?string $parentId = null, array $data = []): array
    {
        return $this->collections->create('test_tree', ['title' => $title, 'parentId' => $parentId] + $data);
    }

    /** @return list<string> titles of the children of a parent (null = top), in the order of the site */
    private function children(?string $parentTitle): array
    {
        $rows = $this->collections->list('test_tree');
        $id = null;
        foreach ($rows as $r) {
            if ($r['title'] === $parentTitle) {
                $id = $r['id'];
            }
        }
        $kids = \array_values(\array_filter($rows, static fn (array $r): bool => ($r['parentId'] ?? null) === $id));

        return \array_column($kids, 'title');
    }

    #[Test]
    public function aRecordIsBornInsideAnotherAndTheListSaysWhoseChildItIs(): void
    {
        $a = $this->node('A');
        $b = $this->node('B', $a['id']);

        self::assertNull($a['parentId']);
        self::assertSame($a['id'], $b['parentId']);
        self::assertSame($a['id'], $this->collections->get('test_tree', $b['id'])['item']['parentId']);
        self::assertSame(['A', 'B'], \array_column($this->collections->list('test_tree'), 'title'));
        self::assertSame($a['id'], $this->collections->list('test_tree')[1]['parentId']);
    }

    #[Test]
    public function aMissingParentIsRefusedAndAFlatCollectionIgnoresTheParent(): void
    {
        try {
            $this->node('Сирота', 'no-such-record');
            self::fail('the parent must exist');
        } catch (ContentException $e) {
            self::assertSame(404, $e->status());
        }

        $trip = $this->trip('Поездка', ['parentId' => 'whatever']);
        self::assertArrayNotHasKey('parentId', $trip, 'test_trips has no tree(): records do not nest');
        try {
            $this->collections->tree('test_trips', []);
            self::fail('a collection without tree() cannot be moved around');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
    }

    #[Test]
    public function aMoveChangesTheParentAndTheOrderOfTheBrothers(): void
    {
        $a = $this->node('A');
        $b = $this->node('B');
        $c = $this->node('C');
        $d = $this->node('D', $a['id']);

        // C and B go under A, before D: A → C, B, D
        $moved = $this->collections->tree('test_tree', [
            ['id' => $c['id'], 'parentId' => $a['id'], 'position' => 0],
            ['id' => $b['id'], 'parentId' => $a['id'], 'position' => 1],
            ['id' => $d['id'], 'parentId' => $a['id'], 'position' => 2],
        ]);

        self::assertSame(2, $moved, 'two records got another parent, D only changed its place');
        self::assertSame(['C', 'B', 'D'], $this->children('A'));
        self::assertSame(['A'], $this->children(null));

        // and out again, to the top level, between nothing and A
        $this->collections->tree('test_tree', [['id' => $d['id'], 'parentId' => null, 'position' => 0], ['id' => $a['id'], 'parentId' => null, 'position' => 1]]);
        self::assertSame(['D', 'A'], $this->children(null));
        self::assertSame(['C', 'B'], $this->children('A'));
    }

    #[Test]
    public function aRecordCannotGoIntoItselfOrIntoItsOwnBranchAndNothingChanges(): void
    {
        $a = $this->node('A');
        $b = $this->node('B', $a['id']);
        $c = $this->node('C', $b['id']);

        foreach ([[$a['id'], $a['id']], [$a['id'], $c['id']], [$b['id'], $c['id']]] as [$id, $parent]) {
            try {
                $this->collections->tree('test_tree', [['id' => $id, 'parentId' => $parent, 'position' => 0]]);
                self::fail('a cycle');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
        self::assertSame(['A'], $this->children(null));
        self::assertSame(['B'], $this->children('A'));
        self::assertSame(['C'], $this->children('B'));

        try {
            $this->collections->tree('test_tree', [['id' => $a['id'], 'parentId' => null, 'position' => 0], ['id' => $a['id'], 'parentId' => null, 'position' => 1]]);
            self::fail('one record named twice');
        } catch (ContentException) {
            self::addToAssertionCount(1);
        }
    }

    #[Test]
    public function aMovedRecordGoesThroughTheHooksOfAnUpdateAndAFailingHookCancelsTheMove(): void
    {
        $a = $this->node('A');
        $b = $this->node('B');
        TreeCollection::$log = [];

        $this->collections->tree('test_tree', [['id' => $b['id'], 'parentId' => $a['id'], 'position' => 0]]);
        self::assertSame(['method:beforeUpdating:parentId', 'method:afterUpdated:B'], TreeCollection::$log);

        $stay = $this->node('no-move');
        try {
            $this->collections->tree('test_tree', [['id' => $stay['id'], 'parentId' => $a['id'], 'position' => 0]]);
            self::fail('beforeUpdating may cancel');
        } catch (ContentException $e) {
            self::assertSame(409, $e->status());
        }
        self::assertContains('no-move', $this->children(null));
    }

    #[Test]
    public function aMoveOnlyNeedsTheRecordsItNamesAndLeavesTheRestWhereTheyAre(): void
    {
        $a = $this->node('A');
        $this->node('B');
        $c = $this->node('C');

        $this->collections->tree('test_tree', [['id' => $c['id'], 'parentId' => $a['id'], 'position' => 0]]);

        self::assertSame(['A', 'B'], $this->children(null));
        self::assertSame(['C'], $this->children('A'));
    }

    #[Test]
    public function deletingARecordLiftsItsChildrenToItsPlace(): void
    {
        $a = $this->node('A');
        $b = $this->node('B', $a['id']);
        $this->node('C', $b['id']);

        $this->collections->remove('test_tree', $b['id']);

        self::assertSame(['A'], $this->children(null));
        self::assertSame(['C'], $this->children('A'));
    }

    /** @return array<string, ?string> id → parentId of the pages made by the test */
    private function placesOf(string ...$ids): array
    {
        $out = [];
        foreach ($this->pages->list() as $row) {
            if (\in_array($row['id'], $ids, true)) {
                $out[$row['id']] = $row['parentId'];
            }
        }

        return $out;
    }

    #[Test]
    public function pagesMoveByTheRulesOfTheirTemplates(): void
    {
        $a = $this->page('default', 'Один', '/test-tree-one');
        $b = $this->page('default', 'Два', '/test-tree-two');
        $c = $this->page('test_tpl', 'Лист', '/test-tree-one/leaf', $a['id']);

        // «Лист» may stand only under an ordinary page: from «Один» to «Два» is fine
        self::assertSame(1, $this->pages->tree([['id' => $c['id'], 'parentId' => $b['id'], 'position' => 0]]));
        self::assertSame($b['id'], $this->placesOf($c['id'])[$c['id']]);

        // to the top level, or into a leaf, it may not
        foreach ([[$c['id'], null], [$a['id'], $c['id']]] as [$id, $parent]) {
            try {
                $this->pages->tree([['id' => $id, 'parentId' => $parent, 'position' => 0]]);
                self::fail('the template rules apply to a drag as well');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
        self::assertSame($b['id'], $this->placesOf($c['id'])[$c['id']]);
        self::assertNull($this->placesOf($a['id'])[$a['id']]);
    }

    #[Test]
    public function aPageCannotGoIntoItsOwnBranchAndTheGuardCanStopTheWholeMove(): void
    {
        $a = $this->page('default', 'Один', '/test-tree-one');
        $b = $this->page('default', 'Два', '/test-tree-one/two', $a['id']);
        $c = $this->page('default', 'Три', '/test-tree-three');

        try {
            $this->pages->tree([['id' => $a['id'], 'parentId' => $b['id'], 'position' => 0]]);
            self::fail('a page cannot go into its own branch');
        } catch (ContentException $e) {
            self::assertStringContainsString('ветки', $e->getMessage());
        }

        $asked = [];
        try {
            $this->pages->tree([['id' => $c['id'], 'parentId' => $a['id'], 'position' => 0]], static function (Page $p) use (&$asked): void {
                $asked[] = $p->id;
                throw ContentException::forbidden('Эту страницу вам менять нельзя');
            });
            self::fail('the guard refuses');
        } catch (ContentException $e) {
            self::assertSame(403, $e->status());
        }
        self::assertSame([$c['id']], $asked, 'the guard is asked for every page that really moves');
        self::assertNull($this->placesOf($c['id'])[$c['id']], 'nothing moved');
    }

    #[Test]
    public function theToursHaveACalendarOverTheirDates(): void
    {
        $tours = self::getContainer()->get(Schema::class)->collection('tours')->toArray();

        self::assertSame([
            ['type' => 'table'],
            ['type' => 'calendar', 'start' => 'dateStart', 'end' => 'dateEnd', 'title' => 'city'],
        ], $tours['views']);
        self::assertFalse($tours['tree']);
    }
}
