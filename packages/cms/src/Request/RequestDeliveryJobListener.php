<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Retry decisions have been made at priority 100; final failure releases the operator's action.
 *
 * @internal
 */
#[AsEventListener(priority: -150)]
final class RequestDeliveryJobListener
{
    public function __construct(private readonly RequestDeliverer $deliverer, private readonly ?LoggerInterface $logger = null)
    {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof SendRequestNotification || $event->willRetry()) {
            return;
        }
        try {
            $this->deliverer->settlePhase($message->requestId, $message->channel, $message->jobId ?? 'legacy', 'failed');
        } catch (\Throwable) {
            // A failed flush may have closed the manager. Keep Messenger's original failure;
            // the persisted reservation expires rather than trapping the operator forever.
            $this->logger?->error('Could not release the failed delivery job for request #{id}.', ['id' => $message->requestId]);
        }
    }
}
