<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Transport\RoundRobinTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Isolates CMS delivery from the site's automatic HTTP retry policy.
 *
 * Symfony exposes neither a transport's client nor a collection's children. Copy only the transport/client
 * graph, retaining tokens, DSN options, scoped HTTP defaults and dispatchers. Leaf clients remain shared.
 * The adapter covers Symfony 7's AbstractTransport, Transports and HTTP client decorators; its behavior
 * is checked through the actual Telegram bridge with a simulated HTTP wire in TelegramConnectionTest.
 *
 * @internal
 */
final class TelegramTransportGuard
{
    public static function refused(TransportException $failure): bool
    {
        if (HttpDeliveryFailure::beforeSend($failure->getResponse()->getInfo(), $failure->getResponse())) {
            return true;
        }
        $status = $failure->getResponse()->getInfo('http_code');

        return null === $failure->getPrevious() && $status >= 400 && $status < 500 && 408 !== $status;
    }

    public static function transport(TransportInterface $transport, ?LoggerInterface $logger = null): TransportInterface
    {
        $tokens = [];
        try {
            $copy = self::copy($transport, new \SplObjectStorage(), $logger, $tokens);
        } catch (\Throwable $failure) {
            // A concrete/readonly decorator property may reject the guarded interface implementation.
            // Keep the channel configured, but never fall back to sending through an unguarded client.
            $copy = new RefusedTelegramTransport($transport, TelegramConnection::scrub($failure::class . ': ' . $failure->getMessage(), $tokens));
            $logger?->warning('Telegram transport cannot be isolated for safe delivery: {error}', ['error' => $copy->problem()]);
        }
        \assert($copy instanceof TransportInterface);

        return $copy;
    }

    public static function client(HttpClientInterface $client): HttpClientInterface
    {
        $copy = self::copy($client, new \SplObjectStorage());
        \assert($copy instanceof HttpClientInterface);

        return new SingleAttemptHttpClient($copy);
    }

    /**
     * @param \SplObjectStorage<object, object> $copies
     * @param list<string> $secrets
     */
    private static function copy(object $source, \SplObjectStorage $copies, ?LoggerInterface $logger = null, array &$secrets = []): object
    {
        if (isset($copies[$source])) {
            return $copies[$source];
        }
        // Register before descending so a decorating client cannot introduce an infinite recursion.
        $copies[$source] = $source;
        $copy = null;
        $client = null;
        $tokens = [];
        for ($class = new \ReflectionObject($source); false !== $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || !$property->isInitialized($source)) {
                    continue;
                }
                $value = $property->getValue($source);
                if ($source instanceof TelegramTransport && 'token' === $property->getName() && \is_string($value)) {
                    $tokens[] = $value;
                    $secrets[] = $value;
                }
                $replacement = $value;
                if ($value instanceof TransportInterface || $value instanceof HttpClientInterface) {
                    $replacement = self::copy($value, $copies, $logger, $secrets);
                    if ($source instanceof TransportInterface && $replacement instanceof HttpClientInterface) {
                        $replacement = $client = new SingleAttemptHttpClient($replacement);
                    }
                } elseif (\is_array($value)) {
                    foreach ($value as $key => $child) {
                        if ($child instanceof TransportInterface || $child instanceof HttpClientInterface) {
                            $replacement[$key] = self::copy($child, $copies, $logger, $secrets);
                        }
                    }
                } elseif ($source instanceof RoundRobinTransport && $value instanceof \SplObjectStorage) {
                    $replacement = new \SplObjectStorage();
                    foreach ($value as $child) {
                        $replacement[$child instanceof TransportInterface ? self::copy($child, $copies, $logger, $secrets) : $child] = $value[$child];
                    }
                }
                if ($replacement !== $value) {
                    $copy ??= clone $source;
                    $copies[$source] = $copy;
                    $property->setValue($copy, $replacement);
                }
            }
        }
        if ($source instanceof RetryableHttpClient) {
            // A scoped max_retries option can override the default, so enforce the limit on each request.
            $copy = new SingleAttemptHttpClient($copy ?? $source, retryable: true);
        }
        if ($source instanceof TransportInterface && !$source instanceof HttpClientInterface) {
            $transport = $copy ?? $source;
            \assert($transport instanceof TransportInterface);
            $copy = new SingleAttemptTelegramTransport($transport, $client, $logger, $tokens);
        }

        return $copies[$source] = $copy ?? $source;
    }
}
