<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Support\Json;
use Itk\Cms\Support\Url;

/**
 * Defaults, validation, normalisation and equality of field values. Mirrors admin/src/utils/fields.js.
 *
 * @internal
 */
final class FieldValues
{
    public function __construct(private readonly HtmlSanitizer $html, private readonly AdminLocale $locale)
    {
    }

    /** Default of a field for a NEW section or repeater item (required texts get a visible placeholder). */
    public function defaultFor(FieldInterface $f): mixed
    {
        if ($f->hasDefault()) {
            return $f->defaultValue();
        }
        if ('text' === $f->type() && \in_array($f->toArray()['inputType'] ?? '', ['date', 'datetime-local'], true)) {
            return '';
        }

        return match ($f->type()) {
            'text', 'textarea' => $f->isRequired() ? $this->placeholder('content.field_default_item') : '',
            'headline' => $f->isRequired() ? $this->placeholder('content.field_default_section') : '',
            'toggle' => false,
            'number' => '',
            'select' => $f->choices()[0][0] ?? '',
            'image' => ['src' => '', 'alt' => ''],
            'link' => ['kind' => 'page', 'page' => '/', 'url' => ''],
            'repeater', 'photos' => [],
            default => '',
        };
    }

    /** The visible placeholder of a required text is saved with the data of the site, so it is in the language of the content, not of the screen. */
    private function placeholder(string $key): string
    {
        return $this->locale->runContent(static fn (): string => I18n::t($key));
    }

