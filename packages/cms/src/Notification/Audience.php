<?php

declare(strict_types=1);

namespace Itk\Cms\Notification;

use Itk\Cms\Security\Viewer;

/**
 * Who a notification is for: null = everybody who is signed in; 'role:<key>' = people of one role; anything else is a right (Viewer::allows: 'requests', 'col:tours:edit', 'set:users').
 *
 * @internal
 */
final class Audience
{
    public static function matches(?string $audience, Viewer $viewer): bool
    {
        if (null === $audience || '' === $audience) {
            return !$viewer->isSystem();
        }
        if (\str_starts_with($audience, 'role:')) {
            return null !== $viewer->role() && \substr($audience, 5) === $viewer->role();
        }

        return !$viewer->isSystem() && $viewer->allows($audience);
    }
}
