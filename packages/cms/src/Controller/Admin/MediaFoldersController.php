<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Media\ImageVariants;
use Itk\Cms\Media\MediaFolderService;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Folders of the media library (rights: area media). Folder: { id, parentId, name, position, files (directly in it) }.
 *   GET media-folders   { folders: [Folder] (parents first inside each level, by position), total (all files), unfiled (files in no folder), ratios: ["16:9", …] (itk_cms.images.ratios, for the image editor) }
 *   POST media-folders { name, parentId? }   201, Folder
 *   PATCH media-folders/{id} { name?, parentId? (null or "root": the top level), position? (place among the folders of its parent) }   Folder
 *   DELETE media-folders/{id}?moveTo=   { ok }; a folder with files or folders inside needs moveTo (a folder id or "root"), else 409 { details: { files, folders } }.
 *
 * @internal
 */
#[AsController]
#[Route('/api/media-folders', name: 'itk_api_media_folders_')]
final class MediaFoldersController extends AbstractApiController
{
    public function __construct(
        private readonly MediaFolderService $folders,
        private readonly ImageVariants $images,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'tree', methods: ['GET'])]
    public function tree(): JsonResponse
    {
        $this->access->require('media', 'view');

        return $this->json($this->folders->tree() + ['ratios' => $this->images->ratios()]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->access->require('media', 'edit');
        $body = $this->body($request);
        $parentId = $body['parentId'] ?? null;

        return $this->json($this->folders->present($this->folders->create((string) ($body['name'] ?? ''), \is_string($parentId) ? $parentId : null)), 201);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '[^/]+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->require('media', 'edit');

        return $this->json($this->folders->present($this->folders->update($id, $this->body($request))));
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => '[^/]+'], methods: ['DELETE'])]
    public function remove(Request $request, string $id): JsonResponse
    {
        $this->access->require('media', 'edit');
        $moveTo = $request->query->get('moveTo');
        $this->folders->remove($id, \is_string($moveTo) ? $moveTo : null);

        return $this->json(['ok' => true]);
    }
}
