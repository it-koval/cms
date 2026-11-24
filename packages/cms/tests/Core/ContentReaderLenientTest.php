<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Tests\App\RecordingLogger;
use Itk\Cms\Tests\App\TestKernel;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment;

/** itk_cms.strict: false (prod): неверный вызов itk_collection() / itk_pages() даёт пустой список и строку в журнале, страница не падает. */
final class ContentReaderLenientTest extends ResourceKernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], ['strict' => false]);
    }

    #[Test]
    public function aWrongCallIsAnEmptyListAndAWarning(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        $out = $twig->createTemplate("{{ itk_collection('test_trips', {where: {colour: 'red'}})|length }}|{{ itk_pages({template: 'nope'})|length }}|{{ itk_collection('test_trips')|length }}")->render([]);

        self::assertSame('0|0|0', $out);
        $warnings = self::getContainer()->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $warnings);
        $text = \implode("\n", $warnings->messages('warning'));
        self::assertStringContainsString('colour', $text);
        self::assertStringContainsString('nope', $text);
    }
}
