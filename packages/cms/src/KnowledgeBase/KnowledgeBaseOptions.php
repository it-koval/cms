<?php

declare(strict_types=1);

namespace Itk\Cms\KnowledgeBase;

use Itk\Cms\KnowledgeBase\Model\SourceKind;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The itk_cms.knowledge_base options as one question: does this source belong in the index? The listener asks it before dispatching, the
 * SourceReader before reading, so an excluded template or collection never costs a message, and a language the public route does not serve
 * (anything but the main one) never gets a document.
 *
 * @internal
 */
final class KnowledgeBaseOptions
{
    /**
     * @param list<string> $excludeTemplates
     * @param list<string> $excludeCollections
     */
    public function __construct(
        #[Autowire(param: 'itk_cms.knowledge_base.enabled')]
        public readonly bool $enabled,
        #[Autowire(param: 'itk_cms.knowledge_base.exclude_templates')]
        private readonly array $excludeTemplates,
        #[Autowire(param: 'itk_cms.knowledge_base.exclude_collections')]
        private readonly array $excludeCollections,
        #[Autowire(param: 'itk_cms.main_locale')]
        public readonly string $mainLocale,
    ) {
    }

    /** @param string $resourceKey the template key of a page, the collection key of an item */
    public function indexes(SourceKind $kind, string $resourceKey, string $locale): bool
    {
        if (!$this->enabled || $locale !== $this->mainLocale) {
            return false;
        }

        return !\in_array($resourceKey, SourceKind::Page === $kind ? $this->excludeTemplates : $this->excludeCollections, true);
    }
}
