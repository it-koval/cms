<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\Request;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Mail\SendMail;
use Itk\Cms\Media\GenerateImageVariants;
use Itk\Cms\Request\SendRequestNotification;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The queue of the site as «Состояние сайта» shows it: how many messages wait in each transport, what landed in the failure transport and why,
 * and the two things a person can do with a failed message: put it back into the queue it came from, or drop it.
 * Works with whatever transports the site configured (the core knows no transport names); without a failure transport the failed block is empty.
 *
 * @internal
 */
final class MessengerMonitor
{
    public const LIMIT = 50;

    private bool $failedResolved = false;
    private ?ReceiverInterface $failedReceiver = null;
    private ?string $failedProblem = null;

    /**
     * @param ServiceProviderInterface<object> $receivers messenger.receiver_locator: every transport by its name (and by service id)
     * @param (\Closure(): ?ReceiverInterface)|ReceiverInterface|null $failed the failure transport (messenger.failure_transports.default; a closure builds it on first use, so a transport
     *                                                                        that cannot be built does not break the screen), null when the site has none
     */
    public function __construct(
        private readonly ServiceProviderInterface $receivers,
        private readonly \Closure|ReceiverInterface|null $failed = null,
    ) {
    }

    /**
     * Messages waiting in each transport that can say how many (the failure transport and «sync» are not queues).
     * A transport that cannot be built or does not answer (a DSN nobody can handle, a database that is down) is shown with its `error` instead of failing the screen.
     *
     * @return list<array{name: string, waiting: int|null, error?: string}>
     */
    public function queues(): array
    {
        $out = [];
        foreach ($this->transportNames() as $name) {
            try {
                $t = $this->receivers->get($name);
                if (!$t instanceof MessageCountAwareInterface || $t === $this->failedTransport()) {
                    continue;
                }
                $out[] = ['name' => $name, 'waiting' => $t->getMessageCount()];
            } catch (\Throwable $e) {
                $out[] = ['name' => $name, 'waiting' => null, 'error' => I18n::t('ops.queue_transport_failed', ['error' => self::scrub($e->getMessage())])];
            }
        }

        return $out;
    }

    /**
     * What is in the failure transport (the newest LIMIT messages) and how many there are.
     *
     * @return array{available: bool, total: int, items: list<array<string, mixed>>, error?: string}
     */
    public function failed(): array
    {
        $failed = $this->failedTransport();
        if (null !== $this->failedProblem) {
            return ['available' => true, 'total' => 0, 'items' => [], 'error' => I18n::t('ops.queue_failed_store_down', ['error' => $this->failedProblem])];
        }
        if (!$failed instanceof ListableReceiverInterface) {
            return ['available' => false, 'total' => 0, 'items' => []];
        }
        try {
            $items = [];
            foreach ($failed->all(self::LIMIT) as $envelope) {
                $items[] = $this->present($envelope);
            }
            $total = $failed instanceof MessageCountAwareInterface ? $failed->getMessageCount() : \count($items);
        } catch (\Throwable) {
            return ['available' => true, 'total' => 0, 'items' => [], 'error' => I18n::t('ops.queue_failed_unreadable')];
        }

        return ['available' => true, 'total' => \max($total, \count($items)), 'items' => $items];
    }

    /** Puts a failed message back into the queue it came from; a worker takes it from there as a new one (the retry count starts over). */
    public function retry(string $id): void
    {
        $envelope = $this->envelope($id);
        $from = $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName();
        try {
            $target = null !== $from && $this->receivers->has($from) ? $this->receivers->get($from) : null;
        } catch (\Throwable) {
            $target = null;
        }
        if (!$target instanceof SenderInterface) {
            throw ContentException::invalid(I18n::t('ops.queue_no_origin'));
        }
        $target->send(new Envelope($envelope->getMessage(), $envelope->all(BusNameStamp::class)));
        $this->failedTransport()?->ack($envelope);
    }

