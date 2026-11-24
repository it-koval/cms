<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\H1Rule;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\PageVersions;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Schema\Schema;
use Itk\Cms\SchemaOrg\SchemaOrgInjector;
use Itk\Cms\Support\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * Renders a page with the site's Twig templates:
 *   live     the published snapshot of a PageTranslation (the catch-all PageController)
 *   preview  the DRAFT with data-f / data-sec markers and the preview script (the admin's /admin/preview/{id}, PreviewController)
 *
 * Template lookup: templates/pages/<template>.html.twig of the site, then @ItkCms/pages/<template>.html.twig, then @ItkCms/pages/default.html.twig.
 * Variables: page { id, parentId, template, locale, html_lang, og_locale, translations, title, url, seo, fields, sections, h1Id }, site (settings + name, domain, locale).
 * page.seo is { title, description } of the page with the SEO rule of its address laid over it (SeoResolver) + robots, canonical, ogImage, rule; itk_seo() turns it into the meta tags.
 *
 * @internal
 */
final class PageRenderer
{
    public const PREVIEW_JS = '/bundles/itkcms/preview.js';
    public const PREVIEW_CSS = '/bundles/itkcms/preview.css';

    /** What the templates asked of the cache of pages in the last renderLive() (itk_cache()), see PageCache::put(). */
    private int|false|null $cacheDirective = null;

    public function __construct(
        private readonly Environment $twig,
        private readonly Schema $schema,
        private readonly H1Rule $h1,
        private readonly SettingsService $settings,
        private readonly PageService $pages,
        private readonly RenderState $state,
        private readonly SeoResolver $seo,
        private readonly RequestStack $requests,
        private readonly AdminTheme $theme,
        private readonly CriticalCssService $critical,
        private readonly PublicUrl $publicUrl,
        private readonly SchemaOrgInjector $schemaOrg,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly ?LocaleSwitcher $locales = null,
        private readonly ?ContentLocales $contentLocales = null,
        private readonly ?PageVersions $versions = null,
    ) {
    }

    /** @param list<PageTranslation> $translations */
    public function prepareSources(array $translations): void
    {
        $this->versions?->prepare($translations);
    }

    /**
     * The live page as the critical CSS generator measures it: the published snapshot with the plain stylesheet links, outside any request (worker or console).
     *
     * @throws \Throwable whatever the templates throw (the caller records it as the page's failure)
     */
    public function renderSource(PageTranslation $t): string
    {
        if (!$t->isLive() || !($this->contentLocales?->serves($t->locale, (string) ($t->published['url'] ?? $t->publishedUrl)) ?? true)) {
            throw new \LogicException('Critical CSS requires a live version in an enabled content language.');
        }
        $path = (string) ($t->published['url'] ?? '/');
        $request = Request::create(($this->publicUrl->seoBase() ?? 'http://localhost') . Url::encodePath('' === $path ? '/' : $path));
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->setLocale($t->locale);
        $this->requests->push($request);
        try {
            $render = fn (): string => $this->render($t, $t->published ?? [], false, null, true);

            return null === $this->locales ? $render() : $this->locales->runWithLocale($t->locale, $render);
        } finally {
            $this->requests->pop();
        }
    }

    /** The live page: the only render that may inline critical CSS. */
    public function renderLive(PageTranslation $t): Response
    {
        $render = function () use ($t): Response {
            $snapshot = $t->published ?? [];
            $html = $this->render($t, $snapshot, false, $this->critical->forLiveRender($t));

            return new Response($this->schemaOrg->inject($html, $t)); // the JSON-LD graph is part of the page the cache keeps
        };

        return $this->withLocale($t->locale, $render);
    }

    /** The directive of itk_cache() in the last renderLive(): a number of seconds, false = do not keep, null = nothing asked. */
    public function lastCacheDirective(): int|false|null
    {
        return $this->cacheDirective;
    }

    /** The draft of a page for the admin canvas (the version must exist). */
    public function renderPreview(string $pageId, ?string $locale = null): Response
    {
        $locale ??= $this->mainLocale;
        $page = $this->pages->find($pageId);
        $t = $page->translation($locale) ?? throw new ContentException(I18n::t(PageService::NO_VERSION), 404);
        $render = fn (): string => $this->render($t, $t->draft, true, null);
        $html = $this->withLocale($t->locale, $render);

        $head = '<style>' . $this->theme->previewCss() . '</style><link rel="stylesheet" href="' . self::PREVIEW_CSS . '">'; // the colours of the overlay follow the admin theme
        $tail = '<script src="' . self::PREVIEW_JS . '" defer></script>';
        if (!\str_contains($html, self::PREVIEW_JS)) { // a layout may include @ItkCms/preview/_markers.html.twig itself
            $html = \str_contains($html, '</head>') ? \preg_replace('~</head>~i', $head . '</head>', $html, 1) : $head . $html;
            $html = \str_contains($html, '</body>') ? \preg_replace('~</body>~i', $tail . '</body>', $html, 1) : $html . $tail;
        }

        $response = new Response((string) $html);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * @template T
     *
     * @param callable(): T $render
     *
     * @return T
     */
    private function withLocale(string $locale, callable $render): mixed
    {
        // Legacy public templates keep the site's routing and translator locale.
        if (!($this->contentLocales?->isConfigured() ?? false)) {
            return $render();
        }
        $request = $this->requests->getCurrentRequest();
        $previous = $request?->getLocale() ?? $this->mainLocale;
        $request?->setLocale($locale);
        try {
            return null === $this->locales ? $render() : $this->locales->runWithLocale($locale, $render);
        } finally {
            if (null !== $request) {
                $request->setLocale($previous);
            }
        }
    }

    /**
     * @param array<string, mixed> $version draft or snapshot
     */
    private function render(PageTranslation $t, array $version, bool $preview, ?CriticalStyles $critical, bool $source = false): string
    {
        $p = $t->page;
        $tpl = $this->schema->template($p->template);
        $sections = \array_values($version['sections'] ?? []);
        $page = [
            'id' => $p->id,
            'parentId' => $p->parentId(),
            'template' => $p->template,
            'locale' => $t->locale,
            'html_lang' => $this->contentLocales?->htmlLang($t->locale) ?? $t->locale,
            'og_locale' => $this->contentLocales?->ogLocale($t->locale),
            'translations' => \array_map(static fn (array $version): array => [
                'code' => $version['code'], 'name' => $version['name'], 'url' => $version['url'],
            ], $this->versions?->live($p) ?? []),
            'title' => (string) ($version['title'] ?? ''),
            'url' => (string) ($version['url'] ?? ''),
            'seo' => $this->seo->apply((array) ($version['seo'] ?? []), $this->seo->best((string) ($version['url'] ?? ''), $preview ? [] : ($this->requests->getCurrentRequest()?->query->all() ?? []), $t->locale)),
            'fields' => (array) ($version['fields'] ?? []),
            'sections' => $sections,
            'h1Id' => $this->h1->pageH1Id($sections, $tpl),
        ];
        if (!$preview && ($this->contentLocales?->isMultilingual() ?? false)) {
            $base = $this->publicUrl->seoBase();
            $page['seo_alternates'] = $this->versions?->alternates($p, $base) ?? [];
            $page['seo_canonical'] = null === $base ? '' : $base . Url::encodePath(Url::spelling($page['url']));
        }
        $site = $this->settings->all($t->locale) + ['locale' => $t->locale];
        $site['name'] = $site['siteName'] ?? '';

        $this->cacheDirective = null;
        $this->state->reset();
        $this->state->preview = $preview;
        $this->state->source = $source;
        $this->state->locale = $t->locale;
        $this->state->path = $page['url'];
        $this->state->template = $p->template;
        $this->state->published = $preview ? $this->pages->liveSnapshot($t) : null;
        $this->state->critical = $critical;
        try {
            $names = ['pages/' . $p->template . '.html.twig', '@ItkCms/pages/' . $p->template . '.html.twig', '@ItkCms/pages/default.html.twig'];

            $html = $this->twig->resolveTemplate($names)->render(['page' => $page, 'site' => $site, 'itk_preview' => $preview]);
            $this->cacheDirective = $preview ? null : $this->state->cache; // the state is reset below

            return $html;
        } finally {
            $this->state->reset();
        }
    }
}
