<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\KnowledgeBase\Extractor;
use Itk\Cms\KnowledgeBase\Model\Block;
use Itk\Cms\KnowledgeBase\Model\LiveSource;
use Itk\Cms\KnowledgeBase\Model\Source;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Что из опубликованного снимка попадает в базу знаний: только текст, который видит посетитель. Скрытые секции, неизвестные типы, поля raw(),
 * поля с canSee(), вид секции, картинки без alt остаются снаружи; вопросы FAQ и подзаголовки статьи становятся заголовками блоков.
 */
final class KnowledgeBaseExtractorTest extends KernelTestCase
{
    private static function extractor(): Extractor
    {
        $c = self::getContainer();

        return new Extractor($c->get(Schema::class), $c->get(HtmlSanitizer::class));
    }

    /** @param array<string, mixed> $published */
    private static function page(array $published, string $template = 'default'): LiveSource
    {
        return new LiveSource(new Source(SourceKind::Page, 'p1', 'ru', $template, (string) ($published['title'] ?? ''), '/p'), $published, 't1');
    }

    /**
     * @param array<array-key, mixed> $blocks
     *
     * @return list<array{0: ?string, 1: string, 2: ?string, 3: list<string>}> section id, field, heading, paragraphs
     */
    private static function flat(array $blocks): array
    {
        return \array_map(static fn (Block $b): array => [$b->sectionId, $b->field, $b->heading, $b->paragraphs], $blocks);
    }

    #[Test]
    public function onlyPublicTextOfVisibleKnownSectionsIsExtracted(): void
    {
        self::bootKernel();
        $blocks = self::extractor()->extract(self::page([
            'title' => 'Возврат',
            'url' => '/return',
            'seo' => ['title' => 'SEO-заголовок', 'description' => 'Как вернуть товар за 14 дней'],
            'fields' => [],
            'sections' => [
                ['id' => 's1', 'type' => 'hero', 'hidden' => false, 'data' => ['layout' => 'home', 'headline' => 'Первая<br>строка', 'description' => 'Описание <em>героя</em>', 'buttonText' => 'Позвонить', 'buttonLink' => ['url' => '/x'], 'image' => ['src' => '/a.png', 'alt' => 'Фото магазина']]],
                ['id' => 's2', 'type' => 'text', 'hidden' => true, 'data' => ['text' => '<p>Скрытый текст</p>']],
                ['id' => 's3', 'type' => 'html', 'hidden' => false, 'data' => ['html' => '<script>alert(1)</script><p>Сырой HTML</p>']],
                ['id' => 's4', 'type' => 'gone', 'hidden' => false, 'data' => ['text' => 'Секции такого типа больше нет']],
                ['id' => 's5', 'type' => 'notes', 'hidden' => false, 'data' => ['summary' => "Кратко  о\nглавном", 'internal' => 'Только для своих', 'mood' => 'calm', 'gallery' => ['/a.jpg', ['src' => '/b.jpg', 'alt' => 'Вторая']]]],
                ['id' => 's6', 'type' => 'image', 'hidden' => false, 'data' => ['image' => ['src' => '/c.png', 'alt' => ''], 'align' => 'left']],
            ],
        ]));

        self::assertSame([
            [null, 'seo.description', 'Возврат', ['Как вернуть товар за 14 дней']],
            ['s1', 'description', 'Первая строка', ['Описание героя', 'Позвонить', 'Фото магазина']],
            ['s5', 'summary', null, ['Кратко о главном', 'Тон: Спокойный', 'Вторая']],
            ['s6', 'align', null, ['Выравнивание: По левому краю']],
        ], self::flat($blocks));
    }

    #[Test]
    public function articleSubheadingsAndRepeaterRowsOpenBlocksOfTheirOwn(): void
    {
        self::bootKernel();
        $blocks = self::extractor()->extract(self::page([
            'title' => 'Доставка',
            'url' => '/delivery',
            'seo' => ['title' => '', 'description' => ''],
            'fields' => [],
            'sections' => [
                ['id' => 'a', 'type' => 'text', 'hidden' => false, 'data' => ['text' => '<p>До подзаголовка.</p><h2>По городу</h2><p>Курьером.</p><h3>Самовывоз</h3><ul><li>Пункт 1</li></ul>']],
                ['id' => 'f', 'type' => 'faq', 'hidden' => false, 'data' => ['headline' => 'Вопросы', 'items' => [
                    ['_id' => 'q1', 'question' => 'Сколько стоит?', 'answer' => '<p>Бесплатно от 5000.</p>'],
                    ['_id' => 'q2', 'question' => '', 'answer' => '<p>Ответ без вопроса.</p>'],
                ]]],
            ],
        ]));

        self::assertSame([
            [null, 'title', 'Доставка', ['Доставка']],
            ['a', 'text', null, ['До подзаголовка.']],
            ['a', 'text', 'По городу', ['Курьером.']],
            ['a', 'text', 'Самовывоз', ['Пункт 1']],
            ['f', 'items.0.answer', 'Сколько стоит?', ['Бесплатно от 5000.']],
            ['f', 'items.1.answer', 'Вопросы', ['Ответ без вопроса.']],
        ], self::flat($blocks));
    }

    #[Test]
    public function anItemIsOneBlockUnderItsTitleField(): void
    {
        self::bootKernel();
        $live = new LiveSource(new Source(SourceKind::Item, 'i1', 'ru', 'tours', 'Алматы', null), ['city' => 'Алматы', 'dateStart' => '2026-05-01', 'dateEnd' => '2026-05-09'], null);

        self::assertSame([], self::flat(self::extractor()->extract($live)), 'the title is the heading, dates give no text: nothing to embed');
    }
}
