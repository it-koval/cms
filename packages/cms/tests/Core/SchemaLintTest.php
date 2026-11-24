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

    #[Test]
    public function aFormWithoutMaxWarnsWithoutFailingLint(): void
    {
        self::bootKernel(['itk' => ['schema_file' => __DIR__ . '/Fixture/schema_f1_input.php']]);
        $tester = $this->lint();
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('f1_input', $tester->getDisplay());
        self::assertStringContainsString('1000', $tester->getDisplay());
        $warnings = self::getContainer()->get(\Itk\Cms\Schema\SchemaLint::class)->run()['warnings'];
        self::assertTrue((bool) \array_filter($warnings, static fn (string $warning): bool => \str_contains($warning, 'f1_input') && \str_contains($warning, 'text')));
        self::assertFalse((bool) \array_filter($warnings, static fn (string $warning): bool => \str_contains($warning, 'f1_input') && (\str_contains($warning, 'explicit') || \str_contains($warning, 'consent'))));
    }

    #[Test]
    public function healthShowsMissingFormLimitsWithoutLocaleChecks(): void
    {
        self::bootKernel(['itk' => ['schema_file' => __DIR__ . '/Fixture/schema_f1_input.php']]);
        $report = self::getContainer()->get(\Itk\Cms\Health\HealthService::class)->report(includeLocaleChecks: false);
        $warnings = \array_filter($report['checks'], static fn (array $check): bool => 'warn' === $check['status'] && \str_contains($check['message'], 'f1_input') && \str_contains($check['message'], 'text') && \str_contains($check['message'], '1000'));
        self::assertNotEmpty($warnings);
    }

    #[Test]
    public function doctorReportsTheSchemaWarningOnceInTheSchemaGroup(): void
    {
        self::bootKernel(['itk' => ['schema_file' => __DIR__ . '/Fixture/schema_f1_input.php']]);
        $name = \Itk\Cms\I18n\I18n::t('ops.doctor_name_schema_warnings');
        $lines = self::getContainer()->get(\Itk\Cms\Health\Doctor::class)->run();
        $warnings = \array_values(\array_filter($lines, static fn (array $line): bool => $name === $line['name']));
        self::assertCount(1, $warnings, 'the tagged health check must not duplicate the direct schema check');
        self::assertSame(\Itk\Cms\I18n\I18n::t('ops.doctor_group_schema'), $warnings[0]['group']);
        self::assertSame('warn', $warnings[0]['status']);
        self::assertStringContainsString('f1_input', $warnings[0]['message']);
        self::assertStringContainsString('text', $warnings[0]['message']);
        self::assertStringContainsString('1000', $warnings[0]['message']);
    }
}
