<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Can the CMS save files? A folder that is not writable breaks every upload in the media library.
 *
 * @internal
 */
final class UploadsHealthCheck implements HealthCheckInterface
{
    public function __construct(
        #[Autowire('%itk_cms.upload_dir%')]
        private readonly string $uploadDir,
    ) {
    }

    public function name(): string
    {
        return I18n::t('ops.health_uploads_name');
    }

    public function check(): HealthResult
    {
        if (!\is_dir($this->uploadDir)) {
            return HealthResult::warn(I18n::t('ops.health_uploads_missing'));
        }
        if (!\is_writable($this->uploadDir)) {
            return HealthResult::fail(I18n::t('ops.health_uploads_readonly'));
        }

        return HealthResult::ok(I18n::t('ops.health_uploads_ok'));
    }
}
