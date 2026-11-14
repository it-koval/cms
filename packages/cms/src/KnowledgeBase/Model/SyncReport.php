<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * @internal
 */
final readonly class SyncReport
{
    /**
     * @param array<string, Outcome> $outcomes SourceRef::key() => outcome
     * @param array<string, string> $failures SourceRef::key() => short reason (Outcome::Failed only)
     */
    public function __construct(
        public array $outcomes,
        public array $failures,
        /** inputs sent to the embedder */
        public int $embedded,
        /** inputs whose vector was found in the table or earlier in the batch */
        public int $reused,
        /** as the provider reported them; null when it does not say */
        public ?int $tokens,
    ) {
    }

    /** @return array<string, int> Outcome name => count, every outcome present, for the command's summary line */
    public function counts(): array
    {
        $counts = [];
        foreach (Outcome::cases() as $case) {
            $counts[$case->name] = 0;
        }
        foreach ($this->outcomes as $outcome) {
            ++$counts[$outcome->name];
        }

        return $counts;
    }
}
