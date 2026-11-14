<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\TreeMove;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Арифметика переноса узлов дерева (страницы и записи коллекций): родитель, место среди «братьев», запреты. */
final class TreeMoveTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: int}> a → (b, c), d; b → e */
    private function tree(): array
    {
        return ['a' => [null, 0], 'd' => [null, 1], 'b' => ['a', 0], 'c' => ['a', 1], 'e' => ['b', 0]];
    }

    #[Test]
    public function aNodeMovesToAnotherParentAndTheBrothersAreNumberedAgain(): void
    {
        $plan = TreeMove::resolve($this->tree(), ['d' => ['a', 1]]);

        self::assertSame(['d'], $plan['moved']);
        self::assertSame('a', $plan['parent']['d']);
        self::assertEquals(['b' => 0, 'd' => 1, 'c' => 2], \array_intersect_key($plan['position'], ['b' => 0, 'c' => 0, 'd' => 0]), 'the named node goes before the unnamed one with the same place');
        self::assertSame(0, $plan['position']['a'], 'the top level lost a node and is numbered anew');
    }

    #[Test]
    public function aNodeChangesItsPlaceAmongTheBrothersWithoutChangingTheParent(): void
    {
        $plan = TreeMove::resolve($this->tree(), ['c' => ['a', 0], 'b' => ['a', 1]]);

        self::assertSame([], $plan['moved']);
        self::assertSame(0, $plan['position']['c']);
        self::assertSame(1, $plan['position']['b']);
        self::assertEqualsCanonicalizing(['b', 'c'], $plan['reordered']);
    }

    #[Test]
    public function aNodeToTheTopLevelLeavesItsOldBrothersNumbered(): void
    {
        $plan = TreeMove::resolve($this->tree(), ['c' => [null, 0]]);

        self::assertNull($plan['parent']['c']);
        self::assertEquals(['c' => 0, 'a' => 1, 'd' => 2], \array_intersect_key($plan['position'], ['a' => 0, 'c' => 0, 'd' => 0]));
        self::assertSame(0, $plan['position']['b'], 'the old parent now has one child, numbered from 0');
    }

    #[Test]
    public function aNodeCannotGoIntoItselfOrIntoItsOwnBranch(): void
    {
        foreach ([['a' => ['a', 0]], ['a' => ['e', 0]], ['b' => ['e', 0]]] as $want) {
            try {
                TreeMove::resolve($this->tree(), $want);
                self::fail('a cycle must be refused: ' . \json_encode($want));
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
    }

    #[Test]
    public function anUnknownNodeOrParentIsRefusedWithAReadableMessage(): void
    {
        try {
            TreeMove::resolve($this->tree(), ['zzz' => [null, 0]]);
            self::fail('unknown node');
        } catch (ContentException $e) {
            self::assertSame(404, $e->status());
        }
        try {
            TreeMove::resolve($this->tree(), ['d' => ['zzz', 0]]);
            self::fail('unknown parent');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
            self::assertStringContainsString('обновите страницу', $e->getMessage());
        }
    }

    #[Test]
    public function aMoveThatChangesNothingReportsNothing(): void
    {
        $plan = TreeMove::resolve($this->tree(), ['d' => [null, 1]]);

        self::assertSame([], $plan['moved']);
        self::assertSame([], $plan['reordered']);
    }
}
