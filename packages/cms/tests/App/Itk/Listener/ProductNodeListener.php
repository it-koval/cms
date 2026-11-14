<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App\Itk\Listener;

use Itk\Cms\Event\SchemaOrgGraph;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The site adds its own node to the graph of one page (SchemaOrgTest). */
#[AsEventListener]
final class ProductNodeListener
{
    public function __invoke(SchemaOrgGraph $graph): void
    {
        if ('/test-ld-event' === $graph->page['url']) {
            $graph->add(['@type' => 'Product', 'name' => 'Тестовый товар']);
            $graph->remove('WebSite');
        }
    }
}
