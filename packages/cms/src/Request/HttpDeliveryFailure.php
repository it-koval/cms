<?php

declare(strict_types=1);

namespace Itk\Cms\Request;

use Symfony\Component\HttpClient\Response\NativeResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** @internal */
final class HttpDeliveryFailure
{
    /** @param array<string, mixed> $info */
    public static function beforeSend(array $info, ?ResponseInterface $response = null): bool
    {
        // Absence of progress information is not evidence that nothing left the host.
        if (!isset($info['size_upload']) || !\is_numeric($info['size_upload']) || 0.0 !== (float) $info['size_upload']
            || 0 !== (int) ($info['request_size'] ?? 0) || 0 !== (int) ($info['http_code'] ?? 0) || 0 !== (int) ($info['redirect_count'] ?? 0)) {
            return false;
        }
        // NativeResponse::open() always sets pretransfer_time in finally. Only its STREAM_NOTIFY_CONNECT
        // callback proves a connection was established, and getInfo() removes the pending request_header.
        // Restrict this evidence to an actual NativeResponse, including Symfony's response decorators.
        if (null !== $response && self::isNative($response, new \SplObjectStorage())) {
            return isset($info['connect_time'], $info['starttransfer_time'])
                && \is_numeric($info['connect_time']) && 0.0 === (float) $info['connect_time']
                && \is_numeric($info['starttransfer_time']) && 0.0 === (float) $info['starttransfer_time'];
        }

        return isset($info['pretransfer_time']) && \is_numeric($info['pretransfer_time']) && 0.0 === (float) $info['pretransfer_time'];
    }

    /** @param \SplObjectStorage<object, null> $seen */
    private static function isNative(ResponseInterface $response, \SplObjectStorage $seen): bool
    {
        if ($response instanceof NativeResponse) {
            return true;
        }
        if (isset($seen[$response])) {
            return false;
        }
        $seen[$response] = null;
        for ($class = new \ReflectionObject($response); false !== $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || !$property->isInitialized($response)) {
                    continue;
                }
                $child = $property->getValue($response);
                if ($child instanceof ResponseInterface && self::isNative($child, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }
}
