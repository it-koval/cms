<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Doctrine\DBAL\Connection;
use Itk\Cms\Entity\Request;
use Itk\Cms\Event\RequestDelivery;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * What one messenger did with a request: nothing to send (pending), delivered, or refused with a reason. Made outside the transaction, written inside it.
 *
 * @internal
 */
final class ChannelOutcome
{
    private function __construct(
        public readonly string $state,
        public readonly ?string $error = null,
        public readonly bool $uncertain = false,
    ) {
    }

    public static function pending(): self
    {
        return new self(Request::DELIVERY_PENDING);
    }

    public static function sent(): self
    {
        return new self(Request::DELIVERY_SENT);
    }

    public static function failed(string $error): self
    {
        return new self(Request::DELIVERY_FAILED, $error);
    }

    public static function uncertain(string $error): self
    {
        return new self(Request::DELIVERY_FAILED, $error, true);
    }

    public function isPending(): bool
    {
        return Request::DELIVERY_PENDING === $this->state;
    }

    public function isFailed(): bool
    {
        return Request::DELIVERY_FAILED === $this->state;
    }

    /**
     * @param Request::CHANNEL_* $channel
     * @param (\Closure(\Closure(): void): void)|null $defer
     */
    public function record(Request $request, string $channel, EventDispatcherInterface $events, ?\Closure $defer, ?Connection $deliveryConnection = null): bool
    {
        $wasFailed = Request::DELIVERY_FAILED === $request->channelState($channel);
        $request->markChannel($channel, $this->state, $this->error);
        if (!$this->isPending() || $wasFailed) {
            $event = new RequestDelivery($request, Request::DELIVERY_SENT === $this->state, $this->error, $this->isPending(), $channel, $deliveryConnection);
            $announce = static function () use ($events, $event): void {
                $events->dispatch($event);
            };
            null === $defer ? $announce() : $defer($announce);
        }

        return !$this->isFailed();
    }
}
