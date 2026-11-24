<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\FieldValues;
use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\TemplateResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class HeadlineBreakTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(ResourceRegistry::class)->add(new class extends TemplateResource {
            public function key(): string
            {
                return 'test_headline_break';
            }

            public function title(): string
            {
                return 'Headline breaks';
            }

            public function fields(): iterable
            {
                yield Headline::make('Single line', 'single')->lineBreak(false);
                yield Headline::make('Default', 'default');
                yield Headline::make('Enabled', 'enabled')->lineBreak();
                yield Repeater::make('Rows', 'rows')->fields(Headline::make('Title', 'title')->lineBreak(false));
            }
        });
    }

    #[Test]
    public function normalizationReplacesDisabledBreaksWithSpacesIncludingNestedRows(): void
    {
        $fields = self::getContainer()->get(\Itk\Cms\Schema\Schema::class)->templateFields('test_headline_break');
        $values = self::getContainer()->get(FieldValues::class);
        $stored = $values->normalize($fields, [
            'single' => '<p>A</p><p>B</p>', 'default' => 'A<br>B', 'enabled' => 'A<br>B',
            'rows' => [['_id' => 'row-a', 'title' => 'A<br>B']],
        ]);
        self::assertSame('A B', $stored['single']);
        self::assertSame('A<br>B', $stored['default']);
        self::assertSame('A<br>B', $stored['enabled']);
        self::assertSame([['_id' => 'row-a', 'title' => 'A B']], $stored['rows']);
        self::assertTrue($values->equal($fields[0], 'A<br>B', 'A B'));
        self::assertSame($stored, $values->normalize($fields, $stored));
    }

    #[Test]
    public function savingAndReloadingAPageKeepsDisabledBreaksNormalized(): void
    {
        $pages = self::getContainer()->get(\Itk\Cms\Content\PageService::class);
        $page = $pages->create(['template' => 'test_headline_break', 'title' => 'Headline break', 'url' => '/test-headline-break-' . \bin2hex(\random_bytes(5))]);
        try {
            $pages->saveDraft($page['id'], null, ['fields' => ['single' => 'A<br>B', 'default' => 'A<br>B', 'enabled' => 'A<br>B']]);
            self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
            $stored = $pages->get($page['id']);
            self::assertSame('A B', $stored['fields']['single']);
            self::assertSame('A<br>B', $stored['fields']['default']);
            self::assertSame('A<br>B', $stored['fields']['enabled']);
        } finally {
            $pages->remove($page['id']);
        }
    }

    #[Test]
    public function legacyValuesRenderWithoutDisabledBreaksInLiveAndPreview(): void
    {
        $state = self::getContainer()->get(RenderState::class);
        $twig = self::getContainer()->get(Environment::class);
        $template = $twig->createTemplate('{{ itk_field(page, "single") }}|{{ itk_field(page, "default") }}|{{ itk_field(page, "enabled") }}');
        $page = ['id' => 'headline', 'template' => 'test_headline_break', 'fields' => ['single' => 'A<br>B', 'default' => 'A<br>B', 'enabled' => 'A<br>B']];
        foreach ([false, true] as $preview) {
            $state->preview = $preview;
            $html = $template->render(['page' => $page]);
            self::assertSame('A B|A<br>B|A<br>B', \preg_replace('~</?span[^>]*>~', '', $html));
        }
        $state->reset();
    }
}
