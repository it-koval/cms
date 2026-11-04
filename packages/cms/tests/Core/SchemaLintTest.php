<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/** itk:schema:lint и загрузка схемы в debug: ошибки названы, код выхода 1; хорошая схема проходит. */
final class SchemaLintTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', $options['debug'] ?? true, null, '', [], [], $options['itk'] ?? []);
    }

    private function lint(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('itk:schema:lint'));
        $tester->execute([]);

        return $tester;
    }

    #[Test]
    public function aGoodSchemaPasses(): void
    {
        self::bootKernel();
        $tester = $this->lint();

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Схема в порядке', $tester->getDisplay());
    }

    #[Test]
    public function aBrokenSchemaIsReportedWithExitCodeOne(): void
    {
        self::bootKernel(['itk' => ['schema_file' => __DIR__ . '/Fixture/schema_broken.php']]);
        $tester = $this->lint();
        $out = \preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Секция «ghost»: ключ поля «title» повторяется', $out);
        self::assertStringContainsString('Форма «empty»: в форме нет ни одного поля', $out);
        self::assertStringContainsString('Секция «ghost»: нет Twig-шаблона sections/ghost.html.twig', $out);
    }

    #[Test]
    public function aBrokenSchemaStopsTheSiteInDebug(): void
    {
        self::bootKernel(['itk' => ['schema_file' => __DIR__ . '/Fixture/schema_broken.php']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Секция «ghost»: ключ поля «title» повторяется');
        self::getContainer()->get(\Itk\Cms\Schema\Schema::class);
    }

    #[Test]
    public function aBrokenSchemaIsLeftAloneWithoutDebug(): void
    {
        self::bootKernel(['debug' => false, 'itk' => ['schema_file' => __DIR__ . '/Fixture/schema_broken.php']]);

        self::assertNotNull(self::getContainer()->get(\Itk\Cms\Schema\Schema::class)->section('ghost'), 'in prod the schema is not checked on every request: itk:schema:lint does it');
    }
}
