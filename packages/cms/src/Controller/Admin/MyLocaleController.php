<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * «Мой профиль → Язык интерфейса»: the signed-in person chooses the language of the admin for himself. Only his own; no special right is needed, like «Сменить пароль».
 *   PUT me/locale { locale: 'ru' | null }   { ok, locale, localePreference }   null = the language of the site (itk_cms.admin_locale); 422 for a language outside itk_cms.admin_locales
 * `locale` is the language he now gets, `localePreference` what he chose (the same pair GET me has). The language of the content is another matter: see AbstractApiController::locale().
 *
 * @internal
 */
#[AsController]
#[Route('/api/me/locale', name: 'itk_api_me_locale_')]
final class MyLocaleController extends AbstractApiController
{
    public function __construct(
        private readonly Access $access,
        private readonly AdminLocale $locale,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'set', methods: ['PUT'])]
    public function set(Request $request): JsonResponse
    {
        $user = $this->access->user();
        if (!$user instanceof User) {
            throw new AccessDeniedException(); // ApiErrorListener answers 401 with its own words
        }
        $body = $this->body($request);
        $chosen = $body['locale'] ?? null;
        if (!\array_key_exists('locale', $body) || (null !== $chosen && (!\is_string($chosen) || !$this->locale->isAvailable($chosen)))) {
            throw ContentException::invalid(I18n::t('i18n.locale_unknown', ['locales' => \implode(', ', $this->locale->available())]), ['locale' => I18n::t('i18n.locale_unknown_field')]);
        }
        $user->locale = $chosen;
        $this->em->flush();

        return $this->json(['ok' => true, 'locale' => $this->locale->forUser($user), 'localePreference' => $user->locale]);
    }
}
