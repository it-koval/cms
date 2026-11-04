<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Support;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Security\Viewer;

/**
 * A field as the model reads it in site_schema: key, type, the format of its value, limits, options.
 *
 * @internal
 */
final class FieldDescriber
{
    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<array<string, mixed>> only the fields the viewer may see; notes are left out
     */
    public function describeAll(array $fields, Viewer $viewer, bool $inItem = false): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (!$f->isNote() && $f->visibleTo($viewer)) {
                $out[] = $this->describe($f, $viewer, $inItem);
            }
        }

        return $out;
    }

    /**
     * @param bool $inItem the field is inside an item of a list (repeater): a list is replaced as a whole, which changes what the model should do with a raw field
     *
     * @return array<string, mixed>
     */
    public function describe(FieldInterface $f, Viewer $viewer, bool $inItem = false): array
    {
        $a = $f->toArray();
        $out = ['key' => $f->key(), 'type' => $f->type(), 'label' => $f->label(), 'format' => $this->format($f)];
        if ($f->isRequired()) {
            $out['required'] = true;
        }
        if (null !== $f->maxLength() && !$f->isRich()) {
            $out['maxLength'] = $f->maxLength();
        }
        if ([] !== $f->choices()) {
            $out['options'] = \array_map(static fn (array $c): array => ['value' => (string) $c[0], 'label' => (string) $c[1]], $f->choices());
        }
        if (null !== $f->regex()) {
            $out['pattern'] = $f->regex();
        }
        if ($f->isLocked()) {
            $out['readonly'] = true;
        }
        if ($f->isRaw()) {
            $out['raw'] = true;
            $out['readonly'] = true;
            $out['note'] = $inItem
                ? 'Raw HTML/script. Nobody may add or change it through a key, administrators included: any such call gets an error. When you send this list, keep the _id of every existing item and leave this field out: its value stays with the item (or send it exactly as page_get shows it). An item without its _id is a new one and loses it. Tell the person it can be edited in the admin only.'
                : 'Raw HTML/script. Nobody may add or change it through a key, administrators included: any such call gets an error. Do not send this field; leave it as it is and tell the person it can be edited in the admin only.';
        }
        if ('' !== ($a['help'] ?? '')) {
            $out['help'] = Format::text($a['help'], 200);
        }
        if (isset($a['to'])) {
            $out['collection'] = $a['to'];
            $out['multiple'] = !empty($a['multi']);
        }
        if ('repeater' === $f->type()) {
            $out['itemFields'] = $this->describeAll($f->subFields(), $viewer, true);
            if (!empty($a['noun'])) {
                $out['itemName'] = $a['noun'];
            }
        }

        return $out;
    }

    public function format(FieldInterface $f): string
    {
        $input = $f->toArray()['inputType'] ?? '';

        return match (true) {
            'date' === $input => 'string YYYY-MM-DD',
            'datetime-local' === $input => 'string YYYY-MM-DDTHH:MM',
            'headline' === $f->type() => 'string, HTML limited to inline marks (<b>, <i>, <br>); the tags are cleaned on save',
            $f->isRich() && !empty($f->toArray()['article']) => 'string, HTML (cleaned on save: paragraphs, lists, links, headings, quotes, photos, simple tables: table > thead/tbody > tr > th/td with inline text only, no attributes except numeric colspan/rowspan)',
            $f->isRich() => 'string, HTML (cleaned on save: paragraphs, lists, links, headings)',
            'text' === $f->type() => 'string',
            'textarea' === $f->type() => 'string (may contain line breaks)',
            'number' === $f->type() => 'number',
            'toggle' === $f->type() => 'boolean',
            'select' === $f->type() => 'one of the option values',
            'image' === $f->type() => '{"src": "<path from the media library>", "alt": "<description>"}; get src from media_search or media_upload',
            'link' === $f->type() => '{"kind": "page"|"url"|"modal", "page": "/site/path", "url": "https://…"}; page for kind=page, url for kind=url, kind=modal opens the call-back form',
            'photos' === $f->type() => 'list of photos from the media library; an item is an address string (as page_get shows it) or {"src": "…", "alt": "…"}; items are kept as sent',
            'repeater' === $f->type() => 'list of objects with itemFields (omit _id for new items)',
            'relation' === $f->type() => !empty($f->toArray()['multi']) ? 'list of record ids' : 'record id (string)',
            default => $f->type(),
        };
    }
}
