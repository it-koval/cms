<?php

declare(strict_types=1);

namespace Itk\Cms\Media;

/** Messenger message (async transport, default Messenger bus): make the WebP and AVIF copies of an uploaded image ahead of the first visit. */
final class GenerateImageVariants
{
    public function __construct(
        public readonly string $mediaId,
    ) {
    }
}
