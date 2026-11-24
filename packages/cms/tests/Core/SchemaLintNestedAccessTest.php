<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Command\SchemaLintCommand;
use Itk\Cms\Extension\Registry;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Schema\SchemaLint;
use Itk\Cms\Schema\SchemaLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SchemaLintNestedAccessTest extends TestCase
{
    protected function tearDown(): void
    {
        I18n::shutdown();
        I18n::useLocale('ru');
    }

    /** @return iterable<string, array{string, string}> */
    public static function locales(): iterable
    {
        yield 'English' => ['en', 'top-level'];
        yield 'Russian' => ['ru', 'верхнего уровня'];
        yield 'Kazakh' => ['kk', 'жоғарғы деңгейдегі'];
    }

    #[Test]
    #[DataProvider('locales')]
    public function nestedAccessDeclarationsWarnWithTheirPathsAndTopLevelGuidance(string $locale, string $guidance): void
    {
        I18n::shutdown();
        I18n::useLocale($locale);
        $lint = $this->lint();
        $result = $lint->run();

        self::assertSame([], $result['errors']);
        self::assertCount(20, $result['warnings'], 'each nested field warns once, including nested repeaters with both flags');
        foreach (['nested_section', 'nested_template', 'nested_collection', 'nested_settings'] as $owner) {
            foreach (['rows.secret' => ['canSee()'], 'rows.locked' => ['readonly()'], 'rows.both' => ['canSee()', 'readonly()'], 'rows.children' => ['canSee()', 'readonly()'], 'rows.children.deep' => ['canSee()', 'readonly()']] as $path => $flags) {
                $matches = \array_values(\array_filter($result['warnings'], static fn (string $warning): bool => \str_contains($warning, $owner) && \str_contains($warning, '«' . $path . '»')));
                self::assertCount(1, $matches, $owner . ': ' . $path);
                self::assertStringContainsString($guidance, $matches[0]);
                self::assertStringContainsString('«rows»', $matches[0], 'guidance identifies the containing top-level field');
                foreach (['canSee()', 'readonly()'] as $flag) {
                    if (\in_array($flag, $flags, true)) {
                        self::assertStringContainsString($flag, $matches[0]);
                    } else {
                        self::assertStringNotContainsString($flag, $matches[0]);
                    }
                }
            }
        }
        $warnings = \implode("\n", $result['warnings']);
        foreach (['top_secret', 'top_locked', 'rows.plain', 'rows.unlocked', 'rows.note', 'model.lint_'] as $absent) {
            self::assertStringNotContainsString($absent, $warnings);
        }

        $command = new CommandTester(new SchemaLintCommand($lint));
        $command->execute([]);
        self::assertSame(0, $command->getStatusCode(), 'unsupported nested flags are warnings, not schema errors');
        self::assertStringContainsString('rows.children.deep', $command->getDisplay());
        self::assertStringContainsString($guidance, (string) \preg_replace('/\s+/', ' ', $command->getDisplay()));
    }

    private function lint(): SchemaLint
    {
        $loader = new SchemaLoader(__DIR__ . '/Fixture/schema_nested_access.php', 'ru', new ResourceRegistry([], new Registry()), blog: false);
        $templates = ['sections/nested_section.html.twig' => ''];
        foreach ($loader->build()->templates() as $key => $_) {
            $templates['pages/' . $key . '.html.twig'] = '';
        }

        return new SchemaLint($loader, new Environment(new ArrayLoader($templates)));
    }
}
