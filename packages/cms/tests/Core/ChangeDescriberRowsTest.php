<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\ChangeDescriber;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Field\Number;
use Itk\Cms\Field\Photos;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Строки списка в истории: заголовок по шаблону и Photos внутри строки. */
final class ChangeDescriberRowsTest extends KernelTestCase
{
    private ChangeDescriber $describer;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $html = new HtmlSanitizer($c->get(Schema::class));
        $this->describer = new ChangeDescriber($html, new FieldValues($html, $c->get(AdminLocale::class)));
    }

    private function variants(): Repeater
    {
        return Repeater::make('Варианты', 'variants')->noun('вариант')->itemTitle('{kind} · {yearFrom}–{yearTo}', '{title}')->fields(
            Select::make('Вид', 'kind')->options(['a' => 'Альфа']),
            Number::make('С', 'yearFrom')->half(),
            Number::make('По', 'yearTo')->half(),
            Text::make('Название', 'title'),
            Photos::make('Фото', 'photos'),
        );
    }

    #[Test]
    public function aRowIsTitledByItsTemplate(): void
    {
        $f = $this->variants();
        self::assertSame('Альфа · 2020–2022', $this->describer->repeaterTitle($f, ['kind' => 'a', 'yearFrom' => 2020, 'yearTo' => 2022], 0));
        self::assertSame('2020', $this->describer->repeaterTitle($f, ['kind' => '', 'yearFrom' => 2020], 0));
        self::assertSame('Полная', $this->describer->repeaterTitle($f, ['title' => 'Полная'], 0));
        self::assertSame('Вариант 2', $this->describer->repeaterTitle($f, [], 1), 'nothing to show: the title it always had');
    }

    #[Test]
    public function photosInsideARowAreCountedNotDumped(): void
    {
        $f = $this->variants();
        $was = [['_id' => 'p1', 'title' => 'Полная', 'photos' => [['src' => '/a.jpg', 'alt' => '']]]];
        $now = [['_id' => 'p1', 'title' => 'Полная', 'photos' => [['src' => '/a.jpg', 'alt' => ''], '/b.jpg']]];

        $d = $this->describer->describeChange($f, $now, $was);

        self::assertSame(['val' => '2 фото', 'old' => '1 фото'], ['val' => $d['val'], 'old' => $d['old']]);
        self::assertStringContainsString('Полная', $d['where']);
        self::assertSame('нет фото', $this->describer->describeValue(Photos::make('Фото', 'photos'), []));
    }

    #[Test]
    public function aGroupedNumberInATitleIsWrittenWithThousandsSeparatorsOfTheLanguage(): void
    {
        $f = Repeater::make('Цены', 'prices')->itemTitle('{price} ₸')->fields(Number::make('Цена', 'price')->grouped(), Number::make('Год', 'year'));
        $locale = self::getContainer()->get(AdminLocale::class);
        $title = fn (array $item): string => $this->describer->repeaterTitle($f, $item, 0);

        // the title template collapses every kind of space into a plain one, as the admin does
        self::assertSame('110 000 ₸', $locale->run('ru', static fn (): string => $title(['price' => 110000])));
        self::assertSame('110 000,5 ₸', $locale->run('ru', static fn (): string => $title(['price' => 110000.5])));
        self::assertSame('110 000 ₸', $locale->run('kk', static fn (): string => $title(['price' => '110000'])));
        self::assertSame('110,000.5 ₸', $locale->run('en', static fn (): string => $title(['price' => 110000.5])));

        $plain = Repeater::make('Годы', 'years')->itemTitle('{year}')->fields(Number::make('Год', 'year'));
        self::assertSame('2020', $locale->run('ru', fn (): string => $this->describer->repeaterTitle($plain, ['year' => 2020], 0)), 'a number without grouped is left as it is');
    }

    #[Test]
    public function theSeparatorOfThousandsIsANonBreakingSpaceInRussianAndACommaInEnglish(): void
    {
        $grouped = new \ReflectionMethod(ChangeDescriber::class, 'groupedNumber');
        $locale = self::getContainer()->get(AdminLocale::class);

        self::assertSame("110\u{00A0}000", $locale->run('ru', static fn (): string => $grouped->invoke(null, 110000)));
        self::assertSame("110\u{00A0}000,5", $locale->run('ru', static fn (): string => $grouped->invoke(null, 110000.5)));
        self::assertSame("1\u{00A0}234\u{00A0}567,891", $locale->run('kk', static fn (): string => $grouped->invoke(null, 1234567.891)));
        self::assertSame('110,000.5', $locale->run('en', static fn (): string => $grouped->invoke(null, 110000.5)));
        self::assertSame('0.1', $locale->run('en', static fn (): string => $grouped->invoke(null, 0.1)));
    }

    #[Test]
    public function withoutIntlTheSameSeparatorsAreUsed(): void
    {
        $plain = new \ReflectionMethod(ChangeDescriber::class, 'groupedByHand');

        self::assertSame("110\u{00A0}000", $plain->invoke(null, 110000, 'ru'));
        self::assertSame("110\u{00A0}000,5", $plain->invoke(null, 110000.5, 'kk'));
        self::assertSame('110,000.5', $plain->invoke(null, 110000.5, 'en'));
        self::assertSame('-1,234,567.25', $plain->invoke(null, -1234567.25, 'en'));
        self::assertSame('0.1', $plain->invoke(null, 0.1, 'en'));
    }
}
