<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\DashboardOverview;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Сегодня». GET dashboard →
 *   newRequests   latest 8 requests with work status «new»   newRequestCount   all new requests   failedCount   requests not delivered to Telegram or MAX
 *   unpublished   [{ kind: 'page'|'item', id, locale, title, pageKind, template?|collection?, draft, changes, updatedAt, migrated?, awaiting, to }]  newest first
 *   awaiting      the same rows that wait for a publisher, + by, at                           proposals   [] (no AI in this iteration)
 *   recent        [{ label, to, at }] last published, newest first (8)                        languages   { main, list }
 *   locales       { code: { pages: { count, unpublished }, collections: { count, unpublished } } } enabled languages, accessible versions only
 * A person sees only what his rights show: requests, pages (by template) and collections.
 *
 * @internal
 */
#[AsController]
#[Route('/api/dashboard', name: 'itk_api_dashboard', methods: ['GET'])]
final class DashboardController extends AbstractApiController
{
    public function __construct(
        private readonly Access $access,
        private readonly DashboardOverview $overview,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        if (null === $this->access->user()) {
            throw new AccessDeniedException(I18n::t('http.sign_in_required'));
        }

        return $this->json($this->overview->build());
    }
}
