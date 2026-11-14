<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Resource\ResourceApplier;
use Itk\Cms\Schema\Field;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Tests\Core\Fixture\TestTemplate;
use Itk\Cms\Tests\Core\Fixture\TripsCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Schema::validate(): ошибки схемы названы по-русски, с именем раздела; хорошая схема даёт пустой список. */
final class SchemaValidateTest extends TestCase
{
    /** @return list<string> */
    private function problems(callable $define): array
    {
        $b = new SchemaBuilder('ru');
        $define($b);

        return $b->build()->validate();
    }

    #[Test]
    public function theCoreSchemaAndAGoodSchemaHaveNoProblems(): void
    {
        self::assertSame([], (new SchemaBuilder('ru'))->build()->validate());

        $schema = $this->goodSchema();
        self::assertSame([], $schema->validate());
    }

    private function goodSchema(): Schema
    {
        $b = new SchemaBuilder('ru');
        $b->menuArea('header', 'Меню в шапке', depth: 2);
        $b->section('hero', 'Первый экран')->fields(Field::headline('headline', 'Заголовок'), Field::repeater('items', 'Пункты', Field::text('name', 'Имя'), Field::text('value', 'Значение')));
        $b->template('catalog', 'Каталог')->single()->topLevel()->listing(of: ['product'], label: 'Товары');
        $b->template('product', 'Товар')->parents('catalog')->fields(Field::text('title', 'Название'));
        $b->collection('tours', 'Поездки')->titleField('title')->fields(Field::text('title', 'Название'), Field::date('from', 'С'));
        $b->settings('contacts', 'Контакты')->fields(Field::text('phone', 'Телефон'));
        $b->form('call', 'Звонок')->fields(Field::text('phone', 'Телефон'));
        ResourceApplier::apply($b, new TestTemplate());
        ResourceApplier::apply($b, new TripsCollection());

        return $b->build();
    }

