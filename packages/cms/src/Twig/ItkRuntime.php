<?php

declare(strict_types=1);

namespace Itk\Cms\Twig;

use Itk\Cms\Content\ChangeDetector;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentReader;
use Itk\Cms\Content\FieldValues;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\Listing;
use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\MenuService;
use Itk\Cms\Field\FieldInterface;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Rendering\RenderState;
use Itk\Cms\Repository\MediaFileRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Settings\Settings;
use Itk\Cms\Support\Json;
use Itk\Cms\Support\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;
use Twig\Markup;

/**
 * Implementation of the Twig functions of ItkExtension (a runtime class: loaded only when a template calls one).
 * Functions for site templates:
 *   itk_field(section|page, 'headline')   the value of a field: rich/headline as safe HTML, text escaped, other types as raw data
 *   itk_field_attrs(section|page, 'image') data-f / data-sec attributes of a non-text field (preview only, else '')
 *   itk_section_attrs(section)            attributes of a section's root element (preview only, else '')
 *   itk_section_class(section)            'chg' when the section differs from the published one (preview only)
 *   render_sections(page)                 every visible section through templates/sections/<type>.html.twig (or the template() of a section class)
 *   menu('header')                        items of a menu area: [{ label, kind, url, active, children }]
 *   settings('phone') / settings()        a site setting of the current language / all of them
 *   media_url(image[, width[, ratio]])    address of an image value ({ src, alt }) or of a path; with a width: of its WebP variant of that width;
 *                                         with a ratio ('16:9'): of the frame of that ratio (the one saved in the media editor, else around the focal point)
 *                                         a ratio that is neither in itk_cms.images.ratios nor saved for the file is not an error: the whole picture is used and a warning is logged
 *   media_width(image)                    width of the original file in px (null for other addresses): for a picture the layout shows at its own size
 *   media_srcset(image[, maxWidth[, ratio]])  value of srcset: the WebP variants of the image (empty for GIF, SVG and other addresses), same ratio rules
 *   media_ratio(width, height)            the ratio key of a fixed slot ('14:5' for 700, 250; 219:103 is given as the nearest 17:8), to pass as the ratio above
 *   itk_img(image, { sizes, class, width, height, loading, alt, src_width, max_width, attrs, ratio })   a whole <img> with src, srcset and the image's alt;
 *                                         where the server makes AVIF it is wrapped in <picture> with an AVIF <source>
 *   page_url(link|page|id)                address behind a link value, a page or a page id
 *   listing(page)                         the published pages (or collection records) a page lists
 *   itk_collection('ambassadors')         the published records of a collection in site order
 *   itk_collection('tours', {where, sort, limit, offset})   the same, filtered, sorted and cut by the database (only published, the same for everyone)
 *   itk_pages({template, parent, limit, sort})              the published pages of a template / under a page: [{ id, parentId, template, title, url, seo, fields, … }]
 *   itk_page(id)                          the published page with this id (the same row) or null
 *   itk_is_preview()                      true while the admin canvas renders the draft
 *   itk_cache(300) / itk_cache(false)     how long the cache of pages may keep this page at most / not at all (a page that shows data of the site's own tables or the time of day).
 *
 * itk_cms.strict (in debug by default): a key that the section or page does not have in itk_field(), a section type the schema does not know and a section without its
 * Twig template are exceptions that list what exists; without it they are a warning in the log and the page is drawn as before. The admin canvas never throws.
 *
 * @internal
 */
final class ItkRuntime implements RuntimeExtensionInterface
{
    /** @var array<string, true> ratios already reported as not allowed (one warning per request) */
    private array $warnedRatios = [];

    /** @var array<string, bool> "path|ratio" => the file has a saved frame of that ratio */
    private array $savedFrames = [];

    /** @var array<string, true> mistakes of the site templates already logged (one line per request each) */
    private array $warned = [];

