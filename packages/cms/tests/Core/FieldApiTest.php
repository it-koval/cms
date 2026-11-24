<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Entity\User;
use Itk\Cms\Field\Article;
use Itk\Cms\Field\Date;
use Itk\Cms\Field\DateTime;
use Itk\Cms\Field\Description;
use Itk\Cms\Field\FilterKind;
use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Note;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Relation;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Toggle;
use Itk\Cms\Schema\Field;
use Itk\Cms\Security\Viewer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Поля-объекты Itk\Cms\Field\*: подпись и ключ, цепочка методов, общий вид для формы, колонки, фильтра; фасад Schema\Field. */
final class FieldApiTest extends TestCase
{
    #[Test]
    public function headlineLineBreakCanBeExplicitlyDisabledWithoutChangingTheDefault(): void
    {
        self::assertArrayNotHasKey('brk', Headline::make('Title', 'title')->toArray());
        self::assertTrue(Headline::make('Title', 'title')->lineBreak()->toArray()['brk']);
        self::assertFalse(Headline::make('Title', 'title')->lineBreak(false)->toArray()['brk']);
    }

    #[Test]
    public function aFieldTakesTheLabelFirstAndTheKeySecond(): void
    {
        $f = Text::make('Заголовок', 'title');
        self::assertSame('title', $f->key());
        self::assertSame('Заголовок', $f->label());
        self::assertSame('text', $f->type());
    }

