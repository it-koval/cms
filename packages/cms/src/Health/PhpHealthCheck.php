<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;

/**
 * Checks the PHP runtime serving this request, including sites installed before the October 2026 security release.
 *
 * @internal
 */
final class PhpHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly string $version = \PHP_VERSION)
    {
    }

    public function name(): string
    {
        return 'PHP';
    }

    public function check(): HealthResult
    {
        $minimum = match (true) {
            \version_compare($this->version, '8.4.0-dev', '<') => '8.3.36',
            \version_compare($this->version, '8.5.0-dev', '<') => '8.4.27',
            \version_compare($this->version, '8.6.0-dev', '<') => '8.5.12',
            default => '8.6.0',
        };

        return \version_compare($this->version, $minimum, '>=')
            ? HealthResult::ok(I18n::t('ops.health_php_ok', ['version' => $this->version]))
            : HealthResult::warn(I18n::t('ops.health_php_old', ['version' => $this->version, 'minimum' => $minimum]));
    }
}
