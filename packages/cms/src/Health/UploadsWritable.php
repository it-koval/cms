<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

/**
 * The first upload may create missing folders, provided the nearest existing parent accepts files.
 *
 * @internal
 */
final class UploadsWritable
{
    public static function check(string $path): bool
    {
        $dir = $path;
        while (!\is_dir($dir)) {
            if (\file_exists($dir) || \is_link($dir) || \dirname($dir) === $dir) {
                return false;
            }
            $dir = \dirname($dir);
        }
        $probe = $dir . '/.itk-write-' . \bin2hex(\random_bytes(8));
        try {
            return false !== @\file_put_contents($probe, 'ok', \LOCK_EX);
        } finally {
            if (\is_file($probe)) {
                @\unlink($probe);
            }
        }
    }
}
