<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Site;

use Itk\Cms\Health\LivenessProbe;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /healthz: for a load balancer or a monitor. Open to everyone, so it says only {"status": "ok"} (200) or {"status": "fail"} (503); the details are in «Состояние сайта» for the staff.
 *
 * @internal
 */
#[AsController]
final class HealthzController
{
    public function __construct(private readonly LivenessProbe $probe)
    {
    }

    #[Route('/healthz', name: 'itk_healthz', methods: ['GET', 'HEAD'])]
    public function __invoke(): Response
    {
        $alive = $this->probe->isAlive();

        return new JsonResponse(['status' => $alive ? 'ok' : 'fail'], $alive ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
    }
}
