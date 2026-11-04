<?php

declare(strict_types=1);

namespace Itk\Cms\Generator;

use Itk\Cms\Resource\CollectionResource;
use Itk\Cms\Resource\ResourceInterface;
use Itk\Cms\Resource\ResourceRegistry;
use Itk\Cms\Resource\SectionResource;
use Itk\Cms\Resource\SettingsResource;
use Itk\Cms\Resource\TemplateResource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What every itk:make:* command needs: where the project is, the skeletons, the writer and the keys the site already uses.
 *
 * @internal
 */
final class MakeContext
{
    private const RESOURCES = [
        'collection' => CollectionResource::class,
        'template' => TemplateResource::class,
        'section' => SectionResource::class,
        'settings' => SettingsResource::class,
    ];

    public function __construct(
        public readonly ProjectLayout $layout,
        public readonly SkeletonRenderer $skeleton,
        public readonly FileWriter $writer,
        private readonly ResourceRegistry $resources,
        #[Autowire(param: 'itk_cms.schema_file')]
        private readonly string $schemaFile,
    ) {
    }

    /** Makes a class known to the schema in this run (the container does not know a file that was written a second ago). */
    public function addResource(ResourceInterface $resource): void
    {
        $this->resources->add($resource);
    }

    /**
     * Who already uses the key: a class (other than $exceptClass) or config/itk/schema.php; null when it is free.
     *
     * @param 'collection'|'template'|'section'|'settings' $kind
     */
    public function keyOwner(string $kind, string $key, string $exceptClass): ?string
    {
        $type = self::RESOURCES[$kind] ?? null;
        if (null === $type) {
            return null;
        }
        foreach ($this->resources->all() as $r) {
            if ($r instanceof $type && $r->key() === $key && $r::class !== $exceptClass) {
                return $r::class;
            }
        }
        if (\is_file($this->schemaFile) && \preg_match('/\$s->' . $kind . '\(\s*[\'"]' . \preg_quote($key, '/') . '[\'"]/', (string) \file_get_contents($this->schemaFile))) {
            return $this->layout->relative($this->schemaFile);
        }

        return null;
    }
}
