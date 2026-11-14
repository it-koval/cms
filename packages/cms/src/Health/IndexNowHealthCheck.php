<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Itk\Cms\IndexNow\IndexNowSite;
use Itk\Cms\IndexNow\IndexNowState;

/**
 * «IndexNow» in «Разработчику → Состояние сайта».
 *   toggle off                       ok    "off"
 *   no site address / no http-client warn
 *   address not public               ok    nothing is sent
 *   on                               ok    the key file and the last submission; warn when the last answer was not 200 or 202.
 *
 * @internal
 */
final class IndexNowHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly IndexNowSite $site)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.health_indexnow');
    }

    public function check(): HealthResult
    {
        switch ($this->site->state()) {
            case IndexNowState::Off:
                return HealthResult::ok(I18n::t('ops.health_indexnow_off'));
            case IndexNowState::NoAddress:
                return HealthResult::warn(I18n::t('ops.health_indexnow_no_address'));
            case IndexNowState::NotPublic:
                return HealthResult::ok(I18n::t('ops.health_indexnow_not_public'));
            case IndexNowState::NoHttpClient:
                return HealthResult::warn(I18n::t('ops.health_indexnow_no_client'));
        }
        $file = '/' . $this->site->key() . '.txt';
        $last = $this->site->lastSubmission();
        if (null === $last) {
            return HealthResult::ok(I18n::t('ops.health_indexnow_ok_none', ['file' => $file]));
        }
        $params = ['file' => $file, 'count' => $last['count'], 'time' => (new \DateTimeImmutable($last['at']))->format('d.m.Y H:i'), 'code' => $last['status'] > 0 ? (string) $last['status'] : (string) $last['error']];
        if (200 === $last['status'] || 202 === $last['status']) {
            return HealthResult::ok(I18n::t('ops.health_indexnow_ok', $params));
        }

        return HealthResult::warn(I18n::t('ops.health_indexnow_failed', $params));
    }
}
