<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * The ownership check of a host as Yandex reports it.
 *
 * @internal
 */
final class WebmasterVerification
{
    public const VERIFIED = 'VERIFIED';
    public const NONE = 'NONE';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const FAILED = 'VERIFICATION_FAILED';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * @param string $state one of the constants above
     * @param ?string $uin the code of the meta tag `yandex-verification`
     */
    public function __construct(
        public readonly string $state,
        public readonly ?string $uin,
        public readonly ?string $failReason,
    ) {
    }

    public function isVerified(): bool
    {
        return self::VERIFIED === $this->state;
    }

    public function isFailed(): bool
    {
        return \in_array($this->state, [self::FAILED, self::INTERNAL_ERROR], true);
    }
}
