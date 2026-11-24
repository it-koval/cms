<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ChangeDescriber;
use Itk\Cms\Content\ChangeDetector;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChangeCountPerformanceTest extends KernelTestCase
{
    #[Test]
    public function pageCountsMatchDescriptionsWithoutCallingTheDescriber(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $described = $c->get(ChangeDetector::class);
        // Uninitialised dependencies make any rich-value description fail. Counting must not use it.
        $counter = new ChangeDetector($c->get(Schema::class), $c->get(FieldValues::class), (new \ReflectionClass(ChangeDescriber::class))->newInstanceWithoutConstructor());
        $live = ['title' => 'Live', 'url' => '/live', 'sections' => [], 'fields' => []];
        for ($i = 0; $i < 20; ++$i) {
            $live['sections'][] = ['id' => 's' . $i, 'type' => 'hero', 'data' => ['headline' => 'Live<br>headline']];
        }
        $draft = $live;
        $draft['title'] = 'Draft';
        foreach ($draft['sections'] as &$section) {
            $section['data']['headline'] = 'Draft<br>headline';
        }
        unset($section);
        $variants = [$live, $draft];
        $hidden = $draft;
        $hidden['sections'][0]['hidden'] = true;
        $variants[] = $hidden;
        $removed = $draft;
        \array_pop($removed['sections']);
        $variants[] = $removed;
        $added = $draft;
        $added['sections'][] = ['id' => 'new', 'type' => 'video', 'data' => []];
        $variants[] = $added;
        $moved = $draft;
        $moved['sections'] = \array_reverse($moved['sections']);
        $variants[] = $moved;
        foreach ($variants as $variant) {
            self::assertSame(\count($described->pageChanges($variant, 'home', $live)), $counter->pageChangeCount($variant, 'home', $live));
        }
        self::assertSame(0, $counter->pageChangeCount($draft, 'home', null));
        $start = \hrtime(true);
        for ($i = 0; $i < 200; ++$i) {
            $described->pageChanges($draft, 'home', $live);
        }
        $full = (\hrtime(true) - $start) / 1e6;
        $start = \hrtime(true);
        for ($i = 0; $i < 200; ++$i) {
            $counter->pageChangeCount($draft, 'home', $live);
        }
        \fwrite(\STDERR, \sprintf("\n200 pages x 20 rich sections: descriptions %.1f ms, count %.1f ms; rich descriptions 4000 -> 0\n", $full, (\hrtime(true) - $start) / 1e6));
    }

    #[Test]
    public function collectionCountsMatchIncludingMatrixAndRepeater(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $detector = $c->get(ChangeDetector::class);
        foreach ($c->get(Schema::class)->collections() as $collection) {
            $live = $c->get(FieldValues::class)->defaults($collection->fieldList());
            $draft = $live;
            foreach ($collection->fieldList() as $field) {
                if ('text' === $field->type() || $field->isRich()) {
                    $draft[$field->key()] = 'Changed';
                }
            }
            if ('matrix' === $collection->viewName()) {
                $draft = ['cols' => [['key' => 'x']], 'rows' => [['_id' => 'r', 'x' => 'changed']]];
            }
            self::assertSame(\count($detector->recordChanges($collection, $draft, $live)), $detector->recordChangeCount($collection, $draft, $live));
            self::assertSame(0, $detector->recordChangeCount($collection, $draft, null));
        }
    }
}