    /** Empty value of a field (page fields, settings, collection records): texts start empty. */
    public function emptyFor(FieldInterface $f): mixed
    {
        if (\in_array($f->type(), ['text', 'textarea', 'headline'], true) && !$f->hasDefault()) {
            return '';
        }

        return $this->defaultFor($f);
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return array<string, mixed>
     */
    public function defaults(array $fields, bool $empty = false): array
    {
        $out = [];
        foreach ($fields as $f) {
            if (!$f->isNote()) {
                $out[$f->key()] = $empty ? $this->emptyFor($f) : $this->defaultFor($f);
            }
        }

        return $out;
    }

    /** True when a REQUIRED field has no usable value. */
    public function isMissing(FieldInterface $f, mixed $v): bool
    {
        if (!$f->isRequired()) {
            return false;
        }
        if ($f->isRich()) {
            return $this->html->isEmpty($v);
        }
        if ('image' === $f->type()) {
            return !(\is_array($v) && !empty($v['src']));
        }
        if (\is_array($v)) {
            return [] === $v;
        }

        return '' === \trim((string) ($v ?? ''));
    }

    public function requiredMessage(FieldInterface $f): string
    {
        return \preg_match('/^Заголовок/u', $f->label()) // i18n-ignore: the label is written by the developer of the site, not by the bundle
            ? I18n::t('content.required_heading')
            : I18n::t('content.required_field', ['label' => $f->label()]);
    }

    /**
     * Required-field errors, including repeater sub-fields.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed>|null $data
     *
     * @return list<array{path: string, field: array<string, mixed>, message: string}>
     */
    public function validate(array $fields, ?array $data, string $base = ''): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f->isNote()) {
                continue;
            }
            $v = $data[$f->key()] ?? null;
            $path = '' === $base ? $f->key() : $base . '.' . $f->key();
            if ($this->isMissing($f, $v)) {
                $out[] = ['path' => $path, 'field' => $f->toArray(), 'message' => $this->requiredMessage($f)];
            }
            foreach (FieldValueRules::problems($f, $v) as $problem) {
                $out[] = ['path' => $path, 'field' => $f->toArray(), 'message' => $problem['message']];
            }
            if ('repeater' === $f->type() && \is_array($v)) {
                foreach (\array_values($v) as $i => $item) {
                    if (\is_array($item)) {
                        \array_push($out, ...$this->validate($f->subFields(), $item, $path . '.' . $i));
                    }
                }
            }
        }

        return $out;
    }

    /** Equality of two values of field f. Rich/headline HTML is compared after sanitising, so normalising is never a change. */
    public function equal(FieldInterface $f, mixed $a, mixed $b): bool
    {
        if ($f->isRich()) {
            $mode = (string) $f->richMode();

            return $this->html->sanitize($a, $mode) === $this->html->sanitize($b, $mode);
        }
        if ('repeater' === $f->type()) {
            return Json::same(Json::stripIds($a ?? []), Json::stripIds($b ?? []));
        }

        return Json::same($a, $b);
    }

    /**
     * Keys of the fields whose value differs from the published one.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>|null $published
     *
     * @return list<string>
     */
    public function changedKeys(array $fields, ?array $data, ?array $published): array
    {
        if (null === $published) {
            return [];
        }
        $out = [];
        foreach ($fields as $f) {
            if (!$f->isNote() && !$this->equal($f, $data[$f->key()] ?? null, $published[$f->key()] ?? null)) {
                $out[] = $f->key();
            }
        }

        return $out;
    }

    /**
     * The data the server stores: rich values sanitised, repeater items get an _id, unknown keys dropped.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function normalize(array $fields, array $data): array
    {
        $out = [];
        foreach ($fields as $f) {
            if ($f->isNote() || !\array_key_exists($f->key(), $data)) {
                continue;
            }
            $v = $data[$f->key()];
            $problems = FieldValueRules::problems($f, $v);
            if ([] !== $problems) {
                throw ContentException::invalid($problems[0]['message'], \array_map(static fn (array $problem): array => ['path' => $f->key(), 'field' => $f->toArray(), 'message' => $problem['message']], $problems));
            }
            if ($f->isRich()) {
                $v = $this->html->sanitize($v, (string) $f->richMode());
            } elseif ('repeater' === $f->type()) {
                $items = [];
                foreach (\is_array($v) ? \array_values($v) : [] as $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $row = $this->normalize($f->subFields(), $item);
                    $row = ['_id' => (string) ($item['_id'] ?? Json::nid('p'))] + $row;
                    $items[] = $row;
                }
                $v = $items;
            } elseif ('link' === $f->type()) {
                $v = $this->link($v);
            } elseif ('image' === $f->type()) {
                $v = $this->image($v);
            } elseif ('photos' === $f->type()) {
                $v = $this->photos($v);
            } elseif ('toggle' === $f->type()) {
                $v = (bool) $v;
            } elseif ('number' === $f->type()) {
                $v = \is_numeric($v) ? $v + 0 : '';
            } elseif (\in_array($f->type(), ['text', 'textarea'], true)) {
                $v = \is_scalar($v) ? (string) $v : '';
            }
            $out[$f->key()] = $v;
        }

        return $out;
    }

    /** @return array{kind: string, page: string, url: string} */
    private function link(mixed $v): array
    {
        $v = \is_array($v) ? $v : [];
        $kind = \in_array($v['kind'] ?? '', ['page', 'url', 'modal'], true) ? (string) $v['kind'] : 'page';
        $url = Url::cleanLinkUrl($v['url'] ?? '');

        return ['kind' => $kind, 'page' => Url::cleanPageRef($v['page'] ?? '/'), 'url' => $url];
    }

    /**
     * «Photos» is a list of photos: an item is an image address (what the admin adds) or {src, alt} (the MCP tools may send it). The shape of an item is
     * kept, so old data and templates (`p.src`, or the item itself) work as before; only the strings are trimmed. An item without an address, or with an
     * address of a dangerous scheme, is dropped. An address with no scheme (`/uploads/a.jpg`, `uploads/a.jpg`, `//cdn.example/a.jpg`) is fine.
     * Normalising twice gives the same result, and valid data comes back unchanged.
     *
     * @return list<string|array<array-key, mixed>>
     */
    private function photos(mixed $v): array
    {
        $out = [];
        foreach (\is_array($v) ? \array_values($v) : [] as $item) {
            if (\is_string($item)) {
                $src = \trim($item);
                if (self::safePhotoSrc($src)) {
                    $out[] = $src;
                }
            } elseif (\is_array($item)) {
                $src = \is_scalar($item['src'] ?? null) ? \trim((string) $item['src']) : '';
                if (self::safePhotoSrc($src)) {
                    // the item as it came (its alt or other keys stay; no alt is added), only src and alt trimmed
                    $item['src'] = $src;
                    if (\is_string($item['alt'] ?? null)) {
                        $item['alt'] = \trim($item['alt']);
                    }
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /** An address is kept when it is not empty and either has no scheme or its scheme is http, https or data:image/ (javascript:, vbscript:, data:text/html… are not). */
    private static function safePhotoSrc(string $src): bool
    {
        if ('' === $src) {
            return false;
        }
        // browsers drop tabs and line breaks anywhere in an address and control characters at its start («java\nscript:»)
        $probe = \ltrim((string) \preg_replace('/[\t\n\r]+/', '', $src), "\x00..\x20");
        if (!\preg_match('/^[a-z][a-z0-9+.-]*:/i', $probe)) {
            return true;
        }

        return 1 === \preg_match('~^(https?:|data:image/)~i', $probe);
    }

    /** @return array{src: string, alt: string} */
    private function image(mixed $v): array
    {
        $v = \is_array($v) ? $v : [];
        $src = \trim((string) ($v['src'] ?? ''));
        if ('' !== $src && !\preg_match('~^(https?://|/(?![/\\\\])|assets/|data:image/)~i', $src)) {
            $src = '';
        }

        return ['src' => $src, 'alt' => \trim((string) ($v['alt'] ?? ''))];
    }
}
