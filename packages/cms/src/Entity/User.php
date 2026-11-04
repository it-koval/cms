<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\UserRepository;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/** A person who can sign in to the admin. */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'itk_user')]
#[ORM\UniqueConstraint(name: 'itk_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ACTIVE = 'active';
    public const INVITED = 'invited';
    public const BLOCKED = 'blocked';

    public const ROLE = 'ROLE_ITK_USER';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\Column(type: Types::STRING, length: 180)]
    public string $email;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $name = '';

    #[ORM\Column(name: 'password_hash', type: Types::STRING, length: 255)]
    public string $passwordHash = '';

    /** Key of the Role. */
    #[ORM\Column(name: 'role_key', type: Types::STRING, length: 64)]
    public string $role = Role::EDITOR;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $status = self::ACTIVE;

    /** The language of the interface he chose; null = the language of the site (itk_cms.admin_locale). */
    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    public ?string $locale = null;

    #[ORM\Column(name: 'last_sign_in_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $lastSignInAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $name, string $role = Role::EDITOR)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->email = \mb_strtolower(\trim($email));
        $this->name = $name;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [self::ROLE];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data['passwordHash'] = \hash('crc32c', $this->passwordHash);

        return $data;
    }
}
