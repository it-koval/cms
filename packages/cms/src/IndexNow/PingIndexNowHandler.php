<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow;

use Itk\Cms\IndexNow\Model\PingIndexNow;
use Itk\Cms\Support\Url;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Handler\Acknowledger;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PingIndexNow in batches: one POST {host, key, keyLocation, urlList} to the IndexNow endpoint (itk_cms.indexnow.endpoint) for up to BATCH messages.
 * The address is the one of the moment the batch runs, and the state is checked again: a toggle turned off after queueing sends nothing.
 *
 *   200 | 202                          ack (202: the engine has not checked the key file yet)
 *   429, 5xx, no answer              nack every job with a RecoverableMessageHandlingException, after Retry-After when the engine names it
 *   400 | 403 | 422 and other 4xx     nack every job unrecoverable with the status and the start of the body: a retry would be refused the same way
 *   toggle off, no address, no client  ack, nothing is sent
 *
 * In a sync transport there is no Acknowledger and the trait runs process() at once with the one job.
 *
 * @internal
 */
#[AsMessageHandler(bus: 'messenger.bus.default')]
final class PingIndexNowHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    private const BATCH = 100;

    public function __construct(
        private readonly IndexNowSite $site,
        private readonly ?HttpClientInterface $http,
        #[Autowire(param: 'itk_cms.indexnow.endpoint')]
        private readonly string $endpoint,
    ) {
    }

    public function __invoke(PingIndexNow $message, ?Acknowledger $ack = null): mixed
    {
        return $this->handle($message, $ack);
    }

    /** @param list<array{0: PingIndexNow, 1: Acknowledger}> $jobs */
    private function process(array $jobs): void
    {
        if (IndexNowState::Ready !== $this->site->state() || null === $this->http) {
            $this->settle($jobs, null);

            return;
        }
        $urls = [];
        foreach ($jobs as [$message]) {
            $urls[$this->site->base() . Url::encodePath($message->path)] = true;
        }
        $urls = \array_keys($urls);
        try {
            $response = $this->http->request('POST', $this->endpoint, [
                'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
                'json' => ['host' => $this->site->host(), 'key' => $this->site->key(), 'keyLocation' => $this->site->keyLocation(), 'urlList' => $urls],
            ]);
            $status = $response->getStatusCode();
            $body = \mb_substr(\trim($response->getContent(false)), 0, 200);
            $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
        } catch (HttpException $e) {
            $this->site->recordSubmission(\count($urls), 0, $e->getMessage());
            $this->settle($jobs, new RecoverableMessageHandlingException('IndexNow did not answer: ' . $e->getMessage(), 0, $e));

            return;
        }
        $accepted = 200 === $status || 202 === $status;
        $this->site->recordSubmission(\count($urls), $status, $accepted ? null : $body);
        $this->settle($jobs, match (true) {
            $accepted => null,
            429 === $status || $status >= 500 => new RecoverableMessageHandlingException(\sprintf('IndexNow answered %d. %s', $status, $body), 0, null, $retryAfter > 0 ? $retryAfter * 1000 : null),
            default => new UnrecoverableMessageHandlingException(\sprintf('IndexNow refused the addresses with %d. %s', $status, $body)),
        });
    }

    /** @param list<array{0: PingIndexNow, 1: Acknowledger}> $jobs */
    private function settle(array $jobs, ?\Throwable $failure): void
    {
        foreach ($jobs as [, $ack]) {
            null === $failure ? $ack->ack() : $ack->nack($failure);
        }
    }

    private function getBatchSize(): int
    {
        return self::BATCH;
    }
}
