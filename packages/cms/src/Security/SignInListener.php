<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Entity\User;
use Itk\Cms\Mcp\ApiTokenAuthenticator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Remembers the last sign-in; an invited person who signs in becomes active.
 *
 * @internal
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class SignInListener
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if ($event->getAuthenticator() instanceof ApiTokenAuthenticator) {
            return; // a key of an AI assistant is not a sign-in of the person
        }
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->lastSignInAt = new \DateTimeImmutable();
        if (User::INVITED === $user->status) {
            $user->status = User::ACTIVE;
        }
        $this->em->flush();
    }
}
