<?php

declare(strict_types=1);

namespace Itk\Cms\Event;

use Itk\Cms\Entity\Request;

/**
 * The delivery of a request to a messenger (`channel`: Request::CHANNEL_TELEGRAM, the default, or Request::CHANNEL_MAX) has an outcome: it reached the chats, or it did not (`error` says why).
 * Not dispatched while that messenger is off, except once with `cancelled`: a request that had failed ended up «pending», so it is no failure any more though it was not delivered.
 */
final class RequestDelivery
{
    public function __construct(
        public readonly Request $request,
        public readonly bool $delivered,
        public readonly ?string $error = null,
        public readonly bool $cancelled = false,
        public readonly string $channel = Request::CHANNEL_TELEGRAM,
    ) {
    }
}