    public function __construct(
        private readonly Schema $schema,
        private readonly RenderState $state,
        private readonly HtmlSanitizer $html,
        private readonly FieldValues $values,
        private readonly ChangeDetector $changes,
        private readonly MenuService $menus,
        private readonly Settings $settings,
        private readonly Listing $listing,
        private readonly CollectionService $collections,
        private readonly ContentReader $reader,
        private readonly LiveUrlIndex $liveUrls,
        private readonly ImageVariants $images,
        private readonly RequestStack $requests,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        #[Autowire(param: 'itk_cms.strict')]
        private readonly bool $strict = false,
        private readonly ?MediaFileRepository $mediaFiles = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isPreview(): bool
    {
        return $this->state->preview;
    }

    /**
     * Tells the cache of pages how long this render may be kept: `{% do itk_cache(300) %}` at most that many seconds, `{% do itk_cache(false) %}` not at all.
     * Many calls in one render (the layout and a section) leave the strictest. Outside the render of a live page it changes nothing.
     */
    public function cache(int|false $ttl): string
    {
        $this->state->cache = RenderState::mergeCache($this->state->cache, $ttl);

        return '';
    }

    private function locale(): string
    {
        return $this->state->locale
            ?? $this->requests->getCurrentRequest()?->attributes->get('_locale')
            ?? $this->mainLocale;
    }

    /**
     * @param array<string, mixed> $subject a section { id, type, data } or a page { id, template, fields }
     */
    public function field(array $subject, string $path): mixed
    {
        [$field, $data, $secId, $base, $miss] = $this->resolve($subject, $path);
        $value = Json::get($data, $path);
        if (null !== $miss && !$this->state->preview) {
            $this->mistake(I18n::t(isset($subject['type']) ? 'http.field_missing_section' : 'http.field_missing_page', [
                'owner' => (string) ($subject['type'] ?? $subject['template'] ?? ''), 'field' => $miss[0], 'path' => $path, 'keys' => [] === $miss[1] ? I18n::t('http.field_no_fields') : \implode(', ', $miss[1]),
            ]));
        }
        if (null === $field) {
            return \is_scalar($value) ? new Markup(HtmlSanitizer::escape((string) $value), 'UTF-8') : $value;
        }
        if ($field->isRich()) {
            $out = $this->images->rewriteHtml($this->html->sanitize($value, (string) $field->richMode()));
        } elseif (\in_array($field->type(), ['text', 'textarea', 'number', 'select'], true)) {
            $out = HtmlSanitizer::escape(\is_scalar($value) ? (string) $value : '');
            if ('textarea' === $field->type()) {
                $out = \nl2br($out, false);
            }
        } else {
            return $value; // image, link, toggle, repeater, photos: data for the template
        }
        if ($this->state->preview) {
            $cls = 'itk-f' . ($field->isRich() && 'headline' !== $field->type() ? ' itk-rich' : '') . ($this->fieldChanged($subject, $field, $path, $base) ? ' chg' : '');
            $out = '<span data-f="' . HtmlSanitizer::escape($path) . '" data-sec="' . HtmlSanitizer::escape($secId) . '" class="' . $cls . '">' . $out . '</span>';
        }

        return new Markup($out, 'UTF-8');
    }

    /** @param array<string, mixed> $subject */
    public function fieldAttrs(array $subject, string $path): Markup
    {
        if (!$this->state->preview) {
            return new Markup('', 'UTF-8');
        }
        [$field, , $secId, $base] = $this->resolve($subject, $path);
        $chg = null !== $field && $this->fieldChanged($subject, $field, $path, $base);

        return new Markup(' data-f="' . HtmlSanitizer::escape($path) . '" data-sec="' . HtmlSanitizer::escape($secId) . '"' . ($chg ? ' data-chg="1"' : ''), 'UTF-8');
    }

    /** @param array<string, mixed> $section */
    public function sectionAttrs(array $section): Markup
    {
        if (!$this->state->preview) {
            return new Markup('', 'UTF-8');
        }
        $def = $this->schema->section((string) ($section['type'] ?? ''));
        $short = null !== $def ? $def->toArray()['short'] : (string) ($section['type'] ?? '');
        $out = ' data-sec="' . HtmlSanitizer::escape((string) $section['id']) . '" data-type="' . HtmlSanitizer::escape((string) $section['type']) . '" data-label="' . HtmlSanitizer::escape($short) . '"';
        if ('chg' === $this->sectionClass($section)) {
            $out .= ' data-chg="1"';
        }

        return new Markup($out, 'UTF-8');
    }

    /** @param array<string, mixed> $section */
    public function sectionClass(array $section): string
    {
        $published = $this->state->published;
        if (!$this->state->preview || null === $published) {
            return '';
        }
        foreach ($published['sections'] ?? [] as $s) {
            if ($s['id'] === $section['id']) {
                return [] !== $this->changes->sectionChangedKeys($section, $s) || !empty($s['hidden']) !== !empty($section['hidden']) ? 'chg' : '';
            }
        }

        return 'chg'; // a section added since the last publication
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $page
     */
    public function renderSections(Environment $env, array $context, array $page): Markup
    {
        $html = '';
        $h1Id = $page['h1Id'] ?? null;
        $loader = $env->getLoader();
        foreach ($page['sections'] ?? [] as $section) {
            if (!empty($section['hidden'])) {
                continue;
            }
            $def = $this->schema->section((string) ($section['type'] ?? ''));
            if (null === $def) {
                if (!$this->state->preview) {
                    $this->mistake(I18n::t('http.sections_unknown_type', ['type' => (string) ($section['type'] ?? ''), 'types' => \implode(', ', \array_keys($this->schema->sections()))]));
                }
                continue;
            }
            $name = $def->templatePath();
            if (!$loader->exists($name)) {
                if ($this->state->preview) {
                    $html .= '<div data-sec="' . HtmlSanitizer::escape((string) $section['id']) . '" style="padding:16px;border:1px dashed #999">' . I18n::t('http.sections_template_missing_preview', ['name' => HtmlSanitizer::escape($name)]) . '</div>';
                } else {
                    $this->mistake(I18n::t('http.sections_template_missing', ['key' => $def->key(), 'name' => $name]));
                }
                continue;
            }
            $html .= $env->render($name, \array_merge($context, ['section' => $section, 'page' => $page, 'is_h1' => $section['id'] === $h1Id]));
        }

        return new Markup($html, 'UTF-8');
    }

    /** @return list<array<string, mixed>> */
    public function menu(string $key): array
    {
        $items = $this->menus->resolve($key, $this->locale());
        $path = Url::key($this->state->path ?: ($this->requests->getCurrentRequest()?->getPathInfo() ?? '/'));
        $mark = static function (array $items) use (&$mark, $path): array {
            foreach ($items as &$it) {
                $it['children'] = $mark($it['children']);
                $own = null !== $it['url'] && Url::key($it['url']) === $path;
                $it['active'] = $own;
                $it['open'] = $own || [] !== \array_filter($it['children'], static fn (array $c): bool => $c['active'] || $c['open']);
            }

            return $items;
        };

        return $mark($items);
    }

    public function setting(?string $key = null, mixed $default = null): mixed
    {
        if (null === $key) {
            $subject = $this->settings->all($this->locale());
            if (!\in_array($subject, $this->state->settingsSubjects, true)) {
                $this->state->settingsSubjects[] = $subject;
            }

            return $subject;
        }

        return $this->settings->get($key, $default, $this->locale());
    }

    /**
     * @param int|null $width with a width the address of the WebP variant (the nearest allowed width), if the image has variants
     * @param string|null $ratio with a width too: the frame of that ratio ("16:9"); the ratio should be one of itk_cms.images.ratios or a
     *                           frame saved for the file; otherwise the page does not break: the whole picture is used and a warning is logged
     */
    public function mediaUrl(mixed $image, ?int $width = null, ?string $ratio = null): string
    {
        $src = \is_array($image) ? (string) ($image['src'] ?? '') : (\is_scalar($image) ? (string) $image : '');
        if ('' === $src) {
            return $src;
        }
        if (!\preg_match('~^(https?:|data:|/|#)~i', $src)) {
            $src = '/' . $src;
        }

        return null === $width ? $src : $this->images->url($src, $width, $this->usableRatio($src, $ratio));
    }

    public function mediaSrcset(mixed $image, ?int $maxWidth = null, ?string $ratio = null): string
    {
        $src = $this->mediaUrl($image);

        return $this->images->srcset($src, $maxWidth, null, $this->usableRatio($src, $ratio));
    }

    /** Width in px of the original of a media library image, or null (other address, unreadable file). For layouts that show a picture at its own size. */
    public function mediaWidth(mixed $image): ?int
    {
        $src = $this->mediaUrl($image);

        return '' === $src ? null : $this->images->originalWidth($src);
    }

    /** The ratio key of a fixed slot of the layout (width × height), see ImageVariants::slotRatio(); null for sizes that are not positive. */
    public function mediaRatio(mixed $width, mixed $height): ?string
    {
        return \is_numeric($width) && \is_numeric($height) ? ImageVariants::slotRatio((int) $width, (int) $height) : null;
    }

    /**
     * The ratio key an image may really be asked in, or null (the whole picture): the ratio must be reduced to a key, be one of
     * itk_cms.images.ratios or be saved as a frame of this file; anything else would be a 404 address, so it is dropped with a warning.
     */
    private function usableRatio(string $src, mixed $ratio): ?string
    {
        if (null === $ratio || '' === $ratio || !$this->images->isConvertible($src)) {
            return null;
        }
        $pair = ImageVariants::parseRatio($ratio);
        $key = null === $pair ? null : ImageVariants::ratioKey($pair);
        if (null !== $key && $this->images->isAllowedRatio($key)) {
            return $key;
        }
        if (null !== $key && null !== $this->mediaFiles) {
            $cache = $src . '|' . $key;
            $this->savedFrames[$cache] ??= \is_array($this->mediaFiles->findOneBy(['path' => $src])?->crops[$key] ?? null);
            if ($this->savedFrames[$cache]) {
                return $key;
            }
        }
        $warn = (\is_scalar($ratio) ? (string) $ratio : \gettype($ratio)) . ' ' . $src;
        if (!isset($this->warnedRatios[$warn])) {
            $this->warnedRatios[$warn] = true;
            $this->logger?->warning('Image ratio is not allowed, the whole picture is used: add it to itk_cms.images.ratios', ['ratio' => $key ?? $ratio, 'image' => $src, 'allowed' => $this->images->ratios()]);
        }

        return null;
    }

    /**
     * A whole <img>. Options (all optional): sizes; class; width, height (attributes); loading (default lazy, null for none);
     * alt (default: the image's own); src_width (width of src, default: width rounded up, else 960); max_width (the widest picture
     * the layout can show in device pixels, srcset stops at the first width that covers it; default: twice width, else no limit);
     * sizes defaults to "(max-width: {width}px) 100vw, {width}px" when width is given; attrs (array of extra attributes, escaped; a Markup such as itk_field_attrs() is inserted as it is);
     * ratio ('16:9'): the picture is the frame of that ratio, and with a width the height attribute is worked out from it.
     * An image that gets copies is printed as <picture><source type="image/avif" srcset sizes><img …></picture> when this PHP can write AVIF (the img inside is the WebP one).
     *
     * @param array<string, mixed> $options
     */
    public function img(mixed $image, array $options = []): Markup
    {
        $original = $this->mediaUrl($image);
        if ('' === $original) {
            return new Markup('', 'UTF-8');
        }
        $width = isset($options['width']) ? (int) $options['width'] : null;
        $srcWidth = (int) ($options['src_width'] ?? $width ?? 960);
        $max = isset($options['max_width']) ? (int) $options['max_width'] : (null !== $width ? $width * 2 : null);
        $alt = (string) ($options['alt'] ?? (\is_array($image) ? ($image['alt'] ?? '') : ''));
        $ratioKey = isset($options['ratio']) ? $this->usableRatio($original, $options['ratio']) : null;
        $ratio = null === $ratioKey ? null : ImageVariants::parseRatio($ratioKey);
        $srcset = $this->images->srcset($original, $max, null, $ratioKey);
        $sizes = $options['sizes'] ?? (null !== $width ? \sprintf('(max-width: %dpx) 100vw, %dpx', $width, $width) : null);
        $loading = \array_key_exists('loading', $options) ? $options['loading'] : 'lazy';

        $attrs = [
            'src' => $this->images->url($original, $srcWidth, $ratioKey),
            'srcset' => $srcset,
            'sizes' => '' !== $srcset ? $sizes : null,
            'alt' => $alt,
            'class' => $options['class'] ?? null,
            'width' => $width,
            'height' => isset($options['height']) ? (int) $options['height'] : (null !== $width && null !== $ratio ? (int) \round($width * $ratio[1] / $ratio[0]) : null),
            'loading' => $loading,
            'decoding' => 'async',
        ];
        $out = '<img';
        foreach ($attrs as $name => $value) {
            if (null !== $value && ('' !== $value || 'alt' === $name)) {
                $out .= ' ' . $name . '="' . HtmlSanitizer::escape((string) $value) . '"';
            }
        }
        $extra = $options['attrs'] ?? null;
        if ($extra instanceof Markup) {
            $out .= (string) $extra;
        } elseif (\is_array($extra)) {
            foreach ($extra as $name => $value) {
                if (null !== $value && \preg_match('~^[a-z][a-z0-9:_-]*$~i', (string) $name)) {
                    $out .= ' ' . $name . '="' . HtmlSanitizer::escape((string) $value) . '"';
                }
            }
        }

        $out .= '>';
        if ('' !== $srcset && $this->images->supportsAvif()) {
            $avif = $this->images->srcset($original, $max, null, $ratioKey, 'avif');
            if ('' !== $avif) {
                $source = '<source type="image/avif" srcset="' . HtmlSanitizer::escape($avif) . '"';
                $out = '<picture>' . $source . (null !== $sizes ? ' sizes="' . HtmlSanitizer::escape((string) $sizes) . '"' : '') . '>' . $out . '</picture>';
            }
        }

        return new Markup($out, 'UTF-8');
    }

    /** The address behind a link value, a page or a page id. Never a javascript: / data: address, whatever is stored (old or imported data included). */
    public function pageUrl(mixed $target): string
    {
        if (\is_array($target)) {
            if (isset($target['kind'])) {
                return match ($target['kind']) {
                    'url' => Url::safeHref(Url::cleanLinkUrl($target['url'] ?? '')),
                    'modal' => '#',
                    default => $this->pageRef($target['page'] ?? '/'),
                };
            }
            if (isset($target['url'])) {
                return Url::safeHref(Url::cleanLinkUrl($target['url']));
            }
            $target = $target['id'] ?? '';
        }

        return \is_string($target) ? $this->pageRef($target, '') : '';
    }

    /** A site path as it is, a page id as the address of the live page; anything else is $fallback. */
    private function pageRef(mixed $ref, string $fallback = '/'): string
    {
        $ref = \is_string($ref) ? \trim($ref) : '';
        if ('' === $ref) {
            return $fallback;
        }
        if (Url::isLocalPath($ref)) {
            return $this->liveUrls->spelled($this->locale(), $ref); // a live page in its own spelling (trailing slash or none), else as written
        }
        $clean = Url::cleanPageRef($ref);

        return '/' === $clean ? $fallback : ($this->liveUrls->byId($this->locale(), $clean)['url'] ?? '');
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    public function listingOf(array $page): array
    {
        $rows = isset($page['id']) ? $this->listing->forPage((string) $page['id'], $this->locale()) : [];
        $collection = $this->schema->template((string) ($page['template'] ?? $this->state->template))?->listingRule()['collection'] ?? null;
        if (null !== $collection) {
            $this->rememberCollectionOwners($rows, (string) $collection);
        }

        return $rows;
    }

    /**
     * The published records of a collection. With options (where, sort, limit, offset) the database filters, sorts and cuts them (ContentReader); without, as always.
     *
     * @param array<string, mixed>|null $options
     *
     * @return list<array<string, mixed>>
     */
    public function collection(string $key, ?array $options = null): array
    {
        $rows = null === $options
            ? $this->collections->published($key, $this->locale())
            : $this->read(fn (): array => $this->reader->collection($key, $options, $this->locale()), []);
        $this->rememberCollectionOwners($rows, $key);

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function rememberCollectionOwners(array $rows, string $key): void
    {
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $this->state->collectionOwners[(string) $row['id']] = $key;
            }
        }
    }

    /**
     * The published pages of a template / under a page.
     *
     * @param array<string, mixed> $options template, parent, limit, sort
     *
     * @return list<array<string, mixed>>
     */
    public function pages(array $options = []): array
    {
        return $this->read(fn (): array => $this->reader->pages($options, $this->locale()), []);
    }

    /** The published page with this id, or null. @return null|array<string, mixed> */
    public function page(mixed $id): ?array
    {
        return \is_string($id) && '' !== $id ? $this->reader->page($id, $this->locale()) : null;
    }

    /** A call of ContentReader from a template: a wrong option is a mistake of the template (itk_cms.strict), the answer without it is $empty. */
    private function read(\Closure $call, mixed $empty): mixed
    {
        try {
            return $call();
        } catch (\InvalidArgumentException $e) {
            $this->mistake($e->getMessage());

            return $empty;
        }
    }

    /**
     * @param array<string, mixed> $subject
     *
     * @return array{0: ?FieldInterface, 1: array<string, mixed>, 2: string, 3: ?array<string, mixed>, 4: array{0: string, 1: list<string>}|null} field, data, owner id for data-sec, published data of the owner,
     *                                                                                                                                            and the first key of the path the section or the repeater does not have (with the keys it has)
     */
    private function resolve(array $subject, string $path): array
    {
        $isSettings = \in_array($subject, $this->state->settingsSubjects, true);
        $isSection = !$isSettings && isset($subject['type']);
        $collection = !$isSettings && isset($subject['id']) ? ($this->state->collectionOwners[(string) $subject['id']] ?? null) : null;
        if ($isSettings) {
            $fields = $this->schema->settingsFields();
            $data = $subject;
            $secId = '@settings';
            $base = null;
        } elseif (null !== $collection) {
            $fields = $this->schema->collection($collection)?->fieldList() ?? [];
            $data = $subject;
            $secId = '@collection/' . $collection . '/' . (string) $subject['id'];
            $base = null;
        } elseif ($isSection) {
            $fields = $this->schema->sectionFields((string) $subject['type']);
            $data = (array) ($subject['data'] ?? []);
            $secId = (string) ($subject['id'] ?? '');
            $base = null;
            foreach ($this->state->published['sections'] ?? [] as $s) {
                if ($s['id'] === $secId) {
                    $base = (array) ($s['data'] ?? []);
                }
            }
        } else {
            $fields = $this->schema->templateFields((string) ($subject['template'] ?? ''));
            $data = (array) ($subject['fields'] ?? []);
            $secId = '@page';
            $base = null !== $this->state->published ? (array) ($this->state->published['fields'] ?? []) : null;
        }
        $field = null;
        $miss = null;
        $list = $fields;
        foreach (\explode('.', $path) as $depth => $part) {
            if (\ctype_digit($part)) {
                continue;
            }
            $field = null;
            foreach ($list as $f) {
                if ($f->key() === $part) {
                    $field = $f;
                    break;
                }
            }
            if (null === $field) {
                // a path under a field that has no sub-fields (image.src) reads the data of the value: not a mistake
                $miss = 0 === $depth || [] !== $list ? [$part, \array_map(static fn (FieldInterface $f): string => $f->key(), $list)] : null;
                break;
            }
            $list = $field->subFields();
        }

        return [$field, $data, $secId, $base, $miss];
    }

    /** A mistake of a site template: an exception in strict mode, else one warning in the log per request. */
    private function mistake(string $message): void
    {
        if ($this->strict) {
            throw new \LogicException(I18n::t('http.template_mistake', ['message' => $message]));
        }
        if (!isset($this->warned[$message])) {
            $this->warned[$message] = true;
            $this->logger?->warning($message);
        }
    }

    /** @param array<string, mixed> $subject @param null|array<string, mixed> $base */
    private function fieldChanged(array $subject, FieldInterface $field, string $path, ?array $base): bool
    {
        if (null === $this->state->published || null === $base) {
            return false;
        }
        $data = (array) ($subject['type'] ?? null ? ($subject['data'] ?? []) : ($subject['fields'] ?? []));

        return !$this->values->equal($field, Json::get($data, $path), Json::get($base, $path));
    }
}
