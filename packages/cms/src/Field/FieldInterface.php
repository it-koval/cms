<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

use Itk\Cms\Security\Viewer;

/**
 * One field of a section, a template, a collection, a settings group or a site form: what the core reads to validate, store,
 * compare and export it. Every field is an Itk\Cms\Field\AbstractField (Text::make('Заголовок', 'title')->required()).
 */
interface FieldInterface
{
    public function key(): string;

    /** Wire type of the admin: text, textarea, headline, number, toggle, select, image, link, repeater, photos, relation or readonly (a note). */
    public function type(): string;

    public function label(): string;

    public function isRequired(): bool;

    /** A note for the editor (Note), not data. */
    public function isNote(): bool;

    public function isRaw(): bool;

    public function isHeadline(): bool;

    public function isRich(): bool;

    public function isTranslatable(): bool;

    public function maxLength(): ?int;

    public function regex(): ?string;

    public function regexMessage(): string;

    /** Sanitizer mode of a rich field: 'headline' | 'rich' | 'article', or null for non-rich fields. */
    public function richMode(): ?string;

    /** @return list<FieldInterface> */
    public function subFields(): array;

    public function subField(string $key): ?self;

    public function hasDefault(): bool;

    public function defaultValue(): mixed;

    /** @return list<array{0: string, 1: string}> */
    public function choices(): array;

    /** The value cannot be changed from the admin or the API (hooks and code still set it). */
    public function isLocked(): bool;

    /** May this viewer see (and change) the field? Fields without canSee() are visible to everybody. */
    public function visibleTo(Viewer $viewer): bool;

    public function isSortable(): bool;

    public function isSearchable(): bool;

    /** null = not a filter. */
    public function filterKind(): ?FilterKind;

    /** True / false when the field was explicitly put in / kept out of the list columns, null = the admin decides. */
    public function listColumn(): ?bool;

    /** @return array<string, mixed> the field as the admin reads it */
    public function toArray(): array;
}
