<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/** Retry decisions have been made at priority 100; final failure releases the operator's action. */
#[AsEventListener(priority: -150)]
final class RequestDeliveryJobListener
{
    public function __construct(private readonly RequestDeliverer $deliverer, private readonly ?LoggerInterface $logger = null)
    {
    }

    /** Run after the failed:retry command has decided whether to retry, skip or delete. */
    #[AsEventListener(priority: -200)]
    public function retryFailedJob(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        if ($event->shouldHandle() && $message instanceof SendRequestNotification && null !== $envelope->last(SentToFailureTransportStamp::class)) {
            $this->deliverer->resumeJob($message);
        }
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
