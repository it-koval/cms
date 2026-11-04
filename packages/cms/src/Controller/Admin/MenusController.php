<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\MenuService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Menu areas and menus (rights: area `set:menus`). Locale: ?locale=ru.
 *   GET menu-areas   [{ key, name, depth, snippet, usedIn, usedInAll, counts }]    POST menu-areas { name, depth } (201, area)
 *   PATCH menu-areas/{key} { name?, depth? } area      DELETE menu-areas/{key}   { ok, usedIn }
 *   GET menus        { [areaKey]: items } of the language          PUT menus { [areaKey]: items }   the same, after saving
 *   GET menus/{key}  items of one area                             PUT menus/{key} items | { items }   items, after saving
 * Item: { _id, label, link: { kind: 'page'|'url', page?, url? }, children?: [] }.
 *
 * @internal
 */
#[AsController]
#[Route('/api', name: 'itk_api_menus_')]
final class MenusController extends AbstractApiController
{
    public function __construct(
        private readonly MenuService $menus,
        private readonly Access $access,
    ) {
    }

    #[Route('/menu-areas', name: 'areas', methods: ['GET'])]
    public function areas(): JsonResponse
    {
        $this->access->require('set:menus', 'view');

        return $this->json($this->menus->areas());
    }

    #[Route('/menu-areas', name: 'area_create', methods: ['POST'])]
    public function createArea(Request $request): JsonResponse
    {
        $this->access->require('set:menus', 'edit');
        $body = $this->body($request);
        $area = $this->menus->createArea((string) ($body['name'] ?? ''), (int) ($body['depth'] ?? 1));

        return $this->json($this->areaRow($area->key), 201);
    }

    #[Route('/menu-areas/{key}', name: 'area_update', requirements: ['key' => '[^/]+'], methods: ['PATCH'])]
    public function updateArea(Request $request, string $key): JsonResponse
    {
        $this->access->require('set:menus', 'edit');
        $this->menus->updateArea($key, $this->body($request));

        return $this->json($this->areaRow($key));
    }

    #[Route('/menu-areas/{key}', name: 'area_remove', requirements: ['key' => '[^/]+'], methods: ['DELETE'])]
    public function removeArea(string $key): JsonResponse
    {
        $this->access->require('set:menus', 'edit');

        return $this->json($this->menus->removeArea($key));
    }

    #[Route('/menus', name: 'all', methods: ['GET'])]
    public function all(Request $request): JsonResponse
    {
        $this->access->require('set:menus', 'view');

        return $this->json($this->asObject($this->menus->menus($this->locale($request))));
    }

    #[Route('/menus', name: 'save_all', methods: ['PUT'])]
    public function saveAll(Request $request): JsonResponse
    {
        $this->access->require('set:menus', 'edit');
        $body = $this->body($request);

        return $this->json($this->asObject($this->menus->saveMenus($this->without($body, 'locale'), $this->locale($request, $body))));
    }

    #[Route('/menus/{key}', name: 'one', requirements: ['key' => '[^/]+'], methods: ['GET'])]
    public function one(Request $request, string $key): JsonResponse
    {
        $this->access->require('set:menus', 'view');
        $all = $this->menus->menus($this->locale($request));

        return $this->json($all[$key] ?? throw ContentException::notFound(I18n::t('http.menu_not_found')));
    }

    #[Route('/menus/{key}', name: 'save_one', requirements: ['key' => '[^/]+'], methods: ['PUT'])]
    public function saveOne(Request $request, string $key): JsonResponse
    {
        $this->access->require('set:menus', 'edit');
        $body = $this->body($request);
        $items = isset($body['items']) && \is_array($body['items']) ? $body['items'] : (\array_is_list($body) ? $body : []);

        return $this->json($this->menus->saveMenus([$key => $items], $this->locale($request, $body))[$key] ?? []);
    }

    /** @return array<string, mixed> */
    private function areaRow(string $key): array
    {
        foreach ($this->menus->areas() as $a) {
            if ($a['key'] === $key) {
                return $a;
            }
        }
        throw ContentException::notFound(I18n::t('http.menu_not_found'));
    }

    /**
     * @param array<string, mixed> $map
     */
    private function asObject(array $map): array|\stdClass
    {
        return [] === $map ? new \stdClass() : $map;
    }
}
