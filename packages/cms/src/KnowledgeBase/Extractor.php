<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\ItemTemplate;
use Itk\Cms\Field\AbstractField;
use Itk\Cms\Field\Article;
use Itk\Cms\Field\Description;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\Headline;
use Itk\Cms\Field\Image;
use Itk\Cms\Field\Photos;
use Itk\Cms\Field\PublicFieldInterface;
use Itk\Cms\Field\Repeater;
use Itk\Cms\Field\Select;
use Itk\Cms\Field\Text;
use Itk\Cms\Field\Textarea;
use Itk\Cms\KnowledgeBase\Model\Block;
use Itk\Cms\KnowledgeBase\Model\LiveSource;
use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Itk\Cms\Schema\Schema;

/**
 * The text a visitor can read on a live page or item, as blocks in render order. Pure: the published snapshot and the schema in, blocks out.
 *
 * What is never indexed, so that every chunk is public and no consumer needs to filter by viewer:
 *   the draft (only LiveSource::$published reaches here), hidden sections, section types the schema does not know (the renderer skips them),
 *   raw() fields (embed codes, scripts), Note, the variant() select, Number, Toggle, Date, DateTime, Link, Relation,
 *   and fields without an explicit public-visibility contract or with isPublic() false.
 *
 * @internal
 */
final class Extractor
{
    public function __construct(
        private readonly Schema $schema,
        private readonly HtmlSanitizer $html,
    ) {
    }

    /** @return list<Block> */
    public function extract(LiveSource $live): array
    {
        return SourceKind::Page === $live->source->kind ? $this->page($live) : $this->item($live);
    }

    public static function allowsField(?FieldInterface $field): bool
    {
        return $field instanceof PublicFieldInterface && $field->isPublic() && !$field->isNote() && !$field->isRaw();
    }

    /**
     * The head block (title as heading; seo.description; the template's fields in schema order), then the visible sections in order.
     *
     * @return list<Block>
     */
    private function page(LiveSource $live): array
    {
        $published = $live->published;
        $description = \trim((string) (((array) ($published['seo'] ?? []))['description'] ?? ''));
        $blocks = $this->fields(
            $this->schema->templateFields($live->source->resourceKey),
            (array) ($published['fields'] ?? []),
            null,
            null,
            $live->source->title,
            'fields.',
            '' === $description ? [] : [$description],
            '' === $description ? null : 'seo.description',
            null,
            true,
        );
        $hasNoHeadBlock = [] === $blocks || null !== $blocks[0]->sectionId;
        if ($hasNoHeadBlock) {
            \array_unshift($blocks, new Block(null, null, 'title', $live->source->title, [$live->source->title]));
        }
        foreach ((array) ($published['sections'] ?? []) as $section) {
            if (!\is_array($section) || !empty($section['hidden'])) {
                continue;
            }
            $type = (string) ($section['type'] ?? '');
            $def = $this->schema->section($type);
            if (null === $def) {
                continue;
            }
            $blocks = [...$blocks, ...$this->fields($def->fieldList(), (array) ($section['data'] ?? []), (string) ($section['id'] ?? ''), $type, null, '')];
        }

        return $blocks;
    }

    /** @return list<Block> one block headed by the title field, plus one per repeater row and per Article subheading */
    private function item(LiveSource $live): array
    {
        $collection = $this->schema->collection($live->source->resourceKey);
        if (null === $collection) {
            return [];
        }

        return $this->fields($collection->fieldList(), $live->published, null, null, $live->source->title, '', [], null, $collection->titleFieldKey());
    }

    /**
     * The blocks of a list of fields over their values: plain contributions gather into one block under $heading; an Article's subheadings and each
     * Repeater row open blocks of their own (a row headed by Repeater::itemTitle() through ItemTemplate::first(), else its first Text sub-field, else the
     * heading it sits under). Without a heading the first Headline becomes it.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $values
     * @param list<string> $lead paragraphs that come before the fields in the gathered block
     * @param string|null $leadField the field of the gathered block when $lead is not empty
     * @param string|null $skip a field already used as the heading
     *
     * @return list<Block>
     */
    private function fields(array $fields, array $values, ?string $sectionId, ?string $sectionType, ?string $heading, string $path, array $lead = [], ?string $leadField = null, ?string $skip = null, bool $splitFields = false): array
    {
        $blocks = [];
        $plain = $lead;
        $plainField = $leadField;
        $flush = static function () use (&$blocks, &$plain, &$plainField, $sectionId, $sectionType, &$heading): void {
            if ([] !== $plain) {
                $blocks[] = new Block($sectionId, $sectionType, (string) $plainField, $heading, $plain);
            }
            $plain = [];
            $plainField = null;
        };
        foreach ($fields as $f) {
            $value = $values[$f->key()] ?? null;
            if ($f->key() === $skip || null === $value || '' === $value || [] === $value) {
                continue;
            }
            $field = $path . $f->key();
            $got = $this->contribution($f, $value);
            if (null === $got) {
                continue;
            }
            if ($splitFields && null !== $plainField && $plainField !== $field) {
                $flush();
            }
            if ('heading' === $got) {
                $text = $this->html->oneLine($value);
                if ('' === $text) {
                    continue;
                }
                if (null === $heading) {
                    $heading = $text;
                    continue;
                }
                $got = [$text];
            }
            if (\is_array($got)) {
                if ([] !== $got) {
                    $plain = [...$plain, ...$got];
                    $plainField ??= $field;
                }
                continue;
            }
            if ('runs' === $got) {
                foreach ($this->html->runs($value) as $i => $run) {
                    if (0 === $i && null === $run['heading']) {
                        if ([] !== $run['paragraphs']) {
                            $plain = [...$plain, ...$run['paragraphs']];
                            $plainField ??= $field;
                        }
                        continue;
                    }
                    $flush();
                    if ([] !== $run['paragraphs']) {
                        $blocks[] = new Block($sectionId, $sectionType, $field, $run['heading'], $run['paragraphs']);
                    }
                }
                continue;
            }
            $flush();
            foreach (\array_values((array) $value) as $i => $row) {
                if (!\is_array($row)) {
                    continue;
                }
                [$rowHeading, $rowSkip] = $this->rowHeading($f, $row);
                $blocks = [...$blocks, ...$this->fields($f->subFields(), $row, $sectionId, $sectionType, $rowHeading ?? $heading, $field . '.' . $i . '.', [], null, $rowSkip)];
            }
        }
        $flush();

        return $blocks;
    }

