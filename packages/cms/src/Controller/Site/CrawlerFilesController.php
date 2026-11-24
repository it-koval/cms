<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\CrawlerFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /robots.txt and GET /llms.txt: a file of the site in public/ if there is one (the web server serves it before PHP runs, and a request that still reaches
 * the route gets the same file), else the text of «Настройки → SEO», else the standard text (see CrawlerFiles).
 *
 * @internal
 */
#[AsController]
final class CrawlerFilesController
{
    public function __construct(private readonly CrawlerFiles $files)
    {
    }

    #[Route('/robots.txt', name: 'itk_robots', methods: ['GET', 'HEAD'])]
    public function robots(): Response
    {
        return $this->answer(CrawlerFiles::ROBOTS);
    }

    #[Route('/llms.txt', name: 'itk_llms', methods: ['GET', 'HEAD'])]
    public function llms(?Request $request = null): Response
    {
        return $this->answer(CrawlerFiles::LLMS, $request);
    }

    private function answer(string $kind, ?Request $request = null): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
        $static = $this->files->staticFile($kind);
        if (null !== $static) {
            $file = new BinaryFileResponse($static, 200, $headers);
            $file->headers->set('Cache-Control', 'public, max-age=600');

            return $file;
        }

        $body = $this->files->body($kind);
        $response = new Response($body, 200, $headers + ['Cache-Control' => 'public, max-age=600']);
        if (CrawlerFiles::LLMS === $kind && $this->files->isMultilingual()) {
            $response->headers->set('Cache-Control', 'public, max-age=0, must-revalidate');
            $response->setEtag(\hash('sha256', $body));
            // An IMS-only request gets the current body: dates cannot distinguish same-second edits.
            if (null !== $request && $request->headers->has('If-None-Match')) {
                $response->isNotModified($request);
            }
        }

        return $response;
    }
}
