<?php

declare(strict_types=1);

namespace Itk\Cms\Controller\Admin;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\I18n\AdminLocale;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\SiteMailer;
use Itk\Cms\Request\TelegramNotifier;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Access;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Site settings. Rights: area `set:<group>` of the schema; tgOn / tgRoutes / mailRoutes / forms belong to `set:notifications`. Locale: ?locale=ru.
 * robots.txt and llms.txt (robotsTxt / llmsTxt) are NOT here: they have their own calls (SeoFilesController), and these keys are neither returned nor taken.
 * The mail server (Настройки → Почта) is NOT here: it has its own calls with a password that never leaves (MailController).
 *   GET   settings         flat settings of the language, without the groups the person may not view and without the fields he may not see (canSee); the notification keys only for those who may see notifications (`tgFallback` = does the server have chats of its own, `mailReady` = can the site send mail: both a yes / no)
 *   PATCH settings { key: value, … }   saved at once; returns the merged settings. 403 when the patch touches a group the person may not edit; a field he may not see or that is readonly() is ignored
 *   POST  settings/telegram-test { chatId? | form? | chat? }   sends a test message → { ok, chat, sent }.
 *
 * @internal
 */
#[AsController]
#[Route('/api/settings', name: 'itk_api_settings_')]
final class SettingsController extends AbstractApiController
{
    private const NOTIFICATIONS = 'set:notifications';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Schema $schema,
        private readonly TelegramNotifier $telegram,
        private readonly Access $access,
        private readonly FieldAccess $fields,
        private readonly SiteMailer $mailer,
        private readonly AdminLocale $adminLocale,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        $all = $this->visible($this->without($this->settings->all($this->locale($request)), ...$this->crawlerKeys()));
        $this->telegramKeys($all);

        return $this->json($all);
    }

    #[Route('', name: 'update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $locale = $this->locale($request, $body);
        $patch = $this->without($body, 'locale', ...$this->crawlerKeys());
        $owner = [];
        foreach ($this->schema->settingsGroups() as $g) {
            foreach ($g->fieldList() as $f) {
                $owner[$f->key()] = 'set:' . $g->key();
            }
        }
        foreach (Schema::CORE_SETTING_KEYS as $k) {
            $owner[$k] = self::NOTIFICATIONS;
        }
        $areas = [];
        foreach (\array_keys($patch) as $key) {
            if (isset($owner[(string) $key])) {
                $areas[$owner[(string) $key]] = true;
            }
        }
        foreach (\array_keys($areas) as $area) {
            $this->access->require($area, 'edit');
        }
        $merged = $this->visible($this->settings->update($patch, $locale));
        $this->telegramKeys($merged);

        return $this->json($merged);
    }

    /**
     * The values of the groups the person may not view and of the fields he may not see are not handed out.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function visible(array $settings): array
    {
        foreach ($this->schema->settingsGroups() as $g) {
            $list = $g->fieldList();
            $shown = $this->access->can('set:' . $g->key(), 'view') ? $this->fields->visible($list) : [];
            foreach ($list as $f) {
                if (!\in_array($f, $shown, true)) {
                    unset($settings[$f->key()]);
                }
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function telegramKeys(array &$settings): void
    {
        if ($this->access->can(self::NOTIFICATIONS, 'view')) {
            $settings['tgFallback'] = $this->settings->hasFallbackChat();
            $settings['mailReady'] = $this->mailer->isConfigured();
        } else {
            unset($settings['tgOn'], $settings['tgRoutes'], $settings['mailRoutes']);
        }
    }

    #[Route('/telegram-test', name: 'telegram_test', methods: ['POST'])]
    public function telegramTest(Request $request): JsonResponse
    {
        $this->access->require(self::NOTIFICATIONS, 'edit');
        $body = $this->body($request);
        $label = (string) ($body['chat'] ?? $body['form'] ?? I18n::t('http.telegram_other_forms'));
        $chats = [];
        $direct = \trim((string) ($body['chatId'] ?? ''));
        if ('' !== $direct) {
            $chats = [$direct];
        } else {
            $routeKey = '*';
            foreach ($this->schema->forms() as $def) {
                if ($def->key() === $label || $def->formTitle() === $label) {
                    $routeKey = $def->key();
                }
            }
            $chats = $this->settings->chatIdsFor($routeKey);
        }
        if ([] === $chats) {
            throw ContentException::invalid(I18n::t('http.telegram_no_chat'));
        }
        $name = \trim((string) $this->settings->get('siteName', null, ''));
        // the chat is read by the people of the site, so the text goes in the language of the site (the errors below stay in the language of the person who pressed the button)
        $text = $this->adminLocale->runDefault(static fn (): string => '' !== $name ? I18n::t('http.telegram_test_site', ['site' => HtmlSanitizer::escape($name)]) : I18n::t('http.telegram_test'));
        foreach ($chats as $chat) {
            $error = SettingsService::chatIdError($chat);
            if ('' !== $error) {
                throw ContentException::invalid($error);
            }
            $this->telegram->sendTo($chat, $text);
        }

        return $this->json(['ok' => true, 'chat' => $label, 'sent' => \count($chats)]);
    }

    /** @return list<string> the keys of the texts of robots.txt and llms.txt (edited in «SEO»); a settings field of the site with such a key stays its own */
    private function crawlerKeys(): array
    {
        return \array_values(\array_filter(Schema::CRAWLER_SETTING_KEYS, fn (string $k): bool => null === $this->schema->settingsField($k)));
    }
}
