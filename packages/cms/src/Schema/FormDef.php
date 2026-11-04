<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\Field\FieldInterface;

/** A form of the public site («перезвоните мне»): POST /request/{key}. */
final class FormDef
{
    /** @var list<FieldInterface> */
    private array $fields = [];
    private ?string $telegram = null;

    public function __construct(private readonly string $key, private string $title)
    {
    }

    public function fields(FieldInterface ...$fields): self
    {
        $this->fields = \array_values($fields);

        return $this;
    }

    /**
     * Telegram text with placeholders: {id} {form} {phone} {name} {city} {url} and any field key as {key}. HTML is escaped.
     * Without it a plain «Новая заявка» text is built from all fields.
     */
    public function telegram(string $template): self
    {
        $this->telegram = $template;

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function formTitle(): string
    {
        return $this->title;
    }

    public function telegramTemplate(): ?string
    {
        return $this->telegram;
    }

    /** @return list<FieldInterface> */
    public function fieldList(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'fields' => \array_map(static fn (FieldInterface $f): array => $f->toArray(), $this->fields),
        ];
    }
}
