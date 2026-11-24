<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Entity\Request;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** @internal */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class SendRequestNotificationHandler
{
    public function __construct(private readonly RequestDeliverer $deliverer)
    {
    }

    public function __invoke(SendRequestNotification $message): void
    {
        $delivered = $this->deliverer->deliver($message->requestId, $message->channel, $message->resend, $message->jobId ?? 'legacy');
        if (false === $delivered) {
            // Messenger retries (retry_strategy of the async transport), this messenger only; its state stays «failed» until a delivery works
            throw new RequestNotDeliveredException(\sprintf('Request #%d was not delivered to %s.', $message->requestId, Request::CHANNELS[$message->channel] ?? $message->channel));
        }
    }
}
