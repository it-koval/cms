<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\KnowledgeBase\Chunker;
use Itk\Cms\KnowledgeBase\Embedding\EmbeddingFailed;
use Itk\Cms\KnowledgeBase\Embedding\Space;
use Itk\Cms\KnowledgeBase\Embedding\Vector;
use Itk\Cms\KnowledgeBase\Model\Basis;
use Itk\Cms\KnowledgeBase\Model\Block;
use Itk\Cms\KnowledgeBase\Model\Chunk;
use Itk\Cms\KnowledgeBase\Model\DocumentPlan;
use Itk\Cms\KnowledgeBase\Model\Freshness;
use Itk\Cms\KnowledgeBase\Model\Hit;
use Itk\Cms\KnowledgeBase\Model\LiveSource;
use Itk\Cms\KnowledgeBase\Model\SearchOptions;
use Itk\Cms\KnowledgeBase\Model\SearchResult;
use Itk\Cms\KnowledgeBase\Model\Source;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\KnowledgeBase\Model\SourceRef;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Чистые типы базы знаний: свежесть документа выводится из основы, разбиение на фрагменты, текст из HTML, вектор ровно нужной размерности. */
final class KnowledgeBaseModelTest extends KernelTestCase
{
    private static function source(string $title = 'Возврат товара'): Source
    {
        return new Source(SourceKind::Page, 'p1', 'ru', 'default', $title, '/return');
    }

    private static function plan(Source $source, Chunk ...$chunks): DocumentPlan
    {
        $space = new Space('fake', 'm1');

        return new DocumentPlan(new LiveSource($source, [], 't1'), $space, \array_values($chunks), Basis::of($space, $source, \array_values($chunks)));
    }

    #[Test]
    public function freshnessIsDerivedFromTheStoredBasisAndThePlan(): void
    {
        $plan = self::plan(self::source());
        $same = self::plan(self::source());
        $other = self::plan(self::source('Другой заголовок'));

        self::assertNull(Freshness::of(null, null));
        self::assertSame(Freshness::Missing, Freshness::of(null, $plan));
        self::assertSame(Freshness::Orphan, Freshness::of(Basis::fromStored($plan->basis->value), null));
        self::assertSame(Freshness::Current, Freshness::of($same->basis, $plan));
        self::assertSame(Freshness::Stale, Freshness::of($other->basis, $plan));
        self::assertFalse(Freshness::Current->needsWork());
        self::assertTrue(Freshness::Orphan->needsWork());
    }

    #[Test]
    public function theBasisFollowsTheSpaceTheTitleAndEveryChunk(): void
    {
        $chunks = Chunker::chunk(self::source(), [new Block('s1', 'text', 'text', null, ['Вернуть можно в течение 14 дней.'])]);
        $a = Basis::of(new Space('fake', 'm1'), self::source(), $chunks);

        self::assertTrue($a->equals(Basis::of(new Space('fake', 'm1'), self::source(), $chunks)));
        self::assertFalse($a->equals(Basis::of(new Space('fake', 'm2'), self::source(), $chunks)), 'another model is another basis');
        self::assertFalse($a->equals(Basis::of(new Space('fake', 'm1'), self::source(), [])), 'a chunk gone is another basis');
        self::assertSame(40, \strlen($a->value));
    }

    #[Test]
    public function aSourceOptionNamesAKindAnIdAndMaybeALocale(): void
    {
        self::assertSame([SourceKind::Page, 'abc', null], SourceRef::parseOption('page:abc'));
        self::assertSame([SourceKind::Item, 'abc', 'en'], SourceRef::parseOption('item:abc:en'));
        self::assertSame('page:abc:ru', (new SourceRef(SourceKind::Page, 'abc', 'ru'))->key());

        $this->expectException(\InvalidArgumentException::class);
        SourceRef::parseOption('menu:abc');
    }

