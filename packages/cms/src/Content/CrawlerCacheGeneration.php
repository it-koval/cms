<?php

declare(strict_types=1);

namespace Itk\Cms\Content;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\Cache\CacheInterface;

/** Shared generations fence off a build that finishes after a committed change invalidated its snapshot. */
final class CrawlerCacheGeneration
{
    private static int $seed = 0;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface|CacheInterface $pool,
        private readonly ?LockFactory $locks = null,
    ) {
    }

    /** Standalone builders share the same generation protocol as the container services. */
    public static function forCache(CacheInterface $cache): self
    {
        return new self($cache);
    }

    public function current(string $scope): int
    {
        return $this->change($scope, false);
    }

    public function advance(string $scope): void
    {
        $this->change($scope, true);
    }

    private function change(string $scope, bool $advance): int
    {
        $lock = ($this->locks ?? new LockFactory(new FlockStore()))->createLock('itk-crawler-generation-' . $scope);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Cannot lock the crawler cache generation.');
        }
        try {
            $key = 'itk_crawler_generation_' . $scope;
            if (!$this->pool instanceof CacheItemPoolInterface) {
                return $this->changeInContractsCache($this->pool, $key, $advance);
            }
            $item = $this->pool->getItem($key);
            $value = $item->get();
            if (!$item->isHit() || !\is_int($value) || $value < 1) {
                $value = self::next();
            } elseif (!$advance) {
                return $value;
            } else {
                $value = self::next($value);
            }
            if (!$this->pool->save($item->set($value)->expiresAfter(null))) {
                throw new \RuntimeException('Cannot save the crawler cache generation.');
            }

            return $value;
        } finally {
            $lock->release();
        }
    }

    /** Cache Contracts provides forced recomputation for adapters without the PSR-6 write API. */
    private function changeInContractsCache(CacheInterface $cache, string $key, bool $advance): int
    {
        $value = $cache->get($key, static fn (CacheItemInterface $item): int => self::next(), 0.0);
        if (!$advance && \is_int($value) && $value > 0) {
            return $value;
        }
        $next = self::next(\is_int($value) && $value > 0 ? $value : null);

        return $cache->get($key, static function (CacheItemInterface $item) use ($next): int {
            $item->expiresAfter(null);

            return $next;
        }, \INF);
    }

    private static function next(?int $previous = null): int
    {
        // An evicted counter must not restart at zero while an older generated file still exists.
        $next = null === $previous ? \max(self::$seed + 1, (int) (\microtime(true) * 1_000_000)) : $previous + 1;
        self::$seed = \max(self::$seed, $next);

        return $next;
    }
}
