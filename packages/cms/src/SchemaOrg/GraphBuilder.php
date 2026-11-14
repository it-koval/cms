<?php

declare(strict_types=1);

namespace Itk\Cms\SchemaOrg;

use Itk\Cms\Content\LiveUrlIndex;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\Event\SchemaOrgGraph;
use Itk\Cms\Repository\PageRepository;
use Itk\Cms\Support\Url;
use Itk\Cms\Twig\ItkRuntime;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The schema.org nodes of a live page: WebSite, Organization (or LocalBusiness), BreadcrumbList for a page with a live ancestor, BlogPosting for `blog_article`,
 * then the SchemaOrgGraph event for the site. The addresses are absolute and made from the site address (SitemapBuilder::base()); without it there is no graph.
 *
 * @internal
 */
final class GraphBuilder
{
    public const ORGANIZATION_TYPES = ['Organization', 'LocalBusiness'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SitemapBuilder $sitemap,
        private readonly LiveUrlIndex $liveUrls,
        private readonly PageRepository $pageRepository,
        private readonly ItkRuntime $media,
        private readonly EventDispatcherInterface $events,
    ) {
    }

    /**
     * @return list<array<string, mixed>> empty when the site has no address or the markup is switched off
     */
    public function build(PageTranslation $t): array
    {
        $locale = $t->locale;
        if (\filter_var($this->settings->get('schemaOrgOff', $locale, false), \FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }
        $base = $this->sitemap->base();
        if (null === $base) {
            return [];
        }
        $snapshot = $t->published ?? [];
        $page = [
            'id' => $t->page->id,
            'template' => $t->page->template,
            'locale' => $locale,
            'title' => (string) ($snapshot['title'] ?? ''),
            'url' => (string) ($snapshot['url'] ?? ''),
            'fields' => (array) ($snapshot['fields'] ?? []),
        ];
        $pageUrl = $base . Url::encodePath($page['url']);

        $organization = $this->organization($base, $locale);
        $nodes = \array_values(\array_filter([$this->webSite($base, $locale, null !== $organization), $organization]));
        $crumbs = $this->breadcrumbs($t, $page, $pageUrl, $base);
        if (null !== $crumbs) {
            $nodes[] = $crumbs;
        }
        if ('blog_article' === $page['template']) {
            $nodes[] = $this->blogPosting($t, $page, $pageUrl, $base, $locale, null !== $organization ? $base . '/#organization' : null);
        }

        return $this->events->dispatch(new SchemaOrgGraph($page, $base, $nodes))->nodes();
    }

    /** @return array<string, mixed>|null null without a site name */
    private function webSite(string $base, string $locale, bool $hasPublisher): ?array
    {
        $name = $this->text('siteName', $locale);
        if ('' === $name) {
            return null;
        }

        return [
            '@id' => $base . '/#website',
            '@type' => 'WebSite',
            'url' => $base . '/',
            'name' => $name,
            'inLanguage' => $locale,
        ] + ($hasPublisher ? ['publisher' => ['@id' => $base . '/#organization']] : []);
    }

    /** @return array<string, mixed>|null null when the company has no name (neither organizationName nor siteName) */
    private function organization(string $base, string $locale): ?array
    {
        $type = $this->text('organizationType', $locale);
        $name = $this->text('organizationName', $locale);
        $address = $this->text('organizationAddress', $locale);
        $name = '' !== $name ? $name : $this->text('siteName', $locale);
        if ('' === $name) {
            return null;
        }
        $logo = $this->image($this->settings->get('organizationLogo', $locale), $base);

        return \array_filter([
            '@id' => $base . '/#organization',
            '@type' => 'LocalBusiness' === $type && '' !== $address ? $type : 'Organization', // Google wants an address for a LocalBusiness
            'name' => $name,
            'url' => $base . '/',
            'logo' => $logo,
            'telephone' => $this->text('phone', $locale),
            'email' => $this->text('email', $locale),
            'address' => '' !== $address ? ['@type' => 'PostalAddress', 'streetAddress' => $address] : '',
            'sameAs' => $this->links($this->text('organizationLinks', $locale)),
        ], static fn (mixed $v): bool => '' !== $v && [] !== $v);
    }

    /**
     * @param array{id: string, template: string, locale: string, title: string, url: string, fields: array<string, mixed>} $page
     *
     * @return array<string, mixed>|null null for a page whose parent is not live
     */
    private function breadcrumbs(PageTranslation $t, array $page, string $pageUrl, string $base): ?array
    {
        $parentId = $t->page->parentId();
        if (null === $parentId) {
            return null;
        }
        $parents = $this->pageRepository->parentIds();
        $trail = [];
        // only an unbroken chain of live pages counts: the fingerprint of the page covers the place of live pages only, so a draft in the middle must not change the markup
        for ($id = $parentId, $guard = 0; null !== $id && $guard < 50; $id = $parents[$id] ?? null, ++$guard) {
            $live = $this->liveUrls->byId($page['locale'], $id);
            if (null === $live) {
                break;
            }
            \array_unshift($trail, ['name' => $live['title'], 'item' => $base . Url::encodePath($live['url'])]);
        }
        if ([] === $trail) {
            return null;
        }
        $trail[] = ['name' => $page['title'], 'item' => $pageUrl];
        $items = [];
        foreach ($trail as $i => $crumb) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1] + $crumb;
        }

