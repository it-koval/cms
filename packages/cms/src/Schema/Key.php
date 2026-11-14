<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\I18n\I18n;

/** The format of a key (section, template, collection, settings group, form, menu area, field): the same in schema.php and in resource classes. */
final class Key
{
    /** Latin letters, digits, _ and -, the first one is a letter or a digit: the key goes into JSON paths of queries, file names and URLs. */
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_\-]*$/';

    private function __construct()
    {
    }

    public static function isValid(string $key): bool
    {
        return 1 === \preg_match(self::PATTERN, $key);
    }

    /** What a key may be, as a sentence for the error. */
    public static function rule(): string
    {
        return I18n::t('model.key_rule');
    }
}