    #[Test]
    public function chunksNeverSpanSectionsAndKeepTheRowHeadingsOfOneSection(): void
    {
        $source = self::source();
        $chunks = Chunker::chunk($source, [
            new Block(null, null, 'title', 'Возврат товара', ['Как вернуть товар.']),
            new Block('faq', 'faq', 'items.0.answer', 'Сколько дней?', ['14 дней.']),
            new Block('faq', 'faq', 'items.1.answer', 'Нужен чек?', ['Да, нужен.', 'Да, нужен.']),
            new Block('s2', 'text', 'text', null, ['Отдельная секция.']),
        ]);

        self::assertSame([0, 1, 2], \array_map(static fn (Chunk $c): int => $c->ordinal, $chunks));
        self::assertSame([null, 'faq', 's2'], \array_map(static fn (Chunk $c): ?string => $c->sectionId, $chunks), 'the head, one FAQ chunk, one text chunk');
        self::assertSame("14 дней.\nНужен чек?\nДа, нужен.", $chunks[1]->text, 'rows are packed; a later row keeps its question; a repeated line is kept once');
        self::assertSame('Сколько дней?', $chunks[1]->heading);
        self::assertSame("Возврат товара\nСколько дней?\n\n14 дней.\nНужен чек?\nДа, нужен.", $chunks[1]->input);
        self::assertSame(\hash('sha256', $chunks[1]->input), $chunks[1]->inputHash);
        self::assertSame("Возврат товара\n\n\nОтдельная секция.", $chunks[2]->input);
    }

    #[Test]
    public function anOversizeBlockIsCutAtSentencesWithOneSentenceOfOverlap(): void
    {
        $sentence = 'Это предложение о возврате товара в магазин. ';
        $long = \trim(\str_repeat($sentence, 120));
        $chunks = Chunker::chunk(self::source(), [new Block('s1', 'text', 'text', null, [$long])]);

        self::assertGreaterThan(3, \count($chunks));
        foreach ($chunks as $c) {
            self::assertLessThanOrEqual(Chunker::MAX, \mb_strlen($c->text));
            self::assertSame('s1', $c->sectionId);
        }
        $first = \explode("\n", $chunks[0]->text);
        self::assertSame($first[\count($first) - 1], \explode("\n", $chunks[1]->text)[0], 'the last sentence of a part opens the next one');
    }

    #[Test]
    public function htmlBecomesRunsUnderSubheadingsAndOneLine(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get(HtmlSanitizer::class);

        $runs = $html->runs('<p>Вводный <strong>абзац</strong>.</p><h2>Сроки</h2><p>14 дней.</p><ul><li>Чек</li><li>Коробка</li></ul><h3>Таблица</h3><table><tr><th>Товар</th><th>Срок</th></tr><tr><td>Обувь</td><td>30 дней</td></tr></table><img src="/a.png" alt="x"><hr>');

        self::assertSame([
            ['heading' => null, 'paragraphs' => ['Вводный абзац.']],
            ['heading' => 'Сроки', 'paragraphs' => ['14 дней.', 'Чек', 'Коробка']],
            ['heading' => 'Таблица', 'paragraphs' => ['Товар | Срок', 'Обувь | 30 дней']],
        ], $runs);
        self::assertSame([['heading' => 'Только заголовок', 'paragraphs' => []]], $html->runs('<h2>Только заголовок</h2>'), 'an empty first run is left out');
        self::assertSame('Две строки заголовка', $html->oneLine('Две<br>строки   <em>заголовка</em>'));
    }

    #[Test]
    public function aVectorHasExactlyTheDimensionsOfTheSpaceAndRoundTripsThroughSql(): void
    {
        $values = \array_fill(0, Space::DIMENSIONS, 0.25);
        $values[1] = -1;
        $vector = Vector::of($values);

        self::assertSame(Space::DIMENSIONS, \count($vector->values));
        self::assertSame(-1.0, $vector->values[1]);
        self::assertStringStartsWith('[0.25,-1,0.25,', $vector->toSql());
        self::assertSame($vector->values, Vector::fromSql($vector->toSql())->values);

        try {
            Vector::of([1.0, 2.0]);
            self::fail('two dimensions were accepted');
        } catch (EmbeddingFailed $e) {
            self::assertFalse($e->transient, 'a wrong size is not fixed by a retry');
        }
    }

    #[Test]
    public function searchOptionsRejectALimitOutOfRangeAndAResultListsEachSourceOnce(): void
    {
        self::assertSame(8, (new SearchOptions())->limit);
        $hit = static fn (string $id, float $score): Hit => new Hit($score, 'text', null, new Source(SourceKind::Page, $id, 'ru', 'default', 't', '/t'), null, null, 'title');
        $result = new SearchResult('q', 'ru', [$hit('a', 0.9), $hit('b', 0.8), $hit('a', 0.7)]);

        self::assertSame(['a', 'b'], \array_map(static fn (Hit $h): string => $h->source->id, $result->bySource()));

        $this->expectException(\InvalidArgumentException::class);
        new SearchOptions(limit: 51);
    }
}
