<?php

declare(strict_types=1);

namespace Itk\Cms\Security;

/**
 * What a request wants to do: area ('pages', 'col:<key>', 'requests', 'media', 'set:<group>', 'templates'), action (view|edit|publish|delete)
 * and, for pages, the template of the page (a role may be limited to some templates).
 *
 *   $this->isGranted(PermissionVoter::ATTRIBUTE, new Permission('pages', 'publish', template: 'blog_article'))
 */
final class Permission
{
    public function __construct(
        public readonly string $area,
        public readonly string $action = 'view',
        public readonly ?string $template = null,
    ) {
    }
}
