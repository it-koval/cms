<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;

/**
 * Short human descriptions of a changed value («было → стало»). Mirrors describeValue / describeChange of admin/src/utils/fields.js.
 *
 * @internal
 */
final class ChangeDescriber
{
    /** Formatting groups: id (the text of content.mark_<id>) => XPath of the elements. */
    private const MARKS = [
        'headings' => '//h2 | //h3',
        'quotes' => '//blockquote',
        'photos' => '//img',
        'links' => '//a',
        'emphasis' => '//strong | //em',
        'lists' => '//ul | //ol',
        'dividers' => '//hr',
        'tables' => '//table',
        'gradient' => "//span[contains(concat(' ', normalize-space(@class), ' '), ' grad ')]",
    ];

    public function __construct(private readonly HtmlSanitizer $html, private readonly FieldValues $values)
    {
    }

    private static function clip(string $s): string
    {
        return \mb_strlen($s) > 56 ? \mb_substr($s, 0, 54) . '…' : $s;
    }

    public function describeValue(?FieldInterface $f, mixed $v): string
    {
        if (null !== $f && 'select' === $f->type()) {
            foreach ($f->choices() as [$value, $label]) {
                if ($value === (string) $v) {
                    return $label;
                }
            }

            return \is_scalar($v) ? (string) $v : '';
        }
        if (null !== $f && $f->isRich()) {
            return self::clip(\str_replace("\n", ' / ', $this->html->toText($v))) ?: I18n::t('content.change_empty');
        }
        if (\is_array($v) && !\array_is_list($v)) {
            if (\array_key_exists('src', $v)) {
                return (string) (($v['alt'] ?? '') ?: (!empty($v['src']) ? I18n::t('content.change_photo') : I18n::t('content.change_no_photo')));
            }
            if (\array_key_exists('kind', $v)) {
                return 'url' === $v['kind'] ? (string) ($v['url'] ?? '') : ('modal' === $v['kind'] ? I18n::t('content.change_call_form') : (string) ($v['page'] ?? ''));
            }
        }
        if (null !== $f && 'photos' === $f->type() && \is_array($v)) {
            return [] === $v ? I18n::t('content.change_no_photo') : I18n::t('content.change_photos', ['count' => \count($v)]);
        }
        if (\is_array($v)) {
            $n = \count($v);

            return I18n::t('content.change_items', ['count' => $n]);
        }
        if (\is_bool($v)) {
            return $v ? I18n::t('content.change_on') : I18n::t('content.change_off');
        }

        return self::clip(\is_scalar($v) ? (string) $v : '') ?: I18n::t('content.change_empty');
    }

    /**
     * Short description of a changed value: { val, old, where? }.
     *
     * @return array{val: string, old: string, where?: string}
     */
    public function describeChange(FieldInterface $f, mixed $cur, mixed $old): array
    {
        if ('repeater' === $f->type()) {
            $d = $this->describeItemChange($f, $cur, $old);
            if (null !== $d) {
                return $d;
            }
        }
        if (!$f->isRich()) {
            return ['val' => $this->describeValue($f, $cur), 'old' => $this->describeValue($f, $old)];
        }
        $mode = (string) $f->richMode();
        $a = \str_replace("\n", ' / ', $this->html->toText($cur));
        $b = \str_replace("\n", ' / ', $this->html->toText($old));
        $marks = $this->changedMarks($cur, $old, $mode);
        if ($a === $b) {
            if ($f->isHeadline()) {
                return ['val' => \in_array('gradient', $marks, true) ? I18n::t('content.change_gradient_changed') : I18n::t('content.change_line_break'), 'old' => ''];
            }

            return ['val' => [] !== $marks ? I18n::t('content.change_format_marks', ['marks' => $this->markNames($marks)]) : I18n::t('content.change_format_text'), 'old' => ''];
        }
        $t = $this->textChange($a, $b);
        if ([] !== $marks && !$f->isHeadline()) {
            $t['val'] = I18n::t('content.change_text_with_marks', ['text' => $t['val'], 'marks' => $this->markNames($marks)]);
        }

        return $t;
    }

