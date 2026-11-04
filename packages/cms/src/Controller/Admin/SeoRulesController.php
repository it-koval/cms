<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\SeoAddressCheck;
use Itk\Cms\Content\SeoRuleService;
use Itk\Cms\Entity\SeoRule;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * SEO of addresses (rights: area `set:seo`). Row: { id, path, match: exact|prefix, locale: ?string, title, description, robots, canonical, ogImage, active, createdAt, updatedAt }.
 *   GET seo-rules?q=&locale=            rows, newest first (q looks in the address, title and description; locale = the rules of that language and of all)
 *   POST seo-rules { path, match?, ... } (201, row)         PATCH seo-rules/{id} { any of the keys } row         DELETE seo-rules/{id} { ok }
 *   GET seo-rules/check?path=&locale=   what the address answers and where each meta tag comes from: { path, answer, rules, meta } (SeoAddressCheck).
 *
 * @internal
 */
#[AsController]
#[Route('/api/seo-rules', name: 'itk_api_seo_rules_')]
final class SeoRulesController extends AbstractApiController
{
    public function __construct(
        private readonly SeoRuleService $seo,
        private readonly SeoAddressCheck $checker,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $this->access->require('set:seo', 'view');
        $q = \mb_strtolower(\trim((string) $request->query->get('q', '')));
        $locale = $this->locale($request);
        $rows = \array_filter($this->seo->all(), static function (SeoRule $r) use ($q, $locale): bool {
            if (null !== $locale && null !== $r->locale && '' !== $r->locale && $r->locale !== $locale) {
                return false;
            }

            return '' === $q || \str_contains(\mb_strtolower($r->path . ' ' . $r->title . ' ' . $r->description), $q);
        });

        return $this->json(\array_map($this->present(...), \array_values($rows)));
    }

    #[Route('/check', name: 'check', methods: ['GET'])]
    public function check(Request $request): JsonResponse
    {
        $this->access->require('set:seo', 'view');

        return $this->json($this->checker->check((string) $request->query->get('path', ''), $this->locale($request)));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->access->require('set:seo', 'edit');

        return $this->json($this->present($this->seo->create($this->without($this->body($request), 'id', 'createdAt', 'updatedAt'))), 201);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '[^/]+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $this->access->require('set:seo', 'edit');

        return $this->json($this->present($this->seo->update($id, $this->without($this->body($request), 'id', 'createdAt', 'updatedAt'))));
    }

    #[Route('/{id}', name: 'remove', requirements: ['id' => '[^/]+'], methods: ['DELETE'])]
    public function remove(string $id): JsonResponse
    {
        $this->access->require('set:seo', 'delete');
        $this->seo->remove($id);

        return $this->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function present(SeoRule $r): array
    {
        $utc = static fn (\DateTimeImmutable $d): string => $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => $r->id, 'path' => $r->path, 'match' => $r->matchType, 'locale' => $r->locale,
            'title' => $r->title, 'description' => $r->description, 'robots' => $r->robots, 'canonical' => $r->canonical, 'ogImage' => $r->ogImage,
            'active' => $r->active, 'createdAt' => $utc($r->createdAt), 'updatedAt' => $utc($r->updatedAt),
        ];
    }
}
