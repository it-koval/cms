<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\UserTwoFactorRepository;

/**
 * The second sign-in factor of one person (TOTP). The row appears when he starts the setup; `enabledAt` is set when he confirms with a first code, until then the
 * secret is only pending and does not protect the sign-in.
 */
#[ORM\Entity(repositoryClass: UserTwoFactorRepository::class)]
#[ORM\Table(name: 'itk_user_2fa')]
class UserTwoFactor
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    /** The Base32 secret, sealed (Security\TwoFactor\SecretBox). */
    #[ORM\Column(type: Types::STRING, length: 255)]
    public string $secret;

    #[ORM\Column(name: 'enabled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $enabledAt = null;

    /** @var list<string> keyed hashes of the recovery codes that are not used yet */
    #[ORM\Column(name: 'recovery_codes', type: Types::JSON)]
    public array $recoveryCodes = [];

    /** The last 30-second step a code was accepted for: a code is used once. */
    #[ORM\Column(name: 'last_step', type: Types::INTEGER, nullable: true)]
    public ?int $lastStep = null;

    #[ORM\Column(name: 'failed_attempts', type: Types::SMALLINT)]
    public int $failedAttempts = 0;

    #[ORM\Column(name: 'locked_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $lockedUntil = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $sealedSecret)
    {
        $this->user = $user;
        $this->secret = $sealedSecret;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isEnabled(): bool
    {
        return null !== $this->enabledAt;
    }
}
