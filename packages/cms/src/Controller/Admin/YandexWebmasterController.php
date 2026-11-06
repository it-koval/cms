<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Security\Access;
use Itk\Cms\YandexWebmaster\Model\WebmasterStatus;
use Itk\Cms\YandexWebmaster\WebmasterService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * «Сегодня» → how the site does in Yandex (Webmaster). Rights: area `set:analytics`, the same as the counters and verification codes. The token is never in an answer.
 *   GET    yandex-webmaster            the status (see WebmasterStatus), refreshed from Yandex when the snapshot is older than 6 hours; on a failure the last snapshot with `error`. «Смотреть»
 *   POST   yandex-webmaster/connect    { token } validates it against Yandex, finds or adds the site, starts the check of ownership → the status. «Править». 422 { error } for an empty or refused token or a site without a public address, 502 when Yandex does not answer
 *   POST   yandex-webmaster/refresh    refreshes now → the status. «Смотреть»
 *   DELETE yandex-webmaster            forgets the token, the site and the snapshot (the site stays in Webmaster) → the status. «Править».
 *
 * @internal
 */
#[AsController]
#[Route('/api/yandex-webmaster', name: 'itk_api_yandex_webmaster_')]
final class YandexWebmasterController extends AbstractApiController
{
    private const AREA = 'set:analytics';

    public function __construct(
        private readonly WebmasterService $webmaster,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->answer($this->webmaster->status());
    }

    #[Route('/connect', name: 'connect', methods: ['POST'])]
    public function connect(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');
        $token = $this->body($request)['token'] ?? '';

        return $this->answer($this->webmaster->connect(\is_string($token) ? $token : ''));
    }

    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->answer($this->webmaster->status(true));
    }

    #[Route('', name: 'disconnect', methods: ['DELETE'])]
    public function disconnect(): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->answer($this->webmaster->disconnect());
    }

    private function answer(WebmasterStatus $status): JsonResponse
    {
        return $this->json($status->toArray($this->access->can(self::AREA, 'edit')));
    }
}
