<?php

declare(strict_types=1);

namespace Itk\Cms\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Keeps the Telegram bot token out of logs: HttpClient logs «POST https://api.telegram.org/bot<token>/sendMessage» at INFO.
 * Replaces `bot<digits>:<token>` with `bot***` in the message and, recursively, in the string values of context and extra.
 * An exception in the context (`'exception' => $e`; HttpClient puts the request URL into its message) that carries the token, or whose previous exception does, is replaced
 * with a ScrubbedThrowable: the class, message, place and previous exceptions stay, the secret and the stack trace do not.
 * Registered by the bundle when Monolog is installed (config/services.php), with the tag `monolog.processor`.
 *
 * @internal
 */
final class SecretScrubProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    private function scrubString(string $s): string
    {
        return SecretScrubber::scrub($s);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private function scrub(array $data): array
    {
        foreach ($data as $k => $v) {
            if (\is_string($v)) {
                $data[$k] = $this->scrubString($v);
            } elseif (\is_array($v)) {
                $data[$k] = $this->scrub($v);
            } elseif ($v instanceof \Throwable) {
                $data[$k] = $this->scrubThrowable($v);
            }
        }

        return $data;
    }

    private function scrubThrowable(\Throwable $e): \Throwable
    {
        if (!$this->carriesSecret($e)) {
            return $e;
        }
        $previous = $e->getPrevious();

        return new ScrubbedThrowable(
            $e::class,
            $this->scrubString($e->getMessage()),
            \is_int($e->getCode()) ? $e->getCode() : 0,
            $e->getFile(),
            $e->getLine(),
            null === $previous ? null : $this->scrubThrowable($previous),
            $this->scrubString((string) $e),
        );
    }

    /** The message, the string form (class, place, trace) of the exception or of any of its previous exceptions holds the token. */
    private function carriesSecret(\Throwable $e): bool
    {
        for ($i = 0; null !== $e && $i < 20; $e = $e->getPrevious(), ++$i) {
            if ($e->getMessage() !== SecretScrubber::scrub($e->getMessage()) || (string) $e !== SecretScrubber::scrub((string) $e)) {
                return true;
            }
        }

        return false;
    }
}
