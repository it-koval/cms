<?php

declare(strict_types=1);

namespace Itk\Cms\Resource;

use Itk\Cms\Field\FieldInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A section of the site described by one PHP class: a collection, a page template, a section type or a group of settings.
 * Classes of src/Itk/{Collection,Template,Section,Settings}/ are found by themselves (autoconfigured, tag `itk.resource`).
 */
#[AutoconfigureTag('itk.resource')]
interface ResourceInterface
{
    /** Stable key: the address in the API, the key in the database (Latin letters, digits, _ and -). */
    public function key(): string;

    /** What the editor sees. */
    public function title(): string;

    /** @return iterable<FieldInterface> */
    public function fields(): iterable;
}
