<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * What the CMS keeps to talk to Yandex Webmaster for the site: the sealed token (Security\TwoFactor\SecretBox), who and which host it is, and the code of the meta tag.
 * It changes rarely (a connect, the first sitemap, a new code), unlike the snapshot.
 *
 * @internal
 */
final class WebmasterConnection
{
    /** Yandex tokens of this flow live 6 months. */
    public const TOKEN_DAYS = 180;
    /** The owner is warned this many days before. */
    public const WARN_DAYS = 14;

    public function __construct(
        public readonly string $sealedToken,
        public readonly \DateTimeImmutable $connectedAt,
        public readonly int $userId,
        public readonly string $hostId,
        public readonly string $hostUrl,
        public readonly ?string $uin,
        public readonly bool $sitemapAdded,
    ) {
    }

    public function withUin(?string $uin): self
    {
        return new self($this->sealedToken, $this->connectedAt, $this->userId, $this->hostId, $this->hostUrl, $uin, $this->sitemapAdded);
    }

    public function withSitemapAdded(): self
    {
        return new self($this->sealedToken, $this->connectedAt, $this->userId, $this->hostId, $this->hostUrl, $this->uin, true);
    }

    public function tokenExpiresAt(): \DateTimeImmutable
    {
        return $this->connectedAt->modify('+' . self::TOKEN_DAYS . ' days');
    }

    public function tokenExpiresSoon(\DateTimeImmutable $now): bool
    {
        return $this->tokenExpiresAt() < $now->modify('+' . self::WARN_DAYS . ' days');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->sealedToken,
            'connectedAt' => $this->connectedAt->format(\DATE_ATOM),
            'userId' => $this->userId,
            'hostId' => $this->hostId,
            'hostUrl' => $this->hostUrl,
            'uin' => $this->uin,
            'sitemapAdded' => $this->sitemapAdded,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $token = $data['token'] ?? null;
        $at = $data['connectedAt'] ?? null;
        $hostId = $data['hostId'] ?? null;
        if (!\is_string($token) || '' === $token || !\is_string($at) || !\is_string($hostId) || '' === $hostId || !\is_int($data['userId'] ?? null)) {
            return null;
        }
        try {
            $connectedAt = new \DateTimeImmutable($at);
        } catch (\Exception) {
            return null;
        }

        return new self($token, $connectedAt, $data['userId'], $hostId, \is_string($data['hostUrl'] ?? null) ? $data['hostUrl'] : '', \is_string($data['uin'] ?? null) ? $data['uin'] : null, (bool) ($data['sitemapAdded'] ?? false));
    }
}
