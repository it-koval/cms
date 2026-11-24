<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\FieldValueRules;
use Itk\Cms\Field\AbstractField;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\ValueValidatingFieldInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldValueRulesTest extends TestCase
{
    #[Test]
    public function emptyOptionalValuesHaveNoChoiceOrCustomValidationErrors(): void
    {
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
        foreach ([Select::make('State', 'state')->options(['yes' => 'Yes']), $custom] as $field) {
            foreach ([null, '', false, []] as $value) {
                self::assertSame([], FieldValueRules::problems($field, $value), $field->key());
            }
            self::assertNotSame([], FieldValueRules::problems($field, 'bad'));
        }
        self::assertSame([], FieldValueRules::problems(Select::make('State', 'state')->required()->options(['yes' => 'Yes']), ''), 'requiredness is checked separately');
    }
}