    public function delete(string $id): void
    {
        $envelope = $this->envelope($id);
        $this->failedTransport()?->reject($envelope);
    }

    /** @return list<string> */
    private function transportNames(): array
    {
        // the locator knows each transport twice: by its name and by its service id («messenger.transport.async»)
        return \array_values(\array_filter(\array_keys($this->receivers->getProvidedServices()), static fn (string|int $k): bool => !\str_contains((string) $k, '.')));
    }

    /** The failure transport, built on the first call; null when the site has none or it cannot be built (the reason is in $failedProblem). */
    private function failedTransport(): ?ReceiverInterface
    {
        if (!$this->failedResolved) {
            $this->failedResolved = true;
            try {
                $failed = $this->failed instanceof \Closure ? ($this->failed)() : $this->failed;
                $this->failedReceiver = $failed instanceof ReceiverInterface ? $failed : null;
            } catch (\Throwable $e) {
                $this->failedProblem = self::scrub($e->getMessage());
            }
        }

        return $this->failedReceiver;
    }

    private function envelope(string $id): Envelope
    {
        $failed = $this->failedTransport();
        if (!$failed instanceof ListableReceiverInterface) {
            throw ContentException::notFound(I18n::t('ops.queue_no_failed'));
        }
        try {
            $envelope = $failed->find($id);
        } catch (\Throwable) {
            throw ContentException::invalid(I18n::t('ops.queue_message_unreadable'));
        }

        return $envelope ?? throw ContentException::notFound(I18n::t('ops.queue_message_gone'));
    }

    /** @return array<string, mixed> */
    private function present(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $error = $envelope->last(ErrorDetailsStamp::class);
        $redelivery = $envelope->last(RedeliveryStamp::class);

        return [
            'id' => (string) $envelope->last(TransportMessageIdStamp::class)?->getId(),
            'type' => $message::class,
            'label' => self::label($message),
            'error' => null === $error ? '' : self::scrub($error->getExceptionMessage()),
            'errorClass' => null === $error ? '' : $error->getExceptionClass(),
            'retries' => $redelivery?->getRetryCount() ?? 0,
            'failedAt' => $redelivery?->getRedeliveredAt()->format(\DATE_ATOM),
            'queue' => $envelope->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName(),
        ];
    }

    /** A line for a person instead of a class name. */
    private static function label(object $message): string
    {
        return match (true) {
            $message instanceof SendRequestNotification => I18n::t(Request::CHANNEL_MAX === $message->channel ? 'ops.queue_label_request_max' : 'ops.queue_label_request_telegram', ['id' => $message->requestId]),
            $message instanceof SendMail => match ($message->kind) {
                SendMail::REQUEST => I18n::t('ops.queue_label_mail_request', ['id' => $message->ref, 'address' => self::maskAddress($message->to)]),
                SendMail::RESET, SendMail::INVITE => I18n::t('ops.queue_label_mail_password', ['address' => self::maskAddress($message->to)]),
                default => I18n::t('ops.queue_label_mail', ['subject' => \mb_substr($message->subject, 0, 60), 'address' => self::maskAddress($message->to)]),
            },
            $message instanceof GenerateImageVariants => I18n::t('ops.queue_label_image_variants'),
            default => (new \ReflectionClass($message))->getShortName(),
        };
    }

    /** an address for a screen of developers: «ivan@example.com» → «i***@example.com» */
    private static function maskAddress(string $email): string
    {
        $at = \strrpos($email, '@');

        return false === $at ? '***' : \mb_substr($email, 0, 1) . '***' . \substr($email, $at);
    }

    /** Error texts are shown on the screen: the bot token of a Telegram address never goes there, and a long trace is cut. */
    public static function scrub(string $text): string
    {
        $text = \Itk\Cms\Logging\SecretScrubber::scrub($text);

        return \mb_strlen($text) > 400 ? \mb_substr($text, 0, 400) . '…' : $text;
    }
}
