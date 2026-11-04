<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\History\HistoryAdmin;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * History of changes and the trash. Rights: `view` of the thing for reading, `edit` for returning (pages by template, `col:<key>`, `set:<group>`, `set:menus`). Locale: ?locale=ru.
 *   GET  history/{kind}/{id}?limit=&offset=     { items: [{ id, action, userName, title, summary, createdAt, updatedAt, baseline }], total, limit, offset, keep, days }
 *                                               kind: page | item | settings | menu (id of the page or record; key of the group or the menu)
 *   GET  history/revision/{id}                  one entry + `against` (what returning to it would change now), `current`, `inTrash`
 *   POST history/revision/{id}/restore          { ok, kind, id, key, locale, restored: 'version'|'trash' } — the draft becomes that version; a deletion brings the page or record back
 *   GET  history/trash?kind=page|item&key=      { items: [{ id (of the entry), kind, resourceId, key, keyTitle, title, userName, deletedAt, locales, wasLive, canRestore, restorable }], total }.
 *
 * @internal
 */
#[AsController]
#[Route('/api/history', name: 'itk_api_history_')]
final class HistoryController extends AbstractApiController
{
    public function __construct(
        private readonly HistoryAdmin $history,
    ) {
    }

    #[Route('/trash', name: 'trash', methods: ['GET'])]
    public function trash(Request $request): JsonResponse
    {
        return $this->json($this->history->trash((string) $request->query->get('kind', '') ?: null, (string) $request->query->get('key', '') ?: null));
    }

    #[Route('/revision/{id}', name: 'revision', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function revision(int $id): JsonResponse
    {
        return $this->json($this->history->entry($id));
    }

    #[Route('/revision/{id}/restore', name: 'restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id): JsonResponse
    {
        return $this->json($this->history->restore($id));
    }

    #[Route('/{kind}/{id}', name: 'list', requirements: ['kind' => 'page|item|settings|menu', 'id' => '[^/]+'], methods: ['GET'])]
    public function list(Request $request, string $kind, string $id): JsonResponse
    {
        return $this->json($this->history->entries($kind, $id, $this->locale($request), (int) $request->query->get('limit', 50), (int) $request->query->get('offset', 0)));
    }
}
