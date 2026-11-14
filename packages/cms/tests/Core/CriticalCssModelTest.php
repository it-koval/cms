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
        $row->markReady($wanted->value, 'inputs', [['media' => null, 'css' => 'a{}']], $at, 'stamp');
        self::assertSame(Freshness::Ready, Freshness::of($row, $wanted));
        self::assertSame(Freshness::Stale, Freshness::of($row, $other));

        $row->markFailed($wanted->value, 'inputs', 'boom', $at);
        self::assertSame(Freshness::Failed, Freshness::of($row, $wanted));
        self::assertSame(Freshness::Stale, Freshness::of($row, $other));
        self::assertNull($row->styles, 'a failed row has no styles');
    }

    #[Test]
    public function onlyReadyAndStaleRowsMayBeInlined(): void
    {
        self::assertTrue(Freshness::Ready->inlines());
        self::assertTrue(Freshness::Stale->inlines());
        self::assertFalse(Freshness::Missing->inlines());
        self::assertFalse(Freshness::Failed->inlines());
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

    /** @param list<string> $runs */
    private static function merged(array $runs, int $max = 75_000): CriticalStyles
    {
        $viewports = [];
        foreach ($runs as $i => $_) {
            $viewports[] = new Viewport(400 + $i * 400, 800);
        }
        $result = CriticalStyles::fromRuns($viewports, $runs, $max);
        self::assertInstanceOf(CriticalStyles::class, $result);

        return $result;
    }

    #[Test]
    public function identicalRunsGiveOneBlockWithoutMedia(): void
    {
        self::assertSame([['media' => null, 'css' => 'a{x:1}']], self::merged(['a{x:1}', 'a{x:1}', 'a{x:1}'])->toStored());
    }

    #[Test]
    public function theUnionHasEveryRuleOnceInSourceOrder(): void
    {
        $common = '.a{x:1}.b{x:2}.d{x:4}';
        $mobile = '.a{x:1}.b{x:2}.d{x:4}';
        $desktop = '.a{x:1}.b{x:2}.card p{y:1}.d{x:4}';

        self::assertSame([['media' => null, 'css' => '.a{x:1}.b{x:2}.card p{y:1}.d{x:4}']], self::merged([$mobile, $desktop])->toStored());
        self::assertSame([['media' => null, 'css' => '.a{x:1}.b{x:2}.card p{y:1}.d{x:4}']], self::merged([$desktop, $common, $mobile])->toStored());
    }

    #[Test]
    public function aStatementOnlyOneViewportHasStillComesBeforeTheRules(): void
    {
        $phone = '.r2{c:2}.r3{c:3}';
        $desktop = '@import url(/fonts.css);.r1{c:1}.r3{c:3}';

        $css = self::merged([$phone, $desktop])->toStored()[0]['css'];

        self::assertStringStartsWith('@import url(/fonts.css);', $css);
    }

    #[Test]
    public function aStrayClosingBraceFallsBackToBlocksPerRange(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(1280, 900)];
        $result = CriticalStyles::fromRuns($viewports, ['.a{x:1}}.b{x:1}', '.b{x:1}'], 75_000);

        self::assertInstanceOf(CriticalStyles::class, $result);
        self::assertCount(2, $result->toStored());
    }

    #[Test]
    public function mediaBlocksWithDifferentChildrenMergeIntoOneBlockWithTheUnionOfTheChildren(): void
    {
        $phone = '.a{x:1}@media (max-width:760px){.m{x:1}.n{x:2}}.z{x:9}';
        $desktop = '.a{x:1}@media  (max-width:760px){.m{x:1}.o{x:3}.n{x:2}}.z{x:9}';

        self::assertSame([['media' => null, 'css' => '.a{x:1}@media (max-width:760px){.m{x:1}.o{x:3}.n{x:2}}.z{x:9}']], self::merged([$phone, $desktop])->toStored());
    }

    #[Test]
    public function twoSeparateMediaBlocksWithTheSamePreludeStayApart(): void
    {
        $first = '@media (max-width:760px){.a{x:1}}.b{x:2}@media (max-width:760px){.c{x:3}}';
        $second = '@media (max-width:760px){.a{x:1}}.b{x:2}.extra{x:0}@media (max-width:760px){.c{x:3}}';

        self::assertSame([['media' => null, 'css' => '@media (max-width:760px){.a{x:1}}.b{x:2}.extra{x:0}@media (max-width:760px){.c{x:3}}']], self::merged([$first, $second])->toStored());
    }

    #[Test]
    public function stringsAndCommentsWithBracesDoNotBreakTheSplit(): void
    {
        $a = '.a::after{content:"}{;"}/* } { */.b[title="}"]{x:1}@import url("a;b.css");';
        $b = ".a::after{content:\"}{;\"}.c{content:'\\'}'}.b[title=\"}\"]{x:1}@import url(\"a;b.css\");";

        $css = self::merged([$a, $b])->toStored()[0]['css'];

        self::assertSame(1, \substr_count($css, '.a::after'));
        self::assertSame(1, \substr_count($css, '.b[title="}"]{x:1}'));
        self::assertSame(1, \substr_count($css, '@import url("a;b.css");'));
        self::assertStringContainsString(".c{content:'\\'}'}", $css);
        self::assertLessThan(\strpos($css, '.b['), \strpos($css, '.c{'));
    }

    #[Test]
    public function aConflictingOrderFallsBackToOneBlockPerMediaRange(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(768, 1024), new Viewport(1280, 900)];
        $result = CriticalStyles::fromRuns($viewports, ['a{x:1}b{x:1}', 'a{x:1}b{x:1}', 'b{x:1}a{x:1}'], 75_000);

        self::assertInstanceOf(CriticalStyles::class, $result);
        self::assertSame([['media' => '(max-width:1279.98px)', 'css' => 'a{x:1}b{x:1}'], ['media' => '(min-width:1280px)', 'css' => 'b{x:1}a{x:1}']], $result->toStored());
    }

    #[Test]
    public function anUnbalancedRunFallsBackToOneBlockPerMediaRange(): void
    {
        $result = CriticalStyles::fromRuns([new Viewport(390, 844), new Viewport(1280, 900)], ['a{x:1}', 'a{x:1}b{x:1'], 75_000);

        self::assertInstanceOf(CriticalStyles::class, $result);
        self::assertSame(['(max-width:1279.98px)', '(min-width:1280px)'], \array_column($result->toStored(), 'media'));
    }

    #[Test]
    public function aStraySemicolonOrAnEmptySelectorFallsBackToOneBlockPerMediaRange(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(1280, 900)];
        $result = CriticalStyles::fromRuns($viewports, ['a{x:1};b{x:2}', 'a{x:1}b{x:2}'], 75_000);

        self::assertInstanceOf(CriticalStyles::class, $result);
        self::assertSame(['(max-width:1279.98px)', '(min-width:1280px)'], \array_column($result->toStored(), 'media'));
    }

    #[Test]
    public function anUnquotedUrlWithABraceDoesNotSplitTheRule(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(1280, 900)];
        $result = CriticalStyles::fromRuns($viewports, ['.a{background:url(data:x,a})}.b{x:1}', '.b{x:1}'], 75_000);

        self::assertInstanceOf(CriticalStyles::class, $result);
        self::assertSame([['media' => null, 'css' => '.a{background:url(data:x,a})}.b{x:1}']], $result->toStored());
    }

    #[Test]
    public function theMergedCssAboveTheLimitIsRefusedEvenWhenEveryRunFits(): void
    {
        $viewports = [new Viewport(390, 844), new Viewport(1280, 900)];

        self::assertSame('too large', CriticalStyles::fromRuns($viewports, ['a{x:1}b{x:1}', 'a{x:1}c{x:1}'], 14));
        self::assertInstanceOf(CriticalStyles::class, CriticalStyles::fromRuns($viewports, ['a{x:1}b{x:1}', 'a{x:1}c{x:1}'], 18));
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
