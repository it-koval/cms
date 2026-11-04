<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Content\CrawlerFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
    public function llms(): Response
    {
        return $this->answer(CrawlerFiles::LLMS);
    }

    private function answer(string $kind): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
        $static = $this->files->staticFile($kind);
        if (null !== $static) {
            $file = new BinaryFileResponse($static, 200, $headers);
            $file->headers->set('Cache-Control', 'public, max-age=600');

            return $file;
        }

        return new Response($this->files->body($kind), 200, $headers + ['Cache-Control' => 'public, max-age=600']);
    }
}
