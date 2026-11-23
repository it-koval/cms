<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\PageService;
use Itk\Cms\Entity\Page;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages. Locale: ?locale=ru (default: the main language).
 *   GET    pages?template=&parentId=            rows (no sections)        GET pages/{id}            flat page          GET pages/{id}/published   snapshot|null
 *   GET    pages/{id}/draft                     { page, published, changes, locale, translated, locales, shell }
 *   PUT    pages/{id}/draft                     autosave: flat page + changed      PATCH pages/{id}    list-level edit: flat page
 *          `baseVersion` (the `version` of the page the editor loaded) is checked: a draft that changed meanwhile gives 409 { error, details: { code: 'conflict', current: { version, by, at, via, viaName } } } and nothing is written; without it the save is not checked
 *   PUT    pages/tree [{ id, parentId, position }]   moves pages in the site tree (drag and drop): { ok, moved }; pages not named stay where they are
 *   POST   pages                                new page (201, flat page)          DELETE pages/{id}   { ok }          PUT pages/order { ids }
 *   POST   pages/{id}/{publish|revert|unpublish|republish|submit|return}   flat page.
 *
 * @internal
 */
#[AsController]
#[Route('/api/pages', name: 'itk_api_pages_')]
final class PagesController extends AbstractApiController
{
    private const ACTIONS = [
        'publish' => ['publish', 'publish'],
        'revert' => ['revert', 'edit'],
        'unpublish' => ['unpublish', 'publish'],
        'republish' => ['republish', 'publish'],
        'undo-unpublish' => ['republish', 'publish'],
        'submit' => ['submit', 'edit'],
        'return' => ['returnToAuthor', 'publish'],
    ];

    public function __construct(
        private readonly PageService $pages,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->access->require('pages', 'view');
        $parent = $request->query->get('parentId');
        $rows = $this->pages->list($this->locale($request), $request->query->get('template') ?: null, \in_array($parent, [null, '', 'null', 'root'], true) ? null : (string) $parent, $this->access->viewer());
        if (\in_array($parent, ['null', 'root'], true)) {
            $rows = \array_values(\array_filter($rows, static fn (array $r): bool => null === $r['parentId']));
        }

        return $this->json($rows);
    }

    #[Route('/order', name: 'order', methods: ['PUT'])]
    public function order(Request $request): JsonResponse
    {
        $this->access->require('pages', 'edit');
        $this->pages->reorder($this->ids($this->body($request)['ids'] ?? null), fn (Page $p) => $this->access->require('pages', 'edit', $p->template));

        return $this->json(['ok' => true]);
    }

    #[Route('/tree', name: 'tree', methods: ['PUT'])]
    public function tree(Request $request): JsonResponse
    {
        $this->access->require('pages', 'edit');
        $nodes = $this->body($request);
        $nodes = \array_is_list($nodes) ? $nodes : (array) ($nodes['nodes'] ?? []);
        $moved = $this->pages->tree(\array_values(\array_filter($nodes, 'is_array')), fn (Page $p) => $this->access->require('pages', 'edit', $p->template));

        return $this->json(['ok' => true, 'moved' => $moved]);
    }

    #[Route('/{id}', name: 'get', requirements: ['id' => '[^/]+'], methods: ['GET'])]
    public function get(Request $request, string $id): JsonResponse
    {
        $this->need($id, 'view');

        return $this->json($this->pages->get($id, $this->locale($request)));
    }

    #[Route('/{id}/published', name: 'published', methods: ['GET'])]
    public function published(Request $request, string $id): JsonResponse
    {
        $this->need($id, 'view');

        return $this->json($this->pages->getPublished($id, $this->locale($request), visibleOnly: true));
    }

    #[Route('/{id}/draft', name: 'draft', methods: ['GET'])]
    public function draft(Request $request, string $id): JsonResponse
    {
        $this->need($id, 'view');

        return $this->json($this->pages->getDraft($id, $this->locale($request)));
    }

    #[Route('/{id}/draft', name: 'save_draft', methods: ['PUT'])]
    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $body = $this->body($request);
        $this->need($id, 'edit');
        $this->requireNewTemplate($body);

        return $this->json($this->pages->saveDraft($id, $this->locale($request, $body), $this->without($body, 'locale', 'baseVersion'), $this->baseVersion($body)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $this->without($this->body($request), 'id', 'locale');
        $this->access->require('pages', 'edit', (string) ($body['template'] ?? 'default'));

        return $this->json($this->pages->create($body), 201);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '[^/]+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $body = $this->body($request);
        $this->need($id, 'edit');
        $this->requireNewTemplate($body);

        return $this->json($this->pages->update($id, $this->locale($request, $body), $this->without($body, 'locale', 'sections')));
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => '[^/]+'], methods: ['DELETE'])]
    public function remove(string $id): JsonResponse
    {
        $this->need($id, 'delete');
        $this->pages->remove($id);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/translations', name: 'translation_add', methods: ['POST'])]
    public function addTranslation(Request $request, string $id): JsonResponse
    {
        $this->need($id, 'edit');
        [$locale, $from] = $this->translationInput($request);

        return $this->json($this->pages->addTranslation($id, $locale, $from), 201);
    }

    #[Route('/{id}/translations/{locale}', name: 'translation_remove', requirements: ['locale' => '[a-z]{2,3}'], methods: ['DELETE'])]
    public function removeTranslation(string $id, string $locale): JsonResponse
    {
        $this->need($id, 'delete');
        $this->pages->removeTranslation($id, $locale);

        return $this->json(['ok' => true]);
    }

    #[Route('/{id}/{action}', name: 'action', requirements: ['action' => 'publish|revert|unpublish|republish|undo-unpublish|submit|return'], methods: ['POST'])]
    public function action(Request $request, string $id, string $action): JsonResponse
    {
        [$method, $right] = self::ACTIONS[$action];
        $this->need($id, $right);

        $body = $this->body($request);
        $locale = $this->locale($request, $body);
        if ('publish' === $action) {
            return $this->json($this->pages->publish($id, $locale, $this->baseVersion($body)));
        }

        return $this->json($this->pages->{$method}($id, $locale));
    }

    private function need(string $id, string $action): void
    {
        $this->access->require('pages', $action, $this->pages->find($id)->template);
    }

    /** @param array<string, mixed> $body */
    private function requireNewTemplate(array $body): void
    {
        if (isset($body['template']) && \is_string($body['template'])) {
            $this->access->require('pages', 'edit', $body['template']);
        }
    }
}
