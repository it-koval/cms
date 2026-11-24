<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Health\HealthService;
use Itk\Cms\Health\MessengerMonitor;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Rendering\PageCache;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Разработчику → Состояние сайта». Area `dev` (by default the administrator only): «Смотреть» opens the screen, «Править» lets him act.
 *   GET  health                          the report (see HealthService)
 *   POST health/messenger/{id}/retry     a failed message goes back to the queue it came from           { ok }
 *   POST health/messenger/{id}/delete    a failed message is dropped                                      { ok }
 *   POST health/requests/{id}/resend     a failed request is sent now (up to 20 parts) or queued (202 + queuedChannels), to { channel? } or to each messenger that failed   { ok, delivery, error, channels }
 *   POST health/updates/refresh          asks the update source again                                     { ok, updates }
 *   POST health/page-cache/clear         forgets every page kept by PageCache                             { ok }, or 500 { error } when the pool refused.
 *
 * @internal
 */
#[AsController]
#[Route('/api/health', name: 'itk_api_health_')]
final class HealthController extends AbstractApiController
{
    public function __construct(
        private readonly HealthService $health,
        private readonly MessengerMonitor $messenger,
        private readonly Access $access,
        private readonly PageCache $pageCache,
    ) {
    }

    #[Route('', name: 'report', methods: ['GET'])]
    public function report(): JsonResponse
    {
        $this->access->require('dev', 'view');

        return $this->json($this->health->report());
    }

    #[Route('/messenger/{id}/retry', name: 'messenger_retry', requirements: ['id' => '[A-Za-z0-9_\-]+'], methods: ['POST'])]
    public function retry(string $id): JsonResponse
    {
        $this->access->require('dev', 'edit');
        $this->messenger->retry($id);

        return $this->json(['ok' => true]);
    }

    #[Route('/messenger/{id}/delete', name: 'messenger_delete', requirements: ['id' => '[A-Za-z0-9_\-]+'], methods: ['POST'])]
    public function delete(string $id): JsonResponse
    {
        $this->access->require('dev', 'edit');
        $this->messenger->delete($id);

        return $this->json(['ok' => true]);
    }

    #[Route('/requests/{id}/resend', name: 'request_resend', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resend(Request $request, string $id): JsonResponse
    {
        $this->access->require('dev', 'edit');

        $result = $this->health->resendRequest($id, $this->deliveryChannel($request));

        return $this->json(['ok' => true] + $result, [] !== $result['queuedChannels'] ? 202 : 200);
    }

    #[Route('/updates/refresh', name: 'updates_refresh', methods: ['POST'])]
    public function refreshUpdates(): JsonResponse
    {
        $this->access->require('dev', 'edit');

        return $this->json(['ok' => true, 'updates' => $this->health->refreshUpdates()]);
    }

    #[Route('/page-cache/clear', name: 'page_cache_clear', methods: ['POST'])]
    public function clearPageCache(): JsonResponse
    {
        $this->access->require('dev', 'edit');
        if (!$this->pageCache->clear()) {
            throw new ContentException(I18n::t('ops.cache_clear_failed'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json(['ok' => true]);
    }
}
