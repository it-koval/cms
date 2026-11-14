<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * «Мой профиль → Сменить пароль»: a person changes his own password and has to know the current one.
 * Five tries in 15 minutes (the limiter itk_password_change, one bucket for each person): a stolen open session cannot be used to guess the password.
 * Other sessions of the person end by themselves: the password hash is part of what Symfony compares on every request.
 *
 * @internal
 */
final class PasswordChanger
{
    public function __construct(
        private readonly UserManager $manager,
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.itk_password_change')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * @throws ContentException 422 on a wrong current password or a weak new one, 429 when there were too many tries
     */
    public function change(User $user, string $current, string $new, ?string $repeat = null): void
    {
        $limit = $this->limiter->create($user->id);
        if (!$limit->consume()->isAccepted()) {
            throw new ContentException(I18n::t('http.password_locked'), 429, ['code' => 'password_locked']);
        }
        if ('' === $current || !$this->hasher->isPasswordValid($user, $current)) {
            throw ContentException::invalid(I18n::t('http.password_current_wrong'), ['current' => I18n::t('http.password_current_wrong')]);
        }
        UserManager::checkPasswordPolicy($new);
        if (null !== $repeat && $repeat !== $new) {
            throw ContentException::invalid(I18n::t('http.password_repeat_differs'), ['repeat' => I18n::t('http.password_repeat_differs')]);
        }
        if ($new === $current) {
            throw ContentException::invalid(I18n::t('http.password_same'), ['password' => I18n::t('http.password_same')]);
        }
        $this->manager->setPassword($user, $new);
        $limit->reset();
    }
}
