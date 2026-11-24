<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Exception;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\UserTwoFactorRepository;
use Itk\Cms\Security\TwoFactor\SecretBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The line «APP_SECRET» of «Состояние сайта»: an empty secret leaves the CSRF tokens of the admin, the keys of the two-factor codes and the hashes of the requests without a key.
 *
 * @internal
 */
final class AppSecretHealthCheck implements HealthCheckInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        private readonly string $secret,
        private readonly UserTwoFactorRepository $factors,
        private readonly SecretBox $box,
    ) {
    }

    /** The warning for an empty secret: the health line and itk:install say the same. */
    public static function emptyMessage(): string
    {
        return I18n::t('ops.health_secret_empty');
    }

    public function name(): string
    {
        return 'APP_SECRET';
    }

    public function check(): HealthResult
    {
        if ('' === \trim($this->secret)) {
            return HealthResult::warn(self::emptyMessage());
        }
        try {
            foreach ($this->factors->enabledSecrets() as $sealed) {
                if (null === $this->box->open($sealed)) {
                    return HealthResult::fail(I18n::t('ops.health_secret_2fa_unreadable'));
                }
            }
        } catch (Exception) {
            return HealthResult::warn(I18n::t('ops.health_secret_2fa_unavailable'));
        }

        return HealthResult::ok(I18n::t('ops.health_secret_ok'));
    }
}
