<?php

declare(strict_types=1);

namespace Itk\Cms\Logging;

/**
 * What SecretScrubProcessor puts in a log record in place of an exception that carried a secret: the same class name, message, place, code and previous
 * exceptions (all scrubbed). The stack trace of the original is dropped: its arguments may hold the secret too.
 *
 * @internal
 */
final class ScrubbedThrowable extends \RuntimeException
{
    public function __construct(private readonly string $original, string $message, int $code, string $file, int $line, ?\Throwable $previous, private readonly string $text)
    {
        parent::__construct($original . ': ' . $message, $code, $previous);
        $this->file = $file;
        $this->line = $line;
    }

    /** Class of the exception that was replaced. */
    public function originalClass(): string
    {
        return $this->original;
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
