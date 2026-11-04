<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\Theme\AdminTheme;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The colours and corners of the admin (rights: area `set:theme`). Answer of every call:
 *   { theme: { brand, accent, radius, logo }, custom, defaults: { brand, accent, radius }, vars: { '--cy': '#…', … }, checks: { brand, accent }, ladder: { brand: { 50: '#…', … }, accent } }
 *   GET    admin-theme                          the look in force
 *   POST   admin-theme/preview { brand?, accent?, radius? }   what that look would be, nothing is saved (a colour no text reads on is answered with checks.ok = false)
 *   PUT    admin-theme { brand?, accent?, radius? }           saved at once (422 for a colour no text reads on)
 *   DELETE admin-theme                          back to the developer's look
 * The same look is in window.ITK_ADMIN.theme (AdminAppController).
 *
 * @internal
 */
#[AsController]
#[Route('/api/admin-theme', name: 'itk_api_admin_theme_')]
final class ThemeController extends AbstractApiController
{
    private const AREA = 'set:theme';

    public function __construct(
        private readonly AdminTheme $theme,
        private readonly Access $access,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $this->access->require(self::AREA, 'view');

        return $this->json($this->theme->payload());
    }

    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->theme->payload($this->theme->sanitize($this->body($request), false)));
    }

    #[Route('', name: 'save', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');

        return $this->json($this->theme->save($this->body($request)));
    }

    #[Route('', name: 'reset', methods: ['DELETE'])]
    public function reset(): JsonResponse
    {
        $this->access->require(self::AREA, 'edit');
        $this->theme->reset();

        return $this->json($this->theme->payload());
    }
}
