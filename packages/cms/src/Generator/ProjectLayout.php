<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the site keeps things: the project root and the PSR-4 namespace of a directory (from composer.json, `src/` → `App\` in a standard Symfony project).
 *
 * @internal
 */
final class ProjectLayout
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    public function root(): string
    {
        return $this->projectDir;
    }

    /** A directory as the person typed it → clean relative path («src/Itk/Collection»). */
    public function cleanDir(string $dir): string
    {
        $dir = \trim(\str_replace('\\', '/', $dir), '/');
        if ('' === $dir || \in_array('..', \explode('/', $dir), true) || \str_starts_with($dir, '.')) {
            throw new \InvalidArgumentException(I18n::t('model.gen_dir_invalid', ['dir' => $dir]));
        }

        return $dir;
    }

    /** The namespace of classes that live in $dir (relative to the project), by the PSR-4 map of composer.json. */
    public function namespaceOf(string $dir): string
    {
        $dir = $this->cleanDir($dir);
        $best = null;
        foreach ($this->psr4() as $prefix => $path) {
            $path = \trim($path, '/');
            if ($dir === $path || \str_starts_with($dir, $path . '/')) {
                if (null === $best || \strlen($path) > \strlen($best[1])) {
                    $best = [$prefix, $path];
                }
            }
        }
        if (null === $best) {
            throw new \InvalidArgumentException(I18n::t('model.gen_dir_not_autoloaded', ['dir' => $dir]));
        }
        $rest = \trim(\substr($dir, \strlen($best[1])), '/');

        return \rtrim($best[0], '\\') . ('' === $rest ? '' : '\\' . \str_replace('/', '\\', $rest));
    }

    public function relative(string $absolute): string
    {
        return \str_starts_with($absolute, $this->projectDir . '/') ? \substr($absolute, \strlen($this->projectDir) + 1) : $absolute;
    }

    /** @return array<string, string> namespace prefix → directory */
    private function psr4(): array
    {
        $file = $this->projectDir . '/composer.json';
        $json = \is_file($file) ? \json_decode((string) \file_get_contents($file), true) : null;
        $map = [];
        foreach ((array) ($json['autoload']['psr-4'] ?? []) as $prefix => $paths) {
            foreach ((array) $paths as $path) {
                $map[(string) $prefix] = (string) $path;
            }
        }

        return [] !== $map ? $map : ['App\\' => 'src/'];
    }
}
