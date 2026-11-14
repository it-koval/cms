<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\I18n\I18n;

/**
 * Puts generated files on disk: a class that exists is never overwritten without --force, a helper file (Twig, JS) that exists is kept.
 *
 * @internal
 */
final class FileWriter
{
    public const CREATE = 'create';
    public const REPLACE = 'replace';
    public const KEEP = 'keep';
    public const CONFLICT = 'conflict';

    /**
     * What would happen to each file.
     *
     * @param list<GeneratedFile> $files
     *
     * @return list<array{file: GeneratedFile, status: string}>
     */
    public function plan(string $root, array $files, bool $force): array
    {
        $out = [];
        foreach ($files as $f) {
            $status = match (true) {
                !\is_file($root . '/' . $f->path) => self::CREATE,
                $force => self::REPLACE,
                $f->main => self::CONFLICT,
                default => self::KEEP,
            };
            $out[] = ['file' => $f, 'status' => $status];
        }

        return $out;
    }

    /** @param list<array{file: GeneratedFile, status: string}> $plan */
    public function write(string $root, array $plan): void
    {
        foreach ($plan as ['file' => $f, 'status' => $status]) {
            if (!\in_array($status, [self::CREATE, self::REPLACE], true)) {
                continue;
            }
            $path = $root . '/' . $f->path;
            if (!\is_dir(\dirname($path)) && !@\mkdir(\dirname($path), 0775, true) && !\is_dir(\dirname($path))) {
                throw new \RuntimeException(I18n::t('model.gen_mkdir_failed', ['dir' => \dirname($path)]));
            }
            if (false === \file_put_contents($path, $f->content)) {
                throw new \RuntimeException(I18n::t('model.gen_write_failed', ['path' => $f->path]));
            }
        }
    }
}
