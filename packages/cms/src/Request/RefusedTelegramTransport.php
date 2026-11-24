<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\I18n\I18n;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\TransportInterface;

/**
 * A configured transport whose client graph cannot be isolated safely.
 *
 * @internal
 */
final class RefusedTelegramTransport implements TransportInterface
{
    public function __construct(private readonly TransportInterface $transport, private readonly string $reason)
    {
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }

    public function supports(MessageInterface $message): bool
    {
        return $this->transport->supports($message);
    }

    public function problem(): string
    {
        return I18n::t('http.telegram_transport_incompatible', ['reason' => $this->reason]);
    }

    public function send(MessageInterface $message): ?SentMessage
    {
        throw new DeliveryRefusedException($this->problem());
    }
}
