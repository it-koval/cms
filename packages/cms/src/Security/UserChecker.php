<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** A blocked person cannot sign in (set `user_checker: Itk\Cms\Security\UserChecker` on the firewall). */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && User::BLOCKED === $user->status) {
            throw new CustomUserMessageAccountStatusException(I18n::t('http.account_closed'));
        }
    }

    /** The token is the second argument of Symfony 8's interface (7.4 passes it too); optional here so that the class fits both. */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
