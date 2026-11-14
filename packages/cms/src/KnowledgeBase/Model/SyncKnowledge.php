<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase\Model;

/**
 * Messenger message (transport `knowledge_base`, bus messenger.bus.default): bring the document of one source up to date with the live site.
 * It carries only the identity: the handler re-reads everything, so a late, duplicated or replayed message is a cheap no-op.
 * The class name is public: a site may route it to a transport of its own.
 */
final readonly class SyncKnowledge
{
    /** @param 'page'|'item' $kind */
    public function __construct(
        public string $kind,
        public string $id,
        public string $locale,
    ) {
    }

    public function ref(): SourceRef
    {
        return new SourceRef(SourceKind::from($this->kind), $this->id, $this->locale);
    }
}
