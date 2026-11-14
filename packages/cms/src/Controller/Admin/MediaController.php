<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Media\MediaService;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Media library. Item: { id, name, originalName, src, thumb (w320 WebP of an image, else ''), w, h, kb, alt, folderId, focal: {x,y}|null, crops: { "16:9": {x,y,w,h} }, mime, type: 'img'|'pdf', used: [{ label, to }], createdAt }.
 *   GET media?q=&type=img|pdf&folder=   list (`used` empty: ask media/{id}); folder: a folder id, "none" (no folder), empty (all files)
 *   GET media/{id}   item with `used`      GET media/{id}/usage   [{ label, to }]
 *   POST media (multipart, field "file", optional "folderId")   201, item
 *   PATCH media/{id} { alt, name, folderId, focal, crops }   item; folderId null or "root": the top level; focal and crops (fractions 0..1 of the picture) only for photos, crops replaces all frames
 *   POST media/move { ids, folderId }   { moved }   all or none
 *   DELETE media/{id}   { ok }
 * Folders: MediaFoldersController (media-folders).
 *
 * @internal
 */
#[AsController]
#[Route('/api/media', name: 'itk_api_media_')]
final class MediaController extends AbstractApiController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->access->require('media', 'view');
        $files = $this->media->list((string) $request->query->get('q', ''), (string) $request->query->get('type', ''), (string) $request->query->get('folder', ''));

        return $this->json(\array_map(fn ($m): array => $this->media->present($m), $files));
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->access->require('media', 'edit');
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            $first = \array_values($request->files->all())[0] ?? null;
            $file = $first instanceof UploadedFile ? $first : null;
        }

        $folderId = $request->request->get('folderId');

        return $this->json($this->media->present($this->media->upload($file, \is_string($folderId) ? $folderId : null)), 201);
    }

    #[Route('/move', name: 'move', methods: ['POST'])]
    public function move(Request $request): JsonResponse
    {
        $this->access->require('media', 'edit');
        $body = $this->body($request);

        return $this->json(['moved' => $this->media->move($this->ids($body['ids'] ?? []), $body['folderId'] ?? null)]);
    }

    #[Route('/{id}', name: 'get', requirements: ['id' => '[^/]+'], methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $this->access->require('media', 'view');

        return $this->json($this->media->present($this->media->find($id), true, $this->access->viewer()));
    }

    #[Route('/{id}/usage', name: 'usage', methods: ['GET'])]
    public function usage(string $id): JsonResponse
    {
        $this->access->require('media', 'view');

        return $this->json($this->media->usage($this->media->find($id), $this->access->viewer()));
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '[^/]+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->require('media', 'edit');

        return $this->json($this->media->present($this->media->update($id, $this->body($request)), true, $this->access->viewer()));
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => '[^/]+'], methods: ['DELETE'])]
    public function remove(string $id): JsonResponse
    {
        $this->access->require('media', 'delete');
        $this->media->remove($id);

        return $this->json(['ok' => true]);
    }
}
