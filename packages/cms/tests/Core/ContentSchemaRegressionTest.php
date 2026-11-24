<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Note;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\ResourceApplier;
use Itk\Cms\Resource\SectionResource;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\SchemaBuilder;
use Itk\Cms\Schema\SectionType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentSchemaRegressionTest extends TestCase
{
    #[Test]
    public function collectionMetadataKeysAreReservedOnlyAtTheRecordLevel(): void
    {
        foreach (['id', 'status', 'locale', 'locales', 'position', 'version', 'translated', 'updatedAt', 'awaiting', 'returned', 'changed', 'changes', 'parentId'] as $key) {
            $b = new SchemaBuilder('ru');
            $b->collection('test_reserved', 'Reserved')->fields(Text::make('Field', $key));
            self::assertNotSame([], $b->build()->validate(), $key);
        }
        $b = new SchemaBuilder('ru');
        $b->collection('test_free', 'Free')->fields(Text::make('State', 'state'));
        self::assertSame([], $b->build()->validate());
    }

    #[Test]
    public function h1ReferencesAreCheckedInTemplatesAndSections(): void
    {
        $b = new SchemaBuilder('ru');
        $b->template('test_h1', 'Heading')->h1('typo')->fields(Text::make('Title', 'title'));
        $b->section('test_hero', 'Hero')->group(SectionType::FIRST_SCREEN)->h1('typo')->fields(Headline::make('Title', 'title'));
        self::assertCount(2, $b->build()->validate());
    }

    #[Test]
    public function repeatedNoteLabelsAreNotDuplicateDataKeys(): void
    {
        $b = new SchemaBuilder('ru');
        $b->section('test_notes', 'Notes')->fields(Note::make('Help'), Note::make('Help'));
        self::assertSame([], $b->build()->validate());
    }

    #[Test]
    public function explicitFirstScreenDoesNotDependOnTheGroupLanguage(): void
    {
        foreach (['Первый экран', 'First screen'] as $group) {
            $s = (new SectionType('test_hero', 'Hero'))->group($group)->firstScreen()->fields(Headline::make('Title', 'title'));
            self::assertSame('title', $s->h1Field());
            self::assertTrue($s->isFirstScreen());
            self::assertTrue($s->toArray()['firstScreen']);
        }
        $other = (new SectionType('test_other', 'Other'))->group(SectionType::FIRST_SCREEN)->firstScreen(false);
        self::assertNull($other->h1Field());
        self::assertFalse($other->toArray()['firstScreen']);
        self::assertTrue((new SectionType('test_legacy', 'Legacy'))->group(SectionType::FIRST_SCREEN)->toArray()['firstScreen']);
    }

    #[Test]
    public function resourceCanDeclareFirstScreenWithAnEnglishGroup(): void
    {
        $b = new SchemaBuilder('en');
        ResourceApplier::apply($b, new class extends SectionResource {
            public function key(): string
            {
                return 'test_resource_hero';
            }

            public function title(): string
            {
                return 'Hero';
            }

            public function group(): string
            {
                return 'First screen';
            }

            public function firstScreen(): ?bool
            {
                return true;
            }

            public function fields(): iterable
            {
                yield Headline::make('Title', 'title');
            }
        });
        self::assertSame('title', $b->build()->section('test_resource_hero')->h1Field());
    }

    #[Test]
    public function anEmptyCollectionHasASafeTitleKey(): void
    {
        self::assertSame('title', (new Collection('test_empty', 'Empty'))->titleFieldKey());
    }
}
