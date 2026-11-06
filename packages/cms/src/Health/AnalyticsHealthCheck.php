<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\Analytics\AnalyticsConfig;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\I18n;

/**
 * «Аналитика и вебмастера» in «Разработчику → Состояние сайта».
 *   nothing set                                  ok    "not connected"
 *   a value that does not parse                  warn  names each one (it is not put on the site)
 *   otherwise                                    ok    lists what is connected.
 *
 * @internal
 */
final class AnalyticsHealthCheck implements HealthCheckInterface
{
    private const LABELS = [
        AnalyticsConfig::METRIKA_ID => 'model.analytics_metrika_id',
        AnalyticsConfig::GA_ID => 'model.analytics_ga_id',
        AnalyticsConfig::YANDEX_VERIFICATION => 'model.analytics_yandex_verification',
        AnalyticsConfig::GOOGLE_VERIFICATION => 'model.analytics_google_verification',
    ];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.health_analytics');
    }

    public function check(): HealthResult
    {
        $config = AnalyticsConfig::fromSettings($this->settings->all());
        if ([] !== $config->problems) {
            return HealthResult::warn(I18n::t('ops.health_analytics_bad', ['fields' => \implode(', ', \array_map(static fn (string $key): string => I18n::t(self::LABELS[$key]), $config->problems))]));
        }
        if ($config->isEmpty()) {
            return HealthResult::ok(I18n::t('ops.health_analytics_none'));
        }
        $parts = [];
        if (null !== $config->metrikaId) {
            $parts[] = I18n::t('ops.health_analytics_metrika', ['id' => $config->metrikaId]);
        }
        if (null !== $config->googleAnalyticsId) {
            $parts[] = I18n::t('ops.health_analytics_ga', ['id' => $config->googleAnalyticsId]);
        }
        if (null !== $config->yandexVerification) {
            $parts[] = I18n::t('ops.health_analytics_yandex_verification');
        }
        if (null !== $config->googleVerification) {
            $parts[] = I18n::t('ops.health_analytics_google_verification');
        }

        return HealthResult::ok(I18n::t('ops.health_analytics_ok', ['list' => \implode(', ', $parts)]));
    }
}
