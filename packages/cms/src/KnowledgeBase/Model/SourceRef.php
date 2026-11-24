<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * The identity of one document of the index: a page or an item, in one language. It says nothing about the content, so a message that carries
 * it is never out of date: the handler reads the source as it is when the message runs.
 */
final readonly class SourceRef
{
    public function __construct(
        public SourceKind $kind,
        /** the page id (not the PageTranslation id) or the item id */
        public string $id,
        public string $locale,
    ) {
    }

    /** 'page:<id>:<locale>', the primary key of itk_kb_document and the advisory lock name. */
    public function key(): string
    {
        return $this->kind->value . ':' . $this->id . ':' . $this->locale;
    }

    /**
     * The command's --source: 'page:<id>', 'page:<id>:<locale>', 'item:<id>:<locale>'. Without a locale: every language of the source (the caller expands it).
     *
     * @throws \InvalidArgumentException
     *
     * @return array{SourceKind, string, ?string}
     */
    public static function parseOption(string $value): array
    {
        $parts = \explode(':', $value, 3);
        $kind = SourceKind::tryFrom($parts[0]);
        $id = \trim($parts[1] ?? '');
        if (null === $kind || '' === $id) {
            throw new \InvalidArgumentException(\sprintf('A source is page:<id>[:<locale>] or item:<id>[:<locale>], not "%s".', $value));
        }
        $locale = \trim($parts[2] ?? '');

        return [$kind, $id, '' === $locale ? null : $locale];
    }
}
