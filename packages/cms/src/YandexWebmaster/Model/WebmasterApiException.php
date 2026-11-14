<?php

declare(strict_types=1);

namespace Itk\Cms\YandexWebmaster\Model;

/**
 * Yandex Webmaster answered with an error: the HTTP status and the `error_code` of its body. The message never holds the token or the address of the request.
 *
 * @internal
 */
final class WebmasterApiException extends \RuntimeException implements WebmasterFailure
{
    /** @param array<string, mixed> $body the decoded error body: some errors carry data (HOST_ALREADY_ADDED has the host_id) */
    public function __construct(public readonly int $status, public readonly ?string $errorCode, public readonly array $body = [])
    {
        parent::__construct(\sprintf('Yandex Webmaster answered HTTP %d%s', $status, null === $errorCode ? '' : ' ' . $errorCode), $status);
    }

    /** Yandex says the token is missing or no good: 401, or 403 INVALID_OAUTH_TOKEN. */
    public function isInvalidToken(): bool
    {
        return 401 === $this->status || (403 === $this->status && 'INVALID_OAUTH_TOKEN' === $this->errorCode);
    }

    /** The quota or a failure on the side of Yandex. */
    public function isTemporary(): bool
    {
        return 429 === $this->status || $this->status >= 500;
    }

    public function messageKey(): string
    {
        return match (true) {
            $this->isInvalidToken() => 'ops.webmaster_token_rejected',
            $this->isTemporary() => 'ops.webmaster_error_busy',
            default => 'ops.webmaster_error_api',
        };
    }

    public function messageCode(): string
    {
        return $this->errorCode ?? (string) $this->status;
    }
}
