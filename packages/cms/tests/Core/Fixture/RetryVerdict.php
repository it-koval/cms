<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** What the worker does with a message a handler nacked: Messenger's own retry listener, on a transport that allows three retries. */
final class RetryVerdict
{
    /** Is the message tried again after this failure, when it has already been retried $retries times? */
    public static function retried(object $message, \Throwable $failure, int $retries): bool
    {
        $envelope = (new Envelope($message))->with(new RedeliveryStamp($retries));
        $event = new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [$failure]));
        $listener = new SendFailedMessageForRetryListener(
            new ServiceLocator(['async' => static fn (): InMemoryTransport => new InMemoryTransport()]),
            new ServiceLocator(['async' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(3)]),
        );
        $listener->onMessageFailed($event);

        return $event->willRetry();
    }
}
