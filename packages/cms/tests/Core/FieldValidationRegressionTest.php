<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\CollectionImporter;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Field\AbstractField;
use Itk\Cms\Field\Date;
use Itk\Cms\Field\DateTime;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\ValueValidatingFieldInterface;
use Itk\Cms\Mcp\Support\FieldInput;
use Itk\Cms\Tests\Core\Fixture\ResourceKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FieldValidationRegressionTest extends ResourceKernelTestCase
{
    #[Test]
    public function datesStartEmptyUnlessTheSiteProvidesAnExplicitDefault(): void
    {
        $values = self::getContainer()->get(FieldValues::class);
        foreach ([Date::make('Date', 'date'), DateTime::make('Time', 'time')] as $field) {
            self::assertSame('', $values->defaultFor($field));
            self::assertSame('', $values->defaultFor($field->required()));
            self::assertCount(1, $values->validate([$field], [$field->key() => '']));
        }
        self::assertSame('2026-11-01', $values->defaultFor(Date::make('Date', 'date')->required()->default('2026-11-01')));
        self::assertSame('2026-11-01T09:00', $values->defaultFor(DateTime::make('Time', 'time')->required()->default('2026-11-01T09:00')));
        self::assertNotSame('', $values->defaultFor(Text::make('Title', 'title')->required()), 'ordinary required text keeps its placeholder');
    }

    #[Test]
    public function publicationRequiresRealDatesAfterCreatingADraft(): void
    {
        $item = $this->collections->create('tours', ['city' => 'Test city']);
        try {
            self::assertSame('', $item['dateStart']);
            self::assertSame('', $item['dateEnd']);
            try {
                $this->collections->publish('tours', $item['id']);
                self::fail('A draft with missing required dates was published');
            } catch (ContentException $error) {
                self::assertSame(422, $error->status());
                self::assertSame(['dateStart', 'dateEnd'], \array_column($error->details(), 'path'));
            }
            self::assertSame('draft', $this->collections->get('tours', $item['id'])['item']['status']);
            $this->collections->update('tours', $item['id'], ['dateStart' => '2026-11-01', 'dateEnd' => '2026-11-09']);
            self::assertSame('published', $this->collections->publish('tours', $item['id'])['status']);
        } finally {
            $this->collections->remove('tours', $item['id']);
            self::getContainer()->get(EntityManagerInterface::class)
                ->createQuery('DELETE FROM Itk\Cms\Entity\Revision r WHERE r.resourceId = :id')
                ->setParameter('id', $item['id'])->execute();
        }
    }

    #[Test]
    public function allContentEntryPointsRejectTheSameInvalidFieldValues(): void
    {
        foreach ([['state', 'other'], ['title', \str_repeat('я', 61)], ['start', 'tomorrow'], ['start', '2026-02-30']] as [$key, $value]) {
            $data = ['title' => 'Valid', $key => $value];
            try {
                $this->collections->create('test_trips', $data);
                self::fail($key . ': invalid PHP create accepted');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
            $result = self::getContainer()->get(CollectionImporter::class)->import('test_trips', [['invalid-' . $key, $data]], 'test:validation');
            self::assertSame(0, $result->created, $key);
            self::assertCount(1, $result->failed, $key);
        }
        $item = $this->trip('Valid');
        try {
            $this->collections->update('test_trips', $item['id'], ['state' => 'other']);
            self::fail('Invalid update accepted');
        } catch (ContentException $e) {
            self::assertSame(422, $e->status());
        }
        self::assertSame('open', $this->collections->get('test_trips', $item['id'])['item']['state']);
    }

    #[Test]
    public function scalarCoercionCannotBypassTextLimitsOrDateValidation(): void
    {
        $values = self::getContainer()->get(FieldValues::class);
        foreach ([[Text::make('Text', 'text')->max(3), 123456], [Date::make('Date', 'date'), 20260101]] as [$field, $value]) {
            try {
                $values->normalize([$field], [$field->key() => $value]);
                self::fail('Scalar constraints bypassed');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
        self::assertSame(['text' => '12'], $values->normalize([Text::make('Text', 'text')->max(3)], ['text' => 12]));
        self::assertSame(['text' => ''], $values->normalize([Text::make('Text', 'text')->max(3)], ['text' => null]));
    }

    #[Test]
    public function sharedRulesAndCustomFieldHooksAreUsedByContentAndMcp(): void
    {
        $values = self::getContainer()->get(FieldValues::class);
        $input = self::getContainer()->get(FieldInput::class);
        $custom = new class('Code', 'code') extends AbstractField implements ValueValidatingFieldInterface {
            protected function wireType(): string
            {
                return 'custom-code';
            }

            public function validationErrors(mixed $value): array
            {
                return 'valid' === $value ? [] : ['Use valid'];
            }
        };
        foreach ([[Select::make('State', 'state')->options(['yes' => 'Yes']), 'no'], [Text::make('Title', 'title')->max(3), 'four'], [Date::make('Date', 'date'), 'tomorrow'], [$custom, 'bad']] as [$field, $value]) {
            self::assertNotSame([], $values->validate([$field], [$field->key() => $value]));
            self::assertNotSame([], $input->problems([$field], [$field->key() => $value]));
            try {
                $values->normalize([$field], [$field->key() => $value]);
                self::fail('Invalid normalization accepted');
            } catch (ContentException $e) {
                self::assertSame(422, $e->status());
            }
        }
        self::assertSame([], $values->validate([$custom], ['code' => 'valid']));
        self::assertSame([], $input->problems([$custom], ['code' => 'valid']));
    }
}
