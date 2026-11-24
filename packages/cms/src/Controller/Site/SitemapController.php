<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\SitemapBuilder;
use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /sitemap.xml: generated from the live pages (see SitemapBuilder). A site that keeps its own public/sitemap.xml keeps it:
 * the web server serves that file before PHP runs, and if a request still reaches this route the file is sent instead of the generated one.
 *
 * @internal
 */
#[AsController]
final class SitemapController
{
    public function __construct(
        private readonly SitemapBuilder $sitemap,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/sitemap.xml', name: 'itk_sitemap', methods: ['GET', 'HEAD'])]
    public function __invoke(?Request $request = null): Response
    {
        $static = $this->projectDir . '/public/sitemap.xml';
        if (\is_file($static)) {
            $file = new BinaryFileResponse($static, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
            $file->headers->set('Cache-Control', 'public, max-age=600');

            return $file;
        }
        $xml = $this->sitemap->xml();
        if (null === $xml) {
            throw new NotFoundHttpException(I18n::t('http.sitemap_no_url'));
        }

        $response = new Response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=600']);
        if ($this->sitemap->isMultilingual()) {
            $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
            $response->setEtag(\hash('sha256', $xml));
            // Only the content tag can distinguish changes made within one second.
            if (null !== $request && $request->headers->has('If-None-Match')) {
                $response->isNotModified($request);
            }
        }

        return $response;
    }
}
