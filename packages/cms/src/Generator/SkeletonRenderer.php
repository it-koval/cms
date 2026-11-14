<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;

/**
 * Fills a file of packages/cms/skeleton/: every @@name@@ is replaced with the value of `name`.
 *
 * @internal
 */
final class SkeletonRenderer
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? \dirname(__DIR__, 2) . '/skeleton';
    }

    /** @param array<string, string> $vars */
    public function render(string $name, array $vars): string
    {
        $file = $this->dir . '/' . $name . '.tpl';
        if (!\is_file($file)) {
            throw new \LogicException(I18n::t('model.gen_skeleton_missing', ['file' => $file]));
        }
        $pairs = [];
        foreach ($vars as $k => $v) {
            $pairs['@@' . $k . '@@'] = $v;
        }
        $out = \strtr((string) \file_get_contents($file), $pairs);
        if (\preg_match('/@@[A-Za-z]+@@/', $out, $m)) {
            throw new \LogicException(I18n::t('model.gen_skeleton_unfilled', ['name' => $name, 'place' => $m[0]]));
        }

        return $out;
    }
}
