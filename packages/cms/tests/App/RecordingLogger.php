<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App;

use Psr\Log\AbstractLogger;

/** The logger of the test site: it prints nothing and keeps what was logged, so a test can check a warning. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<string> the messages of one level */
    public function messages(string $level): array
    {
        return \array_values(\array_map(static fn (array $r): string => $r['message'], \array_filter($this->records, static fn (array $r): bool => $r['level'] === $level)));
    }
}