    #[Test]
    public function aRepeatedFieldKeyNamesTheSection(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->section('hero', 'Первый экран')->fields(Field::text('title', 'Заголовок'), Field::text('title', 'Ещё заголовок'));
        });

        self::assertCount(1, $problems);
        self::assertStringContainsString('Секция «hero»', $problems[0]);
        self::assertStringContainsString('«title»', $problems[0]);
        self::assertStringContainsString('повторяется', $problems[0]);
    }

    #[Test]
    public function aRepeatedKeyIsFoundInTemplatesCollectionsFormsAndRepeaters(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->template('page2', 'Страница')->fields(Field::text('a', 'A'), Field::text('a', 'B'));
            $s->collection('c', 'Коллекция')->fields(Field::text('x', 'X'))->tab('more', 'Ещё', Field::text('x', 'Опять X'));
            $s->form('f', 'Форма')->fields(Field::text('phone', 'Телефон'), Field::text('phone', 'Телефон 2'));
            $s->section('faq', 'FAQ')->fields(Field::repeater('items', 'Вопросы', Field::text('q', 'Вопрос'), Field::text('q', 'Вопрос 2')));
        });

        self::assertCount(4, $problems);
        $text = \implode("\n", $problems);
        foreach (['Шаблон «page2»', 'Коллекция «c»', 'Форма «f»', 'Секция «faq»'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        self::assertStringContainsString('«items»', $text, 'a repeater names its place');
    }

    #[Test]
    public function aCollectionFieldCannotBeCalledVersion(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->collection('releases', 'Релизы')->titleField('title')->fields(Field::text('title', 'Название'))->tab('more', 'Ещё', Field::text('version', 'Версия'));
            $s->template('tool', 'Программа')->fields(Field::text('version', 'Версия'));
            $s->section('about', 'О программе')->fields(Field::text('version', 'Версия'));
            $s->settings('app', 'Приложение')->fields(Field::text('version', 'Версия'));
            $s->collection('plans', 'Тарифы')->titleField('title')->fields(Field::text('title', 'Название'), Field::repeater('items', 'Пункты', Field::text('version', 'Версия')));
        });

        self::assertCount(1, $problems, 'the record of a collection carries its own `version`; in a template, a section, settings or a repeater the key is free');
        self::assertStringContainsString('Коллекция «releases»', $problems[0]);
        self::assertStringContainsString('«version»', $problems[0]);
        self::assertStringContainsString('Назовите поле иначе', $problems[0]);
    }

    #[Test]
    public function aSettingKeyRepeatedInTwoGroupsIsAProblem(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->settings('contacts', 'Контакты')->fields(Field::text('phone', 'Телефон'));
            $s->settings('header', 'Шапка')->fields(Field::text('phone', 'Телефон в шапке'));
        });

        self::assertCount(1, $problems);
        self::assertStringContainsString('Настройки «header»', $problems[0]);
        self::assertStringContainsString('«contacts»', $problems[0]);
    }

    #[Test]
    public function anEmptyOrWrongKeyIsAProblem(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->section('my section', 'Секция')->fields(Field::text('ok', 'Ок'));
            $s->template('Шаблон', 'Шаблон');
            $s->collection('', 'Пустая');
            $s->settings('a/b', 'Настройки')->fields(Field::text('x', 'X'));
            $s->form('call me', 'Форма')->fields(Field::text('a', 'A'));
            $s->menuArea('', 'Без ключа');
            $s->section('good', 'Хорошая')->fields(Field::text('bad key', 'Плохой'), Field::text('-start', 'Начало'));
        });

        $text = \implode("\n", $problems);
        foreach (['Секция «my section»', 'Шаблон «Шаблон»', 'Коллекция «»', 'Настройки «a/b»', 'Форма «call me»', 'Меню «»', '«bad key»', '«-start»'] as $needle) {
            self::assertStringContainsString($needle, $text);
        }
        self::assertStringContainsString('латинские буквы', $text);
    }

    #[Test]
    public function parentsAndListingNameKnownTemplates(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->template('shop', 'Магазин')->parents('catalog', 'blog')->listing(of: ['product', 'blog_article'], label: 'Товары');
        });

        self::assertCount(2, $problems);
        self::assertStringContainsString('Шаблон «shop»', $problems[0]);
        self::assertStringContainsString('parents()', $problems[0]);
        self::assertStringContainsString('«catalog»', $problems[0]);
        self::assertStringNotContainsString('«blog»', \explode('Есть:', $problems[0])[0]);
        self::assertStringContainsString('listing(of:)', $problems[1]);
        self::assertStringContainsString('«product»', $problems[1]);
    }

    #[Test]
    public function aFormWithoutFieldsIsAProblem(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->form('empty', 'Пустая форма');
        });

        self::assertCount(1, $problems);
        self::assertStringContainsString('Форма «empty»', $problems[0]);
        self::assertStringContainsString('нет ни одного поля', $problems[0]);
    }

    #[Test]
    public function aTitleFieldThatDoesNotExistIsAProblem(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->collection('tours', 'Поездки')->titleField('name')->fields(Field::text('title', 'Название'));
        });

        self::assertCount(1, $problems);
        self::assertStringContainsString('Коллекция «tours»', $problems[0]);
        self::assertStringContainsString('titleField', $problems[0]);
        self::assertStringContainsString('«name»', $problems[0]);
    }

    #[Test]
    public function aMenuAreaDescribedTwiceIsAProblem(): void
    {
        $problems = $this->problems(static function (SchemaBuilder $s): void {
            $s->menuArea('header', 'Шапка');
            $s->menuArea('footer', 'Подвал');
            $s->menuArea('header', 'Шапка ещё раз', depth: 2);
        });

        self::assertCount(1, $problems);
        self::assertStringContainsString('Меню «header»', $problems[0]);
        self::assertStringContainsString('больше одного раза', $problems[0]);
    }
}
