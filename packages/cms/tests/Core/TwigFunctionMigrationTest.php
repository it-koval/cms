<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class TwigFunctionMigrationTest extends TestCase
{
    #[Test]
    public function documentedCommandRenamesOnlyDirectTwigFunctionCalls(): void
    {
        if (null === (new ExecutableFinder())->find('perl')) {
            self::markTestSkipped('The documented migration requires Perl.');
        }

        $root = \dirname(__DIR__, 4);
        $english = $this->command($root . '/UPGRADE.md');
        self::assertSame($english, $this->command($root . '/UPGRADE.ru.md'), 'both guides run exactly the same migration');
        $directory = $root . '/packages/cms/dist/twig-migration-' . \bin2hex(\random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory . '/templates/nested');

        try {
            $fixture = __DIR__ . '/Fixture/twig-function-migration/';
            $before = (string) \file_get_contents($fixture . 'before.txt');
            $expected = (string) \file_get_contents($fixture . 'after.txt');
            \file_put_contents($directory . '/templates/nested/example.twig', $before);
            \file_put_contents($directory . '/templates/second.twig', '{{ menu() }}');
            \file_put_contents($directory . '/templates/unchanged.txt', '{{ menu() }}');

            $process = Process::fromShellCommandline($english, $directory, timeout: 10);
            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame($expected, \file_get_contents($directory . '/templates/nested/example.twig'));
            self::assertSame('{{ itk_menu() }}', \file_get_contents($directory . '/templates/second.twig'));
            self::assertSame('{{ menu() }}', \file_get_contents($directory . '/templates/unchanged.txt'));

            $process->run();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertSame($expected, \file_get_contents($directory . '/templates/nested/example.twig'), 'the command is idempotent');
        } finally {
            $filesystem->remove($directory);
        }
    }

    private function command(string $file): string
    {
        $guide = (string) \file_get_contents($file);
        self::assertSame(1, \preg_match('/```bash\n(find templates -type f .*?)\n```/s', $guide, $matches), $file . ' contains the migration command');

        return $matches[1];
    }
}
