<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/** Recovery runs even when the broker has no envelopes: committed request intents do not rely on a request-process callback. */
#[AsEventListener(event: WorkerRunningEvent::class)]
final class RequestQueueRecoveryListener
{
    private float $next = 0;

    public function __construct(private readonly RequestQueue $queue, private readonly LoggerInterface $logger, private readonly RequestDeliverer $deliverer)
    {
    }

    public function __invoke(WorkerRunningEvent $event): void
    {
        if (\microtime(true) < $this->next) {
            return;
        }
        $this->next = \microtime(true) + 5;
        try {
            $this->deliverer->recoverAnnouncements();
        } catch (\Throwable $failure) {
            $this->logger->error('Request delivery announcement recovery failed.', ['exceptionType' => $failure::class]);
        }
        try {
            $this->queue->recover();
        } catch (\Throwable $failure) {
            $this->logger->error('Request notification outbox recovery failed; the next worker tick will retry.', ['exceptionType' => $failure::class]);
        }
    }
}