    #[Test]
    public function aFieldThatHoldsDataNeedsAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a key/');
        Text::make('Заголовок');
    }

    #[Test]
    public function aNoteMayGoWithoutAKey(): void
    {
        $n = Note::make('Подсказка')->text('Текст');
        self::assertNotSame('', $n->key());
        self::assertTrue($n->isNote());
        self::assertSame('Текст', $n->toArray()['text']);
    }

    #[Test]
    public function theFacadeMakesTheSameObjects(): void
    {
        self::assertSame(Text::make('Имя', 'name')->max(30)->required()->toArray(), Field::text('name', 'Имя')->max(30)->required()->toArray());
        self::assertSame(Headline::make('H', 'h')->toArray(), Field::headline('h', 'H')->toArray());
        self::assertSame(Description::make('D', 'd')->toArray(), Field::description('d', 'D')->toArray());
        self::assertSame(Article::make('A', 'a')->toArray(), Field::article('a', 'A')->toArray());
        self::assertSame(Date::make('Дата', 'date')->toArray(), Field::date('date', 'Дата')->toArray());
        self::assertSame(
            Select::make('С', 's')->options([['a', 'Раз'], 'b' => 'Два'])->toArray(),
            Field::select('s', 'С', [['a', 'Раз'], 'b' => 'Два'])->toArray(),
        );
        self::assertSame([['a', 'Раз'], ['b', 'Два']], Field::select('s', 'С', [['a', 'Раз'], 'b' => 'Два'])->choices());
        $repeater = Field::repeater('items', 'Список', Field::text('x', 'X'), [Field::text('y', 'Y')]);
        self::assertInstanceOf(Repeater::class, $repeater);
        self::assertSame(['x', 'y'], \array_map(static fn ($f): string => $f->key(), $repeater->subFields()));
    }

    #[Test]
    public function theExportKeepsTheOldShapeAndAddsListFilterAndDetail(): void
    {
        $a = Text::make('Название', 'title')->required()->hint('Подсказка')->max(40)->sortable()->searchable()->width(200)->filterable()->toArray();
        self::assertSame('title', $a['k']);
        self::assertSame('text', $a['t']);
        self::assertSame('Название', $a['label']);
        self::assertTrue($a['req']);
        self::assertSame('Подсказка', $a['help']);
        self::assertSame(40, $a['max']);
        self::assertSame(['show' => null, 'sortable' => true, 'width' => 200], $a['list']);
        self::assertSame(['kind' => 'text', 'options' => []], $a['filter']);
        self::assertTrue($a['detail']);
        self::assertTrue($a['search']);

        $plain = Text::make('Р', 'r')->toArray();
        self::assertNull($plain['filter']);
        self::assertArrayNotHasKey('ro', $plain);
        self::assertArrayNotHasKey('form', $plain);
        self::assertArrayNotHasKey('default', $plain);
    }

    #[Test]
    public function helpAndHintAreOneThingAndSoAreDefaultAndInitial(): void
    {
        self::assertSame('x', Text::make('A', 'a')->help('x')->toArray()['help']);
        $f = Number::make('Мест', 'seats')->default(10);
        self::assertTrue($f->hasDefault());
        self::assertSame(10, $f->defaultValue());
        self::assertSame(5, Number::make('Мест', 'seats')->initial(5)->defaultValue());
    }

    #[Test]
    public function aFilterTakesItsKindFromTheField(): void
    {
        self::assertSame(FilterKind::Text, Text::make('A', 'a')->filterable()->filterKind());
        self::assertSame(FilterKind::Select, Select::make('A', 'a')->options(['x' => 'X'])->filterable()->filterKind());
        self::assertSame(FilterKind::Toggle, Toggle::make('A', 'a')->filterable()->filterKind());
        self::assertSame(FilterKind::NumberRange, Number::make('A', 'a')->filterable()->filterKind());
        self::assertSame(FilterKind::DateRange, Date::make('A', 'a')->filterable()->filterKind());
        self::assertSame(FilterKind::DateRange, DateTime::make('A', 'a')->filterable()->filterKind());
        self::assertSame(FilterKind::Relation, Relation::make('A', 'a')->to('tours')->filterable()->filterKind());
        self::assertSame(FilterKind::Multi, Select::make('A', 'a')->filterable(FilterKind::Multi)->filterKind());
        self::assertNull(Text::make('A', 'a')->filterKind());
        $options = Select::make('A', 'a')->options(['x' => 'X'])->filterable()->toArray()['filter'];
        self::assertSame(['kind' => 'select', 'options' => [['x', 'X']]], $options);
    }

    #[Test]
    public function columnsAreTriState(): void
    {
        self::assertNull(Text::make('A', 'a')->listColumn());
        self::assertTrue(Text::make('A', 'a')->column()->listColumn());
        self::assertTrue(Text::make('A', 'a')->showOnIndex()->listColumn());
        self::assertFalse(Text::make('A', 'a')->hideOnIndex()->listColumn());
        $a = Text::make('A', 'a')->hideOnForm()->hideOnDetail()->readonly()->toArray();
        self::assertFalse($a['form']);
        self::assertFalse($a['detail']);
        self::assertTrue($a['ro']);
    }

    #[Test]
    public function datesKeepTheOldTextShapeWithAnInputType(): void
    {
        self::assertSame(['text', 'date'], [Date::make('A', 'a')->type(), Date::make('A', 'a')->toArray()['inputType']]);
        self::assertSame('datetime-local', DateTime::make('A', 'a')->toArray()['inputType']);
        $r = Relation::make('Куда', 'dest')->to('destinations')->multiple()->toArray();
        self::assertSame(['relation', 'destinations', true], [$r['t'], $r['to'], $r['multi']]);
    }

    #[Test]
    public function canSeeTakesAnAreaARightOrAClosure(): void
    {
        $viewer = Viewer::of(new User('v@example.com', 'V', 'custom'), ['perms' => ['set:users' => ['view'], 'col:tours' => ['view', 'edit']], 'templates' => null]);

        self::assertTrue(Text::make('A', 'a')->visibleTo($viewer));
        self::assertTrue(Text::make('A', 'a')->canSee('set:users')->visibleTo($viewer));
        self::assertFalse(Text::make('A', 'a')->canSee('set:site')->visibleTo($viewer));
        self::assertTrue(Text::make('A', 'a')->canSee('col:tours:edit')->visibleTo($viewer));
        self::assertFalse(Text::make('A', 'a')->canSee('col:tours:publish')->visibleTo($viewer));
        self::assertTrue(Text::make('A', 'a')->canSee(static fn (Viewer $v): bool => 'v@example.com' === $v->email())->visibleTo($viewer));
        self::assertFalse(Text::make('A', 'a')->canSee(static fn (Viewer $v): bool => false)->visibleTo($viewer));
        // the system (console, queue) is not limited
        self::assertTrue(Text::make('A', 'a')->canSee('set:site')->visibleTo(Viewer::system()));
    }

    #[Test]
    public function aRepeaterTakesTitleTemplatesAndAButtonToDuplicate(): void
    {
        $plain = Repeater::make('Варианты', 'variants')->fields(Text::make('Название', 'title'))->toArray();
        foreach (['itemTitle', 'itemSummary', 'dup'] as $key) {
            self::assertArrayNotHasKey($key, $plain, 'off until asked for');
        }

        $r = Repeater::make('Варианты', 'variants')
            ->fields(Text::make('Название', 'title'))
            ->itemTitle('{bike} · {yearFrom}–{yearTo}', '{title}')
            ->itemSummary('{sku} · {price}')
            ->duplicable()
            ->toArray();
        self::assertSame(['{bike} · {yearFrom}–{yearTo}', '{title}'], $r['itemTitle']);
        self::assertSame(['{sku} · {price}'], $r['itemSummary']);
        self::assertTrue($r['dup']);
        self::assertArrayNotHasKey('dup', Repeater::make('R', 'r')->duplicable()->duplicable(false)->toArray());
    }

    #[Test]
    public function halfAndSectionShapeTheForm(): void
    {
        $plain = Number::make('С', 'from')->toArray();
        self::assertArrayNotHasKey('half', $plain);
        self::assertArrayNotHasKey('section', $plain);

        $a = Number::make('С', 'from')->half()->section('Подбор')->toArray();
        self::assertTrue($a['half']);
        self::assertSame('Подбор', $a['section']);
        $b = Number::make('С', 'from')->half()->half(false)->section('Раздел')->section('')->toArray();
        self::assertArrayNotHasKey('half', $b);
        self::assertArrayNotHasKey('section', $b);
        // inside a repeater the sub-fields keep them
        $r = Repeater::make('R', 'r')->fields(Number::make('С', 'from')->half(), Number::make('По', 'to')->half())->toArray();
        self::assertTrue($r['sub'][0]['half'] && $r['sub'][1]['half']);
    }
}
