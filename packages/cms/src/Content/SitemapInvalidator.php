<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Itk\Cms\Event\RecordDeleted;
use Itk\Cms\Event\RecordEvent;
use Itk\Cms\Event\RecordPublished;
use Itk\Cms\Event\RecordTranslationRemoved;
use Itk\Cms\Event\RecordUnpublished;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The sitemap and the standard llms.txt are kept in the cache (SitemapBuilder, CrawlerFiles); a page that goes live, goes off, loses a translation or is deleted makes them stale. Draft autosaves never change the published snapshot.
 *
 * @internal
 */
#[AsEventListener(event: RecordPublished::class)]
#[AsEventListener(event: RecordUnpublished::class)]
#[AsEventListener(event: RecordDeleted::class)]
#[AsEventListener(event: RecordTranslationRemoved::class)]
final class SitemapInvalidator
{
    public function __construct(private readonly SitemapBuilder $sitemap, private readonly CrawlerFiles $crawlerFiles)
    {
    }

    public function __invoke(RecordEvent $event): void
    {
        if ('page' === $event->kind) {
            $this->sitemap->forget();
            $this->crawlerFiles->forget();
        }
    }
}
