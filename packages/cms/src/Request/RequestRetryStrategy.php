<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;

/** @internal The default async transport serves both site requests and other background jobs. */
final class RequestRetryStrategy implements RetryStrategyInterface
{
    private readonly RetryStrategyInterface $requests;
    private readonly RetryStrategyInterface $default;

    public function __construct()
    {
        $this->requests = new MultiplierRetryStrategy(delayMilliseconds: 60000, multiplier: 2, jitter: 0);
        $this->default = new MultiplierRetryStrategy(multiplier: 2); // Symfony Framework's default transport strategy
    }

    public function isRetryable(Envelope $message, ?\Throwable $throwable = null): bool
    {
        return $this->forMessage($message)->isRetryable($message, $throwable);
    }

    public function getWaitingTime(Envelope $message, ?\Throwable $throwable = null): int
    {
        return $this->forMessage($message)->getWaitingTime($message, $throwable);
    }

    private function forMessage(Envelope $message): RetryStrategyInterface
    {
        return $message->getMessage() instanceof SendRequestNotification ? $this->requests : $this->default;
    }
}
