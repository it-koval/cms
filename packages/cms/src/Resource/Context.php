<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Security\Viewer;

/** Handed to every hook of a resource class: who acts, in which language, on what. */
final class Context
{
    /**
     * @param 'page'|'item' $kind
     */
    public function __construct(
        public readonly Viewer $viewer,
        public readonly string $locale,
        public readonly string $kind,
        public readonly string $resourceKey,
        public readonly bool $translationOnly = false,
    ) {
    }

    public function viewer(): Viewer
    {
        return $this->viewer;
    }
}
