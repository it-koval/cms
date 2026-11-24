<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\DBAL\Connection;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Sends a request to the Telegram chats of its form with the saved admin bot token, or the site's Notifier chatter as a fallback
 * (framework.notifier.chatter_transports, e.g. telegram: '%env(TELEGRAM_DSN)%').
 * Chats: Settings → Уведомления (tgRoutes: the form's chat, else '*'), else the bundle option telegram.chat_ids.
 * Queued by Notification\Channel\TelegramChannel; the outcome of each delivery is announced with the event RequestDelivery (the bell tells about failures).
 *
 * Telegram is optional. A site without a transport, with a TELEGRAM_DSN nobody set, with null://null, with a DSN that cannot be built, or without a chat keeps its requests
 * in the admin: the delivery stays «pending» (the same neutral state as a switched-off Telegram), nothing is retried and nobody is told about a failure.
 * Only a message that a connected Telegram refused is a failure.
 */
final class TelegramNotifier implements ChannelNotifier
{
    private readonly TelegramConnection $connection;

    /** @param TelegramConnection|(\Closure(): ?TransportInterface)|null $chatter */
    public function __construct(
        private readonly Schema $schema,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
        TelegramConnection|\Closure|null $chatter,
        private readonly EventDispatcherInterface $events,
        #[Autowire(param: 'itk_cms.telegram.enabled')]
        private readonly bool $enabled,
        private readonly ?\Closure $sleep = null,
    ) {
        $this->connection = $chatter instanceof TelegramConnection ? $chatter : new TelegramConnection($chatter, $logger);
    }

    /** Whether Telegram has a usable transport. The token saved in the admin takes priority over the site's chatter. */
    public function isConnected(): bool
    {
        return null !== $this->connection->transport();
    }

    public function source(): ?string
    {
        return $this->connection->source();
    }

    public function problem(bool $includeUnconfigured = true): ?string
    {
        return $this->connection->problem($includeUnconfigured);
    }

    /** Is a delivery worth queueing: the bundle option and the setting «Telegram включён» are on, and a Telegram is connected? Otherwise the request just stays «pending». */
    public function isActive(): bool
    {
        return $this->enabled && $this->settings->isTelegramOn() && $this->isConnected();
    }

    /** Can a request of this form be sent to Telegram right now: Telegram is active and has a chat for the form (its own or the fallback)? */
    public function reaches(string $form): bool
    {
        return $this->isActive() && [] !== $this->chatsFor($form);
    }

    /** @return list<string> */
    private function chatsFor(string $form): array
    {
        $chats = $this->settings->chatIdsFor($form);

        return [] !== $chats ? $chats : $this->settings->fallbackChatIds();
    }

    /** Delivers the request and sets its delivery state. Returns true when it needs no retry. */
    public function notify(Request $r, bool $resend = false): bool
    {
        return $this->record($r, $this->attempt($r, $resend));
    }

    public function attempt(Request $r, bool $resend = false): ChannelOutcome
    {
        $chatter = $this->enabled && $this->settings->isTelegramOn() ? $this->connection->transport() : null;
        $chats = null === $chatter ? [] : $this->chatsFor($r->form);
        if (null === $chatter || [] === $chats) { // off, not connected, or no chat to send to (the health screen says which): not a failure
            return ChannelOutcome::pending();
        }

        $parts = MessageParts::telegram($this->buildText($r, $resend));
        $errors = [];
        foreach ($chats as $chat) {
            foreach ($parts->texts as $i => $text) {
                $parts->pauseBefore($i, $this->sleep);
                try {
                    $chatter->send((new ChatMessage($text))->options(
                        (new TelegramOptions())->chatId($chat)->parseMode(TelegramOptions::PARSE_MODE_HTML)->disableWebPagePreview(true),
                    ));
                } catch (\Throwable $e) {
                    $error = $parts->failure($i, $this->connection->scrubError($e->getMessage()));
                    $errors[] = $chat . ': ' . $error;
                    $this->logger->error('Telegram delivery failed for request #{id}, chat {chat}: {error}', ['id' => $r->id, 'chat' => $chat, 'error' => $error]);
                    break; // the rest would arrive out of order; a retry starts from the first
                }
            }
        }

        return [] !== $errors ? ChannelOutcome::failed(\implode("\n", $errors)) : ChannelOutcome::sent();
    }

    /** Writes the outcome of attempt() into the request and announces it. Returns true when it needs no retry. */
    /** @param (\Closure(\Closure(): void): void)|null $defer */
    public function record(Request $r, ChannelOutcome $outcome, ?\Closure $defer = null, ?Connection $deliveryConnection = null): bool
    {
        return $outcome->record($r, Request::CHANNEL_TELEGRAM, $this->events, $defer, $deliveryConnection);
    }

    /**
     * Sends a plain text to one chat (the «Проверить» button of Settings → Уведомления).
     *
     * @throws ContentException with a plain reason (in the language current at the call) when the message could not be delivered
     */
    public function sendTo(string $chatId, string $text): void
    {
        $chatter = $this->connection->transport();
        if (null === $chatter) {
            throw new ContentException(I18n::t('http.telegram_not_connected'), 502);
        }
        try {
            $chatter->send((new ChatMessage($text))->options(
                (new TelegramOptions())->chatId($chatId)->parseMode(TelegramOptions::PARSE_MODE_HTML)->disableWebPagePreview(true),
            ));
        } catch (\Throwable $e) {
            $error = $this->connection->scrubError($e->getMessage());
            $this->logger->error('Telegram test message failed for chat {chat}: {error}', ['chat' => $chatId, 'error' => $error]);
            throw new ContentException(I18n::t('http.telegram_not_delivered', ['reason' => $error]), 502);
        }
    }

    /** Text of the message (Telegram HTML), in the current language (SendRequestNotificationHandler: admin_locale). */
    public function buildText(Request $r, bool $resend = false): string
    {
        return RequestText::build($this->schema, $r, $resend, HtmlSanitizer::escape(...));
    }
}
