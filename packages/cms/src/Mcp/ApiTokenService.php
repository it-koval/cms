<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp;

use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\ApiToken;
use Itk\Cms\Entity\User;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\ApiTokenRepository;
use Itk\Cms\Security\RoleResolver;
use Itk\Cms\Security\TwoFactor\TwoFactorService;

/**
 * Keys of AI assistants. A key is `itk_` + 43 signs of base64url (32 random bytes); only its sha256 is stored. The key works as long as it is not
 * revoked and not expired and its owner is not blocked. Blocking the owner stops every key at once (the keys stay in the list and work again if he is
 * unblocked); a new password of the owner does NOT stop them: revoke the keys for that. A key never has more rights than its owner has at the moment of the call.
 */
final class ApiTokenService
{
    public const PREFIX = 'itk_';
    public const MAX_ACTIVE_PER_USER = 20;
    public const MAX_DAYS = 730;
    /** last_used_at is written at most this often (a busy assistant would otherwise write on every call). */
    private const TOUCH_SECONDS = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $tokens,
        private readonly RoleResolver $roles,
        private readonly TwoFactorService $factor,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @param int|null $days how many days the key lives; null = no end
     *
     * @return array{token: ApiToken, plain: string} the plain key is returned once and never stored
     */
    public function create(User $user, string $name, array $scopes, ?int $days): array
    {
        $name = \trim(\preg_replace('/\s+/u', ' ', \strip_tags($name)) ?? '');
        if ('' === $name || \mb_strlen($name) > 120) {
            throw ContentException::invalid(I18n::t('ops.token_name_invalid'), ['name' => I18n::t('ops.token_name_field')]);
        }
        if (User::BLOCKED === $user->status) {
            throw ContentException::forbidden(I18n::t('ops.token_blocked'));
        }
        $scopes = McpScopes::normalize($scopes);
        if ([] === $scopes) {
            throw ContentException::invalid(I18n::t('ops.token_scopes_empty'), ['scopes' => I18n::t('ops.token_scopes_empty_field')]);
        }
        $conflict = McpScopes::conflict($scopes);
        if (null !== $conflict) {
            throw ContentException::invalid($conflict, ['scopes' => I18n::t('ops.token_scopes_conflict_field')]);
        }
        $offered = McpScopes::offeredTo($this->roles->rightsOf($user));
        $extra = \array_values(\array_diff($scopes, $offered));
        if ([] !== $extra) {
            throw ContentException::invalid(I18n::t('ops.token_scopes_exceed', ['extra' => \implode(', ', $extra)]), ['scopes' => I18n::t('ops.token_scopes_exceed_field')]);
        }
        if (null !== $days && ($days < 1 || $days > self::MAX_DAYS)) {
            throw ContentException::invalid(I18n::t('ops.token_expires_invalid', ['max' => self::MAX_DAYS]), ['expires' => I18n::t('ops.token_expires_field')]);
        }
        $now = new \DateTimeImmutable();
        if ($this->tokens->countActive($user->id, $now) >= self::MAX_ACTIVE_PER_USER) {
            throw ContentException::conflict(I18n::t('ops.token_limit', ['max' => self::MAX_ACTIVE_PER_USER]));
        }
        $plain = self::PREFIX . \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
        $token = new ApiToken($user, $name, self::hash($plain), \substr($plain, -4), $scopes, null === $days ? null : $now->modify('+' . $days . ' days'));
        $this->em->persist($token);
        $this->em->flush();

        return ['token' => $token, 'plain' => $plain];
    }

    public static function hash(string $plain): string
    {
        return \hash('sha256', $plain);
    }

    /** @throws ApiTokenException when the key cannot be used now */
    public function authenticate(string $plain): ApiToken
    {
        if (!\preg_match('/^itk_[A-Za-z0-9_-]{40,128}$/', $plain)) {
            throw new ApiTokenException('invalid', 'The key is not valid');
        }
        $token = $this->tokens->findByHash(self::hash($plain)) ?? throw new ApiTokenException('invalid', 'The key is not valid');
        if ($token->isRevoked()) {
            throw new ApiTokenException('revoked', 'The key was revoked', 401, $token);
        }
        if ($token->isExpired()) {
            throw new ApiTokenException('expired', 'The key has expired', 401, $token);
        }
        $user = $token->user;
        if (User::BLOCKED === $user->status) {
            throw new ApiTokenException('blocked', 'Access is closed for the owner of this key', 403, $token);
        }
        if ($this->factor->isRequired($user) && !$this->factor->isEnabled($user)) {
            throw new ApiTokenException('two_factor', 'The role of the owner requires two-step sign-in: set it up in the admin first', 403, $token);
        }

        return $token;
    }

    public function touch(ApiToken $token): void
    {
        $now = new \DateTimeImmutable();
        if (null === $token->lastUsedAt || $now->getTimestamp() - $token->lastUsedAt->getTimestamp() >= self::TOUCH_SECONDS) {
            $token->lastUsedAt = $now;
            $this->em->flush();
        }
    }

    public function find(string $id): ApiToken
    {
        return $this->tokens->find($id) ?? throw ContentException::notFound(I18n::t('ops.token_not_found'));
    }

    public function revoke(ApiToken $token): ApiToken
    {
        if (!$token->isRevoked()) {
            $token->revokedAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return $token;
    }

    /** @return list<ApiToken> */
    public function ofUser(User $user): array
    {
        return $this->tokens->ofUser($user->id);
    }

    /** @return list<ApiToken> */
    public function everyone(): array
    {
        return $this->tokens->everyone();
    }

    /**
     * A key as the admin lists it (never the key itself).
     *
     * @return array<string, mixed>
     */
    public function present(ApiToken $t, bool $withOwner = false): array
    {
        $utc = static fn (?\DateTimeImmutable $d): ?string => $d?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $out = [
            'id' => $t->id, 'name' => $t->name, 'hint' => $t->hint, 'scopes' => $t->scopes, 'state' => $t->state(),
            'createdAt' => $utc($t->createdAt), 'lastUsedAt' => $utc($t->lastUsedAt), 'expiresAt' => $utc($t->expiresAt), 'revokedAt' => $utc($t->revokedAt),
        ];
        if ($withOwner) {
            $out['owner'] = ['id' => $t->user->id, 'name' => $t->user->name, 'email' => $t->user->email, 'blocked' => User::BLOCKED === $t->user->status];
        }

        return $out;
    }
}
