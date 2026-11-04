<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Security\Access;

/**
 * Field-level rights, on the server: a field with canSee() the person fails is left out of what he reads and what he writes; a field
 * with readonly() cannot be written by him. The system (console, imports, queue) is not limited.
 */
final class FieldAccess
{
    public function __construct(private readonly Access $access)
    {
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<FieldInterface> the fields the current person may see
     */
    public function visible(array $fields): array
    {
        $viewer = $this->access->viewer();
        if ($viewer->isSystem()) {
            return $fields;
        }

        return \array_values(\array_filter($fields, static fn (FieldInterface $f): bool => $f->visibleTo($viewer)));
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<FieldInterface> the fields the current person may change
     */
    public function writable(array $fields): array
    {
        $viewer = $this->access->viewer();
        if ($viewer->isSystem()) {
            return $fields;
        }

        return \array_values(\array_filter($fields, static fn (FieldInterface $f): bool => !$f->isLocked() && $f->visibleTo($viewer)));
    }

    /**
     * The incoming values without the ones the person may not write.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function input(array $fields, array $data): array
    {
        return $this->without($fields, $this->writable($fields), $data);
    }

    /**
     * The stored values without the ones the person may not see.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function output(array $fields, array $data): array
    {
        return $this->without($fields, $this->visible($fields), $data);
    }

    /**
     * @param list<FieldInterface> $fields
     *
     * @return list<string> keys of the fields the current person does not see
     */
    public function hiddenKeys(array $fields): array
    {
        $keep = \array_map(static fn (FieldInterface $f): string => $f->key(), $this->visible($fields));

        return \array_values(\array_diff(\array_map(static fn (FieldInterface $f): string => $f->key(), $fields), $keep));
    }

    /**
     * @param list<FieldInterface> $all
     * @param list<FieldInterface> $allowed
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function without(array $all, array $allowed, array $data): array
    {
        if (\count($allowed) === \count($all)) {
            return $data;
        }
        $ok = \array_flip(\array_map(static fn (FieldInterface $f): string => $f->key(), $allowed));
        foreach ($all as $f) {
            if (!isset($ok[$f->key()])) {
                unset($data[$f->key()]);
            }
        }

        return $data;
    }
}
