<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/**
 * Optional contract for a custom field whose text may be indexed in the public knowledge base.
 * Without this contract, public visibility is unknown and the field is excluded.
 */
interface PublicFieldInterface extends FieldInterface
{
    /** True only when every visitor may read the value, independently of the current Viewer. */
    public function isPublic(): bool;
}
