<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Release the admin session lock during slow I/O, then check that another tab did not sign out. @internal */
final class SessionWork
{
    public function __construct(private readonly TokenStorageInterface $tokens)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    public function run(Request $request, callable $call): mixed
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $key = $request->attributes->get('_security_firewall_run');
        if (null === $session || !$session->isStarted() || !\is_string($key)) {
            return $call();
        }
        $id = $session->getId();
        $session->save();
        try {
            return $call();
        } finally {
            $session->start();
            if ($session->getId() !== $id || !$session->has($key)) {
                $this->tokens->setToken(null);
                throw new AccessDeniedException();
            }
        }
    }
}
