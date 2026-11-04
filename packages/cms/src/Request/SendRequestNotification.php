<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

/** Messenger message (async transport, bus messenger.bus.default): send a request to Telegram. */
final class SendRequestNotification
{
    public function __construct(
        public readonly int $requestId,
        public readonly bool $resend = false,
    ) {
    }
}
