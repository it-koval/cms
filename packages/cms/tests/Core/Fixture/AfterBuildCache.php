<?php

declare(strict_types=1);

namespace Itk\Cms\Tests\Core\Fixture;

use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Finish an old callback only after another request has invalidated and rebuilt its file. */
final class AfterBuildCache extends ArrayAdapter
{
    public ?\Closure $afterBuild = null;

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        if (\str_starts_with($key, 'itk_crawler_generation_')) {
            return parent::get($key, $callback, $beta, $metadata);
        }

        return parent::get($key, function (...$args) use ($callback): mixed {
            $value = $callback(...$args);
            $after = $this->afterBuild;
            $this->afterBuild = null;
            $after?->__invoke();

            return $value;
        }, $beta, $metadata);
    }
}
