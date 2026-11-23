<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Support\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function slugCases(): iterable
    {
        $cases = \json_decode(\file_get_contents(__DIR__ . '/../Fixtures/slug-cases.json'), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case['in'] => [$case['in'], $case['max'], $case['out']];
        }
    }

    #[Test]
    #[DataProvider('slugCases')]
    public function transliteratesWithTheSharedSlugRules(string $text, int $max, string $expected): void
    {
        self::assertSame($expected, Slug::slugify($text, $max));
    }
}
