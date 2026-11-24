<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Key;

/**
 * One field of a collection the generator is asked to write. `column` is the original field or table column name.
 *
 * @internal
 */
final class FieldSpec
{
    /** Types the generator knows → class of Itk\Cms\Field\. */
    public const TYPES = [
        'text' => 'Text', 'textarea' => 'Textarea', 'description' => 'Description', 'headline' => 'Headline', 'article' => 'Article',
        'number' => 'Number', 'toggle' => 'Toggle', 'select' => 'Select', 'date' => 'Date', 'datetime' => 'DateTime',
        'image' => 'Image', 'photos' => 'Photos', 'link' => 'Link',
    ];

    /**
     * @param list<string> $options values of a select
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly ?int $max = null,
        public readonly array $options = [],
        public readonly ?string $column = null,
    ) {
        if (!Key::isValid($key)) {
            throw new \InvalidArgumentException(I18n::t('model.gen_field_key_invalid', ['key' => $key, 'rule' => Key::rule()]));
        }
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException(I18n::t('model.gen_field_type_unknown', ['key' => $key, 'type' => $type, 'types' => \implode(', ', \array_keys(self::TYPES))]));
        }
    }

    public function className(): string
    {
        return self::TYPES[$this->type];
    }
}
