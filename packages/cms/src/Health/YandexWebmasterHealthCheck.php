<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\I18n\I18n;
use Itk\Cms\YandexWebmaster\Model\WebmasterState;
use Itk\Cms\YandexWebmaster\WebmasterService;

/**
 * «Яндекс Вебмастер» in «Разработчику → Состояние сайта». It reads what the CMS stored last time and never calls Yandex (the screen must open fast); the refresh is made by «Сегодня».
 *   not connected                        ok    "not connected" (also when the site has no public address or no http-client: nothing is lost)
 *   connected, but no address/client     warn
 *   token refused by Yandex              warn  the owner pastes a new one
 *   token ends in less than 14 days      warn  with the date
 *   ownership check running              ok
 *   ownership check failed               warn  with the reason Yandex gives
 *   verified                             ok    with the ИКС and the pages in search when Yandex has given them.
 *
 * @internal
 */
final class YandexWebmasterHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly WebmasterService $webmaster)
    {
    }

    public function name(): string
    {
        return I18n::t('ops.health_webmaster');
    }

    public function check(): HealthResult
    {
        $status = $this->webmaster->view();
        if (null === $status->connectedAt) {
            return HealthResult::off(I18n::t('ops.health_webmaster_none'));
        }
        if (WebmasterState::NoAddress === $status->state) {
            return HealthResult::warn(I18n::t('ops.health_webmaster_no_address'));
        }
        if (WebmasterState::NoHttpClient === $status->state) {
            return HealthResult::warn(I18n::t('ops.health_webmaster_no_client'));
        }
        if (WebmasterState::TokenInvalid === $status->state) {
            return HealthResult::warn(I18n::t('ops.health_webmaster_token_invalid'));
        }
        if ($status->tokenExpiresSoon && null !== $status->tokenExpiresAt) {
            return HealthResult::warn(I18n::t('ops.health_webmaster_token_soon', ['date' => $status->tokenExpiresAt->format('d.m.Y')]));
        }

        return match ($status->state) {
            WebmasterState::VerificationFailed => HealthResult::warn(I18n::t('ops.health_webmaster_failed', ['reason' => $status->verification->failReason ?? $status->verification->state ?? ''])),
            WebmasterState::Ready => HealthResult::ok($this->ready($status->summary)),
            default => HealthResult::ok(I18n::t('ops.health_webmaster_verifying')),
        };
    }

    /** @param ?array<string, mixed> $summary */
    private function ready(?array $summary): string
    {
        $parts = [];
        if (\is_int($summary['sqi'] ?? null)) {
            $parts[] = I18n::t('ops.health_webmaster_sqi', ['sqi' => $summary['sqi']]);
        }
        if (\is_int($summary['searchable'] ?? null)) {
            $parts[] = I18n::t('ops.health_webmaster_pages', ['pages' => $summary['searchable']]);
        }

        return [] === $parts ? I18n::t('ops.health_webmaster_ready') : I18n::t('ops.health_webmaster_ready_with', ['list' => \implode(', ', $parts)]);
    }
}
