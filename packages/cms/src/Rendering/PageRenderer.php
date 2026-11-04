<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\H1Rule;
use Itk\Cms\Content\PageService;
use Itk\Cms\Content\SeoResolver;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\CriticalCss\CriticalCssService;
use Itk\Cms\CriticalCss\Model\CriticalStyles;
use Itk\Cms\Entity\PageTranslation;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;
use Itk\Cms\Schema\Schema;
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
 * Variables: page { id, parentId, template, locale, title, url, seo, fields, sections, h1Id }, site (settings + name, domain, locale).
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
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly ?LocaleSwitcher $locales = null,
    ) {
    }

    /**
     * The live page as the critical CSS generator measures it: the published snapshot with the plain stylesheet links, outside any request (worker or console).
     *
     * @throws \Throwable whatever the templates throw (the caller records it as the page's failure)
     */
    public function renderSource(PageTranslation $t): string
    {
        $path = (string) ($t->published['url'] ?? '/');
        $request = Request::create(($this->publicUrl->base() ?? 'http://localhost') . Url::encodePath('' === $path ? '/' : $path));
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
        $snapshot = $t->published ?? [];
        $html = $this->render($t, $snapshot, false, $this->critical->forLiveRender($t));

        return new Response($html);
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
        $html = $this->render($t, $t->draft, true, null);

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
            'title' => (string) ($version['title'] ?? ''),
            'url' => (string) ($version['url'] ?? ''),
            'seo' => $this->seo->apply((array) ($version['seo'] ?? []), $this->seo->best((string) ($version['url'] ?? ''), $preview ? [] : ($this->requests->getCurrentRequest()?->query->all() ?? []), $t->locale)),
            'fields' => (array) ($version['fields'] ?? []),
            'sections' => $sections,
            'h1Id' => $this->h1->pageH1Id($sections, $tpl),
        ];
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
