<?php

declare(strict_types=1);

namespace Itk\Cms\CriticalCss;

use Itk\Cms\CriticalCss\Model\GenerateCriticalCss;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One message, one language version of a page, through CriticalCssGenerator. A page that fails is recorded as Failed and the message is done:
 * a Messenger retry would only start Chrome again on the same input. Only an exception of generate() itself (the database gone) leaves the message
 * unhandled, and then Messenger retries as usual.
 *
 * @internal
 */
#[AsMessageHandler]
final class GenerateCriticalCssHandler
{
    public function __construct(
        private readonly CriticalCssGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateCriticalCss $message): void
    {
        $report = $this->generator->generate([$message->translationId => $message->when]);
        foreach ($report->failed as $id => $reason) {
            $this->logger->warning('Critical CSS of page version {id} was not made: {reason}', ['id' => $id, 'reason' => $reason]);
        }
    }
}
