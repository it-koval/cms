<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\RoleRepository;

/**
 * A role. Built-in roles (admin, editor, author, requests) have no stored rights: they are worked out from the schema on every read
 * (Itk\Cms\Security\RoleResolver). Custom roles carry perms: { areaKey: [actions] } and templates (null = every template).
 */
#[ORM\Entity(repositoryClass: RoleRepository::class)]
#[ORM\Table(name: 'itk_role')]
class Role
{
    public const ADMIN = 'admin';
    public const EDITOR = 'editor';
    public const AUTHOR = 'author';
    public const REQUESTS = 'requests';

    #[ORM\Id]
    #[ORM\Column(name: 'role_key', type: Types::STRING, length: 64)]
    public string $key;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $name;

    #[ORM\Column(type: Types::STRING, length: 500)]
    public string $description = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    public bool $builtin = false;

    /** @var array<string, list<string>> */
    #[ORM\Column(type: Types::JSON)]
    public array $perms = [];

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $templates = null;

    /** People with this role must sign in with a second factor (a one-time code); one who has none yet sets it up at the next sign-in. */
    #[ORM\Column(name: 'require_2fa', type: Types::BOOLEAN, options: ['default' => false])]
    public bool $require2fa = false;

    public function __construct(string $key, string $name)
    {
        $this->key = $key;
        $this->name = $name;
    }
}
