<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Tests\App\RecordingLogger;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;

/** itk_cms.strict: ошибка шаблона сайта (чужой ключ поля, неизвестный тип секции, нет шаблона секции) в debug — исключение со списком допустимого, в prod — предупреждение в лог. */
final class StrictModeTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', $options['debug'] ?? true, null, '', [], [], ($options['itk'] ?? []) + ['schema_file' => __DIR__ . '/Fixture/schema_ghost.php']);
    }

    /** @param array<array-key, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        return self::getContainer()->get(Environment::class)->createTemplate($template)->render($context);
    }

    private function logger(): RecordingLogger
    {
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        return $logger;
    }

    /**
     * The message of the exception a template throws (the innermost one: Twig wraps it).
     *
     * @param array<array-key, mixed> $context
     */
    private function thrown(string $template, array $context = []): string
    {
        try {
            $this->render($template, $context);
        } catch (\Throwable $e) {
            while (null !== $e->getPrevious()) {
                $e = $e->getPrevious();
            }

            return $e->getMessage();
        }
        self::fail('the template must throw');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function section(string $type, array $data = []): array
    {
        return ['id' => 's1', 'type' => $type, 'data' => $data];
    }

    #[Test]
    public function strictFollowsDebugByDefault(): void
    {
        self::bootKernel();
        self::assertTrue(self::getContainer()->getParameter('itk_cms.strict'));
        self::ensureKernelShutdown();

        self::bootKernel(['debug' => false]);
        self::assertFalse(self::getContainer()->getParameter('itk_cms.strict'));
        self::ensureKernelShutdown();

        self::bootKernel(['debug' => false, 'itk' => ['strict' => true]]);
        self::assertTrue(self::getContainer()->getParameter('itk_cms.strict'));
    }

    #[Test]
    public function anUnknownFieldKeyThrowsWithTheKeysThatExist(): void
    {
        self::bootKernel();
        $message = $this->thrown("{{ itk_field(section, 'headlin') }}", ['section' => $this->section('hero')]);
        self::assertStringContainsString('«headlin»', $message);
        self::assertStringContainsString('«hero»', $message);
        self::assertStringContainsString('headline', $message);
        self::assertStringContainsString('description', $message);
    }

    #[Test]
    public function aKeyInsideARepeaterIsCheckedToo(): void
    {
        self::bootKernel();
        self::assertSame('Как?', $this->render("{{ itk_field(section, 'items.0.question') }}", ['section' => $this->section('faq', ['items' => [['question' => 'Как?']]])]));

        $message = $this->thrown("{{ itk_field(section, 'items.0.quest') }}", ['section' => $this->section('faq', ['items' => [['question' => 'Как?']]])]);
        self::assertStringContainsString('«quest»', $message);
        self::assertStringContainsString('question', $message);
        self::assertStringContainsString('answer', $message);
    }

    #[Test]
    public function aPathIntoAValueOfAFieldIsNotAMistake(): void
    {
        self::bootKernel();
        $out = $this->render("{{ itk_field(section, 'headline.nested') is null ? 'null' : 'value' }}", ['section' => $this->section('hero', ['headline' => 'x'])]);

        self::assertSame('null', $out, 'a path under a field that has no sub-fields is read as data, as before');
    }

    #[Test]
    public function withoutStrictAnUnknownKeyIsAWarningInTheLogOncePerRequest(): void
    {
        self::bootKernel(['itk' => ['strict' => false]]);
        $out = $this->render("{{ itk_field(section, 'headlin') }}{{ itk_field(section, 'headlin') }}", ['section' => $this->section('hero', ['headlin' => 'raw'])]);

        self::assertSame('rawraw', $out, 'the value is given as before');
        $warnings = $this->logger()->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('«headlin»', $warnings[0]);
    }

    #[Test]
    public function anUnknownSectionTypeThrowsInStrictAndIsLoggedWithout(): void
    {
        self::bootKernel();
        $message = $this->thrown('{{ itk_render_sections(page) }}', ['page' => ['sections' => [$this->section('old_slider')], 'h1Id' => null]]);
        self::assertStringContainsString('«old_slider»', $message);
        self::assertStringContainsString('hero', $message);
        self::ensureKernelShutdown();

        self::bootKernel(['itk' => ['strict' => false]]);
        self::assertSame('', $this->render('{{ itk_render_sections(page) }}', ['page' => ['sections' => [$this->section('old_slider')], 'h1Id' => null]]));
        self::assertStringContainsString('«old_slider»', $this->logger()->messages('warning')[0] ?? '');
    }

    #[Test]
    public function aHiddenSectionOfAnUnknownTypeIsLeftAlone(): void
    {
        self::bootKernel();

        self::assertSame('', $this->render('{{ itk_render_sections(page) }}', ['page' => ['sections' => [['hidden' => true] + $this->section('old_slider')], 'h1Id' => null]]));
    }

    #[Test]
    public function aSectionWithoutATemplateThrowsInStrictAndIsLoggedWithout(): void
    {
        self::bootKernel();
        self::assertStringContainsString('sections/ghost.html.twig', $this->thrown('{{ itk_render_sections(page) }}', ['page' => ['sections' => [$this->section('ghost')], 'h1Id' => null]]));
        self::ensureKernelShutdown();

        self::bootKernel(['itk' => ['strict' => false]]);
        self::assertSame('', $this->render('{{ itk_render_sections(page) }}', ['page' => ['sections' => [$this->section('ghost')], 'h1Id' => null]]));
        self::assertStringContainsString('sections/ghost.html.twig', $this->logger()->messages('warning')[0] ?? '');
    }

    #[Test]
    public function thePreviewOfTheAdminNeverThrows(): void
    {
        self::bootKernel();
        self::getContainer()->get(RenderState::class)->preview = true;
        try {
            $out = $this->render('{{ itk_render_sections(page) }}{{ itk_field(section, "nokey") }}', ['page' => ['sections' => [$this->section('ghost'), $this->section('old_slider')], 'h1Id' => null], 'section' => $this->section('hero')]);
        } finally {
            self::getContainer()->get(RenderState::class)->reset();
        }

        self::assertStringContainsString('шаблона секции нет', $out, 'the canvas shows the dashed placeholder, as before: the editor must stay usable');
    }
}
