<?php

declare(strict_types=1);

namespace Itk\Cms\IndexNow\Model;

/**
 * Messenger message (transport `async`, default Messenger bus): tell search engines that the page at this path changed.
 * It carries a path, not an address: the handler adds the site address of the moment it runs, so a changed `domain` is respected.
 * The class name is public: a site may route it to a transport of its own.
 */
final readonly class PingIndexNow
{
    public function __construct(public string $path)
    {
    }
}
