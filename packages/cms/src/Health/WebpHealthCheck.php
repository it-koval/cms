<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Media\ImageVariants;

/** Whether GD can generate WebP image variants. @internal */
final class WebpHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly ImageVariants $images)
    {
    }

    public function name(): string
    {
        return 'WebP';
    }

    public function check(): HealthResult
    {
        return $this->images->supportsWebp()
            ? HealthResult::ok(I18n::t('ops.doctor_webp_ok'))
            : HealthResult::fail(I18n::t('ops.doctor_webp_missing'));
    }
}
