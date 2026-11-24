<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\I18n\I18n;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Exception\RuntimeException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\RoundRobinTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * Stops Symfony failover before another bot can duplicate a message whose outcome is unknown.
 *
 * @internal
 */
final class SingleAttemptTelegramTransport implements TransportInterface
{
    /** @param list<string> $tokens */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ?SingleAttemptHttpClient $client = null,
        private readonly ?LoggerInterface $logger = null,
        #[\SensitiveParameter]
        private readonly array $tokens = [],
    ) {
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }

    public function supports(MessageInterface $message): bool
    {
        return $this->transport->supports($message);
    }

    public function send(MessageInterface $message): ?SentMessage
    {
        $this->client?->beginAttempt();
        try {
            $sent = $this->transport->send($message);
            if (null !== $this->client?->response() && !$this->client->accepted()) {
                throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
            }

            return $sent;
        } catch (\Throwable $failure) {
            if ($this->client?->accepted()) {
                // This leaf's original POST succeeded. A decoding error or listener's separate send cannot undo it.
                $this->logger?->warning('Telegram accepted the message, but post-send processing failed: {error}', ['error' => TelegramConnection::scrub($failure::class . ': ' . $failure->getMessage(), $this->tokens)]);

                return new SentMessage($message, (string) $this->transport);
            }
            if (null !== ($response = $this->client?->response())) {
                // Classify the original POST, even if a failure listener threw for an unrelated request.
                if ($this->client->refused() || HttpDeliveryFailure::beforeSend($response->getInfo(), $response)) {
                    throw new TransportException(TelegramConnection::scrub($failure->getMessage(), $this->tokens), $response);
                }
                throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
            }
            if ($failure instanceof TransportException) {
                if (TelegramTransportGuard::refused($failure)) {
                    // A definite rejection is safe for Symfony failover to handle.
                    throw $failure;
                }
                throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
            }
            if ($failure instanceof TransportExceptionInterface || $failure instanceof \Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
                throw new DeliveryUncertainException(I18n::t('http.request_delivery_uncertain'));
            }
            if ($failure instanceof RuntimeException && $this->transport instanceof RoundRobinTransport && 'All transports failed.' === $failure->getMessage()) {
                // Ambiguous leaf failures escape the composition above; reaching exhaustion means all were refused.
                throw new DeliveryRefusedException($failure->getMessage());
            }
            throw $failure;
        } finally {
            $this->client?->endAttempt();
        }
    }
}
