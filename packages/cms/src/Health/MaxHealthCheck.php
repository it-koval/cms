<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\Bots\BotSettings;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\MaxNotifier;
use Itk\Cms\Schema\Schema;

/**
 * The line «MAX» of «Состояние сайта». MAX is optional: a site without it is fine (the line is calm), a connected MAX that has no chat for some form is a warning,
 * because the requests of that form are then saved but never sent.
 *
 * @internal
 */
final class MaxHealthCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly MaxNotifier $max,
        private readonly SettingsService $settings,
        private readonly Schema $schema,
        private readonly ?BotSettings $bots = null,
    ) {
    }

    public function name(): string
    {
        return 'MAX';
    }

    public function check(): HealthResult
    {
        if ($this->bots?->unreadable('max')) {
            return HealthResult::warn($this->withSource(I18n::t('http.bot_token_unreadable', ['channel' => 'MAX'])));
        }
        if (null !== $problem = $this->max->problem(false)) {
            return HealthResult::warn($this->withSource($problem));
        }
        if (!$this->max->isConnected()) {
            return HealthResult::off(I18n::t('ops.health_max_off'));
        }
        if (!$this->settings->isMaxOn()) {
            return HealthResult::off($this->withSource(I18n::t('ops.health_max_off_settings')));
        }
        if (!$this->max->isActive()) { // what is left is the bundle option itk_cms.max.enabled
            return HealthResult::off($this->withSource(I18n::t('ops.health_max_off_config')));
        }
        $without = [];
        foreach ($this->schema->forms() as $key => $form) {
            if ([] === $this->settings->maxChatIdsFor((string) $key) && [] === $this->settings->maxFallbackChatIds()) {
                $without[] = I18n::t('ops.quoted', ['text' => $form->formTitle()]);
            }
        }
        if ([] !== $without) {
            return HealthResult::warn($this->withSource(I18n::t('ops.health_max_no_chat', ['forms' => \implode(', ', $without)])));
        }

        return HealthResult::ok(I18n::t(match ($this->max->source()) {
            'admin' => 'ops.health_max_admin',
            'server' => 'ops.health_max_server',
            default => 'ops.health_max_ok',
        }));
    }

    private function withSource(string $message): string
    {
        $source = $this->max->source();

        return null === $source || !$this->max->isConnected() ? $message : $message . '. ' . I18n::t('admin' === $source ? 'ops.health_max_admin' : 'ops.health_max_server');
    }
}
