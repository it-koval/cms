<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\SchemaLint;

/**
 * Schema limits must be visible even when multilingual content is not configured.
 *
 * @internal
 */
final class SchemaHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly SchemaLint $lint)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.doctor_name_schema_warnings');
    }

    public function check(): HealthResult
    {
        $result = $this->lint->run();
        if ([] !== $result['errors']) {
            return HealthResult::fail(\implode("\n", $result['errors']));
        }

        return [] !== $result['warnings'] ? HealthResult::warn(\implode("\n", $result['warnings'])) : HealthResult::ok();
    }
}
