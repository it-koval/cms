<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Схема сайта (config/itk/schema.php) и её выгрузка для админки (GET /admin/api/schema). */
final class SchemaExportTest extends KernelTestCase
{
    /** @return array<string, mixed> */
    private function export(): array
    {
        self::bootKernel();

        return self::getContainer()->get(Schema::class)->toAdminArray();
    }

    #[Test]
    public function itExportsEverythingTheAdminNeeds(): void
    {
        $s = $this->export();
        foreach (['mainLocale', 'design', 'sections', 'sectionGroups', 'pageFields', 'templates', 'layout', 'collections', 'menuAreas', 'settings', 'forms', 'permissionAreas'] as $key) {
            self::assertArrayHasKey($key, $s, $key);
        }
        self::assertSame('ru', $s['mainLocale']);
        self::assertArrayNotHasKey('features', $s);
        self::assertSame(['hero', 'text', 'html', 'notes', 'image', 'faq'], \array_column($s['sections'], 'key'));
        self::assertNotFalse(\json_encode($s, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function itDescribesTemplatesWithTheirParents(): void
    {
        $templates = [];
        foreach ($this->export()['templates'] as $t) {
            $templates[$t['key']] = $t;
        }
        self::assertSame(['home', 'default', 'blog', 'blog_category', 'blog_article'], \array_keys($templates));
        self::assertTrue($templates['home']['single']);
        self::assertSame(['blog', 'blog_category'], $templates['blog_article']['parents']);
    }

    #[Test]
    public function everyPageHasOneHeadlineFieldDeclaredAsItsH1(): void
    {
        $export = $this->export();
        $hero = null;
        foreach ($export['sections'] as $section) {
            if ('hero' === $section['key']) {
                $hero = $section;
            }
        }
        self::assertSame('headline', $hero['h1']);
        self::assertSame([], \array_values(\array_filter($hero['fields'], static fn (array $f): bool => \in_array($f['k'], ['line1', 'line2', 'line1Style', 'line2Style'], true))));
        foreach ($export['templates'] as $t) {
            if ('blog' === $t['key']) {
                self::assertSame(['field' => 'headline'], $t['h1'], $t['key']);
                self::assertNotContains('heroLine1', \array_column($t['fields'], 'k'));
            }
        }
    }

    #[Test]
    public function itDeclaresCollectionsFormsMenusAndSettings(): void
    {
        $s = $this->export();
        self::assertSame(['tours'], \array_column($s['collections'], 'key'), 'классы tests/App/Itk/Collection');
        self::assertSame(['call', 'maintenance'], \array_column($s['forms'], 'key'));
        self::assertSame(['header', 'footer'], \array_column($s['menuAreas'], 'key'));
        self::assertSame(['header'], $s['layout']['top']);
        self::assertSame(['footer'], $s['layout']['bottom']);
        self::assertSame(['site', 'header', 'contacts', 'analytics'], \array_column($s['settings']['groups'], 'key'));
    }

    #[Test]
    public function permissionAreasAreGeneratedFromTheSchema(): void
    {
        $areas = \array_column($this->export()['permissionAreas'], null, 'key');
        foreach (['pages', 'requests', 'media', 'col:tours', 'set:site', 'set:contacts'] as $key) {
            self::assertArrayHasKey($key, $areas, $key);
        }
        self::assertSame(['view', 'edit', 'publish', 'delete'], $areas['pages']['actions']);
    }

    #[Test]
    public function everySectionTemplateExists(): void
    {
        $root = self::getContainer()->getParameter('kernel.project_dir');
        foreach ($this->export()['sections'] as $section) {
            self::assertFileExists($root . '/templates/sections/' . $section['key'] . '.html.twig', $section['key']);
        }
    }
}
