<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

/** What CollectionImporter did (or, in a dry run, would do). */
final class ImportResult
{
    public int $created = 0;
    public int $updated = 0;
    /** @var list<string> rows that were not imported and why */
    public array $failed = [];
    /** @var list<string> keys of the rows that the collection has no field for */
    public array $ignoredKeys = [];
    /** @var list<array{0: string, 1: array<string, mixed>}> the first converted rows: [sourceId, values] */
    public array $preview = [];
}
