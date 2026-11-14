<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Itk\Cms\Request\Max\MaxDeliveryException;
use Itk\Cms\Request\Max\MaxSender;

/**
 * The MAX of the test site (aliased over MaxApiSender): no network, and not connected until a test says so (`$connected`), like a site without the MAX bundle.
 * Records every message, and refuses them with the reason in `$refuse`.
 */
final class FakeMaxSender implements MaxSender
{
    public bool $connected = false;

    /** The reason MAX gives for refusing every message; null = it takes them. */
    public ?string $refuse = null;

    /** @var (\Closure(): void)|null runs inside send(), while the message is on its way */
    public ?\Closure $onSend = null;

    /** @var list<array{chat: string, text: string}> */
    public array $sent = [];

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function send(int|string $chatId, string $html): void
    {
        if (!$this->connected) {
            throw new MaxDeliveryException('MAX is not connected');
        }
        if (null !== $this->onSend) {
            ($this->onSend)();
        }
        if (null !== $this->refuse) {
            throw new MaxDeliveryException($this->refuse);
        }
        $this->sent[] = ['chat' => (string) $chatId, 'text' => $html];
    }
}
