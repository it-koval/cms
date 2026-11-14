<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Entity\Request;

/** What one messenger did with a request: nothing to send (pending), delivered, or refused with a reason. Made outside the transaction, written inside it. */
final class ChannelOutcome
{
    private function __construct(
        public readonly string $state,
        public readonly ?string $error = null,
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

    public function isPending(): bool
    {
        return Request::DELIVERY_PENDING === $this->state;
    }

    public function isFailed(): bool
    {
        return Request::DELIVERY_FAILED === $this->state;
    }
}
