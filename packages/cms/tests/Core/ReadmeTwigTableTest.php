<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Twig\ItkExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Таблица Twig в README полная: каждая функция ItkExtension в ней есть. */
final class ReadmeTwigTableTest extends TestCase
{
    #[Test]
    public function everyTwigFunctionIsInTheReadmeTable(): void
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        $table = \substr($readme, (int) \strpos($readme, '| Функция | Что делает |'), 20000);
        $table = \substr($table, 0, (int) \strpos($table, "\n\n"));

        $missing = [];
        foreach ((new ItkExtension())->getFunctions() as $function) {
            if (!\str_contains($table, '`' . $function->getName() . '(') && !\str_contains($table, '`' . $function->getName() . '`')) {
                $missing[] = $function->getName();
            }
        }

        self::assertSame([], $missing, 'functions that the README table does not show');
    }
}
