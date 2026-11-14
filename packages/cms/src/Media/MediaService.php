<?php

declare(strict_types=1);

namespace Itk\Cms\Media;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Itk\Cms\Content\ContentException;
use Itk\Cms\Content\FieldAccess;
use Itk\Cms\Entity\MediaFile;
use Itk\Cms\Entity\MediaFolder;
use Itk\Cms\I18n\I18n;
use Itk\Cms\Repository\MediaFileRepository;
use Itk\Cms\Repository\MediaFolderRepository;
use Itk\Cms\Schema\Schema;
use Itk\Cms\Security\Viewer;
use Itk\Cms\Support\Slug;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/** The media library: files in public/uploads/media/Y/m/, rows in itk_media_file, usage lookup across pages, records and settings. */
final class MediaService
{
    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaFileRepository $files,
        private readonly Connection $db,
        private readonly Schema $schema,
        private readonly ImageVariants $variants,
        private readonly MediaFolderRepository $folders,
        #[Autowire(service: 'messenger.bus.default')]
        private readonly MessageBusInterface $bus,
        #[Autowire(param: 'itk_cms.upload_dir')]
        private readonly string $uploadDir,
        #[Autowire(param: 'itk_cms.upload_url')]
        private readonly string $uploadUrl,
        #[Autowire(param: 'itk_cms.max_upload_mb')]
        private readonly int $maxMb,
    ) {
    }

    /**
     * @param string $folder '' every file, MediaFile::NO_FOLDER the files outside any folder, else a folder id
     *
     * @return list<MediaFile>
     */
    public function list(string $q = '', string $type = '', string $folder = ''): array
    {
        return $this->files->search($q, $type, $folder);
    }

    public function find(string $id): MediaFile
    {
        return $this->files->find($id) ?? throw ContentException::notFound(I18n::t('http.file_not_found'));
    }

    /** @param string|null $folderId the folder the file is put into (the top level when null) */
    public function upload(?UploadedFile $file, ?string $folderId = null): MediaFile
    {
        $folder = $this->folderOrRoot($folderId);
        if (null === $file || !$file->isValid()) {
            throw ContentException::invalid(I18n::t('http.media_no_upload'));
        }
        if ($file->getSize() > $this->maxMb * 1024 * 1024) {
            throw new ContentException(I18n::t('http.media_too_big', ['max' => $this->maxMb]), 413);
        }
        $mime = (string) $file->getMimeType();
        $ext = self::TYPES[$mime] ?? null;
        if (null === $ext) {
            throw new ContentException(I18n::t('http.media_bad_type'), 415);
        }
        $original = $file->getClientOriginalName() ?: ('file.' . $ext);
        $dir = \date('Y') . '/' . \date('m');
        $name = (Slug::slugify(\pathinfo($original, \PATHINFO_FILENAME), 60) ?: 'file') . '-' . \bin2hex(\random_bytes(3)) . '.' . $ext;
        $target = \rtrim($this->uploadDir, '/') . '/' . $dir;
        if (!\is_dir($target) && !@\mkdir($target, 0775, true) && !\is_dir($target)) {
            throw new ContentException(I18n::t('http.media_no_dir'), 500);
        }
        $size = (int) $file->getSize();
        $file->move($target, $name);

        $m = new MediaFile(\rtrim($this->uploadUrl, '/') . '/' . $dir . '/' . $name, $original, $mime);
        $m->size = $size;
        $m->folder = $folder;
        if (\str_starts_with($mime, 'image/')) {
            $size = $this->variants->dimensions($target . '/' . $name); // as it looks: the EXIF turn is applied
            if (null !== $size) {
                $m->width = $size[0];
                $m->height = $size[1];
            }
        }
        $this->em->persist($m);
        $this->em->flush();
        if ($m->isImage() && $this->variants->isConvertible($m->path)) {
            try {
                $this->bus->dispatch(new GenerateImageVariants($m->id)); // WebP and AVIF copies ahead of the first visit
            } catch (\Throwable) {
                // the queue is down: the copies are made on the first request instead, the upload itself went through
            }
        }

        return $m;
    }

    /**
     * @param array{alt?: string, title?: string, name?: string, folderId?: string|null, focal?: mixed, crops?: mixed} $patch
     *                                                                                                                        folderId: a folder id, null or "root" for the top level; focal: { x, y } or null; crops: { "16:9": { x, y, w, h }, … } (replaces all frames) or null
     */
    public function update(string $id, array $patch): MediaFile
    {
        $m = $this->find($id);
        $pictureChanged = false;
        if (\array_key_exists('focal', $patch) || \array_key_exists('crops', $patch)) {
            if (!$m->isImage()) {
                throw ContentException::invalid(I18n::t('http.media_focus_photo_only'));
            }
            if (\array_key_exists('focal', $patch)) {
                $focal = $this->cleanFocal($patch['focal']);
                $pictureChanged = $focal !== $m->focal;
                $m->focal = $focal;
            }
            if (\array_key_exists('crops', $patch)) {
                $crops = $this->cleanCrops($patch['crops']);
                $pictureChanged = $pictureChanged || $crops !== $m->crops;
                $m->crops = $crops;
            }
        }
        if (\array_key_exists('folderId', $patch)) {
            $m->folder = $this->folderOrRoot($patch['folderId']);
        }
        if (\array_key_exists('alt', $patch)) {
            $m->alt = \mb_substr(\trim((string) $patch['alt']), 0, 255);
        }
        $title = $patch['title'] ?? $patch['name'] ?? null;
        if (null !== $title && '' !== \trim((string) $title)) {
            $m->title = \mb_substr(\trim((string) $title), 0, 255);
        }
        $this->em->flush();
        if ($pictureChanged) {
            $this->variants->removeRatioVariants($m->path); // the frames were cut from the old point or frame
        }

        return $m;
    }

    /**
     * Puts files into a folder (null or "root": the top level); all or none: an unknown file or folder moves nothing.
     *
     * @param list<string> $ids
     *
     * @return int how many files moved
     */
    public function move(array $ids, mixed $folderId): int
    {
        $folder = $this->folderOrRoot($folderId);
        $files = [];
        foreach (\array_values(\array_unique(\array_map('strval', $ids))) as $id) {
            $files[] = $this->find($id);
        }
        foreach ($files as $m) {
            $m->folder = $folder;
        }
        $this->em->flush();

        return \count($files);
    }

    public function remove(string $id): void
    {
        $m = $this->find($id);
        $file = $this->absolutePath($m);
        $this->em->remove($m);
        $this->em->flush();
        if (null !== $file && \is_file($file)) {
            @\unlink($file);
        }
        $this->variants->remove($m->path);
    }

    /**
     * Where the file is used: pages, collection records and settings that contain its address.
     *
     * @return list<array{label: string, to: string}>
     */
    public function usage(MediaFile $m, ?Viewer $viewer = null): array
    {
        $like = '%' . \addcslashes($m->path, '%_\\') . '%';
        $out = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT t.page_id, p.template, t.draft, t.published FROM itk_page_translation t JOIN itk_page p ON p.id = t.page_id
             WHERE t.draft::text LIKE :p OR t.published::text LIKE :p',
            ['p' => $like],
        );
        foreach ($rows as $r) {
            $template = (string) $r['template'];
            if (null !== $viewer && !$viewer->can('pages', 'view', $template)) {
                continue;
            }
            $draft = (array) \json_decode((string) $r['draft'], true);
            if (null !== $viewer) {
                $published = (array) \json_decode((string) $r['published'], true);
                $versions = [];
                foreach ([$draft, $published] as $version) {
                    $versions[] = FieldAccess::pageOutputFor($this->schema, $template, $version, $viewer);
                }
                if (!self::containsPath($versions, $m->path)) {
                    continue;
                }
            }
            $out[] = ['label' => I18n::t('http.media_used_page', ['title' => $draft['title'] ?? '']), 'to' => '/pages/' . $r['page_id']];
        }
        $rows = $this->db->fetchAllAssociative('SELECT id, collection_key, translations::text AS tr FROM itk_collection_item WHERE translations::text LIKE :p', ['p' => $like]);
        foreach ($rows as $r) {
            $c = $this->schema->collection((string) $r['collection_key']);
            $tr = \json_decode((string) $r['tr'], true) ?: [];
            if (null !== $viewer) {
                if (null === $c || !$viewer->can('col:' . $c->key()) || !$c->isVisibleTo($viewer)) {
                    continue;
                }
                foreach ($tr as $locale => $version) {
                    foreach (['draft', 'published'] as $state) {
                        $tr[$locale][$state] = FieldAccess::outputFor($c->fieldList(), (array) ($version[$state] ?? []), $viewer);
                    }
                }
                if (!self::containsPath($tr, $m->path)) {
                    continue;
                }
            }
            $first = \reset($tr) ?: [];
            $name = null !== $c ? (string) ($first['draft'][$c->titleFieldKey()] ?? '') : '';
            $out[] = ['label' => ($c?->title() ?? $r['collection_key']) . ('' !== $name ? ' · ' . $name : ''), 'to' => '/collections/' . $r['collection_key'] . '/' . $r['id']];
        }
        $keys = $this->db->fetchFirstColumn('SELECT DISTINCT setting_key FROM itk_setting WHERE setting_value::text LIKE :p', ['p' => $like]);
        if ([] !== $keys && (null === $viewer || $this->maySeeSetting($keys, $viewer))) {
            $out[] = ['label' => I18n::t('http.media_used_settings'), 'to' => '/settings'];
        }

        return $out;
    }

    /** @param array<mixed> $values */
    private static function containsPath(array $values, string $path): bool
    {
        return \str_contains((string) \json_encode($values, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $path);
    }

    /** @param list<mixed> $keys */
    private function maySeeSetting(array $keys, Viewer $viewer): bool
    {
        foreach ($this->schema->settingsGroups() as $group) {
            if (!$viewer->can('set:' . $group->key())) {
                continue;
            }
            foreach ($group->fieldList() as $field) {
                if (\in_array($field->key(), $keys, true) && $field->visibleTo($viewer)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A file as the admin's media library reads it.
     *
     * @return array<string, mixed>
     */
    public function present(MediaFile $m, bool $withUsage = false, ?Viewer $viewer = null): array
    {
        return [
            'id' => $m->id,
            'name' => $m->title,
            'originalName' => $m->originalName,
            'src' => $m->path,
            'thumb' => $m->isImage() ? $this->variants->url($m->path, 320) : '',
            'w' => $m->width,
            'h' => $m->height,
            'kb' => \max(1, (int) \round($m->size / 1024)),
            'alt' => $m->alt,
            'folderId' => $m->folderId(),
            'focal' => $m->focal,
            'crops' => $m->crops ?? (object) [],
            'mime' => $m->mime,
            'type' => $m->isImage() ? 'img' : 'pdf',
            'used' => $withUsage ? $this->usage($m, $viewer) : [],
            'createdAt' => $m->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function folderOrRoot(mixed $id): ?MediaFolder
    {
        if (!\is_string($id) || '' === $id || MediaFolderService::ROOT === $id) {
            return null;
        }

        return $this->folders->find($id) ?? throw ContentException::notFound(I18n::t('http.folder_not_found'));
    }

    /** @return array{x: float, y: float}|null */
    private function cleanFocal(mixed $focal): ?array
    {
        if (null === $focal || [] === $focal) {
            return null;
        }
        if (!\is_array($focal) || !isset($focal['x'], $focal['y']) || !\is_numeric($focal['x']) || !\is_numeric($focal['y'])
            || $focal['x'] < 0 || $focal['x'] > 1 || $focal['y'] < 0 || $focal['y'] > 1) {
            throw ContentException::invalid(I18n::t('http.media_focus_invalid'));
        }

        return ['x' => \round((float) $focal['x'], 4), 'y' => \round((float) $focal['y'], 4)];
    }

    /**
     * @return array<string, array{x: float, y: float, w: float, h: float}>|null frames by the reduced ratio ("32:18" is kept as "16:9")
     */
    private function cleanCrops(mixed $crops): ?array
    {
        if (null === $crops || [] === $crops) {
            return null;
        }
        if (!\is_array($crops) || \count($crops) > 24) {
            throw ContentException::invalid(I18n::t('http.media_crops_limit'));
        }
        $out = [];
        foreach ($crops as $ratio => $frame) {
            $pair = ImageVariants::parseRatio((string) $ratio);
            if (null === $pair) {
                throw ContentException::invalid(I18n::t('http.media_ratio_invalid', ['ratio' => $ratio]));
            }
            $ok = \is_array($frame);
            foreach (['x', 'y', 'w', 'h'] as $k) {
                $ok = $ok && isset($frame[$k]) && \is_numeric($frame[$k]) && $frame[$k] >= 0 && $frame[$k] <= 1;
            }
            if (!$ok || $frame['w'] < 0.01 || $frame['h'] < 0.01 || $frame['x'] + $frame['w'] > 1.001 || $frame['y'] + $frame['h'] > 1.001) {
                throw ContentException::invalid(I18n::t('http.media_crop_invalid', ['ratio' => $ratio]));
            }
            $out[ImageVariants::ratioKey($pair)] = [
                'x' => \round((float) $frame['x'], 4),
                'y' => \round((float) $frame['y'], 4),
                'w' => \round(\min((float) $frame['w'], 1 - (float) $frame['x']), 4),
                'h' => \round(\min((float) $frame['h'], 1 - (float) $frame['y']), 4),
            ];
        }

        return $out;
    }

    private function absolutePath(MediaFile $m): ?string
    {
        $prefix = \rtrim($this->uploadUrl, '/') . '/';
        if (!\str_starts_with($m->path, $prefix)) {
            return null;
        }
        $path = \rtrim($this->uploadDir, '/') . '/' . \substr($m->path, \strlen($prefix));

        return \str_contains($path, '..') ? null : $path;
    }
}
