<?php

declare(strict_types=1);

namespace Itk\Cms\Rendering;

use Symfony\Component\HttpFoundation\Response;

/**
 * What the cache of pages did for one answer; PageController sends it as the header X-ITK-Cache.
 *
 * @internal
 */
enum PageCacheStatus: string
{
    /** The page came from the cache, nothing was drawn. */
    case Hit = 'HIT';
    /** The page was drawn (and kept, when the rules allow). */
    case Miss = 'MISS';
    /** The cache was not asked: it is off, debug, a signed-in visitor, a method other than GET and HEAD. */
    case Bypass = 'BYPASS';

    private const HEADER = 'X-ITK-Cache';

    /** Puts the status on the answer. */
    public function stamp(Response $response): Response
    {
        $response->headers->set(self::HEADER, $this->value);

        return $response;
    }
}
