<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\IndexNow\IndexNowState;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /{key}.txt: the proof of ownership that IndexNow fetches. The body is the key itself, and only the key of this site (IndexNowSite::key()) is answered,
 * while the toggle is on; any other 32 hex characters is a missing page.
 *
 * @internal
 */
#[AsController]
final class IndexNowKeyController
{
    public function __construct(private readonly IndexNowSite $site)
    {
    }

    #[Route('/{key}.txt', name: 'itk_indexnow_key', requirements: ['key' => '[0-9a-f]{32}'], methods: ['GET', 'HEAD'])]
    public function __invoke(string $key): Response
    {
        if (\in_array($this->site->state(), [IndexNowState::Off, IndexNowState::NoAddress], true) || !\hash_equals($this->site->key(), $key)) {
            throw new NotFoundHttpException();
        }

        return new Response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=600']);
    }
}
