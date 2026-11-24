<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\PublicUrl;

/**
 * A configured origin is needed in HTTP, CLI and workers for account links.
 *
 * @internal
 */
final class TrustedPublicUrlHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly PublicUrl $urls)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.doctor_name_public_url');
    }

    public function check(): HealthResult
    {
        try {
            $base = $this->urls->trustedBase();

            return null === $base ? HealthResult::warn(I18n::t('ops.doctor_public_url_missing')) : HealthResult::ok($base);
        } catch (\UnexpectedValueException $error) {
            return HealthResult::fail($error->getMessage());
        }
    }
}
