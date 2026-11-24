<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core;

use Itk\Cms\Content\CollectionService;
use Itk\Cms\Field\Text;
use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Itk\Cms\Resource\ResourceRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HookDispatcherResetTest extends KernelTestCase
{
    #[Test]
    public function onLoadRunsOncePerResourceAndLocaleAgainAfterTheConfiguredCycleReset(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $resource = new class('f1_load_' . \bin2hex(\random_bytes(6))) extends CollectionResource {
            /** @var list<string> */
            public array $loads = [];

            public function __construct(private readonly string $resourceKey)
            {
            }

            public function key(): string
            {
                return $this->resourceKey;
            }

            public function title(): string
            {
                return 'Load cycle fixture';
            }

            public function fields(): iterable
            {
                yield Text::make('Title', 'title');
            }

            public function onLoad(Context $ctx): void
            {
                $this->loads[] = $ctx->locale;
            }
        };
        $c->get(ResourceRegistry::class)->add($resource);
        $collections = $c->get(CollectionService::class);

        foreach (['ru', 'ru', 'en', 'en'] as $locale) {
            $collections->loaded($resource->key(), $locale);
        }
        self::assertSame(['ru', 'en'], $resource->loads, 'one onLoad per locale within the first cycle');

        $c->get('services_resetter')->reset();

        foreach (['ru', 'ru', 'en', 'en'] as $locale) {
            $collections->loaded($resource->key(), $locale);
        }
        self::assertSame(['ru', 'en', 'ru', 'en'], $resource->loads, 'the next cycle invokes each locale once again');
    }
}
