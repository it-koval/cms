<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Itk\Cms\Entity\Request;

/**
 * Messenger message (async transport, bus messenger.bus.default): send a request to one messenger.
 * `channel` is Request::CHANNEL_TELEGRAM or Request::CHANNEL_MAX; a message queued before MAX existed has none and goes to Telegram.
 */
final class SendRequestNotification
{
    public function __construct(
        public readonly int $requestId,
        public readonly bool $resend = false,
        public readonly string $channel = Request::CHANNEL_TELEGRAM,
    ) {
    }

    /**
     * Messenger restores messages without the constructor, so a payload queued before `channel` existed gets its default here.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->requestId = (int) $data['requestId'];
        $this->resend = (bool) ($data['resend'] ?? false);
        $this->channel = (string) ($data['channel'] ?? Request::CHANNEL_TELEGRAM);
    }
}
