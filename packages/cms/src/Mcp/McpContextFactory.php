<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Itk\Cms\Entity\ApiToken;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\Security\Access;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Makes the McpContext of a key; outside the firewall (console, tests) it also signs the owner of the key in for the rights checks.
 *
 * @internal
 */
final class McpContextFactory
{
    public function __construct(
        private readonly Access $access,
        private readonly TokenStorageInterface $storage,
        private readonly AdminLocale $locale,
    ) {
    }

    /** The owner of the key is already the signed-in person (the firewall did it). */
    public function forRequest(ApiToken $token): McpContext
    {
        return new McpContext($token, $this->access, 'http');
    }

    /** Signs the owner in (no firewall here, so AdminLocaleListener does not run: the language of the owner is set here too) and returns the context. */
    public function signIn(ApiToken $token, string $transport = 'stdio'): McpContext
    {
        $user = $token->user;
        $this->storage->setToken(new UsernamePasswordToken($user, 'itk_mcp', $user->getRoles()));
        $this->locale->apply($this->locale->forUser($user));

        return new McpContext($token, $this->access, $transport);
    }
}
