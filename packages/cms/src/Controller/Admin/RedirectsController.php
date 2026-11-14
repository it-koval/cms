<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\RedirectService;
use Itk\Cms\Entity\Redirect;
use Itk\Cms\Security\Access;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redirects (rights: area `set:redirects`). Row: { id, from, to, code: 301|302, createdAt, locale }.
 *   GET redirects?locale=   rows, newest first     POST redirects { from, to, code } (201, row)
 *   PATCH redirects/{id} { from?, to?, code? } row  DELETE redirects/{id} { ok }.
 *
 * @internal
 */
#[AsController]
#[Route('/api/redirects', name: 'itk_api_redirects_')]
final class RedirectsController extends AbstractApiController
{
    public function __construct(
        private readonly RedirectService $redirects,
        private readonly Access $access,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->access->require('set:redirects', 'view');
        $locale = $this->locale($request);
        $rows = null !== $locale && $locale !== $this->mainLocale ? [] : $this->redirects->all(); // iteration 1: one language, every path is in it

        return $this->json(\array_map($this->present(...), $rows));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->access->require('set:redirects', 'edit');
        $body = $this->body($request);

        return $this->json($this->present($this->redirects->create(\trim((string) ($body['from'] ?? '')), \trim((string) ($body['to'] ?? '')), (int) ($body['code'] ?? 301))), 201);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '[^/]+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->require('set:redirects', 'edit');

        return $this->json($this->present($this->redirects->update($id, $this->without($this->body($request), 'id', 'locale', 'createdAt'))));
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => '[^/]+'], methods: ['DELETE'])]
    public function remove(string $id): JsonResponse
    {
        $this->access->require('set:redirects', 'delete');
        $this->redirects->remove($id);

        return $this->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function present(Redirect $r): array
    {
        return [
            'id' => $r->id, 'from' => $r->fromPath, 'to' => $r->toPath, 'code' => $r->code, 'locale' => $this->mainLocale,
            'createdAt' => $r->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
