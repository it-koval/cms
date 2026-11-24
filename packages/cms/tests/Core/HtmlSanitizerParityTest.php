<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HtmlSanitizerParityTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function examples(): iterable
    {
        $fixture = \file_get_contents(__DIR__ . '/Fixture/html-sanitizer.json');
        self::assertIsString($fixture);
        /** @var list<array{name: string, mode: string, input: string, expected: string}> $examples */
        $examples = \json_decode($fixture, true, flags: \JSON_THROW_ON_ERROR);
        foreach ($examples as $example) {
            yield $example['name'] => [$example['input'], $example['mode'], $example['expected']];
        }
    }

    #[Test]
    #[DataProvider('examples')]
    public function editorHtmlSurvivesSaving(string $input, string $mode, string $expected): void
    {
        self::bootKernel();
        $sanitizer = self::getContainer()->get(HtmlSanitizer::class);

        self::assertSame($expected, $sanitizer->sanitize($input, $mode));
        self::assertSame($expected, $sanitizer->sanitize($expected, $mode));
    }
}
