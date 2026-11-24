<?php

declare(strict_types=1);

namespace Itk\Cms\Field;

/** Optional value validation for site-defined field types, shared by content services, forms and MCP. */
interface ValueValidatingFieldInterface extends FieldInterface
{
    /** @return list<string> translated errors for the supplied value; empty means valid */
    public function validationErrors(mixed $value): array;
}
