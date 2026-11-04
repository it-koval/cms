<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;

/**
 * The line «Telegram» of «Состояние сайта». Telegram is optional: a site without it is fine (the line is calm), a connected Telegram that has no chat for some form is a warning,
 * because the requests of that form are then saved but never sent.
 *
 * @internal
 */
final class TelegramHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly TelegramNotifier $telegram,
        private readonly SettingsService $settings,
        private readonly Schema $schema,
    ) {
    }

    public function name(): string
    {
        return 'Telegram';
    }

    public function check(): HealthResult
    {
        if (!$this->telegram->isConnected()) {
            return HealthResult::ok(I18n::t('ops.health_telegram_off'));
        }
        if (!$this->settings->isTelegramOn()) {
            return HealthResult::ok(I18n::t('ops.health_telegram_off_settings'));
        }
        if (!$this->telegram->isActive()) { // what is left is the bundle option itk_cms.telegram.enabled
            return HealthResult::ok(I18n::t('ops.health_telegram_off_config'));
        }
        $without = [];
        foreach ($this->schema->forms() as $key => $form) {
            if ([] === $this->settings->chatIdsFor((string) $key) && [] === $this->settings->fallbackChatIds()) {
                $without[] = I18n::t('ops.quoted', ['text' => $form->formTitle()]);
            }
        }
        if ([] !== $without) {
            return HealthResult::warn(I18n::t('ops.health_telegram_no_chat', ['forms' => \implode(', ', $without)]));
        }

        return HealthResult::ok(I18n::t('ops.health_telegram_ok'));
    }
}
