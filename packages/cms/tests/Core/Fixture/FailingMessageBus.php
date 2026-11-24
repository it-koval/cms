<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class FailingMessageBus implements MessageBusInterface
{
    public int $attempts = 0;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        ++$this->attempts;
        throw new \RuntimeException('Queue is down');
    }
}
