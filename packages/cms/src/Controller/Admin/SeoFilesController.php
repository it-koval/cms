<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\CrawlerFiles;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * robots.txt and llms.txt of the site (rights: area `set:seo`). Each file is { text, default, custom, file }:
 * text = what the settings make the site answer (the saved text, else the standard one), default = the standard text as it is now,
 * custom = is a text of the site saved, file = is there a file in public/ (then the site sends the file, not this text).
 *   GET seo-files                    { robots: {…}, llms: {…} }
 *   PUT seo-files { robots?, llms? } the same answer. A missing key stays as it is, null or '' (or the standard text) brings the standard one back. 422 { error, details: { robots|llms: message } } for a text over 64 KB or a value that is not a string.
 *
 * @internal
 */
#[AsController]
#[Route('/api/seo-files', name: 'itk_api_seo_files_')]
final class SeoFilesController extends AbstractApiController
{
    public function __construct(
        private readonly CrawlerFiles $files,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $this->access->require('set:seo', 'view');

        return $this->json($this->files->overview());
    }

    #[Route('', name: 'update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $this->access->require('set:seo', 'edit');
        $this->files->save($this->body($request));

        return $this->json($this->files->overview());
    }
}
