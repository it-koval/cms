<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\RedirectService;
use Itk\Cms\I18n\ContentLocales;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Rendering\PageCacheStatus;
use Itk\Cms\Rendering\PageRenderer;
use Itk\Cms\Rendering\PageValidators;
use Itk\Cms\Repository\PageTranslationRepository;
use Itk\Cms\Support\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The catch-all route: redirect from the table, else the live page at the address, else 404.
 * A visitor without a session gets the page with an ETag and a Last-Modified (PageValidators); one who sends the tag back and has the current page gets 304 without a render.
 * A page that was drawn before for the same content comes from PageCache instead of a render; every answer says which way it went in X-ITK-Cache (PageCacheStatus).
 *
 * @internal
 */
#[AsController]
final class PageController
{
    public function __construct(
        private readonly PageTranslationRepository $translations,
        private readonly RedirectService $redirects,
        private readonly PageRenderer $renderer,
        private readonly PageValidators $validators,
        private readonly PageCache $cache,
        private readonly ContentLocales $locales,
    ) {
    }

    #[Route('/{path}', name: 'itk_page', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET', 'HEAD'], priority: -1000)]
    public function __invoke(Request $request, string $path): Response
    {
        $url = Url::fromPath($path);

        $redirect = $this->redirects->lookup($url);
        if (null !== $redirect) {
            return new RedirectResponse($redirect->toPath, $redirect->code);
        }
        $locale = $this->locales->fromPath($url);
        $t = $this->translations->findLiveAtPath($locale, $url, $locale !== $this->locales->main());
        if (null === $t) {
            throw new NotFoundHttpException(I18n::t('http.page_not_found'));
        }
        $stored = (string) ($t->published['url'] ?? '');
        if ('' !== $path && '/' !== $path && Url::hasNonCanonicalSlashes($request->getPathInfo(), $stored)) { // the page's own spelling is canonical; any other goes there
            $qs = $request->getQueryString();

            return new RedirectResponse(Url::encodePath(Url::spelling($stored)) . (null !== $qs ? '?' . $qs : ''), 301);
        }

        $bypass = $this->cache->bypasses($request, $t);
        $fingerprint = $bypass ? null : $this->validators->fingerprint($t, $request);
        $validators = $this->validators->forPage($t, $request, $fingerprint);
        if (null !== $validators && $request->headers->has('If-None-Match')) { // the tag decides; a date alone cannot tell that a setting or a menu changed
            $answer = (new Response())->setEtag($validators['etag'], false)->setLastModified($validators['lastModified']);
            $answer->headers->addCacheControlDirective('no-cache'); // a 304 renews the headers the browser keeps: it must not lose the rule to ask again
            if ($answer->isNotModified($request)) {
                return ($bypass ? PageCacheStatus::Bypass : PageCacheStatus::Hit)->stamp($answer); // drawn by nobody: as good as a hit
            }
        }
        $response = null === $fingerprint ? null : $this->cache->get($request, $fingerprint);
        $status = null === $fingerprint ? PageCacheStatus::Bypass : (null === $response ? PageCacheStatus::Miss : PageCacheStatus::Hit);
        if (null === $response) {
            $response = $this->renderer->renderLive($t);
            if (null !== $fingerprint) {
                $this->cache->put($request, $fingerprint, $response, $this->renderer->lastCacheDirective());
            }
        }
        if (null !== $validators && [] === $response->headers->getCookies()) {
            $response->setEtag($validators['etag'], false)->setLastModified($validators['lastModified']);
            $response->headers->addCacheControlDirective('no-cache'); // without it a browser may keep the page for a while by the date alone: it asks again each time, and gets the short 304
        }

        return $status->stamp($response);
    }
}
