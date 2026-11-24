<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\App\Itk\Collection;

use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\Context;
use Symfony\Component\DependencyInjection\Attribute\When;

/** Application resource used to observe onLoad through real MCP HTTP requests. */
#[When(env: 'test_hook_request')]
final class RequestLoadsCollection extends CollectionResource
{
    /** @var list<string> */
    public static array $loads = [];

    public function key(): string
    {
        return 'test_request_loads';
    }

    public function title(): string
    {
        return 'Request loads';
    }

    public function fields(): iterable
    {
        return [];
    }

    public function onLoad(Context $ctx): void
    {
        self::$loads[] = $ctx->locale;
    }
}