    /** Title of a repeater item for its collapsed header. */
    public function repeaterTitle(FieldInterface $f, array $item, int $i): string
    {
        $templates = $f->toArray()['itemTitle'] ?? [];
        if (\is_array($templates) && [] !== $templates) {
            $title = ItemTemplate::first($templates, fn (string $key): string => $this->itemValueText($f, $item, $key));
            if ('' !== $title) {
                return $title;
            }
        }
        $net = $f->subField('net');
        if (null !== $net) {
            foreach ($net->choices() as [$value, $label]) {
                if ($value === ($item['net'] ?? null)) {
                    return $label;
                }
            }

            return I18n::t('content.change_social');
        }
        foreach ($f->subFields() as $s) {
            if ('text' === $s->type()) {
                if (!empty($item[$s->key()])) {
                    return (string) $item[$s->key()];
                }
                break;
            }
        }
        $noun = $f->toArray()['noun'] ?? null;

        return ($noun ? \mb_strtoupper(\mb_substr($noun, 0, 1)) . \mb_substr($noun, 1) : I18n::t('content.change_item_noun')) . ' ' . ($i + 1);
    }

    /** The value of a sub-field as a title template shows it. A record of another collection is not looked up here (the history is written without them), so a Relation gives nothing. */
    private function itemValueText(FieldInterface $f, array $item, string $key): string
    {
        $s = $f->subField($key);
        $v = $item[$key] ?? null;
        if (null === $s || null === $v) {
            return '';
        }
        if ('select' === $s->type()) {
            foreach ($s->choices() as [$value, $label]) {
                if ((string) $value === (string) $v) {
                    return $label;
                }
            }

            return '';
        }
        if ($s->isRich()) {
            return \str_replace("\n", ' ', $this->html->toText($v));
        }
        if ('toggle' === $s->type()) {
            return $v ? $s->label() : '';
        }
        if ('image' === $s->type()) {
            return \is_array($v) && !empty($v['src']) ? (string) ($v['alt'] ?? '') : '';
        }

        if ('number' === $s->type() && !empty($s->toArray()['grouped']) && \is_numeric($v)) {
            return self::groupedNumber($v + 0);
        }

        return \is_scalar($v) && !\in_array($s->type(), ['relation', 'photos', 'link', 'repeater', 'readonly'], true) ? (string) $v : '';
    }

    /**
     * A number with thousands separators of the current language («110 000» in Russian, «110,000» in English), as `Intl.NumberFormat(locale)` of the admin writes it,
     * with the fraction kept whole. Without ext-intl the separators of the three languages of the bundle are used.
     */
    private static function groupedNumber(int|float $n): string
    {
        $locale = I18n::locale();
        if (\class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 20);
            $text = $f->format($n);
            if (\is_string($text)) {
                return $text;
            }
        }

