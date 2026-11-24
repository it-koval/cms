<?php

declare(strict_types=1);

namespace Itk\Cms\Schema;

use Itk\Cms\I18n\I18n;
use Itk\Cms\Resource\ResourceApplier;
use Itk\Cms\Resource\ResourceRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the site schema: the core templates, then config/itk/schema.php (optional), then the resource classes
 * (src/Itk/…, classes of extensions). One key described in two places is an error that names both.
 * In debug the built schema is checked too (Schema::validate()): a mistake of the site stops the page at once and says what to change.
 *
 * @internal
 */
final class SchemaLoader
{
    private ?Schema $schema = null;
    private ?Schema $built = null;

    public function __construct(
        #[Autowire(param: 'itk_cms.schema_file')]
        private readonly string $schemaFile,
        #[Autowire(param: 'itk_cms.main_locale')]
        private readonly string $mainLocale,
        private readonly ResourceRegistry $resources,
        #[Autowire(param: 'kernel.debug')]
        private readonly bool $debug = false,
        #[Autowire(param: 'itk_cms.blog')]
        private readonly bool $blog = true,
    ) {
    }

    /** The schema the site works with; in debug a schema that does not pass validate() is an exception with every problem in it. */
    public function load(): Schema
    {
        if (null !== $this->schema) {
            return $this->schema;
        }
        $schema = $this->build();
        if ($this->debug) {
            $problems = $schema->validate();
            if ([] !== $problems) {
                throw new \LogicException(I18n::t('model.schema_invalid') . "\n - " . \implode("\n - ", $problems));
            }
        }

        return $this->schema = $schema;
    }

    /** The schema as it is built, without the check of validate() (itk:schema:lint and itk:doctor report the problems themselves). */
    public function build(): Schema
    {
        if (null !== $this->built) {
            return $this->built;
        }
        $builder = new SchemaBuilder($this->mainLocale, $this->blog);
        $builder->from($this->schemaFile);
        if (\is_file($this->schemaFile)) {
            $define = require $this->schemaFile;
            if (!\is_callable($define)) {
                throw new \LogicException(\sprintf('%s must return a callable like static function (SchemaBuilder $s): void.', $this->schemaFile));
            }
            $define($builder);
        }
        foreach ($this->resources->all() as $resource) {
            ResourceApplier::apply($builder, $resource);
        }

        return $this->built = $builder->build();
    }
}
