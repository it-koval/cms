<?php

declare(strict_types=1);

namespace Itk\Cms\Media;

use Itk\Cms\Repository\MediaFileRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** @internal */
#[AsMessageHandler]
final class GenerateImageVariantsHandler
{
    public function __construct(
        private readonly MediaFileRepository $files,
        private readonly ImageVariants $variants,
    ) {
    }

    public function __invoke(GenerateImageVariants $message): void
    {
        $m = $this->files->find($message->mediaId);
        if (null === $m || !$m->isImage()) {
            return; // deleted before the worker came
        }
        $this->variants->warm($m->path, $m->width);
    }
}
