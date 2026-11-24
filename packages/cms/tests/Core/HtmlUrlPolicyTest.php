<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlUrlPolicyTest extends TestCase
{
    #[Test]
    public function sharedUrlCorpusSurvivesTheSaveRoundTrip(): void
    {
        $html = new HtmlSanitizer(new Schema('ru', [], [], ['top' => [], 'bottom' => []], [], [], [], [], []));
        $cases = \json_decode(\file_get_contents(__DIR__ . '/../Fixtures/html-url-cases.json'), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $saved = $html->sanitize($case['html'], 'article');
            self::assertSame($case['expected'], $saved, $case['name']);
            self::assertSame($saved, $html->sanitize($saved, 'article'), $case['name'] . ': reloaded');
        }
    }
}
