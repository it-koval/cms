<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Request\Max\MaxApiSender;
use Itk\Cms\Request\Max\MaxSender;
use Itk\Cms\Schema\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Sends a request to the MAX chats of its form, the way TelegramNotifier does to Telegram: the same text, its own chats and its own delivery state (Request::CHANNEL_MAX).
 * Chats: Settings → Уведомления (maxRoutes: the form's chat, else '*'), else the bundle option max.chat_ids.
 * Queued by Notification\Channel\MaxChannel; the outcome is announced with the event RequestDelivery (channel max): the bell tells about failures.
 *
 * MAX is optional. A site without the MAX bundle or its token, with MAX switched off, or without a chat keeps the MAX delivery «pending»: nothing is retried and nobody is told about a failure.
 * Only a message that a connected MAX refused is a failure.
 */
final class MaxNotifier implements ChannelNotifier
{
    public function __construct(
        private readonly Schema $schema,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
        private readonly MaxSender $sender,
        private readonly EventDispatcherInterface $events,
        #[Autowire(param: 'itk_cms.max.enabled')]
        private readonly bool $enabled,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /** Is there a MAX bot to send with (the bundle is installed and has a token)? */
    public function isConnected(): bool
    {
        return $this->sender->isConnected();
    }

    public function source(): ?string
    {
        return $this->sender instanceof MaxApiSender ? $this->sender->source() : null;
    }

    public function problem(bool $includeUnconfigured = true): ?string
    {
        return $this->sender instanceof MaxApiSender ? $this->sender->problem($includeUnconfigured) : null;
    }

    /** Is a delivery worth queueing: the bundle option and the setting «MAX включён» are on, and a bot is connected? Otherwise the MAX delivery just stays «pending». */
    public function isActive(): bool
    {
        return $this->enabled && $this->settings->isMaxOn() && $this->isConnected();
    }

    /** Can a request of this form be sent to MAX right now: MAX is active and has a chat for the form (its own or the fallback)? */
    public function reaches(string $form): bool
    {
        return [] !== $this->chatsFor($form);
    }

    /** @return list<string> */
    private function chatsFor(string $form): array
    {
        if (!$this->isActive()) {
            return [];
        }
        $chats = $this->settings->maxChatIdsFor($form);

        return [] !== $chats ? $chats : $this->settings->maxFallbackChatIds();
    }

    /** Delivers the request to MAX and sets its MAX delivery state. Returns true when it needs no retry. */
    public function notify(Request $r, bool $resend = false): bool
    {
        return $this->record($r, $this->attempt($r, $resend));
    }

    public function attempt(Request $r, bool $resend = false): ChannelOutcome
    {
        $chats = $this->chatsFor($r->form);
        if ([] === $chats) { // off, not connected, or no chat to send to (the health screen says which): not a failure
            return ChannelOutcome::pending();
        }

        $parts = MessageParts::max($this->buildText($r, $resend));
        $errors = [];
        foreach ($chats as $chat) {
            foreach ($parts->texts as $i => $text) {
                $parts->pauseBefore($i, $this->sleep);
                try {
                    $this->sender->send($chat, $text);
                } catch (\Throwable $e) {
                    $error = $parts->failure($i, $e->getMessage());
                    $errors[] = $chat . ': ' . $error;
                    $this->logger->error('MAX delivery failed for request #{id}, chat {chat}: {error}', ['id' => $r->id, 'chat' => $chat, 'error' => $error]);
                    break; // the rest would arrive out of order; a retry starts from the first
                }
            }
        }

        return [] !== $errors ? ChannelOutcome::failed(\implode("\n", $errors)) : ChannelOutcome::sent();
    }

    /** Writes the outcome of attempt() into the request and announces it. Returns true when it needs no retry. */
    public function record(Request $r, ChannelOutcome $outcome): bool
    {
        if ($outcome->isPending()) {
            $wasFailed = Request::DELIVERY_FAILED === $r->channelState(Request::CHANNEL_MAX);
            $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_PENDING);
            if ($wasFailed) {
                $this->events->dispatch(new RequestDelivery($r, false, null, true, Request::CHANNEL_MAX));
            }

            return true;
        }
        if ($outcome->isFailed()) {
            $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_FAILED, $outcome->error);
            $this->events->dispatch(new RequestDelivery($r, false, $outcome->error, false, Request::CHANNEL_MAX));

            return false;
        }
        $r->markChannel(Request::CHANNEL_MAX, Request::DELIVERY_SENT);
        $this->events->dispatch(new RequestDelivery($r, true, null, false, Request::CHANNEL_MAX));

        return true;
    }

    /**
     * Sends a plain text to one chat (the «Проверить» button of Settings → Уведомления).
     *
     * @throws ContentException with a plain reason (in the language current at the call) when the message could not be delivered
     */
    public function sendTo(string $chatId, string $text): void
    {
        if (!$this->isConnected()) {
            throw new ContentException(I18n::t('http.max_not_connected'), 502);
        }
        try {
            $this->sender->send($chatId, $text);
        } catch (\Throwable $e) {
            $this->logger->error('MAX test message failed for chat {chat}: {error}', ['chat' => $chatId, 'error' => $e->getMessage()]);
            throw new ContentException(I18n::t('http.max_not_delivered', ['reason' => $e->getMessage()]), 502);
        }
    }

    /** Text of the message (MAX HTML), in the current language (SendRequestNotificationHandler: admin_locale). */
    public function buildText(Request $r, bool $resend = false): string
    {
        return RequestText::build($this->schema, $r, $resend, self::escape(...));
    }

    /** MAX takes &, <, > and " as named entities; HtmlSanitizer::escape writes the apostrophe as a numeric entity, and MAX may show those as they are. */
    public static function escape(?string $value): string
    {
        return \strtr((string) $value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;']);
    }
}
