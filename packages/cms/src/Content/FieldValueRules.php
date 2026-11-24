<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\ValueValidatingFieldInterface;
use Itk\Cms\I18n\I18n;

/** @internal Value constraints shared by every writer; input shape and access checks belong to each transport. */
final class FieldValueRules
{
    /** @return list<array{code: string, message: string}> */
    public static function problems(FieldInterface $field, mixed $value, bool $plainText = false): array
    {
        // Empty optional fields stay unset, including custom fields. Requiredness belongs to the writer.
        if (!$field->isRequired() && \in_array($value, [null, '', false, []], true)) {
            return [];
        }
        $problems = [];
        $canonical = \is_scalar($value) && \in_array($field->type(), ['text', 'textarea', 'headline', 'select'], true) ? (string) $value : $value;
        if (\is_string($canonical)) {
            $max = $field->maxLength();
            if (($plainText || !$field->isRich()) && null !== $max && \mb_strlen($canonical) > $max) {
                $problems[] = ['code' => 'max', 'message' => I18n::t('content.field_max_length', ['count' => $max])];
            }
            if ('' !== $canonical && 'select' === $field->type() && !\in_array($canonical, \array_column($field->choices(), 0), true)) {
                $problems[] = ['code' => 'choice', 'message' => I18n::t('content.field_choice_invalid')];
            }
            $input = $field->toArray()['inputType'] ?? '';
            if ('' !== $canonical && \in_array($input, ['date', 'datetime-local'], true) && !self::validDate($canonical, $input)) {
                $problems[] = ['code' => 'date', 'message' => I18n::t('content.field_date_invalid')];
            }
            if ('' !== $canonical && null !== $field->regex() && !self::matches($field->regex(), $canonical)) {
                $problems[] = ['code' => 'pattern', 'message' => $field->regexMessage()];
            }
        }
        if ($field instanceof ValueValidatingFieldInterface) {
            foreach ($field->validationErrors($value) as $message) {
                $problems[] = ['code' => 'custom', 'message' => $message];
            }
        }

        return $problems;
    }

    private static function validDate(string $value, string $input): bool
    {
        if (1 !== \preg_match('date' === $input ? '/^(\d{4})-(\d{2})-(\d{2})$/' : '/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m)) {
            return false;
        }

        return \checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function matches(string $pattern, string $value): bool
    {
        $result = @\preg_match($pattern, $value);
        if (false === $result && \PREG_JIT_STACKLIMIT_ERROR === \preg_last_error()) {
            $pattern = \ltrim($pattern);
            $result = @\preg_match($pattern[0] . '(*NO_JIT)' . \substr($pattern, 1), $value);
        }

        return 1 === $result;
    }
}
