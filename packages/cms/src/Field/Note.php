<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** A note for the editor, not data. Its key may be left out. */
final class Note extends AbstractField
{
    protected function wireType(): string
    {
        return 'readonly';
    }

    protected function configure(): void
    {
        $this->definition['text'] = '';
    }

    public function text(string $text): static
    {
        $this->definition['text'] = $text;

        return $this;
    }
}
