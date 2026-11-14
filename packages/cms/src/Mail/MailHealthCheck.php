<?php

declare(strict_types=1);

namespace Itk\Cms\Mail;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\Health\HealthCheckInterface;
use Itk\Cms\Health\HealthResult;
use Itk\Cms\I18n\I18n;

/**
 * The line «Почта» of «Состояние сайта»: is sending set up, and did the last letter go out. Letters that failed for good are also in the list of failed tasks.
 *
 * @internal
 */
final class MailHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly MailerFactory $factory,
        private readonly MailStatus $status,
        private readonly SettingsService $settings,
    ) {
    }

    public function name(): string
    {
        return I18n::t('http.mail_name');
    }

    public function check(): HealthResult
    {
        $route = $this->factory->route();
        if (null === $route) {
            return $this->settings->hasMailRecipients()
                ? HealthResult::warn(I18n::t('http.mail_health_recipients_only'))
                : HealthResult::off(I18n::t('http.mail_health_off'));
        }
        $last = $this->status->get();
        $env = MailRoute::ENV === $route->source;
        if (null !== $last['error'] && (null === $last['okAt'] || ($last['errorAt'] ?? '') >= $last['okAt'])) {
            return HealthResult::warn(I18n::t($env ? 'http.mail_failed_env' : 'http.mail_failed_settings', ['error' => $last['error']]));
        }

        return HealthResult::ok(I18n::t($env ? 'http.mail_ready_env' : 'http.mail_ready_settings'));
    }
}
