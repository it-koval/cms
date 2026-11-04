<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\UserPref;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\UserPrefRepository;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Settings of the signed-in person that follow him between devices (filters and sorting of a list, the chosen view). Only his own.
 *   GET    prefs/{key}    { key, value }   (value is null when nothing was saved)
 *   PUT    prefs/{key}    { value }        → { key, value }
 *   DELETE prefs/{key}    { ok }
 * Keys are made of letters, digits and . _ : - (up to 100); a value is any JSON up to 16 KB; a person keeps up to 200 of them.
 *
 * @internal
 */
#[AsController]
#[Route('/api/prefs', name: 'itk_api_prefs_')]
final class PrefsController extends AbstractApiController
{
    private const KEY = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,99}$/';
    private const MAX_BYTES = 16384;
    private const MAX_PER_USER = 200;

    public function __construct(
        private readonly Access $access,
        private readonly UserPrefRepository $prefs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{key}', name: 'get', requirements: ['key' => '[^/]+'], methods: ['GET'])]
    public function get(string $key): JsonResponse
    {
        $this->check($key);

        return $this->json(['key' => $key, 'value' => $this->find($key)?->value]);
    }

    #[Route('/{key}', name: 'put', requirements: ['key' => '[^/]+'], methods: ['PUT'])]
    public function put(Request $request, string $key): JsonResponse
    {
        $this->check($key);
        $body = $this->body($request);
        if (!\array_key_exists('value', $body)) {
            throw ContentException::invalid(I18n::t('http.prefs_no_value'));
        }
        $value = $body['value'];
        if (\strlen((string) \json_encode($value)) > self::MAX_BYTES) {
            throw ContentException::invalid(I18n::t('http.prefs_too_big'));
        }
        $pref = $this->find($key);
        if (null === $pref) {
            if ($this->prefs->count(['user' => $this->user()]) >= self::MAX_PER_USER) {
                throw ContentException::invalid(I18n::t('http.prefs_too_many'));
            }
            $this->em->persist(new UserPref($this->user(), $key, $value));
        } else {
            $pref->value = $value;
            $pref->updatedAt = new \DateTimeImmutable();
        }
        $this->em->flush();

        return $this->json(['key' => $key, 'value' => $value]);
    }

    #[Route('/{key}', name: 'delete', requirements: ['key' => '[^/]+'], methods: ['DELETE'])]
    public function delete(string $key): JsonResponse
    {
        $this->check($key);
        if (null !== ($pref = $this->find($key))) {
            $this->em->remove($pref);
            $this->em->flush();
        }

        return $this->json(['ok' => true]);
    }

    private function check(string $key): void
    {
        $this->user();
        if (!\preg_match(self::KEY, $key)) {
            throw ContentException::invalid(I18n::t('http.prefs_bad_name'));
        }
    }

    private function find(string $key): ?UserPref
    {
        return $this->prefs->find(['user' => $this->user(), 'key' => $key]);
    }

    private function user(): \Itk\Cms\Entity\User
    {
        return $this->access->user() ?? throw new AccessDeniedException(I18n::t('http.sign_in_required'));
    }
}
