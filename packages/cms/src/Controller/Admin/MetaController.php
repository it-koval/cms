<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Admin\NavBuilder;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Itk\Cms\Security\AdminCsrf;
use Itk\Cms\Security\CurrentUser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * What the admin loads first (any signed-in person).
 *   GET schema     the site schema in the admin's shape (Schema::toAdminArray()) for this person (fields and actions he may not use are left out)
 *                  + nav: the menu built by the server (NavBuilder), only what he may open
 *   GET me         { id, name, email, status, roleId, roleName, preset, builtin, perms, templates, twoFactor, locale, localePreference, csrf }
 *                  (`csrf`: the token of the admin API, the same as in window.ITK_ADMIN.csrf; `npm run dev` has no shell page and takes it from here)
 *   GET languages  { main: 'ru', list: [{ code, name }] }   (iteration 1: only the main language).
 *
 * @internal
 */
#[AsController]
#[Route('/api', name: 'itk_api_meta_')]
final class MetaController extends AbstractApiController
{
    private const NAMES = ['ru' => 'Русский', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano', 'pl' => 'Polski', 'uk' => 'Українська', 'kk' => 'Қазақша', 'tr' => 'Türkçe', 'zh' => '中文']; // i18n-ignore: languages are named in themselves

    public function __construct(
        private readonly Schema $schema,
        private readonly CurrentUser $current,
        private readonly Access $access,
        private readonly NavBuilder $nav,
        private readonly AdminCsrf $csrf,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
    ) {
    }

    #[Route('/schema', name: 'schema', methods: ['GET'])]
    public function schema(): JsonResponse
    {
        $this->user();
        $viewer = $this->access->viewer();

        return $this->json($this->schema->toAdminArray($viewer) + ['nav' => $this->nav->build($viewer)]);
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        return $this->json($this->user() + ['csrf' => $this->csrf->token($request)]);
    }

    #[Route('/languages', name: 'languages', methods: ['GET'])]
    public function languages(): JsonResponse
    {
        $this->user();

        return $this->json(self::languagesOf($this->mainLocale));
    }

    /** @return array{main: string, list: list<array{code: string, name: string}>} */
    public static function languagesOf(string $main): array
    {
        return ['main' => $main, 'list' => [['code' => $main, 'name' => self::NAMES[$main] ?? $main]]];
    }

    /** @return array<string, mixed> */
    private function user(): array
    {
        return $this->current->present() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
    }
}