        return ['@id' => $pageUrl . '#breadcrumb', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * @param array{id: string, template: string, locale: string, title: string, url: string, fields: array<string, mixed>} $page
     *
     * @return array<string, mixed>
     */
    private function blogPosting(PageTranslation $t, array $page, string $pageUrl, string $base, string $locale, ?string $organizationId): array
    {
        $f = $page['fields'];
        $title = \is_string($f['title'] ?? null) && '' !== \trim($f['title']) ? \trim($f['title']) : $page['title'];
        $published = $this->date($f['date'] ?? null) ?? $t->page->createdAt->format(\DATE_ATOM); // the creation of the page: a republication does not move it
        $org = null === $organizationId ? null : ['@id' => $organizationId];

        return \array_filter([
            '@id' => $pageUrl . '#article',
            '@type' => 'BlogPosting',
            'headline' => $title,
            'datePublished' => $published,
            'dateModified' => $t->publishedAt?->format(\DATE_ATOM), // the live text changed when it was last published; updatedAt also moves on draft saves
            'image' => $this->image($f['cover'] ?? null, $base),
            'description' => \is_string($f['excerpt'] ?? null) ? self::plain($f['excerpt']) : '',
            'inLanguage' => $locale,
            'mainEntityOfPage' => $pageUrl,
            'author' => $org,
            'publisher' => $org,
        ], static fn (mixed $v): bool => null !== $v && '' !== $v);
    }

    /** The text of an HTML fragment (the excerpt is a rich description): tags dropped, paragraphs apart by a space. */
    private static function plain(string $html): string
    {
        $text = \html_entity_decode(\strip_tags((string) \preg_replace('~<(/p|br\s*/?|/li|/h\d)>~i', ' $0', $html)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return \trim((string) \preg_replace('~\s+~u', ' ', $text));
    }

    private function text(string $key, string $locale): string
    {
        $v = $this->settings->get($key, $locale, '');

        return \is_scalar($v) ? \trim((string) $v) : '';
    }

    /** One URL per line; only http(s) addresses stay. @return list<string> */
    private function links(string $text): array
    {
        $links = [];
        foreach (\preg_split('~\R~', $text) ?: [] as $line) {
            $line = \trim($line);
            if (1 === \preg_match('~^https?://[^\s<>"]+$~i', $line)) {
                $links[$line] = $line;
            }
        }

        return \array_values($links);
    }

    /** The address of an image field value as an absolute URL, '' when there is none. */
    private function image(mixed $value, string $base): string
    {
        $src = $this->media->mediaUrl($value);
        if ('' === $src || 1 === \preg_match('~^(data:|#)~i', $src)) {
            return '';
        }

        return 1 === \preg_match('~^https?://~i', $src) ? $src : $base . $src;
    }

    /** The date of a `date` field (YYYY-MM-DD) as is, or null. */
    private function date(mixed $value): ?string
    {
        return \is_string($value) && 1 === \preg_match('~^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$~', \trim($value)) ? \trim($value) : null;
    }
}
