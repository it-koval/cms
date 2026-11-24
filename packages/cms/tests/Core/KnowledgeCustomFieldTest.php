<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\PublicFieldInterface;
use Itk\Cms\KnowledgeBase\Extractor;
use Itk\Cms\KnowledgeBase\Model\LiveSource;
use Itk\Cms\KnowledgeBase\Model\Source;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SectionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KnowledgeCustomFieldTest extends TestCase
{
    #[Test]
    public function onlyAnExplicitlyPublicCustomFieldCanReachTheIndexer(): void
    {
        $open = $this->createStub(PublicFieldInterface::class);
        $open->method('key')->willReturn('open');
        $open->method('type')->willReturn('text');
        $open->method('isPublic')->willReturn(true);
        $closed = $this->createStub(PublicFieldInterface::class);
        $closed->method('key')->willReturn('closed');
        $closed->method('type')->willReturn('text');
        $closed->method('isPublic')->willReturn(false);
        $unknown = $this->createStub(FieldInterface::class);
        $unknown->method('key')->willReturn('unknown');
        $unknown->method('type')->willReturn('text');
        $unsupported = $this->createStub(PublicFieldInterface::class);
        $unsupported->method('key')->willReturn('unsupported');
        $unsupported->method('type')->willReturn('internal-json');
        $unsupported->method('isPublic')->willReturn(true);
        $section = (new SectionType('custom', 'Custom'))->fields($open, $closed, $unknown, $unsupported);
        $schema = new Schema('ru', [], [], [], ['top' => [], 'bottom' => []], ['custom' => $section], [], [], [], []);
        $live = new LiveSource(new Source(SourceKind::Page, 'fixture-page', 'ru', 'default', 'Page', '/fixture'), [
            'sections' => [['id' => 'fixture-section', 'type' => 'custom', 'data' => ['open' => 'Public fixture', 'closed' => 'Private fixture', 'unknown' => 'Undefined fixture', 'unsupported' => 'Unsupported fixture']]],
        ], 'fixture-translation');
        $blocks = (new Extractor($schema, new HtmlSanitizer($schema)))->extract($live);
        $text = \implode(' ', \array_merge(...\array_map(static fn ($block): array => $block->paragraphs, $blocks)));
        self::assertStringContainsString('Public fixture', $text);
        self::assertStringNotContainsString('Private fixture', $text);
        self::assertStringNotContainsString('Undefined fixture', $text);
        self::assertStringNotContainsString('Unsupported fixture', $text);
    }
}
