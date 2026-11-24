<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Admin\NavBuilder;
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Security\Viewer;
use Itk\Cms\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/** Блог отключается: $s->withoutBlog() в schema.php или itk_cms.blog: false — шаблонов блога нет, ссылки «Статьи блога» в меню тоже. */
final class WithoutBlogTest extends KernelTestCase
{
    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new TestKernel('test', true, null, '', [], [], $options['itk'] ?? []);
    }

    #[Test]
    public function aSiteWithABlogHasTheBlogTemplatesByDefault(): void
    {
        $schema = (new SchemaBuilder('ru'))->build();

        self::assertSame(['home', 'default', 'blog', 'blog_category', 'blog_article'], \array_keys($schema->templates()));
    }

    #[Test]
    public function withoutBlogTakesTheThreeTemplatesOut(): void
    {
        $schema = (new SchemaBuilder('ru'))->withoutBlog()->build();

        self::assertSame(['home', 'default'], \array_keys($schema->templates()));
        self::assertSame([], $schema->validate());
        self::assertSame([], $schema->articleTemplates());
    }

    #[Test]
    public function theBuilderOptionDoesTheSame(): void
    {
        self::assertSame(['home', 'default'], \array_keys((new SchemaBuilder('ru', false))->build()->templates()));
    }

    #[Test]
    public function aSiteMayDescribeItsOwnTemplateWithABlogKeyAfterwards(): void
    {
        $b = (new SchemaBuilder('ru'))->withoutBlog();
        $b->template('blog', 'Журнал')->single()->topLevel();
        $b->template('blog_article', 'Заметка')->parents('blog')->fields(Field::text('title', 'Название'));
        $schema = $b->build();

        self::assertSame(['home', 'default', 'blog', 'blog_article'], \array_keys($schema->templates()));
        self::assertFalse($schema->template('blog')->isCore(), 'the core template is gone, this one is the site\'s own');
        self::assertSame([], $schema->validate());
    }

    #[Test]
    public function aTemplateThatPointsAtTheBlogIsAProblemOnceItIsGone(): void
    {
        $b = (new SchemaBuilder('ru'))->withoutBlog();
        $b->template('news', 'Новость')->parents('blog');

        $problems = $b->build()->validate();
        self::assertCount(1, $problems);
        self::assertStringContainsString('«blog»', $problems[0]);
    }

    /** @return list<string> */
    private function railLinks(): array
    {
        $nav = self::getContainer()->get(NavBuilder::class)->build(Viewer::system());

        return \array_column($nav[0]['items'], 'label');
    }

    #[Test]
    public function theArticlesLinkIsInTheRailWithABlogAndGoneWithout(): void
    {
        self::bootKernel();
        self::assertContains('Статьи блога', $this->railLinks());
        self::ensureKernelShutdown();

        self::bootKernel(['itk' => ['blog' => false]]);
        self::assertNotContains('Статьи блога', $this->railLinks());
        $schema = self::getContainer()->get(Schema::class);
        self::assertNull($schema->template('blog_article'));
        self::assertNull($schema->template('blog_category'));
        self::assertNotNull($schema->template('home'));
        self::assertTrue(self::getContainer()->has('itk_cms.blog') || false === self::getContainer()->getParameter('itk_cms.blog'));
    }
}
