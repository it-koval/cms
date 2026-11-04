<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\CriticalCss\Model\Basis;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\CriticalCss\Model\Freshness;
use Itk\Cms\CriticalCss\Model\Viewport;
use Itk\Cms\CriticalCss\Model\When;
use Itk\Cms\Entity\CriticalCss;
use Itk\Cms\Entity\Page;
use Itk\Cms\Entity\PageTranslation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Состояние критического CSS страницы выводится из строки и текущей основы, а политика повторов и диапазоны media заданы одной таблицей. */
final class CriticalCssModelTest extends TestCase
{
    private function row(): CriticalCss
    {
        return CriticalCss::for(new PageTranslation(new Page(), 'ru'));
    }

    #[Test]
    public function freshnessFollowsTheRowAndTheWantedBasis(): void
    {
        $wanted = Basis::of(['title' => 'A'], 'build-1', 'css-1');
        $other = Basis::of(['title' => 'A'], 'build-2', 'css-1');
        $at = new \DateTimeImmutable();

        self::assertSame(Freshness::Missing, Freshness::of(null, $wanted));

        $row = $this->row();
        $row->markReady($wanted->value, 'inputs', [['media' => null, 'css' => 'a{}']], $at);
        self::assertSame(Freshness::Ready, Freshness::of($row, $wanted));
        self::assertSame(Freshness::Stale, Freshness::of($row, $other));

        $row->markFailed($wanted->value, 'inputs', 'boom', $at);
        self::assertSame(Freshness::Failed, Freshness::of($row, $wanted));
        self::assertSame(Freshness::Stale, Freshness::of($row, $other));
        self::assertNull($row->styles, 'a failed row has no styles');
    }

    #[Test]
    public function theBasisIgnoresKeyOrderAndFollowsEveryInput(): void
    {
        $a = Basis::of(['title' => 'A', 'url' => '/a'], 'b', 'c');

        self::assertTrue($a->equals(Basis::of(['url' => '/a', 'title' => 'A'], 'b', 'c')));
        self::assertFalse($a->equals(Basis::of(['title' => 'B', 'url' => '/a'], 'b', 'c')));
        self::assertFalse($a->equals(Basis::of(['title' => 'A', 'url' => '/a'], 'b2', 'c')));
        self::assertFalse($a->equals(Basis::of(['title' => 'A', 'url' => '/a'], 'b', 'c2')));
        self::assertSame(40, \strlen($a->value));
    }

    #[Test]
    public function whenIsTheWholeRetryPolicy(): void
    {
        self::assertTrue(When::Outdated->admits(Freshness::Missing));
        self::assertTrue(When::Outdated->admits(Freshness::Stale));
        self::assertFalse(When::Outdated->admits(Freshness::Failed));
        self::assertFalse(When::Outdated->admits(Freshness::Ready));

        self::assertTrue(When::NotReady->admits(Freshness::Failed));
        self::assertFalse(When::NotReady->admits(Freshness::Ready));

        self::assertTrue(When::Always->admits(Freshness::Ready));
        self::assertFalse(When::Always->reusesIdenticalInputs());
        self::assertTrue(When::Outdated->reusesIdenticalInputs());

        self::assertSame(When::Always, When::Outdated->widest(When::Always));
        self::assertSame(When::NotReady, When::NotReady->widest(When::Outdated));
    }

    #[Test]
    public function mediaRangesComeFromTheSortedWidthsAlone(): void
    {
        self::assertSame([null], Viewport::mediaRanges([new Viewport(1280, 900)]));
        self::assertSame(['(max-width:1279.98px)', '(min-width:1280px)'], Viewport::mediaRanges([new Viewport(390, 844), new Viewport(1280, 900)]));
        self::assertSame(
            ['(max-width:767.98px)', '(min-width:768px) and (max-width:1279.98px)', '(min-width:1280px)'],
            Viewport::mediaRanges([new Viewport(390, 844), new Viewport(768, 1024), new Viewport(1280, 900)]),
        );
    }

    #[Test]
    public function identicalAdjacentRunsMergeIntoOneBlock(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(768, 1024), new Viewport(1280, 900)];

        $same = CriticalStyles::fromRuns($viewports, ['a{x:1}', 'a{x:1}', 'a{x:1}'], 75_000);
        self::assertInstanceOf(CriticalStyles::class, $same);
        self::assertSame([['media' => null, 'css' => 'a{x:1}']], $same->toStored());

        $twoAndOne = CriticalStyles::fromRuns($viewports, ['a{x:1}', 'a{x:1}', 'a{x:2}'], 75_000);
        self::assertInstanceOf(CriticalStyles::class, $twoAndOne);
        self::assertSame([['media' => '(max-width:1279.98px)', 'css' => 'a{x:1}'], ['media' => '(min-width:1280px)', 'css' => 'a{x:2}']], $twoAndOne->toStored());

        $oneAndTwo = CriticalStyles::fromRuns($viewports, ['a{x:1}', 'a{x:2}', 'a{x:2}'], 75_000);
        self::assertInstanceOf(CriticalStyles::class, $oneAndTwo);
        self::assertSame([['media' => '(max-width:767.98px)', 'css' => 'a{x:1}'], ['media' => '(min-width:768px)', 'css' => 'a{x:2}']], $oneAndTwo->toStored());

        $all = CriticalStyles::fromRuns($viewports, ['a{x:1}', 'a{x:2}', 'a{x:3}'], 75_000);
        self::assertInstanceOf(CriticalStyles::class, $all);
        self::assertSame(['(max-width:767.98px)', '(min-width:768px) and (max-width:1279.98px)', '(min-width:1280px)'], \array_column($all->toStored(), 'media'));
    }

    #[Test]
    public function anEmptyOrOversizedRunIsARefusalNotAResult(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(1280, 900)];

        self::assertSame('empty', CriticalStyles::fromRuns($viewports, ['a{x:1}', '  '], 75_000));
        self::assertSame('too large', CriticalStyles::fromRuns($viewports, ['a{x:1}', \str_repeat('a', 11)], 10));
        self::assertSame('bad output', CriticalStyles::fromRuns($viewports, ['a{x:1}'], 75_000));
    }
}