        return self::groupedByHand($n, $locale);
    }

    /** The separators of the languages of the bundle, for a server without ext-intl. */
    private static function groupedByHand(int|float $n, string $locale): string
    {
        $plain = (string) \json_encode($n); // the shortest text that gives the number back: the fraction is kept as it is
        $decimals = \str_contains($plain, '.') && !\str_contains($plain, 'e') ? \strlen(\substr($plain, (int) \strrpos($plain, '.') + 1)) : 0;
        $comma = \in_array($locale, ['ru', 'kk'], true);

        return \number_format($n, $decimals, $comma ? ',' : '.', $comma ? "\u{00A0}" : ',');
    }

    /** @return array{val: string, old: string, where: string}|null */
    private function describeItemChange(FieldInterface $f, mixed $cur, mixed $old): ?array
    {
        $a = \is_array($cur) ? \array_values($cur) : [];
        $b = \is_array($old) ? \array_values($old) : [];
        if (\count($a) !== \count($b)) {
            return null;
        }
        foreach ($a as $i => $item) {
            if (($item['_id'] ?? null) !== ($b[$i]['_id'] ?? null)) {
                return ['where' => '', 'val' => I18n::t('content.change_order_changed'), 'old' => ''];
            }
        }
        $diffs = [];
        foreach ($a as $i => $item) {
            foreach ($f->subFields() as $s) {
                if ($s->isNote()) {
                    continue;
                }
                if (!$this->values->equal($s, $item[$s->key()] ?? null, $b[$i][$s->key()] ?? null)) {
                    $diffs[] = [$s, $item, $b[$i], $i];
                }
            }
        }
        if ([] === $diffs) {
            return null;
        }
        [$s, $item, $was, $i] = $diffs[0];
        $d = $this->describeChange($s, $item[$s->key()] ?? null, $was[$s->key()] ?? null);
        $title = $this->repeaterTitle($f, $item, $i);
        $where = \count($diffs) > 1 ? I18n::t('content.change_where_more', ['title' => $title, 'label' => $s->label(), 'count' => \count($diffs) - 1]) : I18n::t('content.change_where', ['title' => $title, 'label' => $s->label()]);

        return ['where' => $where, 'val' => $d['val'], 'old' => $d['old']];
    }

    /** @return array{val: string, old: string} */
    private function textChange(string $a, string $b): array
    {
        if ('' === $a) {
            return ['val' => I18n::t('content.change_empty'), 'old' => self::clip($b)];
        }
        if ('' === $b) {
            return ['val' => self::clip($a), 'old' => I18n::t('content.change_empty')];
        }
        if (\mb_strlen($a) <= 56 && \mb_strlen($b) <= 56) {
            return ['val' => $a, 'old' => $b];
        }
        $A = \mb_str_split($a);
        $B = \mb_str_split($b);
        $p = 0;
        while ($p < \count($A) && $p < \count($B) && $A[$p] === $B[$p]) {
            ++$p;
        }
        $tidy = static fn (array $chars): string => (string) \preg_replace('~^[\s/]+|[\s/]+$~u', '', \implode('', $chars));
        if ($p === \count($B)) {
            return ['val' => I18n::t('content.change_appended', ['text' => self::clip($tidy(\array_slice($A, $p)))]), 'old' => ''];
        }
        if ($p === \count($A)) {
            return ['val' => I18n::t('content.change_removed', ['text' => self::clip($tidy(\array_slice($B, $p)))]), 'old' => ''];
        }
        $from = \max(0, $p - 16);
        if ($from > 0) {
            $sp = \array_search(' ', \array_slice($A, $from, null, true), true);
            if (false !== $sp && $sp < $p) {
                $from = $sp + 1;
            }
        }
        $piece = static fn (array $X): string => ($from > 0 ? '…' : '') . \trim(\implode('', \array_slice($X, $from, 46))) . ($from + 46 < \count($X) ? '…' : '');

        return ['val' => $piece($A), 'old' => $piece($B)];
    }

    /** @param list<string> $marks ids of MARKS */
    private function markNames(array $marks): string
    {
        $names = [];
        foreach ($marks as $id) {
            $key = 'content.mark_' . $id; // i18n-dynamic: content.mark_
            $names[] = I18n::t($key);
        }

        return \implode(', ', $names);
    }

    /** @return list<string> */
    private function changedMarks(mixed $cur, mixed $old, string $mode): array
    {
        $a = $this->markup($cur, $mode);
        $b = $this->markup($old, $mode);
        $out = [];
        foreach (\array_keys(self::MARKS) as $i => $name) {
            if ($a[$i] !== $b[$i]) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function markup(mixed $html, string $mode): array
    {
        $doc = new \DOMDocument();
        $prev = \libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $this->html->sanitize($html, $mode) . '</body></html>', \LIBXML_NOERROR | \LIBXML_NOWARNING);
        \libxml_clear_errors();
        \libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);
        $out = [];
        foreach (self::MARKS as $query) {
            $rows = [];
            foreach ($xp->query($query) ?: [] as $el) {
                /** @var \DOMElement $el */
                $rows[] = \strtoupper($el->tagName) . '|' . ($el->getAttribute('href') ?: $el->getAttribute('src')) . '|' . $el->textContent;
            }
            $out[] = \implode("\n", $rows);
        }

        return $out;
    }
}
