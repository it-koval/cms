<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Security\Viewer;

/** What an action handler gets: the chosen records, the values of the modal and who runs it. */
final class ActionContext
{
    /**
     * @param list<Record> $records
     * @param array<string, mixed> $input
     */
    public function __construct(
        private readonly array $records,
        private readonly array $input,
        private readonly Viewer $viewer,
        private readonly string $resourceKey,
        private readonly string $kind,
        private readonly string $locale,
    ) {
    }

    /**
     * The records passed to the action. Row and form actions receive the current record.
     * Bulk actions receive the selected records. A top action can run with no records.
     *
     * @return list<Record>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * The values the person typed in the modal (already checked against its fields).
     *
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->input;
    }

    public function viewer(): Viewer
    {
        return $this->viewer;
    }

    public function resourceKey(): string
    {
        return $this->resourceKey;
    }

    /** The action runner supplies 'item' for collection records. */
    public function kind(): string
    {
        return $this->kind;
    }

    public function locale(): string
    {
        return $this->locale;
    }
}
