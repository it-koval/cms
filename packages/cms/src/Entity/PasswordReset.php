<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\PasswordResetRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A link that lets a person set a password: «забыл пароль» (purpose reset), «пригласили» (invite). The link holds a random token; the database holds only its hash,
 * so a copy of the database cannot be used to take over an account. A token works once (`usedAt`) and until `expiresAt`.
 */
#[ORM\Entity(repositoryClass: PasswordResetRepository::class)]
#[ORM\Table(name: 'itk_password_reset')]
#[ORM\UniqueConstraint(name: 'itk_password_reset_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'itk_password_reset_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'itk_password_reset_expires_idx', columns: ['expires_at'])]
class PasswordReset
{
    public const RESET = 'reset';
    public const INVITE = 'invite';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    /** sha256 of the token, hex. */
    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
    public string $tokenHash;

    #[ORM\Column(type: Types::STRING, length: 16)]
    public string $purpose = self::RESET;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $tokenHash, string $purpose, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->purpose = $purpose;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isUsable(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return null === $this->usedAt && $this->expiresAt > $now;
    }
}
