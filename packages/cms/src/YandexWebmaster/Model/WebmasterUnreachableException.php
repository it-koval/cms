<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * Yandex Webmaster did not answer: no connection, a timeout, a broken response.
 *
 * @internal
 */
final class WebmasterUnreachableException extends \RuntimeException implements WebmasterFailure
{
    public function isTemporary(): bool
    {
        return true;
    }

    public function messageKey(): string
    {
        return 'ops.webmaster_error_unreachable';
    }

    public function messageCode(): ?string
    {
        return null;
    }
}
