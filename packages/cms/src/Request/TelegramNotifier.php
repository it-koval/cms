<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\HtmlSanitizer;
use Itk\Cms\Content\SettingsService;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Schema\Schema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Sends a request to the Telegram chats of its form through the site's Notifier chatter (framework.notifier.chatter_transports,
 * e.g. telegram: '%env(TELEGRAM_DSN)%' — the bot token lives in .env.local only).
 * Chats: Settings → Уведомления (tgRoutes: the form's chat, else '*'), else the bundle option telegram.chat_ids.
 * Queued by Notification\Channel\TelegramChannel; the outcome of each delivery is announced with the event RequestDelivery (the bell tells about failures).
 *
 * Telegram is optional. A site without a transport, with a TELEGRAM_DSN nobody set, with null://null, with a DSN that cannot be built, or without a chat keeps its requests
 * in the admin: the delivery stays «pending» (the same neutral state as a switched-off Telegram), nothing is retried and nobody is told about a failure.
 * Only a message that a connected Telegram refused is a failure.
 */
final class TelegramNotifier
{
    private bool $resolved = false;
    private ?TransportInterface $transport = null;

    /**
     * @param \Closure(): ?TransportInterface|null $chatter builds the site's chatter on first use (a closure, because building it reads TELEGRAM_DSN: a variable nobody set must not break
     *                                                      every page that only needs this class to be created); null when the site has no Notifier
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $chatter,
        private readonly EventDispatcherInterface $events,
        #[Autowire(param: 'itk_cms.telegram.enabled')]
        private readonly bool $enabled,
    ) {
    }

    /** Is there a Telegram to send to: the Notifier is installed, a chatter transport is configured and can be built? */
    public function isConnected(): bool
    {
        return null !== $this->chatter();
    }

    /** Is a delivery worth queueing: the bundle option and the setting «Telegram включён» are on, and a Telegram is connected? Otherwise the request just stays «pending». */
    public function isActive(): bool
    {
        return $this->enabled && $this->settings->isTelegramOn() && $this->isConnected();
    }

    /** Delivers the request and sets its delivery state. Returns true when it needs no retry. */
    public function notify(Request $r, bool $resend = false): bool
    {
        $chatter = $this->enabled && $this->settings->isTelegramOn() ? $this->chatter() : null;
        $chats = null === $chatter ? [] : $this->settings->chatIdsFor($r->form);
        if (null !== $chatter && [] === $chats) {
            $chats = $this->settings->fallbackChatIds();
        }
        if (null === $chatter || [] === $chats) { // off, not connected, or no chat to send to (the health screen says which): not a failure
            $wasFailed = Request::DELIVERY_FAILED === $r->delivery;
            $r->delivery = Request::DELIVERY_PENDING;
            $r->deliveryError = null;
            if ($wasFailed) {
                $this->events->dispatch(new RequestDelivery($r, false, null, true));
            }

            return true;
        }

        $text = $this->buildText($r, $resend);
        $errors = [];
        foreach ($chats as $chat) {
            try {
                $chatter->send((new ChatMessage($text))->options(
                    (new TelegramOptions())->chatId($chat)->parseMode(TelegramOptions::PARSE_MODE_HTML)->disableWebPagePreview(true),
                ));
            } catch (\Throwable $e) {
                $errors[] = $chat . ': ' . self::scrub($e->getMessage());
                $this->logger->error('Telegram delivery failed for request #{id}, chat {chat}: {error}', ['id' => $r->id, 'chat' => $chat, 'error' => self::scrub($e->getMessage())]);
            }
        }
        if ([] !== $errors) {
            return $this->fail($r, \implode("\n", $errors));
        }
        $r->delivery = Request::DELIVERY_SENT;
        $r->deliveryError = null;
        $this->events->dispatch(new RequestDelivery($r, true));

        return true;
    }

    /**
     * Sends a plain text to one chat (the «Проверить» button of Settings → Уведомления).
     *
     * @throws ContentException with a plain reason (in the language current at the call) when the message could not be delivered
     */
    public function sendTo(string $chatId, string $text): void
    {
        $chatter = $this->chatter();
        if (null === $chatter) {
            throw new ContentException(I18n::t('http.telegram_not_connected'), 502);
        }
        try {
            $chatter->send((new ChatMessage($text))->options(
                (new TelegramOptions())->chatId($chatId)->parseMode(TelegramOptions::PARSE_MODE_HTML)->disableWebPagePreview(true),
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Telegram test message failed for chat {chat}: {error}', ['chat' => $chatId, 'error' => self::scrub($e->getMessage())]);
            throw new ContentException(I18n::t('http.telegram_not_delivered', ['reason' => self::scrub($e->getMessage())]), 502);
        }
    }

    /** Error texts of the HTTP client carry the request address, and the address carries the bot token: never show or log it. The same goes for a DSN quoted by a configuration error. */
    private static function scrub(string $message): string
    {
        $message = (string) \preg_replace('~bot\d+:[A-Za-z0-9_-]+~', 'bot***', $message);

        return (string) \preg_replace('~([a-z][a-z0-9+.-]*://)[^\s"\'@/]*@~i', '$1***@', $message);
    }

    /**
     * The chatter of the site, built once; null when Telegram cannot be used: no Notifier or Telegram bridge installed, no transport configured, the DSN is null://null,
     * the variable of the DSN (TELEGRAM_DSN) not set, or a DSN that cannot be built. The reason is logged when it is a mistake of the configuration (a missing variable is not one: Telegram is optional).
     */
    private function chatter(): ?TransportInterface
    {
        if ($this->resolved) {
            return $this->transport;
        }
        $this->resolved = true;
        if (null === $this->chatter || !\class_exists(ChatMessage::class) || !\class_exists(TelegramOptions::class)) {
            return null;
        }
        try {
            $chatter = ($this->chatter)();
            $this->transport = $chatter instanceof TransportInterface && self::canDeliver($chatter) ? $chatter : null;
        } catch (EnvNotFoundException) {
            // TELEGRAM_DSN is not set: Telegram is not used on this site
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram is not used, its transport cannot be built: {error}', ['error' => self::scrub($e::class . ': ' . $e->getMessage())]);
        }

        return $this->transport;
    }

    /**
     * Does the transport really send? framework.notifier without chatter_transports still has a (empty) transport collection: it prints as «[]».
     * null://null means «ничего не отправлять» (TELEGRAM_DSN in the .env of a new site), the same as for mail. The collection does not show its transports, but a null transport takes any message and a chatter only chat messages.
     */
    private static function canDeliver(TransportInterface $chatter): bool
    {
        return '[]' !== (string) $chatter && !$chatter->supports(new SmsMessage('+10000000000', 'probe'));
    }

    /** Text of the message (Telegram HTML), in the current language (SendRequestNotificationHandler: admin_locale). */
    public function buildText(Request $r, bool $resend = false): string
    {
        $form = $this->schema->form($r->form);
        $e = static fn (mixed $v): string => HtmlSanitizer::escape(\is_scalar($v) ? (string) $v : '');
        $title = $form?->formTitle() ?? $r->form;
        $template = $form?->telegramTemplate();

        if (null !== $template) {
            $vars = ['id' => (string) $r->id, 'form' => $e($title), 'phone' => $e($r->phone), 'name' => $e($r->name), 'city' => $e($r->city), 'url' => $e($r->sourceUrl)];
            foreach ($r->data as $k => $v) {
                $vars[(string) $k] = $e($v);
            }
            $lines = [(string) \preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => $vars[$m[1]] ?? '', $template)];
        } else {
            $lines = [I18n::t('http.telegram_title', ['id' => $r->id, 'form' => $e($title)])];
            $shown = [];
            foreach ($form?->fieldList() ?? [] as $f) {
                $v = $r->data[$f->key()] ?? null;
                if (null === $v || '' === $v) {
                    continue;
                }
                $shown[$f->key()] = true;
                $lines[] = $e($f->label()) . ': ' . $e(\is_bool($v) ? I18n::t($v ? 'http.yes' : 'http.no') : $v);
            }
            foreach ($r->data as $k => $v) {
                if (!isset($shown[$k]) && \is_scalar($v) && '' !== $v) {
                    $lines[] = $e($k) . ': ' . $e($v);
                }
            }
            if (null !== $r->sourceUrl && '' !== $r->sourceUrl) {
                $lines[] = I18n::t('http.request_page_line', ['url' => $e($r->sourceUrl)]);
            }
        }
        if ($resend) {
            \array_unshift($lines, I18n::t('http.telegram_resend', ['id' => $r->id]), '');
        }

        return \implode("\n", $lines);
    }

    private function fail(Request $r, string $error): bool
    {
        $r->delivery = Request::DELIVERY_FAILED;
        $r->deliveryError = $error;
        $this->events->dispatch(new RequestDelivery($r, false, $error));

        return false;
    }
}
