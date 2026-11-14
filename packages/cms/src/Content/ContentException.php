<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

/** A rule of the content model was broken. `status` is the HTTP code the admin API answers with (403/404/409/422). */
final class ContentException extends \RuntimeException
{
    /**
     * @param mixed $details extra data for the client: a list of field errors, { field: message }, …
     */
    public function __construct(string $message, private readonly int $status = 422, private readonly mixed $details = null)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): mixed
    {
        return $this->details;
    }

    public static function notFound(string $message): self
    {
        return new self($message, 404);
    }

    public static function conflict(string $message, mixed $details = null): self
    {
        return new self($message, 409, $details);
    }

    public static function invalid(string $message, mixed $details = null): self
    {
        return new self($message, 422, $details);
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403);
    }
}
