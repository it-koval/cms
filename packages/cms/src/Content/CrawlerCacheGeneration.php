<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Shared generations fence off a build that finishes after a committed change invalidated its snapshot. */
final class CrawlerCacheGeneration
{
    private const KEY = 'itk_crawler_generation_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function current(string $scope): int
    {
        $item = $this->pool->getItem(self::KEY . $scope);
        $value = $item->get();

        return $item->isHit() && \is_int($value) && $value > 0 ? $value : $this->write($item);
    }

    public function advance(string $scope): void
    {
        $this->write($this->pool->getItem(self::KEY . $scope));
    }

    private function write(CacheItemInterface $item): int
    {
        $value = (int) (\microtime(true) * 1_000_000) * 1_000 + \random_int(0, 999);
        if (!$this->pool->save($item->set($value)->expiresAfter(null))) {
            throw new \RuntimeException('Cannot save the crawler cache generation.');
        }

        return $value;
    }
}
