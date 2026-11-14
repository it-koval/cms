<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Twig\ItkExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The Twig tables of both READMEs are complete: every function of ItkExtension is in them. */
final class ReadmeTwigTableTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function readmes(): iterable
    {
        yield 'English' => ['README.md', '| Function | What it does |'];
        yield 'Russian' => ['README.ru.md', '| Функция | Что делает |'];
    }

    #[Test]
    #[DataProvider('readmes')]
    public function everyTwigFunctionIsInTheReadmeTable(string $file, string $header): void
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__, 2) . '/' . $file);
        $at = \strpos($readme, $header);
        self::assertNotFalse($at, $file . ' has the Twig functions table');
        $table = \substr($readme, $at, 20000);
        $table = \substr($table, 0, (int) \strpos($table, "\n\n"));

        $missing = [];
        foreach ((new ItkExtension())->getFunctions() as $function) {
            if (!\str_contains($table, '`' . $function->getName() . '(') && !\str_contains($table, '`' . $function->getName() . '`')) {
                $missing[] = $function->getName();
            }
        }

        self::assertSame([], $missing, 'functions that the ' . $file . ' table does not show');
    }
}
