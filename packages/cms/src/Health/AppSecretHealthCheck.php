<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
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
        return '' === \trim($this->secret) ? HealthResult::warn(self::emptyMessage()) : HealthResult::ok(I18n::t('ops.health_secret_ok'));
    }
}
