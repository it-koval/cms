<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;
use Itk\Cms\Field\FormFields;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Security\Viewer;

/** A group of site settings (Настройки → one tab or section). Settings are saved at once, no publish step. */
final class SettingsGroup
{
    private string $description = '';
    private ?string $titleKey = null;
    /** @var list<FieldInterface> */
    private array $fields = [];

    public function __construct(private readonly string $key, private string $title)
    {
    }

    public function title(string $title): self
    {
        $this->title = $title;
        $this->titleKey = null;

        return $this;
    }

    /** The title of a group the bundle itself defines, by catalogue key: read in the language of the person who asks, not of the one who built the schema. */
    public function titleKey(string $key): self
    {
        $this->titleKey = $key;

        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Adds fields (a group of the same key defined twice is merged). */
    public function fields(FieldInterface ...$fields): self
    {
        foreach ($fields as $f) {
            $this->fields = \array_values(\array_filter($this->fields, static fn (FieldInterface $x): bool => $x->key() !== $f->key()));
            $this->fields[] = $f;
        }

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function groupTitle(): string
    {
        return null !== $this->titleKey ? I18n::t($this->titleKey) : $this->title;
    }

    /** Puts fields in front of the ones the group has (a field of the same key is replaced). */
    public function prepend(FieldInterface ...$fields): self
    {
        $keys = \array_map(static fn (FieldInterface $f): string => $f->key(), $fields);
        $this->fields = \array_values([...$fields, ...\array_filter($this->fields, static fn (FieldInterface $x): bool => !\in_array($x->key(), $keys, true))]);

        return $this;
    }

    /** @return list<FieldInterface> */
    public function fieldList(): array
    {
        return \array_values(\array_filter($this->fields, static fn (FieldInterface $field): bool => !\in_array($field->key(), Schema::PRIVATE_SETTING_KEYS, true)));
    }

    /**
     * @return array<string, mixed> the group as the admin reads it; with a viewer: only the fields he may see
     */
    public function toArray(?Viewer $viewer = null): array
    {
        return [
            'key' => $this->key,
            'title' => $this->groupTitle(),
            'description' => $this->description,
            'fields' => FormFields::toArray($this->fieldList(), $viewer),
        ];
    }
}
