<?php

declare(strict_types=1);

namespace Itk\Cms\Media;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\MediaFolder;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\MediaFolderRepository;

/**
 * Folders of the media library: a tree of names, a file lies in one folder or in none. A folder with files or folders inside is removed only together with moving them.
 *
 * @internal
 */
final class MediaFolderService
{
    public const MAX_DEPTH = 6;

    /** `moveTo` value of remove() for «the top level of the library». */
    public const ROOT = 'root';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFolderRepository $folders,
        private readonly Connection $db,
    ) {
    }

    /**
     * Every folder with the number of files directly in it, and the totals for «Все файлы» and «Без папки».
     *
     * @return array{folders: list<array<string, mixed>>, total: int, unfiled: int}
     */
    public function tree(): array
    {
        $counts = [];
        $total = 0;
        $unfiled = 0;
        foreach ($this->db->fetchAllAssociative('SELECT folder_id, COUNT(*) AS n FROM itk_media_file GROUP BY folder_id') as $row) {
            $n = (int) $row['n'];
            $total += $n;
            if (null === $row['folder_id']) {
                $unfiled = $n;
            } else {
                $counts[(string) $row['folder_id']] = $n;
            }
        }

        return [
            'folders' => \array_map(fn (MediaFolder $f): array => $this->present($f, $counts[$f->id] ?? 0), $this->folders->all()),
            'total' => $total,
            'unfiled' => $unfiled,
        ];
    }

    public function find(string $id): MediaFolder
    {
        return $this->folders->find($id) ?? throw ContentException::notFound(I18n::t('http.folder_not_found'));
    }

    public function create(string $name, ?string $parentId = null): MediaFolder
    {
        $parent = null === $parentId || '' === $parentId ? null : $this->find($parentId);
        $name = $this->cleanName($name);
        $this->assertFree($name, $parent, null);
        if (null !== $parent && $this->depth($parent) >= self::MAX_DEPTH) {
            throw ContentException::invalid(I18n::t('http.folder_depth', ['count' => self::MAX_DEPTH]));
        }
        $folder = new MediaFolder($name, $parent, $this->nextPosition($parent));
        $this->em->persist($folder);
        $this->em->flush();

        return $folder;
    }

    /**
     * Renames and/or moves a folder (parentId, null is the top level) and/or puts it at a place among its neighbours (position).
     *
     * @param array{name?: mixed, parentId?: mixed, position?: mixed} $patch
     */
    public function update(string $id, array $patch): MediaFolder
    {
        $folder = $this->find($id);
        $parent = $folder->parent;
        $name = \array_key_exists('name', $patch) ? $this->cleanName((string) $patch['name']) : $folder->name;
        $moved = false;
        if (\array_key_exists('parentId', $patch)) {
            $parentId = \is_string($patch['parentId']) && '' !== $patch['parentId'] && self::ROOT !== $patch['parentId'] ? $patch['parentId'] : null;
            $parent = null === $parentId ? null : $this->find($parentId);
            $moved = $parent?->id !== $folder->parent?->id;
            if (null !== $parent && ($parent->id === $folder->id || $this->isInside($parent, $folder))) {
                throw ContentException::invalid(I18n::t('http.folder_into_itself'));
            }
            if ($moved && (null === $parent ? 0 : $this->depth($parent)) + $this->height($folder) > self::MAX_DEPTH) {
                throw ContentException::invalid(I18n::t('http.folder_depth', ['count' => self::MAX_DEPTH]));
            }
        }
        $this->assertFree($name, $parent, $folder);
        $folder->name = $name;
        $folder->parent = $parent;
        if ($moved) {
            $folder->position = $this->nextPosition($parent);
        }
        if (isset($patch['position']) && \is_numeric($patch['position'])) {
            $this->place($folder, (int) $patch['position']);
        }
        $this->em->flush();

        return $folder;
    }

    /**
     * Removes a folder. Files and folders inside are moved to `$moveTo` (a folder id, or ROOT for the top level);
     * without it a folder that is not empty is refused (409, details { files, folders }).
     */
    public function remove(string $id, ?string $moveTo = null): void
    {
        $folder = $this->find($id);
        $children = $this->folders->findBy(['parent' => $folder]);
        $files = $this->em->getRepository(MediaFile::class)->findBy(['folder' => $folder]);
        $target = null;
        if (null !== $moveTo && '' !== $moveTo && self::ROOT !== $moveTo) {
            $target = $this->find($moveTo);
            if ($target->id === $folder->id || $this->isInside($target, $folder)) {
                throw ContentException::invalid(I18n::t('http.folder_move_target'));
            }
        }
        if (([] !== $children || [] !== $files) && (null === $moveTo || '' === $moveTo)) {
            throw ContentException::conflict(
                I18n::t('http.folder_not_empty'),
                ['files' => \count($files), 'folders' => \count($children)],
            );
        }
        $this->em->wrapInTransaction(function () use ($folder, $children, $files, $target): void {
            $next = $this->nextPosition($target);
            foreach ($children as $child) {
                $child->parent = $target;
                $child->position = $next++;
            }
            foreach ($files as $file) {
                $file->folder = $target;
            }
            $this->em->flush();
            $this->em->remove($folder);
            $this->em->flush();
        });
    }

    /** @return array<string, mixed> */
    public function present(MediaFolder $f, int $files = 0): array
    {
        return ['id' => $f->id, 'parentId' => $f->parentId(), 'name' => $f->name, 'position' => $f->position, 'files' => $files];
    }

    private function cleanName(string $name): string
    {
        $name = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ('' === $name) {
            throw ContentException::invalid(I18n::t('http.folder_name_required'));
        }

        return \mb_substr($name, 0, 120);
    }

    private function assertFree(string $name, ?MediaFolder $parent, ?MediaFolder $self): void
    {
        foreach ($this->folders->findBy(['parent' => $parent]) as $sibling) {
            if ($sibling->id !== $self?->id && \mb_strtolower($sibling->name) === \mb_strtolower($name)) {
                throw ContentException::conflict(I18n::t('http.folder_name_taken'));
            }
        }
    }

    private function nextPosition(?MediaFolder $parent): int
    {
        $max = -1;
        foreach ($this->folders->findBy(['parent' => $parent]) as $sibling) {
            $max = \max($max, $sibling->position);
        }

        return $max + 1;
    }

    /** Puts the folder at index $index among the folders of its parent and numbers them again. */
    private function place(MediaFolder $folder, int $index): void
    {
        $siblings = \array_values(\array_filter($this->folders->findBy(['parent' => $folder->parent], ['position' => 'ASC', 'name' => 'ASC']), static fn (MediaFolder $f): bool => $f->id !== $folder->id));
        \array_splice($siblings, \max(0, \min($index, \count($siblings))), 0, [$folder]);
        foreach ($siblings as $i => $f) {
            $f->position = $i;
        }
    }

    /** Levels from the top level down to this folder (a top folder is 1). */
    private function depth(MediaFolder $folder): int
    {
        $n = 1;
        for ($p = $folder->parent; null !== $p && $n <= 64; $p = $p->parent) {
            ++$n;
        }

        return $n;
    }

    /** Levels of the folder and everything below it (a folder without folders inside is 1). */
    private function height(MediaFolder $folder): int
    {
        $by = [];
        foreach ($this->folders->all() as $f) {
            $by[(string) $f->parentId()][] = $f->id;
        }
        $walk = static function (string $id) use (&$walk, $by): int {
            $h = 0;
            foreach ($by[$id] ?? [] as $child) {
                $h = \max($h, $walk($child));
            }

            return $h + 1;
        };

        return $walk($folder->id);
    }

    /** Whether $folder lies somewhere below $ancestor. */
    private function isInside(MediaFolder $folder, MediaFolder $ancestor): bool
    {
        $n = 0;
        for ($p = $folder->parent; null !== $p && $n < 64; $p = $p->parent, ++$n) {
            if ($p->id === $ancestor->id) {
                return true;
            }
        }

        return false;
    }
}
