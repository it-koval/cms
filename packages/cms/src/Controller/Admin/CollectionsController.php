<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\CollectionQuery;
use Itk\Cms\Content\CollectionService;
use Itk\Cms\Content\ContentException;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Collection;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Collections (records without pages of their own). Rights: area `col:<key>`. Locale: ?locale=ru.
 *   GET collections                          [{ key, title, count, unpublished, … }] (only collections the person may see)
 *   GET collections/{col}?q=&status=&onlyTranslated=&filter[date][from]=2026-11-01&sort=-date&page=2&perPage=50&locale=
 *        { items, total, page, perPage, facets, metrics } — see Itk\Cms\Content\CollectionQuery.
 *   PUT collections/{col}/order { ids }
 *   PUT collections/{col}/tree [{ id, parentId, position }]   moves records of a collection with tree() ({ ok, moved }); nodes not named stay
 *   GET collections/{col}/{id}               { item, published, changes, locale, translated, locales }
 *   PUT|PATCH collections/{col}/{id}         flat record (data merged)      POST collections/{col}   new record (201)
 *        `baseVersion` (the `version` of the record the form loaded) is checked as for pages: 409 `conflict` and nothing written when the draft changed meanwhile
 *   DELETE collections/{col}/{id}            { ok }                         POST collections/{col}/{id}/{publish|revert|submit|return}   flat record.
 *
 * @internal
 */
#[AsController]
#[Route('/api/collections', name: 'itk_api_collections_')]
final class CollectionsController extends AbstractApiController
{
    private const ACTIONS = [
        'publish' => ['publish', 'publish'],
        'revert' => ['revert', 'edit'],
        'submit' => ['submit', 'edit'],
        'return' => ['returnToAuthor', 'publish'],
    ];

    public function __construct(
        private readonly CollectionService $collections,
        private readonly CollectionQuery $query,
        private readonly Schema $schema,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $viewer = $this->access->viewer();
        $rows = \array_values(\array_filter(
            $this->collections->collections($this->locale($request)),
            fn (array $c): bool => $this->access->can('col:' . $c['key'], 'view') && ($this->schema->collection($c['key'])?->isVisibleTo($viewer) ?? false),
        ));

        return $this->json($rows);
    }

    #[Route('/{col}', name: 'rows', requirements: ['col' => '[a-z0-9_\-]+'], methods: ['GET'])]
    public function rows(Request $request, string $col): JsonResponse
    {
        $collection = $this->need($col, 'view');
        $locale = $this->locale($request);
        $this->collections->ensureSingleton($collection);
        $this->collections->loaded($col, $locale);

        return $this->json($this->query->run($collection, $request->query->all(), $locale));
    }

    #[Route('/{col}', name: 'create', requirements: ['col' => '[a-z0-9_\-]+'], methods: ['POST'])]
    public function create(Request $request, string $col): JsonResponse
    {
        $this->need($col, 'edit');
        $body = $this->body($request);

        return $this->json($this->collections->create($col, $this->without($body, 'id', 'locale')), 201);
    }

    #[Route('/{col}/order', name: 'order', requirements: ['col' => '[a-z0-9_\-]+'], methods: ['PUT'])]
    public function order(Request $request, string $col): JsonResponse
    {
        $this->need($col, 'edit');
        $this->collections->reorder($col, $this->ids($this->body($request)['ids'] ?? null));

        return $this->json(['ok' => true]);
    }

    #[Route('/{col}/tree', name: 'tree', requirements: ['col' => '[a-z0-9_\-]+'], methods: ['PUT'])]
    public function tree(Request $request, string $col): JsonResponse
    {
        $this->need($col, 'edit');
        $nodes = $this->body($request);
        $nodes = \array_is_list($nodes) ? $nodes : (array) ($nodes['nodes'] ?? []);

        return $this->json(['ok' => true, 'moved' => $this->collections->tree($col, \array_values(\array_filter($nodes, 'is_array')))]);
    }

    #[Route('/{col}/{id}', name: 'get', requirements: ['col' => '[a-z0-9_\-]+', 'id' => '(?!(?:order|tree)$)[^/]+'], methods: ['GET'])]
    public function get(Request $request, string $col, string $id): JsonResponse
    {
        $this->need($col, 'view');

        return $this->json($this->collections->get($col, $id, $this->locale($request)));
    }

    #[Route('/{col}/{id}', name: 'update', requirements: ['col' => '[a-z0-9_\-]+', 'id' => '(?!(?:order|tree)$)[^/]+'], methods: ['PUT', 'PATCH'])]
    public function update(Request $request, string $col, string $id): JsonResponse
    {
        $this->need($col, 'edit');
        $body = $this->body($request);

        return $this->json($this->collections->update($col, $id, $this->without($body, 'id', 'locale', 'baseVersion'), $this->locale($request, $body), baseVersion: $this->baseVersion($body)));
    }

    #[Route('/{col}/{id}', name: 'remove', requirements: ['col' => '[a-z0-9_\-]+', 'id' => '(?!(?:order|tree)$)[^/]+'], methods: ['DELETE'])]
    public function remove(string $col, string $id): JsonResponse
    {
        $this->need($col, 'delete');
        $this->collections->remove($col, $id);

        return $this->json(['ok' => true]);
    }

    #[Route('/{col}/{id}/translations', name: 'translation_add', requirements: ['col' => '[a-z0-9_\-]+'], methods: ['POST'])]
    public function addTranslation(Request $request, string $col, string $id): JsonResponse
    {
        $this->need($col, 'edit');
        [$locale, $from] = $this->translationInput($request);

        return $this->json($this->collections->addTranslation($col, $id, $locale, $from), 201);
    }

    #[Route('/{col}/{id}/translations/{locale}', name: 'translation_remove', requirements: ['col' => '[a-z0-9_\-]+', 'locale' => '[^/]+'], methods: ['DELETE'])]
    public function removeTranslation(string $col, string $id, string $locale): JsonResponse
    {
        $this->need($col, 'delete');
        $this->collections->removeTranslation($col, $id, $this->contentLocales->assertUsable($locale));

        return $this->json(['ok' => true]);
    }

    #[Route('/{col}/{id}/{action}', name: 'action', requirements: ['col' => '[a-z0-9_\-]+', 'action' => 'publish|revert|submit|return'], methods: ['POST'])]
    public function action(Request $request, string $col, string $id, string $action): JsonResponse
    {
        [$method, $right] = self::ACTIONS[$action];
        $this->need($col, $right);

        $body = $this->body($request);
        $locale = $this->locale($request, $body);
        if ('publish' === $action) {
            return $this->json($this->collections->publish($col, $id, $locale, $this->baseVersion($body)));
        }

        return $this->json($this->collections->{$method}($col, $id, $locale));
    }

    private function need(string $col, string $action): Collection
    {
        $collection = $this->schema->collection($col) ?? throw ContentException::notFound(I18n::t('http.collection_not_found'));
        if (!$collection->isVisibleTo($this->access->viewer())) {
            throw new AccessDeniedException(I18n::t('http.no_permission'));
        }
        $this->access->require('col:' . $col, $action);

        return $collection;
    }
}