    /**
     * The heading of a repeater row: itemTitle() rendered as the admin renders it, else the first Text sub-field (then skipped as a paragraph).
     *
     * @param array<string, mixed> $row
     *
     * @return array{?string, ?string} heading, the sub-field it took
     */
    private function rowHeading(FieldInterface $repeater, array $row): array
    {
        $templates = $repeater->toArray()['itemTitle'] ?? null;
        if (\is_array($templates)) {
            $title = ItemTemplate::first($templates, fn (string $key): string => $this->valueText($repeater->subField($key), $row[$key] ?? null));
            if ('' !== $title) {
                return [$title, null];
            }
        }
        foreach ($repeater->subFields() as $sub) {
            if ($sub instanceof Text && self::allowsField($sub) && \is_string($row[$sub->key()] ?? null) && '' !== \trim($row[$sub->key()])) {
                return [\trim($row[$sub->key()]), $sub->key()];
            }
        }

        return [null, null];
    }

    /** A value as a person reads it, for itemTitle(): '' for a field that gives no text. */
    private function valueText(?FieldInterface $f, mixed $value): string
    {
        if (null === $f || null === $value) {
            return '';
        }
        $got = $this->contribution($f, $value);
        if ('heading' === $got) {
            return $this->html->oneLine($value);
        }
        if ('runs' === $got) {
            return \implode(' ', \array_merge(...\array_map(static fn (array $run): array => $run['paragraphs'], $this->html->runs($value))));
        }

        return \is_array($got) ? \implode(' ', $got) : '';
    }

    /**
     * The field policy: the one table of which field types give text, and how. No default arm that indexes: a field class added to the bundle
     * gives nothing until it has a row here.
     *
     * @return list<string>|'heading'|'repeater'|'runs'|null paragraphs; 'heading' is a Headline (fields() makes the first one the block heading); 'repeater' / 'runs' open blocks; null = nothing
     */
    private function contribution(FieldInterface $f, mixed $value): array|string|null
    {
        if (!self::allowsField($f)) {
            return null;
        }

        return match (true) {
            $f instanceof Headline => 'heading',
            $f instanceof Text, $f instanceof Textarea => self::line($value),
            $f instanceof Description, $f instanceof Article => 'runs',
            $f instanceof Select => $this->choice($f, $value),
            $f instanceof Image => self::line(\is_array($value) ? ($value['alt'] ?? null) : null),
            $f instanceof Photos => self::alts($value),
            $f instanceof Repeater => 'repeater',
            // Custom implementations opt into the public contract and one of the supported text formats.
            // Core classes keep their explicit policy above (Date, for example, also has wire type "text").
            default => $f instanceof AbstractField ? null : match ($f->type()) {
                'text' => self::line($value),
                'headline' => 'heading',
                'textarea' => $f->isRich() ? 'runs' : self::line($value),
                'select' => $this->choice($f, $value),
                'image' => self::line(\is_array($value) ? ($value['alt'] ?? null) : null),
                'photos' => self::alts($value),
                'repeater' => 'repeater',
                default => null,
            },
        };
    }

    /** @return list<string> */
    private static function line(mixed $value): array
    {
        if (!\is_scalar($value)) {
            return [];
        }
        $text = \trim((string) \preg_replace('/\s+/u', ' ', (string) $value));

        return '' === $text ? [] : [$text];
    }

    /** @return list<string> */
    private static function alts(mixed $value): array
    {
        $out = [];
        foreach (\is_array($value) ? $value : [] as $photo) {
            if (\is_array($photo)) {
                $out = [...$out, ...self::line($photo['alt'] ?? null)];
            }
        }

        return $out;
    }

    /**
     * "Label: option label" of the chosen option; nothing for the variant() select (the look of a section is not content).
     *
     * @return list<string>|null
     */
    private function choice(FieldInterface $f, mixed $value): ?array
    {
        if (!empty($f->toArray()['variant']) || !\is_scalar($value)) {
            return null;
        }
        foreach ($f->choices() as [$option, $label]) {
            if ($option === (string) $value) {
                return self::line($f->label() . ': ' . $label);
            }
        }

        return null;
    }
}
