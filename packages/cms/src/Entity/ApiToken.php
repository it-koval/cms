<?php

declare(strict_types=1);

namespace Itk\Cms\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Itk\Cms\Repository\ApiTokenRepository;
use Symfony\Component\Uid\Uuid;

/**
 * A key of an AI assistant (MCP): it acts as the person who made it, never with more rights than he has. Only the sha256 of the key is stored,
 * the key itself is shown once when it is made. `hint` is the last four signs of the key, so a person can tell his keys apart in the list.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'itk_api_token')]
#[ORM\Index(name: 'itk_api_token_user_idx', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'itk_api_token_hash', columns: ['token_hash'])]
class ApiToken
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(type: Types::STRING, length: 120)]
    public string $name;

    /** sha256 of the key, hex. */
    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 64)]
    public string $tokenHash;

    #[ORM\Column(type: Types::STRING, length: 8)]
    public string $hint = '';

    /** @var list<string> read | write | publish | requests (Itk\Cms\Mcp\McpScopes) */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    public array $scopes = [];

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    /** @param list<string> $scopes */
    public function __construct(User $user, string $name, string $tokenHash, string $hint, array $scopes, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->user = $user;
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->hint = $hint;
        $this->scopes = $scopes;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    public function isActive(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }

    /** 'active' | 'expired' | 'revoked' */
    public function state(\DateTimeImmutable $now = new \DateTimeImmutable()): string
    {
        return $this->isRevoked() ? 'revoked' : ($this->isExpired($now) ? 'expired' : 'active');
    }
}
